<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Telemetry;

use Drupal\sales_leadership_diagnostic\DTO\AiCall;

/**
 * Recoge las llamadas al proveedor hechas durante esta petición.
 *
 * Existe para resolver una tensión concreta. El coste hay que medirlo donde se
 * conoce —dentro del cliente de OpenAI, que es quien ve los tokens y el
 * tiempo—, pero el cliente NO sabe de qué alumno se trata, y no debe saberlo:
 * lo que se le pasa al proveedor está deliberadamente libre de identidad
 * (§31, §43).
 *
 * Así que el cliente deposita aquí lo que sabe y quien conduce la conversación
 * —que sí conoce alumno, agente y sesión— lo recoge y lo guarda. Nadie tiene
 * que saber más de lo que le toca.
 *
 * Vive solo lo que dura la petición. Si nadie lo vacía, se pierde: por eso el
 * vaciado va en un `finally` y no detrás de un `return`.
 */
final class AiUsageCollector {

  /**
   * Llamadas registradas y todavía no recogidas.
   *
   * @var \Drupal\sales_leadership_diagnostic\DTO\AiCall[]
   */
  private array $calls = [];

  /**
   * Anota una llamada.
   */
  public function record(AiCall $call): void {
    $this->calls[] = $call;
  }

  /**
   * Devuelve lo acumulado y lo deja vacío.
   *
   * Vacía a propósito: si dos turnos de la misma petición leyeran la misma
   * lista, el consumo del primero se contaría dos veces.
   *
   * @return \Drupal\sales_leadership_diagnostic\DTO\AiCall[]
   *   Las llamadas hechas desde el último vaciado, en orden.
   */
  public function drain(): array {
    $calls = $this->calls;
    $this->calls = [];

    return $calls;
  }

}
