<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Respuesta del motor para un turno, ya validada.
 *
 * Que exista este objeto y no un array suelto es lo que permite que el resto
 * del módulo no sepa nada del formato concreto que devuelve un proveedor: el
 * validador traduce, y de aquí en adelante todo el mundo trabaja con lo mismo.
 */
final readonly class DiagnosticTurn {

  /**
   * Construye el turno que devolvió el motor.
   *
   * @param string $message
   *   Texto conversacional para el alumno, en Markdown.
   * @param bool $completed
   *   Si el diagnóstico ha concluido con este turno.
   * @param array<string, mixed>|null $result
   *   Estructura del resultado final. Solo presente si $completed es TRUE.
   * @param array<string, mixed> $raw
   *   Respuesta completa del motor, para almacenarla junto al mensaje.
   */
  public function __construct(
    public string $message,
    public bool $completed,
    public ?array $result,
    public array $raw,
  ) {}

}
