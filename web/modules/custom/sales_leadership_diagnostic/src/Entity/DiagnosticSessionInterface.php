<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\user\EntityOwnerInterface;

/**
 * Contrato de una sesión de diagnóstico.
 */
interface DiagnosticSessionInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Estado actual de la sesión.
   */
  public function getStatus(): DiagnosticStatus;

  /**
   * Cambia el estado y ajusta las marcas temporales asociadas.
   *
   * Pasar a "en curso" fija la fecha de inicio si aún no existía; pasar a un
   * estado final fija la de finalización. Concentrar esa lógica aquí evita que
   * cada punto de llamada tenga que recordar actualizar ambas cosas.
   */
  public function setStatus(DiagnosticStatus $status): static;

  /**
   * Identificador del usuario en WordPress.
   */
  public function getWordPressUserId(): string;

  /**
   * Curso de LearnDash que autorizó este diagnóstico.
   */
  public function getCourseId(): string;

  /**
   * Versión del diagnóstico con la que se ejecutó esta sesión.
   */
  public function getDiagnosticVersion(): string;

  /**
   * Prompt exacto con el que se inició la sesión.
   *
   * Se congela al crearla para que un resultado histórico siga siendo
   * reproducible aunque el prompt configurado cambie después.
   */
  public function getPromptSnapshot(): string;

  /**
   * Huella SHA-256 del prompt congelado.
   */
  public function getPromptHash(): string;

  /**
   * Número de turnos consumidos.
   */
  public function getTurnCount(): int;

  /**
   * Incrementa el contador de turnos.
   */
  public function incrementTurnCount(): static;

  /**
   * Momento en que empezó la conversación, si ya empezó.
   */
  public function getStartedAt(): ?int;

  /**
   * Momento en que terminó, si ya terminó.
   */
  public function getCompletedAt(): ?int;

}
