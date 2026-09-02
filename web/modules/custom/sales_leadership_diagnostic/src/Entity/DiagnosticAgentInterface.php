<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Contrato de un agente de diagnóstico.
 *
 * Existe para que el resto del módulo dependa de esta interfaz y no de la
 * clase: cuando haya que sacar los agentes de configuración —por ejemplo, si
 * algún día los define el propio cliente desde WordPress— bastará con otra
 * implementación.
 */
interface DiagnosticAgentInterface extends ConfigEntityInterface {

  /**
   * Versión de la metodología, que se congela en cada sesión.
   */
  public function getVersion(): string;

  /**
   * Curso de WordPress que concede este agente.
   */
  public function getCourseId(): string;

  /**
   * Descripción interna, para quien administra.
   */
  public function getDescription(): string;

  /**
   * Prompt principal.
   */
  public function getSystemPrompt(): string;

  /**
   * Instrucciones de conducción.
   */
  public function getInstructions(): string;

  /**
   * Contrato de salida.
   */
  public function getOutputContract(): string;

  /**
   * Documentos de conocimiento, por orden.
   *
   * @return int[]
   *   Identificadores de archivo.
   */
  public function getKnowledgeFids(): array;

  /**
   * Fija los documentos de conocimiento.
   *
   * @param int[] $fids
   *   Identificadores de archivo.
   */
  public function setKnowledgeFids(array $fids): static;

  /**
   * Icono de la pantalla de bienvenida.
   */
  public function getWelcomeIconFid(): int;

  /**
   * Texto introductorio del chat.
   */
  public function getWelcomeIntro(): string;

  /**
   * Encabezado de la página de resultado, o cadena vacía si usa el de serie.
   *
   * Existe porque no todos los agentes entregan un diagnóstico. El de
   * prospección cierra con un Weekly GOLD Pack, y encabezar esa página con
   * «Resultado de tu diagnóstico» describe mal lo que se está leyendo.
   */
  public function getResultTitle(): string;

  /**
   * Sugerencias para empezar.
   *
   * @return string[]
   *   Sugerencias ya limpias.
   */
  public function getWelcomeSuggestions(): array;

  /**
   * Orden en los listados.
   */
  public function getWeight(): int;

  /**
   * Si el agente puede ofrecerse a un alumno.
   */
  public function isUsable(): bool;

}
