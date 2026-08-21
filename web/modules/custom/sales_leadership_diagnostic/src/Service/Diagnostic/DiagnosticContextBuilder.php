<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;

/**
 * Prepara lo que se envía al motor de diagnóstico (§31).
 *
 * Su responsabilidad principal es de contención: decide qué NO se envía. No
 * viaja identidad del alumno, ni identificadores internos, ni metadatos de
 * Drupal. Solo el prompt congelado y la conversación.
 */
final class DiagnosticContextBuilder {

  public function __construct(
    private readonly DiagnosticMessageRepository $messages,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Construye el contexto del turno que va a generarse.
   */
  public function build(DiagnosticSessionInterface $session): DiagnosticContext {
    $maxTurns = $this->getMaxTurns();
    $sessionId = (int) $session->id();

    return new DiagnosticContext(
      // El prompt sale de la sesión, no de la configuración vigente: es lo que
      // hace que un resultado antiguo siga siendo reproducible (§57).
      systemPrompt: $session->getPromptSnapshot(),
      // El historial se acota al tope de turnos. Cada turno reenvía toda la
      // conversación, así que sin límite el coste y la latencia crecen de
      // forma cuadrática con la longitud del diagnóstico.
      history: $this->messages->loadForSession($sessionId, $maxTurns * 2),
      diagnosticVersion: $session->getDiagnosticVersion(),
      turnNumber: $session->getTurnCount() + 1,
      maxTurns: $maxTurns,
    );
  }

  /**
   * Tope de turnos configurado.
   */
  public function getMaxTurns(): int {
    $value = (int) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('security.max_turns');

    return $value > 0 ? $value : 40;
  }

}
