<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\WordPress;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Recuerda qué versión del plugin de WordPress respondió por última vez.
 *
 * Existe por un fallo que costó una tarde: el módulo empezó a necesitar el dato
 * `started_at` y el WordPress del cliente seguía con una versión anterior que
 * no lo enviaba. Drupal no tenía forma de saberlo. Solo pudo observar que el
 * dato faltaba —y, correctamente, degradar sin romper—, pero nadie se enteró
 * de la causa hasta mirar el registro.
 *
 * Con la versión a la vista, esa situación se ve en el informe de estado ANTES
 * de que provoque un comportamiento raro.
 *
 * Se guarda en el estado y no en configuración a propósito: es un hecho
 * observado del entorno, no una decisión de nadie. La configuración se exporta
 * a Git y se despliega entre entornos; llevarse allí «lo que respondió el
 * WordPress de producción» daría un dato falso en cuanto se importara en otro
 * sitio.
 */
final class PluginVersionTracker {

  /**
   * Clave del estado donde se guarda lo observado.
   */
  private const STATE_KEY = 'sales_leadership_diagnostic.wordpress_plugin';

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Anota la versión que acaba de responder.
   *
   * Solo escribe cuando el valor cambia. Sin esa comprobación, cada consulta
   * de autorización escribiría en el estado, que es una tabla de la base de
   * datos: mucho tráfico de escritura para un dato que cambia dos veces al año.
   *
   * @param mixed $version
   *   Lo que venga en la respuesta. Puede ser NULL si el plugin es anterior a
   *   la versión que empezó a informarlo, y eso también es información.
   */
  public function record(mixed $version): void {
    $observed = is_string($version) && trim($version) !== '' ? trim($version) : NULL;
    $stored = $this->state->get(self::STATE_KEY);

    if (is_array($stored) && ($stored['version'] ?? NULL) === $observed) {
      return;
    }

    $this->state->set(self::STATE_KEY, [
      'version' => $observed,
      'seen_at' => $this->time->getRequestTime(),
    ]);
  }

  /**
   * Última versión observada, o NULL si aún no se sabe.
   *
   * NULL significa dos cosas distintas que no se pueden separar aquí: que
   * todavía no se ha consultado a WordPress, o que respondió un plugin
   * anterior al que empezó a informar su versión. Quien muestre este dato debe
   * decirlo así en lugar de afirmar una de las dos.
   */
  public function getVersion(): ?string {
    $stored = $this->state->get(self::STATE_KEY);

    return is_array($stored) && is_string($stored['version'] ?? NULL)
      ? $stored['version']
      : NULL;
  }

  /**
   * Momento en que se observó, o NULL si nunca.
   */
  public function getSeenAt(): ?int {
    $stored = $this->state->get(self::STATE_KEY);

    return is_array($stored) && is_numeric($stored['seen_at'] ?? NULL)
      ? (int) $stored['seen_at']
      : NULL;
  }

  /**
   * Indica si ya se ha llegado a hablar con WordPress alguna vez.
   *
   * Hace falta distinguirlo de «respondió sin decir su versión», porque las
   * dos cosas dejan la versión en NULL y significan lo contrario: la primera
   * es una instalación recién hecha y la segunda es un plugin desactualizado.
   */
  public function hasObserved(): bool {
    return is_array($this->state->get(self::STATE_KEY));
  }

  /**
   * Indica si la versión observada cumple el mínimo que el módulo necesita.
   *
   * Un plugin que responde SIN versión no cumple: informar de la versión se
   * añadió en la 1.1.0, así que su ausencia identifica precisamente a los
   * anteriores. Era el caso real que este servicio existe para detectar, y
   * darlo por bueno lo habría dejado invisible otra vez.
   *
   * Mientras no se haya consultado nunca sí se da por bueno: no se puede
   * acusar de desactualizado a quien todavía no ha dicho nada, y un informe en
   * rojo tras instalar sería ruido.
   *
   * @param string $minimum
   *   Versión mínima exigida.
   */
  public function meetsMinimum(string $minimum): bool {
    if (!$this->hasObserved()) {
      return TRUE;
    }

    $version = $this->getVersion();

    if ($version === NULL) {
      return FALSE;
    }

    return version_compare($version, $minimum, '>=');
  }

}
