<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Security;

use Drupal\Core\Site\Settings;
use Drupal\sales_leadership_diagnostic\Exception\MissingSecretException;

/**
 * Punto único de acceso a los secretos del módulo.
 *
 * Los secretos viven exclusivamente en variables de entorno, que
 * settings.php traslada a Settings (§29, §49). Ninguna otra clase del módulo
 * debe leer Settings ni getenv() directamente: centralizarlo aquí garantiza
 * que la política de acceso a secretos sea una sola y auditable.
 *
 * Esta clase nunca guarda un secreto en una propiedad. Los lee bajo demanda,
 * de modo que un volcado de la instancia (var_dump, backtrace, mensaje de
 * excepción) no puede exponerlos.
 */
final class SecretsProvider {

  /**
   * Secreto compartido con WordPress para firmar el token SSO (HS256).
   */
  public const JWT_SHARED_SECRET = 'sld_jwt_shared_secret';

  /**
   * Secreto compartido con WordPress para firmar las llamadas de autorización.
   */
  public const WP_HMAC_SECRET = 'sld_wp_hmac_secret';

  /**
   * API key del proveedor de IA.
   */
  public const OPENAI_API_KEY = 'sld_openai_api_key';

  /**
   * Todos los secretos que el módulo necesita para operar por completo.
   *
   * @var string[]
   */
  public const ALL = [
    self::JWT_SHARED_SECRET,
    self::WP_HMAC_SECRET,
    self::OPENAI_API_KEY,
  ];

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * Indica si un secreto está configurado, sin revelar su valor.
   *
   * Es lo que consultan hook_runtime_requirements() y el formulario de
   * ajustes: ambos necesitan saber el estado, jamás el contenido.
   */
  public function has(string $name): bool {
    return $this->read($name) !== '';
  }

  /**
   * Devuelve el valor de un secreto.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\MissingSecretException
   *   Si el secreto no está configurado. Se falla de forma explícita en lugar
   *   de devolver una cadena vacía, porque firmar o verificar con un secreto
   *   vacío es un fallo de seguridad silencioso.
   */
  public function get(string $name): string {
    $value = $this->read($name);
    if ($value === '') {
      throw MissingSecretException::forSetting($name);
    }
    return $value;
  }

  /**
   * Devuelve los nombres de los secretos que faltan por configurar.
   *
   * @return string[]
   */
  public function missing(): array {
    return array_values(array_filter(
      self::ALL,
      fn (string $name): bool => !$this->has($name),
    ));
  }

  /**
   * Lee un secreto de Settings y lo normaliza.
   *
   * El trim() evita el error más común al copiar valores a un fichero de
   * entorno: un salto de línea final que rompe la comparación de firmas sin
   * dar ninguna pista útil en el log.
   */
  private function read(string $name): string {
    $value = $this->settings->get($name, '');
    return is_string($value) ? trim($value) : '';
  }

}
