<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\sales_leadership_diagnostic\DTO\Entitlement;
use Drupal\sales_leadership_diagnostic\TurnClass;

/**
 * Decide de qué clase es el turno antes de enseñarle herramientas al modelo.
 *
 * Su §3 lo pide con estas palabras: «la clasificación debe ocurrir **antes** de
 * exponer herramientas de búsqueda al modelo. Un follow-up no puede
 * convertirse silenciosamente en una misión nueva.»
 *
 * De ahí sale la decisión que gobierna esta clase: **la clase se deduce del
 * estado que guarda el backend, no se le pregunta al modelo**. Si dependiera de
 * lo que el modelo declarase, bastaría con que llamara «investigación nueva» a
 * su follow-up para saltarse el entitlement entero, que es exactamente lo que
 * esa regla existe para impedir.
 *
 * No mira el texto del alumno. No hace falta y sería peor: un clasificador por
 * palabras se engaña pidiendo «investiga de nuevo», que es el bypass por
 * prompt injection que nombra su §6.
 */
final class TurnClassifier {

  /**
   * De qué clase es el turno de esta persona ahora mismo.
   *
   * @param \Drupal\sales_leadership_diagnostic\DTO\Entitlement $entitlement
   *   Lo que puede la persona esta semana.
   * @param int $maxRechecks
   *   Comprobaciones puntuales que se conceden tras cerrar la misión.
   */
  public function classify(Entitlement $entitlement, int $maxRechecks): TurnClass {
    // Con la misión abierta, o con derecho a abrirla, el turno puede ser
    // investigación. Abrirla es cosa del gateway al conceder la primera
    // búsqueda; aquí solo se dice de qué clase es el turno.
    if ($entitlement->state->isActive() || $entitlement->state->canStartMission()) {
      return TurnClass::ResearchMission;
    }

    // Cerrada la misión quedan comprobaciones puntuales, y cuando se agotan no
    // queda nada: se trabaja con lo que ya se sabe. Es el «después de
    // completar, los follow-ups siguen funcionando con evidencia persistida»
    // de su §2.
    return $entitlement->rechecksUsed < $maxRechecks
      ? TurnClass::TargetedRecheck
      : TurnClass::MissionFollowUp;
  }

}
