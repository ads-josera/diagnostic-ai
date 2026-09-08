<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Guarda y cuenta las llamadas a herramientas.
 *
 * Registra las concedidas y **las denegadas**. Las segundas son la mitad que
 * importa: son la prueba de que el gateway hace algo. Sin ellas, «bloquea
 * físicamente research no autorizado» —§15 de la especificación del cliente—
 * es una afirmación, no un hecho comprobable.
 */
final class ToolCallRepository {

  /**
   * Nombre de la tabla.
   */
  private const TABLE = 'sld_tool_call';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Anota una llamada, concedida o no.
   */
  public function record(
    int $uid,
    int $sessionId,
    string $tool,
    string $query,
    bool $allowed,
    string $denialReason = '',
    int $results = 0,
    int $retrievedChars = 0,
    int $latencyMs = 0,
    bool $isSandbox = FALSE,
  ): void {
    $this->database->insert(self::TABLE)->fields([
      'uid' => $uid,
      'session_id' => $sessionId,
      'tool' => $tool,
      'query' => $query,
      'allowed' => $allowed ? 1 : 0,
      'denial_reason' => $denialReason,
      'results' => $results,
      'retrieved_chars' => $retrievedChars,
      'latency_ms' => $latencyMs,
      'is_sandbox' => $isSandbox ? 1 : 0,
      'created' => $this->time->getRequestTime(),
    ])->execute();
  }

  /**
   * Lo consumido en una misión.
   *
   * Solo cuenta lo CONCEDIDO: una petición denegada no gastó nada, y contarla
   * dejaría al agente sin margen por haber intentado algo que no se le dejó
   * hacer.
   *
   * @return array{calls: int, chars: int}
   *   Llamadas concedidas y texto que entró al modelo.
   */
  public function usedInMission(int $sessionId): array {
    $fila = $this->database->query(
      'SELECT COUNT(*) AS calls, COALESCE(SUM(retrieved_chars), 0) AS chars
         FROM {' . self::TABLE . '}
        WHERE session_id = :id AND allowed = 1',
      [':id' => $sessionId],
    )->fetchAssoc() ?: [];

    return [
      'calls' => (int) ($fila['calls'] ?? 0),
      'chars' => (int) ($fila['chars'] ?? 0),
    ];
  }

  /**
   * Llamadas concedidas a una persona desde una fecha.
   *
   * Este contador NO es redundante con el de la misión. Sin él, el tope se
   * salta abriendo otra conversación, que es exactamente el bypass que nombra
   * el §6 de la especificación: «evitar bypass por nueva conversación, nuevo
   * dispositivo, Entry Mode distinto».
   *
   * Los ensayos del estudio quedan fuera, por el mismo motivo que en el gasto:
   * los hace quien administra y no deben comerse el cupo de nadie.
   */
  public function usedByUserSince(int $uid, int $since): int {
    return (int) $this->database->select(self::TABLE, 't')
      ->condition('uid', $uid)
      ->condition('created', $since, '>=')
      ->condition('allowed', 1)
      ->condition('is_sandbox', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Resumen de un periodo.
   *
   * @return array{allowed: int, denied: int, chars: int, results: int}
   *   Concedidas, denegadas, texto que entró y resultados traídos.
   */
  public function summarySince(int $since): array {
    $fila = $this->database->query(
      'SELECT COALESCE(SUM(allowed), 0) AS allowed,
              COALESCE(SUM(1 - allowed), 0) AS denied,
              COALESCE(SUM(retrieved_chars), 0) AS chars,
              COALESCE(SUM(results), 0) AS results
         FROM {' . self::TABLE . '}
        WHERE created >= :desde',
      [':desde' => $since],
    )->fetchAssoc() ?: [];

    return [
      'allowed' => (int) ($fila['allowed'] ?? 0),
      'denied' => (int) ($fila['denied'] ?? 0),
      'chars' => (int) ($fila['chars'] ?? 0),
      'results' => (int) ($fila['results'] ?? 0),
    ];
  }

  /**
   * Las últimas llamadas, para enseñarlas.
   *
   * @return array<int, array<string, mixed>>
   *   Filas completas, de la más reciente a la más antigua.
   */
  public function recent(int $limit = 30): array {
    return $this->database->select(self::TABLE, 't')
      ->fields('t')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * Cuántas se denegaron desde una fecha, por motivo.
   *
   * @return array<string, int>
   *   Motivo => cuántas veces.
   */
  public function denialsSince(int $since): array {
    $filas = $this->database->query(
      'SELECT denial_reason, COUNT(*) AS total
         FROM {' . self::TABLE . '}
        WHERE created >= :desde AND allowed = 0
        GROUP BY denial_reason
        ORDER BY total DESC',
      [':desde' => $since],
    )->fetchAll(FetchAs::Associative);

    $salida = [];

    foreach ($filas as $fila) {
      $salida[(string) $fila['denial_reason']] = (int) $fila['total'];
    }

    return $salida;
  }

}
