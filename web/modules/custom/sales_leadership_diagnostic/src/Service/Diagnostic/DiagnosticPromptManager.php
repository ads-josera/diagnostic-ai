<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Compone el prompt del agente a partir de la configuración (§18).
 *
 * El prompt no se construye en un controller ni se concatena por ahí: vive
 * aquí, en un solo sitio, junto con su versión y su huella. Así, cuando dentro
 * de un año haya que explicar por qué dos diagnósticos de la misma versión
 * dieron resultados distintos, hay un único lugar donde mirar.
 *
 * La metodología, las preguntas y los criterios son del cliente y se usan tal
 * cual (§15). Lo único que añade el módulo es el contrato de salida, que es
 * una necesidad técnica: sin él la respuesta no puede validarse ni almacenarse
 * de forma estructurada.
 */
final class DiagnosticPromptManager {

  private const CONFIG_NAME = 'sales_leadership_diagnostic.diagnostic';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Versión configurada actualmente.
   */
  public function getCurrentVersion(): string {
    return trim((string) $this->config()->get('version'));
  }

  /**
   * Compone el prompt completo con la configuración vigente.
   *
   * Se usa al CREAR una sesión, para congelarlo. Una sesión ya iniciada nunca
   * vuelve a pasar por aquí: usa su propia copia (§57).
   */
  public function compose(): string {
    $config = $this->config();

    $parts = array_filter([
      trim((string) $config->get('system_prompt')),
      trim((string) $config->get('instructions')),
      trim((string) $config->get('output_contract')),
    ], static fn (string $part): bool => $part !== '');

    return implode("\n\n", $parts);
  }

  /**
   * Huella del prompt, para detectar deriva entre sesiones de igual versión.
   */
  public function hash(string $prompt): string {
    return hash('sha256', $prompt);
  }

  /**
   * Configuración del agente de diagnóstico.
   */
  private function config() {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
