<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Authorization\DiagnosticAccessChecker;
use Drupal\sales_leadership_diagnostic\Service\Security\UserProvisioner;

/**
 * Comprobación de acceso de las rutas del alumno (§12).
 *
 * Encadena tres condiciones acumulativas, y fallar cualquiera deniega:
 *
 *  1. Tener el permiso del módulo.
 *  2. Proceder de WordPress, es decir, tener entrada en el authmap. Una cuenta
 *     creada a mano en Drupal no entra por aquí, aunque un administrador le
 *     otorgue el permiso por error.
 *  3. Conservar el derecho al curso, que se consulta a WordPress con cache.
 *
 * La tercera condición es la que hace que una revocación en WordPress se note
 * en Drupal sin intervención de nadie: al expirar la cache, la siguiente
 * petición vuelve a preguntar y deja de conceder acceso.
 */
final class DiagnosticAccessCheck implements AccessInterface {

  public function __construct(
    private readonly UserProvisioner $provisioner,
    private readonly DiagnosticAccessChecker $accessChecker,
  ) {}

  /**
   * Decide si la cuenta puede usar el diagnóstico.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    // El resultado depende del usuario y no debe cachearse entre cuentas.
    // Además se marca sin cachear en el tiempo, porque la autorización puede
    // cambiar en WordPress en cualquier momento; la cache de esa consulta ya
    // vive en su propia capa, con su propio TTL.
    $deny = AccessResult::forbidden()->cachePerUser()->setCacheMaxAge(0);

    if (!$account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS)) {
      return $deny;
    }

    $externalUserId = $this->provisioner->getExternalUserId($account);

    if ($externalUserId === NULL) {
      // Los administradores del módulo pueden ver las páginas para poder
      // configurarlas y depurarlas, aunque no procedan de WordPress.
      if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ADMINISTER)) {
        return AccessResult::allowed()->cachePerPermissions()->setCacheMaxAge(0);
      }

      return $deny;
    }

    if (!$this->accessChecker->isAuthorized($externalUserId)) {
      return $deny;
    }

    return AccessResult::allowed()->cachePerUser()->setCacheMaxAge(0);
  }

  /**
   * Variante para la ruta de un resultado concreto.
   *
   * Existe porque el personal de soporte no es alumno. Con la comprobación
   * general, alguien con «ver los resultados de cualquier alumno» quedaba
   * rechazado por la ruta antes de llegar al control que se lo concedía: el
   * permiso existía pero no servía para nada.
   *
   * Aquí solo se decide si la persona puede entrar por esta puerta. QUÉ
   * resultado concreto puede leer lo sigue decidiendo el handler de acceso de
   * la entidad, que el enrutador aplica a continuación.
   */
  public function accessResult(AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Para el alumno, un resultado es parte del diagnóstico: si perdió el
    // acceso, deja de ver también sus resultados anteriores. Es la política
    // acordada, y se obtiene sin código extra reutilizando la cadena completa.
    return $this->access($account);
  }

}
