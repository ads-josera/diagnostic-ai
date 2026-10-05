<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;

/**
 * Devuelve a su dirección real las fuentes que el agente reescribió de memoria.
 *
 * El 05-10-2026, en una sola misión, el agente citó tres páginas con la
 * dirección retocada: le quitó palabras al título («…oficial-en-un-espectaculo»
 * en lugar de «…oficial-en-ecuador-con-un-espectaculo»), o se las añadió. Las
 * retocadas daban 404; las que había traído la búsqueda, 200. La revisión de
 * citas lo detectaba como «otra página del mismo sitio», pero el enlace llegaba
 * roto a quien lee el Pack.
 *
 * Aquí no hace falta preguntarle nada al modelo: la plataforma tiene la
 * dirección buena, porque la trajo la búsqueda. Se sustituye solo cuando no hay
 * duda razonable de que es la misma página:
 *
 * - del mismo sitio;
 * - y con el mismo identificador largo en la dirección (el número de una
 *   publicación, de diez dígitos o más), o con un título casi igual;
 * - y sin otra candidata que se le parezca casi lo mismo.
 *
 * Si hay duda, no se toca: un enlace roto que la revisión de citas señala es
 * mejor que un enlace a la página equivocada que nadie señala.
 */
final class SourceRepair {

  /**
   * Parecido mínimo del título, en porcentaje, para dar por buena una página.
   */
  private const PARECIDO_MINIMO = 80.0;

  /**
   * Ventaja mínima sobre la segunda candidata, para que no haya duda.
   */
  private const VENTAJA_MINIMA = 5.0;

  public function __construct(
    // Opcionales: repair() es puro y se prueba sin ellos; forCurrentTurn() los
    // necesita para saber qué trajeron las búsquedas de la misión en curso.
    private readonly ?CurrentTurn $turn = NULL,
    private readonly ?ToolCallRepository $calls = NULL,
  ) {}

  /**
   * Repara el Pack contra lo que buscó la misión en curso.
   *
   * @param array<string, mixed> $result
   *   El resultado estructurado del Pack.
   * @param string $message
   *   El Pack en Markdown.
   *
   * @return array{result: array<string, mixed>, message: string, reparadas: array<string, string>}
   *   Lo mismo que repair().
   */
  public function forCurrentTurn(array $result, string $message): array {
    $buscadas = $this->turn !== NULL && $this->calls !== NULL
      ? $this->calls->retrievedUrlsInMission($this->turn->sessionId())
      : [];

    return $this->repair($result, $message, $buscadas);
  }

  /**
   * Las fuentes del Pack, con las reescritas devueltas a su dirección real.
   *
   * @param array<string, mixed> $result
   *   El resultado estructurado del Pack.
   * @param string $message
   *   El Pack en Markdown: si el agente enlazó la dirección retocada también
   *   en el texto, se cambia ahí.
   * @param string[] $buscadas
   *   Las direcciones que trajeron las búsquedas de la misión.
   *
   * @return array{result: array<string, mixed>, message: string, reparadas: array<string, string>}
   *   El Pack reparado, y cada dirección cambiada con la que la sustituye.
   */
  public function repair(array $result, string $message, array $buscadas): array {
    $exactas = [];
    $porSitio = [];

    foreach ($buscadas as $url) {
      $exactas[UrlKey::of($url)] = TRUE;
      $porSitio[UrlKey::domain($url)][] = $url;
    }

    $reparadas = [];

    foreach ($result['accounts'] ?? [] as $i => $cuenta) {
      if (!is_array($cuenta)) {
        continue;
      }

      foreach ($cuenta['sources'] ?? [] as $j => $fuente) {
        $url = is_array($fuente) ? trim((string) ($fuente['url'] ?? '')) : '';
        $real = $this->real($url, $exactas, $porSitio);

        if ($real !== NULL) {
          $result['accounts'][$i]['sources'][$j]['url'] = $real;
          $reparadas[$url] = $real;
        }
      }

      $comprador = trim((string) ($cuenta['buyer_source'] ?? ''));
      $real = $this->real($comprador, $exactas, $porSitio);

      if ($real !== NULL) {
        $result['accounts'][$i]['buyer_source'] = $real;
        $reparadas[$comprador] = $real;
      }
    }

    // De la más larga a la más corta: una dirección que contiene a otra se
    // cambiaría a medias si fuera la corta la primera.
    uksort($reparadas, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

    return [
      'result' => $result,
      'message' => strtr($message, $reparadas),
      'reparadas' => $reparadas,
    ];
  }

  /**
   * La dirección real de una citada, o NULL si ya es real o hay duda.
   */
  private function real(string $url, array $exactas, array $porSitio): ?string {
    if ($url === '' || isset($exactas[UrlKey::of($url)])) {
      return NULL;
    }

    $candidatas = $porSitio[UrlKey::domain($url)] ?? [];

    if ($candidatas === []) {
      return NULL;
    }

    $camino = $this->camino($url);
    $puntos = [];

    foreach (array_unique($candidatas) as $candidata) {
      $suyo = $this->camino($candidata);

      // El mismo identificador largo —el número de una publicación— es la
      // misma página aunque el título que lo acompaña cambie.
      if ($this->identificador($camino) !== NULL && $this->identificador($camino) === $this->identificador($suyo)) {
        $puntos[$candidata] = 100.0;
        continue;
      }

      similar_text($camino, $suyo, $parecido);
      $puntos[$candidata] = $parecido;
    }

    arsort($puntos);
    $orden = array_values($puntos);
    $mejor = (string) array_key_first($puntos);

    if ($orden[0] < self::PARECIDO_MINIMO) {
      return NULL;
    }

    if (isset($orden[1]) && $orden[0] - $orden[1] < self::VENTAJA_MINIMA) {
      return NULL;
    }

    return $mejor;
  }

  /**
   * La ruta de una dirección, comparable: decodificada, sin tildes ni barras.
   */
  private function camino(string $url): string {
    $ruta = rawurldecode((string) parse_url($url, PHP_URL_PATH));
    $plano = strtr(mb_strtolower($ruta), [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'ü' => 'u',
      'ñ' => 'n',
    ]);

    return trim($plano, '/');
  }

  /**
   * El identificador largo de una ruta (diez dígitos o más), si lo tiene.
   *
   * Diez y no menos: con ocho, una fecha escrita seguida —«20260909»— contaría
   * como identificador y emparejaría dos artículos distintos del mismo día. Los
   * de una publicación en redes tienen entre dieciséis y diecinueve.
   */
  private function identificador(string $camino): ?string {
    return preg_match('/\d{10,}/', $camino, $m) ? $m[0] : NULL;
  }

}
