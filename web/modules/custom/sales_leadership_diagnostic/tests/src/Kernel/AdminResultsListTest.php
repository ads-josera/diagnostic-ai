<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Controller\AdminResultsController;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Comprueba qué ve el gestor en el listado de diagnósticos.
 *
 * Hasta el 26-08-2026 este listado leía RESULTADOS, y un resultado solo existe
 * cuando el diagnóstico terminó: quien empezaba, se atascaba y abandonaba no
 * aparecía en ninguna parte. Para un producto que se vende como entregable esa
 * es la cifra que más duele ignorar, así que estas pruebas fijan que los que
 * no terminaron se vean.
 *
 * También se comprueba lo que NO debe verse: los ensayos del gestor, que son
 * simulaciones y contaminarían el listado que se usa para dar soporte.
 */
#[CoversClass(AdminResultsController::class)]
final class AdminResultsListTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'externalauth',
    'datetime',
    'sales_leadership_diagnostic',
  ];

  /**
   * Alumno de las pruebas.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $alumno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    // El listado formatea fechas con el formato «short», que no existe hasta
    // instalar la configuración de system.
    $this->installConfig(['system', 'sales_leadership_diagnostic']);
    $this->installEntitySchema('date_format');

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->alumno = User::create(['name' => 'alumno', 'status' => 1]);
    $this->alumno->save();

    $this->crearAgente('liderazgo', 'Sales Leadership');
    $this->crearAgente('prospeccion', 'GAP Prospecting');
  }

  /**
   * Una conversación abandonada SÍ aparece.
   *
   * Es la razón de ser del cambio. Antes solo se listaban resultados, así que
   * esta fila no existía y nadie sabía a quién había que empujar.
   */
  public function testUnaConversacionAbandonadaAparece(): void {
    $this->crearSesion('liderazgo', DiagnosticStatus::InProgress);

    $this->assertCount(1, $this->filas());
  }

  /**
   * El filtro «sin terminar» reúne todo lo que no llegó a resultado.
   *
   * Agrupa cuatro estados en una sola opción porque esa es la pregunta que se
   * hace el gestor; obligarle a marcarlos uno a uno sería trasladarle un
   * detalle de nuestro modelo de datos.
   */
  public function testElFiltroSinTerminarReuneLoQueNoAcabo(): void {
    $this->crearSesion('liderazgo', DiagnosticStatus::Completed);
    $this->crearSesion('liderazgo', DiagnosticStatus::InProgress);
    $this->crearSesion('liderazgo', DiagnosticStatus::Failed);
    $this->crearSesion('liderazgo', DiagnosticStatus::Draft);

    $this->assertCount(4, $this->filas());
    $this->assertCount(3, $this->filas(['estado' => AdminResultsController::ESTADO_SIN_TERMINAR]));
    $this->assertCount(1, $this->filas(['estado' => DiagnosticStatus::Completed->value]));
  }

  /**
   * Se puede filtrar por agente.
   *
   * Con varios agentes, un listado que los mezcla sin poder separarlos es
   * ambiguo: dos diagnósticos del mismo día pueden ser de metodologías
   * distintas.
   */
  public function testSePuedeFiltrarPorAgente(): void {
    $this->crearSesion('liderazgo', DiagnosticStatus::Completed);
    $this->crearSesion('prospeccion', DiagnosticStatus::Completed);
    $this->crearSesion('prospeccion', DiagnosticStatus::InProgress);

    $this->assertCount(3, $this->filas());
    $this->assertCount(1, $this->filas(['agente' => 'liderazgo']));
    $this->assertCount(2, $this->filas(['agente' => 'prospeccion']));
  }

  /**
   * Los ensayos del gestor no aparecen.
   *
   * Son simulaciones. Mezclarlos daría un listado en el que no se puede
   * confiar para dar soporte, y de paso inflaría cualquier recuento.
   */
  public function testLosEnsayosDelGestorNoAparecen(): void {
    $this->crearSesion('liderazgo', DiagnosticStatus::Completed);
    $this->crearSesion('liderazgo', DiagnosticStatus::Completed, ensayo: TRUE);

    $this->assertCount(1, $this->filas());
  }

  /**
   * Cada fila dice su agente, su estado y sus turnos.
   *
   * Los turnos delatan un prompt que no funciona: uno que cierra en tres
   * concluyó sin evidencia, y uno en el tope se cortó a la fuerza.
   */
  public function testCadaFilaMuestraAgenteEstadoTurnos(): void {
    $sesion = $this->crearSesion('prospeccion', DiagnosticStatus::InProgress);
    $sesion->set('turn_count', 6)->save();

    $fila = $this->filas()[0];

    $this->assertSame('GAP Prospecting', $fila['agent']);
    $this->assertSame(6, $fila['turns']);
    $this->assertSame('alumno', $fila['user']);
  }

  /**
   * Un agente borrado no deja la fila en blanco.
   *
   * El historial conserva el identificador a propósito, así que lo peor que
   * puede pasar es que se muestre ese identificador: sigue diciendo la verdad.
   */
  public function testUnAgenteBorradoMuestraSuIdentificador(): void {
    $this->crearSesion('prospeccion', DiagnosticStatus::Completed);

    $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->load('prospeccion')
      ->delete();

    $this->assertSame('prospeccion', $this->filas()[0]['agent']);
  }

  /**
   * Filas del listado con los filtros indicados.
   *
   * @param array<string, string> $filtros
   *   Parámetros de la URL.
   *
   * @return array<int, array<string, mixed>>
   *   Filas construidas.
   */
  private function filas(array $filtros = []): array {
    $peticion = Request::create('/admin/content/sales-diagnostic', 'GET', $filtros);
    // El controlador construye el formulario de filtros, y la Form API exige
    // una sesión aunque este formulario vaya por GET y no guarde nada.
    $peticion->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($peticion);

    try {
      $build = AdminResultsController::create($this->container)->view();

      return $build['tabla']['#rows'];
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Crea una sesión del agente y estado indicados.
   */
  private function crearSesion(string $agentId, DiagnosticStatus $estado, bool $ensayo = FALSE): DiagnosticSession {
    $sesion = DiagnosticSession::create([
      'uid' => $this->alumno->id(),
      'wp_user_id' => '1',
      'course_id' => '35884',
      'agent' => $agentId,
      'diagnostic_version' => '1.0-TEST',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
      'is_sandbox' => $ensayo,
    ]);
    $sesion->setStatus($estado);
    $sesion->save();

    return $sesion;
  }

  /**
   * Crea un agente utilizable.
   */
  private function crearAgente(string $id, string $nombre): void {
    $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->create([
        'id' => $id,
        'label' => $nombre,
        'status' => TRUE,
        'version' => '1.0',
        'course_id' => '35884',
        'system_prompt' => 'Prompt.',
      ])
      ->save();
  }

}
