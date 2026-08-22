<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Resultado de consultar si un alumno tiene derecho a un curso.
 *
 * El prompt sugería que la interfaz devolviese un booleano. Se devuelve este
 * objeto en su lugar porque un booleano no distingue «denegado» de «no se ha
 * podido comprobar», y esa distinción es exactamente lo que hace implementable
 * el comportamiento fail closed de §13: ante una denegación real no se concede
 * acceso nunca; ante una avería puede valer una autorización ya validada.
 */
final readonly class AccessDecision {

  /**
   * @param bool $granted
   *   Si el alumno tiene derecho al curso.
   * @param string $courseId
   *   Curso consultado.
   * @param int $checkedAt
   *   Momento en que se obtuvo la respuesta.
   * @param string $source
   *   De dónde salió: consulta en vivo o cache.
   */
  public function __construct(
    public bool $granted,
    public string $courseId,
    public int $checkedAt,
    public string $source = self::SOURCE_LIVE,
  ) {}

  /**
   * La respuesta viene de WordPress en este mismo momento.
   */
  public const SOURCE_LIVE = 'live';

  /**
   * La respuesta viene de una consulta anterior guardada en cache.
   */
  public const SOURCE_CACHE = 'cache';

  /**
   * Devuelve una copia marcada como procedente de cache.
   */
  public function fromCache(): self {
    return new self($this->granted, $this->courseId, $this->checkedAt, self::SOURCE_CACHE);
  }

  /**
   * Representación para almacenar en cache.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array {
    return [
      'granted' => $this->granted,
      'courseId' => $this->courseId,
      'checkedAt' => $this->checkedAt,
    ];
  }

  /**
   * Reconstruye una decisión guardada en cache.
   *
   * @param array<string, mixed> $data
   *   Datos previamente serializados.
   */
  public static function fromArray(array $data): self {
    return new self(
      granted: (bool) ($data['granted'] ?? FALSE),
      courseId: (string) ($data['courseId'] ?? ''),
      checkedAt: (int) ($data['checkedAt'] ?? 0),
      source: self::SOURCE_CACHE,
    );
  }

}
