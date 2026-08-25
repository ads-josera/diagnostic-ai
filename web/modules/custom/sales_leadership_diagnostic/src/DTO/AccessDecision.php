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
   * Construye una decisión de acceso.
   *
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
   * @param int|null $startedAt
   *   Momento en que empezó el periodo de acceso vigente. NULL si nunca
   *   empezó. Identifica el periodo: una compra nueva reinicia el reloj en
   *   WordPress y con ello cambia este valor, que es lo que permite saber si
   *   un diagnóstico anterior pertenece al periodo actual o a uno pasado.
   * @param string[] $ownedCourses
   *   Lista completa de cursos autorizadores del alumno, no solo el que
   *   concedió el acceso. Es lo que permite saber a qué agentes tiene
   *   derecho: en Drupal cada agente declara el curso que lo concede.
   *
   *   Llega vacío desde un plugin anterior a la 1.2.0, que solo enviaba
   *   `course_id`. En ese caso quien lo consume debe caer en el curso único,
   *   y así un sitio sin actualizar sigue funcionando con un solo agente.
   */
  public function __construct(
    public bool $granted,
    public string $courseId,
    public int $checkedAt,
    public string $source = self::SOURCE_LIVE,
    public ?int $expiresAt = NULL,
    public ?int $startedAt = NULL,
    public array $ownedCourses = [],
  ) {}

  /**
   * Cursos del alumno, con respaldo para plugins antiguos.
   *
   * Nunca devuelve una lista vacía cuando hay acceso concedido: si el plugin
   * no envió la lista, el curso que concedió el acceso ES la lista. Sin este
   * respaldo, actualizar Drupal antes que WordPress dejaría a los alumnos sin
   * ningún agente visible.
   *
   * @return string[]
   *   Identificadores de curso, sin vacíos ni repetidos.
   */
  public function getOwnedCourses(): array {
    $cursos = array_values(array_unique(array_filter(array_map(
      static fn ($c): string => trim((string) $c),
      $this->ownedCourses,
    ))));

    if ($cursos !== []) {
      return $cursos;
    }

    return trim($this->courseId) !== '' ? [trim($this->courseId)] : [];
  }

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
      $this->startedAt,
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
   *   Los datos mínimos para reconstruir la decisión desde la cache.
   */
  public function toArray(): array {
    return [
      'granted' => $this->granted,
      'courseId' => $this->courseId,
      'checkedAt' => $this->checkedAt,
      'expiresAt' => $this->expiresAt,
      'startedAt' => $this->startedAt,
      // Sin esta línea la lista se perdía al cachear, y el alumno veía todos
      // sus agentes en la primera consulta y uno solo en las siguientes. Un
      // fallo intermitente y dificilísimo de reproducir.
      'ownedCourses' => $this->ownedCourses,
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
    // Ausente en las entradas guardadas antes de que existiera este dato. Se
    // trata como «desconocido» y no como error: la cache anterior sigue
    // sirviendo hasta que caduque sola.
    $startedAt = $data['startedAt'] ?? NULL;

    return new self(
      granted: (bool) ($data['granted'] ?? FALSE),
      courseId: (string) ($data['courseId'] ?? ''),
      checkedAt: (int) ($data['checkedAt'] ?? 0),
      source: self::SOURCE_CACHE,
      expiresAt: is_numeric($expiresAt) ? (int) $expiresAt : NULL,
      startedAt: is_numeric($startedAt) ? (int) $startedAt : NULL,
      // Ausente en entradas guardadas antes de existir este dato, igual que
      // startedAt. getOwnedCourses() cae entonces en el curso único.
      ownedCourses: is_array($data['ownedCourses'] ?? NULL)
        ? array_map('strval', $data['ownedCourses'])
        : [],
    );
  }

}
