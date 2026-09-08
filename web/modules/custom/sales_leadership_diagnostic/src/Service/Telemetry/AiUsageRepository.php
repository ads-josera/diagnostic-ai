<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Telemetry;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Guarda y consulta el consumo del proveedor de IA.
 *
 * Es la única puerta a la tabla `sld_ai_usage`, que no es entidad de contenido
 * a propósito: son cifras que se escriben una vez, no se editan nunca y se
 * consultan sumadas.
 *
 * Lo que aquí se guarda es lo que va a decidir si a un alumno se le corta el
 * servicio, así que dos reglas gobiernan la clase:
 *
 *  - **Se anota también lo que falló.** El proveedor cobra el intento aunque
 *    la respuesta no sirva. Registrar solo los éxitos deja abierta la única
 *    vía de gastar sin tope: reintentar.
 *  - **El coste se congela.** Se calcula con la tarifa del momento y se
 *    guarda. Recalcularlo al leer reescribiría el pasado en cuanto el
 *    proveedor cambiara de precios.
 */
final class AiUsageRepository {

  /**
   * Nombre de la tabla.
   */
  private const TABLE = 'sld_ai_usage';

  public function __construct(
    private readonly Connection $database,
    private readonly PriceList $prices,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Anota un grupo de llamadas atribuyéndolas a quien las provocó.
   *
   * @param \Drupal\sales_leadership_diagnostic\DTO\AiCall[] $calls
   *   Lo que recogió el colector.
   * @param int $uid
   *   Alumno por cuya cuenta se hicieron.
   * @param string $agent
   *   Agente que las motivó.
   * @param int|null $sessionId
   *   Conversación, si la hubo.
   * @param bool $isSandbox
   *   Cierto si vinieron del estudio del prompt.
   */
  public function recordAll(array $calls, int $uid, string $agent, ?int $sessionId, bool $isSandbox = FALSE): void {
    if ($calls === []) {
      return;
    }

    $ahora = $this->time->getRequestTime();
    $insert = $this->database->insert(self::TABLE)->fields([
      'uid', 'agent', 'session_id', 'purpose', 'model',
      'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens',
      'cost_usd', 'latency_ms', 'attempts', 'failed', 'is_sandbox', 'created',
    ]);

    foreach ($calls as $call) {
      $insert->values([
        'uid' => $uid,
        'agent' => $agent,
        'session_id' => $sessionId,
        'purpose' => $call->purpose,
        'model' => $call->model,
        'input_tokens' => $call->inputTokens,
        'cached_input_tokens' => $call->cachedInputTokens,
        'output_tokens' => $call->outputTokens,
        'reasoning_tokens' => $call->reasoningTokens,
        'cost_usd' => $this->prices->costOf($call),
        'latency_ms' => $call->latencyMs,
        'attempts' => $call->attempts,
        'failed' => $call->succeeded() ? 0 : 1,
        'is_sandbox' => $isSandbox ? 1 : 0,
        'created' => $ahora,
      ]);
    }

    $insert->execute();
  }

  /**
   * Lo que lleva gastado un alumno desde una fecha, en dólares.
   *
   * Los ensayos del estudio quedan fuera: cuestan dinero de verdad y por eso
   * se registran, pero los hace el gestor y no deben consumir el cupo de
   * ningún alumno.
   */
  public function costForUser(int $uid, int $since): float {
    return $this->sum(['uid' => $uid], $since, FALSE);
  }

  /**
   * Lo que lleva gastado la instalación entera desde una fecha, en dólares.
   *
   * Aquí SÍ entran los ensayos: el tope global protege la factura, y la
   * factura no distingue quién hizo la llamada.
   */
  public function costGlobal(int $since): float {
    return $this->sum([], $since, TRUE);
  }

  /**
   * Desglose de una conversación.
   *
   * @return array{calls: int, input: int, cached: int, output: int, cost: float, latency: int}
   *   Las cifras de esa sesión.
   */
  public function summaryForSession(int $sessionId): array {
    $fila = $this->database->query(
      'SELECT COUNT(*) AS calls,
              COALESCE(SUM(input_tokens), 0) AS input,
              COALESCE(SUM(cached_input_tokens), 0) AS cached,
              COALESCE(SUM(output_tokens), 0) AS output,
              COALESCE(SUM(cost_usd), 0) AS cost,
              COALESCE(SUM(latency_ms), 0) AS latency
         FROM {' . self::TABLE . '}
        WHERE session_id = :id',
      [':id' => $sessionId],
    )->fetchAssoc();

    return [
      'calls' => (int) ($fila['calls'] ?? 0),
      'input' => (int) ($fila['input'] ?? 0),
      'cached' => (int) ($fila['cached'] ?? 0),
      'output' => (int) ($fila['output'] ?? 0),
      'cost' => (float) ($fila['cost'] ?? 0),
      'latency' => (int) ($fila['latency'] ?? 0),
    ];
  }

  /**
   * Totales del periodo.
   *
   * @return array{calls: int, input: int, cached: int, output: int, cost: float, failed: int, sandbox: float}
   *   Lo consumido desde esa fecha, ensayos incluidos.
   */
  public function periodSummary(int $since): array {
    $fila = $this->database->query(
      'SELECT COUNT(*) AS calls,
              COALESCE(SUM(input_tokens), 0) AS input,
              COALESCE(SUM(cached_input_tokens), 0) AS cached,
              COALESCE(SUM(output_tokens), 0) AS output,
              COALESCE(SUM(cost_usd), 0) AS cost,
              COALESCE(SUM(failed), 0) AS failed,
              COALESCE(SUM(CASE WHEN is_sandbox = 1 THEN cost_usd ELSE 0 END), 0) AS sandbox
         FROM {' . self::TABLE . '}
        WHERE created >= :desde',
      [':desde' => $since],
    )->fetchAssoc() ?: [];

    return [
      'calls' => (int) ($fila['calls'] ?? 0),
      'input' => (int) ($fila['input'] ?? 0),
      'cached' => (int) ($fila['cached'] ?? 0),
      'output' => (int) ($fila['output'] ?? 0),
      'cost' => (float) ($fila['cost'] ?? 0),
      'failed' => (int) ($fila['failed'] ?? 0),
      'sandbox' => (float) ($fila['sandbox'] ?? 0),
    ];
  }

  /**
   * Consumo agrupado por una columna.
   *
   * @return array<int, array<string, mixed>>
   *   Una fila por valor, de mayor a menor coste.
   */
  public function groupedBy(string $column, int $since, int $limit = 25): array {
    // La columna la elige el codigo, nunca la peticion: se comprueba contra
    // una lista blanca porque va sin escapar dentro del SQL.
    if (!in_array($column, ['agent', 'uid', 'model', 'purpose'], TRUE)) {
      return [];
    }

    return $this->database->query(
      'SELECT ' . $column . ' AS clave,
              COUNT(*) AS calls,
              COALESCE(SUM(input_tokens), 0) AS input,
              COALESCE(SUM(cached_input_tokens), 0) AS cached,
              COALESCE(SUM(output_tokens), 0) AS output,
              COALESCE(SUM(cost_usd), 0) AS cost
         FROM {' . self::TABLE . '}
        WHERE created >= :desde
        GROUP BY ' . $column . '
        ORDER BY cost DESC
        LIMIT ' . $limit,
      [':desde' => $since],
    )->fetchAll(FetchAs::Associative);
  }

  /**
   * Lo que habria costado el periodo si el proveedor no reutilizara nada.
   *
   * No es un dato curioso: es la diferencia entre creer que el producto no es
   * rentable y saber que si lo es. El 07-09-2026 una conversacion de dos
   * turnos figuraba en $7.47 MXN con esta cuenta y costo $1.03 con la real.
   *
   * Se calcula modelo a modelo, porque cada uno tiene su tarifa.
   */
  public function costWithoutCacheDiscount(int $since): float {
    $total = 0.0;

    foreach ($this->groupedBy('model', $since, 50) as $fila) {
      $total += $this->prices->fullPriceCost(
        (string) $fila['clave'],
        (int) $fila['input'],
        (int) $fila['output'],
      );
    }

    return $total;
  }

  /**
   * Las ultimas llamadas, para ver el detalle.
   *
   * @return array<int, array<string, mixed>>
   *   Filas completas, de la mas reciente a la mas antigua.
   */
  public function recent(int $limit = 30): array {
    return $this->database->select(self::TABLE, 'u')
      ->fields('u')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * Suma el coste con las condiciones dadas.
   *
   * @param array<string, mixed> $condiciones
   *   Columna => valor.
   * @param int $since
   *   Desde cuándo.
   * @param bool $incluirEnsayos
   *   Si cuentan las conversaciones del estudio.
   */
  private function sum(array $condiciones, int $since, bool $incluirEnsayos): float {
    $consulta = $this->database->select(self::TABLE, 'u');
    $consulta->addExpression('COALESCE(SUM(cost_usd), 0)', 'total');
    $consulta->condition('created', $since, '>=');

    foreach ($condiciones as $columna => $valor) {
      $consulta->condition($columna, $valor);
    }

    if (!$incluirEnsayos) {
      $consulta->condition('is_sandbox', 0);
    }

    return (float) $consulta->execute()->fetchField();
  }

}
