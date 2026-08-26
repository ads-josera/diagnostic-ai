<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity\Handler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\Entity\StudentMemoryInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Control de acceso de la memoria del alumno.
 *
 * Sigue el mismo patrón que el resultado y la sesión, y a propósito: el
 * aislamiento entre alumnos de este módulo descansa por completo en que cada
 * entidad con datos del alumno compare propietario aquí. Cualquier camino que
 * se salte Entity API se salta también esta comprobación, y es ahí donde
 * aparecería una fuga.
 *
 * La diferencia con el resultado es el borrado. Un resultado es inmutable
 * porque es el registro de lo que un diagnóstico concluyó. La memoria no
 * registra nada: es una comodidad, la escribe un modelo que puede
 * equivocarse, y condiciona conversaciones futuras. Por eso su dueño puede
 * borrarla, y ese borrado es la única salida cuando un hecho sale mal
 * extraído.
 *
 * Editarla, en cambio, no se permite: si el alumno pudiera reescribir el
 * texto, dejaría de saberse qué salió de la conversación y qué escribió él, y
 * el agente trataría igual lo uno y lo otro.
 */
final class StudentMemoryAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof StudentMemoryInterface) {
      return AccessResult::neutral();
    }

    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ADMINISTER)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => $this->accessView($entity, $account),
      'delete' => $this->accessDelete($entity, $account),
      // La escribe la extracción, nunca una petición del usuario.
      'update' => AccessResult::forbidden()->cachePerPermissions(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * Lectura: el dueño, o quien puede ver los resultados de todos.
   *
   * Se reutiliza el permiso de los resultados en vez de crear uno nuevo: quien
   * puede leer el análisis completo del negocio de un alumno ya puede leer,
   * por definición, el resumen de cuatro líneas del que salió.
   */
  private function accessView(StudentMemoryInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return $this->soloElDueno($entity, $account);
  }

  /**
   * Borrado: solo el dueño.
   *
   * Deliberadamente más estrecho que la lectura. El gestor puede consultar la
   * memoria de un alumno para dar soporte, pero borrarla afecta a las
   * conversaciones que ese alumno tendrá después, y esa es decisión suya.
   */
  private function accessDelete(StudentMemoryInterface $entity, AccountInterface $account): AccessResultInterface {
    return $this->soloElDueno($entity, $account);
  }

  /**
   * Concede solo si la cuenta es la dueña del hecho.
   */
  private function soloElDueno(StudentMemoryInterface $entity, AccountInterface $account): AccessResultInterface {
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
   * La memoria la crea la extracción al terminar un diagnóstico. No hay
   * ninguna ruta por la que un usuario pueda crear una entrada.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, SalesLeadershipDiagnostic::PERMISSION_ADMINISTER);
  }

}
