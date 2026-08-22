<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Security;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\SsoIdentity;
use Drupal\sales_leadership_diagnostic\Exception\InvalidTokenException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Valida el token de acceso que emite WordPress (§10).
 *
 * La verificación de un JWT es la parte donde están los fallos históricos de
 * este formato, así que se delega en una librería auditada y se fija el
 * algoritmo en la propia clave. Pasar HS256 en el objeto Key impide el ataque
 * de confusión de algoritmos: un token que declare "alg": "none" o "RS256" se
 * rechaza porque no coincide con lo que se espera, no porque se inspeccione su
 * cabecera.
 *
 * Después de la firma se comprueban emisor y audiencia. Sin esa comprobación,
 * un token legítimo emitido para otro destino serviría aquí.
 */
final class SsoTokenValidator {

  /**
   * Canal de log del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly SecretsProvider $secrets,
    private readonly ReplayGuard $replayGuard,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Valida un token y devuelve la identidad que contiene.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidTokenException
   */
  public function validate(string $token, string $expectedIssuer, string $expectedAudience): SsoIdentity {
    if (trim($token) === '') {
      throw new InvalidTokenException('No se recibió ningún token.');
    }

    $secret = $this->secrets->get(SecretsProvider::JWT_SHARED_SECRET);

    // La tolerancia de reloj es estado global de la librería. Se fija justo
    // antes de decodificar y se restaura después, para no alterar el
    // comportamiento de ningún otro consumidor del proceso.
    $previousLeeway = JWT::$leeway;
    JWT::$leeway = $this->getLeeway();

    try {
      $claims = JWT::decode($token, new Key($secret, 'HS256'));
    }
    catch (ExpiredException $e) {
      // Caso habitual y benigno: alguien tardó demasiado entre pulsar el
      // botón y llegar aquí. Se registra como aviso, no como error.
      $this->logger->warning('Token de acceso caducado.');

      throw new InvalidTokenException('El token de acceso ha caducado.', 0, $e);
    }
    catch (SignatureInvalidException $e) {
      // Esto no es benigno: o los secretos se han desincronizado, o alguien
      // está fabricando tokens.
      $this->logger->error('Firma de token inválida. Verifique que SLD_JWT_SHARED_SECRET es idéntico en Drupal y en wp-config.php.');

      throw new InvalidTokenException('La firma del token no es válida.', 0, $e);
    }
    catch (\Throwable $e) {
      $this->logger->error('Token de acceso malformado: @type.', ['@type' => get_class($e)]);

      throw new InvalidTokenException('El token de acceso no es válido.', 0, $e);
    }
    finally {
      JWT::$leeway = $previousLeeway;
    }

    $this->assertAudience($claims, $expectedIssuer, $expectedAudience);
    $this->assertNotExcessivelyLongLived($claims);
    $this->assertFirstUse($claims);

    $subject = isset($claims->sub) ? trim((string) $claims->sub) : '';

    if ($subject === '') {
      throw new InvalidTokenException('El token no identifica a ningún usuario.');
    }

    return new SsoIdentity(
      externalUserId: $subject,
      email: isset($claims->email) ? trim((string) $claims->email) : '',
      name: isset($claims->name) ? trim((string) $claims->name) : '',
    );
  }

  /**
   * Comprueba emisor y audiencia.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidTokenException
   */
  private function assertAudience(\stdClass $claims, string $expectedIssuer, string $expectedAudience): void {
    $issuer = isset($claims->iss) ? rtrim(trim((string) $claims->iss), '/') : '';
    $audience = isset($claims->aud) ? rtrim(trim((string) $claims->aud), '/') : '';

    if ($expectedIssuer !== '' && !hash_equals(rtrim($expectedIssuer, '/'), $issuer)) {
      $this->logger->error('Token con emisor inesperado.');

      throw new InvalidTokenException('El token procede de un emisor inesperado.');
    }

    if ($expectedAudience !== '' && !hash_equals(rtrim($expectedAudience, '/'), $audience)) {
      // Un token emitido para otro Drupal no debe servir en este, aunque la
      // firma sea correcta: son sistemas distintos con datos distintos.
      $this->logger->error('Token dirigido a otra audiencia.');

      throw new InvalidTokenException('El token no está dirigido a este sitio.');
    }
  }

  /**
   * Rechaza tokens con una vigencia mayor de la admitida.
   *
   * La librería comprueba que no haya caducado, pero no cuánto se le concedió
   * de vida. Sin esta comprobación, quien emite podría fabricar un token de
   * un año y este sitio lo aceptaría: el diseño depende de que la ventana sea
   * de segundos, no de que WordPress se porte bien.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidTokenException
   */
  private function assertNotExcessivelyLongLived(\stdClass $claims): void {
    $issuedAt = isset($claims->iat) ? (int) $claims->iat : 0;
    $expiresAt = isset($claims->exp) ? (int) $claims->exp : 0;

    if ($issuedAt <= 0 || $expiresAt <= 0) {
      throw new InvalidTokenException('El token no declara su vigencia.');
    }

    $maxTtl = $this->getMaxTtl();

    if (($expiresAt - $issuedAt) > $maxTtl) {
      $this->logger->error('Token con una vigencia de @ttl s, superior al máximo admitido de @max s.', [
        '@ttl' => $expiresAt - $issuedAt,
        '@max' => $maxTtl,
      ]);

      throw new InvalidTokenException('El token declara una vigencia excesiva.');
    }
  }

  /**
   * Consume el identificador del token para que no pueda reutilizarse.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidTokenException
   */
  private function assertFirstUse(\stdClass $claims): void {
    $tokenId = isset($claims->jti) ? trim((string) $claims->jti) : '';
    $expiresAt = isset($claims->exp) ? (int) $claims->exp : 0;

    // Se recuerda hasta que el token caduque por sí solo, más el margen de
    // reloj: a partir de ahí `exp` ya lo rechaza y recordarlo no aporta nada.
    $lifetime = max(1, $expiresAt - $this->time->getRequestTime() + $this->getLeeway());

    if (!$this->replayGuard->consume($tokenId, $lifetime)) {
      // Un token repetido casi nunca es un accidente del usuario: lo normal es
      // que alguien haya recuperado la URL de un historial o de un registro.
      $this->logger->warning('Intento de reutilizar un token de acceso ya consumido.');

      throw new InvalidTokenException('El token de acceso ya se ha utilizado.');
    }
  }

  /**
   * Tolerancia de desfase de reloj configurada.
   */
  private function getLeeway(): int {
    $value = (int) $this->config()->get('security.sso_token_leeway');

    return max(0, min($value, 120));
  }

  /**
   * Vigencia máxima admitida para un token.
   */
  private function getMaxTtl(): int {
    $value = (int) $this->config()->get('security.sso_token_ttl');

    return $value > 0 ? $value : 90;
  }

  /**
   * Configuración del módulo.
   */
  private function config() {
    return $this->configFactory->get('sales_leadership_diagnostic.settings');
  }

}
