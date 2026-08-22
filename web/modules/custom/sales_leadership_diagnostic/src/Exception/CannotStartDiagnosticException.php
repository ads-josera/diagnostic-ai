<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * No se cumple alguna condición para iniciar un diagnóstico.
 *
 * Lleva un motivo legible por máquina además del mensaje. El mensaje va al log
 * y describe lo ocurrido con precisión; el motivo es lo que el panel traduce a
 * una explicación para el alumno.
 *
 * Se separan porque no dicen lo mismo a propósito. «La autorización del alumno
 * ya no está vigente» es útil para quien lee el log; al alumno se le dice que
 * su acceso ha caducado, sin detallar qué comprobación falló.
 */
final class CannotStartDiagnosticException extends DiagnosticException {

  /**
   * Falta configuración del módulo: prompt, credenciales o integración.
   */
  public const REASON_NOT_READY = 'not_ready';

  /**
   * El alumno no tiene —o ha perdido— derecho al diagnóstico.
   */
  public const REASON_NOT_AUTHORIZED = 'not_authorized';

  /**
   * Ya agotó el diagnóstico que le corresponde en este periodo.
   */
  public const REASON_ALREADY_DONE = 'already_done';

  /**
   * Hay otra petición creando una sesión para la misma cuenta.
   */
  public const REASON_IN_FLIGHT = 'in_flight';

  /**
   * Motivo legible por máquina.
   *
   * @var string
   */
  private string $reason;

  /**
   * Construye la excepción.
   *
   * @param string $message
   *   Descripción técnica, destinada al log. Sin secretos ni prompts (§43).
   * @param string $reason
   *   Una de las constantes REASON_*.
   */
  public function __construct(string $message, string $reason) {
    parent::__construct($message);
    $this->reason = $reason;
  }

  /**
   * Motivo por el que no se pudo empezar.
   */
  public function getReason(): string {
    return $this->reason;
  }

}
