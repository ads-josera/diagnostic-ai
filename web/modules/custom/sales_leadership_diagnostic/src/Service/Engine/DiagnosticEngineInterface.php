<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;

/**
 * Contrato del motor que genera cada turno del diagnóstico (§28).
 *
 * Es la única frontera entre el módulo y un proveedor de IA. Ninguna otra
 * clase debe saber qué proveedor se usa, de modo que añadir uno nuevo sea una
 * clase más y una línea de services.yml, sin tocar la lógica de conversación.
 *
 * Devuelve un turno ya validado: si el proveedor responde algo que no cumple
 * el contrato, la implementación lanza en lugar de propagar basura.
 */
interface DiagnosticEngineInterface {

  /**
   * Genera el siguiente turno de la conversación.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   *   Si falla la comunicación con el proveedor o falta configuración.
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   *   Si el proveedor responde algo que no cumple el contrato esperado.
   */
  public function process(DiagnosticContext $context): DiagnosticTurn;

}
