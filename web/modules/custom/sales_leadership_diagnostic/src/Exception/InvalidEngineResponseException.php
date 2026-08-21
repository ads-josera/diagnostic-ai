<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * El motor respondió, pero su respuesta no cumple el contrato esperado.
 *
 * Se distingue de EngineException porque admite otro tratamiento: una
 * respuesta malformada suele merecer un reintento, mientras que un error de
 * comunicación puede no merecerlo.
 *
 * Nunca debe incluir la respuesta completa en su mensaje: acabaría en el log
 * y puede contener información empresarial del alumno (§43).
 */
final class InvalidEngineResponseException extends DiagnosticException {

}
