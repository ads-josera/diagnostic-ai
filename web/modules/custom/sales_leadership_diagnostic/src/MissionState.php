<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

/**
 * En qué punto está la misión de investigación de una persona.
 *
 * Los cuatro estados y sus nombres son del cliente, de su §2, y se usan tal
 * cual (§15). No se traducen ni se simplifican: aparecen en el bloque que se
 * le inyecta al agente, y su prompt razona con esas palabras.
 */
enum MissionState: string {

  /*
   * Puede iniciar una misión de investigación.
   */
  case Available = 'AVAILABLE';

  /*
   * Tiene una misión en curso.
   */
  case Active = 'ACTIVE';

  /*
   * Terminó la misión de este periodo.
   */
  case Completed = 'COMPLETED';

  /*
   * No puede iniciar otra hasta que renueve.
   *
   * Se distingue de COMPLETED a propósito, aunque hoy ambos impidan lo mismo:
   * el primero dice «hizo su misión», el segundo «se le retiró la capacidad».
   * Confundirlos haría imposible saber por qué alguien no puede investigar.
   */
  case LockedUntilRenewal = 'LOCKED_UNTIL_RENEWAL';

  /**
   * Si desde este estado se puede abrir una misión nueva.
   */
  public function canStartMission(): bool {
    return $this === self::Available;
  }

  /**
   * Si hay una misión abierta ahora mismo.
   */
  public function isActive(): bool {
    return $this === self::Active;
  }

}
