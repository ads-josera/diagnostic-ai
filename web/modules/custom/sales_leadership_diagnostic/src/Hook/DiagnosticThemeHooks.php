<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;

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

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
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
          'status' => '',
          'status_label' => '',
          'accepts_messages' => FALSE,
          'messages' => [],
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
          'logo_url' => NULL,
          'logo_alt' => '',
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
  }

}
