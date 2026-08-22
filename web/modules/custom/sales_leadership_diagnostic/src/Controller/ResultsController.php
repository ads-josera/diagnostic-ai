<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Presenta el resultado de un diagnóstico completado (§35).
 *
 * Igual que en el chat, la comprobación de propiedad no ocurre aquí: la ruta
 * declara el resultado como parámetro de entidad, de modo que el enrutador
 * aplica su handler de acceso antes de invocar este método. Un identificador
 * ajeno devuelve 403 sin ejecutar código del módulo.
 *
 * El contenido procede del motor de IA, así que se trata como no confiable:
 * el resumen pasa por el mismo saneador que los mensajes del chat, y el resto
 * lo escapa Twig.
 */
final class ResultsController extends ControllerBase {

  /**
   * Secciones del resultado, en el orden en que se muestran.
   *
   * La estructura definitiva depende de la metodología del cliente (§32). Las
   * secciones que no vengan en el resultado simplemente no se pintan, de modo
   * que una metodología con menos apartados no produce huecos vacíos.
   */
  private const SECTIONS = [
    'strengths' => 'Fortalezas',
    'opportunities' => 'Oportunidades de mejora',
    'recommendations' => 'Recomendaciones',
    'priority_actions' => 'Acciones prioritarias',
  ];

  public function __construct(
    private readonly MarkdownRenderer $markdown,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(MarkdownRenderer::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Título de la página.
   */
  public function title(DiagnosticResultInterface $sld_diagnostic_result): string {
    return (string) $this->t('Resultado de tu diagnóstico');
  }

  /**
   * Renderiza el resultado.
   */
  public function view(DiagnosticResultInterface $sld_diagnostic_result): array {
    $result = $sld_diagnostic_result;
    $payload = $result->getPayload();

    return [
      '#theme' => 'sld_result',
      '#summary' => Markup::create($this->markdown->render($result->getSummary())),
      '#score' => $result->getScore(),
      '#sections' => $this->buildSections($payload),
      '#version' => $result->getDiagnosticVersion(),
      '#created' => $this->dateFormatter->format((int) $result->get('created')->value, 'long'),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/result'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['sld_diagnostic_result:' . $result->id()],
      ],
    ];
  }

  /**
   * Prepara las secciones de lista del resultado.
   *
   * Los elementos se pasan como texto y los escapa Twig. No se interpretan
   * como Markdown: una lista dentro de una lista no aporta nada y sí ampliaría
   * la superficie de marcado generado por el modelo.
   *
   * @param array<string, mixed> $payload
   */
  private function buildSections(array $payload): array {
    $sections = [];

    foreach (self::SECTIONS as $key => $label) {
      $items = $payload[$key] ?? NULL;

      if (!is_array($items) || $items === []) {
        continue;
      }

      $sections[] = [
        'key' => $key,
        'label' => $this->t($label),
        'items' => array_values(array_filter(
          array_map(static fn ($item): string => is_scalar($item) ? trim((string) $item) : '', $items),
          static fn (string $item): bool => $item !== '',
        )),
      ];
    }

    return $sections;
  }

}
