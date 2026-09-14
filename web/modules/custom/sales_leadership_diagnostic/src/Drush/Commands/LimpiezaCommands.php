<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Drush\Commands;

use Drupal\sales_leadership_diagnostic\Service\Maintenance\TestDataCleaner;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Limpia los datos de prueba antes de abrir el producto a alumnos reales.
 *
 * Por defecto SOLO SIMULA: dice qué borraría y no toca nada. Borrar exige
 * pedirlo con --ejecutar y confirmarlo. La lógica vive en TestDataCleaner;
 * aquí solo se pregunta y se informa.
 */
final class LimpiezaCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly TestDataCleaner $limpiador,
  ) {
    parent::__construct();
  }

  /**
   * Borra conversaciones, informes, consumos y demás rastro de las pruebas.
   *
   * @param array $options
   *   Opciones de la orden.
   */
  #[CLI\Command(name: 'sld:limpiar-pruebas', aliases: ['sld-limpiar'])]
  #[CLI\Option(name: 'ejecutar', description: 'Borra de verdad. Sin esta opción solo se simula.')]
  #[CLI\Option(name: 'con-alumnos', description: 'Borra también los alumnos de prueba (llegados desde WordPress y solo con el rol de alumno).')]
  #[CLI\Option(name: 'conservar', description: 'Alumnos que no se tocan nunca, separados por comas.')]
  #[CLI\Usage(name: 'drush sld:limpiar-pruebas', description: 'Simula: enseña qué se borraría, sin borrar nada.')]
  #[CLI\Usage(name: 'drush sld:limpiar-pruebas --con-alumnos', description: 'Simula incluyendo los alumnos de prueba.')]
  #[CLI\Usage(name: 'drush sld:limpiar-pruebas --ejecutar --con-alumnos', description: 'Borra los datos de uso y los alumnos de prueba, conservando alumno.demo.')]
  public function limpiar(array $options = ['ejecutar' => FALSE, 'con-alumnos' => FALSE, 'conservar' => 'alumno.demo']): int {
    $conAlumnos = (bool) $options['con-alumnos'];
    $conservar = array_values(array_filter(array_map('trim', explode(',', (string) $options['conservar']))));

    $inventario = $this->limpiador->inventario($conAlumnos, $conservar);
    $this->pintar($inventario, $conAlumnos, $conservar);

    if (!$options['ejecutar']) {
      $this->io()->note('Simulación: no se ha borrado nada. Para borrar, repite la orden con --ejecutar.');
      return self::EXIT_SUCCESS;
    }

    if (!$this->io()->confirm('¿Borrar todo lo anterior? No se puede deshacer: haz antes una copia de la base de datos.', FALSE)) {
      $this->io()->warning('Cancelado. No se ha borrado nada.');
      return self::EXIT_SUCCESS;
    }

    $this->limpiador->limpiar($conAlumnos, $conservar);
    $this->io()->success('Datos de prueba borrados. La configuración, los agentes y las cuentas conservadas siguen intactos.');

    return self::EXIT_SUCCESS;
  }

  /**
   * Enseña el inventario en una tabla legible.
   *
   * @param array $inventario
   *   Lo que devuelve TestDataCleaner::inventario().
   * @param bool $conAlumnos
   *   Si se incluyen los alumnos de prueba.
   * @param string[] $conservar
   *   Alumnos que se conservan.
   */
  private function pintar(array $inventario, bool $conAlumnos, array $conservar): void {
    $nombres = [
      'sld_diagnostic_session' => 'Conversaciones (con sus mensajes)',
      'sld_diagnostic_result' => 'Informes',
      'sld_student_memory' => 'Memoria de los alumnos',
      'sld_account' => 'Cuentas de prospección',
      'sld_account_event' => 'Anotaciones de cuentas',
      'sld_ai_usage' => 'Registros de consumo de IA',
      'sld_evidence' => 'Evidencias de investigación',
      'sld_research_entitlement' => 'Cupos de investigación',
      'sld_tool_call' => 'Llamadas a herramientas',
      'sld_diagnostic_message' => 'Mensajes sueltos',
    ];

    $filas = [];
    foreach ($inventario['entidades'] + $inventario['tablas'] as $clave => $cuantos) {
      $filas[] = [$nombres[$clave] ?? $clave, $cuantos];
    }
    $filas[] = ['Avisos de gasto ya enviados', $inventario['estado']];
    $filas[] = ['Turnos pendientes en cola', $inventario['cola']];

    $this->io()->table(['Qué', 'Cuántos'], $filas);

    if ($conAlumnos) {
      $this->io()->text(sprintf(
        'Alumnos de prueba que se borrarían (%d): %s',
        count($inventario['alumnos']),
        $inventario['alumnos'] === [] ? 'ninguno' : implode(', ', $inventario['alumnos']),
      ));
    }
    $this->io()->text('Se conservan siempre: la configuración, los agentes y sus documentos, el administrador, el gestor y ' . ($conservar === [] ? 'ningún alumno' : implode(', ', $conservar)) . '.');
  }

}
