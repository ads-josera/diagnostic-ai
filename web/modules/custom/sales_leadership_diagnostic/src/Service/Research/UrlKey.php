<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

/**
 * La misma dirección escrita de una sola forma, para poder compararlas.
 *
 * Lo usan la revisión de citas y la comprobación de compradores: las dos
 * comparan una URL que escribió el agente con las que trajo la búsqueda. Vive
 * en un solo sitio a propósito: dos normalizaciones distintas acabarían dando
 * por buena en una lo que la otra rechaza.
 *
 * Se igualan las diferencias que NO cambian la página: el esquema, el «www.»,
 * la barra final y el fragmento. La ruta y los parámetros se conservan, y eso
 * es deliberado: la invención más probable no es un dominio falso, es una ruta
 * inventada dentro de un dominio real, y normalizar hasta el dominio la dejaría
 * pasar por buena.
 */
final class UrlKey {

  /**
   * La dirección normalizada.
   */
  public static function of(string $url): string {
    $partes = parse_url(trim($url));

    if ($partes === FALSE || !isset($partes['host'])) {
      return strtolower(trim($url));
    }

    $camino = rtrim($partes['path'] ?? '', '/');

    return self::domain($url)
      . strtolower($camino)
      . (isset($partes['query']) ? '?' . $partes['query'] : '');
  }

  /**
   * El sitio al que apunta, sin «www.» y en minúsculas.
   */
  public static function domain(string $url): string {
    $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
  }

}
