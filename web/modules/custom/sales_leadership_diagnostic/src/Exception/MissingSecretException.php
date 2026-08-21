<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * Se lanza cuando un secreto requerido no está configurado en el entorno.
 *
 * Provocar un fallo explícito es deliberado: sin el secreto no es posible
 * verificar una firma ni autenticar una llamada, y continuar significaría
 * degradar la seguridad en silencio. El módulo falla de forma cerrada (§13).
 */
final class MissingSecretException extends DiagnosticException {

  /**
   * Construye la excepción a partir del nombre del setting ausente.
   *
   * El mensaje nombra la variable de entorno esperada, nunca un valor.
   */
  public static function forSetting(string $settingName): self {
    return new self(sprintf(
      'El secreto "%s" no está configurado. Defina la variable de entorno %s. Consulte .env.example.',
      $settingName,
      strtoupper($settingName),
    ));
  }

}
