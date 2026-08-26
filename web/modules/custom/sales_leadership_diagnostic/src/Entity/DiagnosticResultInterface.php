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

}
