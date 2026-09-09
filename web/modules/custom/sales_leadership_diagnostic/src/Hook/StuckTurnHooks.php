<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Desatasca las conversaciones que se quedaron generando un turno.
 *
 * Desde que los turnos que investigan corren en segundo plano hay un estado
 * nuevo del que se puede no volver: si el proceso muere a mitad —el servidor
 * se reinicia, el proveedor cuelga, alguien mata el cron— la sesión se queda
 * en «procesando» **para siempre**. Y ese estado no admite mensajes, así que
 * la persona no puede escribir, ni reintentar, ni entender por qué.
 *
 * Sin esto, un fallo de un minuto deja una conversación inutilizable de forma
 * permanente. Con esto, se recupera sola.
 *
 * No se reintenta el turno automáticamente. Su §14 pide «no consumir otra
 * misión automáticamente», y reintentar sin que nadie lo pida podría gastar
 * otra ronda de búsquedas por un error que quizá se repita. Se devuelve la
 * conversación a un estado en el que la persona puede volver a escribir, y es
 * ella quien decide.
 */
final class StuckTurnHooks {

  /**
   * Canal de log del módulo.
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function onCron(): void {
    $limite = $this->time->getRequestTime() - $this->stuckAfterSeconds();
    $almacen = $this->entityTypeManager->getStorage('sld_diagnostic_session');

    $ids = $almacen->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', DiagnosticStatus::Processing->value)
      ->condition('changed', $limite, '<')
      ->execute();

    if ($ids === []) {
      return;
    }

    foreach ($almacen->loadMultiple($ids) as $sesion) {
      $sesion->setStatus(DiagnosticStatus::InProgress);
      $sesion->save();

      // Se registra como advertencia y no como información: una conversación
      // que se atasca es un síntoma, y si pasa a menudo hay algo que mirar.
      $this->logger->warning('La sesión @id llevaba demasiado tiempo generando un turno; vuelve a admitir mensajes.', [
        '@id' => $sesion->id(),
      ]);
    }
  }

  /**
   * Cuánto puede tardar un turno antes de darlo por atascado.
   *
   * Generoso a propósito. Una misión que criba diez cuentas puede tardar
   * veinte minutos de trabajo legítimo, y desatascarla a mitad sería peor que
   * el problema: la persona escribiría encima de un turno que sigue vivo.
   */
  private function stuckAfterSeconds(): int {
    $minutos = (int) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('diagnostic.stuck_turn_minutes');

    return max(300, $minutos > 0 ? $minutos * 60 : 2700);
  }

}
