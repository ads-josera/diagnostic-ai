<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolGateway;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolInterface;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba que el gateway IMPIDE la búsqueda, no que la lamente.
 *
 * Es la misma distinción que en los topes de gasto y por el mismo motivo: una
 * búsqueda denegada después de hacerse ya se pagó y ya entró al contexto. Por
 * eso lo que estas pruebas miran no es el mensaje de error, sino que **la
 * herramienta no llegó a ejecutarse**.
 *
 * La prueba que de verdad importa es la del bypass. Un tope por misión solo
 * parece suficiente hasta que alguien abre otra conversación; la propia
 * especificación del cliente lo nombra en su §6 —«evitar bypass por nueva
 * conversación, nuevo dispositivo, Entry Mode distinto»— y por eso hay un
 * segundo contador que ninguna conversación nueva reinicia.
 */
#[CoversClass(ToolGateway::class)]
final class ToolGatewayTest extends KernelTestBase {

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
   * Alumno de las pruebas.
   */
  private const ALUMNO = 7;

  /**
   * Misión de las pruebas.
   */
  private const MISION = 42;

  /**
   * La herramienta espía: cuenta cuántas veces se la ejecutó de verdad.
   */
  private ToolInterface $espia;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('sales_leadership_diagnostic', ['sld_ai_usage', 'sld_tool_call']);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    $this->espia = new class() implements ToolInterface {

      /**
       * Cuántas veces se ejecutó.
       */
      public int $veces = 0;

      /**
       * {@inheritdoc}
       */
      public function name(): string {
        return 'buscar_web';
      }

      /**
       * {@inheritdoc}
       */
      public function declaration(): array {
        return ['type' => 'function', 'name' => 'buscar_web'];
      }

      /**
       * {@inheritdoc}
       */
      public function run(array $arguments): string {
        $this->veces++;

        return (string) json_encode(['resultados' => [['titulo' => 'x', 'url' => 'https://x.mx']]]);
      }

    };
  }

  /**
   * Sin turno declarado, la herramienta NO se ejecuta.
   *
   * Una búsqueda sin dueño no se puede acotar ni auditar. Se prefiere una que
   * no ocurre a una que ocurre sin que se sepa a cuenta de quién.
   */
  public function testSinTurnoLaHerramientaNoSeEjecuta(): void {
    $salida = $this->gateway()->run('buscar_web', ['consulta' => 'cemex']);

    $this->assertSame(0, $this->espia->veces, 'La herramienta NO debe llegar a ejecutarse.');
    $this->assertStringContainsString('NO AUTORIZADA', $salida);
  }

  /**
   * Con turno y margen, pasa.
   */
  public function testConTurnoMargenPasa(): void {
    $this->turno();

    $this->gateway()->run('buscar_web', ['consulta' => 'cemex']);

    $this->assertSame(1, $this->espia->veces);
  }

  /**
   * Al llegar al tope de la misión, deja de ejecutarse.
   */
  public function testAlLlegarAlTopeDeLaMisionDejaDeEjecutarse(): void {
    $this->fijarTopes(porMision: 2);
    $this->turno();
    $gateway = $this->gateway();

    for ($i = 0; $i < 4; $i++) {
      $gateway->run('buscar_web', ['consulta' => 'cemex']);
    }

    $this->assertSame(2, $this->espia->veces, 'Solo deben ejecutarse las dos que caben.');
  }

  /**
   * EL BYPASS: abrir otra misión NO devuelve el cupo.
   *
   * Sin el segundo contador, cualquiera recupera búsquedas empezando una
   * conversación nueva, y el tope deja de ser un tope.
   */
  public function testAbrirOtraMisionNoDevuelveElCupo(): void {
    $this->fijarTopes(porMision: 10, porPeriodo: 2);
    $gateway = $this->gateway();

    $this->turno(mision: 100);
    $gateway->run('buscar_web', ['consulta' => 'a']);
    $gateway->run('buscar_web', ['consulta' => 'b']);

    // Conversación nueva, misión nueva, mismo alumno.
    $this->turno(mision: 200);
    $gateway->run('buscar_web', ['consulta' => 'c']);

    $this->assertSame(2, $this->espia->veces, 'La misión nueva NO debe devolver búsquedas.');
  }

  /**
   * Una denegada no consume cupo.
   *
   * No gastó nada. Contarla dejaría al agente sin margen por haber intentado
   * algo que no se le dejó hacer.
   */
  public function testUnaDenegadaNoConsumeCupo(): void {
    $this->fijarTopes(porMision: 1);
    $this->turno();
    $gateway = $this->gateway();

    $gateway->run('buscar_web', ['consulta' => 'a']);
    $gateway->run('buscar_web', ['consulta' => 'b']);
    $gateway->run('buscar_web', ['consulta' => 'c']);

    $repositorio = $this->container->get(ToolCallRepository::class);

    $this->assertSame(1, $repositorio->usedInMission(self::MISION)['calls']);
    $this->assertSame(2, array_sum($repositorio->denialsSince(0)));
  }

  /**
   * Se acota cuánto texto externo entra en una misión.
   *
   * Es lo que pide el §9 de la especificación —«caps por tokens web
   * recuperados»— y es donde está la mayor parte de lo que cuesta buscar.
   */
  public function testSeAcotaElTextoQueEntraEnUnaMision(): void {
    $this->fijarTopes(porMision: 100, porTexto: 10);
    $this->turno();
    $gateway = $this->gateway();

    $gateway->run('buscar_web', ['consulta' => 'a']);
    $gateway->run('buscar_web', ['consulta' => 'b']);

    $this->assertSame(1, $this->espia->veces, 'La segunda debe caer por el tope de texto.');
  }

  /**
   * La negativa le dice al modelo que NO invente.
   *
   * Devolverle una lista vacía le haría creer que buscó y no encontró nada, y
   * con eso cerraría una misión como ZERO-GOLD sin haber mirado.
   */
  public function testLaNegativaLeDiceQueNoInvente(): void {
    $salida = $this->gateway()->run('buscar_web', ['consulta' => 'cemex']);

    $this->assertStringContainsString('NO inventes', $salida);
    $this->assertStringContainsString('CHECK REQUIRED', $salida);
  }

  /**
   * Los ensayos del gestor no gastan el cupo del alumno.
   */
  public function testLosEnsayosNoGastanElCupoDelPeriodo(): void {
    $this->fijarTopes(porMision: 100, porPeriodo: 1);
    $gateway = $this->gateway();

    $this->turno(mision: 100, ensayo: TRUE);
    $gateway->run('buscar_web', ['consulta' => 'a']);
    $gateway->run('buscar_web', ['consulta' => 'b']);
    $gateway->run('buscar_web', ['consulta' => 'c']);

    $this->assertSame(3, $this->espia->veces, 'Un ensayo no debe toparse con el cupo del periodo.');
  }

  /**
   * Queda anotado qué se denegó y por qué.
   */
  public function testQuedaAnotadoQueSeDenegoPorQue(): void {
    $this->gateway()->run('buscar_web', ['consulta' => 'cemex']);

    $this->assertSame(
      ['sin_turno' => 1],
      $this->container->get(ToolCallRepository::class)->denialsSince(0),
    );
  }

  /**
   * Declara de quién es el turno.
   */
  private function turno(int $mision = self::MISION, bool $ensayo = FALSE): void {
    $this->container->get(CurrentTurn::class)->begin(self::ALUMNO, $mision, $ensayo);
  }

  /**
   * Fija los topes de herramientas.
   */
  private function fijarTopes(int $porMision = 0, int $porTexto = 0, int $porPeriodo = 0): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('tools.max_calls_per_mission', $porMision)
      ->set('tools.max_retrieved_chars_per_mission', $porTexto)
      ->set('tools.max_calls_per_user_period', $porPeriodo)
      ->save();
  }

  /**
   * El gateway envolviendo a la herramienta espía.
   */
  private function gateway(): ToolGateway {
    return new ToolGateway(
      new ToolBox([$this->espia]),
      $this->container->get(CurrentTurn::class),
      $this->container->get(ToolCallRepository::class),
      $this->container->get(SpendGuard::class),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
    );
  }

}
