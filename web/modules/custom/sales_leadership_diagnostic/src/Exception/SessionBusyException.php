<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * Ya hay un turno en curso para esta sesión (§24).
 *
 * Es el control real contra envíos simultáneos: el bloqueo del botón en el
 * navegador es comodidad, pero dos pestañas abiertas o una petición repetida
 * llegarían igualmente al servidor. Sin este control, ambas escribirían en la
 * misma conversación y corromperían el orden del contexto, además de pagar dos
 * llamadas al proveedor de IA.
 */
final class SessionBusyException extends DiagnosticException {

}
