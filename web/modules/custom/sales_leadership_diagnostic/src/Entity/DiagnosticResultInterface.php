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
   */
  public function getPayload(): array;

  /**
   * Sustituye la estructura completa del resultado.
   *
   * @param array<string, mixed> $payload
   */
  public function setPayload(array $payload): static;

}
