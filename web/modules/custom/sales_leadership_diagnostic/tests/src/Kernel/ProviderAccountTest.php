<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Exception\EngineException;
use Drupal\sales_leadership_diagnostic\Exception\ProviderAccountException;
use Drupal\sales_leadership_diagnostic\Exception\SearchException;
use Drupal\sales_leadership_diagnostic\Hook\DiagnosticRequirements;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;
use Drupal\sales_leadership_diagnostic\Service\Search\TavilySearchProvider;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageCollector;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\ProviderAccountStatus;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Un proveedor sin saldo se detecta, no se reintenta y se avisa a quien opera.
 *
 * El 02-10-2026 se agotó el saldo de la cuenta de pruebas de OpenAI en mitad
 * de una batería. Se vio todo lo que estaba mal: el error se reintentaba como
 * si fuera un límite de velocidad, el registro decía «el proveedor está
 * limitando las peticiones» —falso—, el alumno recibía «intenta nuevamente» y
 * nadie más se enteraba. Con Tavily era peor: sin créditos, el agente seguía y
 * entregaba un Pack sin investigar.
 */
#[CoversClass(ProviderAccountStatus::class)]
#[CoversClass(OpenAIClient::class)]
#[CoversClass(TavilySearchProvider::class)]
final class ProviderAccountTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // Los requisitos consultan los documentos de conocimiento.
    'file',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * Peticiones que llegaron al proveedor simulado.
   */
  private array $enviadas = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('sales_leadership_diagnostic', ['sld_ai_usage', 'sld_research_entitlement', 'sld_evidence']);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    // Un reintento basta para distinguir «se reintentó» de «no»; con los de
    // fábrica la prueba esperaría tres segundos sin aprender nada más.
    $this->config('sales_leadership_diagnostic.settings')
      ->set('openai.model', 'modelo-de-prueba')
      ->set('openai.max_retries', 1)
      ->save();
  }

  /**
   * Sin saldo: una sola petición, una excepción propia y el aviso puesto.
   *
   * Una sola es lo que demuestra que no se reintenta: el segundo intento iba a
   * fallar igual, y solo alargaba la espera del alumno.
   */
  public function testSinSaldoNoSeReintentaPeroQuedaAnotado(): void {
    $cliente = $this->openai([$this->errorOpenAi(429, 'credit_balance_exhausted')]);

    try {
      $cliente->completeJson([['role' => 'user', 'content' => 'hola']], 'prueba', [], 'Prueba');
      $this->fail('Tenía que cortar por falta de saldo.');
    }
    catch (ProviderAccountException $e) {
      $this->assertSame(402, $e->getCode());
    }

    $this->assertCount(1, $this->enviadas, 'Sin saldo no se reintenta.');
    $this->assertTrue($this->estado()->isUnavailable(ProviderAccountStatus::IA));
    $this->assertStringContainsString('saldo', $this->estado()->problems()[ProviderAccountStatus::IA]['motivo']);
  }

  /**
   * El nombre clásico del mismo error se trata igual.
   */
  public function testInsufficientQuotaTambienEsSinSaldo(): void {
    $cliente = $this->openai([$this->errorOpenAi(429, 'insufficient_quota')]);

    $this->expectException(ProviderAccountException::class);

    try {
      $cliente->completeJson([['role' => 'user', 'content' => 'hola']], 'prueba', [], 'Prueba');
    }
    finally {
      $this->assertCount(1, $this->enviadas);
    }
  }

  /**
   * Un límite de velocidad de verdad SÍ se sigue reintentando, y no avisa.
   *
   * Es lo que funcionaba antes y no puede romperse: un 429 por ritmo es
   * transitorio, y un aviso de «sin servicio» por eso sería una falsa alarma.
   */
  public function testUnLimiteDeVelocidadSeSigueReintentandoSinAvisar(): void {
    $cliente = $this->openai([
      $this->errorOpenAi(429, 'rate_limit_exceeded'),
      $this->errorOpenAi(429, 'rate_limit_exceeded'),
    ]);

    try {
      $cliente->completeJson([['role' => 'user', 'content' => 'hola']], 'prueba', [], 'Prueba');
      $this->fail('Tenía que fallar tras los reintentos.');
    }
    catch (EngineException $e) {
      $this->assertNotInstanceOf(ProviderAccountException::class, $e);
    }

    $this->assertCount(2, $this->enviadas, 'Un límite de velocidad se reintenta.');
    $this->assertFalse($this->estado()->isUnavailable(ProviderAccountStatus::IA));
  }

  /**
   * El aviso se quita solo en cuanto el proveedor vuelve a responder bien.
   *
   * Nadie tiene que acordarse de quitarlo, que es lo que haría que un aviso
   * viejo acabara ignorándose.
   */
  public function testElAvisoSeQuitaSoloAlVolverElServicio(): void {
    $this->estado()->markUnavailable(ProviderAccountStatus::IA, 'sin saldo');

    $this->openai([$this->respuestaBuena()])
      ->completeJson([['role' => 'user', 'content' => 'hola']], 'prueba', [], 'Prueba');

    $this->assertFalse($this->estado()->isUnavailable(ProviderAccountStatus::IA));
  }

  /**
   * Se conserva la hora del PRIMER fallo: dice cuánto lleva cortado.
   */
  public function testSeConservaLaHoraDelPrimerFallo(): void {
    $this->estado()->markUnavailable(ProviderAccountStatus::IA, 'sin saldo');
    $primera = $this->estado()->problems()[ProviderAccountStatus::IA]['desde'];

    $peticion = $this->container->get('request_stack')->getCurrentRequest();
    $peticion->server->set('REQUEST_TIME', $primera + 600);
    $this->estado()->markUnavailable(ProviderAccountStatus::IA, 'sin saldo');

    $this->assertSame($primera, $this->estado()->problems()[ProviderAccountStatus::IA]['desde']);
  }

  /**
   * Tavily sin créditos: queda anotado, y el turno sigue como antes.
   *
   * Los tres códigos de cuenta de su documentación. El turno no se corta: el
   * buscador sigue diciéndole al modelo que no pudo buscar, como antes. Lo
   * nuevo es que quien opera se entera en el acto.
   */
  public function testTavilySinCreditosQuedaAnotado(): void {
    foreach ([432, 433, 401] as $codigo) {
      $this->estado()->markAvailable(ProviderAccountStatus::BUSCADOR);

      try {
        $this->tavily([new Response($codigo, [], '{"detail":{"error":"x"}}')])->search('consulta', 5);
        $this->fail("El $codigo tenía que fallar.");
      }
      catch (SearchException) {
      }

      $this->assertTrue($this->estado()->isUnavailable(ProviderAccountStatus::BUSCADOR), "El $codigo es un problema de cuenta.");
    }
  }

  /**
   * Un fallo pasajero de Tavily no es un problema de cuenta.
   */
  public function testUnFalloPasajeroDeTavilyNoAvisa(): void {
    try {
      $this->tavily([new Response(500, [], '')])->search('consulta', 5);
    }
    catch (SearchException) {
    }

    $this->assertFalse($this->estado()->isUnavailable(ProviderAccountStatus::BUSCADOR));
  }

  /**
   * Y Tavily también limpia el aviso al volver.
   */
  public function testTavilyLimpiaElAvisoAlVolver(): void {
    $this->estado()->markUnavailable(ProviderAccountStatus::BUSCADOR, 'sin créditos');

    $this->tavily([new Response(200, [], '{"results":[]}')])->search('consulta', 5);

    $this->assertFalse($this->estado()->isUnavailable(ProviderAccountStatus::BUSCADOR));
  }

  /**
   * El informe de estado solo enseña la línea cuando hay un problema.
   *
   * La comprobación tras cada despliegue cuenta cinco líneas de Diagnostic AI
   * en OK: con todo bien no puede aparecer una sexta.
   */
  public function testElInformeDeEstadoSoloAvisaCuandoHayProblema(): void {
    $requisitos = $this->container->get(DiagnosticRequirements::class);

    $this->assertArrayNotHasKey('sales_leadership_diagnostic_providers', $requisitos->runtime());

    $this->estado()->markUnavailable(ProviderAccountStatus::IA, 'la cuenta de OpenAI no tiene saldo; recárguela');
    $linea = $requisitos->runtime()['sales_leadership_diagnostic_providers'] ?? NULL;

    $this->assertNotNull($linea);
    $this->assertStringContainsString('OpenAI', (string) $linea['description']);
    $this->assertStringContainsString('saldo', (string) $linea['description']);
  }

  /**
   * El registro de cortes.
   */
  private function estado(): ProviderAccountStatus {
    return $this->container->get(ProviderAccountStatus::class);
  }

  /**
   * Un cliente de OpenAI con respuestas simuladas y su tráfico anotado.
   */
  private function openai(array $respuestas): OpenAIClient {
    $pila = HandlerStack::create(new MockHandler($respuestas));
    $pila->push(Middleware::history($this->enviadas));

    return new OpenAIClient(
      new Client(['handler' => $pila]),
      new SecretsProvider(new Settings([SecretsProvider::OPENAI_API_KEY => 'sk-de-prueba'])),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      new AiUsageCollector(),
      $this->container->get(SpendGuard::class),
      $this->estado(),
    );
  }

  /**
   * Un buscador con respuestas simuladas.
   */
  private function tavily(array $respuestas): TavilySearchProvider {
    return new TavilySearchProvider(
      new Client(['handler' => HandlerStack::create(new MockHandler($respuestas))]),
      new SecretsProvider(new Settings([SecretsProvider::SEARCH_API_KEY => 'tvly-de-prueba'])),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->estado(),
    );
  }

  /**
   * Un error del proveedor con su código, como lo devuelve.
   */
  private function errorOpenAi(int $estado, string $codigo): Response {
    return new Response($estado, [], (string) json_encode(['error' => ['code' => $codigo, 'message' => 'x']]));
  }

  /**
   * Una respuesta correcta del proveedor.
   */
  private function respuestaBuena(): Response {
    return new Response(200, [], (string) json_encode([
      'status' => 'completed',
      'output' => [
        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]],
      ],
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]));
  }

}
