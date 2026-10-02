<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

/**
 * Quien comprueba que una fuente citada tenga de dónde haber salido.
 *
 * Existe por una razón concreta y vale la pena dejarla escrita: la revisión de
 * citas corre al cerrar una misión, DESPUÉS de guardar el resultado y ANTES de
 * marcar la sesión como completada. Si lanza una excepción, el alumno se queda
 * con entregable y la sesión a medias, y el turno ya se pagó. Por eso quien la
 * llama la envuelve en un try/catch.
 *
 * Ese guardia no se podía probar con la clase concreta: la revisión solo
 * consulta la base si el entregable cita algo, y el motor simulado no cita
 * nada. Con esta interfaz, la prueba sustituye la revisión por una que siempre
 * revienta y exige que la conversación termine igual. Es el desacoplamiento que
 * convierte seis líneas de comentario en una comprobación.
 */
interface CitationAuditInterface {

  /**
   * Revisa las citas de un entregable y deja constancia de lo que no cuadra.
   *
   * @param int $uid
   *   El alumno, para poder reconocer la evidencia que reutiliza.
   * @param int $sessionId
   *   La misión que produjo el entregable.
   * @param array $payload
   *   El resultado estructurado, con las `sources` de cada cuenta.
   * @param string $message
   *   El Pack en Markdown, tal como lo lee la persona.
   *
   * @return array{respaldadas: string[], declaradas: string[], otra_pagina: string[], no_vistas: string[], sin_registro: bool}
   *   Cada URL citada en su categoría.
   */
  public function review(int $uid, int $sessionId, array $payload, string $message = ''): array;

}
