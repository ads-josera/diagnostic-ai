<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Un resultado de búsqueda externa.
 *
 * Lleva el enlace a propósito y no solo el texto. La metodología del cliente
 * exige que toda afirmación cuente con su procedencia —y prohíbe presentar
 * como hecho lo que no está verificado—, así que un resultado sin fuente no
 * sirve para nada: no se podría citar ni comprobar.
 */
final readonly class SearchResult {

  /**
   * Construye un resultado.
   *
   * @param string $title
   *   Título de la página.
   * @param string $url
   *   Dirección, que es la procedencia de todo lo que salga de aquí.
   * @param string $extract
   *   Fragmento de contenido. Se acota antes de entrar al modelo: el texto
   *   recuperado es la mayor parte de lo que cuesta una búsqueda.
   * @param string $published
   *   Fecha declarada por la fuente, si la trae. Vacía si no. Importa porque
   *   la metodología distingue una señal actual de una de hace tres años.
   */
  public function __construct(
    public string $title,
    public string $url,
    public string $extract,
    public string $published = '',
  ) {}

  /**
   * Forma con la que viaja al modelo.
   *
   * @return array<string, string>
   *   El resultado, listo para serializar.
   */
  public function toPayload(): array {
    $payload = [
      'titulo' => $this->title,
      'url' => $this->url,
      'extracto' => $this->extract,
    ];

    if ($this->published !== '') {
      $payload['fecha'] = $this->published;
    }

    return $payload;
  }

}
