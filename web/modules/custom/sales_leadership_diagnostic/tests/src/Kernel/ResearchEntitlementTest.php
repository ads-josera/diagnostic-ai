<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\MissionState;
use Drupal\sales_leadership_diagnostic\ResearchAccess;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolGateway;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolInterface;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchRuntime;
use Drupal\sales_leadership_diagnostic\Service\Research\TurnClassifier;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use Drupal\sales_leadership_diagnostic\TurnClass;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba el Research Entitlement contra los escenarios del cliente.
 *
 * Su §14 enumera los casos que hay que resolver, y varios de ellos son
 * exactamente las formas de saltarse el control. El que más importa lo escribe
 * él así:
 *
 * > «Usuario abre chat nuevo después de completar misión: **sigue bloqueado**
 * > para nueva Research Mission.»
 *
 * De ahí sale la decisión de fondo de todo el diseño: el estado NO cuelga de
 * la conversación sino de la persona y la semana. Si colgara de la
 * conversación, cerrar el chat y abrir otro devolvería una misión nueva.
 */
#[CoversClass(ResearchEntitlementService::class)]
#[CoversClass(TurnClassifier::class)]
#[CoversClass(ResearchRuntime::class)]
final class ResearchEntitlementTest extends KernelTestBase {

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
   */
  private const ALUMNO = 7;

  /**
   * Herramienta espía: cuenta cuántas veces se la ejecutó de verdad.
   */
  private ToolInterface $espia;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('sales_leadership_diagnostic', [
      'sld_ai_usage',
      'sld_tool_call',
      'sld_research_entitlement',
      'sld_evidence',
    ]);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    $this->espia = new class() implements ToolInterface {

      /**
       * Cuántas veces se ejecutó.
       */
      public int $veces = 0;

      /**
       * {@inheritdoc}
       */
      public function name(): string {
        return 'buscar_web';
      }

      /**
       * {@inheritdoc}
       */
      public function declaration(): array {
        return ['type' => 'function', 'name' => 'buscar_web'];
      }

      /**
       * {@inheritdoc}
       */
      public function run(array $arguments): string {
        $this->veces++;

        return '{"resultados":[]}';
      }

    };
  }

  /**
   * Quien no ha investigado nunca empieza pudiendo hacerlo.
   */
  public function testQuienNoHaInvestigadoEmpiezaPudiendo(): void {
    $entitlement = $this->servicio()->forUser(self::ALUMNO);

    $this->assertSame(MissionState::Available, $entitlement->state);
    $this->assertSame(ResearchAccess::Allowed, $entitlement->access(3));
  }

  /**
   * La misión se abre en la PRIMERA búsqueda, no al empezar a conversar.
   *
   * Abrirla al empezar quemaría la misión semanal de quien entra solo a
   * preguntar algo.
   */
  public function testLaMisionSeAbreEnLaPrimeraBusqueda(): void {
    $this->turno(mision: 100);

    $this->assertSame(MissionState::Available, $this->servicio()->forUser(self::ALUMNO)->state);

    $this->gateway()->run('buscar_web', ['consulta' => 'cemex']);

    $this->assertSame(MissionState::Active, $this->servicio()->forUser(self::ALUMNO)->state);
  }

  /**
   * Dos peticiones a la vez NO abren dos misiones.
   *
   * Su §14 lo pide como «lock atómico por user/week». Aquí lo da la base: el
   * segundo intento no toca ninguna fila y se entera.
   */
  public function testDosPeticionesSimultaneasNoAbrenDosMisiones(): void {
    $servicio = $this->servicio();

    $this->assertTrue($servicio->startMission(self::ALUMNO, 100));
    $this->assertFalse($servicio->startMission(self::ALUMNO, 200), 'La segunda no debe abrir nada.');
  }

  /**
   * EL ESCENARIO DEL §14: abrir otro chat tras completar sigue bloqueado.
   *
   * Es la prueba que justifica que el estado viva en la persona y no en la
   * conversación.
   */
  public function testAbrirOtroChatTrasCompletarSigueBloqueado(): void {
    $servicio = $this->servicio();
    $servicio->startMission(self::ALUMNO, 100);
    $servicio->completeMission(self::ALUMNO);

    // Conversación nueva, mismo alumno, misma semana.
    $entitlement = $servicio->forUser(self::ALUMNO);

    $this->assertSame(MissionState::Completed, $entitlement->state);
    $this->assertFalse($entitlement->state->canStartMission());
  }

  /**
   * Tras completar quedan comprobaciones puntuales, y se acaban.
   *
   * Es el «permitir alcance estrecho; NO resetear misión» de su §4.
   */
  public function testTrasCompletarQuedanComprobacionesQueSeAcaban(): void {
    $this->fijarRechecks(2);
    $servicio = $this->servicio();
    $servicio->startMission(self::ALUMNO, 100);
    $servicio->completeMission(self::ALUMNO);

    $this->assertSame(ResearchAccess::TargetedOnly, $servicio->forUser(self::ALUMNO)->access(2));

    $this->turno(mision: 200);
    $gateway = $this->gateway();

    foreach (['a', 'b', 'c', 'd'] as $consulta) {
      $gateway->run('buscar_web', ['consulta' => $consulta]);
    }

    $this->assertSame(2, $this->espia->veces, 'Solo caben las dos comprobaciones concedidas.');
    $this->assertSame(ResearchAccess::NotAvailable, $servicio->forUser(self::ALUMNO)->access(2));
  }

  /**
   * La clase del turno se deduce del estado, nunca del texto.
   *
   * Es lo que impide el bypass por prompt injection: pedir «investiga de
   * nuevo» no cambia nada, porque nadie le pregunta al modelo qué clase de
   * turno cree que es.
   */
  public function testLaClaseDelTurnoSeDeduceDelEstado(): void {
    $this->fijarRechecks(1);
    $servicio = $this->servicio();
    $clasificador = $this->container->get(TurnClassifier::class);

    $this->assertSame(TurnClass::ResearchMission, $clasificador->classify($servicio->forUser(self::ALUMNO), 1));

    $servicio->startMission(self::ALUMNO, 100);
    $servicio->completeMission(self::ALUMNO);

    $this->assertSame(TurnClass::TargetedRecheck, $clasificador->classify($servicio->forUser(self::ALUMNO), 1));

    $servicio->useRecheck(self::ALUMNO);

    $this->assertSame(TurnClass::MissionFollowUp, $clasificador->classify($servicio->forUser(self::ALUMNO), 1));
  }

  /**
   * El bloque que ve el agente NO lleva dinero ni topes.
   *
   * Su §5 es explícito: «no exponer costos internos, secretos, límites
   * monetarios ni lógica sensible al usuario. El modelo necesita conocer
   * permiso/capacidad operativa, no la contabilidad». Y lo que entra al agente
   * puede acabar en la pantalla de una persona.
   */
  public function testElBloqueNoLlevaDineroNiTopes(): void {
    $servicio = $this->servicio();
    $servicio->startMission(self::ALUMNO, 100);
    $entitlement = $servicio->forUser(self::ALUMNO);

    $bloque = $this->container->get(ResearchRuntime::class)
      ->compose($entitlement, TurnClass::ResearchMission, 3);

    $this->assertStringContainsString('RESEARCH_RUNTIME', $bloque);
    $this->assertStringContainsString('mission_state: ACTIVE', $bloque);
    $this->assertStringContainsString('external_research: ALLOWED', $bloque);
    $this->assertStringContainsString('mission_id: ', $bloque);

    $this->assertDoesNotMatchRegularExpression('/USD|MXN|\$|coste|presupuesto|tope/i', $bloque);

    // Ni el identificador de la persona ni el de la conversación. Se comprueba
    // por nombre de campo y no buscando el número: un dígito suelto aparece en
    // la semana ISO y en el hash de la misión, y esa comprobación pasaría o
    // fallaría por casualidad.
    $campos = array_map(
      static fn (string $linea): string => explode(':', $linea)[0],
      array_slice(explode("\n", $bloque), 1),
    );

    $this->assertSame([
      'mission_state',
      'mission_id',
      'turn_class',
      'external_research',
      'research_budget',
      'entitlement_period',
      'evidence_ledger_available',
      'allowed_actions',
    ], $campos, 'El bloque lleva exactamente los campos del §5, ni uno más.');
  }

  /**
   * El periodo es la semana ISO, no el mes.
   */
  public function testElPeriodoEsLaSemanaIso(): void {
    $this->assertMatchesRegularExpression(
      '/^\d{4}-W\d{2}$/',
      $this->servicio()->periodFor(self::ALUMNO),
    );
  }

  /**
   * Declara de quién es el turno.
   */
  private function turno(int $mision): void {
    $this->container->get(CurrentTurn::class)->begin(self::ALUMNO, $mision, FALSE);
  }

  /**
   * Fija cuántas comprobaciones puntuales se conceden.
   */
  private function fijarRechecks(int $cuantas): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('research.max_rechecks_per_period', $cuantas)
      ->save();
  }

  /**
   * El servicio del entitlement.
   */
  private function servicio(): ResearchEntitlementService {
    return $this->container->get(ResearchEntitlementService::class);
  }

  /**
   * El gateway envolviendo a la herramienta espía.
   */
  private function gateway(): ToolGateway {
    return new ToolGateway(
      new ToolBox([$this->espia]),
      $this->container->get(CurrentTurn::class),
      $this->container->get(ToolCallRepository::class),
      $this->container->get(SpendGuard::class),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->servicio(),
    );
  }

}
