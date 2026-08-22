<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Symfony\Component\Routing\Route;

/**
 * Protege el endpoint de mensajes del estudio del prompt.
 *
 * El estudio reutiliza el controlador de mensajes del alumno, que es lo
 * correcto —ensayar por otro camino probaría otra cosa—, pero eso obliga a
 * cerrar bien esta puerta: sin ella, quien pudiera editar el prompt podría
 * escribir en la conversación de cualquier alumno pasando su identificador.
 *
 * Se exigen tres cosas a la vez, y fallar cualquiera deniega:
 *
 *  1. Tener permiso para editar el prompt.
 *  2. Que la sesión esté marcada como de prueba.
 *  3. Que sea la propia, no la de otro gestor.
 */
final class SandboxSessionCheck implements AccessInterface {

  /**
   * Decide si se puede escribir en esta conversación de ensayo.
   */
  public function access(Route $route, AccountInterface $account, ?DiagnosticSessionInterface $sld_diagnostic_session = NULL): AccessResultInterface {
    $deny = AccessResult::forbidden()->cachePerUser();

    if (!$account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_EDIT_PROMPT)) {
      return $deny;
    }

    if ($sld_diagnostic_session === NULL) {
      return $deny;
    }

    // Una sesión de alumno no se toca desde aquí por mucho permiso que se
    // tenga: el estudio sirve para ensayar, no para intervenir diagnósticos.
    if (!$sld_diagnostic_session->get('is_sandbox')->value) {
      return $deny;
    }

    if ((string) $sld_diagnostic_session->getOwnerId() !== (string) $account->id()) {
      return $deny;
    }

    return AccessResult::allowed()
      ->cachePerUser()
      ->addCacheableDependency($sld_diagnostic_session);
  }

}
