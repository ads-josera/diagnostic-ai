<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Security;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\sales_leadership_diagnostic\Exception\RateLimitException;

/**
 * Límites de uso por alumno (§44).
 *
 * Se apoya en el servicio flood de core en lugar de contar en una tabla
 * propia: ya resuelve la ventana deslizante y la limpieza de registros
 * caducados, y está probado.
 *
 * Los límites protegen dos cosas distintas. Frente al abuso, impiden que una
 * cuenta consuma el servicio de forma desmedida. Frente al error propio,
 * acotan el gasto: cada mensaje es una llamada de pago a un proveedor externo,
 * y un bucle en el navegador podría multiplicar la factura en minutos.
 */
final class RateLimiter {

  private const EVENT_MESSAGE = 'sales_leadership_diagnostic.message';
  private const EVENT_DIAGNOSTIC = 'sales_leadership_diagnostic.start';

  /**
   * Ventana del límite diario de diagnósticos, en segundos.
   */
  private const DAY = 86400;

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Comprueba que el alumno puede enviar otro mensaje.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\RateLimitException
   */
  public function assertCanSendMessage(int $uid): void {
    $config = $this->security();
    $threshold = max(1, (int) $config['messages_per_window']);
    $window = max(10, (int) $config['window_seconds']);

    if (!$this->flood->isAllowed(self::EVENT_MESSAGE, $threshold, $window, (string) $uid)) {
      throw new RateLimitException(sprintf(
        'Límite de mensajes superado por el usuario %d: %d en %d segundos.',
        $uid,
        $threshold,
        $window,
      ));
    }
  }

  /**
   * Registra un mensaje enviado.
   *
   * Se registra DESPUÉS de que el turno se complete con éxito. Contar los
   * intentos fallidos penalizaría al alumno por errores del sistema.
   */
  public function registerMessage(int $uid): void {
    $window = max(10, (int) $this->security()['window_seconds']);
    $this->flood->register(self::EVENT_MESSAGE, $window, (string) $uid);
  }

  /**
   * Comprueba que el alumno puede iniciar otro diagnóstico hoy.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\RateLimitException
   */
  public function assertCanStartDiagnostic(int $uid): void {
    $threshold = max(1, (int) $this->security()['max_diagnostics_per_day']);

    if (!$this->flood->isAllowed(self::EVENT_DIAGNOSTIC, $threshold, self::DAY, (string) $uid)) {
      throw new RateLimitException(sprintf(
        'Límite diario de diagnósticos superado por el usuario %d: %d por día.',
        $uid,
        $threshold,
      ));
    }
  }

  /**
   * Registra un diagnóstico iniciado.
   */
  public function registerDiagnostic(int $uid): void {
    $this->flood->register(self::EVENT_DIAGNOSTIC, self::DAY, (string) $uid);
  }

  /**
   * Sección de seguridad de la configuración.
   *
   * @return array<string, mixed>
   */
  private function security(): array {
    $values = $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('security');

    return is_array($values) ? $values : [];
  }

}
