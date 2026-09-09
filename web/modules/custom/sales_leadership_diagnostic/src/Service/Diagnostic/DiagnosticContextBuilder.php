<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchRuntime;
use Drupal\sales_leadership_diagnostic\Service\Research\TurnClassifier;

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
    private readonly ResearchEntitlementService $entitlements,
    private readonly TurnClassifier $classifier,
    private readonly ResearchRuntime $runtime,
    private readonly EvidenceLedger $ledger,
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
      // Qué puede investigar quien está al otro lado, en las palabras del
      // cliente. El bloque no lleva identidad ni dinero, así que puede viajar
      // en lo que se manda al proveedor sin romper §31 ni §43.
      researchRuntime: $this->researchRuntimeFor($session),
    );
  }

  /**
   * El bloque de capacidad de investigación para esta sesión.
   *
   * Devuelve cadena vacía cuando no hay búsqueda encendida: sin herramientas
   * que gobernar, el bloque solo añadiría ruido al prompt y le costaría la
   * caché a los agentes que no investigan.
   */
  private function researchRuntimeFor(DiagnosticSessionInterface $session): string {
    if (!(bool) $this->configFactory->get('sales_leadership_diagnostic.settings')->get('search.enabled')) {
      return '';
    }

    $uid = (int) $session->getOwnerId();
    $entitlement = $this->entitlements->forUser($uid);
    $rechecks = $this->entitlements->maxRechecks();

    return $this->runtime->compose(
      $entitlement,
      $this->classifier->classify($entitlement, $rechecks),
      $rechecks,
      // Decirle que hay ledger cuando está vacío le haría mirar ahí primero
      // para no encontrar nada, y perder un turno en ello.
      $this->ledger->hasAnyFor($uid),
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
