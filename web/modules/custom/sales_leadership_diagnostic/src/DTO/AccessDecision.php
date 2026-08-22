<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Resultado de consultar si un alumno tiene derecho al diagnóstico.
 *
 * El prompt sugería que la interfaz devolviese un booleano. Se devuelve este
 * objeto en su lugar porque un booleano no distingue «denegado» de «no se ha
 * podido comprobar», y esa distinción es exactamente lo que hace implementable
 * el comportamiento fail closed de §13: ante una denegación real no se concede
 * acceso nunca; ante una avería puede valer una autorización ya validada.
 *
 * Lleva además la fecha de caducidad, que WordPress calcula y Drupal no. El
 * acceso al diagnóstico caduca aunque el acceso al curso no lo haga, y quien
 * sabe cuándo compró cada alumno es WordPress.
 */
final readonly class AccessDecision {

  /**
   * La respuesta viene de WordPress en este mismo momento.
   */
  public const SOURCE_LIVE = 'live';

  /**
   * La respuesta viene de una consulta anterior guardada en cache.
   */
  public const SOURCE_CACHE = 'cache';

  /**
   * @param bool $granted
   *   Si el alumno tiene derecho al diagnóstico ahora mismo.
   * @param string $courseId
   *   Curso que concedió el acceso, o cadena vacía si ninguno.
   * @param int $checkedAt
   *   Momento en que se obtuvo la respuesta.
   * @param string $source
   *   De dónde salió: consulta en vivo o cache.
   * @param int|null $expiresAt
   *   Momento en que caduca el acceso. NULL si no caduca o si no llegó a
   *   concederse.
   */
  public function __construct(
    public bool $granted,
    public string $courseId,
    public int $checkedAt,
    public string $source = self::SOURCE_LIVE,
    public ?int $expiresAt = NULL,
  ) {}

  /**
   * Devuelve una copia marcada como procedente de cache.
   */
  public function fromCache(): self {
    return new self(
      $this->granted,
      $this->courseId,
      $this->checkedAt,
      self::SOURCE_CACHE,
      $this->expiresAt,
    );
  }

  /**
   * Días que faltan para que caduque el acceso.
   *
   * Devuelve NULL si no caduca. Un valor negativo significa que ya caducó,
   * aunque en ese caso `granted` ya debería ser FALSE.
   */
  public function daysUntilExpiry(int $now): ?int {
    if ($this->expiresAt === NULL) {
      return NULL;
    }

    return (int) floor(($this->expiresAt - $now) / 86400);
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
      'expiresAt' => $this->expiresAt,
    ];
  }

  /**
   * Reconstruye una decisión guardada en cache.
   *
   * @param array<string, mixed> $data
   *   Datos previamente serializados.
   */
  public static function fromArray(array $data): self {
    $expiresAt = $data['expiresAt'] ?? NULL;

    return new self(
      granted: (bool) ($data['granted'] ?? FALSE),
      courseId: (string) ($data['courseId'] ?? ''),
      checkedAt: (int) ($data['checkedAt'] ?? 0),
      source: self::SOURCE_CACHE,
      expiresAt: is_numeric($expiresAt) ? (int) $expiresAt : NULL,
    );
  }

}
