<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

/**
 * Una herramienta que el modelo puede pedir.
 *
 * Separa dos cosas que conviene no mezclar: cómo se le DECLARA al modelo, y
 * qué pasa cuando la pide. La declaración es un contrato público que el modelo
 * lee; la ejecución puede negarse, acotarse o registrarse sin que la
 * declaración cambie.
 *
 * Esa separación es la costura por donde entrará el Tool Gateway: el control
 * no se mete dentro de cada herramienta, se pone delante de `run()`.
 */
interface ToolInterface {

  /**
   * Nombre con el que el modelo la pide.
   */
  public function name(): string;

  /**
   * Cómo se le declara al modelo.
   *
   * @return array<string, mixed>
   *   La declaración, en el formato del proveedor.
   */
  public function declaration(): array;

  /**
   * Ejecuta la herramienta y devuelve lo que ve el modelo.
   *
   * Devuelve SIEMPRE una cadena, y nunca lanza: un fallo aquí no debe cortar
   * el turno. El modelo tiene que enterarse de que esto no salió para poder
   * declarar su cobertura real y seguir con lo que no dependa de ello, que es
   * lo que manda la metodología del cliente.
   *
   * @param array<string, mixed> $arguments
   *   Lo que pidió el modelo, ya decodificado.
   */
  public function run(array $arguments): string;

}
