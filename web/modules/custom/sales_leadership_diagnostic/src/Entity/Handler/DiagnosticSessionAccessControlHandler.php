<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity\Handler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Control de acceso de las sesiones de diagnóstico.
 *
 * Es la última línea de defensa contra IDOR y la más importante: se aplica
 * cuando el enrutador resuelve el parámetro de ruta a una entidad, de modo que
 * un identificador ajeno se rechaza antes de llegar al controller.
 *
 * Regla básica: tener el permiso de acceso al diagnóstico no da derecho a ver
 * el diagnóstico de otra persona. La propiedad se comprueba siempre.
 */
final class DiagnosticSessionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof DiagnosticSessionInterface) {
      return AccessResult::neutral();
    }

    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ADMINISTER)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => $this->accessView($entity, $account),
      // Actualizar una sesión es lo que ocurre al avanzar la conversación:
      // solo su dueño puede hacerlo, y solo si conserva el permiso de acceso.
      'update' => $this->accessOwnerOnly($entity, $account),
      // Borrar destruye el historial del alumno y su trazabilidad. Queda
      // reservado a administración, que ya se resolvió más arriba.
      'delete' => AccessResult::forbidden()->cachePerPermissions(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * Lectura: el dueño, o el personal de soporte autorizado.
   */
  private function accessView(DiagnosticSessionInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return $this->accessOwnerOnly($entity, $account);
  }

  /**
   * Concede acceso solo al dueño con permiso de acceso al diagnóstico.
   *
   * Se exigen las dos condiciones: si a un alumno se le retira el permiso, sus
   * sesiones dejan de ser accesibles aunque siga siendo su propietario.
   */
  private function accessOwnerOnly(DiagnosticSessionInterface $entity, AccountInterface $account): AccessResultInterface {
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
   * Crear una sesión requiere el permiso de acceso. Que además el alumno tenga
   * derecho al curso lo comprueba la capa de autorización antes de llegar aquí.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ADMINISTER)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIfHasPermission($account, SalesLeadershipDiagnostic::PERMISSION_ACCESS);
  }

}
