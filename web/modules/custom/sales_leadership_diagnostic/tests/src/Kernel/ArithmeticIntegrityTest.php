<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIDiagnosticProvider;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBoxFactory;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageCollector;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Un informe cuyo global no es la suma de sus dimensiones no llega al alumno.
 *
 * En las pruebas del cliente del 12-09-2026 pasó una vez de veintidós: las
 * dimensiones sumaban 10 y el informe decía 11/100. Su metodología lo trata
 * como fallo de tolerancia cero. La plataforma le devuelve el informe al
 * agente para que lo corrija antes de guardarlo; nunca cambia la cifra por su
 * cuenta.
 *
 * El proveedor se sustituye por un doble de Guzzle, y se registra lo que se le
 * envía: el número de llamadas es lo que dice si se pagó una de más.
 */
#[CoversClass(OpenAIDiagnosticProvider::class)]
final class ArithmeticIntegrityTest extends KernelTestBase {

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
   * Las peticiones que recibió el proveedor.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $enviadas = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['sales_leadership_diagnostic']);
    $this->config('sales_leadership_diagnostic.settings')
      ->set('openai.model', 'gpt-prueba')
      ->set('openai.max_retries', 0)
      ->save();
  }

  /**
   * Un informe que no cuadra se corrige antes de guardarse.
   */
  public function testUnInformeQueNoCuadraSeCorrige(): void {
    $turno = $this->motor([$this->informe(11), $this->informe(10)])->process($this->contexto());

    $this->assertSame(10, $turno->result['score'], 'Se guarda el informe corregido.');
    $this->assertCount(2, $this->enviadas);

    // La segunda llamada lleva el aviso con las dos cifras.
    $cuerpo = (string) $this->enviadas[1]['request']->getBody();
    $this->assertStringContainsString('suman 10', $cuerpo);
    $this->assertStringContainsString('declarado es 11', $cuerpo);
  }

  /**
   * Si sigue sin cuadrar, se guarda igual: el alumno no se queda sin informe.
   */
  public function testSiSigueSinCuadrarNoSeQuedaSinInforme(): void {
    $turno = $this->motor([$this->informe(11), $this->informe(12)])->process($this->contexto());

    $this->assertTrue($turno->completed);
    $this->assertCount(2, $this->enviadas, 'Se pide una sola corrección, no más.');
  }

  /**
   * Un informe que cuadra no paga ninguna llamada más.
   *
   * La segunda respuesta de la cola existe a propósito: si se pidiera una
   * corrección sin motivo, se consumiría.
   */
  public function testUnInformeQueCuadraNoPagaNadaMas(): void {
    $turno = $this->motor([$this->informe(10), $this->informe(99)])->process($this->contexto());

    $this->assertSame(10, $turno->result['score']);
    $this->assertCount(1, $this->enviadas);
  }

  /**
   * Si la corrección ya no es un informe, se conserva el original.
   */
  public function testSiLaCorreccionNoEsUnInformeSeConservaElOriginal(): void {
    $conversacion = [
      'type' => 'diagnostic_response',
      'status' => 'in_progress',
      'message' => '¿Quieres que lo revisemos juntos?',
      'result' => NULL,
    ];

    $turno = $this->motor([$this->informe(11), $this->respuesta($conversacion)])->process($this->contexto());

    $this->assertTrue($turno->completed, 'Se guarda el informe original.');
    $this->assertSame(11, $turno->result['score']);
  }

  /**
   * Un diagnóstico parcial no tiene global y no se toca.
   */
  public function testUnParcialNoSeToca(): void {
    $this->motor([$this->informe(NULL), $this->informe(10)])->process($this->contexto());

    $this->assertCount(1, $this->enviadas);
  }

  /**
   * El motor con un proveedor que devuelve estas respuestas, en orden.
   *
   * @param \GuzzleHttp\Psr7\Response[] $respuestas
   *   Lo que devolverá el proveedor.
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
    );

    return new OpenAIDiagnosticProvider(
      $cliente,
      $this->container->get(DiagnosticResponseValidator::class),
      $this->container->get(ToolBoxFactory::class),
      $this->container->get('logger.factory'),
    );
  }

  /**
   * Un turno cualquiera: lo que importa es lo que responde el proveedor.
   */
  private function contexto(): DiagnosticContext {
    return new DiagnosticContext('Prompt.', [], '1.0', 30, 60);
  }

  /**
   * Un informe final con diez dimensiones de 1 punto: suman 10.
   *
   * @param int|null $global
   *   El Score global que declara; NULL para un diagnóstico parcial.
   */
  private function informe(?int $global): Response {
    $dimensiones = [];
    for ($i = 1; $i <= 10; $i++) {
      $dimensiones[] = [
        'name' => "Dimensión $i",
        'score' => 1,
        'max' => 10,
        'level' => 'CRÍTICO',
        'confidence' => 'BAJA',
      ];
    }

    return $this->respuesta([
      'type' => 'diagnostic_result',
      'status' => 'completed',
      'message' => 'Informe final.',
      'result' => ['summary' => 'Resumen.', 'score' => $global, 'dimensions' => $dimensiones],
    ]);
  }

  /**
   * Una respuesta del proveedor con este objeto dentro.
   *
   * @param array<string, mixed> $objeto
   *   Lo que devolvió el modelo.
   */
  private function respuesta(array $objeto): Response {
    return new Response(200, [], (string) json_encode([
      'status' => 'completed',
      'output' => [
        [
          'type' => 'message',
          'content' => [['type' => 'output_text', 'text' => json_encode($objeto)]],
        ],
      ],
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]));
  }

}
