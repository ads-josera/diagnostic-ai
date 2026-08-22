<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\sales_leadership_diagnostic\DTO\ConversationMessage;
use Drupal\sales_leadership_diagnostic\MessageRole;

/**
 * Acceso a los mensajes de una conversación de diagnóstico.
 *
 * Los mensajes viven en una tabla propia y no como entidad de contenido. La
 * razón principal es de privacidad: contienen información empresarial sensible
 * del alumno, y como entidad quedarían expuestos a Views, al índice de
 * búsqueda y a cualquier módulo que recorra entidades. Fuera de Entity API, la
 * única puerta de entrada es esta clase.
 *
 * A eso se suma que no necesitan nada de lo que Entity API aporta: son
 * inmutables, se leen siempre como el conjunto ordenado de una sesión, y su
 * control de acceso es el de la sesión que los contiene, no uno propio.
 */
final class DiagnosticMessageRepository {

  public const TABLE = 'sld_diagnostic_message';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Añade un turno al final de la conversación.
   *
   * El número de secuencia se calcula dentro de una transacción a partir del
   * último existente. La clave única (session_id, sequence) impide que dos
   * escrituras simultáneas produzcan turnos duplicados: si ocurriera, la
   * segunda falla en vez de corromper el historial en silencio. En condiciones
   * normales el bloqueo por sesión de la capa de conversación evita que se
   * llegue a ese punto.
   *
   * @param int $sessionId
   *   Sesión a la que pertenece el turno.
   * @param \Drupal\sales_leadership_diagnostic\MessageRole $role
   *   Quién escribe: el alumno o el agente.
   * @param string $content
   *   Texto del mensaje, sin procesar.
   * @param array<string, mixed>|null $payload
   *   Turno estructurado devuelto por el motor, si lo hubo.
   */
  public function append(int $sessionId, MessageRole $role, string $content, ?array $payload = NULL): ConversationMessage {
    $transaction = $this->database->startTransaction();

    try {
      $sequence = $this->nextSequence($sessionId);
      $created = $this->time->getRequestTime();

      $id = (int) $this->database->insert(self::TABLE)
        ->fields([
          'session_id' => $sessionId,
          'role' => $role->value,
          'content' => $content,
          'payload' => $payload === NULL
            ? NULL
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          'sequence' => $sequence,
          'created' => $created,
        ])
        ->execute();

      return new ConversationMessage(
        id: $id,
        sessionId: $sessionId,
        role: $role,
        content: $content,
        payload: $payload,
        sequence: $sequence,
        created: $created,
      );
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Devuelve la conversación completa de una sesión, en orden.
   *
   * @param int $sessionId
   *   Sesión cuya conversación se quiere leer.
   * @param int|null $limit
   *   Si se indica, devuelve solo los últimos N turnos, manteniendo el orden
   *   cronológico. Sirve para acotar el contexto que se envía al motor sin que
   *   el llamante tenga que invertir el array.
   *
   * @return \Drupal\sales_leadership_diagnostic\DTO\ConversationMessage[]
   *   Los turnos de la sesión, del más antiguo al más reciente.
   */
  public function loadForSession(int $sessionId, ?int $limit = NULL): array {
    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('m.session_id', $sessionId);

    if ($limit !== NULL) {
      // Se toman los más recientes y después se restaura el orden ascendente:
      // recortar por el principio perdería el final de la conversación, que es
      // justo la parte que el motor necesita.
      $query->orderBy('m.sequence', 'DESC')->range(0, $limit);
      $records = $query->execute()->fetchAll();
      $records = array_reverse($records);
    }
    else {
      $query->orderBy('m.sequence', 'ASC');
      $records = $query->execute()->fetchAll();
    }

    return array_map(
      static fn (object $record): ConversationMessage => ConversationMessage::fromRecord($record),
      $records,
    );
  }

  /**
   * Número de mensajes de una sesión.
   */
  public function countForSession(int $sessionId): int {
    return (int) $this->database->select(self::TABLE, 'm')
      ->condition('m.session_id', $sessionId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Elimina todos los mensajes de una sesión.
   *
   * @return int
   *   Número de mensajes eliminados.
   */
  public function deleteForSession(int $sessionId): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('session_id', $sessionId)
      ->execute();
  }

  /**
   * Siguiente número de secuencia libre de una sesión.
   */
  private function nextSequence(int $sessionId): int {
    $query = $this->database->select(self::TABLE, 'm');
    $query->addExpression('MAX([m].[sequence])', 'highest');
    $query->condition('m.session_id', $sessionId);

    $highest = $query->execute()->fetchField();

    return $highest === NULL || $highest === FALSE ? 1 : ((int) $highest) + 1;
  }

}
