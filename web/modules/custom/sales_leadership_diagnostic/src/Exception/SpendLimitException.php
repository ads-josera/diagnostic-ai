<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * Se alcanzó un tope de gasto configurado.
 *
 * Es distinto de RateLimitException, y por eso no la reutiliza: aquella pide
 * esperar unos minutos, esta dice que el presupuesto del periodo se agotó. El
 * mensaje que ve el alumno no puede ser el mismo, porque la acción que le
 * corresponde tampoco lo es.
 *
 * No es un error del sistema sino una decisión deliberada de quien administra.
 * Lo ya generado sigue consultándose siempre: lo que se impide es iniciar
 * turnos nuevos.
 */
final class SpendLimitException extends DiagnosticException {

}
