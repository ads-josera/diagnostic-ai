<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Service\Knowledge\KnowledgeLibrary;

/**
 * Compone el prompt de un agente (§18).
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

  public function __construct(
    private readonly KnowledgeLibrary $knowledge,
  ) {}

  /**
   * Compone el prompt completo de un agente concreto.
   *
   * Es la ÚNICA forma de componer un prompt publicado. Hubo una variante sin
   * agente, que leía de un objeto de configuración único; se retiró el
   * 26-08-2026, cuando dejó de quedar ninguna pantalla que la usara.
   */
  public function composeFor(DiagnosticAgentInterface $agent): string {
    $parts = array_filter([
      $agent->getSystemPrompt(),
      $this->knowledge->compose($agent),
      $agent->getInstructions(),
      $agent->getOutputContract(),
    ], static fn (string $part): bool => $part !== '');

    return implode("\n\n", $parts);
  }

  /**
   * Compone el prompt de un BORRADOR que se está ensayando.
   *
   * Toma la metodología del borrador y los documentos del agente, en el mismo
   * orden que composeFor(). Que incluya los documentos no es un detalle: el
   * ensayo tiene que probar lo que de verdad va a vivir el alumno, y hasta el
   * 26-08-2026 el estudio componía sin ellos —y encima desde la configuración
   * antigua—, así que ensayaba algo que no existía en ninguna parte.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface $agent
   *   Agente al que pertenece el borrador. Aporta sus documentos.
   * @param array<string, string> $values
   *   Campos del borrador.
   */
  public function composeDraft(DiagnosticAgentInterface $agent, array $values): string {
    $parts = array_filter([
      trim((string) ($values['system_prompt'] ?? '')),
      $this->knowledge->compose($agent),
      trim((string) ($values['instructions'] ?? '')),
      trim((string) ($values['output_contract'] ?? '')),
    ], static fn (string $part): bool => $part !== '');

    return implode("\n\n", $parts);
  }

  /**
   * Huella del prompt, para detectar deriva entre sesiones de igual versión.
   */
  public function hash(string $prompt): string {
    return hash('sha256', $prompt);
  }

}
