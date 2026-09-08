<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enseña en qué se está yendo el dinero del proveedor de IA.
 *
 * Existe porque hasta ahora el consumo solo quedaba en el registro del
 * sistema, una línea suelta por llamada: imposible de sumar y sin los tokens
 * cacheados. Sin ese dato el gasto sale multiplicado por varias veces —el
 * 07-09-2026, una conversación de dos turnos figuraba como $7.47 MXN y costó
 * $1.03— y un tope calculado sobre esa cifra cortaría el servicio a quien no
 * ha gastado lo que parece.
 *
 * La pantalla insiste en dos separaciones que el recibo no hace y el gestor
 * necesita: lo cacheado frente a lo que se paga entero, y lo que gasta el
 * estudio del prompt frente a lo que gastan los alumnos.
 */
final class UsageController extends ControllerBase {

  /**
   * Periodos que se pueden mirar, en días.
   */
  private const PERIODOS = [1 => 'Hoy', 7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 365 => 'Último año'];

  public function __construct(
    private readonly AiUsageRepository $usage,
    private readonly AgentRegistry $agents,
    private readonly DateFormatterInterface $dates,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AiUsageRepository::class),
      $container->get(AgentRegistry::class),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
    );
  }

  /**
   * Pinta la pantalla de consumo.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function view(): array {
    $dias = (int) ($this->requestStack->getCurrentRequest()?->query->get('dias') ?? 30);
    $dias = isset(self::PERIODOS[$dias]) ? $dias : 30;
    $desde = $this->time->getRequestTime() - $dias * 86400;

    $total = $this->usage->periodSummary($desde);

    return [
      '#attached' => ['library' => ['sales_leadership_diagnostic/studio']],
      'periodos' => $this->periodos($dias),
      'resumen' => $this->resumen($total, $desde),
      'agentes' => $this->porAgente($desde),
      'alumnos' => $this->porAlumno($desde),
      'ultimas' => $this->ultimas(),
    ];
  }

  /**
   * Selector de periodo.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function periodos(int $actual): array {
    $enlaces = [];

    foreach (self::PERIODOS as $dias => $etiqueta) {
      $enlaces[] = $dias === $actual
        ? ['#markup' => '<strong>' . $etiqueta . '</strong>']
        : [
          '#type' => 'link',
          '#title' => $etiqueta,
          '#url' => Url::fromRoute('sales_leadership_diagnostic.usage', [], ['query' => ['dias' => $dias]]),
        ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $enlaces,
      '#attributes' => ['class' => ['sld-usage__periods']],
    ];
  }

  /**
   * Las cifras de cabecera.
   *
   * @param array<string, mixed> $total
   *   Totales del periodo.
   * @param int $desde
   *   Desde cuándo se cuenta.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function resumen(array $total, int $desde): array {
    $entrada = (int) $total['input'];
    $cacheado = (int) $total['cached'];
    $porcentaje = $entrada > 0 ? round($cacheado / $entrada * 100) : 0;

    // Lo que habría costado sin la caché. Se enseña porque es la diferencia
    // entre creer que el producto no es rentable y saber que sí lo es.
    $sinCache = $this->usage->costWithoutCacheDiscount($desde);

    $filas = [
      [new TranslatableMarkup('Coste total'), $this->dinero((float) $total['cost'])],
      [new TranslatableMarkup('De eso, ensayos del estudio'), $this->dinero((float) $total['sandbox'])],
      [new TranslatableMarkup('Llamadas'), number_format((int) $total['calls'])],
      [new TranslatableMarkup('Fallidas (se pagaron igual)'), number_format((int) $total['failed'])],
      [new TranslatableMarkup('Tokens de entrada'), number_format($entrada)],
      [
        new TranslatableMarkup('De ellos, reutilizados por el proveedor'),
        number_format($cacheado) . ' (' . $porcentaje . ' %)',
      ],
      [new TranslatableMarkup('Tokens de salida'), number_format((int) $total['output'])],
      [
        new TranslatableMarkup('Sin el descuento por reutilización habría costado'),
        $this->dinero($sinCache),
      ],
    ];

    return [
      '#type' => 'table',
      '#header' => [new TranslatableMarkup('Concepto'), new TranslatableMarkup('Periodo')],
      '#rows' => $filas,
      '#empty' => new TranslatableMarkup('Todavía no hay consumo registrado.'),
      '#caption' => new TranslatableMarkup('Resumen'),
    ];
  }

  /**
   * Consumo por agente.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function porAgente(int $desde): array {
    $filas = [];

    foreach ($this->usage->groupedBy('agent', $desde) as $fila) {
      $agente = $this->agents->get((string) $fila['clave']);
      $filas[] = [
        $agente?->label() ?? ($fila['clave'] !== '' ? $fila['clave'] : new TranslatableMarkup('Sin agente')),
        number_format((int) $fila['calls']),
        number_format((int) $fila['input']),
        number_format((int) $fila['output']),
        $this->dinero((float) $fila['cost']),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        new TranslatableMarkup('Agente'),
        new TranslatableMarkup('Llamadas'),
        new TranslatableMarkup('Entrada'),
        new TranslatableMarkup('Salida'),
        new TranslatableMarkup('Coste'),
      ],
      '#rows' => $filas,
      '#empty' => new TranslatableMarkup('Nada todavía.'),
      '#caption' => new TranslatableMarkup('Por agente'),
    ];
  }

  /**
   * Quién está gastando más.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function porAlumno(int $desde): array {
    $filas = [];
    $cuentas = $this->entityTypeManager()->getStorage('user');

    foreach ($this->usage->groupedBy('uid', $desde) as $fila) {
      $cuenta = $cuentas->load((int) $fila['clave']);
      $filas[] = [
        $cuenta?->getAccountName() ?? new TranslatableMarkup('Cuenta borrada (@uid)', ['@uid' => $fila['clave']]),
        number_format((int) $fila['calls']),
        $this->dinero((float) $fila['cost']),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        new TranslatableMarkup('Persona'),
        new TranslatableMarkup('Llamadas'),
        new TranslatableMarkup('Coste'),
      ],
      '#rows' => $filas,
      '#empty' => new TranslatableMarkup('Nada todavía.'),
      '#caption' => new TranslatableMarkup('Quién consume'),
    ];
  }

  /**
   * Las últimas llamadas, una a una.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function ultimas(): array {
    $filas = [];

    foreach ($this->usage->recent() as $fila) {
      $entrada = (int) $fila['input_tokens'];
      $cacheado = (int) $fila['cached_input_tokens'];

      $filas[] = [
        $this->dates->format((int) $fila['created'], 'short'),
        $fila['purpose'],
        number_format($entrada) . ($entrada > 0 ? ' (' . round($cacheado / $entrada * 100) . ' % reutilizado)' : ''),
        number_format((int) $fila['output_tokens']),
        round((int) $fila['latency_ms'] / 1000, 1) . ' s',
        $this->dinero((float) $fila['cost_usd']),
        $this->marcas($fila),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        new TranslatableMarkup('Cuándo'),
        new TranslatableMarkup('Para qué'),
        new TranslatableMarkup('Entrada'),
        new TranslatableMarkup('Salida'),
        new TranslatableMarkup('Tardó'),
        new TranslatableMarkup('Coste'),
        new TranslatableMarkup('Notas'),
      ],
      '#rows' => $filas,
      '#empty' => new TranslatableMarkup('Nada todavía.'),
      '#caption' => new TranslatableMarkup('Últimas llamadas'),
    ];
  }

  /**
   * Etiquetas de una llamada: ensayo, fallo, reintentos.
   *
   * @param array<string, mixed> $fila
   *   Fila de la tabla.
   */
  private function marcas(array $fila): string {
    $marcas = [];

    if ((int) $fila['is_sandbox'] === 1) {
      $marcas[] = 'ensayo';
    }

    if ((int) $fila['failed'] === 1) {
      $marcas[] = 'falló';
    }

    if ((int) $fila['attempts'] > 1) {
      $marcas[] = $fila['attempts'] . ' intentos';
    }

    return implode(' · ', $marcas);
  }

  /**
   * Formatea dinero en las dos monedas.
   *
   * El proveedor cobra en dólares y quien mira esta pantalla razona en pesos.
   * Enseñar solo una de las dos obliga a hacer la cuenta a mano cada vez, y es
   * en esa cuenta a mano donde se cometen los errores de un orden de magnitud.
   *
   * El tipo de cambio es de configuración y NO entra en ningún cálculo: lo que
   * se guarda y lo que gobernará los topes son dólares, que es lo que cobra el
   * proveedor. Si nadie lo mantiene, se omite en lugar de mentir.
   */
  private function dinero(float $usd): string {
    $cambio = (float) $this->config('sales_leadership_diagnostic.settings')->get('openai.exchange_rate');

    if ($cambio <= 0) {
      return sprintf('$%.4f USD', $usd);
    }

    return sprintf('$%.4f USD · $%s MXN', $usd, number_format($usd * $cambio, 2));
  }

}
