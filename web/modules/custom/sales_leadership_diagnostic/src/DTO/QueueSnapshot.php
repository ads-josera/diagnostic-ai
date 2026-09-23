<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Cómo está la cola de turnos en este instante.
 *
 * Existe porque «se sintió lento» no es un dato. El 22-09-2026, en una demo
 * con un cliente, la espera se notó y nadie podía decir si era la
 * investigación —que tarda de verdad— o una cola atascada. Esto separa las
 * dos cosas de un vistazo y ANTES de que alguien se queje.
 */
final readonly class QueueSnapshot {

  /**
   * Construye la foto.
   *
   * @param int $esperando
   *   Turnos encolados que todavía no ha tomado ningún recogedor.
   * @param int $enCurso
   *   Turnos que un recogedor está generando ahora mismo.
   * @param int $esperaMasLarga
   *   Segundos que lleva esperando el más antiguo de los que no han empezado.
   *   Cero si no hay ninguno.
   * @param int $memoriaPendiente
   *   Extracciones de memoria en cola. No hacen esperar a nadie —ocurren
   *   cuando la conversación ya terminó—, pero si se acumulan es que el cron
   *   no está pasando.
   */
  public function __construct(
    public int $esperando,
    public int $enCurso,
    public int $esperaMasLarga,
    public int $memoriaPendiente,
  ) {}

  /**
   * Si no hay nada pendiente ni generándose.
   */
  public function tranquila(): bool {
    return $this->esperando === 0 && $this->enCurso === 0;
  }

}
