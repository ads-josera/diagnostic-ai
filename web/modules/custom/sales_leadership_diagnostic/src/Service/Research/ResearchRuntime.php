<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\sales_leadership_diagnostic\DTO\Entitlement;
use Drupal\sales_leadership_diagnostic\TurnClass;

/**
 * Compone el bloque que el backend le inyecta al agente en cada turno.
 *
 * Su §5 lo define campo por campo y con este nombre. No es un invento nuestro
 * ni una traducción: el prompt del cliente razona con estas palabras, y
 * cambiarlas sería modificar su metodología (§15).
 *
 * La regla que más importa de ese §5 es la última:
 *
 * > «No exponer costos internos, secretos, límites monetarios ni lógica
 * > sensible al usuario. El modelo necesita conocer permiso/capacidad
 * > operativa, no la contabilidad.»
 *
 * Por eso aquí no aparece ni un solo número de dinero, ni cuántas búsquedas
 * quedan, ni qué topes hay. Solo si puede investigar y de qué manera. El
 * agente escribe para una persona, y lo que le entre puede acabar en pantalla.
 */
final class ResearchRuntime {

  /**
   * El bloque, listo para anteponerse a la conversación.
   *
   * @param \Drupal\sales_leadership_diagnostic\DTO\Entitlement $entitlement
   *   Lo que puede la persona esta semana.
   * @param \Drupal\sales_leadership_diagnostic\TurnClass $class
   *   De qué clase es este turno.
   * @param int $maxRechecks
   *   Comprobaciones puntuales del periodo.
   * @param bool $ledgerAvailable
   *   Si hay evidencia guardada que reutilizar.
   */
  public function compose(Entitlement $entitlement, TurnClass $class, int $maxRechecks, bool $ledgerAvailable = FALSE): string {
    $lineas = [
      'RESEARCH_RUNTIME',
      'mission_state: ' . $entitlement->state->value,
      'turn_class: ' . $class->value,
      'external_research: ' . $entitlement->access($maxRechecks)->value,
      // El presupuesto se declara en las palabras del cliente y sin cifras: lo
      // que el agente necesita saber es si le queda margen, no cuánto.
      'research_budget: ' . ($entitlement->access($maxRechecks)->allowsAnything() ? 'LIMITED' : 'EXHAUSTED'),
      'entitlement_period: ' . $entitlement->period,
      'evidence_ledger_available: ' . ($ledgerAvailable ? 'true' : 'false'),
      'allowed_actions: ' . implode(', ', $class->allowedActions()),
    ];

    if ($entitlement->missionId !== '') {
      // Va detrás del estado a propósito: sin misión abierta no hay id que
      // enseñar, y un identificador vacío invita a inventarse uno.
      array_splice($lineas, 2, 0, ['mission_id: ' . $entitlement->missionId]);
    }

    return implode("\n", $lineas);
  }

}
