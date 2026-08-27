<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\ConversationPurger;

/**
 * Mantenimiento periódico de los datos del módulo.
 */
final class RetentionHooks {

  public function __construct(
    private readonly ConversationPurger $purger,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Retira las conversaciones que superaron el plazo de conservación.
   *
   * Va en cron y no en ninguna petición del alumno: borrar es trabajo de
   * mantenimiento, y hacerlo mientras alguien espera una respuesta le añadiría
   * latencia por algo que no le incumbe.
   *
   * De fábrica no hace nada: el plazo viene en cero, que significa conservar
   * indefinidamente. Activarlo es una decisión de alguien, no un efecto de
   * actualizar el módulo.
   */
  #[Hook('cron')]
  public function onCron(): void {
    $this->purger->purge();
  }

}
