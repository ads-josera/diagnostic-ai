<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

/**
 * De quién es el turno que se está generando ahora mismo.
 *
 * Existe por la misma tensión de siempre: el gateway tiene que validar
 * «user_id, mission_id» antes de cada llamada a herramienta —lo exige el §6 de
 * la especificación del cliente— y esa identidad no puede viajar en lo que se
 * le manda al proveedor, que está deliberadamente limpio de ella (§31, §43).
 *
 * Con la telemetría se resolvió al revés: el cliente depositaba y quien conocía
 * al alumno recogía después. Aquí no vale, porque el dato hace falta DURANTE la
 * llamada, no al terminarla. Así que quien conduce la conversación lo deja
 * aquí antes de empezar y lo retira al acabar.
 *
 * Vive lo que dura la petición. Si nadie lo pone, el gateway ve un turno sin
 * dueño y **deniega**: es preferible una búsqueda que no ocurre a una que
 * ocurre sin que se sepa a cuenta de quién.
 */
final class CurrentTurn {

  /**
   * Alumno del turno, o NULL si nadie lo ha declarado.
   */
  private ?int $uid = NULL;

  /**
   * Sesión del turno.
   */
  private ?int $sessionId = NULL;

  /**
   * Si es un ensayo del estudio del prompt.
   */
  private bool $sandbox = FALSE;

  /**
   * Agente que conduce el turno.
   *
   * Hace falta aquí por lo mismo que la persona: la búsqueda se concede por
   * agente, y esa decisión hay que tomarla DURANTE la llamada, al construir
   * las herramientas. La sesión lo sabe, pero la sesión no llega al gateway.
   */
  private string $agentId = '';

  /**
   * Declara de quién es el turno que empieza.
   */
  public function begin(int $uid, int $sessionId, bool $sandbox, string $agentId = ''): void {
    $this->uid = $uid;
    $this->sessionId = $sessionId;
    $this->sandbox = $sandbox;
    $this->agentId = $agentId;
  }

  /**
   * Da el turno por terminado.
   *
   * Se llama en un `finally`. Sin esto, un turno que falla dejaría su
   * identidad puesta y la siguiente llamada de la misma petición se atribuiría
   * a quien no fue.
   */
  public function end(): void {
    $this->uid = NULL;
    $this->sessionId = NULL;
    $this->sandbox = FALSE;
    $this->agentId = '';
  }

  /**
   * Si hay un turno declarado.
   */
  public function isSet(): bool {
    return $this->uid !== NULL && $this->sessionId !== NULL;
  }

  /**
   * Alumno del turno. Cero si no hay turno declarado.
   */
  public function uid(): int {
    return $this->uid ?? 0;
  }

  /**
   * Sesión del turno. Cero si no hay turno declarado.
   */
  public function sessionId(): int {
    return $this->sessionId ?? 0;
  }

  /**
   * Si el turno es un ensayo del gestor.
   */
  public function isSandbox(): bool {
    return $this->sandbox;
  }

  /**
   * Agente que conduce el turno. Cadena vacía si no hay turno declarado.
   */
  public function agentId(): string {
    return $this->agentId;
  }

}
