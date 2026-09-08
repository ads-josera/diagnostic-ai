<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

/**
 * Las herramientas disponibles en un turno.
 *
 * Se construye por turno y no una vez para siempre a propósito: qué
 * herramientas hay depende de a quién y de qué se esté haciendo, y esa
 * decisión es de quien conduce la conversación, no del cliente de IA.
 *
 * El cliente de IA solo sabe pedirle dos cosas: qué declarar y qué hacer
 * cuando el modelo pide algo. No sabe qué herramientas son ni por qué están.
 */
final class ToolBox {

  /**
   * Herramientas por nombre.
   *
   * @var array<string, \Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolInterface>
   */
  private array $tools = [];

  /**
   * Construye la caja del turno.
   *
   * @param \Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolInterface[] $tools
   *   Las herramientas del turno.
   */
  public function __construct(array $tools = []) {
    foreach ($tools as $tool) {
      $this->tools[$tool->name()] = $tool;
    }
  }

  /**
   * Si no hay ninguna.
   *
   * Con la caja vacía no se declara nada al proveedor, ni siquiera una lista
   * vacía: una declaración de herramientas cambia el prefijo del prompt, y con
   * ella se perdería la caché de todos los turnos que no las necesitan.
   */
  public function isEmpty(): bool {
    return $this->tools === [];
  }

  /**
   * Declaraciones para el proveedor.
   *
   * @return array<int, array<string, mixed>>
   *   Una por herramienta.
   */
  public function declarations(): array {
    return array_values(array_map(
      static fn (ToolInterface $tool): array => $tool->declaration(),
      $this->tools,
    ));
  }

  /**
   * Ejecuta la que pidió el modelo.
   *
   * Una herramienta desconocida NO revienta el turno: se le contesta al modelo
   * que no existe. Puede pedir cualquier cosa —lo hace—, y tratar eso como un
   * error del sistema convertiría una alucinación suya en una caída nuestra.
   *
   * @param string $name
   *   Nombre que pidió.
   * @param array<string, mixed> $arguments
   *   Argumentos que pidió, ya decodificados.
   */
  public function run(string $name, array $arguments): string {
    if (!isset($this->tools[$name])) {
      return (string) json_encode([
        'error' => 'Esa herramienta no existe.',
        'disponibles' => array_keys($this->tools),
      ]);
    }

    return $this->tools[$name]->run($arguments);
  }

}
