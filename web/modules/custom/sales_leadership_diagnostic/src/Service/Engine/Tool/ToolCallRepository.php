<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
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
   * @param int $sessionId
   *   Conversación.
   * @param string[] $excluding
   *   Herramientas que no gastan cupo. Quien aplica el tope pasa aquí las que
   *   exime, para que eximir y contar no puedan decir cosas distintas.
   *
   * @return array{calls: int, chars: int}
   *   Llamadas concedidas y texto que entró al modelo.
   */
  public function usedInMission(int $sessionId, array $excluding = []): array {
    $consulta = $this->database->select(self::TABLE, 't')
      ->condition('session_id', $sessionId)
      ->condition('allowed', 1);
    $consulta->addExpression('COUNT(*)', 'calls');
    $consulta->addExpression('COALESCE(SUM(retrieved_chars), 0)', 'chars');
    $this->excluir($consulta, $excluding);

    $fila = $consulta->execute()->fetchAssoc() ?: [];

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
  public function usedByUserSince(int $uid, int $since, array $excluding = []): int {
    $consulta = $this->database->select(self::TABLE, 't')
      ->condition('uid', $uid)
      ->condition('created', $since, '>=')
      ->condition('allowed', 1)
      ->condition('is_sandbox', 0);
    $this->excluir($consulta, $excluding);

    return (int) $consulta->countQuery()->execute()->fetchField();
  }

  /**
   * Resumen de un periodo.
   *
   * @param int $since
   *   Desde cuándo.
   * @param string[] $excluding
   *   Herramientas que no cuentan. La pantalla titula este bloque «Búsquedas
   *   externas», así que sin excluir el ledger diría más búsquedas de las que
   *   hubo: en la primera misión medida, 32 en vez de 27.
   *
   * @return array{allowed: int, denied: int, chars: int, results: int}
   *   Concedidas, denegadas, texto que entró y resultados traídos.
   */
  public function summarySince(int $since, array $excluding = []): array {
    $consulta = $this->database->select(self::TABLE, 't')
      ->condition('created', $since, '>=');
    $consulta->addExpression('COALESCE(SUM(allowed), 0)', 'allowed');
    $consulta->addExpression('COALESCE(SUM(1 - allowed), 0)', 'denied');
    $consulta->addExpression('COALESCE(SUM(retrieved_chars), 0)', 'chars');
    $consulta->addExpression('COALESCE(SUM(results), 0)', 'results');
    $this->excluir($consulta, $excluding);

    $fila = $consulta->execute()->fetchAssoc() ?: [];

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
  public function denialsSince(int $since, array $excluding = []): array {
    $consulta = $this->database->select(self::TABLE, 't')
      ->fields('t', ['denial_reason'])
      ->condition('created', $since, '>=')
      ->condition('allowed', 0)
      ->groupBy('denial_reason');
    $consulta->addExpression('COUNT(*)', 'total');
    $consulta->orderBy('total', 'DESC');
    $this->excluir($consulta, $excluding);

    $filas = $consulta->execute()->fetchAll(FetchAs::Associative);

    $salida = [];

    foreach ($filas as $fila) {
      $salida[(string) $fila['denial_reason']] = (int) $fila['total'];
    }

    return $salida;
  }

  /**
   * Quita de la cuenta las herramientas que no cuentan.
   *
   * Vive aquí y no en cada consulta para que las cuatro excluyan igual. Una
   * lista vacía no toca nada, que es lo que quiere quien pide el total crudo.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $consulta
   *   Consulta a acotar.
   * @param string[] $excluding
   *   Nombres de herramienta que no deben contarse.
   */
  private function excluir(SelectInterface $consulta, array $excluding): void {
    if ($excluding !== []) {
      $consulta->condition('tool', $excluding, 'NOT IN');
    }
  }

}
