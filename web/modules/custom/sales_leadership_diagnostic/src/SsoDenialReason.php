<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

/**
 * Motivos por los que puede rechazarse una entrada desde WordPress.
 *
 * Se usan como valor en la URL de la página de rechazo, así que forman parte
 * de la superficie pública: son una lista cerrada y deliberadamente vaga.
 * Distinguir «firma inválida» de «token caducado» de cara al visitante solo
 * ayudaría a quien esté probando tokens; el detalle real queda en el log.
 */
enum SsoDenialReason: string {

  /*
   * El token no era válido por cualquier motivo: firma, vigencia, emisor,
   * audiencia, formato o reutilización.
   */
  case InvalidToken = 'token';

  /*
   * El token era correcto, pero el alumno no tiene el curso.
   */
  case NoCourse = 'curso';

  /*
   * No se pudo preparar la cuenta: colisión de correo o cuenta bloqueada.
   */
  case AccountUnavailable = 'cuenta';

  /*
   * Demasiados intentos desde la misma dirección.
   */
  case TooManyAttempts = 'intentos';

  /**
   * Convierte un valor de la URL en un motivo, con respaldo seguro.
   *
   * Nunca confía en el parámetro recibido: cualquier valor desconocido cae en
   * el motivo genérico en lugar de propagarse a la plantilla.
   */
  public static function fromRequestValue(?string $value): self {
    return self::tryFrom((string) $value) ?? self::InvalidToken;
  }

}
