<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

/**
 * Autor de un mensaje dentro de una conversación de diagnóstico.
 *
 * Los valores coinciden deliberadamente con los que esperan las APIs de los
 * proveedores de IA, de modo que construir el historial no requiere traducir
 * nombres de rol.
 */
enum MessageRole: string {

  /**
   * Mensaje escrito por el alumno.
   */
  case User = 'user';

  /**
   * Mensaje generado por el agente.
   */
  case Assistant = 'assistant';

}
