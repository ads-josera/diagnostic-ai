<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Contrato del resultado de un diagnóstico.
 */
interface DiagnosticResultInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Sesión que produjo este resultado.
   */
  public function getSession(): ?DiagnosticSessionInterface;

  /**
   * Identificador de la sesión que produjo este resultado.
   */
  public function getSessionId(): ?int;

  /**
   * Agente con el que se hizo.
   *
   * Cadena vacía en lo anterior a que hubiera varios agentes: entonces la
   * pregunta no tenía sentido porque solo había uno.
   */
  public function getAgentId(): string;

  /**
   * Versión del diagnóstico con la que se generó.
   */
  public function getDiagnosticVersion(): string;

  /**
   * Resumen textual del diagnóstico.
   */
  public function getSummary(): string;

  /**
   * Puntuación, si la metodología del cliente contempla alguna.
   */
  public function getScore(): ?int;

  /**
   * Estructura completa del resultado, ya validada.
   *
   * @return array<string, mixed>
   *   La estructura completa del resultado, ya validada.
   */
  public function getPayload(): array;

  /**
   * Sustituye la estructura completa del resultado.
   *
   * @param array<string, mixed> $payload
   *   Estructura completa del resultado, ya validada.
   */
  public function setPayload(array $payload): static;

  /**
   * Banda de madurez global, o cadena vacía si el diagnóstico no la dio.
   */
  public function getMaturity(): string;

  /**
   * Confianza global del diagnóstico, o cadena vacía.
   */
  public function getConfidence(): string;

  /**
   * Puntuación dimensión a dimensión.
   *
   * Se lee del payload y se normaliza aquí para que ningún consumidor tenga
   * que conocer la forma cruda. Los diagnósticos anteriores al 26-08-2026 no
   * la traen y devuelven una lista vacía: su tabla quedó solo en la
   * conversación.
   *
   * @return array<int, array{name: string, score: float, max: float, level: string, confidence: string}>
   *   Una entrada por dimensión, en el orden en que las dio el agente.
   */
  public function getDimensions(): array;

  /**
   * Cuántas cuentas candidatas se cribaron, de las que se pueden auditar.
   *
   * Si el agente declaró más de las que nombró, esta cifra es la sostenible y
   * la declarada queda en getPoolClaimed(). Su metodología pide un «pool
   * auditable», y auditable significa contrastable con la lista de cuentas.
   */
  public function getPoolDeclared(): int;

  /**
   * Lo que el agente declaró antes de cuadrarlo. Cero si no hubo que cuadrar.
   *
   * La diferencia entre esta cifra y la anterior es el dato que dice si el
   * agente está inflando el pool, y por eso no se descarta.
   */
  public function getPoolClaimed(): int;

  /**
   * El Weekly GOLD Pack, cuenta por cuenta.
   *
   * Ordenadas por el ranking que declaró el agente; las que no entran en él,
   * al final. Las que vengan sin nombre se descartan: no se pueden consultar
   * ni seguir de una semana a otra.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por cuenta.
   */
  public function getAccounts(): array;

}
