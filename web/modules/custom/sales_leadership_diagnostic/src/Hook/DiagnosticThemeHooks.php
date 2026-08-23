<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\sales_leadership_diagnostic\Service\Branding\HomePage;

/**
 * Plantillas que aporta el módulo.
 *
 * Viven en el módulo y no en el tema del sitio: §4 exige que la lógica y la
 * presentación del diagnóstico sean autocontenidas, de modo que cambiar el
 * tema del sitio no rompa la experiencia ni obligue a tocar el módulo.
 */
final class DiagnosticThemeHooks {

  /**
   * Ruta que usa la plantilla de página dedicada.
   */
  private const CHAT_ROUTE = 'sales_leadership_diagnostic.session';

  /**
   * Ruta de la portada, que usa su propio marco de pagina.
   */
  private const HOME_ROUTE = 'sales_leadership_diagnostic.welcome';

  /**
   * Rutas de inicio de sesion que comparten el marco de la portada.
   *
   * Se incluye tambien la peticion de contrasena: son la misma puerta y
   * dejarla con el marco generico de Drupal habria delatado justo lo que este
   * trabajo pretende ocultar.
   *
   * @var string[]
   */
  private const LOGIN_ROUTES = [
    'user.login',
    'user.pass',
    'user.reset.form',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly HomePage $home,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'sld_dashboard' => [
        'variables' => [
          'user_name' => '',
          'logo_url' => NULL,
          'logo_alt' => '',
          'welcome_text' => NULL,
          'can_start' => FALSE,
          'start_url' => '',
          'resume_session_id' => NULL,
          'repeat_notice' => NULL,
          'unavailable_notice' => NULL,
          'expiry_notice' => NULL,
          'history' => [],
        ],
      ],
      'sld_chat' => [
        'variables' => [
          'session_id' => 0,
          'welcome_icon' => NULL,
          'welcome_intro' => NULL,
          'welcome_suggestions' => [],
          'status' => '',
          'status_label' => '',
          'accepts_messages' => FALSE,
          'messages' => [],
        ],
      ],
      'sld_studio' => [
        'variables' => [
          'form' => NULL,
          'session_id' => 0,
          'messages' => [],
          'reset_url' => '',
        ],
      ],
      'sld_result' => [
        'variables' => [
          'summary' => NULL,
          'score' => NULL,
          'sections' => [],
          'version' => '',
          'created' => '',
        ],
      ],
      'sld_welcome' => [
        'variables' => [
          'background' => NULL,
          'header_logo' => NULL,
          'footer_logo' => NULL,
          'title' => NULL,
          'intro' => NULL,
          'button_label' => '',
          'help_text' => NULL,
          'accent_color' => '',
          'band_color' => '',
          'origin_url' => NULL,
          'has_access' => FALSE,
        ],
      ],
      'sld_sso_denied' => [
        'variables' => [
          'message' => '',
        ],
      ],
      // Variante de `page` para la vista de conversación. `base hook` hace que
      // se le apliquen los mismos preprocesos que a cualquier página.
      'page__sales_diagnostic_chat' => [
        'template' => 'page--sales-diagnostic-chat',
        'base hook' => 'page',
      ],
      // La portada tambien prescinde del marco del tema: es lo primero que ve
      // quien llega por un enlace suelto, y con la cabecera generica de Drupal
      // parecia una instalacion a medio terminar.
      'page__sales_diagnostic_home' => [
        'template' => 'page--sales-diagnostic-home',
        'base hook' => 'page',
      ],
      // El inicio de sesion reutiliza el marco de la portada. Drupal ya busca
      // por si mismo la sugerencia page--user-login, asi que basta con
      // declarar la plantilla para que la encuentre.
      'page__user__login' => [
        'template' => 'page--user-login',
        'base hook' => 'page',
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_page_alter().
   *
   * Solo la conversación usa el marco reducido. El panel es una página normal
   * del sitio y conserva el marco del tema.
   */
  #[Hook('theme_suggestions_page_alter')]
  public function themeSuggestionsPageAlter(array &$suggestions): void {
    if ($this->routeMatch->getRouteName() === self::CHAT_ROUTE) {
      $suggestions[] = 'page__sales_diagnostic_chat';
    }

    if ($this->routeMatch->getRouteName() === self::HOME_ROUTE) {
      $suggestions[] = 'page__sales_diagnostic_home';
    }

    if (in_array($this->routeMatch->getRouteName(), self::LOGIN_ROUTES, TRUE)) {
      $suggestions[] = 'page__user__login';
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for page.
   *
   * Lleva a la plantilla del inicio de sesion las imagenes y los colores que
   * ya administra la portada. Son la misma pagina en dos momentos distintos:
   * duplicar los ajustes habria obligado a subir las imagenes dos veces y a
   * acordarse de cambiarlas en dos sitios.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    if (!in_array($this->routeMatch->getRouteName(), self::LOGIN_ROUTES, TRUE)) {
      return;
    }

    $variables['sld_background'] = $this->home->getBackgroundUrl();
    $variables['sld_header_logo'] = $this->home->getHeaderLogoUrl();
    $variables['sld_footer_logo'] = $this->home->getFooterLogoUrl();
    $variables['sld_accent_color'] = $this->home->getAccentColor();
    $variables['sld_band_color'] = $this->home->getBandColor();

    // La hoja de estilos de la portada no se carga sola en una ruta que no es
    // del modulo, asi que se adjunta aqui.
    $variables['#attached']['library'][] = 'sales_leadership_diagnostic/welcome';

    // Sin estas etiquetas, cambiar el fondo o el logotipo no se veria en esta
    // pagina hasta que caducara por otro motivo.
    $variables['#cache']['tags'] = array_merge(
      $variables['#cache']['tags'] ?? [],
      $this->home->getCacheTags(),
    );
  }

}
