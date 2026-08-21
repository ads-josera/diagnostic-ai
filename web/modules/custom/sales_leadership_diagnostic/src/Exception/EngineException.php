<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * Fallo al comunicarse con el motor de diagnóstico.
 *
 * Cubre timeouts, errores del proveedor y ausencia de configuración. El
 * alumno nunca ve su mensaje: el EventSubscriber lo traduce a un aviso neutro
 * y el detalle queda en el log (§58).
 */
class EngineException extends DiagnosticException {

}
