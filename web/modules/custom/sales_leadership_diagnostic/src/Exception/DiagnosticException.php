<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * Excepción base de todas las que lanza el módulo.
 *
 * Capturar este tipo permite tratar de forma uniforme cualquier fallo del
 * diagnóstico sin acoplarse a la causa concreta. El EventSubscriber del módulo
 * la intercepta para mostrar al alumno un mensaje neutro y dejar el detalle
 * técnico únicamente en el log (§58).
 *
 * Regla para todas las subclases: el mensaje de la excepción puede terminar en
 * un log, así que NUNCA debe contener secretos, tokens, prompts completos ni
 * respuestas completas del modelo (§43).
 */
class DiagnosticException extends \RuntimeException {

}
