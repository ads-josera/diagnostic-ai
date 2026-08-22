<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Access\DiagnosticAccessCheck;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba quién puede entrar por la puerta de un resultado.
 *
 * Existe por un fallo real: el permiso «ver los resultados de cualquier alumno»
 * estaba correctamente aplicado en el handler de la entidad, pero la ruta
 * exigía además ser alumno procedente de WordPress. Quien atendía soporte
 * quedaba rechazado ANTES de llegar al control que se lo concedía, de modo que
 * el permiso existía y no servía para nada.
 *
 * El fallo no lo detectó ningún test porque los que había comprueban el acceso
 * a la ENTIDAD, y el problema estaba una capa más arriba, en la RUTA.
 *
 * Los dos casos que se prueban aquí no llegan a consultar a WordPress: el de
 * soporte devuelve antes por permiso, y el de la cuenta sin authmap devuelve
 * antes por no tener procedencia. Por eso el test no necesita red.
 */
#[CoversClass(DiagnosticAccessCheck::class)]
final class ResultRouteAccessTest extends KernelTestBase {

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
   * Comprobación de acceso bajo prueba.
   *
   * @var \Drupal\sales_leadership_diagnostic\Access\DiagnosticAccessCheck
   */
  private DiagnosticAccessCheck $check;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    // La comprobación consulta el authmap para saber si la cuenta viene de
    // WordPress, así que su tabla tiene que existir.
    $this->installSchema('externalauth', ['authmap']);

    // El uid 1 es superusuario y salta TODA comprobación de permisos. Si el
    // primer usuario del test fuese uno de los que se examinan, los tests
    // pasarían por ser superusuario y no por la regla que dicen probar.
    // Se quema creando una cuenta que no se usa para nada.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->check = $this->container->get(DiagnosticAccessCheck::class);
  }

  /**
   * El soporte entra a la ruta del resultado sin ser alumno.
   *
   * Es exactamente el caso que estaba roto.
   */
  public function testElSoporteEntraSinSerAlumno(): void {
    $soporte = $this->crearUsuarioConPermiso(
      'soporte',
      SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS,
    );

    $this->assertTrue(
      $this->check->accessResult($soporte)->isAllowed(),
      'Quien puede consultar diagnósticos ajenos debe poder llegar a la página del resultado.',
    );
  }

  /**
   * El soporte NO entra al panel ni al chat.
   *
   * El arreglo debía abrir una puerta concreta, no rebajar la cadena entera.
   */
  public function testElSoporteNoEntraAlPanelNiAlChat(): void {
    $soporte = $this->crearUsuarioConPermiso(
      'soporte2',
      SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS,
    );

    $this->assertFalse(
      $this->check->access($soporte)->isAllowed(),
      'El permiso de soporte no debe dar acceso a las páginas del alumno.',
    );
  }

  /**
   * Una cuenta cualquiera sigue sin entrar por la ruta del resultado.
   *
   * Sin esta comprobación, el arreglo podría haber abierto la puerta a todos.
   */
  public function testUnaCuentaSinPermisosNoEntra(): void {
    $cualquiera = $this->crearUsuarioConPermiso('random', NULL);

    $this->assertFalse(
      $this->check->accessResult($cualquiera)->isAllowed(),
      'Una cuenta sin permisos del módulo no debe llegar a ningún resultado.',
    );
  }

  /**
   * Un alumno con el permiso pero sin procedencia de WordPress no entra.
   *
   * Es la condición 2 de la cadena: una cuenta creada a mano en Drupal a la que
   * un administrador conceda el permiso por error sigue fuera.
   */
  public function testElPermisoDeAlumnoSinAuthmapNoBasta(): void {
    $alumno = $this->crearUsuarioConPermiso(
      'alumno_local',
      SalesLeadershipDiagnostic::PERMISSION_ACCESS,
    );

    $this->assertFalse(
      $this->check->accessResult($alumno)->isAllowed(),
      'Sin entrada en el authmap, el permiso de alumno no debe abrir nada.',
    );
  }

  /**
   * Crea un usuario con un único permiso, o con ninguno.
   *
   * @param string $nombre
   *   Nombre de la cuenta.
   * @param string|null $permiso
   *   Permiso a conceder, o NULL para no conceder ninguno.
   */
  private function crearUsuarioConPermiso(string $nombre, ?string $permiso): User {
    $roles = [];

    if ($permiso !== NULL) {
      $rol = Role::create(['id' => $nombre . '_rol', 'label' => $nombre]);
      $rol->grantPermission($permiso);
      $rol->save();
      $roles[] = $rol->id();
    }

    $usuario = User::create([
      'name' => $nombre,
      'status' => 1,
      'roles' => $roles,
    ]);
    $usuario->save();

    return $usuario;
  }

}
