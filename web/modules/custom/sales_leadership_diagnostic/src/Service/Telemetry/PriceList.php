<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Telemetry;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sales_leadership_diagnostic\DTO\AiCall;

/**
 * Traduce tokens a dinero.
 *
 * Las tarifas viven en la configuración y NO en el código (§30, mismo criterio
 * que el catálogo de modelos): un cambio de precios del proveedor no puede
 * exigir un despliegue, porque entonces los topes de gasto quedarían
 * calculados sobre tarifas viejas justo cuando más importa que no lo estén.
 *
 * Los tres precios son distintos y el del medio es el que más cambia la
 * cuenta: la entrada cacheada cuesta un 10 % de la normal. Ignorarla no
 * «redondea al alza», multiplica por diez la parte más grande del recibo.
 */
final class PriceList {

  /**
   * Nombre del objeto de configuración.
   */
  private const CONFIG_NAME = 'sales_leadership_diagnostic.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Coste en dólares de una llamada.
   *
   * Devuelve 0.0 si el modelo no tiene tarifa. No se inventa una aproximación:
   * un número inventado en la pantalla de consumo es peor que un hueco, porque
   * el hueco se ve y el número no.
   */
  public function costOf(AiCall $call): float {
    $tarifa = $this->forModel($call->model);

    if ($tarifa === NULL) {
      return 0.0;
    }

    return $call->fullPriceInputTokens() / 1e6 * $tarifa['input']
      + $call->cachedInputTokens / 1e6 * $tarifa['cached_input']
      + $call->outputTokens / 1e6 * $tarifa['output'];
  }

  /**
   * Lo que costaria esa entrada y esa salida SIN descuento por reutilizacion.
   *
   * Sirve para poder ensenar la comparacion, que es el unico modo de que se
   * entienda de donde sale el ahorro.
   */
  public function fullPriceCost(string $model, int $inputTokens, int $outputTokens): float {
    $tarifa = $this->forModel($model);

    if ($tarifa === NULL) {
      return 0.0;
    }

    return $inputTokens / 1e6 * $tarifa['input'] + $outputTokens / 1e6 * $tarifa['output'];
  }

  /**
   * Si hay tarifa para ese modelo.
   *
   * Lo consulta el informe de estado: cobrar sin tarifa no rompe nada, pero
   * deja el consumo a cero y los topes sin poder dispararse nunca.
   */
  public function knows(string $model): bool {
    return $this->forModel($model) !== NULL;
  }

  /**
   * Tarifa de un modelo, en dólares por millón de tokens.
   *
   * @return array{input: float, cached_input: float, output: float}|null
   *   Los tres precios, o NULL si ese modelo no está tarifado.
   */
  private function forModel(string $model): ?array {
    $precios = $this->configFactory->get(self::CONFIG_NAME)->get('openai.prices');

    if (!is_array($precios)) {
      return NULL;
    }

    // Va como LISTA y no como mapa «modelo => tarifa» porque la configuración
    // de Drupal no admite puntos en las claves, y todos los modelos los
    // llevan: `gpt-5.6-terra` reventaría al guardar.
    $tarifa = NULL;

    foreach ($precios as $entrada) {
      if (is_array($entrada) && ($entrada['model'] ?? '') === $model) {
        $tarifa = $entrada;
        break;
      }
    }

    if ($tarifa === NULL) {
      return NULL;
    }

    return [
      'input' => (float) ($tarifa['input'] ?? 0),
      // Si no se declara, se supone igual que la normal. Suponerla gratis
      // haría desaparecer del recibo la mayor parte del gasto.
      'cached_input' => (float) ($tarifa['cached_input'] ?? $tarifa['input'] ?? 0),
      'output' => (float) ($tarifa['output'] ?? 0),
    ];
  }

}
