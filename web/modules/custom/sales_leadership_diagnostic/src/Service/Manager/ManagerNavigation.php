<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Manager;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Las secciones del gestor, para su barra.
 *
 * Existe porque la navegación del gestor eran las **pestañas locales** de
 * Drupal, y esas solo se pintan en las rutas que cuelgan de la ruta base del
 * árbol de tareas. Las cinco pantallas de aterrizaje la tenían; todo lo de
 * dentro —editar un agente, el estudio de un agente, sus documentos— se
 * quedaba sin ella.
 *
 * El resultado era un callejón: el gestor entraba a editar un agente y ya no
 * tenía cómo volver a ninguna otra sección. Lo reportó el usuario el
 * 10-09-2026, y es la segunda vez que se queda sin salida: la primera fue el
 * 04-09-2026, sin barra ni cerrar sesión.
 *
 * Declarar cada pantalla interna como pestaña habría llenado la barra de
 * entradas que no son secciones. Lo que hace falta es lo contrario: que la
 * navegación **no dependa de dónde esté**, igual que en cualquier producto.
 * Por eso vive aquí y no en `links.task.yml`.
 */
final class ManagerNavigation {

  use StringTranslationTrait;

  /**
   * Las secciones, en el orden en que se recorren.
   *
   * El orden no es alfabético ni casual: es el del trabajo. Se mira lo que han
   * hecho los alumnos, se ajustan los agentes y su metodología, se ensaya, y
   * el consumo se consulta al final porque es control, no tarea.
   *
   * Cada entrada lista las rutas que «pertenecen» a esa sección, para que al
   * editar un agente siga marcada Agentes en vez de no marcarse ninguna. Una
   * navegación que no dice dónde estás es media navegación.
   *
   * @var array<int, array{ruta: string, dentro: string[]}>
   */
  private const SECCIONES = [
    [
      'ruta' => 'sales_leadership_diagnostic.admin_results',
      'dentro' => ['sales_leadership_diagnostic.result'],
    ],
    [
      'ruta' => 'entity.sld_agent.collection',
      'dentro' => [
        'entity.sld_agent.add_form',
        'entity.sld_agent.edit_form',
        'entity.sld_agent.delete_form',
      ],
    ],
    [
      'ruta' => 'sales_leadership_diagnostic.studio',
      'dentro' => ['sales_leadership_diagnostic.studio_agent'],
    ],
    [
      'ruta' => 'sales_leadership_diagnostic.knowledge',
      'dentro' => ['sales_leadership_diagnostic.knowledge_agent'],
    ],
    [
      'ruta' => 'sales_leadership_diagnostic.usage',
      'dentro' => [],
    ],
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Las secciones que esta persona puede abrir, con cuál está mirando.
   *
   * Se filtra por acceso de verdad y no por rol: una sección que se enseña y
   * da 403 al pulsarla es peor que no enseñarla. El gestor y quien administra
   * no ven exactamente lo mismo.
   *
   * @return array<int, array{titulo: string, url: string, activa: bool}>
   *   Listas para pintar. Vacío si no puede abrir ninguna.
   */
  public function secciones(): array {
    $actual = (string) $this->routeMatch->getRouteName();
    $salida = [];

    foreach (self::SECCIONES as $seccion) {
      $url = Url::fromRoute($seccion['ruta']);

      if (!$url->access()) {
        continue;
      }

      $salida[] = [
        'titulo' => $this->tituloDe($seccion['ruta']),
        'url' => $url->toString(),
        'activa' => $actual === $seccion['ruta'] || in_array($actual, $seccion['dentro'], TRUE),
      ];
    }

    return $salida;
  }

  /**
   * El nombre visible de una sección.
   *
   * Se escriben aquí y no se leen de `links.task.yml` a propósito: los de allí
   * son títulos de pestaña de administración —«Estudio del prompt»— y en una
   * barra de producto lo que se lee mejor es la palabra corta.
   *
   * @param string $ruta
   *   Ruta de la sección.
   */
  private function tituloDe(string $ruta): string {
    return match ($ruta) {
      'sales_leadership_diagnostic.admin_results' => (string) $this->t('Resultados'),
      'entity.sld_agent.collection' => (string) $this->t('Agentes'),
      'sales_leadership_diagnostic.studio' => (string) $this->t('Estudio'),
      'sales_leadership_diagnostic.knowledge' => (string) $this->t('Documentos'),
      'sales_leadership_diagnostic.usage' => (string) $this->t('Consumo'),
      default => $ruta,
    };
  }

}
