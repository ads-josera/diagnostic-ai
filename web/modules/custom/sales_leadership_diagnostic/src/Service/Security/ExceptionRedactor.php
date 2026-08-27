<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Security;

/**
 * Deja un mensaje de excepción en condiciones de escribirse en el registro.
 *
 * Parte de una idea que no es evidente: **el mensaje de una excepción es texto
 * no fiable**. Se suele tratar como si fuera una etiqueta escrita por un
 * programador, y muchas veces no lo es.
 *
 * El caso que lo motiva se midió el 26-08-2026. Drupal envuelve los errores de
 * base de datos en `DatabaseExceptionWrapper`, y su mensaje incluye la consulta
 * COMPLETA con los valores enlazados. Un fallo al guardar un turno escribía
 * así el texto del alumno en el registro:
 *
 *     SQLSTATE[22001]: ... : INSERT INTO "sld_diagnostic_message" (...)
 *     Array ( [:db_insert_placeholder_2] => SECRETO: margen real 12% ... )
 *
 * Eso es exactamente lo que §43 prohíbe: el registro no debe contener nada del
 * negocio del alumno. Y no ocurre solo con la base de datos; cualquier
 * biblioteca puede meter en el mensaje lo que le dieron.
 *
 * La regla es quedarse con el diagnóstico y tirar el resto: lo útil para
 * soporte está al principio —el código de error y qué falló—, y los datos
 * vienen detrás. Se prefiere perder detalle a filtrar contenido.
 */
final class ExceptionRedactor {

  /**
   * Longitud máxima del mensaje que llega al registro.
   *
   * Suficiente para el código de error y la causa; corto para que un mensaje
   * que arrastre datos no los arrastre enteros. Es la segunda red, no la
   * primera: el corte por marcadores va antes.
   */
  private const MAX_LONGITUD = 200;

  /**
   * Marcadores a partir de los cuales el mensaje deja de ser diagnóstico.
   *
   * En cuanto aparece uno, lo que sigue es la consulta o sus valores. Se
   * comparan sin distinguir mayúsculas porque no todas las capas los escriben
   * igual.
   *
   * @var string[]
   */
  private const CORTES = [
    ': INSERT INTO',
    ': SELECT ',
    ': UPDATE ',
    ': DELETE FROM',
    ': REPLACE INTO',
    'db_insert_placeholder',
    'db_condition_placeholder',
    'db_update_placeholder',
    'Array',
  ];

  /**
   * Devuelve el mensaje ya recortado, listo para el registro.
   */
  public static function redact(\Throwable $exception): string {
    return self::redactMessage($exception->getMessage());
  }

  /**
   * Igual que redact(), pero sobre un texto suelto.
   *
   * Existe aparte para poder probarlo con los mensajes exactos que producen
   * las capas de abajo, sin tener que provocar sus errores de verdad.
   */
  public static function redactMessage(string $message): string {
    // Una sola línea: los mensajes de varias líneas suelen serlo porque
    // alguien pegó dentro una estructura de datos.
    $limpio = trim((string) strtok($message, "\r\n"));

    foreach (self::CORTES as $marcador) {
      $posicion = stripos($limpio, $marcador);

      if ($posicion !== FALSE) {
        $limpio = rtrim(substr($limpio, 0, $posicion), " :\t");
      }
    }

    if ($limpio === '') {
      // Cortarlo todo es posible y no es un fallo: significa que el mensaje
      // era íntegramente datos. Mejor decirlo que dejar la entrada muda.
      return '(mensaje omitido por poder contener datos)';
    }

    return mb_strlen($limpio) > self::MAX_LONGITUD
      ? mb_substr($limpio, 0, self::MAX_LONGITUD) . '…'
      : $limpio;
  }

}
