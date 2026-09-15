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
   * Comprueba que el alumno puede iniciar otra sesión hoy con este agente.
   *
   * El límite es por agente desde el 16-09-2026 (decisión de José Raúl). Se
   * fijó en 3 cuando había un solo agente; contado en total, un alumno que
   * hacía una sesión con cada uno y quería repetir una se quedaba bloqueado
   * hasta el día siguiente. El gasto lo acota el tope mensual, no este.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\RateLimitException
   */
  public function assertCanStartDiagnostic(int $uid, string $agentId): void {
    $threshold = max(1, (int) $this->security()['max_diagnostics_per_day']);

    if (!$this->flood->isAllowed(self::EVENT_DIAGNOSTIC, $threshold, self::DAY, $this->startIdentifier($uid, $agentId))) {
      throw new RateLimitException(sprintf(
        'Límite diario de sesiones superado por el usuario %d con el agente %s: %d por día.',
        $uid,
        $agentId,
        $threshold,
      ));
    }
  }

  /**
   * Registra una sesión iniciada con un agente.
   */
  public function registerDiagnostic(int $uid, string $agentId): void {
    $this->flood->register(self::EVENT_DIAGNOSTIC, self::DAY, $this->startIdentifier($uid, $agentId));
  }

  /**
   * Identificador del contador de inicios: alumno y agente.
   *
   * Para reiniciarlo a mano a un alumno: `\Drupal::flood()->clear(
   * 'sales_leadership_diagnostic.start', '<uid>:<agente>')`.
   */
  private function startIdentifier(int $uid, string $agentId): string {
    return $uid . ':' . $agentId;
  }

  /**
   * Sección de seguridad de la configuración.
   *
   * @return array<string, mixed>
   *   Los límites de uso configurados.
   */
  private function security(): array {
    $values = $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('security');

    return is_array($values) ? $values : [];
  }

}
