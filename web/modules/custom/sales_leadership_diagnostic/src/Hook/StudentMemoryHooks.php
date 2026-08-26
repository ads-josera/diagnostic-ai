<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sales_leadership_diagnostic\Service\Memory\StudentMemoryStore;
use Drupal\user\UserInterface;

/**
 * Reacciones del módulo al ciclo de vida de una cuenta.
 */
final class StudentMemoryHooks {

  public function __construct(
    private readonly StudentMemoryStore $memory,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_delete() for user.
   *
   * Borra la memoria de la cuenta eliminada.
   *
   * Entity API no lo hace sola: `uid` es una referencia como otra cualquiera y
   * nadie limpia las entidades que apuntan a un usuario borrado. Sin esto, lo
   * que el sistema sabía del negocio de esa persona seguiría en la base de
   * datos indefinidamente, y peor aún, un uid reutilizado podría heredarlo.
   */
  #[Hook('user_delete')]
  public function onUserDelete(EntityInterface $entity): void {
    if (!$entity instanceof UserInterface) {
      return;
    }

    $uid = $entity->id();

    if ($uid !== NULL) {
      $this->memory->forgetAll((int) $uid);
    }
  }

}
