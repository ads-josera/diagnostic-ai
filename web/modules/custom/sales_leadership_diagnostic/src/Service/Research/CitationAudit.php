<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolGateway;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;

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
final class CitationAudit implements CitationAuditInterface {

  /**
   * Salió de una búsqueda de esta misión. Es el único respaldo fuerte.
   */
  public const RESPALDADAS = 'respaldadas';

  /**
   * El agente ya la tenía anotada en el ledger, en una conversación ANTERIOR.
   *
   * NO es prueba independiente —esas filas las escribió él— pero tampoco es una
   * invención: explica la reutilización de evidencia, que es justo para lo que
   * el ledger existe. Va en su propia categoría para que no se confunda con un
   * respaldo de verdad ni se denuncie como un invento.
   */
  public const DECLARADAS = 'declaradas';

  /**
   * El sitio se visitó, esa página concreta no.
   */
  public const OTRA_PAGINA = 'otra_pagina';

  /**
   * Ese sitio no aparece en ninguna búsqueda. No tiene origen.
   */
  public const NO_VISTAS = 'no_vistas';

  /**
   * Hubo búsquedas, pero de antes de que se guardaran sus URL.
   */
  public const SIN_REGISTRO = 'sin_registro';

  /**
   * Donde queda constancia de una cita que no se pudo respaldar.
   */
  private readonly LoggerChannelInterface $logger;

  public function __construct(
    private readonly ToolCallRepository $calls,
    private readonly EvidenceLedger $ledger,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   *
   * Se miran los dos sitios donde puede estar una cita. `$payload` las trae en
   * las `sources` de cada cuenta, que es lo que se guarda; `$message` es el Pack
   * en Markdown, y desde el 02-10-2026 el contrato le pide los enlaces AHÍ,
   * porque es lo único que la persona lee.
   *
   * `sin_registro` vuelve cierto cuando hubo búsquedas pero de antes de que se
   * guardaran sus URL, y entonces las otras listas no permiten concluir nada.
   */
  public function review(int $uid, int $sessionId, array $payload, string $message = ''): array {
    $citadas = $this->citadas($payload, $message);
    $revision = [
      self::RESPALDADAS => [],
      self::DECLARADAS => [],
      self::OTRA_PAGINA => [],
      self::NO_VISTAS => [],
      self::SIN_REGISTRO => FALSE,
    ];

    if ($citadas === []) {
      return $revision;
    }

    // Dos silencios que se parecen y significan lo contrario. Confundirlos deja
    // muda la única comprobación que importa: una misión que no buscó NADA y
    // cita catorce fuentes es el caso de las catorce inventadas, y la primera
    // versión de esta clase lo trataba igual que una misión anterior al cambio,
    // o sea sin decir una palabra.
    $registro = $this->calls->searchRecordInMission($sessionId, ToolGateway::EXENTAS_DE_TOPE);

    if ($registro['calls'] > 0 && $registro['withUrls'] === 0) {
      $revision[self::SIN_REGISTRO] = TRUE;
      $this->avisar($sessionId, $revision, count($citadas));

      return $revision;
    }

    $exactas = [];
    $dominios = [];

    foreach ($this->calls->retrievedUrlsInMission($sessionId) as $url) {
      $exactas[$this->normalizar($url)] = TRUE;
      $dominios[$this->dominio($url)] = TRUE;
    }

    $declaradas = [];

    foreach ($this->ledger->sourcesFor($uid, $sessionId) as $fuente) {
      $declaradas[$this->normalizar($fuente)] = TRUE;
    }

    foreach ($citadas as $url) {
      $normal = $this->normalizar($url);

      if (isset($exactas[$normal])) {
        $revision[self::RESPALDADAS][] = $url;
        continue;
      }

      if (isset($declaradas[$normal])) {
        $revision[self::DECLARADAS][] = $url;
        continue;
      }

      // Mismo sitio, otra página. Se separa de lo no visto porque son dos
      // problemas distintos: aquí el agente pudo seguir un enlace que venía
      // dentro de un resultado, y allí la URL no tiene ningún origen.
      $revision[isset($dominios[$this->dominio($url)]) ? self::OTRA_PAGINA : self::NO_VISTAS][] = $url;
    }

    $this->avisar($sessionId, $revision, count($citadas));

    return $revision;
  }

  /**
   * Deja la cuenta en el registro, y solo si hay algo que contar.
   *
   * Se anota la misión y las URL, que son páginas públicas. Nunca el texto de
   * la conversación ni quién es la persona: el §31 y el §43 lo prohíben, y una
   * cita sin respaldo se investiga igual de bien sabiendo en qué misión fue.
   */
  private function avisar(int $sessionId, array $revision, int $citadas): void {
    // Una misión sin URL guardadas también deja línea. Callar aquí fue el
    // primer error de esta clase: el silencio se lee como «todo bien».
    if ($revision[self::SIN_REGISTRO]) {
      $this->logger->warning('citas_sin_comprobar: la misión @sesion cita @citadas fuente(s) y sus búsquedas son anteriores a que se guardaran las URL. No se puede concluir nada: no hay con qué comparar.', [
        '@sesion' => $sessionId,
        '@citadas' => $citadas,
      ]);

      return;
    }

    $sospechosas = array_merge($revision[self::NO_VISTAS], $revision[self::OTRA_PAGINA]);

    // Cuando todo cuadra también se dice, en nivel informativo. Sin esta línea,
    // «revisó y no encontró nada» y «no llegó a ejecutarse» se veían igual
    // desde fuera: el mismo silencio ambiguo que ya costó un error aquí.
    if ($sospechosas === []) {
      $this->logger->info('citas_respaldadas: en la misión @sesion, las @citadas fuente(s) citadas cuadran (@vistas de una búsqueda, @declaradas anotadas en conversaciones anteriores).', [
        '@sesion' => $sessionId,
        '@citadas' => $citadas,
        '@vistas' => count($revision[self::RESPALDADAS]),
        '@declaradas' => count($revision[self::DECLARADAS]),
      ]);

      return;
    }

    $this->logger->warning('citas_sin_respaldo: en la misión @sesion, de @citadas fuente(s) citadas, @vistas salieron de una búsqueda y @declaradas estaban anotadas de antes; @otras apuntan a un sitio visitado pero a otra página; @ninguna no se vieron en ninguna búsqueda: @urls', [
      '@sesion' => $sessionId,
      '@citadas' => $citadas,
      '@vistas' => count($revision[self::RESPALDADAS]),
      '@declaradas' => count($revision[self::DECLARADAS]),
      '@otras' => count($revision[self::OTRA_PAGINA]),
      '@ninguna' => count($revision[self::NO_VISTAS]),
      '@urls' => implode(' , ', $sospechosas),
    ]);
  }

  /**
   * Las URL que el entregable cita, vengan de donde vengan, sin repetir.
   *
   * Se miran los DOS sitios: las `sources` de cada cuenta, que es lo que se
   * guarda, y los enlaces del Markdown que la persona lee. Mirar solo uno
   * dejaría sin auditar justo lo que el contrato le pide poner en el otro.
   */
  private function citadas(array $payload, string $message): array {
    $urls = [];

    foreach ($payload['accounts'] ?? [] as $cuenta) {
      foreach ((is_array($cuenta) ? $cuenta['sources'] ?? [] : []) as $fuente) {
        $url = is_array($fuente) ? ($fuente['url'] ?? NULL) : NULL;

        if (is_string($url) && $url !== '') {
          $urls[] = $url;
        }
      }
    }

    return array_values(array_unique(array_merge($urls, $this->enlacesDe($message))));
  }

  /**
   * Los enlaces de un texto en Markdown.
   *
   * Vale igual para `[etiqueta](url)` que para una URL suelta, porque el agente
   * puede escribir cualquiera de las dos y las dos acaban siendo un enlace en
   * pantalla. Se recortan los signos que suelen pegarse al final de una frase
   * —punto, coma, punto y coma— porque no son parte de la dirección.
   */
  private function enlacesDe(string $message): array {
    if ($message === '') {
      return [];
    }

    preg_match_all('#https?://[^\s<>()\[\]"\']+#i', $message, $coincidencias);

    return array_map(
      static fn (string $url): string => rtrim($url, '.,;:!?'),
      $coincidencias[0],
    );
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
