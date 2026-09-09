<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Exception\SpendLimitException;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;
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
 * Comprueba que el tope de gasto IMPIDE la llamada, no que la lamente.
 *
 * La distinción es el punto entero de la Fase 5. Un contador que anota el
 * exceso después de gastarlo no es un tope: es un informe de daños. El
 * proveedor cobra el intento, así que el único momento en que un tope sirve
 * para algo es ANTES de la petición.
 *
 * Por eso la prueba central no mira el mensaje de error: mira que el cliente
 * HTTP no llegó a recibir ninguna petición.
 */
#[CoversClass(SpendGuard::class)]
#[CoversClass(OpenAIClient::class)]
final class SpendLimitTest extends KernelTestBase {

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
   * Identificador del alumno de las pruebas.
   */
  private const ALUMNO = 7;

  /**
   * Peticiones que llegaron al doble de Guzzle.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $enviadas = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('sales_leadership_diagnostic', ['sld_ai_usage', 'sld_research_entitlement', 'sld_evidence']);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    $this->config('sales_leadership_diagnostic.settings')
      ->set('openai.model', 'modelo-tarifado')
      ->set('openai.prices', [
        ['model' => 'modelo-tarifado', 'input' => 2.0, 'cached_input' => 0.2, 'output' => 12.0],
      ])
      ->save();
  }

  /**
   * Sin tope configurado no se bloquea nada.
   *
   * Es el comportamiento que el módulo ha tenido siempre. Un límite de fábrica
   * cortaría el servicio en instalaciones que nunca pidieron uno.
   */
  public function testSinTopeNoSeBloqueaNada(): void {
    $this->gastar(1000.0);

    $this->guard()->assertCanSpend(self::ALUMNO);

    $this->expectNotToPerformAssertions();
  }

  /**
   * Al alcanzar su tope, el alumno no puede provocar otra llamada.
   */
  public function testAlAlcanzarSuTopeElAlumnoSeBloquea(): void {
    $this->fijarTopes(porAlumno: 1.0);
    $this->gastar(1.0);

    $this->expectException(SpendLimitException::class);

    $this->guard()->assertCanSpend(self::ALUMNO);
  }

  /**
   * Justo por debajo del tope todavía puede.
   *
   * Fija el borde: si la comparación fuera «mayor que» en vez de «mayor o
   * igual», nadie se bloquearía nunca al llegar exactamente al tope.
   */
  public function testJustoPorDebajoDelTopeTodaviaPuede(): void {
    $this->fijarTopes(porAlumno: 1.0);
    $this->gastar(0.999);

    $this->guard()->assertCanSpend(self::ALUMNO);

    $this->expectNotToPerformAssertions();
  }

  /**
   * El tope global bloquea aunque el alumno tenga margen de sobra.
   */
  public function testElTopeGlobalBloqueaAunqueElAlumnoTengaMargen(): void {
    $this->fijarTopes(porAlumno: 100.0, global: 1.0);
    $this->gastar(1.0, uid: 999);

    $this->expectException(SpendLimitException::class);

    $this->guard()->assertCanSpend(self::ALUMNO);
  }

  /**
   * LA PRUEBA QUE IMPORTA: sin margen, la petición NO se hace.
   *
   * No basta con que se lance una excepción. Si la llamada saliera y el error
   * llegara después, el proveedor ya habría cobrado y el tope no habría
   * servido para nada.
   */
  public function testSinMargenLaPeticionNoSale(): void {
    $this->fijarTopes(global: 1.0);
    $this->gastar(5.0);

    try {
      $this->cliente()->completeJson([['role' => 'user', 'content' => 'hola']], 'prueba', [], 'Prueba');
      $this->fail('Debería haberse impedido la llamada.');
    }
    catch (SpendLimitException) {
      // Es lo esperado.
    }

    $this->assertSame([], $this->enviadas, 'No debe salir NINGUNA petición cuando el presupuesto está agotado.');
  }

  /**
   * Y con margen, sale exactamente una.
   *
   * Sin esta, la anterior pasaría igual con un cliente roto que no llama nunca.
   */
  public function testConMargenLaPeticionSiSale(): void {
    $this->fijarTopes(global: 100.0);

    $this->cliente()->completeJson([['role' => 'user', 'content' => 'hola']], 'prueba', [], 'Prueba');

    $this->assertCount(1, $this->enviadas);
  }

  /**
   * Lo gastado el mes pasado no bloquea este.
   *
   * El periodo es el mes natural, y eso importa: con una ventana móvil de
   * treinta días, quien se pasó queda bloqueado sin una fecha clara en la que
   * deje de estarlo, y no hay nada que decirle.
   */
  public function testLoGastadoElMesPasadoNoBloqueaEste(): void {
    $this->fijarTopes(porAlumno: 1.0);
    $this->gastar(5.0, cuando: $this->guard()->periodStart() - 86400);

    $this->guard()->assertCanSpend(self::ALUMNO);

    $this->expectNotToPerformAssertions();
  }

  /**
   * Los ensayos del estudio no consumen el cupo del alumno.
   *
   * Los hace quien administra para probar un prompt. Cargárselos a quien
   * figure como dueño de la sesión le comería su diagnóstico sin haber hecho
   * nada.
   */
  public function testLosEnsayosNoConsumenElCupoDelAlumno(): void {
    $this->fijarTopes(porAlumno: 1.0);
    $this->gastar(5.0, esEnsayo: TRUE);

    $this->guard()->assertCanSpend(self::ALUMNO);

    $this->expectNotToPerformAssertions();
  }

  /**
   * Pero sí cuentan contra el tope global, porque la factura no distingue.
   */
  public function testLosEnsayosSiCuentanContraElTopeGlobal(): void {
    $this->fijarTopes(global: 1.0);
    $this->gastar(5.0, esEnsayo: TRUE);

    $this->expectException(SpendLimitException::class);

    $this->guard()->assertGlobalHeadroom();
  }

  /**
   * El estado que se enseña distingue «sin tope» de «al 0 %».
   *
   * Son cosas distintas y confundirlas en una pantalla lleva a creer que hay
   * un control puesto cuando no lo hay.
   */
  public function testSinTopeElEstadoEsNuloNoCero(): void {
    $this->assertNull($this->guard()->statusForUser(self::ALUMNO));

    $this->fijarTopes(porAlumno: 10.0);
    $this->gastar(2.5);

    $estado = $this->guard()->statusForUser(self::ALUMNO);

    $this->assertSame(25, $estado['percent']);
    $this->assertFalse($estado['blocked']);
  }

  /**
   * Fija los topes en configuración.
   */
  private function fijarTopes(float $porAlumno = 0.0, float $global = 0.0): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('spending.per_user_limit', $porAlumno)
      ->set('spending.global_limit', $global)
      ->save();
  }

  /**
   * Anota gasto ya consumido.
   *
   * Se escribe directamente para poder situarlo en el tiempo: el repositorio
   * sella siempre con la hora de la petición, y una de las pruebas necesita
   * gasto del mes pasado.
   */
  private function gastar(float $usd, int $uid = self::ALUMNO, bool $esEnsayo = FALSE, ?int $cuando = NULL): void {
    $this->container->get('database')->insert('sld_ai_usage')->fields([
      'uid' => $uid,
      'agent' => 'agente_de_prueba',
      'session_id' => 1,
      'purpose' => 'Prueba',
      'model' => 'modelo-tarifado',
      'input_tokens' => 1000,
      'cached_input_tokens' => 0,
      'output_tokens' => 0,
      'reasoning_tokens' => 0,
      'cost_usd' => $usd,
      'latency_ms' => 100,
      'attempts' => 1,
      'failed' => 0,
      'is_sandbox' => $esEnsayo ? 1 : 0,
      'created' => $cuando ?? $this->container->get('datetime.time')->getRequestTime(),
    ])->execute();
  }

  /**
   * El guardián, tomado del contenedor.
   */
  private function guard(): SpendGuard {
    return $this->container->get(SpendGuard::class);
  }

  /**
   * Un cliente de OpenAI cuyo transporte queda registrado.
   *
   * Se construye a mano y no se toma del contenedor para poder mirar si le
   * llegó alguna petición, que es lo único que prueba que el tope funciona.
   */
  private function cliente(): OpenAIClient {
    $pila = HandlerStack::create(new MockHandler([
      new Response(200, [], (string) json_encode([
        'status' => 'completed',
        'output' => [
          [
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => '{"ok":true}']],
          ],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
      ])),
    ]));
    $pila->push(Middleware::history($this->enviadas));

    return new OpenAIClient(
      new Client(['handler' => $pila]),
      new SecretsProvider(new Settings([SecretsProvider::OPENAI_API_KEY => 'sk-de-prueba'])),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      new AiUsageCollector(),
      $this->guard(),
    );
  }

}
