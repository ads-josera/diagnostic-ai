<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

/**
 * De qué tipo es el turno que se está generando.
 *
 * Las tres clases son del cliente (§3). Su regla más importante no es la
 * definición de cada una, sino esta: **«la clasificación debe ocurrir antes de
 * exponer herramientas de búsqueda al modelo. Un follow-up no puede
 * convertirse silenciosamente en una misión nueva.»**
 *
 * Por eso la clase NO se le pregunta al modelo: se deduce del estado que
 * guarda el backend. Si dependiera de lo que el modelo declarara, bastaría con
 * que decidiera que su follow-up es una investigación nueva para saltarse el
 * entitlement, que es justo lo que esa regla prohíbe.
 */
enum TurnClass: string {

  /*
   * Investigación externa nueva y sustancial.
   */
  case ResearchMission = 'RESEARCH_MISSION';

  /*
   * Explicar, comparar, priorizar o redactar con lo que ya se sabe.
   */
  case MissionFollowUp = 'MISSION_FOLLOW_UP';

  /*
   * Resolver un bloqueo puntual: una contradicción, una fecha, una
   * verificación concreta.
   */
  case TargetedRecheck = 'TARGETED_RECHECK';

  /**
   * Lo que el agente puede hacer en un turno de esta clase.
   *
   * Va al bloque de runtime, en las palabras del cliente.
   *
   * @return string[]
   *   Acciones permitidas.
   */
  public function allowedActions(): array {
    return match ($this) {
      self::ResearchMission => ['research_mission', 'follow_up', 'targeted_recheck'],
      self::MissionFollowUp => ['follow_up'],
      self::TargetedRecheck => ['follow_up', 'targeted_recheck'],
    };
  }

}
