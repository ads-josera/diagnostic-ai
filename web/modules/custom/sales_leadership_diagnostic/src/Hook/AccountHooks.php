<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Account\AccountRegistry;
use Drupal\user\UserInterface;

/**
 * Alimenta el registro de cuentas y lo borra con la cuenta del alumno.
 *
 * Se engancha al guardado del resultado y no al servicio de conversación a
 * propósito: así cualquier camino que cree un Pack lo registra, y nadie tiene
 * que acordarse de llamar a nada.
 */
final class AccountHooks {

  /**
   * Canal de log del módulo.
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly AccountRegistry $registry,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for sld_diagnostic_result.
   *
   * Registra las cuentas del Pack recién guardado.
   *
   * Un fallo aquí NO puede impedir que el alumno reciba su Pack. El Pack es lo
   * que vino a buscar; el registro es lo que le ahorra trabajo la semana que
   * viene. Si el segundo falla, se anota y el primero se entrega igual.
   */
  #[Hook('sld_diagnostic_result_insert')]
  public function onResultInsert(EntityInterface $entity): void {
    if (!$entity instanceof DiagnosticResultInterface) {
      return;
    }

    // Los ensayos del gestor no son cuentas de nadie: registrarlas llenaría el
    // historial de quien ensaya con empresas de una prueba.
    if ((bool) $entity->get('is_sandbox')->value) {
      return;
    }

    $cuentas = $entity->getAccounts();

    if ($cuentas === []) {
      return;
    }

    try {
      $this->registry->ingestPack(
        (int) $entity->getOwnerId(),
        $entity->getAgentId(),
        (int) $entity->id(),
        $cuentas,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('No se pudieron registrar las cuentas del resultado @id: @motivo. El Pack se entregó igual.', [
        '@id' => $entity->id(),
        '@motivo' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for user.
   *
   * Se lleva las cuentas y su historial. Va aparte del borrado del resto de
   * datos del alumno para no tocar ese código, que ya está probado.
   */
  #[Hook('user_delete')]
  public function onUserDelete(EntityInterface $entity): void {
    if (!$entity instanceof UserInterface || $entity->id() === NULL) {
      return;
    }

    $borradas = $this->registry->forgetAll((int) $entity->id());

    if ($borradas > 0) {
      // Cifras, nunca contenido (§43).
      $this->logger->info('Cuenta @uid eliminada: se retiraron @n cuenta(s) de prospección y su historial.', [
        '@uid' => $entity->id(),
        '@n' => $borradas,
      ]);
    }
  }

}
