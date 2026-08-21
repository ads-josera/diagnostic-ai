<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Estados por los que pasa una sesión de diagnóstico.
 *
 * El estado lo determina el motor de diagnóstico, nunca el navegador: si el
 * cliente pudiera declarar una sesión completada, un alumno podría cerrar su
 * diagnóstico a medias y generar un resultado vacío.
 */
enum DiagnosticStatus: string {

  /**
   * Creada pero sin el primer mensaje del agente.
   */
  case Draft = 'draft';

  /**
   * Conversación en curso.
   */
  case InProgress = 'in_progress';

  /**
   * Generando el resultado final.
   */
  case Processing = 'processing';

  /**
   * Diagnóstico terminado, con resultado disponible.
   */
  case Completed = 'completed';

  /**
   * Interrumpida por un error irrecuperable o por superar el tope de turnos.
   */
  case Failed = 'failed';

  /**
   * Valores permitidos del campo, en el formato que espera list_string.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function allowedValues(): array {
    return [
      self::Draft->value => new TranslatableMarkup('Borrador'),
      self::InProgress->value => new TranslatableMarkup('En curso'),
      self::Processing->value => new TranslatableMarkup('Procesando'),
      self::Completed->value => new TranslatableMarkup('Completado'),
      self::Failed->value => new TranslatableMarkup('Fallido'),
    ];
  }

  /**
   * Indica si la sesión admite nuevos mensajes del alumno.
   */
  public function acceptsMessages(): bool {
    return $this === self::Draft || $this === self::InProgress;
  }

  /**
   * Indica si la sesión ha terminado y ya no cambiará de estado.
   */
  public function isFinal(): bool {
    return $this === self::Completed || $this === self::Failed;
  }

}
