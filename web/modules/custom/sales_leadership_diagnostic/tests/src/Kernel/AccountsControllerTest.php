<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\BuyerTruth;
use Drupal\sales_leadership_diagnostic\Controller\AccountsController;
use Drupal\sales_leadership_diagnostic\ExecutionState;
use Drupal\sales_leadership_diagnostic\Service\Account\AccountRegistry;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * «Mis cuentas»: lo que ve el alumno y lo que puede anotar.
 *
 * La pantalla escribe datos que el agente lee en la siguiente misión, así que
 * lo que importa de verdad es quién puede escribir en qué cuenta. Una nota
 * colada en la cuenta de otra persona no se vería en ninguna pantalla: la
 * leería el agente de esa persona y le cambiaría la criba sin que nadie supiera
 * por qué.
 */
#[CoversClass(AccountsController::class)]
final class AccountsControllerTest extends KernelTestBase {

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
   * Quien usa la pantalla.
   */
  private User $alumna;

  /**
   * Otra persona, con cuentas propias.
   */
  private User $otro;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installSchema('sales_leadership_diagnostic', ['sld_account', 'sld_account_event']);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    // La pantalla compone URLs con nombre de ruta.
    $this->container->get('router.builder')->rebuild();

    // El uid 1 es superusuario y no debe usarse como sujeto de prueba.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->alumna = User::create(['name' => 'alumna', 'status' => 1]);
    $this->alumna->save();
    $this->otro = User::create(['name' => 'otro', 'status' => 1]);
    $this->otro->save();

    $this->registro()->ingestPack((int) $this->alumna->id(), 'prospeccion', 10, [
      ['name' => 'Traxión', 'disposition' => 'GOLD'],
      ['name' => 'Olympic Transport', 'disposition' => 'SILVER'],
    ]);
    $this->registro()->ingestPack((int) $this->otro->id(), 'prospeccion', 20, [
      ['name' => 'Cuenta Ajena', 'disposition' => 'GOLD'],
    ]);

    $this->container->get('current_user')->setAccount($this->alumna);
  }

  /**
   * Solo ve las suyas, y las cifras cuentan lo que dicen.
   */
  public function testVeSoloSusCuentas(): void {
    $this->anotar($this->idDe('Traxión'), ['state' => ExecutionState::Opportunity->value]);

    $pantalla = $this->controlador()->view(Request::create('/sales-diagnostic/cuentas'));

    // Sin orden: dos cuentas del mismo Pack empatan en fecha, y lo que se fija
    // aquí es que no aparezca la ajena.
    $this->assertEqualsCanonicalizing(['Traxión', 'Olympic Transport'], array_column($pantalla['#accounts'], 'name'));
    $this->assertSame(
      ['total' => 2, 'pendientes' => 1, 'avanzan' => 0, 'oportunidades' => 1],
      $pantalla['#figures'],
    );
    $this->assertSame([2, 1, 1], array_column($pantalla['#tabs'], 'count'));
  }

  /**
   * Las pestañas filtran de verdad, y una pestaña inventada vuelve a «todas».
   */
  public function testLasPestanasFiltran(): void {
    $this->anotar($this->idDe('Traxión'), ['state' => ExecutionState::Contacted->value]);

    $pendientes = $this->controlador()->view(Request::create('/sales-diagnostic/cuentas', 'GET', ['ver' => 'pendientes']));
    $this->assertSame(['Olympic Transport'], array_column($pendientes['#accounts'], 'name'));

    $registradas = $this->controlador()->view(Request::create('/sales-diagnostic/cuentas', 'GET', ['ver' => 'registradas']));
    $this->assertSame(['Traxión'], array_column($registradas['#accounts'], 'name'));

    $inventada = $this->controlador()->view(Request::create('/sales-diagnostic/cuentas', 'GET', ['ver' => '<script>']));
    $this->assertSame('todas', $inventada['#view']);
    $this->assertCount(2, $inventada['#accounts']);
  }

  /**
   * Anotar en una cuenta ajena es un 403, y no deja rastro.
   *
   * No se distingue «no es tuya» de «no existe»: distinguirlo le diría a quien
   * prueba números cuáles pertenecen a otra persona.
   */
  public function testNoPuedeAnotarEnUnaCuentaAjena(): void {
    $ajena = $this->registro()->forUser((int) $this->otro->id())[0]['id'];

    try {
      $this->anotar($ajena, ['state' => ExecutionState::Won->value, 'note' => 'colada']);
      $this->fail('Anotar en una cuenta ajena tenía que negarse.');
    }
    catch (AccessDeniedHttpException) {
      // Lo esperado.
    }

    $this->assertNull($this->registro()->forUser((int) $this->otro->id())[0]['state'], 'La cuenta ajena sigue sin resultado.');

    $this->expectException(AccessDeniedHttpException::class);
    $this->anotar(999999, ['state' => ExecutionState::Won->value]);
  }

  /**
   * Sin estado válido no se anota nada, y se le dice por qué.
   *
   * Es el caso de pulsar Intro en la frase sin haber elegido estado: la nota
   * sola no es un resultado.
   */
  public function testSinEstadoNoSeAnota(): void {
    $id = $this->idDe('Traxión');

    $respuesta = $this->anotar($id, ['state_otro' => '', 'note' => 'Llamé y nada']);
    $this->anotar($id, ['state' => 'RELEASED']);

    $eventos = $this->registro()->eventsForUser((int) $this->alumna->id())[$id];
    $this->assertSame(['pack'], array_column($eventos, 'kind'), 'Ni una cadena vacía ni un estado inventado dejan evento.');
    $this->assertNotEmpty($this->container->get('messenger')->messagesByType('warning'));
    $this->assertStringContainsString('#cuenta-' . $id, $respuesta->getTargetUrl());
  }

  /**
   * El botón pulsado manda sobre el desplegable.
   *
   * Y la etiqueta y la frase opcionales se guardan con él.
   */
  public function testElBotonPulsadoMandaSobreElDesplegable(): void {
    $id = $this->idDe('Olympic Transport');

    $respuesta = $this->anotar($id, [
      'state' => ExecutionState::Response->value,
      'state_otro' => ExecutionState::Lost->value,
      'truth' => BuyerTruth::WrongBuyer->value,
      'note' => 'Nos pasó con el de mantenimiento.',
      'ver' => 'pendientes',
    ]);

    $cuenta = $this->cuenta('Olympic Transport');
    $this->assertSame(ExecutionState::Response, $cuenta['state']);
    $this->assertSame(BuyerTruth::WrongBuyer, $cuenta['truth']);
    $this->assertSame('Nos pasó con el de mantenimiento.', $cuenta['note']);

    // Vuelve a la misma pestaña y a la altura de la cuenta.
    $this->assertStringContainsString('ver=pendientes', $respuesta->getTargetUrl());
    $this->assertStringEndsWith('#cuenta-' . $id, $respuesta->getTargetUrl());
  }

  /**
   * La ruta que escribe exige POST y token.
   *
   * Con GET, cualquier cosa que precargue enlaces —un cliente de correo, una
   * extensión— anotaría estados que nadie eligió.
   */
  public function testLaRutaQueEscribeExigePostConToken(): void {
    $ruta = $this->container->get('router.route_provider')
      ->getRouteByName('sales_leadership_diagnostic.account_outcome');

    $this->assertSame(['POST'], $ruta->getMethods());
    $this->assertSame('TRUE', $ruta->getRequirement('_csrf_token'));

    $pantalla = $this->controlador()->view(Request::create('/sales-diagnostic/cuentas'));
    $this->assertStringContainsString('?token=', $pantalla['#accounts'][0]['record_url']);
  }

  /**
   * El historial de la pantalla es el de cada cuenta, y solo el suyo.
   */
  public function testElHistorialEsDeCadaCuenta(): void {
    $id = $this->idDe('Traxión');
    $this->anotar($id, ['state' => ExecutionState::Contacted->value]);

    $eventos = $this->registro()->eventsForUser((int) $this->alumna->id());

    $this->assertCount(2, $eventos, 'Dos cuentas, dos historiales.');
    $this->assertSame(['pack', 'outcome'], array_column($eventos[$id], 'kind'));
    $this->assertSame(2, $this->registro()->countForUser((int) $this->alumna->id()));
    $this->assertSame(1, $this->registro()->countForUser((int) $this->otro->id()));
  }

  /**
   * Envía el formulario de una cuenta.
   *
   * @param int $id
   *   Cuenta.
   * @param array<string, string> $datos
   *   Lo que manda el formulario.
   */
  private function anotar(int $id, array $datos): RedirectResponse {
    return $this->controlador()->record($id, Request::create('/sales-diagnostic/cuentas/' . $id . '/resultado', 'POST', $datos));
  }

  /**
   * Identificador de una cuenta de la alumna, por nombre.
   */
  private function idDe(string $nombre): int {
    return (int) $this->cuenta($nombre)['id'];
  }

  /**
   * Una cuenta de la alumna, por nombre.
   *
   * @return array<string, mixed>
   *   La cuenta.
   */
  private function cuenta(string $nombre): array {
    foreach ($this->registro()->forUser((int) $this->alumna->id()) as $cuenta) {
      if ($cuenta['name'] === $nombre) {
        return $cuenta;
      }
    }

    $this->fail('No existe la cuenta ' . $nombre);
  }

  /**
   * El controlador, construido como lo construye Drupal.
   */
  private function controlador(): AccountsController {
    return AccountsController::create($this->container);
  }

  /**
   * El registro de cuentas.
   */
  private function registro(): AccountRegistry {
    return $this->container->get(AccountRegistry::class);
  }

}
