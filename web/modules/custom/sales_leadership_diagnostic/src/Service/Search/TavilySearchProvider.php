<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Search;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\SearchResult;
use Drupal\sales_leadership_diagnostic\Exception\SearchException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Busca con Tavily.
 *
 * Se eligió frente a alternativas más baratas por lo que devuelve, no por el
 * precio: GAP no necesita enlaces, necesita LEER para verificar identidad,
 * empleo, cargo y recencia de un Buyer. Un buscador que solo diga dónde mirar
 * obligaría a añadir nuestro propio raspado de páginas.
 *
 * Y frente a la búsqueda integrada del proveedor de IA, un buscador propio es
 * lo único que permite cumplir el §6 de la especificación del cliente:
 * **decidir cuánto texto entra al modelo**, que es donde está la mayor parte
 * del gasto.
 *
 * La clave se lee del entorno del servidor, nunca de la configuración
 * exportable (§43).
 */
final class TavilySearchProvider implements SearchProviderInterface {

  /**
   * Endpoint de búsqueda.
   */
  private const ENDPOINT = 'https://api.tavily.com/search';

  /**
   * Cuántos caracteres de cada resultado se conservan.
   *
   * El texto recuperado es la mayor parte de lo que cuesta una búsqueda, y el
   * modelo no necesita la página entera para decidir si una señal es real.
   * Se recorta aquí y no en el prompt: lo que no entra, no se paga.
   */
  private const EXTRACT_LIMIT = 1200;

  /**
   * Canal de log del módulo.
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly SecretsProvider $secrets,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->secrets->has(SecretsProvider::SEARCH_API_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function search(string $query, int $max): array {
    if (!$this->isAvailable()) {
      throw new SearchException('No hay clave del buscador configurada.');
    }

    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->secrets->get(SecretsProvider::SEARCH_API_KEY),
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'query' => $query,
          'max_results' => max(1, min($max, 20)),
          // Se pide el contenido, que es para lo que se eligió este proveedor.
          'include_raw_content' => FALSE,
          'search_depth' => $this->depth(),
        ],
        'timeout' => $this->timeout(),
        'connect_timeout' => 10,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new SearchException('No se pudo contactar con el buscador.', 503, $e);
    }

    $status = $response->getStatusCode();

    if ($status !== 200) {
      // Se registra el código, nunca el cuerpo: puede llevar la consulta, y la
      // consulta la escribió el modelo con datos de la conversación (§43).
      $this->logger->error('El buscador respondió @status.', ['@status' => $status]);

      throw new SearchException(sprintf('El buscador respondió con el código %d.', $status), $status);
    }

    return $this->parse((string) $response->getBody());
  }

  /**
   * Convierte la respuesta en resultados.
   *
   * @return \Drupal\sales_leadership_diagnostic\DTO\SearchResult[]
   *   Los resultados legibles que trajo.
   */
  private function parse(string $body): array {
    $decoded = json_decode($body, TRUE);

    if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
      throw new SearchException('El buscador devolvió algo con una forma inesperada.');
    }

    $resultados = [];

    foreach ($decoded['results'] as $fila) {
      if (!is_array($fila)) {
        continue;
      }

      $url = trim((string) ($fila['url'] ?? ''));

      // Sin enlace no entra. Un dato sin procedencia no se puede citar ni
      // comprobar, y la metodología del cliente prohíbe afirmarlo.
      if ($url === '') {
        continue;
      }

      $resultados[] = new SearchResult(
        title: trim((string) ($fila['title'] ?? '')),
        url: $url,
        extract: mb_substr(trim((string) ($fila['content'] ?? '')), 0, self::EXTRACT_LIMIT),
        published: trim((string) ($fila['published_date'] ?? '')),
      );
    }

    return $resultados;
  }

  /**
   * Profundidad de la búsqueda: «basic» o «advanced».
   *
   * Configurable porque cuesta distinto: la avanzada consume más créditos por
   * consulta y no siempre compensa.
   */
  private function depth(): string {
    $valor = (string) $this->config()->get('search.depth');

    return $valor === 'advanced' ? 'advanced' : 'basic';
  }

  /**
   * Segundos de espera antes de darse por vencido.
   *
   * Corto a propósito: una búsqueda lenta se come el tiempo del turno, y el
   * agente prefiere declarar que no pudo buscar a dejar al alumno esperando.
   */
  private function timeout(): int {
    $valor = (int) $this->config()->get('search.timeout');

    return $valor > 0 ? $valor : 20;
  }

  /**
   * Configuración del módulo.
   */
  private function config() {
    return $this->configFactory->get('sales_leadership_diagnostic.settings');
  }

}
