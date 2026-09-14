<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Maintenance\TestDataCleaner;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * La limpieza de datos de prueba borra el uso y conserva todo lo demás.
 *
 * Se usará UNA vez, en producción, el día que el cliente dé el visto bueno
 * (José Raúl, 14-09-2026). Por eso lo que importa aquí es sobre todo lo que
 * NO debe tocar: la configuración, el administrador, el gestor y los alumnos
 * que se piden conservar.
 */
#[CoversClass(TestDataCleaner::class)]
final class TestDataCleanerTest extends KernelTestBase {

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
   * Alumno que se conserva.
   */
  private UserInterface $demo;

  /**
   * Alumno de prueba que se borra si se pide.
   */
  private UserInterface $otro;

  /**
   * Gestor: no es alumno y no se toca nunca.
   */
  private UserInterface $gestor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installEntitySchema('sld_student_memory');
    $this->installSchema('externalauth', ['authmap']);
    // Drupal la limpia al borrar un usuario; sin ella el borrado revienta.
    $this->installSchema('user', ['users_data']);
    $this->installSchema('sales_leadership_diagnostic', TestDataCleaner::TABLAS);
    $this->installConfig(['sales_leadership_diagnostic']);

    foreach ([SalesLeadershipDiagnostic::STUDENT_ROLE_ID, SalesLeadershipDiagnostic::MANAGER_ROLE_ID] as $rol) {
      if (!Role::load($rol)) {
        Role::create(['id' => $rol, 'label' => $rol])->save();
      }
    }

    User::create(['name' => 'admin', 'status' => 1])->save();
    $this->demo = $this->alumno('alumno.demo', '1');
    $this->otro = $this->alumno('alumno.otro', '4821');
    $this->gestor = User::create([
      'name' => 'gestor.sam',
      'status' => 1,
      'roles' => [SalesLeadershipDiagnostic::MANAGER_ROLE_ID],
    ]);
    $this->gestor->save();

    // Uso de las pruebas: una conversación con su informe y su memoria, una
    // cuenta de prospección y un aviso de gasto ya enviado.
    $sesion = $this->container->get('entity_type.manager')->getStorage('sld_diagnostic_session')->create([
      'uid' => $this->otro->id(),
      'wp_user_id' => '4821',
      'course_id' => '35884',
      'agent' => 'liderazgo',
      'diagnostic_version' => '1.0',
      'prompt_snapshot' => 'prompt',
      'prompt_hash' => 'huella',
    ]);
    $sesion->save();
    $this->container->get('entity_type.manager')->getStorage('sld_diagnostic_result')->create([
      'uid' => $this->otro->id(),
      'session_id' => $sesion->id(),
      'diagnostic_version' => '1.0',
      'summary' => 'Resumen.',
      'payload' => '{}',
      'agent' => 'liderazgo',
    ])->save();
    $this->container->get('entity_type.manager')->getStorage('sld_student_memory')->create([
      'uid' => $this->otro->id(),
      'topic' => 'company',
      'content' => 'Distribuidora en Monterrey.',
    ])->save();
    $this->container->get('database')->insert('sld_account')->fields([
      'uid' => $this->otro->id(),
      'agent' => 'prospeccion',
      'name' => 'Constructora Uno',
      'name_key' => 'constructora uno',
      'first_result_id' => 1,
      'last_result_id' => 1,
      'times_in_pack' => 1,
      'first_seen' => 1,
      'last_seen' => 1,
    ])->execute();
    $this->container->get('state')->set('sld.spend_warned.' . $this->otro->id() . '.1', TRUE);
    $this->container->get('state')->set('sales_leadership_diagnostic.wordpress_plugin', ['version' => '1.3.0']);
  }

  /**
   * Simular no borra nada, pero dice qué borraría.
   */
  public function testSimularNoBorraNada(): void {
    $inventario = $this->limpiador()->inventario(TRUE, ['alumno.demo']);

    $this->assertSame(1, $inventario['entidades']['sld_diagnostic_session']);
    $this->assertSame(1, $inventario['tablas']['sld_account']);
    $this->assertSame(1, $inventario['estado']);
    $this->assertSame(['alumno.otro'], $inventario['alumnos'], 'Solo el alumno de prueba; ni el demo ni el gestor.');

    $this->assertSame(1, $this->limpiador()->inventario(FALSE, [])['entidades']['sld_diagnostic_result'], 'Sigue ahí.');
  }

  /**
   * Borra el uso y conserva la configuración y todas las cuentas.
   */
  public function testBorraSoloElUso(): void {
    $this->limpiador()->limpiar(FALSE, ['alumno.demo']);

    $inventario = $this->limpiador()->inventario(TRUE, ['alumno.demo']);
    $this->assertSame([0, 0, 0], array_values($inventario['entidades']));
    $this->assertSame(0, array_sum($inventario['tablas']));
    $this->assertSame(0, $inventario['estado'], 'El aviso de gasto vuelve a poder enviarse.');

    $this->assertNotNull(User::load($this->otro->id()), 'Sin --con-alumnos no se borra ningún usuario.');
    $this->assertSame(['version' => '1.3.0'], $this->container->get('state')->get('sales_leadership_diagnostic.wordpress_plugin'), 'El estado que no es de uso se queda.');
    $this->assertNotNull($this->config('sales_leadership_diagnostic.settings')->get('spending'), 'La configuración se queda.');
  }

  /**
   * Con alumnos, solo el de prueba: el demo, el gestor y el admin se quedan.
   */
  public function testConAlumnosSoloBorraLosDePrueba(): void {
    $this->limpiador()->limpiar(TRUE, ['alumno.demo']);

    $this->assertNull(User::load($this->otro->id()), 'El alumno de prueba se borra.');
    $this->assertNotNull(User::load($this->demo->id()), 'alumno.demo se conserva.');
    $this->assertNotNull(User::load($this->gestor->id()), 'El gestor no es alumno: no se toca.');
    $this->assertNotNull(User::load(1), 'El administrador tampoco.');
  }

  /**
   * Un alumno enlazado a WordPress.
   */
  private function alumno(string $nombre, string $wpId): UserInterface {
    $usuario = User::create(['name' => $nombre, 'status' => 1, 'roles' => [SalesLeadershipDiagnostic::STUDENT_ROLE_ID]]);
    $usuario->save();
    $this->container->get('externalauth.authmap')->save($usuario, SalesLeadershipDiagnostic::AUTHMAP_PROVIDER, $wpId);

    return $usuario;
  }

  /**
   * El servicio bajo prueba.
   */
  private function limpiador(): TestDataCleaner {
    return $this->container->get(TestDataCleaner::class);
  }

}
