<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

/**
 * Lo que el motor necesita saber de las herramientas de un turno.
 *
 * Existe para que entre la caja y el motor quepa un guardián sin que el motor
 * se entere. El motor pide dos cosas —qué declarar y qué hacer cuando el
 * modelo pide algo— y quien conteste puede ser la caja o el gateway que la
 * envuelve.
 *
 * Es la costura que exige el §6 de la especificación del cliente: «todas las
 * búsquedas externas pasan por un gateway backend; nunca dar acceso directo no
 * medido». Sin esta interfaz, el control tendría que meterse dentro de cada
 * herramienta, y entonces una herramienta nueva nacería sin él.
 */
interface ToolRunnerInterface {

  /**
   * Si no hay ninguna herramienta que ofrecer.
   */
  public function isEmpty(): bool;

  /**
   * Declaraciones para el proveedor.
   *
   * @return array<int, array<string, mixed>>
   *   Una por herramienta.
   */
  public function declarations(): array;

  /**
   * Ejecuta lo que pidió el modelo y devuelve lo que este verá.
   *
   * Devuelve siempre una cadena y nunca lanza. Una negativa es una respuesta,
   * no un error: el modelo tiene que poder leerla, entenderla y seguir con lo
   * que no dependa de esa herramienta.
   *
   * @param string $name
   *   Nombre que pidió.
   * @param array<string, mixed> $arguments
   *   Argumentos que pidió, ya decodificados.
   */
  public function run(string $name, array $arguments): string;

}
