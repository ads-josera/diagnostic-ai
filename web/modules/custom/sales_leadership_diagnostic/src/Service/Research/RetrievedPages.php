<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

/**
 * El texto que trajo cada resultado de búsqueda, mientras dura el turno.
 *
 * Existe para poder comprobar que la fuente que el agente presenta como prueba
 * de un comprador NOMBRA a esa persona. El 03-10-2026 dio por verificado a un
 * directivo con dos fuentes que no lo demostraban —una ni siquiera lo
 * mencionaba—, mientras otra página de sus propios resultados, que sí lo
 * nombraba con su cargo, se quedó sin citar.
 *
 * Vive en memoria y no en la base, a propósito. La comprobación ocurre en el
 * mismo proceso que la búsqueda —el turno que investiga y entrega el Pack—, y
 * guardar el texto de cada página serían cientos de kilobytes por misión que no
 * se vuelven a leer nunca. Se agrupa por conversación para que un recogedor que
 * procesa varios turnos seguidos no mezcle los de una con los de otra, y se
 * suelta al acabar cada turno.
 */
final class RetrievedPages {

  /**
   * El texto de cada página, por conversación y por dirección normalizada.
   *
   * @var array<int, array<string, string>>
   */
  private array $paginas = [];

  /**
   * Anota lo que trajo un resultado.
   *
   * Si la misma página sale en dos búsquedas, se suman los dos textos: cada
   * extracto puede traer un trozo distinto de ella.
   */
  public function remember(int $sessionId, string $url, string $texto): void {
    if ($sessionId <= 0 || trim($url) === '' || trim($texto) === '') {
      return;
    }

    $clave = UrlKey::of($url);
    $previo = $this->paginas[$sessionId][$clave] ?? '';
    $this->paginas[$sessionId][$clave] = trim($previo . ' ' . $texto);
  }

  /**
   * El texto de una página, o NULL si no pasó por este proceso.
   *
   * NULL no significa que no exista: puede haber salido en un turno anterior,
   * procesado en otro momento. Por eso quien lo usa no lo trata como un fallo.
   */
  public function textOf(int $sessionId, string $url): ?string {
    return $this->paginas[$sessionId][UrlKey::of($url)] ?? NULL;
  }

  /**
   * Suelta lo de una conversación al acabar su turno.
   */
  public function forget(int $sessionId): void {
    unset($this->paginas[$sessionId]);
  }

}
