<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

/**
 * Motivos por los que el módulo no puede ejecutar diagnósticos todavía.
 *
 * Se modelan como enum y no como cadenas sueltas porque los consumen dos
 * capas con públicos distintos: el informe de estado, que se los muestra al
 * administrador con todo el detalle, y el panel del alumno, que solo necesita
 * saber si puede empezar y jamás debe revelar el motivo técnico (§58).
 */
enum ReadinessBlocker: string {

  /*
   * Falta alguna variable de entorno con un secreto.
   */
  case MissingSecrets = 'missing_secrets';

  /*
   * La integración con WordPress está incompleta.
   */
  case WordPressNotConfigured = 'wordpress_not_configured';

  /*
   * El cliente aún no ha cargado el prompt del agente.
   */
  case AgentNotLoaded = 'agent_not_loaded';

}
