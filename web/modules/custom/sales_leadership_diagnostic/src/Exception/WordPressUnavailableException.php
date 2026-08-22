<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * No se ha podido determinar el derecho de acceso.
 *
 * Cubre timeouts, errores de red, respuestas malformadas, credenciales que no
 * coinciden y la indisponibilidad del LMS.
 *
 * Es distinto de «el alumno no tiene acceso», y la diferencia importa: ante
 * una denegación real no se concede acceso jamás, mientras que ante esta
 * excepción el módulo puede apoyarse en una autorización previamente validada
 * que siga vigente en cache (§13). Confundir ambos casos convertiría cualquier
 * caída de WordPress en una denegación masiva, o peor, en un acceso indebido.
 */
final class WordPressUnavailableException extends DiagnosticException {

}
