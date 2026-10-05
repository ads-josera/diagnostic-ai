<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\sales_leadership_diagnostic\Service\Research\SourceRepair;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIDiagnosticProvider;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBoxFactory;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Research\BuyerEvidenceCheck;
use Drupal\sales_leadership_diagnostic\Service\Research\RetrievedPages;
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
 * Un comprador verificado sin fuente que lo nombre se corrige antes de guardar.
 *
 * Mismo camino que el informe cuya suma no cuadra: se le pide al agente que lo
 * arregle y, si no lo arregla, la plataforma lo baja a no verificado y lo dice
 * en el Pack. Lo que nunca llega al cliente es un «verificado» sin respaldo,
 * que es lo que pasó el 03-10-2026 con el directivo de un banco.
 */
#[CoversClass(OpenAIDiagnosticProvider::class)]
final class BuyerCorrectionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * Conversación de las pruebas.
   */
  private const SESION = 37;

  /**
   * La página que lo nombra.
   */
  private const BUENA = 'https://amcham.ec/lasso-ceo';

  /**
   * La página del banco que no lo nombra.
   */
  private const MALA = 'https://ekos.ec/banco-150-millones';

  /**
   * Peticiones que llegaron al proveedor simulado.
   */
  private array $enviadas = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('sales_leadership_diagnostic', ['sld_tool_call']);
    $this->installConfig(['sales_leadership_diagnostic']);
    $this->config('sales_leadership_diagnostic.settings')
      ->set('openai.model', 'gpt-prueba')
      ->set('openai.max_retries', 0)
      ->save();

    $this->container->get(CurrentTurn::class)->begin(14, self::SESION, FALSE, 'prospecting_diagnostic');

    $paginas = [
      self::BUENA => 'Guillermo Lasso Alcivar, CEO de Banco Guayaquil.',
      self::MALA => 'Banco Guayaquil sube su capital en 150 millones.',
    ];

    foreach ($paginas as $url => $texto) {
      $this->container->get(ToolCallRepository::class)->record(
        uid: 14, sessionId: self::SESION, tool: WebSearchTool::NAME, query: 'x', allowed: TRUE, results: 1, resultUrls: [$url],
      );
      $this->container->get(RetrievedPages::class)->remember(self::SESION, $url, $texto);
    }
  }

  /**
   * Si el agente corrige la fuente, se guarda el Pack corregido.
   */
  public function testSiElAgenteCitaLaFuenteBuenaSeGuardaCorregido(): void {
    $turno = $this->motor([$this->pack(self::MALA), $this->pack(self::BUENA)])->process($this->contexto());

    $this->assertCount(2, $this->enviadas, 'Se pide una corrección.');
    $this->assertTrue($turno->result['accounts'][0]['buyer_verified']);
    $this->assertSame(self::BUENA, $turno->result['accounts'][0]['buyer_source']);
    $this->assertStringNotContainsString('Comprobación de la plataforma', $turno->message);
  }

  /**
   * La corrección que se le pide nombra la cuenta y el motivo.
   *
   * Sin eso el agente no sabe qué arreglar, y una petición vaga es una llamada
   * pagada que no cambia nada.
   */
  public function testLaCorreccionNombraLaCuentaConSuMotivo(): void {
    $this->motor([$this->pack(self::MALA), $this->pack(self::BUENA)])->process($this->contexto());

    $peticion = (string) $this->enviadas[1]['request']->getBody();
    $this->assertStringContainsString('Control de evidencia', $peticion);
    $this->assertStringContainsString('Banco Guayaquil', $peticion);
    $this->assertStringContainsString('no lo nombra', $peticion);
  }

  /**
   * Si sigue sin respaldo, se baja a no verificado y el Pack lo dice.
   */
  public function testSiSigueSinRespaldoSeBajaConNotaEnElPack(): void {
    $turno = $this->motor([$this->pack(self::MALA), $this->pack(self::MALA)])->process($this->contexto());

    $this->assertFalse($turno->result['accounts'][0]['buyer_verified']);
    $this->assertSame('', $turno->result['accounts'][0]['buyer_source']);
    $this->assertStringContainsString('Comprobación de la plataforma', $turno->message);
    $this->assertStringContainsString('Banco Guayaquil', $turno->message);
  }

  /**
   * Si la corrección falla, tampoco sale un «verificado» sin respaldo.
   */
  public function testSiLaCorreccionFallaTambienSeBaja(): void {
    $turno = $this->motor([$this->pack(self::MALA), new Response(500, [], '')])->process($this->contexto());

    $this->assertFalse($turno->result['accounts'][0]['buyer_verified']);
    $this->assertStringContainsString('Comprobación de la plataforma', $turno->message);
  }

  /**
   * Si la fuente del comprador es una dirección retocada, se repara sin pagar.
   *
   * El agente quitó una palabra a la dirección de la página buena. La búsqueda
   * la trajo entera, así que la plataforma la devuelve a su sitio y la
   * comprobación lee la buena: ni corrección, ni comprador perdido.
   */
  public function testUnaFuenteRetocadaSeReparaSinPedirCorreccion(): void {
    $retocada = 'https://amcham.ec/lasso-el-ceo';

    $turno = $this->motor([$this->pack($retocada, $retocada)])->process($this->contexto());

    $this->assertCount(1, $this->enviadas, 'No hace falta pedir corrección.');
    $this->assertTrue($turno->result['accounts'][0]['buyer_verified']);
    $this->assertSame(self::BUENA, $turno->result['accounts'][0]['buyer_source']);
    $this->assertSame(self::BUENA, $turno->result['accounts'][0]['sources'][0]['url']);
  }

  /**
   * Con todo respaldado no se paga nada más.
   */
  public function testConTodoRespaldadoNoSePagaNadaMas(): void {
    $turno = $this->motor([$this->pack(self::BUENA)])->process($this->contexto());

    $this->assertCount(1, $this->enviadas);
    $this->assertTrue($turno->result['accounts'][0]['buyer_verified']);
  }

  /**
   * El motor con las respuestas simuladas.
   */
  private function motor(array $respuestas): OpenAIDiagnosticProvider {
    $pila = HandlerStack::create(new MockHandler($respuestas));
    $pila->push(Middleware::history($this->enviadas));

    $cliente = new OpenAIClient(
      new Client(['handler' => $pila]),
      new SecretsProvider(new Settings([SecretsProvider::OPENAI_API_KEY => 'sk-de-prueba'])),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      new AiUsageCollector(),
      $this->container->get(SpendGuard::class),
      $this->container->get(ProviderAccountStatus::class),
    );

    return new OpenAIDiagnosticProvider(
      $cliente,
      $this->container->get(DiagnosticResponseValidator::class),
      $this->container->get(ToolBoxFactory::class),
      $this->container->get('logger.factory'),
      $this->container->get(BuyerEvidenceCheck::class),
      $this->container->get(SourceRepair::class),
    );
  }

  /**
   * Un turno cualquiera: lo que importa es lo que responde el proveedor.
   */
  private function contexto(): DiagnosticContext {
    return new DiagnosticContext('Prompt.', [], '1.0', 3, 60);
  }

  /**
   * Un Pack con un comprador verificado y la fuente que diga el agente.
   */
  private function pack(string $fuente, string $primera = self::BUENA): Response {
    return $this->respuesta([
      'type' => 'diagnostic_result',
      'status' => 'completed',
      'message' => 'Weekly GOLD Pack. Buyer verificado: Guillermo Lasso Alcívar.',
      'result' => [
        'summary' => 'Pack.',
        'score' => NULL,
        'dimensions' => [],
        'accounts' => [[
          'name' => 'Banco Guayaquil',
          'disposition' => 'SILVER',
          'rank' => 1,
          'outreach_status' => 'BLOCKED',
          'blocked_reason' => 'Sin CRM',
          'why_now' => 'Capital',
          'gap_hypothesis' => 'x',
          'competing_alternative' => 'x',
          'buyer' => 'Guillermo Lasso Alcívar — CEO de Banco Guayaquil',
          'buyer_verified' => TRUE,
          'buyer_source' => $fuente,
          'do_not_claim' => [],
          'routing' => 'ABM',
          'outreach_message' => '',
          'next_step' => 'x',
          'sources' => [
            ['url' => $primera, 'label' => 'AMCHAM', 'published' => '2026-07-25'],
            ['url' => self::MALA, 'label' => 'Ekos', 'published' => ''],
          ],
        ],
        ],
      ],
    ]);
  }

  /**
   * Una respuesta del proveedor con este objeto dentro.
   */
  private function respuesta(array $objeto): Response {
    return new Response(200, [], (string) json_encode([
      'status' => 'completed',
      'output' => [
        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($objeto)]]],
      ],
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]));
  }

}
