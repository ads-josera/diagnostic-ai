<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DTO\AiCall;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageRepository;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\PriceList;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba que el consumo se contabiliza como lo cobra el proveedor.
 *
 * Nació de un error medido, no de una hipótesis. Hasta el 07-09-2026 el módulo
 * apuntaba tokens de entrada y salida y nada más. Con la caché funcionando eso
 * multiplica el gasto: una conversación de dos turnos figuraba en $7.47 MXN y
 * había costado $1.03.
 *
 * Importa porque sobre esta cifra se van a montar los topes. Un tope calculado
 * siete veces alto corta el servicio a alumnos que no han gastado lo que
 * parece, y lo hace sin que nadie entienda por qué.
 */
#[CoversClass(AiUsageRepository::class)]
#[CoversClass(PriceList::class)]
final class AiUsageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * Repositorio bajo prueba.
   */
  private AiUsageRepository $consumo;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('sales_leadership_diagnostic', ['sld_ai_usage', 'sld_research_entitlement', 'sld_evidence']);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    $this->config('sales_leadership_diagnostic.settings')
      ->set('openai.prices', [
        ['model' => 'modelo-tarifado', 'input' => 2.0, 'cached_input' => 0.2, 'output' => 12.0],
      ])
      ->save();

    $this->consumo = $this->container->get(AiUsageRepository::class);
  }

  /**
   * Lo cacheado se cobra al 10 %, no al precio completo.
   *
   * Es el fallo que motivó todo esto. Un millón de tokens de entrada de los
   * que 900 000 venían cacheados cuesta $2.18, no $2.00: cobrarlo entero
   * multiplica por diez la parte más grande del recibo.
   */
  public function testLoCacheadoSeCobraAlDiezPorCiento(): void {
    $this->anotar($this->llamada(inputTokens: 1000000, cachedInputTokens: 900000));

    // 100 000 a $2/M = $0.20; 900 000 a $0.20/M = $0.18.
    $this->assertEqualsWithDelta(0.38, $this->totalGlobal(), 0.0001);
  }

  /**
   * Sin nada cacheado, se paga todo a precio completo.
   */
  public function testSinCacheSePagaTodoEntero(): void {
    $this->anotar($this->llamada(inputTokens: 1000000, cachedInputTokens: 0));

    $this->assertEqualsWithDelta(2.0, $this->totalGlobal(), 0.0001);
  }

  /**
   * La salida se cobra aparte y más cara.
   */
  public function testLaSalidaSeCobraAparte(): void {
    $this->anotar($this->llamada(inputTokens: 0, cachedInputTokens: 0, outputTokens: 500000));

    $this->assertEqualsWithDelta(6.0, $this->totalGlobal(), 0.0001);
  }

  /**
   * Una llamada que falló también se anota.
   *
   * El proveedor cobra el intento. Registrar solo los éxitos deja abierta la
   * única forma de gastar sin tope: reintentar.
   */
  public function testUnaLlamadaFallidaTambienSeAnota(): void {
    $this->anotar($this->llamada(inputTokens: 100000, error: 'El proveedor está limitando las peticiones.'));

    $fila = $this->primeraFila();

    $this->assertSame('1', (string) $fila['failed']);
    $this->assertSame('100000', (string) $fila['input_tokens']);
  }

  /**
   * Los reintentos quedan contados.
   */
  public function testLosReintentosQuedanContados(): void {
    $this->anotar($this->llamada(attempts: 3));

    $this->assertSame('3', (string) $this->primeraFila()['attempts']);
  }

  /**
   * Un modelo sin tarifa cuesta cero, no una cifra inventada.
   *
   * Un número aproximado en la pantalla de consumo es peor que un hueco: el
   * hueco se ve y lleva a configurar la tarifa; el número se cree.
   */
  public function testUnModeloSinTarifaNoInventaUnPrecio(): void {
    $this->anotar($this->llamada(model: 'modelo-sin-tarifa', inputTokens: 1000000));

    $this->assertSame(0.0, $this->totalGlobal());
    $this->assertFalse($this->container->get(PriceList::class)->knows('modelo-sin-tarifa'));
  }

  /**
   * Los ensayos del estudio NO cuentan contra el cupo del alumno.
   *
   * Los hace el gestor para probar un prompt. Cargárselos a quien aparece como
   * dueño de la sesión le comería su diagnóstico sin que hubiera hecho nada.
   */
  public function testLosEnsayosNoCuentanContraElCupoDeNadie(): void {
    $this->anotar($this->llamada(inputTokens: 1000000), esEnsayo: TRUE);

    $this->assertSame(0.0, $this->consumo->costForUser(7, 0));
  }

  /**
   * Pero SÍ cuentan en el total, porque la factura no distingue.
   */
  public function testLosEnsayosSiCuentanEnElTotal(): void {
    $this->anotar($this->llamada(inputTokens: 1000000), esEnsayo: TRUE);

    $this->assertGreaterThan(0.0, $this->totalGlobal());
  }

  /**
   * El desglose de una conversación separa lo cacheado.
   *
   * Es lo que permite enseñar de dónde sale el ahorro en lugar de pedir que se
   * crea.
   */
  public function testElDesgloseDeUnaSesionSeparaLoCacheado(): void {
    $this->anotar($this->llamada(inputTokens: 100000, cachedInputTokens: 90000));
    $this->anotar($this->llamada(inputTokens: 100000, cachedInputTokens: 95000));

    $resumen = $this->consumo->summaryForSession(42);

    $this->assertSame(2, $resumen['calls']);
    $this->assertSame(200000, $resumen['input']);
    $this->assertSame(185000, $resumen['cached']);
  }

  /**
   * Se puede decir cuánto habría costado sin el descuento.
   *
   * Sin esa comparación, el ahorro es una afirmación nuestra; con ella, es una
   * resta que cualquiera repite.
   */
  public function testSePuedeComparerConLoQueHabriaCostadoSinCache(): void {
    $this->anotar($this->llamada(inputTokens: 1000000, cachedInputTokens: 900000));

    $this->assertEqualsWithDelta(0.38, $this->totalGlobal(), 0.0001);
    $this->assertEqualsWithDelta(2.0, $this->consumo->costWithoutCacheDiscount(0), 0.0001);
  }

  /**
   * Construye una llamada con valores por defecto razonables.
   */
  private function llamada(
    string $model = 'modelo-tarifado',
    int $inputTokens = 1000,
    int $cachedInputTokens = 0,
    int $outputTokens = 0,
    int $attempts = 1,
    string $error = '',
  ): AiCall {
    return new AiCall(
      model: $model,
      purpose: 'Prueba',
      inputTokens: $inputTokens,
      cachedInputTokens: $cachedInputTokens,
      outputTokens: $outputTokens,
      reasoningTokens: 0,
      latencyMs: 1200,
      attempts: $attempts,
      error: $error,
    );
  }

  /**
   * Anota una llamada atribuida a un alumno y una sesión de prueba.
   */
  private function anotar(AiCall $llamada, bool $esEnsayo = FALSE): void {
    $this->consumo->recordAll([$llamada], 7, 'agente_de_prueba', 42, $esEnsayo);
  }

  /**
   * Coste total registrado, sin acotar por fecha.
   */
  private function totalGlobal(): float {
    return $this->consumo->costGlobal(0);
  }

  /**
   * La primera fila escrita.
   *
   * @return array<string, mixed>
   *   La fila.
   */
  private function primeraFila(): array {
    return $this->container->get('database')
      ->select('sld_ai_usage', 'u')
      ->fields('u')
      ->orderBy('id')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
  }

}
