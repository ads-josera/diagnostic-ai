<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity\Handler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Control de acceso de los resultados de diagnóstico.
 *
 * Un resultado es el registro más sensible del módulo: condensa el análisis del
 * negocio del alumno. Por eso es de solo lectura una vez creado, incluso para
 * su dueño: modificarlo destruiría la correspondencia entre el resultado y la
 * conversación que lo produjo.
 */
final class DiagnosticResultAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof DiagnosticResultInterface) {
      return AccessResult::neutral();
    }

    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ADMINISTER)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => $this->accessView($entity, $account),
      // Inmutable por diseño: un resultado editable deja de ser un registro
      // fiable de lo que el diagnóstico concluyó.
      'update', 'delete' => AccessResult::forbidden()->cachePerPermissions(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * Lectura: el dueño, o el personal de soporte autorizado.
   */
  private function accessView(DiagnosticResultInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIf(
      $account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS)
      && $account->id() !== NULL
      && (string) $account->id() === (string) $entity->getOwnerId()
    )
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   *
   * Los resultados los crea el motor de diagnóstico, nunca una petición del
   * usuario: no existe ninguna ruta que permita crear uno.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, SalesLeadershipDiagnostic::PERMISSION_ADMINISTER);
  }

}
