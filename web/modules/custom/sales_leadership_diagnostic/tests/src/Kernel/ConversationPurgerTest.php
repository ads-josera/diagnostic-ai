<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\ConversationPurger;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba qué se retira al cumplirse el plazo de conservación, y qué no.
 *
 * Lo que más importa aquí es lo segundo. Una purga que se lleve de más destruye
 * el entregable del alumno o la trazabilidad de un diagnóstico, y a diferencia
 * de casi todo lo demás del módulo eso no tiene vuelta atrás.
 *
 * El motivo de existir es la privacidad, y solo esa. Se midió antes de
 * diseñarlo (26-08-2026): con diez sesiones reales el módulo entero ocupaba
 * menos de 700 KB, así que el espacio nunca fue el problema.
 */
#[CoversClass(ConversationPurger::class)]
final class ConversationPurgerTest extends KernelTestBase {

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
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installConfig(['sales_leadership_diagnostic']);

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->alumno = User::create(['name' => 'alumno', 'status' => 1]);
    $this->alumno->save();
  }

  /**
   * De fábrica no purga nada.
   *
   * El plazo viene en cero, que significa conservar indefinidamente. Una
   * instalación existente no debe empezar a borrar datos porque alguien
   * actualice el módulo: activarlo tiene que ser una decisión de alguien.
   */
  public function testDeFabricaNoPurgaNada(): void {
    $sesion = $this->crearSesion(DiagnosticStatus::Completed, antiguedadDias: 3650);

    $this->assertSame(0, $this->purger()->getRetentionDays());
    $this->assertSame(0, $this->purger()->purge());
    $this->assertSame(2, $this->contarMensajes($sesion));
  }

  /**
   * Con plazo activo, una conversación terminada y vieja se vacía.
   */
  public function testUnaConversacionTerminadaHaceMuchoSeVacia(): void {
    $sesion = $this->crearSesion(DiagnosticStatus::Completed, antiguedadDias: 400);
    $this->fijarPlazo(365);

    $this->assertSame(1, $this->purger()->purge());
    $this->assertSame(0, $this->contarMensajes($sesion));
  }

  /**
   * Una conversación reciente no se toca.
   */
  public function testUnaConversacionRecienteNoSeToca(): void {
    $sesion = $this->crearSesion(DiagnosticStatus::Completed, antiguedadDias: 10);
    $this->fijarPlazo(365);

    $this->assertSame(0, $this->purger()->purge());
    $this->assertSame(2, $this->contarMensajes($sesion));
  }

  /**
   * Una conversación A MEDIAS no se toca, por vieja que sea.
   *
   * El alumno puede volver a ella. Vaciarla le dejaría una pantalla sin
   * sentido en lugar de su trabajo, y ninguna explicación de por qué.
   */
  public function testUnaConversacionSinTerminarNoSeToca(): void {
    $sesion = $this->crearSesion(DiagnosticStatus::InProgress, antiguedadDias: 900);
    $this->fijarPlazo(365);

    $this->assertSame(0, $this->purger()->purge());
    $this->assertSame(2, $this->contarMensajes($sesion));
  }

  /**
   * La sesión y su copia del prompt SOBREVIVEN.
   *
   * Es la decisión de diseño que separa esto de un borrado a lo bruto. Lo
   * sensible es lo que escribió la persona; la copia del prompt es lo que
   * permite saber años después con qué instrucciones se produjo su
   * diagnóstico (§57). Borrar la sesión entera habría cambiado un problema de
   * privacidad por uno de trazabilidad.
   */
  public function testLaSesionConservaSuCopiaDelPrompt(): void {
    $sesion = $this->crearSesion(DiagnosticStatus::Completed, antiguedadDias: 400);
    $this->fijarPlazo(365);

    $this->purger()->purge();

    $guardada = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->load($sesion);

    $this->assertNotNull($guardada);
    $this->assertSame('PROMPT CONGELADO', $guardada->getPromptSnapshot());
  }

  /**
   * El diagnóstico NO se toca.
   *
   * Es el entregable que el alumno compró.
   */
  public function testElDiagnosticoNoSeToca(): void {
    $sesion = $this->crearSesion(DiagnosticStatus::Completed, antiguedadDias: 400);

    $resultado = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_result')
      ->create([
        'uid' => $this->alumno->id(),
        'session_id' => $sesion,
        'agent' => 'agente',
        'diagnostic_version' => '1.1',
        'summary' => 'Análisis del negocio.',
        'score' => 30,
      ]);
    $resultado->save();

    $this->fijarPlazo(365);
    $this->purger()->purge();

    $this->assertNotNull(
      $this->container->get('entity_type.manager')
        ->getStorage('sld_diagnostic_result')
        ->load($resultado->id()),
    );
  }

  /**
   * Fija el plazo de conservación.
   */
  private function fijarPlazo(int $dias): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('diagnostic.conversation_retention_days', $dias)
      ->save();
  }

  /**
   * Crea una sesión con dos mensajes y la antigüedad indicada.
   *
   * @return int
   *   Identificador de la sesión.
   */
  private function crearSesion(DiagnosticStatus $estado, int $antiguedadDias): int {
    $sesion = DiagnosticSession::create([
      'uid' => $this->alumno->id(),
      'wp_user_id' => '777',
      'course_id' => '35884',
      'agent' => 'agente',
      'diagnostic_version' => '1.1',
      'prompt_snapshot' => 'PROMPT CONGELADO',
      'prompt_hash' => hash('sha256', 'PROMPT CONGELADO'),
    ]);
    $sesion->setStatus($estado);
    $sesion->save();

    $id = (int) $sesion->id();
    $mensajes = $this->container->get(DiagnosticMessageRepository::class);
    $mensajes->append($id, MessageRole::Assistant, '¿A qué se dedica tu empresa?');
    $mensajes->append($id, MessageRole::User, 'Facturamos 40 millones.');

    // `changed` se fija a mano: la entidad lo pone al guardar, y la purga se
    // decide por él. Es la única forma de simular el paso del tiempo sin
    // esperarlo.
    $this->container->get('database')
      ->update('sld_diagnostic_session')
      ->fields(['changed' => \Drupal::time()->getRequestTime() - ($antiguedadDias * 86400)])
      ->condition('id', $id)
      ->execute();

    return $id;
  }

  /**
   * Cuenta los mensajes de una sesión.
   */
  private function contarMensajes(int $sesion): int {
    return (int) $this->container->get('database')
      ->select('sld_diagnostic_message', 'm')
      ->condition('session_id', $sesion)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * El servicio bajo prueba.
   */
  private function purger(): ConversationPurger {
    return $this->container->get(ConversationPurger::class);
  }

}
