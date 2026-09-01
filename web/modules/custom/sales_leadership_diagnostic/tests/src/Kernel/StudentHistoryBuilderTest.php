<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\StudentHistoryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba el reparto del historial entre las pantallas del alumno.
 *
 * Desde el 31-08-2026 el historial ya no está en un solo sitio: con varios
 * agentes, el de cada uno vive en su página. Eso es lo que sustituye a ponerle
 * una columna «Agente» a la tabla, y por tanto lo que hay que dejar fijado.
 *
 * La prueba que más importa aquí es la de los restos. Al repartir el historial
 * por agente aparece un caso que antes no existía: una sesión de un agente que
 * ya no se ofrece —deshabilitado, o con el curso caducado— se queda sin página
 * donde salir. Si nadie la recoge, el alumno pierde el enlace a un resultado
 * que pagó.
 */
#[CoversClass(StudentHistoryBuilder::class)]
final class StudentHistoryBuilderTest extends KernelTestBase {

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

  private const ALUMNO = 7;

  /**
   * Constructor bajo prueba.
   */
  private StudentHistoryBuilder $constructor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('sld_diagnostic_session');
    // `system` trae los formatos de fecha: sin ellos, dar formato a la
    // fecha de una sesión revienta con un error que no dice de qué va.
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    $this->constructor = $this->container->get(StudentHistoryBuilder::class);
  }

  /**
   * Con un agente se ve todo, que es el panel de siempre.
   */
  public function testConUnAgenteSeVeTodo(): void {
    $sesiones = [
      $this->crearSesion('agente_a'),
      $this->crearSesion('agente_a'),
    ];

    $this->assertCount(2, $this->constructor->all($sesiones, []));
  }

  /**
   * El historial de un agente trae solo lo suyo.
   *
   * Es lo que permite quitar la columna «Agente»: la página ya dice de quién
   * es, y repetirlo en cada fila sería ruido.
   */
  public function testElHistorialDeUnAgenteTraeSoloLoSuyo(): void {
    $sesiones = [
      $this->crearSesion('agente_a'),
      $this->crearSesion('agente_b'),
      $this->crearSesion('agente_a'),
    ];

    $filas = $this->constructor->forAgent($sesiones, [], 'agente_a');

    $this->assertCount(2, $filas, 'No debe colarse la sesión del otro agente.');
  }

  /**
   * Un agente sin sesiones da una lista vacía, no las de otro.
   */
  public function testUnAgenteSinSesionesNoHeredaLasDeOtro(): void {
    $sesiones = [$this->crearSesion('agente_a')];

    $this->assertSame([], $this->constructor->forAgent($sesiones, [], 'agente_b'));
  }

  /**
   * Lo que no tiene página propia se recoge aparte.
   *
   * Es la red de seguridad. Sin ella, deshabilitar un agente escondería los
   * diagnósticos que sus alumnos ya habían pagado.
   */
  public function testLoQueNoTienePaginaPropiaSeRecoge(): void {
    $sesiones = [
      $this->crearSesion('agente_a'),
      $this->crearSesion('agente_retirado'),
    ];

    $filas = $this->constructor->excludingAgents($sesiones, [], ['agente_a', 'agente_b']);

    $this->assertCount(1, $filas, 'La sesión del agente retirado no puede desaparecer.');
  }

  /**
   * Con todos los agentes disponibles no sobra nada.
   *
   * Es el caso normal, y el que hace que la sección de restos no aparezca en
   * el panel: un apartado vacío titulado «Otros diagnósticos» solo haría
   * preguntarse qué falta.
   */
  public function testConTodoDisponibleNoSobraNada(): void {
    $sesiones = [
      $this->crearSesion('agente_a'),
      $this->crearSesion('agente_b'),
    ];

    $this->assertSame(
      [],
      $this->constructor->excludingAgents($sesiones, [], ['agente_a', 'agente_b']),
    );
  }

  /**
   * Una sesión a medias se marca como reanudable, y la terminada no.
   *
   * De ahí sale el enlace que ve el alumno: «Continuar» o «Ver resultado».
   */
  public function testSeDistingueLaSesionSinTerminar(): void {
    $sesiones = [
      $this->crearSesion('agente_a', DiagnosticStatus::Draft),
      $this->crearSesion('agente_a', DiagnosticStatus::Completed),
    ];

    $filas = $this->constructor->all($sesiones, []);

    $this->assertTrue($filas[0]['is_resumable']);
    $this->assertFalse($filas[1]['is_resumable']);
  }

  /**
   * Sin resultado no se ofrece enlace al resultado.
   *
   * Un enlace que lleva a un 404 es peor que no ofrecer ninguno.
   */
  public function testSinResultadoNoHayEnlaceAlResultado(): void {
    $filas = $this->constructor->all([$this->crearSesion('agente_a')], []);

    $this->assertNull($filas[0]['result_id']);
  }

  /**
   * Crea una sesión del alumno.
   */
  private function crearSesion(string $agentId, DiagnosticStatus $estado = DiagnosticStatus::Completed) {
    $sesion = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->create([
        'uid' => self::ALUMNO,
        'wp_user_id' => '1',
        'course_id' => '35884',
        'agent' => $agentId,
        'diagnostic_version' => '1.0',
        'prompt_snapshot' => 'prompt',
        'prompt_hash' => 'huella',
      ]);

    $sesion->setStatus($estado);
    $sesion->save();

    return $sesion;
  }

}
