<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;

/**
 * Comprueba que cada fuente citada saliera de una búsqueda de esa misión.
 *
 * El 02-10-2026 un Weekly GOLD Pack citó catorce fuentes con su URL, cada una
 * respaldando una señal o la identificación de una persona —cinco ejecutivos
 * por nombre y cargo—, y no había NADA en el sistema con que comprobar que
 * esas catorce salieran de las trece búsquedas que se hicieron. El registro
 * guardaba la consulta y los recuentos; los enlaces, no.
 *
 * Es el peor fallo posible de este producto, porque no se ve: una URL inventada
 * no parece un error, parece un hecho, y quien la reciba va a salir a decirlo.
 *
 * Esta clase NO altera el entregable. Mide y avisa, a propósito: borrar una
 * cita que el agente puso puede romper un pack bueno —una URL legítima vista
 * dentro del texto de otro resultado no está en la lista de lo recuperado— y
 * qué hacer con una cita sin respaldo es una decisión de producto, no de
 * código. Primero hay que saber si ocurre y cuánto.
 */
final class CitationAudit {

  /**
   * El resultado de revisar una misión.
   */
  public const RESPALDADAS = 'respaldadas';
  public const OTRA_PAGINA = 'otra_pagina';
  public const NO_VISTAS = 'no_vistas';
  public const SIN_REGISTRO = 'sin_registro';

  /**
   * Donde queda constancia de una cita que no se pudo respaldar.
   */
  private readonly LoggerChannelInterface $logger;

  public function __construct(
    private readonly ToolCallRepository $calls,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('sales_leadership_diagnostic');
  }

  /**
   * Revisa las citas de un entregable y deja constancia de lo que no cuadra.
   *
   * @param int $sessionId
   *   La misión que produjo el entregable.
   * @param array $payload
   *   El resultado estructurado, con sus `accounts` y las `sources` de cada
   *   cuenta.
   *
   * @return array{respaldadas: string[], otra_pagina: string[], no_vistas: string[], sin_registro: bool}
   *   Las citadas que salieron de una búsqueda, las que apuntan a un sitio que
   *   sí se visitó pero a otra página, y las que no se vieron en ninguna parte.
   *   `sin_registro` es cierto cuando no hay URL guardadas con que comparar, y
   *   entonces las otras tres listas no significan nada.
   */
  public function review(int $sessionId, array $payload): array {
    $citadas = $this->citadas($payload);
    $recuperadas = $this->calls->retrievedUrlsInMission($sessionId);

    // Sin nada recuperado no se puede concluir nada. Decirlo es importante:
    // en una misión anterior al 02-10-2026 no se guardaron, y tratar eso como
    // «catorce citas inventadas» sería una acusación falsa.
    if ($recuperadas === []) {
      return [
        self::RESPALDADAS => [],
        self::OTRA_PAGINA => [],
        self::NO_VISTAS => [],
        self::SIN_REGISTRO => $citadas !== [],
      ];
    }

    $exactas = [];
    $dominios = [];

    foreach ($recuperadas as $url) {
      $exactas[$this->normalizar($url)] = TRUE;
      $dominios[$this->dominio($url)] = TRUE;
    }

    $revision = [
      self::RESPALDADAS => [],
      self::OTRA_PAGINA => [],
      self::NO_VISTAS => [],
      self::SIN_REGISTRO => FALSE,
    ];

    foreach ($citadas as $url) {
      if (isset($exactas[$this->normalizar($url)])) {
        $revision[self::RESPALDADAS][] = $url;
        continue;
      }

      // Mismo sitio, otra página. Se separa de lo no visto porque son dos
      // problemas distintos: aquí el agente pudo seguir un enlace que venía
      // dentro de un resultado, y allí la URL no tiene ningún origen.
      $revision[isset($dominios[$this->dominio($url)]) ? self::OTRA_PAGINA : self::NO_VISTAS][] = $url;
    }

    $this->avisar($sessionId, $revision);

    return $revision;
  }

  /**
   * Deja la cuenta en el registro, y solo si hay algo que contar.
   *
   * Se anota la misión y las URL, que son páginas públicas. Nunca el texto de
   * la conversación ni quién es la persona: el §31 y el §43 lo prohíben, y una
   * cita sin respaldo se investiga igual de bien sabiendo en qué misión fue.
   */
  private function avisar(int $sessionId, array $revision): void {
    $sinRespaldo = count($revision[self::NO_VISTAS]);

    if ($sinRespaldo === 0 && $revision[self::OTRA_PAGINA] === []) {
      return;
    }

    $this->logger->warning('citas_sin_respaldo: en la misión @sesion, @vistas de @total fuentes citadas salieron de una búsqueda; @otras apuntan a un sitio visitado pero a otra página; @ninguna no se vieron en ninguna búsqueda: @urls', [
      '@sesion' => $sessionId,
      '@vistas' => count($revision[self::RESPALDADAS]),
      '@total' => count($revision[self::RESPALDADAS]) + count($revision[self::OTRA_PAGINA]) + $sinRespaldo,
      '@otras' => count($revision[self::OTRA_PAGINA]),
      '@ninguna' => $sinRespaldo,
      '@urls' => implode(' , ', array_merge($revision[self::NO_VISTAS], $revision[self::OTRA_PAGINA])),
    ]);
  }

  /**
   * Las URL que el entregable cita, sin repetir.
   */
  private function citadas(array $payload): array {
    $urls = [];

    foreach ($payload['accounts'] ?? [] as $cuenta) {
      foreach ((is_array($cuenta) ? $cuenta['sources'] ?? [] : []) as $fuente) {
        $url = is_array($fuente) ? ($fuente['url'] ?? NULL) : NULL;

        if (is_string($url) && $url !== '') {
          $urls[] = $url;
        }
      }
    }

    return array_values(array_unique($urls));
  }

  /**
   * La misma dirección escrita de una sola forma.
   *
   * Se igualan las diferencias que NO cambian la página: el esquema, el «www.»,
   * la barra final y el fragmento. La ruta y los parámetros se conservan, y eso
   * es deliberado: la invención más probable no es un dominio falso, es una
   * ruta inventada dentro de un dominio real, y normalizar hasta el dominio la
   * dejaría pasar por buena.
   */
  private function normalizar(string $url): string {
    $partes = parse_url(trim($url));

    if ($partes === FALSE || !isset($partes['host'])) {
      return strtolower(trim($url));
    }

    $camino = rtrim($partes['path'] ?? '', '/');

    return $this->dominio($url)
      . strtolower($camino)
      . (isset($partes['query']) ? '?' . $partes['query'] : '');
  }

  /**
   * El sitio al que apunta, sin «www.» y en minúsculas.
   */
  private function dominio(string $url): string {
    $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
  }

}
