<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\sales_leadership_diagnostic\Service\Branding\Branding;

/**
 * Inyecta la personalización visual en las páginas del diagnóstico.
 *
 * Solo en ellas. Un <style> global reescribiría variables en páginas que no
 * son del módulo —incluida la administración— y convertiría un ajuste de marca
 * del cliente en un cambio del sitio entero.
 */
final class BrandingHooks {

  /**
   * Rutas que reciben la marca.
   *
   * Se enumeran una a una en lugar de comparar el prefijo de la URL: el
   * prefijo cambia si algún día se traduce o se reubica la ruta, y una lista
   * explícita deja claro qué páginas están afectadas.
   *
   * @var string[]
   */
  private const BRANDED_ROUTES = [
    'sales_leadership_diagnostic.dashboard',
    'sales_leadership_diagnostic.session',
    'sales_leadership_diagnostic.result',
    'sales_leadership_diagnostic.sso_denied',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly Branding $branding,
  ) {}

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    // La marca depende de la ruta, así que la respuesta cacheada de una página
    // no puede reutilizarse en otra. Se declara siempre, incluso cuando no hay
    // nada que inyectar: si solo se declarase al inyectar, una página sin marca
    // podría servirse desde cache a una ruta que sí la lleva.
    $attachments['#cache']['contexts'][] = 'route.name';
    $attachments['#cache']['tags'] = array_merge(
      $attachments['#cache']['tags'] ?? [],
      $this->branding->getCacheTags(),
    );

    if (!in_array($this->routeMatch->getRouteName(), self::BRANDED_ROUTES, TRUE)) {
      return;
    }

    $css = $this->branding->buildCss();

    if ($css === '') {
      return;
    }

    // Se emite como <style> en lugar de como archivo agregado porque el valor
    // procede de configuración y cambia sin desplegar: un archivo obligaría a
    // regenerarlo y a invalidar el agregado en cada guardado.
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => $css,
        '#attributes' => ['data-sld-branding' => 'true'],
        // Después de las hojas del módulo, para que gane sobre los valores
        // por defecto que declara sld-base.css.
        '#weight' => 100,
      ],
      'sales_leadership_diagnostic_branding',
    ];
  }

}
