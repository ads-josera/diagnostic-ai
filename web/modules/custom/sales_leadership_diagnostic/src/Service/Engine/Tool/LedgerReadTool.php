<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;

/**
 * Con la que el agente mira lo que ya sabe, sin gastar una búsqueda.
 *
 * Existe por la frase del §110 de la especificación del cliente: «en follow-ups
 * **recuperar primero el ledger**. Hacer delta research solo cuando una
 * decisión material depende de evidencia faltante o vieja».
 *
 * Y sobre todo por esta otra, del §2: «después de completar, los follow-ups
 * siguen funcionando con evidencia persistida». Por eso esta herramienta se le
 * ofrece **aunque no pueda investigar**: cuando la misión de la semana está
 * cerrada, es lo único que le queda, y sin ella un follow-up se quedaría sin
 * nada que decir.
 *
 * Cada anotación vuelve con su antigüedad y su estado. Que el agente sepa que
 * algo tiene ochenta días es la diferencia entre reutilizar y repetir un error:
 * su prueba T07 es exactamente presentar una señal vieja como un why-now.
 */
final class LedgerReadTool implements ToolInterface {

  /**
   * Nombre con el que lo pide el modelo.
   */
  public const NAME = 'consultar_evidencia';

  public function __construct(
    private readonly EvidenceLedger $ledger,
    private readonly CurrentTurn $turn,
    private readonly int $maxEntries = 20,
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
      'description' => 'Consulta lo que ya se investigó antes sobre una empresa, persona o mercado, con su fuente y su antigüedad. NO gasta búsqueda. Úsala SIEMPRE antes de investigar fuera: si aquí ya está la respuesta, no hace falta buscar. Cada entrada trae su estado: CURRENT sirve tal cual, STALE hay que reverificarlo antes de presentarlo como actual, y CONTRADICTED o SUPERSEDED no deben usarse.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'ambito' => [
            'type' => 'string',
            'description' => 'Empresa, persona o mercado sobre el que preguntas. Déjalo vacío para ver lo más reciente de todo.',
          ],
        ],
        'required' => ['ambito'],
        'additionalProperties' => FALSE,
      ],
      'strict' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $arguments): string {
    $anotaciones = $this->ledger->recall(
      $this->turn->uid(),
      (string) ($arguments['ambito'] ?? ''),
      $this->maxEntries,
    );

    if ($anotaciones === []) {
      return (string) json_encode([
        'evidencia' => [],
        'nota' => 'No hay nada anotado sobre eso. Es distinto de que no exista: nadie lo ha investigado todavía.',
      ], JSON_UNESCAPED_UNICODE);
    }

    // Se marca lo reutilizado: es la medida directa del ahorro que pide el §10,
    // y la única forma de saber si el ledger sirve para algo.
    $this->ledger->markReused(array_map(
      static fn (array $fila): int => (int) $fila['id'],
      $anotaciones,
    ));

    return (string) json_encode([
      'evidencia' => array_map(
        static fn (array $fila): array => [
          'id' => (int) $fila['id'],
          'ambito' => $fila['scope'],
          'afirmacion' => $fila['claim'],
          'resumen' => $fila['summary'],
          'fuente' => $fila['source'],
          'tipo' => $fila['evidence_type'],
          'confianza' => $fila['confidence'],
          'estado' => $fila['status'],
          'dias_desde_observacion' => $fila['age_days'],
        ],
        $anotaciones,
      ),
    ], JSON_UNESCAPED_UNICODE);
  }

}
