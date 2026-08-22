<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Lleva a cada persona a su sitio nada más iniciar sesión.
 *
 * Por defecto Drupal deja a todo el mundo en su página de perfil, que no le
 * sirve a nadie aquí: ni el gestor va a mirar su propio perfil ni el alumno
 * sabe qué hacer con él. Cada rol tiene un único destino útil y es el que se
 * le da.
 *
 * Solo afecta al formulario de inicio de sesión. El alumno normalmente NO pasa
 * por él —entra por SSO desde WordPress, y ese camino tiene su propio destino—
 * así que esto es sobre todo para el personal y para las pruebas.
 *
 * Se respeta un `?destination=` explícito en la URL: quien llega a una página
 * concreta sin sesión iniciada quiere volver a ELLA después de identificarse,
 * no al destino genérico de su rol.
 */
final class LoginRedirectHooks {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for user_login_form.
   */
  #[Hook('form_user_login_form_alter')]
  public function loginFormAlter(array &$form, FormStateInterface $form_state): void {
    // Se añade al final de la lista para ejecutarse DESPUÉS del envío de core,
    // que es quien fija el destino por defecto. Antes, core lo sobrescribiría.
    $form['#submit'][] = [$this, 'redirectAfterLogin'];
  }

  /**
   * Fija el destino según el rol de quien acaba de entrar.
   */
  public function redirectAfterLogin(array &$form, FormStateInterface $form_state): void {
    $request = $this->requestStack->getCurrentRequest();

    // Un destino explícito en la URL gana siempre. Core ya lo respeta por su
    // cuenta, así que aquí basta con no estorbarle.
    if ($request !== NULL && $request->query->has('destination')) {
      return;
    }

    // El proxy ya apunta a la cuenta recién autenticada: core la fija en su
    // propio manejador de envío, que se ejecuta antes que este.
    $route = $this->destinationFor($this->currentUser);

    if ($route === NULL) {
      return;
    }

    $form_state->setRedirectUrl(Url::fromRoute($route));
  }

  /**
   * Ruta de destino para una cuenta, o NULL para dejar el comportamiento de core.
   *
   * El orden importa: se comprueba primero el permiso más amplio. Alguien que
   * administre el módulo Y sea gestor debe acabar donde pueda hacer más cosas.
   */
  private function destinationFor(AccountInterface $account): ?string {
    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_VIEW_ALL_RESULTS)) {
      return 'sales_leadership_diagnostic.admin_results';
    }

    if ($account->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS)) {
      return 'sales_leadership_diagnostic.dashboard';
    }

    // Ni gestor ni alumno: un administrador del sitio, por ejemplo. Se le deja
    // donde Drupal lo habría dejado, porque este módulo no sabe qué venía a
    // hacer.
    return NULL;
  }

}
