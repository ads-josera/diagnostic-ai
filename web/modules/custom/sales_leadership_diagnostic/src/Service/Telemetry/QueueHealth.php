<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Telemetry;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\sales_leadership_diagnostic\DTO\QueueSnapshot;
use Drupal\sales_leadership_diagnostic\Plugin\QueueWorker\DiagnosticTurnWorker;
use Drupal\sales_leadership_diagnostic\Plugin\QueueWorker\MemoryExtractionWorker;

/**
 * Cómo va la cola de turnos: lo que espera y lo que ya se está generando.
 *
 * Se lee de la tabla y no con `numberOfItems()` porque ese método cuenta todo
 * junto, y las dos cifras que importan son distintas: un turno esperando es
 * alguien mirando la pantalla, y un turno en curso es trabajo en marcha.
 *
 * La semántica de `expire` de la cola de Drupal no es evidente y decide el
 * reparto:
 *
 * - `expire = 0` → nadie lo ha tomado.
 * - `expire > ahora` → un recogedor lo está generando; la reserva sigue viva.
 * - `0 < expire <= ahora` → la reserva caducó sin que nadie lo terminara. Para
 *   la cola vuelve a estar libre —lo recupera su recolector de basura—, así
 *   que aquí cuenta como ESPERANDO: para el alumno lo es.
 */
final class QueueHealth {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Foto de la cola en este instante.
   */
  public function snapshot(): QueueSnapshot {
    // La tabla la crea la propia cola la primera vez que se encola algo. En un
    // sitio recién instalado todavía no existe, y eso no es un error: es que
    // no hay nada pendiente.
    if (!$this->database->schema()->tableExists(DatabaseQueue::TABLE_NAME)) {
      return new QueueSnapshot(0, 0, 0, 0);
    }

    $ahora = $this->time->getRequestTime();
    $turnos = DiagnosticTurnWorker::QUEUE;

    $esperando = $this->contar($turnos, "expire = 0 OR expire <= :ahora", [':ahora' => $ahora]);
    $enCurso = $this->contar($turnos, 'expire > :ahora', [':ahora' => $ahora]);

    return new QueueSnapshot(
      esperando: $esperando,
      enCurso: $enCurso,
      esperaMasLarga: $this->esperaMasLarga($turnos, $ahora),
      memoriaPendiente: $this->contar(MemoryExtractionWorker::QUEUE, '1 = 1', []),
    );
  }

  /**
   * Cuenta elementos de una cola que cumplen una condición.
   *
   * @param string $cola
   *   Nombre de la cola.
   * @param string $condicion
   *   Condición SQL sobre la tabla de colas.
   * @param array<string, int> $argumentos
   *   Argumentos de la condición.
   */
  private function contar(string $cola, string $condicion, array $argumentos): int {
    $consulta = $this->database->select(DatabaseQueue::TABLE_NAME, 'q');
    $consulta->condition('name', $cola);
    $consulta->where($condicion, $argumentos);

    return (int) $consulta->countQuery()->execute()->fetchField();
  }

  /**
   * Segundos que lleva esperando el turno más antiguo que no ha empezado.
   */
  private function esperaMasLarga(string $cola, int $ahora): int {
    $consulta = $this->database->select(DatabaseQueue::TABLE_NAME, 'q');
    $consulta->addExpression('MIN([created])', 'primero');
    $consulta->condition('name', $cola);
    $consulta->where('expire = 0 OR expire <= :ahora', [':ahora' => $ahora]);

    $primero = $consulta->execute()->fetchField();

    if ($primero === NULL || $primero === FALSE) {
      return 0;
    }

    // Nunca negativo: el reloj de la petición puede ir por detrás del sello
    // que puso quien encoló, y una espera en negativo se lee como un error.
    return max(0, $ahora - (int) $primero);
  }

}
