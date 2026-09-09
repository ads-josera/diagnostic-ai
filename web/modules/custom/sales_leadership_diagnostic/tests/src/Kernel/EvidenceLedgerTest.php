<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\LedgerReadTool;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\LedgerWriteTool;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBoxFactory;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba que lo investigado se puede reutilizar en vez de repetirlo.
 *
 * El Evidence Ledger no es un archivo: es el control de costo. Sin él, cada
 * follow-up vuelve a buscar, y su §14 lo dice con un caso concreto —«una cuenta
 * ya investigada que reaparece la semana siguiente: reutilizar ledger y hacer
 * delta/freshness, **no empezar de cero**»—.
 *
 * La prueba que define la fase es la de que sus herramientas siguen
 * disponibles cuando ya no se puede investigar. Es lo que hace cierto el
 * «después de completar, los follow-ups siguen funcionando con evidencia
 * persistida» del §2: sin eso, un follow-up posterior a la misión se quedaría
 * sin nada que decir.
 */
#[CoversClass(EvidenceLedger::class)]
#[CoversClass(LedgerReadTool::class)]
#[CoversClass(LedgerWriteTool::class)]
final class EvidenceLedgerTest extends KernelTestBase {

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
   * Agente de las pruebas. Tiene la búsqueda concedida.
   */
  private const AGENTE = 'prospeccion';

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

    $this->container->get('entity_type.manager')->getStorage('sld_agent')->create([
      'id' => self::AGENTE,
      'label' => 'Prospección',
      'course_id' => '35884',
      'system_prompt' => 'PROMPT',
      'can_search' => TRUE,
    ])->save();

    $this->container->get(CurrentTurn::class)->begin(self::ALUMNO, 42, FALSE, self::AGENTE);
  }

  /**
   * Sin fuente no se guarda.
   *
   * La metodología del cliente prohíbe afirmar sin procedencia. Una evidencia
   * sin ella reaparecería más tarde pareciendo comprobable sin serlo.
   */
  public function testSinFuenteNoSeGuarda(): void {
    $guardada = $this->ledger()->record(self::ALUMNO, 'm1', 42, [
      'scope' => 'Cemex',
      'claim' => 'Va a abrir una planta.',
      'source' => '',
    ]);

    $this->assertFalse($guardada);
    $this->assertSame([], $this->ledger()->recall(self::ALUMNO));
  }

  /**
   * Se recupera por ámbito, aunque no se escriba igual.
   *
   * El agente escribe «Cemex» donde antes anotó «Cemex — distribución Bajío».
   * Exigir igualdad haría inservible el ledger justo cuando más falta hace.
   */
  public function testSeRecuperaAunqueElAmbitoNoSeEscribaIgual(): void {
    $this->anotar('Cemex — distribución Bajío', 'Abrió un centro en Querétaro.');

    $encontrado = $this->ledger()->recall(self::ALUMNO, 'Cemex');

    $this->assertCount(1, $encontrado);
    $this->assertSame('Abrió un centro en Querétaro.', $encontrado[0]['claim']);
  }

  /**
   * Una evidencia vieja se marca STALE al leerla.
   *
   * No se guarda ese estado: se deduce al consultar. Una evidencia no cambia
   * de naturaleza porque nadie haya pasado a revisarla.
   */
  public function testUnaEvidenciaViejaSeMarcaStaleAlLeerla(): void {
    $this->anotar('Cemex', 'Algo de hace tiempo.', hace: 40 * 86400);

    $encontrado = $this->ledger()->recall(self::ALUMNO, 'Cemex');

    $this->assertSame('STALE', $encontrado[0]['status']);
    $this->assertSame(40, $encontrado[0]['age_days']);
  }

  /**
   * Lo declarado contradicho NO vuelve a valer por ser reciente.
   */
  public function testLoContradichoSigueContradichoAunqueSeaReciente(): void {
    $this->anotar('Cemex', 'Algo que resultó falso.');
    $id = (int) $this->ledger()->recall(self::ALUMNO)[0]['id'];

    $this->ledger()->setStatus(self::ALUMNO, $id, 'CONTRADICTED');

    $this->assertSame('CONTRADICTED', $this->ledger()->recall(self::ALUMNO)[0]['status']);
  }

  /**
   * Leer el ledger cuenta como reutilización.
   *
   * Es la medida directa del ahorro que pide el §10: cuántas veces se resolvió
   * algo sin volver a buscar.
   */
  public function testLeerElLedgerCuentaComoReutilizacion(): void {
    $this->anotar('Cemex', 'Un dato útil.');

    $this->leer('Cemex');
    $this->leer('Cemex');

    $this->assertSame(2, $this->ledger()->summarySince(0)['reused']);
  }

  /**
   * La evidencia SOBREVIVE al cierre de la misión.
   *
   * Es el escenario del §14: la cuenta reaparece la semana siguiente y hay que
   * reutilizar, no empezar de cero. Si la evidencia colgara de la misión, se
   * iría con ella.
   */
  public function testLaEvidenciaSobreviveAlCierreDeLaMision(): void {
    $entitlements = $this->container->get(ResearchEntitlementService::class);
    $entitlements->startMission(self::ALUMNO, 42);
    $this->anotar('Cemex', 'Lo que se averiguó en la misión.');

    $entitlements->completeMission(self::ALUMNO);

    $this->assertCount(1, $this->ledger()->recall(self::ALUMNO, 'Cemex'));
  }

  /**
   * LA PRUEBA DE LA FASE: el ledger sigue ahí cuando ya no se puede investigar.
   *
   * Con la misión cerrada y las comprobaciones agotadas, la herramienta de
   * buscar desaparece —el modelo no puede pedir lo que no sabe que existe— pero
   * las del ledger siguen. Sin eso, un follow-up posterior a la misión se
   * quedaría mudo.
   */
  public function testElLedgerSigueDisponibleSinPoderInvestigar(): void {
    $this->setSetting('sld_search_api_key', 'clave-de-prueba');
    $this->config('sales_leadership_diagnostic.settings')
      ->set('search.enabled', TRUE)
      ->set('research.max_rechecks_per_period', 0)
      ->save();

    $entitlements = $this->container->get(ResearchEntitlementService::class);
    $entitlements->startMission(self::ALUMNO, 42);
    $entitlements->completeMission(self::ALUMNO);

    $nombres = array_column(
      $this->container->get(ToolBoxFactory::class)->forTurn()->declarations(),
      'name',
    );

    $this->assertContains(LedgerReadTool::NAME, $nombres, 'Consultar lo que ya se sabe no depende del entitlement.');
    $this->assertContains(LedgerWriteTool::NAME, $nombres);
    $this->assertNotContains(WebSearchTool::NAME, $nombres, 'Buscar fuera sí depende del entitlement.');
  }

  /**
   * Con capacidad para investigar están las tres.
   */
  public function testConCapacidadParaInvestigarEstanLasTres(): void {
    $this->setSetting('sld_search_api_key', 'clave-de-prueba');
    $this->config('sales_leadership_diagnostic.settings')->set('search.enabled', TRUE)->save();

    $nombres = array_column(
      $this->container->get(ToolBoxFactory::class)->forTurn()->declarations(),
      'name',
    );

    $this->assertContains(WebSearchTool::NAME, $nombres);
    $this->assertContains(LedgerReadTool::NAME, $nombres);
  }

  /**
   * Sin búsqueda concedida al agente, el ledger sigue estando.
   *
   * Es el mismo reparto que con el entitlement agotado, pero decidido una
   * puerta más arriba. Importa que salga igual: el agente de diagnóstico no
   * sale a internet, y aun así tiene que poder mirar lo que ya se sabe de la
   * persona. Quitarle también eso lo dejaría sin nada que consultar.
   */
  public function testUnAgenteSinBusquedaConservaElLedger(): void {
    $this->setSetting('sld_search_api_key', 'clave-de-prueba');
    $this->config('sales_leadership_diagnostic.settings')->set('search.enabled', TRUE)->save();

    $this->container->get('entity_type.manager')->getStorage('sld_agent')->create([
      'id' => 'diagnostico_gap',
      'label' => 'Diagnóstico GAP',
      'course_id' => '35885',
      'system_prompt' => 'PROMPT',
      'can_search' => FALSE,
    ])->save();

    $this->container->get(CurrentTurn::class)->begin(self::ALUMNO, 42, FALSE, 'diagnostico_gap');

    $nombres = array_column(
      $this->container->get(ToolBoxFactory::class)->forTurn()->declarations(),
      'name',
    );

    $this->assertNotContains(WebSearchTool::NAME, $nombres, 'Este agente no sale a internet.');
    $this->assertContains(LedgerReadTool::NAME, $nombres);
    $this->assertContains(LedgerWriteTool::NAME, $nombres);
  }

  /**
   * Consultar el ledger no gasta cupo, pero SÍ queda anotado.
   *
   * Las dos mitades importan. No gasta cupo porque no sale a internet, y
   * someterlo a los topes dejaría al agente sin poder mirar lo que ya sabe
   * justo cuando se le acaba de negar buscar. Y se anota porque el §10 pide
   * medir `ledger_reads`: sin esa fila no habría forma de distinguir un
   * follow-up que se resolvió con lo guardado de uno que se quedó sin decir
   * nada.
   */
  public function testConsultarElLedgerNoGastaCupoPeroSeAnota(): void {
    $this->anotar('Cemex', 'Un dato.');

    $this->leerPorElGateway('Cemex');
    $this->leerPorElGateway('Cemex');

    $repositorio = $this->container->get(ToolCallRepository::class);

    // Cuenta como llamada concedida, pero sin texto externo: no trajo nada de
    // fuera, así que no consume el tope de contenido de la misión.
    $this->assertSame(2, $repositorio->usedInMission(42)['calls']);
    $this->assertSame(0, $repositorio->usedInMission(42)['chars'], 'No trae texto de fuera.');
  }

  /**
   * Anota una evidencia, opcionalmente con antigüedad.
   */
  private function anotar(string $ambito, string $afirmacion, int $hace = 0): void {
    $this->ledger()->record(self::ALUMNO, 'm1', 42, [
      'scope' => $ambito,
      'claim' => $afirmacion,
      'summary' => 'Resumen.',
      'source' => 'https://ejemplo.mx/1',
      'evidence_type' => 'HECHO',
      'confidence' => 'ALTA',
    ]);

    if ($hace > 0) {
      $this->container->get('database')->update('sld_evidence')
        ->fields(['observed_at' => $this->container->get('datetime.time')->getRequestTime() - $hace])
        ->execute();
    }
  }

  /**
   * Consulta el ledger directamente, sin gateway.
   */
  private function leer(string $ambito): void {
    (new LedgerReadTool($this->ledger(), $this->container->get(CurrentTurn::class)))
      ->run(['ambito' => $ambito]);
  }

  /**
   * Consulta el ledger por la misma vía que el agente: a través del gateway.
   */
  private function leerPorElGateway(string $ambito): void {
    $this->setSetting('sld_search_api_key', 'clave-de-prueba');
    $this->config('sales_leadership_diagnostic.settings')->set('search.enabled', TRUE)->save();

    $this->container->get(ToolBoxFactory::class)->forTurn()->run(LedgerReadTool::NAME, ['ambito' => $ambito]);
  }

  /**
   * El ledger.
   */
  private function ledger(): EvidenceLedger {
    return $this->container->get(EvidenceLedger::class);
  }

}
