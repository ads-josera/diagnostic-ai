<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DTO\Entitlement;
use Drupal\sales_leadership_diagnostic\MissionState;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\LedgerReadTool;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchBudget;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * El presupuesto de investigación tiene tres valores y sigue el consumo real.
 *
 * Nace del peor día del proyecto, el 02-10-2026. El cliente comparó el pack de
 * nuestro agente con el de ChatGPT usando el mismo prompt y los mismos
 * documentos, y el nuestro entregó UNA cuenta donde el otro entregó diez.
 *
 * La causa estaba aquí. El §2 de su especificación define
 * `AVAILABLE | LIMITED | EXHAUSTED`, y el módulo emitía `LIMITED` siempre que
 * hubiera cualquier capacidad —incluso con la misión recién abierta y las
 * cuarenta búsquedas intactas—. El agente razona con ese vocabulario porque es
 * el suyo: se racionó, hizo ocho búsquedas y le explicó a la persona que no
 * había podido cribar diez cuentas «dentro del límite de investigación
 * disponible». No se había denegado ni una sola llamada.
 *
 * El otro desajuste, de la misma raíz: su tabla de estados manda `EXHAUSTED`
 * cuando el presupuesto se agota, y el módulo solo lo decía al acabarse el
 * cupo semanal. Una misión que chocaba contra sus topes seguía anunciando
 * `LIMITED` mientras el gateway denegaba por detrás.
 */
#[CoversClass(ResearchBudget::class)]
final class ResearchBudgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
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
    $this->installSchema('sales_leadership_diagnostic', ['sld_tool_call']);
    $this->installConfig(['sales_leadership_diagnostic']);
  }

  /**
   * Alumno de las pruebas.
   */
  private const UID = 7;

  /**
   * Conversación de las pruebas.
   */
  private const SESION = 42;

  /**
   * Una misión recién abierta tiene el presupuesto DISPONIBLE, no limitado.
   *
   * Es la que estaba mal, y la que costó la comparación con ChatGPT.
   */
  public function testUnaMisionNuevaDiceDisponible(): void {
    $this->assertSame('AVAILABLE', $this->presupuesto(MissionState::Active));
  }

  /**
   * Con la misión cerrada y comprobaciones puntuales, el alcance es estrecho.
   *
   * Aquí `LIMITED` sí es la palabra correcta, y es el caso que el ejemplo de
   * su especificación ilustra.
   */
  public function testConSoloComprobacionesPuntualesDiceLimitado(): void {
    $this->assertSame('LIMITED', $this->presupuesto(MissionState::Completed));
  }

  /**
   * Sin capacidad ninguna, agotado.
   */
  public function testSinCupoDiceAgotado(): void {
    $this->assertSame(
      'EXHAUSTED',
      $this->presupuesto(MissionState::Completed, rechecksUsados: 3),
    );
  }

  /**
   * Al acercarse al tope de la misión, avisa de que queda poco.
   *
   * Con 40 búsquedas de tope, 30 son tres cuartos: a partir de ahí el agente
   * tiene que administrar lo que le queda, y enterarse al 95 % sería
   * enterarse cuando ya no puede hacer nada distinto.
   */
  public function testAlAcercarseAlTopeDeLaMisionAvisa(): void {
    $this->gastarBusquedas(30);

    $this->assertSame('LIMITED', $this->presupuesto(MissionState::Active));
  }

  /**
   * Y al alcanzarlo dice agotado, que es cuando el gateway ya deniega.
   *
   * Sin esto, el agente seguía pidiendo búsquedas que le rebotaban y acababa
   * explicándole a la persona un fallo que en realidad era un tope.
   */
  public function testAlLlegarAlTopeDiceAgotado(): void {
    $this->gastarBusquedas(40);

    $this->assertSame('EXHAUSTED', $this->presupuesto(MissionState::Active));
  }

  /**
   * El texto traído cuenta igual que las llamadas.
   *
   * Son dos topes distintos y cualquiera de los dos puede ser el que se agote
   * primero: una misión con pocas búsquedas muy largas llega antes por aquí.
   */
  public function testElTextoTraidoTambienConsumePresupuesto(): void {
    // 160 000 es el tope; 130 000 pasa de tres cuartos sin llegar al final.
    $this->gastarBusquedas(2, 65000);

    $this->assertSame('LIMITED', $this->presupuesto(MissionState::Active));
  }

  /**
   * Las consultas al registro de evidencia NO gastan presupuesto.
   *
   * Están exentas en el gateway, y si aquí contaran, el bloque avisaría de
   * escasez por mirar lo que ya se sabía. Es el mismo error que ya se corrigió
   * una vez en los contadores.
   */
  public function testElRegistroDeEvidenciaNoGastaPresupuesto(): void {
    $this->gastarBusquedas(40, 100, LedgerReadTool::NAME);

    $this->assertSame('AVAILABLE', $this->presupuesto(MissionState::Active));
  }

  /**
   * Un tope en cero significa «sin tope», y no agota nada.
   */
  public function testUnTopeEnCeroNoAgotaNada(): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('tools.max_calls_per_mission', 0)
      ->set('tools.max_retrieved_chars_per_mission', 0)
      ->set('tools.max_calls_per_user_period', 0)
      ->save();

    $this->gastarBusquedas(500);

    $this->assertSame('AVAILABLE', $this->presupuesto(MissionState::Active));
  }

  /**
   * El valor para un estado dado.
   */
  private function presupuesto(MissionState $estado, int $rechecksUsados = 0): string {
    $entitlement = new Entitlement(
      uid: self::UID,
      period: '2026-W40',
      state: $estado,
      rechecksUsed: $rechecksUsados,
    );

    return $this->container->get(ResearchBudget::class)
      ->forSession($entitlement, 3, self::SESION);
  }

  /**
   * Anota búsquedas ya hechas en esta misión.
   */
  private function gastarBusquedas(int $cuantas, int $caracteres = 100, string $herramienta = WebSearchTool::NAME): void {
    $registro = $this->container->get(ToolCallRepository::class);

    for ($i = 0; $i < $cuantas; $i++) {
      $registro->record(
        uid: self::UID,
        sessionId: self::SESION,
        tool: $herramienta,
        query: 'consulta ' . $i,
        allowed: TRUE,
        retrievedChars: $caracteres,
      );
    }
  }

}
