<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Search\SearchProviderInterface;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;

/**
 * Decide qué herramientas hay en un turno.
 *
 * Hoy la decisión es simple —un interruptor de configuración y si el buscador
 * está configurado— y a propósito: **quién** puede buscar y **cuándo** es lo
 * que gobierna el Research Entitlement del cliente, y eso llega después. Esta
 * clase es el sitio donde entrará esa decisión sin tocar el motor.
 *
 * El interruptor nace APAGADO. Encenderlo cambia lo que el agente puede hacer
 * y lo que cuesta cada turno, y eso no debe pasar por instalar una versión.
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
  ) {}

  /**
   * Herramientas para el turno que va a generarse.
   *
   * Devuelve una caja vacía cuando no hay nada que ofrecer, y eso importa: con
   * la caja vacía no se declara ninguna herramienta al proveedor, ni siquiera
   * una lista vacía. Declararlas cambia el prefijo del prompt, y con él se
   * perdería la caché de todos los turnos que no las necesitan.
   */
  public function forTurn(): ToolRunnerInterface {
    $config = $this->configFactory->get('sales_leadership_diagnostic.settings');

    if (!(bool) $config->get('search.enabled') || !$this->search->isAvailable() || !$this->turn->isSet()) {
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

    if ($entitlement->access($this->entitlements->maxRechecks())->allowsAnything()) {
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

}
