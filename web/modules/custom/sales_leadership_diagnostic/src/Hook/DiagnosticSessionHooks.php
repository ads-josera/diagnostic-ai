<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;

/**
 * Reacciones al ciclo de vida de una sesión de diagnóstico.
 */
final class DiagnosticSessionHooks {

  public function __construct(
    private readonly DiagnosticMessageRepository $messages,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_delete() for sld_diagnostic_session.
   *
   * Borra en cascada los mensajes de la sesión.
   *
   * Los mensajes viven en una tabla propia, así que Entity API no puede
   * limpiarlos por su cuenta: sin esto quedarían huérfanos indefinidamente,
   * conservando información empresarial sensible de una sesión que el sistema
   * considera eliminada (§43).
   */
  #[Hook('sld_diagnostic_session_delete')]
  public function onSessionDelete(EntityInterface $entity): void {
    if (!$entity instanceof DiagnosticSessionInterface) {
      return;
    }

    $id = $entity->id();

    if ($id === NULL) {
      return;
    }

    $this->messages->deleteForSession((int) $id);
  }

}
