<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Search\SearchProviderInterface;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;

/**
 * Decide qué herramientas hay en un turno.
 *
 * La búsqueda externa tiene que superar TRES puertas, y están en este orden a
 * propósito, de la más general a la más particular:
 *
 *  1. **El interruptor del módulo** (`search.enabled`) y que haya buscador
 *     configurado. Es el corte de emergencia: apagarlo apaga todo el sitio.
 *     Nace APAGADO, porque encenderlo cambia lo que el agente puede hacer y lo
 *     que cuesta cada turno, y eso no debe pasar por instalar una versión.
 *  2. **El agente** (`can_search`). Responde a «¿este agente necesita salir a
 *     internet?». El de diagnóstico GAP no: diagnostica a la persona con lo
 *     que ella cuenta. El de prospección sí: su trabajo es mirar cuentas.
 *  3. **La persona** (el Research Entitlement del §2). Responde a «¿le queda
 *     misión esta semana?».
 *
 * La segunda puerta existe porque la tercera se comparte. La misión es una por
 * persona y semana, y vale para todos los agentes: sin esta puerta, un agente
 * que no necesita buscar puede gastarle a alguien la investigación de la
 * semana, y el fallo no se ve —el otro agente, días después, simplemente dice
 * que ya no puede investigar—. Además, declarar herramientas cambia el prefijo
 * del prompt y tira su caché, que es el descuento del que vive el coste por
 * turno.
 */
final class ToolBoxFactory {

  public function __construct(
    private readonly SearchProviderInterface $search,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly CurrentTurn $turn,
    private readonly ToolCallRepository $calls,
    private readonly SpendGuard $spend,
    private readonly ResearchEntitlementService $entitlements,
    private readonly EvidenceLedger $ledger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Herramientas para el turno que va a generarse.
   *
   * Devuelve una caja vacía cuando no hay nada que ofrecer, y eso importa: con
   * la caja vacía no se declara ninguna herramienta al proveedor, ni siquiera
   * una lista vacía. Declararlas cambia el prefijo del prompt, y con él se
   * perdería la caché de todos los turnos que no las necesitan.
   */

  /**
   * Si el turno de esta persona puede salir a investigar.
   *
   * Se pregunta ANTES de generar el turno, para decidir si hay que ejecutarlo
   * en segundo plano: una misión que investiga puede tardar veinte minutos, y
   * eso no cabe en una petición web.
   *
   * No necesita que el turno esté declarado —se le pasa la persona— porque se
   * consulta antes de empezarlo.
   */
  public function mayResearch(int $uid, string $agentId): bool {
    if (!$this->searchIsOn() || !$this->agentMaySearch($agentId)) {
      return FALSE;
    }

    return $this->entitlements
      ->forUser($uid)
      ->access($this->entitlements->maxRechecks())
      ->allowsAnything();
  }

  /**
   * Herramientas para el turno que va a generarse.
   */
  public function forTurn(): ToolRunnerInterface {
    $config = $this->configFactory->get('sales_leadership_diagnostic.settings');

    if (!$this->searchIsOn() || !$this->turn->isSet()) {
      return new ToolBox();
    }

    // El ledger va SIEMPRE, aunque no se pueda investigar. Es lo que hace
    // cierto el «después de completar, los follow-ups siguen funcionando con
    // evidencia persistida» del §2: con la misión cerrada, mirar lo que ya se
    // sabe es lo único que le queda al agente, y quitárselo lo dejaría sin
    // nada que decir.
    $herramientas = [
      new LedgerReadTool($this->ledger, $this->turn),
      new LedgerWriteTool($this->ledger, $this->turn, $this->entitlements),
    ];

    // La búsqueda, en cambio, depende del entitlement. La clasificación ocurre
    // ANTES de exponer la herramienta, como exige el §3: si no hay capacidad,
    // el modelo no llega a ver que exista. No puede pedir lo que no sabe que
    // hay, y eso cierra el bypass por prompt injection —«investiga de
    // nuevo»— sin depender de que el gateway diga que no una y otra vez.
    $entitlement = $this->entitlements->forUser($this->turn->uid());

    if (
      $this->agentMaySearch($this->turn->agentId())
      && $entitlement->access($this->entitlements->maxRechecks())->allowsAnything()
    ) {
      $herramientas[] = new WebSearchTool(
        $this->search,
        $this->loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL),
        max(1, (int) $config->get('search.max_results')),
      );
    }

    $caja = new ToolBox($herramientas);

    // La caja NUNCA se devuelve desnuda. El §6 de la especificación del
    // cliente exige que toda búsqueda externa pase por el gateway: «nunca dar
    // acceso directo no medido». Envolverla aquí, en el único sitio que
    // construye herramientas, es lo que hace que una herramienta nueva nazca
    // controlada sin que nadie tenga que acordarse.
    return new ToolGateway(
      $caja,
      $this->turn,
      $this->calls,
      $this->spend,
      $this->configFactory,
      $this->loggerFactory,
      $this->entitlements,
    );
  }

  /**
   * La primera puerta: si el sitio entero tiene la búsqueda encendida.
   */
  private function searchIsOn(): bool {
    return (bool) $this->configFactory->get('sales_leadership_diagnostic.settings')->get('search.enabled')
      && $this->search->isAvailable();
  }

  /**
   * La segunda puerta: si ESTE agente tiene concedida la búsqueda.
   *
   * Un agente que no se encuentra devuelve NO. Es lo prudente de las dos
   * lecturas posibles: la única forma de llegar aquí sin agente es que la
   * sesión nombre uno borrado, y conceder la capacidad cara a un agente que ya
   * no existe no lo arregla.
   *
   * @param string $agentId
   *   Identificador del agente que conduce el turno.
   */
  private function agentMaySearch(string $agentId): bool {
    if ($agentId === '') {
      return FALSE;
    }

    try {
      $agente = $this->entityTypeManager->getStorage('sld_agent')->load($agentId);
    }
    catch (\Throwable) {
      // Un almacén ilegible no debe conceder búsquedas por descuido.
      return FALSE;
    }

    return $agente instanceof DiagnosticAgentInterface && $agente->canSearch();
  }

}
