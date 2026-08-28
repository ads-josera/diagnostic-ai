<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\EventSubscriber\StudentProfileRedirectSubscriber;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Comprueba que el alumno no acabe en las páginas de cuenta de Drupal.
 *
 * Un alumno que llegaba a `/user/25` se encontraba el tema del sitio en medio
 * de una experiencia diseñada entera, sin nada que le devolviera al
 * diagnóstico. Pero el motivo de sacarlo de ahí no es estético: esa página no
 * le sirve para nada y le ofrece editar una identidad que manda WordPress.
 *
 * La prueba que más importa aquí es la del gestor. Una redirección demasiado
 * amplia le dejaría sin poder ver su propia cuenta, y eso sí sería un fallo:
 * él la necesita.
 */
#[CoversClass(StudentProfileRedirectSubscriber::class)]
final class StudentProfileRedirectTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['sales_leadership_diagnostic']);

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();
  }

  /**
   * Al alumno se le saca de su perfil.
   */
  public function testAlAlumnoSeLeSacaDeSuPerfil(): void {
    $this->identificarComo([SalesLeadershipDiagnostic::PERMISSION_ACCESS]);

    $respuesta = $this->pedir('entity.user.canonical');

    $this->assertInstanceOf(RedirectResponse::class, $respuesta);
    $this->assertStringContainsString('/sales-diagnostic', $respuesta->getTargetUrl());
  }

  /**
   * Y de la edición de su cuenta.
   *
   * Es la mitad que de verdad importa: su identidad la manda WordPress. Si
   * cambia el correo deja al provisionador en un estado que hay que resolver a
   * mano, y si se pone contraseña abre una entrada directa que el diseño no
   * contempla.
   */
  public function testAlAlumnoSeLeSacaDeLaEdicionDeSuCuenta(): void {
    $this->identificarComo([SalesLeadershipDiagnostic::PERMISSION_ACCESS]);

    $this->assertInstanceOf(RedirectResponse::class, $this->pedir('entity.user.edit_form'));
  }

  /**
   * El GESTOR conserva su cuenta.
   *
   * Es la prueba que evita el exceso. Una redirección que le alcanzara le
   * dejaría sin poder ver ni editar su propia cuenta, y él sí la necesita.
   */
  public function testElGestorConservaSuCuenta(): void {
    $this->identificarComo([
      SalesLeadershipDiagnostic::PERMISSION_ACCESS,
      SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS,
    ]);

    $this->assertNull($this->pedir('entity.user.canonical'));
    $this->assertNull($this->pedir('entity.user.edit_form'));
  }

  /**
   * El administrador del módulo, también.
   */
  public function testElAdministradorConservaSuCuenta(): void {
    $this->identificarComo([
      SalesLeadershipDiagnostic::PERMISSION_ACCESS,
      SalesLeadershipDiagnostic::PERMISSION_ADMINISTER,
    ]);

    $this->assertNull($this->pedir('entity.user.canonical'));
  }

  /**
   * Quien no es alumno no se ve afectado.
   *
   * Un usuario cualquiera del sitio, que no tiene nada que ver con esto.
   */
  public function testQuienNoEsAlumnoNoSeVeAfectado(): void {
    $this->identificarComo([]);

    $this->assertNull($this->pedir('entity.user.canonical'));
  }

  /**
   * El cierre de sesión y el restablecimiento NO se tocan.
   *
   * Se enumeran las rutas en lugar de filtrar por el prefijo `/user` justo
   * para esto: arrastrarlas dejaría al alumno sin poder salir ni recuperar su
   * contraseña, que es peor que el problema que se venía a resolver.
   */
  public function testElCierreDeSesionNoSeToca(): void {
    $this->identificarComo([SalesLeadershipDiagnostic::PERMISSION_ACCESS]);

    $this->assertNull($this->pedir('user.logout'));
    $this->assertNull($this->pedir('user.pass'));
    $this->assertNull($this->pedir('user.login'));
  }

  /**
   * Una subpetición no se redirige.
   *
   * Drupal compone bloques con subpeticiones; redirigir una devolvería un
   * fragmento de página que es una redirección.
   */
  public function testUnaSubpeticionNoSeRedirige(): void {
    $this->identificarComo([SalesLeadershipDiagnostic::PERMISSION_ACCESS]);

    $this->assertNull($this->pedir('entity.user.canonical', principal: FALSE));
  }

  /**
   * Corre el suscriptor sobre la ruta indicada.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   La respuesta fijada, o NULL si el suscriptor no intervino.
   */
  private function pedir(string $ruta, bool $principal = TRUE): mixed {
    $peticion = Request::create('/user/2');
    $peticion->attributes->set('_route', $ruta);

    $evento = new RequestEvent(
      $this->container->get('http_kernel'),
      $peticion,
      $principal ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
    );

    $this->container
      ->get(StudentProfileRedirectSubscriber::class)
      ->onRequest($evento);

    return $evento->getResponse();
  }

  /**
   * Deja identificada una cuenta con los permisos indicados.
   *
   * @param string[] $permisos
   *   Permisos que tendrá.
   */
  private function identificarComo(array $permisos): void {
    $rol = Role::create(['id' => 'rol_' . bin2hex(random_bytes(4)), 'label' => 'Rol']);

    foreach ($permisos as $permiso) {
      $rol->grantPermission($permiso);
    }

    $rol->save();

    $cuenta = User::create([
      'name' => 'persona_' . bin2hex(random_bytes(4)),
      'status' => 1,
      'roles' => [$rol->id()],
    ]);
    $cuenta->save();

    $this->container->get('current_user')->setAccount($cuenta);
  }

}
