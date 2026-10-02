<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * La copia en docs/ del contrato es la que se despliega, letra a letra.
 *
 * El contrato vive DENTRO de la configuración del agente —`output_contract` en
 * `config/sync/…agent.*.yml`—, que es lo que `config:import` sube al servidor.
 * La copia de `docs/contratos-de-salida/` existe para poder leerlo y revisarlo
 * sin pelearse con el YAML, y no la lee nadie en tiempo de ejecución.
 *
 * Ahí está la trampa: editar la copia de docs/ se siente como editar el
 * contrato, y no cambia absolutamente nada en producción. Al revés también
 * duele —tocar solo el YAML deja la documentación mintiendo— y ninguna de las
 * dos cosas la nota nadie, porque el sistema sigue funcionando.
 *
 * Esta prueba las ata. Si divergen, falla y dice en qué línea.
 */
final class OutputContractMirrorTest extends UnitTestCase {

  /**
   * Cada agente, con su fichero de configuración y su copia legible.
   */
  public static function agentes(): array {
    return [
      'prospección' => ['prospecting_diagnostic'],
      'diagnóstico de liderazgo' => ['sales_leadership_diagnostic'],
    ];
  }

  /**
   * El contrato desplegado y el documentado son el mismo texto.
   */
  #[DataProvider('agentes')]
  public function testLaCopiaDeDocsEsElContratoQueSeDespliega(string $agente): void {
    $raiz = $this->raizDelProyecto();
    $config = $raiz . '/config/sync/sales_leadership_diagnostic.agent.' . $agente . '.yml';
    $documento = $raiz . '/docs/contratos-de-salida/' . $agente . '.txt';

    // Fuera de este repositorio —un módulo copiado a otro proyecto— no hay
    // config/sync que comparar, y exigirlo sería ruido. Dentro, la ausencia
    // de cualquiera de los dos ficheros SÍ es un fallo: significa que alguien
    // renombró uno y dejó al otro huérfano.
    if ($raiz === NULL) {
      $this->markTestSkipped('Sin config/sync por encima: el módulo no está en su proyecto.');
    }

    $this->assertFileExists($config, 'Falta la configuración del agente ' . $agente . '.');
    $this->assertFileExists($documento, 'Falta la copia legible del contrato de ' . $agente . '.');

    $desplegado = Yaml::parseFile($config)['output_contract'] ?? NULL;
    $this->assertIsString($desplegado, 'El agente ' . $agente . ' no declara output_contract.');

    $this->assertSame(
      $this->normalizado((string) file_get_contents($documento)),
      $this->normalizado($desplegado),
      'La copia de docs/contratos-de-salida/' . $agente . '.txt ya no es el contrato que se despliega. '
      . 'Cambia los DOS, o la documentación miente o el cambio no llega a producción.',
    );
  }

  /**
   * La raíz del proyecto: el primer ancestro que tiene config/sync.
   *
   * Se BUSCA en vez de contar niveles hacia arriba. El primer intento de esta
   * prueba contaba —y contaba mal—, así que las dos comprobaciones salieron
   * «skipped» y la prueba pasó en verde sin comparar nada. Un salto mal puesto
   * es peor que un fallo: no avisa.
   */
  private function raizDelProyecto(): ?string {
    $directorio = __DIR__;

    while ($directorio !== dirname($directorio)) {
      if (is_dir($directorio . '/config/sync')) {
        return $directorio;
      }
      $directorio = dirname($directorio);
    }

    return NULL;
  }

  /**
   * Quita lo que no cambia el sentido: espacios al final y el salto final.
   *
   * Lo demás —tildes, comillas, saltos de línea de en medio— cuenta, porque
   * es el texto literal que lee el modelo.
   */
  private function normalizado(string $texto): string {
    $lineas = preg_split('/\R/', $texto) ?: [];

    return rtrim(implode("\n", array_map('rtrim', $lineas))) . "\n";
  }

}
