<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * El alumno ha superado un límite de uso configurado (§44).
 *
 * No es un error del sistema sino una decisión deliberada, así que se traduce
 * a HTTP 429 y a un mensaje que explica que debe esperar.
 */
final class RateLimitException extends DiagnosticException {

}
