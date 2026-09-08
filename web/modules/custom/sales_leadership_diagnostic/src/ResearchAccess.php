<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

/**
 * Cuánta investigación externa se permite ahora mismo.
 *
 * Los tres valores son del cliente (§2) y viajan tal cual al agente en el
 * bloque de runtime, porque su prompt razona con ellos.
 *
 * El valor por defecto de su metodología es el más restrictivo —«por defecto
 * NO web»—, y aquí se respeta: quien no tenga misión abierta no investiga.
 */
enum ResearchAccess: string {

  /*
   * Puede investigar con normalidad, dentro de los topes.
   */
  case Allowed = 'ALLOWED';

  /*
   * Solo comprobaciones puntuales, atadas a un bloqueo concreto.
   */
  case TargetedOnly = 'TARGETED_ONLY';

  /*
   * No puede investigar fuera.
   */
  case NotAvailable = 'NOT_AVAILABLE';

  /**
   * Si permite alguna llamada a herramienta de búsqueda.
   */
  public function allowsAnything(): bool {
    return $this !== self::NotAvailable;
  }

}
