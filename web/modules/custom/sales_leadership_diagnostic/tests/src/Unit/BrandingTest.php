<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\sales_leadership_diagnostic\Service\Branding\Branding;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Comprueba que un color de marca no puede inyectar CSS.
 *
 * Los colores de marca acaban dentro de un bloque <style> en la página del
 * alumno. Un valor que cierre la declaración —«red; } body { display:none »—
 * inyectaría reglas arbitrarias en la página.
 *
 * El formulario ya valida, pero la configuración también se cambia por drush y
 * se importa desde archivos, caminos que no pasan por el formulario. Por eso
 * quien escribe la salida vuelve a validar, y por eso se prueba aquí.
 */
#[CoversClass(Branding::class)]
final class BrandingTest extends UnitTestCase {

  /**
   * Un color legítimo se emite como variable CSS.
   */
  public function testEmiteUnColorValido(): void {
    $css = $this->buildCssCon(['color_primary' => '#1f4788']);

    $this->assertStringContainsString('--sld-color-primary: #1f4788;', $css);
  }

  /**
   * También se admite la forma corta de tres dígitos.
   */
  public function testAdmiteLaFormaCorta(): void {
    $css = $this->buildCssCon(['color_primary' => '#abc']);

    $this->assertStringContainsString('--sld-color-primary: #abc;', $css);
  }

  /**
   * Sin nada configurado no se emite bloque alguno.
   *
   * Importa: un <style> vacío en todas las páginas del módulo sería peso
   * muerto en cada respuesta.
   */
  public function testSinColoresNoEmiteNada(): void {
    $this->assertSame('', $this->buildCssCon([]));
  }

  /**
   * Ningún valor malicioso llega a la salida.
   */
  #[DataProvider('proveedorDeValoresMaliciosos')]
  public function testDescartaValoresMaliciosos(string $descripcion, string $valor): void {
    $css = $this->buildCssCon(['color_primary' => $valor]);

    $this->assertStringNotContainsString(
      '}',
      str_replace("}\n", '', $css),
      sprintf('Se ha colado un cierre de bloque con: %s', $descripcion),
    );

    $this->assertStringNotContainsString('--sld-color-primary', $css, $descripcion);
  }

  /**
   * Valores que no deben emitirse nunca.
   *
   * @return array<string, array{string, string}>
   *   Descripción y valor de cada caso.
   */
  public static function proveedorDeValoresMaliciosos(): array {
    return [
      'cierra el bloque' => ['cierra el bloque', 'red; } body { display:none } .x{ color:blue'],
      'expresión url' => ['expresión url', 'url(https://evil.test/x.png)'],
      'nombre de color' => ['nombre de color', 'red'],
      'con punto y coma' => ['con punto y coma', '#fff; color: red'],
      'javascript' => ['javascript', 'javascript:alert(1)'],
      'importante colado' => ['importante colado', '#fff !important; position:fixed'],
      'comentario CSS' => ['comentario CSS', '#fff /* '],
      'salto de línea' => ['salto de línea', "#fff\n  --otra: red"],
      'vacío' => ['vacío', ''],
      'solo la almohadilla' => ['solo la almohadilla', '#'],
      'longitud intermedia' => ['longitud intermedia', '#12345'],
    ];
  }

  /**
   * Un valor inválido no arrastra a los válidos.
   *
   * Es la política de degradar sin romper: la página del alumno no debe
   * quedarse sin marca entera por un ajuste mal puesto.
   */
  public function testUnValorInvalidoNoAnulaElResto(): void {
    $css = $this->buildCssCon([
      'color_primary' => 'rojo corporativo',
      'color_accent' => '#16a085',
    ]);

    $this->assertStringNotContainsString('rojo corporativo', $css);
    $this->assertStringContainsString('--sld-color-success: #16a085;', $css);
  }

  /**
   * Construye el CSS con la configuración dada.
   *
   * @param array<string, string> $valores
   *   Ajustes de color.
   */
  private function buildCssCon(array $valores): string {
    $branding = new Branding(
      $this->getConfigFactoryStub([
        Branding::CONFIG_NAME => $valores + [
          'color_primary' => '',
          'color_primary_hover' => '',
          'color_accent' => '',
        ],
      ]),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
    );

    return $branding->buildCss();
  }

}
