<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\SearchResult;
use Drupal\sales_leadership_diagnostic\Exception\SearchException;
use Drupal\sales_leadership_diagnostic\Service\Search\SearchProviderInterface;

/**
 * La herramienta con la que el agente busca en internet.
 *
 * Lo que devuelve va con su enlace, siempre. La metodología del cliente
 * prohíbe afirmar sin procedencia, así que un resultado sin fuente le sobra:
 * no lo podría citar ni verificar.
 *
 * Cuando la búsqueda falla, se le CUENTA que falló en lugar de devolverle una
 * lista vacía. Es la diferencia entre «no encontré nada» y «no pude buscar», y
 * llevan a conclusiones opuestas: con la primera declararía una cobertura que
 * no tuvo y podría cerrar una misión como ZERO-GOLD sin haber mirado.
 */
final class WebSearchTool implements ToolInterface {

  /**
   * Nombre con el que lo pide el modelo.
   */
  public const NAME = 'buscar_web';

  public function __construct(
    private readonly SearchProviderInterface $search,
    private readonly LoggerChannelInterface $logger,
    private readonly int $maxResults = 5,
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
      'description' => 'Busca información pública y actual en internet. Devuelve resultados con su enlace, que es la fuente que debes citar. No inventes resultados si esta herramienta falla: declara que no pudiste buscar.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'consulta' => [
            'type' => 'string',
            'description' => 'Qué buscar, en lenguaje natural o con operadores.',
          ],
        ],
        'required' => ['consulta'],
        'additionalProperties' => FALSE,
      ],
      'strict' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $arguments): string {
    $consulta = trim((string) ($arguments['consulta'] ?? ''));

    if ($consulta === '') {
      return (string) json_encode(['error' => 'Falta la consulta.']);
    }

    try {
      $resultados = $this->search->search($consulta, $this->maxResults);
    }
    catch (SearchException $e) {
      // No se propaga: un fallo del buscador no debe cortar el turno. Se le
      // dice al modelo, que sabe qué hacer con eso.
      $this->logger->warning('Búsqueda fallida: @motivo', ['@motivo' => $e->getMessage()]);

      return (string) json_encode([
        'error' => 'No se pudo buscar. NO inventes resultados: declara que esta búsqueda no pudo hacerse.',
      ]);
    }

    if ($resultados === []) {
      return (string) json_encode([
        'resultados' => [],
        'nota' => 'La búsqueda se hizo y no devolvió resultados. Es distinto de no haber podido buscar.',
      ]);
    }

    return (string) json_encode([
      'resultados' => array_map(
        static fn (SearchResult $r): array => $r->toPayload(),
        $resultados,
      ),
    ], JSON_UNESCAPED_UNICODE);
  }

}
