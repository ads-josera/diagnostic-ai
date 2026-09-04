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
    // La página de cada agente. Se olvidó al crearla el 31-08-2026 y el fallo
    // no se vio hasta medir el color efectivo: la página cargaba perfecta, con
    // el azul de fábrica del módulo en vez del de la marca, entre dos páginas
    // que sí lo llevaban. Mirarla no bastaba; los dos azules se parecen.
    'sales_leadership_diagnostic.agent_page',
    'sales_leadership_diagnostic.session',
    'sales_leadership_diagnostic.result',
    'sales_leadership_diagnostic.sso_denied',
    // La portada también: su barra y su botón usan el color principal de la
    // marca, así que sin esta línea se quedaban con el azul de fábrica y la
    // paleta del cliente no llegaba a la primera página que alguien ve.
    'sales_leadership_diagnostic.welcome',
    // El inicio de sesión comparte el marco de la portada, así que necesita la
    // misma paleta. Sin estas líneas su botón se quedaba con el azul de
    // fábrica mientras la barra de arriba llevaba el del cliente.
    'user.login',
    'user.pass',
    'user.reset.form',
    // Las pantallas del gestor, desde el 04-09-2026. Llevan la misma barra que
    // el alumno y su botón de salir usa el color de la marca: sin estas
    // líneas se quedaba con el azul de fábrica del módulo al lado de una barra
    // que sí llevaba el del cliente. Es la segunda vez que se olvida esta
    // lista al añadir pantallas — no se ve mirando, porque los dos azules se
    // parecen.
    'sales_leadership_diagnostic.admin_results',
    'sales_leadership_diagnostic.studio',
    'sales_leadership_diagnostic.studio_agent',
    'sales_leadership_diagnostic.knowledge',
    'sales_leadership_diagnostic.knowledge_agent',
    'entity.sld_agent.collection',
    'entity.sld_agent.add_form',
    'entity.sld_agent.edit_form',
    'entity.sld_agent.delete_form',
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
