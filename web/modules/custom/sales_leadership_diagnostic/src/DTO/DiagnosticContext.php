<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Todo lo que el motor de diagnóstico necesita para producir un turno.
 *
 * Deliberadamente no contiene identidad del alumno: ni nombre, ni correo, ni
 * identificadores de Drupal o WordPress. El motor no los necesita para aplicar
 * la metodología, y no enviarlos es la forma más simple de no filtrarlos a un
 * tercero (§31, §43).
 *
 * El prompt viene del snapshot congelado en la sesión, no de la configuración
 * vigente: un diagnóstico debe terminar con el mismo prompt con el que empezó
 * aunque un administrador lo edite a mitad (§57).
 */
final readonly class DiagnosticContext {

  /**
   * @param string $systemPrompt
   *   Prompt e instrucciones ya resueltos, tal como se congelaron al iniciar.
   * @param \Drupal\sales_leadership_diagnostic\DTO\ConversationMessage[] $history
   *   Turnos previos, en orden cronológico.
   * @param string $diagnosticVersion
   *   Versión con la que se ejecuta esta sesión.
   * @param int $turnNumber
   *   Número del turno que se está generando, empezando en 1.
   * @param int $maxTurns
   *   Tope configurado. El motor puede usarlo para cerrar a tiempo.
   */
  public function __construct(
    public string $systemPrompt,
    public array $history,
    public string $diagnosticVersion,
    public int $turnNumber,
    public int $maxTurns,
  ) {}

  /**
   * Indica si este es el último turno permitido.
   *
   * Permite que el motor concluya en lugar de quedarse a medias cuando el
   * tope obliga a cerrar la conversación.
   */
  public function isFinalTurn(): bool {
    return $this->turnNumber >= $this->maxTurns;
  }

  /**
   * Historial en el formato que esperan las APIs de IA.
   *
   * @return array<int, array{role: string, content: string}>
   */
  public function historyAsPayload(): array {
    return array_map(
      static fn (ConversationMessage $message): array => $message->toEnginePayload(),
      $this->history,
    );
  }

}
