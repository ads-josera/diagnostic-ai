<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\WordPress;

use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;

/**
 * Firma las peticiones que Drupal envía a WordPress.
 *
 * El contrato es simétrico al que verifica el plugin del otro lado y no debe
 * cambiarse en uno solo de los dos: la cadena que se firma es
 *
 *   timestamp . "." . nonce . "." . cuerpo
 *
 * Los tres elementos entran en la firma por un motivo distinto. El cuerpo,
 * para que nadie pueda alterar qué usuario o qué curso se consulta. La marca
 * de tiempo, para que una petición capturada caduque. El nonce, para que ni
 * siquiera dentro de esa ventana pueda repetirse.
 */
final class HmacSigner {

  /**
   * Cabeceras del protocolo. Deben coincidir con las del plugin.
   */
  public const HEADER_TIMESTAMP = 'X-SLD-Timestamp';
  public const HEADER_NONCE = 'X-SLD-Nonce';
  public const HEADER_SIGNATURE = 'X-SLD-Signature';

  public function __construct(
    private readonly SecretsProvider $secrets,
  ) {}

  /**
   * Construye las cabeceras firmadas para un cuerpo dado.
   *
   * @param string $body
   *   Cuerpo exacto que se va a enviar. Debe ser el mismo byte a byte: firmar
   *   una cadena y enviar otra produce un 401 imposible de diagnosticar.
   *
   * @return array<string, string>
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\MissingSecretException
   */
  public function buildHeaders(string $body): array {
    $secret = $this->secrets->get(SecretsProvider::WP_HMAC_SECRET);
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));

    return [
      self::HEADER_TIMESTAMP => $timestamp,
      self::HEADER_NONCE => $nonce,
      self::HEADER_SIGNATURE => $this->sign($timestamp, $nonce, $body, $secret),
    ];
  }

  /**
   * Calcula la firma.
   */
  public function sign(string $timestamp, string $nonce, string $body, string $secret): string {
    return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
  }

}
