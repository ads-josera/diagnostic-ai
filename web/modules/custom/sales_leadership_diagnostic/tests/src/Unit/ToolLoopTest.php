<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolInterface;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageCollector;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageRepository;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\PriceList;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba el ciclo de herramientas contra lo que hace el proveedor de verdad.
 *
 * Un turno del alumno puede ser VARIAS llamadas: el modelo pide una
 * herramienta, se le da el resultado, y con él pide otra. Al sondearlo el
 * 08-09-2026 pidió dos búsquedas más nada más recibir la primera.
 *
 * Lo que estas pruebas fijan no se puede deducir leyendo la documentación:
 * hubo que preguntárselo a la API. En concreto, que **hay que reenviarle su
 * propio razonamiento** junto a la petición de herramienta. Devolver solo el
 * resultado da un 400 que nombra el elemento de razonamiento que falta, y sin
 * una prueba que lo fije, cualquier limpieza razonable del código —«esto del
 * razonamiento no lo usamos, fuera»— rompe la búsqueda entera.
 */
#[CoversClass(OpenAIClient::class)]
#[CoversClass(ToolBox::class)]
final class ToolLoopTest extends UnitTestCase {

  /**
   * Peticiones que llegaron al doble de Guzzle.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $enviadas = [];

  /**
   * Sin herramientas no se declara nada al proveedor.
   *
   * Ni siquiera una lista vacía. Declarar herramientas cambia el prefijo del
   * prompt, y con él se pierde la caché de todos los turnos que no las
   * necesitan, que son casi todos.
   */
  public function testSinHerramientasNoSeDeclaraNada(): void {
    $this->llamar([$this->respuestaFinal(['ok' => TRUE])], NULL);

    $this->assertArrayNotHasKey('tools', $this->cuerpo(0));
  }

  /**
   * Con herramientas, se declaran.
   */
  public function testConHerramientasSeDeclaran(): void {
    $this->llamar([$this->respuestaFinal(['ok' => TRUE])], new ToolBox([$this->herramienta()]));

    $this->assertSame('buscar_algo', $this->cuerpo(0)['tools'][0]['name']);
  }

  /**
   * El ciclo completo: pide, se ejecuta, se le devuelve y contesta.
   */
  public function testElCicloCompletoDeUnaHerramienta(): void {
    $herramienta = $this->herramienta();

    $objeto = $this->llamar([
      $this->respuestaConPeticion('buscar_algo', ['q' => 'cemex']),
      $this->respuestaFinal(['ok' => TRUE]),
    ], new ToolBox([$herramienta]));

    $this->assertSame(['ok' => TRUE], $objeto);
    $this->assertSame([['q' => 'cemex']], $herramienta->recibidos);
    $this->assertCount(2, $this->enviadas, 'Un turno con herramienta son DOS llamadas al proveedor.');
  }

  /**
   * Se le reenvía SU RAZONAMIENTO junto a la petición.
   *
   * Es el hallazgo que costó una sonda: el proveedor rechaza con un 400 una
   * petición de herramienta que llega sin el elemento de razonamiento que la
   * acompañaba. Sin esta prueba, quitar «eso que no usamos» rompe la búsqueda.
   */
  public function testSeLeReenviaSuRazonamiento(): void {
    $this->llamar([
      $this->respuestaConPeticion('buscar_algo', ['q' => 'cemex']),
      $this->respuestaFinal(['ok' => TRUE]),
    ], new ToolBox([$this->herramienta()]));

    $tipos = array_column($this->cuerpo(1)['input'], 'type');

    $this->assertContains('reasoning', $tipos, 'El razonamiento del modelo debe volver a viajar, o el proveedor rechaza la petición.');
    $this->assertContains('function_call', $tipos);
    $this->assertContains('function_call_output', $tipos);
  }

  /**
   * El resultado se ata a la petición por su call_id.
   */
  public function testElResultadoSeAtaConSuPeticion(): void {
    $this->llamar([
      $this->respuestaConPeticion('buscar_algo', ['q' => 'cemex']),
      $this->respuestaFinal(['ok' => TRUE]),
    ], new ToolBox([$this->herramienta()]));

    $salida = NULL;

    foreach ($this->cuerpo(1)['input'] as $item) {
      if (($item['type'] ?? '') === 'function_call_output') {
        $salida = $item;
      }
    }

    $this->assertSame('call_1', $salida['call_id']);
    $this->assertSame('{"resultado":"algo"}', $salida['output']);
  }

  /**
   * Si el modelo no para de pedir, se corta.
   *
   * Cada vuelta es otra llamada al proveedor y, con búsqueda, varias búsquedas
   * más. Darle una ronda de más en silencio es cómo un turno se convierte en
   * una factura.
   */
  public function testSiNoParaDePedirSeCorta(): void {
    $this->expectException(InvalidEngineResponseException::class);
    $this->expectExceptionMessage('límite de vueltas');

    $this->llamar([
      $this->respuestaConPeticion('buscar_algo', ['q' => '1']),
      $this->respuestaConPeticion('buscar_algo', ['q' => '2']),
      $this->respuestaConPeticion('buscar_algo', ['q' => '3']),
    ], new ToolBox([$this->herramienta()]), vueltas: 2);
  }

  /**
   * Una herramienta que no existe no revienta el turno.
   *
   * El modelo puede pedir cualquier cosa —lo hace—, y tratar eso como un error
   * del sistema convertiría una alucinación suya en una caída nuestra.
   */
  public function testUnaHerramientaDesconocidaNoRevientaElTurno(): void {
    $objeto = $this->llamar([
      $this->respuestaConPeticion('herramienta_inventada', []),
      $this->respuestaFinal(['ok' => TRUE]),
    ], new ToolBox([$this->herramienta()]));

    $this->assertSame(['ok' => TRUE], $objeto);

    $salida = '';

    foreach ($this->cuerpo(1)['input'] as $item) {
      if (($item['type'] ?? '') === 'function_call_output') {
        $salida = $item['output'];
      }
    }

    $this->assertStringContainsString('no existe', $salida);
  }

  /**
   * Una herramienta con nombre desconocido, en la caja.
   */
  private function herramienta(): ToolInterface {
    return new class() implements ToolInterface {

      /**
       * Argumentos con los que se la llamó.
       *
       * @var array<int, array<string, mixed>>
       */
      public array $recibidos = [];

      /**
       * {@inheritdoc}
       */
      public function name(): string {
        return 'buscar_algo';
      }

      /**
       * {@inheritdoc}
       */
      public function declaration(): array {
        return ['type' => 'function', 'name' => 'buscar_algo', 'parameters' => []];
      }

      /**
       * {@inheritdoc}
       */
      public function run(array $arguments): string {
        $this->recibidos[] = $arguments;

        return '{"resultado":"algo"}';
      }

    };
  }

  /**
   * Una respuesta en la que el modelo pide una herramienta.
   *
   * Lleva el razonamiento delante, como llega de verdad.
   *
   * @param string $nombre
   *   Herramienta que pide el modelo.
   * @param array<string, mixed> $argumentos
   *   Lo que el modelo pasaría a la herramienta.
   */
  private function respuestaConPeticion(string $nombre, array $argumentos): Response {
    return new Response(200, [], (string) json_encode([
      'status' => 'completed',
      'output' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
        [
          'type' => 'function_call',
          'id' => 'fc_1',
          'call_id' => 'call_1',
          'name' => $nombre,
          'arguments' => json_encode($argumentos),
        ],
      ],
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]));
  }

  /**
   * Una respuesta final, con el objeto pedido.
   *
   * @param array<string, mixed> $objeto
   *   Lo que se quiere que haya devuelto.
   */
  private function respuestaFinal(array $objeto): Response {
    return new Response(200, [], (string) json_encode([
      'status' => 'completed',
      'output' => [
        ['type' => 'reasoning', 'id' => 'rs_2', 'summary' => []],
        [
          'type' => 'message',
          'content' => [['type' => 'output_text', 'text' => json_encode($objeto)]],
        ],
      ],
      'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
    ]));
  }

  /**
   * Cuerpo de la petición número indicada.
   *
   * @return array<string, mixed>
   *   Lo que se envió.
   */
  private function cuerpo(int $n): array {
    return json_decode((string) $this->enviadas[$n]['request']->getBody(), TRUE);
  }

  /**
   * Ejecuta una conversación contra las respuestas preparadas.
   *
   * @param \GuzzleHttp\Psr7\Response[] $respuestas
   *   Cola de respuestas del proveedor.
   * @param \Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox|null $tools
   *   Herramientas del turno.
   * @param int $vueltas
   *   Tope de vueltas configurado.
   *
   * @return array<string, mixed>
   *   El objeto final.
   */
  private function llamar(array $respuestas, ?ToolBox $tools, int $vueltas = 4): array {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $pila = HandlerStack::create(new MockHandler($respuestas));
    $pila->push(Middleware::history($this->enviadas));

    $configFactory = $this->getConfigFactoryStub([
      'sales_leadership_diagnostic.settings' => [
        'openai' => [
          'model' => 'modelo-de-prueba',
          'timeout' => 30,
          'max_retries' => 0,
          'max_completion_tokens' => 2000,
        ],
        'search' => ['max_tool_rounds' => $vueltas],
      ],
    ]);

    $cliente = new OpenAIClient(
      new Client(['handler' => $pila]),
      new SecretsProvider(new Settings([SecretsProvider::OPENAI_API_KEY => 'sk-de-prueba'])),
      $configFactory,
      $loggerFactory,
      new AiUsageCollector(),
      new SpendGuard(
        new AiUsageRepository(
          $this->createMock(Connection::class),
          new PriceList($configFactory),
          $this->createMock(TimeInterface::class),
        ),
        $configFactory,
        $this->createMock(TimeInterface::class),
        $this->createMock(StateInterface::class),
        $loggerFactory,
      ),
    );

    return $cliente->completeJson([['role' => 'user', 'content' => 'hola']], 'prueba', [], 'Prueba', NULL, $tools);
  }

}
