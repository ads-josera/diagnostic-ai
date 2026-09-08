<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Search\SearchProviderInterface;

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
  ) {}

  /**
   * Herramientas para el turno que va a generarse.
   *
   * Devuelve una caja vacía cuando no hay nada que ofrecer, y eso importa: con
   * la caja vacía no se declara ninguna herramienta al proveedor, ni siquiera
   * una lista vacía. Declararlas cambia el prefijo del prompt, y con él se
   * perdería la caché de todos los turnos que no las necesitan.
   */
  public function forTurn(): ToolBox {
    $config = $this->configFactory->get('sales_leadership_diagnostic.settings');

    if (!(bool) $config->get('search.enabled') || !$this->search->isAvailable()) {
      return new ToolBox();
    }

    return new ToolBox([
      new WebSearchTool(
        $this->search,
        $this->loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL),
        max(1, (int) $config->get('search.max_results')),
      ),
    ]);
  }

}
