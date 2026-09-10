<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DTO\AiCall;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;
use Drupal\sales_leadership_diagnostic\MissionState;
use Drupal\sales_leadership_diagnostic\ResearchAccess;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\LedgerReadTool;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBoxFactory;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolGateway;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolInterface;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageRepository;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Los criterios de aceptación del cliente, uno por uno.
 *
 * Son los A01–A12 del §13 de su especificación de backend. No es una suite
 * nuestra: es la lista contra la que él dijo que revisaría el trabajo, y por
 * eso cada prueba lleva su identificador en el nombre.
 *
 * Los doce están cubiertos desde el 10-09-2026. Hasta esa fecha, **A10 y A11
 * quedaban fuera** porque comprobaban la salida estructurada del Weekly GOLD
 * Pack y el Pack solo existía como prosa dentro de la conversación: escribir
 * esas pruebas antes habría sido fingir cobertura. Ahora el Pack tiene campos
 * y las dos comprueban algo de verdad.
 */
#[CoversNothing]
final class AcceptanceCriteriaTest extends KernelTestBase {

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
   * Herramienta espía: cuenta cuántas veces se buscó de verdad.
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

    $this->setSetting('sld_search_api_key', 'clave-de-prueba');
    $this->config('sales_leadership_diagnostic.settings')
      ->set('search.enabled', TRUE)
      ->set('openai.prices', [
        ['model' => 'modelo', 'input' => 2.0, 'cached_input' => 0.2, 'output' => 12.0],
      ])
      ->save();

    $this->espia = new class() implements ToolInterface {

      /**
       * Cuántas veces se buscó.
       */
      public int $veces = 0;

      /**
       * {@inheritdoc}
       */
      public function name(): string {
        return WebSearchTool::NAME;
      }

      /**
       * {@inheritdoc}
       */
      public function declaration(): array {
        return ['type' => 'function', 'name' => WebSearchTool::NAME];
      }

      /**
       * {@inheritdoc}
       */
      public function run(array $arguments): string {
        $this->veces++;

        return '{"resultados":[{"titulo":"x","url":"https://x.mx"}]}';
      }

    };

    $this->turno(100);
  }

  /**
   * A01 — Primera misión semanal: AVAILABLE → ACTIVE → COMPLETED, medida.
   */
  public function testA01PrimeraMisionSemanal(): void {
    $servicio = $this->entitlements();

    $this->assertSame(MissionState::Available, $servicio->forUser(self::ALUMNO)->state);

    $this->gateway()->run(WebSearchTool::NAME, ['consulta' => 'cemex']);

    $this->assertSame(MissionState::Active, $servicio->forUser(self::ALUMNO)->state);

    $servicio->completeMission(self::ALUMNO);

    $this->assertSame(MissionState::Completed, $servicio->forUser(self::ALUMNO)->state);
    $this->assertSame(1, $this->container->get(ToolCallRepository::class)->usedInMission(100)['calls']);
  }

  /**
   * A02 — Chat nuevo después: no permite una segunda misión sustancial.
   */
  public function testA02ChatNuevoNoPermiteSegundaMision(): void {
    $servicio = $this->entitlements();
    $servicio->startMission(self::ALUMNO, 100);
    $servicio->completeMission(self::ALUMNO);

    $this->turno(200);

    $this->assertFalse($servicio->forUser(self::ALUMNO)->state->canStartMission());
    $this->assertFalse($servicio->startMission(self::ALUMNO, 200));
  }

  /**
   * A03 — Cambiar de Entry Mode no resetea el entitlement.
   *
   * Se cumple por construcción y por eso la prueba mira la construcción: el
   * entitlement no guarda ni consulta el Entry Mode en ninguna parte, así que
   * no hay nada que cambiar al cambiarlo. Lo que sí cambia entre modos es la
   * conversación, y eso ya está cubierto por A02.
   */
  public function testA03CambiarDeEntryModeNoResetea(): void {
    $servicio = $this->entitlements();
    $servicio->startMission(self::ALUMNO, 100);
    $servicio->completeMission(self::ALUMNO);

    $antes = $servicio->forUser(self::ALUMNO);

    // Otra conversación, que es por donde se elige otro Entry Mode.
    $this->turno(300);
    $despues = $servicio->forUser(self::ALUMNO);

    $this->assertSame($antes->state, $despues->state);
    $this->assertSame($antes->missionId, $despues->missionId);
  }

  /**
   * A04 — Follow-up: responde con la evidencia existente y SIN web.
   */
  public function testA04FollowUpResponderSinWeb(): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('research.max_rechecks_per_period', 0)
      ->save();

    $servicio = $this->entitlements();
    $servicio->startMission(self::ALUMNO, 100);
    $this->ledger()->record(self::ALUMNO, 'm1', 100, [
      'scope' => 'Cemex',
      'claim' => 'Abrió un centro en Querétaro.',
      'source' => 'https://ejemplo.mx/1',
    ]);
    $servicio->completeMission(self::ALUMNO);

    $this->turno(200);
    $nombres = array_column($this->container->get(ToolBoxFactory::class)->forTurn()->declarations(), 'name');

    $this->assertContains(LedgerReadTool::NAME, $nombres, 'Puede consultar lo que ya sabe.');
    $this->assertNotContains(WebSearchTool::NAME, $nombres, 'No puede salir a la web.');
    $this->assertCount(1, $this->ledger()->recall(self::ALUMNO, 'Cemex'));
  }

  /**
   * A05 — Recheck puntual: solo lo autorizado, y no abre discovery.
   *
   * «No discovery adicional» se cumple porque una comprobación NO devuelve el
   * estado a ACTIVE: se gasta y el estado sigue siendo COMPLETED.
   */
  public function testA05RecheckPuntualNoAbreDiscovery(): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('research.max_rechecks_per_period', 1)
      ->save();

    $servicio = $this->entitlements();
    $servicio->startMission(self::ALUMNO, 100);
    $servicio->completeMission(self::ALUMNO);

    $this->assertSame(ResearchAccess::TargetedOnly, $servicio->forUser(self::ALUMNO)->access(1));

    $this->turno(200);
    $gateway = $this->gateway();
    $gateway->run(WebSearchTool::NAME, ['consulta' => 'verificar buyer']);
    $gateway->run(WebSearchTool::NAME, ['consulta' => 'otra cosa']);

    $this->assertSame(1, $this->espia->veces, 'Solo cabe la comprobación concedida.');
    $this->assertSame(MissionState::Completed, $servicio->forUser(self::ALUMNO)->state, 'Un recheck NO reabre la misión.');
  }

  /**
   * A06 — Tope a mitad de misión: se bloquea y no se finge investigación.
   */
  public function testA06TopeEnMitadDeLaMision(): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('tools.max_calls_per_mission', 1)
      ->save();

    $gateway = $this->gateway();
    $gateway->run(WebSearchTool::NAME, ['consulta' => 'a']);
    $negada = $gateway->run(WebSearchTool::NAME, ['consulta' => 'b']);

    $this->assertSame(1, $this->espia->veces);
    $this->assertStringContainsString('NO inventes', $negada, 'Se le dice que declare, no que rellene.');
  }

  /**
   * A07 — Concurrencia: una sola misión ACTIVE por persona y periodo.
   */
  public function testA07ConcurrenciaUnaSolaMisionActiva(): void {
    $servicio = $this->entitlements();

    $this->assertTrue($servicio->startMission(self::ALUMNO, 100));
    $this->assertFalse($servicio->startMission(self::ALUMNO, 200));
  }

  /**
   * A08 — Renovación: la semana nueva vuelve a habilitar, una vez.
   *
   * No hace falta ningún proceso que la dispare: al cambiar la semana cambia
   * la clave, no se encuentra fila y nace una nueva en AVAILABLE. Se simula
   * envejeciendo la fila existente.
   */
  public function testA08RenovacionSemanal(): void {
    $servicio = $this->entitlements();
    $servicio->startMission(self::ALUMNO, 100);
    $servicio->completeMission(self::ALUMNO);

    $this->assertSame(MissionState::Completed, $servicio->forUser(self::ALUMNO)->state);

    // La fila de la semana pasada se queda donde está; la de esta nace limpia.
    $this->container->get('database')->update('sld_research_entitlement')
      ->fields(['period' => '2020-W01'])
      ->condition('uid', self::ALUMNO)
      ->execute();

    $this->assertSame(MissionState::Available, $servicio->forUser(self::ALUMNO)->state);
    $this->assertTrue($servicio->startMission(self::ALUMNO, 300));
    $this->assertFalse($servicio->startMission(self::ALUMNO, 400), 'Habilita UNA vez, no dos.');
  }

  /**
   * A09 — La semana siguiente reutiliza la evidencia vigente.
   */
  public function testA09ReutilizaLaEvidenciaDeLaSemanaAnterior(): void {
    $servicio = $this->entitlements();
    $servicio->startMission(self::ALUMNO, 100);
    $this->ledger()->record(self::ALUMNO, 'm1', 100, [
      'scope' => 'Cemex',
      'claim' => 'Abrió un centro en Querétaro.',
      'source' => 'https://ejemplo.mx/1',
    ]);
    $servicio->completeMission(self::ALUMNO);

    // Pasa una semana.
    $this->container->get('database')->update('sld_research_entitlement')
      ->fields(['period' => '2020-W01'])
      ->condition('uid', self::ALUMNO)
      ->execute();

    $recordado = $this->ledger()->recall(self::ALUMNO, 'Cemex');

    $this->assertCount(1, $recordado, 'La evidencia sobrevive a la renovación.');
    $this->assertSame('CURRENT', $recordado[0]['status'], 'Y sigue vigente, con su delta por antigüedad.');
  }

  /**
   * A10 — Una cuenta sin mensaje no sale marcada como lista para enviar.
   *
   * Su §13: «PREPARED sin mensaje: el validador lo corrige a BLOCKED». Corrige,
   * no avisa: el estado de envío es lo que mira alguien para decidir si manda
   * algo hoy.
   *
   * Se comprueban los DOS estados que prometen un mensaje. Su criterio nombra
   * PREPARED, pero RELEASED sin mensaje es peor —dice que se puede enviar ya— y
   * taparlo solo en uno dejaría abierta la puerta más ancha.
   */
  public function testA10UnaCuentaSinMensajeSeBloquea(): void {
    $resultado = $this->validar([
      'pool_declared' => 3,
      'accounts' => [
        ['name' => 'Grupo MexAmerik', 'outreach_status' => 'PREPARED', 'outreach_message' => ''],
        ['name' => 'Olympic Transport', 'outreach_status' => 'RELEASED', 'outreach_message' => '   '],
        [
          'name' => 'XBorder Solutions',
          'outreach_status' => 'PREPARED',
          'outreach_message' => 'Vi que abrieron una vacante…',
        ],
      ],
    ]);

    $cuentas = $resultado['accounts'];

    $this->assertSame('BLOCKED', $cuentas[0]['outreach_status'], 'PREPARED sin mensaje se bloquea.');
    $this->assertSame('BLOCKED', $cuentas[1]['outreach_status'], 'RELEASED con un mensaje en blanco, también.');
    $this->assertNotSame('', $cuentas[0]['blocked_reason'], 'Y se dice por qué.');

    $this->assertSame(
      'PREPARED',
      $cuentas[2]['outreach_status'],
      'La que sí trae mensaje se queda como está.',
    );
  }

  /**
   * A11 — El pool declarado no puede superar a las cuentas con nombre.
   *
   * Su §13: «Pool declarado ≥10: el validador exige cuentas nominales».
   * Declarar diez y nombrar tres no es un pool auditable, y su metodología pide
   * que lo sea.
   *
   * Lo declarado NO se borra: queda aparte. La diferencia entre lo dicho y lo
   * sostenido es justo el dato que dice si el agente infla el pool.
   */
  public function testA11ElPoolDeclaradoSeCuadraConLasCuentasNombradas(): void {
    $resultado = $this->validar([
      'pool_declared' => 10,
      'accounts' => [
        ['name' => 'Grupo MexAmerik'],
        ['name' => 'Olympic Transport'],
        // Sin nombre: no es auditable y no cuenta.
        ['name' => '   '],
      ],
    ]);

    $this->assertSame(2, $resultado['pool_declared'], 'Solo dos cuentas se pueden auditar.');
    $this->assertSame(10, $resultado['pool_claimed'], 'Lo que declaró se conserva.');
  }

  /**
   * Un pool honesto no se toca.
   *
   * Va junto a A11 porque una corrección que se dispara siempre no corrige
   * nada: deja de distinguir el caso bueno del malo.
   */
  public function testUnPoolQueCuadraNoSeToca(): void {
    $resultado = $this->validar([
      'pool_declared' => 2,
      'accounts' => [['name' => 'Grupo MexAmerik'], ['name' => 'Olympic Transport']],
    ]);

    $this->assertSame(2, $resultado['pool_declared']);
    $this->assertArrayNotHasKey('pool_claimed', $resultado, 'No hubo nada que cuadrar.');
  }

  /**
   * Pasa un resultado por el validador y devuelve lo que quedó.
   *
   * @param array<string, mixed> $result
   *   La parte estructurada, tal como la devolvería el motor.
   *
   * @return array<string, mixed>
   *   El resultado ya validado.
   */
  private function validar(array $result): array {
    $turno = $this->container->get(DiagnosticResponseValidator::class)->validate([
      'type' => 'diagnostic_result',
      'status' => 'completed',
      'message' => 'El Weekly GOLD Pack.',
      'result' => $result,
    ]);

    $this->assertIsArray($turno->result);

    return $turno->result;
  }

  /**
   * A12 — Cada misión deja telemetría completa y su coste.
   */
  public function testA12ObservabilidadCompleta(): void {
    $this->container->get(AiUsageRepository::class)->recordAll(
      [new AiCall('modelo', 'Turno generado', 1000, 900, 200, 50, 1200, 1)],
      self::ALUMNO,
      'prospecting_diagnostic',
      100,
    );

    $this->gateway()->run(WebSearchTool::NAME, ['consulta' => 'cemex']);

    $consumo = $this->container->get(AiUsageRepository::class)->summaryForSession(100);
    $llamadas = $this->container->get(ToolCallRepository::class)->usedInMission(100);

    $this->assertSame(1, $consumo['calls']);
    $this->assertSame(900, $consumo['cached'], 'Lo cacheado se separa de lo que se paga entero.');
    $this->assertGreaterThan(0.0, $consumo['cost'], 'Con su coste estimado.');
    $this->assertSame(1, $llamadas['calls'], 'Y las búsquedas, contadas aparte.');
  }

  /**
   * Declara de quién es el turno.
   */
  private function turno(int $mision): void {
    $this->container->get(CurrentTurn::class)->begin(self::ALUMNO, $mision, FALSE);
  }

  /**
   * El servicio del entitlement.
   */
  private function entitlements(): ResearchEntitlementService {
    return $this->container->get(ResearchEntitlementService::class);
  }

  /**
   * El ledger.
   */
  private function ledger(): EvidenceLedger {
    return $this->container->get(EvidenceLedger::class);
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
      $this->entitlements(),
    );
  }

}
