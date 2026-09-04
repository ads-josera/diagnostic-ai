<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Controller\ResultsController;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba el encabezado de la página de resultado.
 *
 * Cada agente puede fijar el suyo desde el 02-09-2026. Nació porque el segundo
 * agente no entrega un diagnóstico: cierra con un Weekly GOLD Pack —cuentas,
 * buyers, routing y outreach—, y encabezar esa página con «Resultado de tu
 * diagnóstico» describe mal lo que la persona tiene delante.
 *
 * Las dos pruebas que importan no son la del título propio, sino las de los
 * respaldos: un agente que no dice nada debe seguir viéndose como siempre, y
 * un resultado cuyo agente ya no existe no puede reventar la página. Lo segundo
 * pasa de verdad —el administrador puede borrar un agente y sus resultados
 * siguen ahí— y dejaría sin ver un diagnóstico que alguien pagó.
 */
#[CoversClass(ResultsController::class)]
final class ResultTitleTest extends KernelTestBase {

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

  private const POR_DEFECTO = 'Resultado de tu diagnóstico';

  /**
   * Dueño de los resultados de la prueba.
   */
  private int $dueno;

  /**
   * Alguien que NO es el dueño: el gestor mirando desde su listado.
   */
  private int $ajeno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installConfig(['system', 'sales_leadership_diagnostic']);
    $this->container->get('router.builder')->rebuild();

    // El uid 1 es superusuario y saltaria toda comprobacion.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->dueno = $this->crearCuenta('alumno');
    $this->ajeno = $this->crearCuenta('gestor');

    // Por defecto mira el DUEÑO. Las pruebas del título del agente hablan de
    // otra cosa, y sin espectador el usuario en curso es el anónimo, que no es
    // el dueño: darían «Resultado del diagnóstico» por un motivo que no es el
    // que están comprobando.
    $this->mirarComo($this->dueno);
  }

  /**
   * El agente que fija un encabezado propio, manda.
   */
  public function testElAgenteConEncabezadoPropioManda(): void {
    $this->crearAgente('prospeccion', 'Tu Weekly GOLD Pack');

    $this->assertSame(
      'Tu Weekly GOLD Pack',
      $this->tituloDe('prospeccion'),
    );
  }

  /**
   * El agente que no dice nada usa el de siempre.
   *
   * Es lo que garantiza que el primer agente no cambie: su ficha no lleva este
   * campo y no tiene por qué llevarlo.
   */
  public function testElAgenteSinEncabezadoUsaElDeSiempre(): void {
    $this->crearAgente('liderazgo', '');

    $this->assertSame(self::POR_DEFECTO, $this->tituloDe('liderazgo'));
  }

  /**
   * Un encabezado con solo espacios cuenta como vacío.
   *
   * Quien deja el campo con un espacio al guardar no está pidiendo una página
   * sin título.
   */
  public function testUnEncabezadoEnBlancoCuentaComoVacio(): void {
    $this->crearAgente('descuidado', '   ');

    $this->assertSame(self::POR_DEFECTO, $this->tituloDe('descuidado'));
  }

  /**
   * Un resultado cuyo agente ya NO existe sigue abriéndose.
   *
   * El administrador puede borrar un agente, y sus resultados no se van con
   * él. Sin este respaldo, la página moriría al pedirle el título a algo que
   * ya no está, y el alumno perdería el acceso a un diagnóstico suyo.
   */
  public function testUnResultadoSinAgenteSigueAbriendose(): void {
    $this->assertSame(self::POR_DEFECTO, $this->tituloDe('agente_borrado'));
  }

  /**
   * Un resultado antiguo, sin agente anotado, también.
   *
   * Los hay: el campo `agent` se rellena desde las fases multi-agente, y antes
   * de eso los resultados nacían sin él.
   */
  public function testUnResultadoAntiguoSinAgenteTambien(): void {
    $this->assertSame(self::POR_DEFECTO, $this->tituloDe(''));
  }

  /**
   * A quien NO es su dueño no se le dice «tu diagnóstico».
   *
   * El gestor abre el resultado de un alumno desde su listado. Tutearle sobre
   * algo ajeno le hace dudar de qué está viendo, y con 35 alumnos esa duda es
   * cara: puede creer que está leyendo el de otra persona.
   */
  public function testAlGestorNoSeLeDiceQueElDiagnosticoEsSuyo(): void {
    $resultado = $this->crearResultado('');
    $this->mirarComo($this->ajeno);

    $this->assertSame(
      'Resultado del diagnóstico',
      ResultsController::create($this->container)->title($resultado),
    );
  }

  /**
   * Al dueño sí, que es de quien habla el texto.
   */
  public function testAlDuenoSiSeLeDiceQueEsSuyo(): void {
    $resultado = $this->crearResultado('');
    $this->mirarComo($this->dueno);

    $this->assertSame(
      self::POR_DEFECTO,
      ResultsController::create($this->container)->title($resultado),
    );
  }

  /**
   * Cada uno vuelve a SU pantalla.
   *
   * Antes el enlace llevaba siempre al panel del alumno, así que el gestor
   * acababa en una pantalla que no es suya y sin sus pestañas: desde el
   * resultado no tenía ninguna salida hacia su propia sección.
   */
  public function testCadaCualVuelveDondeLeToca(): void {
    $resultado = $this->crearResultado('');

    $this->mirarComo($this->dueno);
    $suyo = ResultsController::create($this->container)->view($resultado);

    $this->mirarComo($this->ajeno);
    $ajeno = ResultsController::create($this->container)->view($resultado);

    $this->assertSame('/sales-diagnostic', $suyo['#back']['url']);
    $this->assertSame('/admin/content/sales-diagnostic', $ajeno['#back']['url']);
  }

  /**
   * Crea una cuenta y devuelve su identificador.
   */
  private function crearCuenta(string $nombre): int {
    $cuenta = User::create(['name' => $nombre, 'status' => 1]);
    $cuenta->save();

    return (int) $cuenta->id();
  }

  /**
   * Deja identificada la cuenta indicada como quien mira.
   */
  private function mirarComo(int $uid): void {
    $this->container->get('current_user')->setAccount(User::load($uid));
  }

  /**
   * Título que da el controller para un resultado de ese agente.
   */
  private function tituloDe(string $agentId): string {
    return ResultsController::create($this->container)->title($this->crearResultado($agentId));
  }

  /**
   * Crea un agente utilizable con el encabezado indicado.
   */
  private function crearAgente(string $id, string $titulo): void {
    $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->create([
        'id' => $id,
        'label' => strtoupper($id),
        'status' => TRUE,
        'version' => '1.0',
        'course_id' => '35884',
        'system_prompt' => 'Prompt.',
        'result_title' => $titulo,
      ])->save();
  }

  /**
   * Crea un resultado atribuido a un agente.
   */
  private function crearResultado(string $agentId): DiagnosticResultInterface {
    $resultado = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_result')
      ->create([
        'uid' => $this->dueno,
        'session_id' => 1,
        'agent' => $agentId,
        'diagnostic_version' => '1.0',
        'summary' => 'Resumen.',
      ]);
    $resultado->save();

    return $resultado;
  }

}
