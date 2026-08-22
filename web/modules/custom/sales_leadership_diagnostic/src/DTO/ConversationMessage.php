<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

use Drupal\sales_leadership_diagnostic\MessageRole;

/**
 * Un mensaje de la conversación, ya leído de la base de datos.
 *
 * Es inmutable a propósito: la conversación es un registro de lo que ocurrió y
 * ningún consumidor debería poder reescribir un turno pasado. Para añadir un
 * turno nuevo se usa el repositorio.
 */
final readonly class ConversationMessage {

  /**
   * Construye un mensaje ya leído de la base de datos.
   *
   * @param int $id
   *   Identificador del mensaje.
   * @param int $sessionId
   *   Sesión a la que pertenece.
   * @param \Drupal\sales_leadership_diagnostic\MessageRole $role
   *   Quién lo escribió.
   * @param string $content
   *   Texto tal como se escribió o se recibió.
   * @param array<string, mixed>|null $payload
   *   Turno estructurado completo tal como lo devolvió el motor, si lo hubo.
   *   Los mensajes del alumno no tienen estructura y llevan NULL.
   * @param int $sequence
   *   Posición dentro de la conversación.
   * @param int $created
   *   Marca temporal de creación.
   */
  public function __construct(
    public int $id,
    public int $sessionId,
    public MessageRole $role,
    public string $content,
    public ?array $payload,
    public int $sequence,
    public int $created,
  ) {}

  /**
   * Construye el DTO a partir de una fila de la tabla.
   */
  public static function fromRecord(object $record): self {
    $payload = NULL;

    if (isset($record->payload) && $record->payload !== '') {
      $decoded = json_decode((string) $record->payload, TRUE);
      $payload = is_array($decoded) ? $decoded : NULL;
    }

    return new self(
      id: (int) $record->id,
      sessionId: (int) $record->session_id,
      role: MessageRole::from((string) $record->role),
      content: (string) $record->content,
      payload: $payload,
      sequence: (int) $record->sequence,
      created: (int) $record->created,
    );
  }

  /**
   * Representación del mensaje en el formato que esperan las APIs de IA.
   *
   * @return array{role: string, content: string}
   *   El mensaje con la forma que esperan las APIs de los proveedores.
   */
  public function toEnginePayload(): array {
    return [
      'role' => $this->role->value,
      'content' => $this->content,
    ];
  }

}
