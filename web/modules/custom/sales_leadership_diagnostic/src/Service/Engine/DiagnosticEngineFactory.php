<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\Core\Site\Settings;

/**
 * Decide qué motor de diagnóstico se usa.
 *
 * El motor simulado solo se entrega si está declarado explícitamente en
 * settings.php. En cualquier otro caso se devuelve el proveedor real, y si aún
 * no hay ninguno configurado, uno que falla de forma visible.
 *
 * La alternativa —usar el simulado cuando no haya proveedor real— sería cómoda
 * en desarrollo y peligrosa en producción: un despliegue sin API key entregaría
 * diagnósticos inventados a alumnos reales sin que nada lo delatase. Entre
 * fallar de forma ruidosa y funcionar con datos falsos, el módulo falla (§13).
 */
final class DiagnosticEngineFactory {

  /**
   * Ajuste que habilita el motor simulado.
   */
  public const MOCK_SETTING = 'sld_use_mock_engine';

  public function __construct(
    private readonly Settings $settings,
    private readonly MockDiagnosticEngine $mock,
    private readonly UnavailableDiagnosticEngine $unavailable,
    private readonly OpenAIDiagnosticProvider $openAi,
  ) {}

  /**
   * Devuelve el motor activo.
   */
  public function create(): DiagnosticEngineInterface {
    if ($this->settings->get(self::MOCK_SETTING) === TRUE) {
      return $this->mock;
    }

    // Sin API key no hay proveedor con el que hablar. Se devuelve el motor
    // que falla de forma explícita en lugar de intentar la llamada: el error
    // resultante nombra la causa en vez de ser un 401 del proveedor.
    if (!$this->hasApiKey()) {
      return $this->unavailable;
    }

    return $this->openAi;
  }

  /**
   * Indica si hay credenciales para hablar con el proveedor real.
   */
  private function hasApiKey(): bool {
    return trim((string) $this->settings->get('sld_openai_api_key', '')) !== '';
  }

  /**
   * Indica si el motor activo es el simulado.
   *
   * Lo consulta el informe de estado para avisar al administrador.
   */
  public function isMockActive(): bool {
    return $this->settings->get(self::MOCK_SETTING) === TRUE;
  }

}
