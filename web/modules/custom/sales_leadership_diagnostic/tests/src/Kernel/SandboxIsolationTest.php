<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Access\SandboxSessionCheck;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\SandboxSessionManager;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Routing\Route;

/**
 * Comprueba que los ensayos del gestor no contaminan los datos reales.
 *
 * El estudio del prompt conversa con el motor de verdad, y esa fidelidad es su
 * razón de ser: un ensayo que no se pareciera al recorrido del alumno no
 * probaría nada. El precio es que esas conversaciones son indistinguibles de
 * las reales salvo por su marca, así que la marca tiene que respetarse en
 * todas partes.
 *
 * Si esta separación se rompe, el daño no es visible de inmediato: aparecen
 * ensayos en el listado que el cliente usa para dar soporte, y alumnos a los
 * que se les niega su diagnóstico porque un gestor gastó su cupo probando.
 */
#[CoversClass(SandboxSessionManager::class)]
#[CoversClass(SandboxSessionCheck::class)]
final class SandboxIsolationTest extends KernelTestBase {

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
   * Gestor que ensaya.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $gestor;

  /**
   * Alumno con un diagnóstico real.
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
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installSchema('externalauth', ['authmap']);
    $this->installConfig(['sales_leadership_diagnostic']);

    // El uid 1 es superusuario y saltaría toda comprobación de permisos.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $rolGestor = Role::create(['id' => 'gestor', 'label' => 'Gestor']);
    $rolGestor->grantPermission(SalesLeadershipDiagnostic::PERMISSION_EDIT_PROMPT);
    $rolGestor->save();

    $this->gestor = User::create(['name' => 'gestor', 'status' => 1, 'roles' => ['gestor']]);
    $this->gestor->save();

    $this->alumno = User::create(['name' => 'alumno', 'status' => 1]);
    $this->alumno->save();
  }

  /**
   * El gestor obtiene una conversación marcada como prueba.
   */
  public function testLaConversacionDeEnsayoNaceMarcada(): void {
    $session = $this->manager()->getOrCreate($this->gestor, $this->agente());

    $this->assertTrue(
      (bool) $session->get('is_sandbox')->value,
      'Sin la marca, el ensayo sería indistinguible de un diagnóstico real.',
    );
    $this->assertSame(DiagnosticStatus::Draft, $session->getStatus());
  }

  /**
   * Solo hay una conversación de ensayo viva por gestor.
   */
  public function testNoSeAcumulanEnsayos(): void {
    $primera = $this->manager()->getOrCreate($this->gestor, $this->agente());
    $segunda = $this->manager()->getOrCreate($this->gestor, $this->agente());

    $this->assertSame($primera->id(), $segunda->id());
  }

  /**
   * Reiniciar descarta la anterior en lugar de dejarla acumulada.
   */
  public function testReiniciarSustituyeLaConversacion(): void {
    $primera = $this->manager()->getOrCreate($this->gestor, $this->agente());
    $segunda = $this->manager()->reset($this->gestor, $this->agente());

    $this->assertNotSame($primera->id(), $segunda->id());
    $this->assertSame(1, $this->contarSesionesDe($this->gestor), 'La anterior debería haberse borrado.');
  }

  /**
   * El acceso al endpoint del ensayo exige las tres condiciones.
   *
   * Es la comprobación de seguridad del estudio: reutiliza el controlador de
   * mensajes del alumno, así que sin esta puerta quien pudiera editar el
   * prompt podría escribir en la conversación de cualquier alumno.
   */
  public function testElEndpointDeEnsayoExigeLasTresCondiciones(): void {
    $check = $this->container->get(SandboxSessionCheck::class);
    $route = new Route('/irrelevante');

    $ensayoDelGestor = $this->manager()->getOrCreate($this->gestor, $this->agente());
    $sesionDelAlumno = $this->crearSesionReal($this->alumno);

    $this->assertTrue(
      $check->access($route, $this->gestor, $ensayoDelGestor)->isAllowed(),
      'El gestor debe poder escribir en su propio ensayo.',
    );

    $this->assertFalse(
      $check->access($route, $this->gestor, $sesionDelAlumno)->isAllowed(),
      'Ni con permiso debe poder escribir en la conversación de un alumno.',
    );

    $this->assertFalse(
      $check->access($route, $this->alumno, $ensayoDelGestor)->isAllowed(),
      'Sin el permiso de editar el prompt no se entra al endpoint del ensayo.',
    );

    $otroGestor = User::create(['name' => 'otro_gestor', 'status' => 1, 'roles' => ['gestor']]);
    $otroGestor->save();

    $this->assertFalse(
      $check->access($route, $otroGestor, $ensayoDelGestor)->isAllowed(),
      'Un gestor no debe poder escribir en el ensayo de otro.',
    );
  }

  /**
   * Un ensayo no gasta el diagnóstico del periodo de nadie.
   *
   * Se comprueba sobre la consulta real que usa la política, no sobre una
   * reproducción de ella: lo que importa es que ESA consulta los excluya.
   */
  public function testUnEnsayoNoCuentaParaElLimitePorPeriodo(): void {
    $this->manager()->getOrCreate($this->gestor, $this->agente());
    $this->crearSesionReal($this->gestor);

    $reales = (int) $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->gestor->id())
      ->condition('is_sandbox', FALSE)
      ->count()
      ->execute();

    $this->assertSame(1, $reales, 'Solo la sesión real debería contar.');
    $this->assertSame(2, $this->contarSesionesDe($this->gestor), 'Pero ambas existen.');
  }

  /**
   * Agente del ensayo, creado al vuelo la primera vez que se pide.
   *
   * El estudio ensaya el prompt de un agente concreto desde el 26-08-2026, así
   * que ya no se puede pedir una conversación de prueba «a secas».
   */
  private function agente(): DiagnosticAgentInterface {
    $almacen = $this->container->get('entity_type.manager')->getStorage('sld_agent');
    $agente = $almacen->load('agente_prueba');

    if ($agente === NULL) {
      $agente = $almacen->create([
        'id' => 'agente_prueba',
        'label' => 'Agente de prueba',
        'status' => TRUE,
        'version' => '1.0-TEST',
        'course_id' => '35884',
        'system_prompt' => 'Prompt de prueba.',
      ]);
      $agente->save();
    }

    return $agente;
  }

  /**
   * El servicio bajo prueba.
   */
  private function manager(): SandboxSessionManager {
    return $this->container->get(SandboxSessionManager::class);
  }

  /**
   * Crea una sesión de diagnóstico normal.
   */
  private function crearSesionReal(User $owner): DiagnosticSession {
    $session = DiagnosticSession::create([
      'uid' => $owner->id(),
      'wp_user_id' => '4821',
      'course_id' => '35884',
      'diagnostic_version' => '1.0-TEST',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
    ]);
    $session->setStatus(DiagnosticStatus::Completed);
    $session->save();

    return $session;
  }

  /**
   * Cuenta todas las sesiones de una cuenta, de prueba o no.
   */
  private function contarSesionesDe(User $owner): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $owner->id())
      ->count()
      ->execute();
  }

}
