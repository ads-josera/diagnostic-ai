<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Una llamada al proveedor de IA, tal como la vio quien la hizo.
 *
 * Deliberadamente NO lleva identidad: ni alumno, ni sesión, ni agente. La
 * produce OpenAIClient, que no sabe para quién trabaja y no debe saberlo (§31,
 * §43); quien la recoge le añade a quién pertenece.
 *
 * Lleva `attempts` porque el reintento se paga. Un 429 que se repite y luego
 * funciona son dos llamadas facturadas, y contar solo la que salió bien deja
 * una vía para gastar sin tope.
 */
final readonly class AiCall {

  /**
   * Construye el apunte de una llamada.
   *
   * @param string $model
   *   Modelo que atendió la llamada.
   * @param string $purpose
   *   Para qué era: conducir un turno, extraer memoria.
   * @param int $inputTokens
   *   Tokens de entrada facturados, cacheados incluidos.
   * @param int $cachedInputTokens
   *   Los que el proveedor ya tenía calculados y cobra al 10 %. Se guardan
   *   aparte porque, si no, el coste sale inflado: el 07-09-2026 nueve
   *   llamadas figuraban como $26 MXN y costaron unos $16.
   * @param int $outputTokens
   *   Tokens generados, razonamiento incluido.
   * @param int $reasoningTokens
   *   La parte del anterior que el modelo gastó pensando antes de escribir.
   * @param int $latencyMs
   *   Lo que tardó, de principio a fin, reintentos incluidos.
   * @param int $attempts
   *   Intentos facturados. Uno si salió a la primera.
   * @param string $error
   *   Vacío si terminó bien. Si no, qué falló, sin contenido de la
   *   conversación.
   */
  public function __construct(
    public string $model,
    public string $purpose,
    public int $inputTokens,
    public int $cachedInputTokens,
    public int $outputTokens,
    public int $reasoningTokens,
    public int $latencyMs,
    public int $attempts,
    public string $error = '',
  ) {}

  /**
   * Tokens de entrada que se pagan a precio completo.
   */
  public function fullPriceInputTokens(): int {
    return max(0, $this->inputTokens - $this->cachedInputTokens);
  }

  /**
   * Si la llamada terminó sin error.
   */
  public function succeeded(): bool {
    return $this->error === '';
  }

}
