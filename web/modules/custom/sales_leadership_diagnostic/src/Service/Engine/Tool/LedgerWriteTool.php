<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;

/**
 * Con la que el agente deja anotado lo que averiguó, para no repetirlo.
 *
 * Los campos son los del §7 de la especificación del cliente. No se guarda un
 * resultado de búsqueda sino una **afirmación que el agente usó**, y esa
 * diferencia es la que permite reutilizar sin volver a leer la página: el §9
 * pide «resumir resultados web antes de siguientes turns; no reenviar
 * contenido completo si basta el Evidence Ledger».
 *
 * Sin fuente no se guarda. La metodología del cliente prohíbe afirmar sin
 * procedencia, así que una anotación sin ella no serviría para nada: al
 * recuperarla más tarde parecería comprobable y no lo sería.
 */
final class LedgerWriteTool implements ToolInterface {

  /**
   * Nombre con el que lo pide el modelo.
   */
  public const NAME = 'anotar_evidencia';

  public function __construct(
    private readonly EvidenceLedger $ledger,
    private readonly CurrentTurn $turn,
    private readonly ResearchEntitlementService $entitlements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return self::NAME;
  }

  /**
   * {@inheritdoc}
   */
  public function declaration(): array {
    return [
      'type' => 'function',
      'name' => self::NAME,
      'description' => 'Deja anotada una afirmación que vas a usar, con su fuente, para poder reutilizarla en semanas siguientes sin volver a investigar. Anota solo lo que sostendrías ante el Director: una afirmación por llamada, con la fuente concreta de la que sale. NO anotes suposiciones tuyas como si fueran hechos.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'ambito' => [
            'type' => 'string',
            'description' => 'Empresa, persona o mercado al que aplica. Es por lo que se buscará al reutilizarla.',
          ],
          'afirmacion' => [
            'type' => 'string',
            'description' => 'Qué se afirma, en una frase concreta y comprobable.',
          ],
          'resumen' => [
            'type' => 'string',
            'description' => 'El contexto mínimo para poder reutilizarla sin volver a la fuente. No copies la página.',
          ],
          'fuente' => [
            'type' => 'string',
            'description' => 'La URL o la procedencia exacta. Obligatoria: sin ella no se guarda.',
          ],
          'tipo' => [
            'type' => 'string',
            'enum' => ['HECHO', 'SIGNAL', 'INFERENCE', 'CONTRADICTION'],
            'description' => 'Qué clase de evidencia es. Una inferencia tuya NO es un hecho.',
          ],
          'confianza' => [
            'type' => 'string',
            'enum' => ['ALTA', 'MEDIA', 'BAJA'],
            'description' => 'Cuánto la sostiene la fuente.',
          ],
        ],
        'required' => ['ambito', 'afirmacion', 'resumen', 'fuente', 'tipo', 'confianza'],
        'additionalProperties' => FALSE,
      ],
      'strict' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $arguments): string {
    $guardada = $this->ledger->record(
      $this->turn->uid(),
      $this->entitlements->forUser($this->turn->uid())->missionId,
      $this->turn->sessionId(),
      [
        'scope' => (string) ($arguments['ambito'] ?? ''),
        'claim' => (string) ($arguments['afirmacion'] ?? ''),
        'summary' => (string) ($arguments['resumen'] ?? ''),
        'source' => (string) ($arguments['fuente'] ?? ''),
        'evidence_type' => (string) ($arguments['tipo'] ?? ''),
        'confidence' => (string) ($arguments['confianza'] ?? ''),
      ],
    );

    if (!$guardada) {
      return (string) json_encode([
        'error' => 'No se anotó: faltaba la afirmación o la fuente.',
        'que_hacer' => 'Una evidencia sin procedencia no se puede reutilizar ni comprobar. Vuelve a anotarla con la fuente concreta, o no la anotes.',
      ], JSON_UNESCAPED_UNICODE);
    }

    return (string) json_encode(['anotada' => TRUE], JSON_UNESCAPED_UNICODE);
  }

}
