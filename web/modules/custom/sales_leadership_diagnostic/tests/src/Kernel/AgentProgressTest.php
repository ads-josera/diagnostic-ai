<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\AgentProgress;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Lo que dice la tarjeta de cada agente: lo hecho y lo pendiente.
 *
 * El 12-09-2026 a un alumno con tres diagnósticos terminados le salía «A
 * medias» solo por haber abierto el chat y salido sin escribir, y José Raúl no
 * entendía la etiqueta. Estas pruebas fijan lo que ahora dice.
 */
#[CoversClass(AgentProgress::class)]
final class AgentProgressTest extends KernelTestBase {

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
   * Dueño de las sesiones.
   */
  private User $alumno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();
    $this->alumno = User::create(['name' => 'alumna', 'status' => 1]);
    $this->alumno->save();
  }

  /**
   * Sin nada con ese agente: sin empezar.
   */
  public function testSinNadaEsSinEmpezar(): void {
    $this->assertSame('Sin empezar', $this->progreso([])['label']);
  }

  /**
   * Solo terminados: cuántos.
   */
  public function testSoloTerminadosDiceCuantos(): void {
    $progreso = $this->progreso([
      $this->sesion(DiagnosticStatus::Completed),
      $this->sesion(DiagnosticStatus::Completed),
      $this->sesion(DiagnosticStatus::Completed),
    ]);

    $this->assertSame('3 realizados', $progreso['label']);
    $this->assertNull($progreso['resumable']);
    $this->assertSame('1 realizado', $this->progreso([$this->sesion(DiagnosticStatus::Completed)])['label']);
  }

  /**
   * Una empezada y ninguna terminada: a medias, y se puede continuar.
   */
  public function testUnaEmpezadaQuedaPendiente(): void {
    $abierta = $this->sesion(DiagnosticStatus::InProgress);
    $progreso = $this->progreso([$abierta]);

    $this->assertSame('A medias', $progreso['label']);
    $this->assertSame((int) $abierta->id(), $progreso['resumable']);
  }

  /**
   * Lo hecho y lo pendiente, juntos: «A medias» ya no tapa lo terminado.
   */
  public function testLoHechoConLoPendiente(): void {
    $progreso = $this->progreso([
      $this->sesion(DiagnosticStatus::InProgress),
      $this->sesion(DiagnosticStatus::Completed),
      $this->sesion(DiagnosticStatus::Completed),
      $this->sesion(DiagnosticStatus::Completed),
    ]);

    $this->assertSame('3 realizados · uno a medias', $progreso['label']);
  }

  /**
   * Un chat abierto y vacío no es trabajo a medias.
   *
   * Es el caso que confundía: entrar al chat y salir sin escribir dejaba la
   * tarjeta en «A medias» y el botón en «Continuar».
   */
  public function testUnChatVacioNoCuenta(): void {
    $progreso = $this->progreso([
      $this->sesion(DiagnosticStatus::Draft),
      $this->sesion(DiagnosticStatus::Completed),
    ]);

    $this->assertSame('1 realizado', $progreso['label']);
    $this->assertFalse($progreso['open']);
    $this->assertNull($progreso['resumable'], 'El botón no dice «Continuar».');
  }

  /**
   * Uno que se está generando en segundo plano sí está a medias.
   *
   * Pero todavía no se puede continuar escribiendo.
   */
  public function testUnoEnProcesoQuedaPendienteSinContinuar(): void {
    $progreso = $this->progreso([$this->sesion(DiagnosticStatus::Processing)]);

    $this->assertSame('A medias', $progreso['label']);
    $this->assertNull($progreso['resumable']);
  }

  /**
   * Ni los ensayos del gestor ni lo de otro agente se cuentan.
   */
  public function testNiEnsayosNiOtroAgenteCuentan(): void {
    $progreso = $this->progreso([
      $this->sesion(DiagnosticStatus::Completed, ensayo: TRUE),
      $this->sesion(DiagnosticStatus::InProgress, agente: 'otro'),
      $this->sesion(DiagnosticStatus::Completed, agente: 'otro'),
    ]);

    $this->assertSame('Sin empezar', $progreso['label']);
  }

  /**
   * Lo que devuelve la pieza para el agente de la prueba.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sesiones
   *   Sesiones, de la más reciente a la más antigua.
   *
   * @return array<string, mixed>
   *   El progreso.
   */
  private function progreso(array $sesiones): array {
    return $this->container->get(AgentProgress::class)->describe($sesiones, 'liderazgo');
  }

  /**
   * Una sesión del alumno en ese estado.
   */
  private function sesion(DiagnosticStatus $estado, string $agente = 'liderazgo', bool $ensayo = FALSE): DiagnosticSessionInterface {
    $sesion = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->create([
        'uid' => $this->alumno->id(),
        'wp_user_id' => '4821',
        'course_id' => '35884',
        'agent' => $agente,
        'diagnostic_version' => '1.0',
        'prompt_snapshot' => 'prompt',
        'prompt_hash' => 'huella',
        'is_sandbox' => $ensayo,
      ]);
    $sesion->setStatus($estado);
    $sesion->save();

    return $sesion;
  }

}
