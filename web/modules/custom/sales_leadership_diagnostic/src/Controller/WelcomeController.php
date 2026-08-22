<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Branding\Branding;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Portada del sitio.
 *
 * Este Drupal no es un sitio que se navegue: existe para alojar el
 * diagnóstico, y al alumno se le entra por SSO desde WordPress. Sin embargo
 * alguien acaba siempre en la raíz —un enlace mal copiado, el historial del
 * navegador, un marcador— y encontrarse la página de bienvenida por defecto de
 * Drupal, vacía, no le dice qué hacer.
 *
 * Esta página resuelve eso con una frase y un enlace de vuelta. Deliberadamente
 * NO ofrece un formulario de inicio de sesión: el alumno no tiene contraseña de
 * Drupal —su cuenta se provisiona desde WordPress y nunca se le asigna una— así
 * que invitarle a iniciar sesión aquí solo le haría fracasar. Quien sí tiene
 * contraseña, el personal, conoce /user/login.
 */
final class WelcomeController extends ControllerBase {

  public function __construct(
    private readonly Branding $branding,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(Branding::class),
    );
  }

  /**
   * Construye la portada.
   */
  public function view(): array {
    $config = $this->config('sales_leadership_diagnostic.settings');
    $origin = trim((string) $config->get('wordpress.api_base_url'));

    return [
      '#theme' => 'sld_welcome',
      '#logo_url' => $this->branding->getLogoUrl(),
      '#logo_alt' => $this->branding->getLogoAlt(),
      // Solo se ofrece el enlace de vuelta si hay un origen configurado y es
      // https. Un enlace a http:// desde una página pública degradaría el
      // canal, y uno vacío llevaría a la propia portada en bucle.
      '#origin_url' => str_starts_with($origin, 'https://') ? $origin : NULL,
      // A quien ya es alumno se le ofrece el atajo a su panel en lugar de
      // mandarlo de vuelta a WordPress para que vuelva a entrar.
      '#has_access' => $this->currentUser()->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/welcome'],
      ],
      '#cache' => [
        // Cambia según los permisos de quien mira, y cuando se toca la marca o
        // la URL de WordPress.
        'contexts' => ['user.permissions'],
        'tags' => array_merge($config->getCacheTags(), $this->branding->getCacheTags()),
      ],
    ];
  }

}
