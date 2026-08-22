<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\WordPress;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Cliente HTTP hacia el puente instalado en WordPress.
 *
 * Es la única clase del módulo que habla con WordPress. Ningún controller ni
 * servicio de negocio hace peticiones HTTP por su cuenta (§8).
 *
 * Todo fallo se traduce a WordPressUnavailableException. Se hace a propósito:
 * quien llama no debería tener que distinguir un timeout de un 500 ni de un
 * JSON malformado, porque su reacción es la misma en los tres casos. Lo que sí
 * debe distinguir —y por eso nunca se traduce a esta excepción— es una
 * respuesta legítima que diga que el alumno no tiene acceso.
 */
final class WordPressApiClient {

  /**
   * Ruta del endpoint dentro del sitio WordPress.
   */
  private const ACCESS_PATH = '/wp-json/salesbumm-sld/v1/access';

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly HmacSigner $signer,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Pregunta a WordPress si un usuario tiene acceso a un curso.
   *
   * @return array<string, mixed>
   *   Respuesta decodificada.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException
   */
  public function requestAccess(string $externalUserId, string $courseId): array {
    $baseUrl = $this->getBaseUrl();

    if ($baseUrl === '') {
      throw new WordPressUnavailableException('La URL base de WordPress no está configurada.');
    }

    // El cuerpo se serializa UNA vez y esa misma cadena se firma y se envía.
    // Serializarlo dos veces podría producir cadenas distintas y una firma
    // que el otro extremo rechazaría sin explicación útil.
    $body = json_encode([
      'wp_user_id' => (int) $externalUserId,
      'course_id' => (int) $courseId,
    ], JSON_UNESCAPED_SLASHES);

    if ($body === FALSE) {
      throw new WordPressUnavailableException('No se pudo serializar la consulta de autorización.');
    }

    try {
      $response = $this->httpClient->request('POST', $baseUrl . self::ACCESS_PATH, [
        'headers' => $this->signer->buildHeaders($body) + [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'body' => $body,
        'timeout' => $this->getTimeout(),
        'connect_timeout' => $this->getTimeout(),
        // Los errores HTTP se tratan aquí abajo, no como excepciones de Guzzle,
        // para poder distinguir un 401 de configuración de una caída.
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      // No se registra la URL completa ni las cabeceras: contienen la firma.
      $this->logger->error('No se pudo contactar con WordPress: @type.', [
        '@type' => get_class($e),
      ]);

      throw new WordPressUnavailableException('No se pudo contactar con WordPress.', 0, $e);
    }

    return $this->parse($response->getStatusCode(), (string) $response->getBody());
  }

  /**
   * Interpreta la respuesta del endpoint.
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException
   */
  private function parse(int $status, string $body): array {
    if ($status === 401) {
      // Es un fallo de configuración, no una caída, y merece un mensaje
      // propio: sin él, el síntoma es «nadie puede entrar» sin más pistas.
      $this->logger->error('WordPress rechazó la firma de la consulta de autorización. Revise que SLD_WP_HMAC_SECRET sea idéntico en Drupal y en wp-config.php, y que los relojes de ambos servidores no difieran más de cinco minutos.');

      throw new WordPressUnavailableException('WordPress rechazó la firma de la petición.');
    }

    if ($status === 503) {
      $this->logger->warning('WordPress respondió que no puede comprobar el acceso; probablemente LearnDash no esté disponible.');

      throw new WordPressUnavailableException('WordPress no pudo comprobar el acceso.');
    }

    if ($status !== 200) {
      $this->logger->error('WordPress respondió con un código inesperado: @status.', [
        '@status' => $status,
      ]);

      throw new WordPressUnavailableException(sprintf('WordPress respondió con el código %d.', $status));
    }

    $decoded = json_decode($body, TRUE);

    if (!is_array($decoded) || !array_key_exists('has_access', $decoded)) {
      $this->logger->error('La respuesta de WordPress no tiene el formato esperado.');

      throw new WordPressUnavailableException('La respuesta de WordPress no tiene el formato esperado.');
    }

    return $decoded;
  }

  /**
   * URL base de WordPress, sin barra final.
   */
  private function getBaseUrl(): string {
    return rtrim(trim((string) $this->config()->get('wordpress.api_base_url')), '/');
  }

  /**
   * Timeout configurado, con un valor de respaldo razonable.
   */
  private function getTimeout(): int {
    $timeout = (int) $this->config()->get('wordpress.timeout');

    return $timeout > 0 ? $timeout : 10;
  }

  private function config() {
    return $this->configFactory->get('sales_leadership_diagnostic.settings');
  }

}
