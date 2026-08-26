<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\sales_leadership_diagnostic\MemoryTopic;
use Drupal\user\EntityOwnerInterface;

/**
 * Un hecho que el sistema recuerda sobre un alumno.
 */
interface StudentMemoryInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Tema del que trata el hecho.
   *
   * @return \Drupal\sales_leadership_diagnostic\MemoryTopic|null
   *   El tema, o NULL si el almacenado ya no pertenece a la lista vigente.
   *   Puede ocurrir si un tema se retira del código habiendo memoria escrita.
   */
  public function getTopic(): ?MemoryTopic;

  /**
   * Qué se recuerda, en prosa.
   */
  public function getContent(): string;

  /**
   * Reemplaza lo que se recuerda.
   */
  public function setContent(string $content): static;

  /**
   * Agente de cuya conversación salió el hecho.
   */
  public function getSourceAgentId(): string;

  /**
   * Sesión de la que salió, si todavía existe.
   */
  public function getSourceSessionId(): ?int;

  /**
   * Deja constancia de dónde viene lo que ahora se recuerda.
   *
   * Va junto porque los dos datos describen el mismo origen: guardar uno sin
   * el otro deja una procedencia a medias que no sirve para explicar nada.
   */
  public function setSource(string $agentId, ?int $sessionId): static;

}
