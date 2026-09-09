<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageRepository;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enseña en qué se está yendo el dinero del proveedor de IA.
 *
 * Existe porque hasta ahora el consumo solo quedaba en el registro del
 * sistema, una línea suelta por llamada: imposible de sumar y sin los tokens
 * cacheados. Sin ese dato el gasto sale multiplicado —el 07-09-2026, una
 * conversación de dos turnos figuraba como $7.47 MXN y costó $1.03— y un tope
 * calculado sobre esa cifra cortaría el servicio a quien no ha gastado lo que
 * parece.
 *
 * La pantalla insiste en dos separaciones que el recibo no hace y quien opera
 * necesita: lo reutilizado frente a lo que se paga entero, y lo que gasta el
 * estudio del prompt frente a lo que gastan los alumnos.
 *
 * Aquí solo se preparan cifras. La forma la pone `sld-usage.html.twig`: es una
 * pantalla que se mira de un vistazo, y el orden de lectura es parte de lo que
 * hay que diseñar, no un efecto de en qué orden se escribieron las tablas.
 */
final class UsageController extends ControllerBase {

  /**
   * Periodos que se pueden mirar, en días.
   */
  private const PERIODOS = [
    1 => 'Hoy',
    7 => '7 días',
    30 => '30 días',
    365 => 'Un año',
  ];

  public function __construct(
    private readonly AiUsageRepository $usage,
    private readonly SpendGuard $spend,
    private readonly ToolCallRepository $toolCalls,
    private readonly EvidenceLedger $ledger,
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
      $container->get(SpendGuard::class),
      $container->get(ToolCallRepository::class),
      $container->get(EvidenceLedger::class),
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
    $sinCache = $this->usage->costWithoutCacheDiscount($desde);

    return [
      '#theme' => 'sld_usage',
      '#attached' => ['library' => ['sales_leadership_diagnostic/usage']],
      '#cache' => ['max-age' => 0],
      '#budget' => $this->presupuesto(),
      '#periods' => $this->periodos($dias),
      '#has_data' => $total['calls'] > 0,
      '#totals' => $this->totales($total, $sinCache),
      '#searches' => $this->busquedas($desde),
      '#ledger' => $this->evidencia($desde),
      '#agents' => $this->porAgente($desde),
      '#people' => $this->porAlumno($desde),
      '#calls' => $this->ultimas(),
    ];
  }

  /**
   * Cómo va el presupuesto de la instalación.
   *
   * El guardián avisa en el registro al cruzar el umbral, pero quien opera no
   * vive en el registro. Aquí es donde lo va a ver, y por eso este bloque va
   * arriba del todo cuando hay algo que decir.
   *
   * Devuelve NULL cuando no hay tope configurado, que **no** es lo mismo que
   * estar al 0 %: confundirlos en una pantalla lleva a creer que hay un
   * control puesto cuando no lo hay.
   *
   * @return array<string, mixed>|null
   *   Estado del presupuesto, o NULL si nadie ha fijado un tope.
   */
  private function presupuesto(): ?array {
    $estado = $this->spend->statusGlobal();

    if ($estado === NULL) {
      return NULL;
    }

    return [
      'percent' => min(100, $estado['percent']),
      'spent' => $this->dinero($estado['spent']),
      'limit' => $this->dinero($estado['limit']),
      'left' => $this->dinero(max(0.0, $estado['limit'] - $estado['spent'])),
      'blocked' => $estado['blocked'],
      // Se destaca a partir del 80 %, el mismo umbral con el que avisa el
      // guardián. Si la pantalla marcara antes o después que el registro,
      // habría dos verdades sobre lo mismo.
      'warn' => !$estado['blocked'] && $estado['percent'] >= 80,
    ];
  }

  /**
   * Selector de periodo.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por periodo.
   */
  private function periodos(int $actual): array {
    $salida = [];

    foreach (self::PERIODOS as $dias => $etiqueta) {
      $salida[] = [
        'label' => $etiqueta,
        'active' => $dias === $actual,
        'url' => Url::fromRoute('sales_leadership_diagnostic.usage', [], ['query' => ['dias' => $dias]])->toString(),
      ];
    }

    return $salida;
  }

  /**
   * Las cifras de cabecera.
   *
   * @param array<string, mixed> $total
   *   Totales del periodo.
   * @param float $sinCache
   *   Lo que habría costado sin descuento por reutilización.
   *
   * @return array<string, mixed>
   *   Cifras ya formateadas.
   */
  private function totales(array $total, float $sinCache): array {
    $entrada = (int) $total['input'];
    $cacheado = (int) $total['cached'];
    $coste = (float) $total['cost'];
    $ahorro = max(0.0, $sinCache - $coste);

    return [
      'cost' => $this->dinero($coste),
      'cost_mxn' => $this->pesos($coste),
      'without_cache' => $this->dinero($sinCache),
      // Todas las cifras de cabecera lideran en dólares y llevan los pesos
      // debajo. Mezclarlo —una tarjeta en dólares y la de al lado en pesos—
      // obliga a comprobar la unidad antes de comparar dos números que están
      // uno junto al otro, que es exactamente lo que esta pantalla debería
      // ahorrar.
      'saved' => $this->dinero($ahorro),
      'saved_mxn' => $this->pesos($ahorro),
      // Cuánto de lo que habría costado no se pagó. Es la cifra que convierte
      // el ahorro en algo comprobable en lugar de en una afirmación nuestra.
      'saved_pct' => $sinCache > 0 ? (int) round($ahorro / $sinCache * 100) : 0,
      'calls' => number_format((int) $total['calls']),
      'failed' => (int) $total['failed'],
      'input' => number_format($entrada),
      'cached' => number_format($cacheado),
      'full_price' => number_format(max(0, $entrada - $cacheado)),
      'cached_pct' => $entrada > 0 ? (int) round($cacheado / $entrada * 100) : 0,
      'output' => number_format((int) $total['output']),
      'sandbox' => $this->dinero((float) $total['sandbox']),
      'sandbox_pct' => $coste > 0 ? (int) round((float) $total['sandbox'] / $coste * 100) : 0,
    ];
  }

  /**
   * Búsquedas externas del periodo.
   *
   * Se enseñan las DENEGADAS junto a las concedidas, y con el mismo peso. Son
   * la única forma de ver que el gateway está haciendo algo, y de distinguir
   * un agente que no quiso buscar de uno al que no se le dejó. Sin esto, la
   * frase «bloquea físicamente» del §15 de la especificación del cliente sería
   * algo que solo se puede comprobar leyendo el registro del sistema.
   *
   * Devuelve NULL cuando no ha habido ninguna: un panel a cero ocupa sitio y
   * no dice nada.
   *
   * @return array<string, mixed>|null
   *   Las cifras, o NULL si no hubo búsquedas.
   */
  private function busquedas(int $desde): ?array {
    $resumen = $this->toolCalls->summarySince($desde);

    if ($resumen['allowed'] === 0 && $resumen['denied'] === 0) {
      return NULL;
    }

    $motivos = [];

    foreach ($this->toolCalls->denialsSince($desde) as $motivo => $veces) {
      $motivos[] = ['label' => $this->explicarMotivo($motivo), 'count' => $veces];
    }

    return [
      'allowed' => number_format($resumen['allowed']),
      'denied' => number_format($resumen['denied']),
      'results' => number_format($resumen['results']),
      'chars' => number_format($resumen['chars']),
      'reasons' => $motivos,
    ];
  }

  /**
   * La evidencia reutilizable del periodo.
   *
   * Lo que se enseña no son las anotaciones sino **las reutilizaciones**: es la
   * medida directa del ahorro que pide el §10 de la especificación del
   * cliente. Una anotación que nadie vuelve a mirar no ahorró nada; una
   * reutilizada es una búsqueda que no se hizo.
   *
   * @return array<string, mixed>|null
   *   Las cifras, o NULL si no hay nada anotado.
   */
  private function evidencia(int $desde): ?array {
    $resumen = $this->ledger->summarySince($desde);

    if ($resumen['entries'] === 0) {
      return NULL;
    }

    return [
      'entries' => number_format($resumen['entries']),
      'reused' => number_format($resumen['reused']),
      'scopes' => number_format($resumen['scopes']),
    ];
  }

  /**
   * El motivo de una denegación, en algo que se pueda leer.
   */
  private function explicarMotivo(string $motivo): string {
    return match ($motivo) {
      'sin_turno' => (string) $this->t('Sin misión identificada'),
      'presupuesto' => (string) $this->t('Presupuesto agotado'),
      'tope_llamadas_mision' => (string) $this->t('Tope de la misión'),
      'tope_texto_mision' => (string) $this->t('Tope de contenido externo'),
      'tope_llamadas_periodo' => (string) $this->t('Tope del periodo'),
      default => $motivo,
    };
  }

  /**
   * Consumo por agente.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por agente.
   */
  private function porAgente(int $desde): array {
    $filas = [];
    $mayor = 0.0;

    $grupos = $this->usage->groupedBy('agent', $desde);

    foreach ($grupos as $fila) {
      $mayor = max($mayor, (float) $fila['cost']);
    }

    foreach ($grupos as $fila) {
      $agente = $this->agents->get((string) $fila['clave']);
      $coste = (float) $fila['cost'];

      $filas[] = [
        'label' => $agente?->label() ?? ($fila['clave'] !== '' ? $fila['clave'] : $this->t('Sin agente')),
        'calls' => number_format((int) $fila['calls']),
        'input' => number_format((int) $fila['input']),
        'output' => number_format((int) $fila['output']),
        'cost' => $this->dinero($coste),
        // Proporción respecto al que más gasta, para que la comparación se vea
        // sin tener que leer las cifras una por una. Con una sola fila no hay
        // nada que comparar y el riel se lee como un subrayado roto, así que
        // no se dibuja.
        'share' => count($grupos) > 1 && $mayor > 0 ? (int) round($coste / $mayor * 100) : NULL,
      ];
    }

    return $filas;
  }

  /**
   * Quién está gastando más.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por persona.
   */
  private function porAlumno(int $desde): array {
    $filas = [];
    $cuentas = $this->entityTypeManager()->getStorage('user');
    $grupos = $this->usage->groupedBy('uid', $desde);
    $mayor = 0.0;

    foreach ($grupos as $fila) {
      $mayor = max($mayor, (float) $fila['cost']);
    }

    foreach ($grupos as $fila) {
      $cuenta = $cuentas->load((int) $fila['clave']);
      $coste = (float) $fila['cost'];

      $filas[] = [
        'label' => $cuenta?->getAccountName() ?? $this->t('Cuenta borrada (@uid)', ['@uid' => $fila['clave']]),
        'calls' => number_format((int) $fila['calls']),
        'cost' => $this->dinero($coste),
        'share' => count($grupos) > 1 && $mayor > 0 ? (int) round($coste / $mayor * 100) : NULL,
      ];
    }

    return $filas;
  }

  /**
   * Las últimas llamadas, una a una.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por llamada.
   */
  private function ultimas(): array {
    $filas = [];

    foreach ($this->usage->recent() as $fila) {
      $entrada = (int) $fila['input_tokens'];
      $cacheado = (int) $fila['cached_input_tokens'];

      $filas[] = [
        'when' => $this->dates->format((int) $fila['created'], 'short'),
        'purpose' => $fila['purpose'],
        'input' => number_format($entrada),
        'cached_pct' => $entrada > 0 ? (int) round($cacheado / $entrada * 100) : 0,
        'output' => number_format((int) $fila['output_tokens']),
        'seconds' => round((int) $fila['latency_ms'] / 1000, 1),
        'cost' => $this->dinero((float) $fila['cost_usd']),
        'flags' => $this->marcas($fila),
      ];
    }

    return $filas;
  }

  /**
   * Etiquetas de una llamada: ensayo, fallo, reintentos.
   *
   * @param array<string, mixed> $fila
   *   Fila de la tabla.
   *
   * @return array<int, array{label: string, kind: string}>
   *   Las marcas que aplican, con su severidad.
   */
  private function marcas(array $fila): array {
    $marcas = [];

    if ((int) $fila['is_sandbox'] === 1) {
      $marcas[] = ['label' => (string) $this->t('ensayo'), 'kind' => 'neutral'];
    }

    if ((int) $fila['failed'] === 1) {
      $marcas[] = ['label' => (string) $this->t('falló'), 'kind' => 'danger'];
    }

    if ((int) $fila['attempts'] > 1) {
      $marcas[] = [
        'label' => (string) $this->t('@n intentos', ['@n' => $fila['attempts']]),
        'kind' => 'warning',
      ];
    }

    return $marcas;
  }

  /**
   * Formatea dólares, que es lo que cobra el proveedor.
   */
  private function dinero(float $usd): string {
    return '$' . number_format($usd, $usd < 1 ? 4 : 2) . ' USD';
  }

  /**
   * La misma cifra en pesos, solo para leerla.
   *
   * El proveedor cobra en dólares y quien mira esta pantalla razona en pesos.
   * Obligarle a hacer la cuenta a mano cada vez es donde se cometen los
   * errores de un orden de magnitud.
   *
   * El tipo de cambio es de configuración y NO entra en ningún cálculo: lo que
   * se guarda y lo que gobernará los topes son dólares. Si nadie lo mantiene,
   * se omite en lugar de mentir.
   */
  private function pesos(float $usd): string {
    $cambio = (float) $this->config('sales_leadership_diagnostic.settings')->get('openai.exchange_rate');

    return $cambio > 0 ? '$' . number_format($usd * $cambio, 2) . ' MXN' : '';
  }

}
