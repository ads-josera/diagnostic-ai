<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Agent;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;

/**
 * La pantalla de «¿sobre qué agente trabajas?».
 *
 * Dos pantallas de administración la necesitan —el Estudio del prompt y los
 * Documentos de conocimiento— y hasta el 02-09-2026 cada una la construía por
 * su cuenta. Pasó lo que pasa siempre con el marcado duplicado: divergieron.
 * El Estudio pintaba botones y Documentos una lista de viñetas, de modo que la
 * misma pregunta se veía de dos formas distintas según por qué pestaña se
 * hubiera entrado.
 *
 * Vive aquí para que solo haya un sitio donde arreglarla.
 *
 * **Solo aparece con VARIOS agentes.** Con uno se entra directo al suyo:
 * obligar a elegir entre una sola opción es empeorar la pantalla. Quien llama
 * decide eso; esto solo pinta.
 */
final class AgentChooser {

  use StringTranslationTrait;

  /**
   * Construye la pantalla de elección.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface[] $disponibles
   *   Agentes entre los que elegir. Vacío produce el aviso de que no hay
   *   ninguno, que es distinto de que haya que elegir.
   * @param string $ruta
   *   Ruta a la que lleva cada opción. Recibe el agente en `sld_agent`.
   * @param string $pregunta
   *   Lo que se le pregunta a quien mira. Cada pantalla tiene la suya, porque
   *   ajustar un prompt y cargar documentos no son lo mismo.
   * @param string $vacio
   *   Qué decir cuando todavía no hay ningún agente.
   *
   * @return array<string, mixed>
   *   Elemento de renderizado.
   */
  public function build(array $disponibles, string $ruta, string $pregunta, string $vacio): array {
    if ($disponibles === []) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['sld__notice', 'sld__notice--warning']],
        'texto' => ['#markup' => $vacio],
      ];
    }

    $opciones = [
      // Los enlaces van dentro de su propio contenedor, con separación. Sin él
      // salían pegados uno detrás de otro —«Sales Leadership Diagnostic AIGAP
      // Prospecting AI»— porque son elementos en línea y la clase de botón no
      // los distancia por sí sola.
      '#type' => 'container',
      '#attributes' => ['class' => ['sld-chooser__options']],
    ];

    foreach ($disponibles as $agent) {
      if (!$agent instanceof DiagnosticAgentInterface) {
        continue;
      }

      $opciones[$agent->id()] = [
        '#type' => 'link',
        '#title' => $agent->label(),
        '#url' => Url::fromRoute($ruta, ['sld_agent' => $agent->id()]),
        '#attributes' => ['class' => ['sld__button', 'sld__button--secondary']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['sld', 'sld-chooser']],
      'pregunta' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $pregunta,
      ],
      'opciones' => $opciones,
      // La hoja del módulo viaja CON el selector, y no la adjunta quien llama.
      // Ese fue justo el fallo: la rama de elección del Estudio salía antes de
      // adjuntarla y la pantalla se servía sin ningún estilo.
      '#attached' => ['library' => ['sales_leadership_diagnostic/studio']],
    ];
  }

}
