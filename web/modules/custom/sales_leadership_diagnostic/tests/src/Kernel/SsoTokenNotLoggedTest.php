<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Controller\SsoController;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * El token de entrada no llega nunca al registro de Drupal (§43).
 *
 * Nace de producción, 14-09-2026: cada entrada correcta desde WordPress
 * escribía tres líneas en el registro y Drupal guardaba con cada una la URI
 * completa, token incluido, y el token lleva el correo y el nombre del
 * alumno. Se prueba con un token roto porque es el camino que escribe en el
 * registro sin necesitar un WordPress de verdad; la limpieza vale igual para
 * cada camino, porque ocurre antes que cualquier otra cosa.
 */
#[CoversNothing]
final class SsoTokenNotLoggedTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'dblog',
    'file',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('dblog', ['watchdog']);
    $this->container->get('router.builder')->rebuild();

    new Settings(Settings::getAll() + [
      'sld_jwt_shared_secret' => str_repeat('s', 64),
    ]);
  }

  /**
   * Lo que se registra durante la entrada no lleva el token.
   */
  public function testElRegistroNoGuardaElToken(): void {
    $token = 'eyJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6ImFsdW1ub0BlamVtcGxvLmNvbSJ9.firma';
    $request = Request::create('https://labai.example.com/sales-diagnostic/sso?token=' . $token . '&otro=1');
    // Sin sesión, el cierre de la prueba falla al buscarla en esta petición.
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    SsoController::create($this->container)->login($request);

    $ubicaciones = $this->container->get('database')
      ->select('watchdog', 'w')
      ->fields('w', ['location'])
      ->execute()
      ->fetchCol();

    $this->assertNotEmpty($ubicaciones, 'La prueba no mide nada si la entrada no escribió en el registro.');

    foreach ($ubicaciones as $ubicacion) {
      $this->assertStringNotContainsString('token=', $ubicacion);
      $this->assertStringNotContainsString($token, $ubicacion);
      $this->assertStringContainsString('/sales-diagnostic/sso', $ubicacion);
      $this->assertStringContainsString('otro=1', $ubicacion, 'El resto de parámetros se conserva.');
    }
  }

}
