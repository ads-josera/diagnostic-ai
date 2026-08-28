<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Saca al alumno de las páginas de cuenta de Drupal.
 *
 * Un alumno que llega a `/user/25` se encuentra el tema del sitio —cabecera
 * azul, «Funciona con Drupal»— en medio de una experiencia que se diseñó
 * entera, sin nada que le devuelva al diagnóstico. Pero el problema no es solo
 * que se vea distinto:
 *
 *  - **La página no le sirve para nada.** Lo único que dice es «Miembro desde
 *    hace 5 días».
 *  - **Le ofrece editar su cuenta**, y su identidad la manda WordPress. Si
 *    cambia el correo, deja al provisionador en un estado que hay que resolver
 *    a mano (§7.3); si se pone contraseña, abre una entrada directa por
 *    `/user/login` que el diseño no contempla.
 *
 * Por eso se le lleva a su panel en lugar de maquillar esa pantalla: dejarla
 * bonita habría conservado los dos problemas de fondo y el botón de editar.
 *
 * **Solo afecta a los alumnos.** El gestor y los administradores sí necesitan
 * esas páginas, y para ellos no cambia nada. Tampoco se tocan el inicio de
 * sesión, el cierre ni el restablecimiento de contraseña: son otras rutas y
 * cumplen su función.
 */
final class StudentProfileRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Rutas de las que se saca al alumno.
   *
   * Solo su perfil y su edición. Se enumeran en lugar de filtrar por prefijo
   * para no arrastrar por descuido rutas que sí hacen falta, como el cierre de
   * sesión o el restablecimiento de contraseña.
   *
   * @var string[]
   */
  private const RUTAS = [
    'entity.user.canonical',
    'entity.user.edit_form',
  ];

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Prioridad 30, justo POR DEBAJO del enrutador, que corre a 32. Por encima
   * de él la ruta todavía no está resuelta y `_route` viene vacío: el
   * suscriptor no se enteraría de nada. Costó descubrirlo porque no falla,
   * simplemente no hace nada.
   *
   * Y por debajo de 32 se sigue llegando antes de que se construya la página,
   * que era el otro objetivo.
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => [['onRequest', 30]]];
  }

  /**
   * Lleva al alumno a su panel si aterrizó en su cuenta.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $ruta = (string) $event->getRequest()->attributes->get('_route');

    if (!in_array($ruta, self::RUTAS, TRUE) || !$this->esSoloAlumno()) {
      return;
    }

    $event->setResponse(new RedirectResponse(
      Url::fromRoute('sales_leadership_diagnostic.dashboard')->toString(),
    ));
  }

  /**
   * Si quien mira es alumno y nada más.
   *
   * El orden de las comprobaciones importa. Alguien puede ser alumno Y gestor
   * —el propio cliente probando el producto—, y a esa persona no se le puede
   * quitar el acceso a su cuenta: preguntar solo por el permiso de alumno la
   * habría dejado fuera.
   */
  private function esSoloAlumno(): bool {
    return $this->currentUser->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS)
      && !$this->currentUser->hasPermission(SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS)
      && !$this->currentUser->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ADMINISTER);
  }

}
