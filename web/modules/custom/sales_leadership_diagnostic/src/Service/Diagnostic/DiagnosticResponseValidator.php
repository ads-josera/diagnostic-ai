<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;

/**
 * Valida y normaliza la respuesta estructurada del motor (§32).
 *
 * Se valida en el servidor aunque el proveedor garantice el formato. Esa
 * garantía es de terceros y puede fallar por un cambio de modelo, una
 * degradación del servicio o un prompt que la contradiga; almacenar sin
 * comprobar significaría descubrirlo al mostrar un resultado corrupto al
 * alumno, cuando ya es tarde.
 *
 * El límite de longitud existe porque el texto acaba en la base de datos y en
 * la pantalla: una respuesta desbocada no debe poder llenar ninguna de las dos.
 */
final class DiagnosticResponseValidator {

  /**
   * Longitud máxima admitida para el mensaje conversacional.
   */
  private const MAX_MESSAGE_LENGTH = 20000;

  /**
   * Tipos de turno reconocidos.
   */
  private const TYPE_RESPONSE = 'diagnostic_response';
  private const TYPE_RESULT = 'diagnostic_result';

  /**
   * Valida la respuesta cruda y la convierte en un turno.
   *
   * @param array<string, mixed> $raw
   *   Respuesta del motor, ya decodificada.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  public function validate(array $raw): DiagnosticTurn {
    $type = $this->readString($raw, 'type');

    if (!in_array($type, [self::TYPE_RESPONSE, self::TYPE_RESULT], TRUE)) {
      throw new InvalidEngineResponseException(sprintf(
        'El motor devolvió un tipo de turno desconocido: "%s".',
        // Se acota antes de registrarlo: el valor viene de fuera y acaba en
        // el log.
        mb_substr($type, 0, 40),
      ));
    }

    $message = $this->readString($raw, 'message');

    if (trim($message) === '') {
      throw new InvalidEngineResponseException('El motor devolvió un turno sin mensaje para el alumno.');
    }

    if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
      throw new InvalidEngineResponseException(sprintf(
        'El mensaje del motor supera el máximo admitido (%d caracteres).',
        self::MAX_MESSAGE_LENGTH,
      ));
    }

    $status = $this->readString($raw, 'status');
    $completed = $type === self::TYPE_RESULT || $status === 'completed';

    $result = NULL;

    if ($completed) {
      $result = $this->extractResult($raw);
    }

    return new DiagnosticTurn(
      message: $message,
      completed: $completed,
      result: $result,
      raw: $raw,
    );
  }

  /**
   * Extrae la estructura del resultado final.
   *
   * La forma definitiva depende de la metodología del cliente (§32), así que
   * aquí solo se exige que exista y sea una estructura. Cuando el cliente
   * entregue su formato, este es el punto donde añadir las comprobaciones
   * concretas.
   *
   * @return array<string, mixed>
   *   La estructura del resultado final, tal como la devolvió el motor.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function extractResult(array $raw): array {
    // El resultado puede venir anidado en "result" o al mismo nivel que el
    // mensaje, según cómo lo formule el prompt del cliente. Se admiten ambos.
    if (isset($raw['result']) && is_array($raw['result']) && $raw['result'] !== []) {
      return $raw['result'];
    }

    $inline = array_intersect_key($raw, array_flip([
      'summary',
      'score',
      'strengths',
      'opportunities',
      'recommendations',
      'priority_actions',
    ]));

    if ($inline !== []) {
      return $inline;
    }

    throw new InvalidEngineResponseException('El motor declaró el diagnóstico completado pero no devolvió ningún resultado.');
  }

  /**
   * Lee una clave como cadena, tolerando su ausencia.
   */
  private function readString(array $raw, string $key): string {
    $value = $raw[$key] ?? '';

    return is_scalar($value) ? (string) $value : '';
  }

}
