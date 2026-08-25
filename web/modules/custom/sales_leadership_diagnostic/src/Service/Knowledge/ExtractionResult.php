<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Knowledge;

/**
 * Resultado de intentar leer un documento de conocimiento.
 *
 * Se devuelve un objeto y no una cadena o NULL porque el motivo del fallo hay
 * que enseñárselo al gestor: «este PDF no tiene texto» es accionable, y un
 * documento que aparece en la lista sin avisar de que llegó vacío no lo es.
 */
final class ExtractionResult {

  private function __construct(
    public readonly bool $correcto,
    public readonly string $texto,
    public readonly string $motivo,
  ) {}

  /**
   * Lectura correcta.
   *
   * @param string $texto
   *   Texto extraído, ya normalizado.
   */
  public static function exito(string $texto): self {
    return new self(TRUE, $texto, '');
  }

  /**
   * Lectura fallida.
   *
   * @param string $motivo
   *   Explicación para quien administra, en su idioma.
   */
  public static function fallo(string $motivo): self {
    return new self(FALSE, '', $motivo);
  }

}
