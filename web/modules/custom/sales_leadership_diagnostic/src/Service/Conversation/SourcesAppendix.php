<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

/**
 * Añade al entregable las fuentes de cada cuenta, enlazadas.
 *
 * Existe por un dilema medido en producción el 02-10-2026, con cinco misiones
 * del mismo caso:
 *
 * - Pidiéndole al agente, en el contrato de salida, que pusiera el enlace de
 *   cada afirmación en el texto, los enlaces aparecían —10 de 11—, pero el
 *   agente dejó de buscar a los compradores: 0 de 3 misiones hicieron esa
 *   ronda, también después de quitar la frase que parecía disuadirle.
 * - Sin ese requisito, la buscó en 2 de 2 y nombró a cuatro y cinco
 *   compradores, pero solo 5 de 14 fuentes llegaban enlazadas al texto.
 *
 * La salida no era elegir: era dejar de pedirle al modelo un trabajo de la
 * plataforma. Las fuentes ya llegan estructuradas en `accounts[].sources`, con
 * su URL y su etiqueta, y las URL de ahí son las que audita `CitationAudit`.
 * Aquí se convierten en una sección que se lee: el modelo investiga, la
 * plataforma enlaza.
 */
final class SourcesAppendix {

  /**
   * La sección de fuentes en Markdown, o cadena vacía si no hay ninguna.
   *
   * @param array|null $payload
   *   El resultado estructurado del turno final.
   *
   * @return string
   *   Una sección «Fuentes por cuenta», lista para añadir al mensaje.
   */
  public function build(?array $payload): string {
    $bloques = [];

    foreach ($payload['accounts'] ?? [] as $cuenta) {
      if (!is_array($cuenta)) {
        continue;
      }

      $lineas = [];
      $vistas = [];

      foreach ($cuenta['sources'] ?? [] as $fuente) {
        $url = is_array($fuente) ? trim((string) ($fuente['url'] ?? '')) : '';

        // Solo direcciones web. El renderizador ya desarma cualquier otra cosa,
        // pero no tiene sentido escribir una línea que va a quedar sin enlace.
        if (!preg_match('#^https?://#i', $url) || isset($vistas[$url])) {
          continue;
        }

        $vistas[$url] = TRUE;
        $lineas[] = '- ' . $this->enlace($this->etiqueta($fuente), $url) . $this->fecha($fuente);
      }

      if ($lineas === []) {
        continue;
      }

      $nombre = $this->limpio((string) ($cuenta['name'] ?? ''));
      $bloques[] = '**' . ($nombre !== '' ? $nombre : 'Cuenta') . "**\n\n" . implode("\n", $lineas);
    }

    return $bloques === [] ? '' : "### Fuentes por cuenta\n\n" . implode("\n\n", $bloques);
  }

  /**
   * El enlace en Markdown, con la dirección protegida.
   *
   * Un paréntesis o un espacio dentro de la URL cerrarían el enlace antes de
   * tiempo y dejarían media dirección como texto suelto. Se codifican, que es
   * lo que haría el navegador de todas formas.
   */
  private function enlace(string $etiqueta, string $url): string {
    $segura = str_replace(['(', ')', ' '], ['%28', '%29', '%20'], $url);

    return '[' . $etiqueta . '](' . $segura . ')';
  }

  /**
   * Qué dice la fuente, en el texto del enlace.
   */
  private function etiqueta(array $fuente): string {
    $etiqueta = $this->limpio((string) ($fuente['label'] ?? ''));

    return $etiqueta !== '' ? $etiqueta : 'Fuente';
  }

  /**
   * La fecha de publicación, si la fuente la trae.
   */
  private function fecha(array $fuente): string {
    $fecha = $this->limpio((string) ($fuente['published'] ?? ''));

    return $fecha !== '' ? ' · ' . $fecha : '';
  }

  /**
   * Quita lo que rompería el Markdown de la línea.
   *
   * Corchetes y asteriscos en una etiqueta del modelo convertirían el texto en
   * otro enlace o en negritas sueltas; un salto de línea partiría la lista.
   */
  private function limpio(string $texto): string {
    return trim(preg_replace('/\s+/u', ' ', str_replace(['[', ']', '*', '`'], '', $texto)) ?? '');
  }

}
