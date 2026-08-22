<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResult;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\sales_leadership_diagnostic\Entity\Handler\DiagnosticResultAccessControlHandler;
use Drupal\sales_leadership_diagnostic\Entity\Handler\DiagnosticSessionAccessControlHandler;

/**
 * Comprueba que ningún alumno puede ver los datos de otro (§35, §42).
 *
 * Es la garantía de la que depende la confianza en el producto: los
 * diagnósticos contienen información empresarial de la organización del
 * alumno. Una fuga aquí no es un fallo de funcionalidad sino una brecha.
 *
 * Estos tests fijan el comportamiento para que nadie pueda relajarlo sin que
 * la suite lo delate.
 */
#[CoversClass(DiagnosticSessionAccessControlHandler::class)]
#[CoversClass(DiagnosticResultAccessControlHandler::class)]
final class DiagnosticAccessTest extends KernelTestBase {

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

  private User $alumnoA;
  private User $alumnoB;
  private User $soporte;
  private DiagnosticSession $sesionDeA;
  private DiagnosticResult $resultadoDeA;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installConfig(['sales_leadership_diagnostic']);

    // El usuario 1 es superusuario en Drupal y pasaría cualquier comprobación,
    // así que se crea y se descarta para que no interfiera en los tests.
    User::create(['name' => 'superusuario'])->save();

    $rolAlumno = Role::create([
      'id' => SalesLeadershipDiagnostic::STUDENT_ROLE_ID,
      'label' => 'Alumno',
    ]);
    $rolAlumno->grantPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS);
    $rolAlumno->save();

    $rolSoporte = Role::create(['id' => 'soporte', 'label' => 'Soporte']);
    $rolSoporte->grantPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS);
    $rolSoporte->grantPermission(SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS);
    $rolSoporte->save();

    $this->alumnoA = $this->crearUsuario('alumno_a', [SalesLeadershipDiagnostic::STUDENT_ROLE_ID]);
    $this->alumnoB = $this->crearUsuario('alumno_b', [SalesLeadershipDiagnostic::STUDENT_ROLE_ID]);
    $this->soporte = $this->crearUsuario('soporte_u', ['soporte']);

    $this->sesionDeA = DiagnosticSession::create([
      'uid' => $this->alumnoA->id(),
      'wp_user_id' => '4821',
      'course_id' => '35884',
      'diagnostic_version' => '1.0',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
    ]);
    $this->sesionDeA->setStatus(DiagnosticStatus::Completed);
    $this->sesionDeA->save();

    $this->resultadoDeA = DiagnosticResult::create([
      'uid' => $this->alumnoA->id(),
      'session_id' => $this->sesionDeA->id(),
      'diagnostic_version' => '1.0',
      'summary' => 'Información empresarial confidencial de A.',
      'score' => 62,
    ]);
    $this->resultadoDeA->save();
  }

  /**
   * El dueño puede ver su sesión y su resultado.
   */
  public function testElDuenoAccedeASusDatos(): void {
    $this->assertTrue($this->sesionDeA->access('view', $this->alumnoA));
    $this->assertTrue($this->resultadoDeA->access('view', $this->alumnoA));
  }

  /**
   * Otro alumno NO puede ver ni la sesión ni el resultado.
   *
   * Este es el test que no debe fallar nunca.
   */
  public function testOtroAlumnoNoAccedeANada(): void {
    $this->assertFalse(
      $this->sesionDeA->access('view', $this->alumnoB),
      'Un alumno pudo ver la sesión de otro.',
    );
    $this->assertFalse(
      $this->resultadoDeA->access('view', $this->alumnoB),
      'Un alumno pudo ver el resultado de otro.',
    );
  }

  /**
   * Otro alumno tampoco puede escribir en la sesión ajena.
   */
  public function testOtroAlumnoNoPuedeEscribir(): void {
    $this->assertFalse($this->sesionDeA->access('update', $this->alumnoB));
  }

  /**
   * El usuario anónimo no accede a nada.
   */
  public function testElAnonimoNoAccede(): void {
    $anonimo = User::getAnonymousUser();

    $this->assertFalse($this->sesionDeA->access('view', $anonimo));
    $this->assertFalse($this->resultadoDeA->access('view', $anonimo));
  }

  /**
   * El soporte autorizado puede leer, pero no escribir.
   *
   * La distinción es deliberada: consultar un diagnóstico para ayudar a un
   * alumno es legítimo; alterar su conversación no lo es.
   */
  public function testElSoporteLeePeroNoEscribe(): void {
    $this->assertTrue(
      $this->sesionDeA->access('view', $this->soporte),
      'El soporte con permiso debería poder consultar.',
    );
    $this->assertFalse(
      $this->sesionDeA->access('update', $this->soporte),
      'El soporte no debe poder escribir en la conversación de un alumno.',
    );
  }

  /**
   * El resultado es inmutable, incluso para su dueño.
   *
   * Editarlo rompería la correspondencia con la conversación que lo produjo.
   */
  public function testElResultadoEsInmutable(): void {
    $this->assertFalse($this->resultadoDeA->access('update', $this->alumnoA));
    $this->assertFalse($this->resultadoDeA->access('delete', $this->alumnoA));
  }

  /**
   * Un alumno no puede borrar su sesión.
   *
   * Destruiría su historial y la trazabilidad del diagnóstico.
   */
  public function testElAlumnoNoBorraSuSesion(): void {
    $this->assertFalse($this->sesionDeA->access('delete', $this->alumnoA));
  }

  /**
   * Retirar el permiso deja al dueño sin acceso a sus propios datos.
   *
   * La propiedad no basta: se exigen las dos condiciones, de modo que revocar
   * el acceso a un alumno surta efecto de inmediato.
   */
  public function testLaPropiedadSolaNoBasta(): void {
    $sinPermiso = $this->crearUsuario('sin_permiso', []);

    $sesion = DiagnosticSession::create([
      'uid' => $sinPermiso->id(),
      'wp_user_id' => '999',
      'course_id' => '35884',
      'diagnostic_version' => '1.0',
    ]);
    $sesion->save();

    $this->assertFalse(
      $sesion->access('view', $sinPermiso),
      'Sin el permiso del módulo, ser el dueño no debe bastar.',
    );
  }

  /**
   * Crea un usuario con los roles indicados.
   *
   * @param string[] $roles
   */
  private function crearUsuario(string $nombre, array $roles): User {
    $usuario = User::create(['name' => $nombre, 'status' => 1]);

    foreach ($roles as $rol) {
      $usuario->addRole($rol);
    }

    $usuario->save();

    return $usuario;
  }

}
