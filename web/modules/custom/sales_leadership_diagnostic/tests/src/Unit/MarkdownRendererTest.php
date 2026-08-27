<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Comprueba que el texto del agente nunca puede inyectar marcado.
 *
 * Es el test más importante de la suite. El módulo muestra al alumno texto
 * generado por un modelo de lenguaje, y ese texto llega a la página como HTML.
 * Si esta clase falla, un prompt manipulado —o simplemente una alucinación—
 * podría ejecutar JavaScript en la sesión del alumno.
 */
#[CoversClass(MarkdownRenderer::class)]
final class MarkdownRendererTest extends UnitTestCase {

  /**
   * Renderizador bajo prueba.
   *
   * @var \Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer
   */
  private MarkdownRenderer $renderer;

  /**
   * Una tabla del informe se convierte en tabla de verdad.
   *
   * El informe final del cliente trae una de diez filas —la madurez por
   * dimensión— y hasta el 26-08-2026 se pintaba como un párrafo lleno de
   * barras verticales, porque las tablas no son parte de CommonMark sino una
   * extensión que nadie había activado. Su entregable se leía mal.
   */
  public function testUnaTablaSeConvierteEnTabla(): void {
    $html = $this->renderer->render("| Dimensión | Score |\n|---|---|\n| Estrategia | 1/10 |");

    $this->assertStringContainsString('<table>', $html);
    $this->assertStringContainsString('<th>Dimensión</th>', $html);
    $this->assertStringContainsString('<td>Estrategia</td>', $html);
  }

  /**
   * Un encabezado de primer nivel se rebaja, no se pierde.
   *
   * La página ya tiene su «h1». Antes esto se resolvía dejando «h1» fuera de
   * la lista blanca, y el efecto era peor de lo que parecía: el filtro quita
   * la etiqueta y CONSERVA el texto, así que el encabezado quedaba como un
   * párrafo suelto sin jerarquía ninguna.
   */
  public function testUnEncabezadoDePrimerNivelSeRebaja(): void {
    $html = $this->renderer->render("# Diagnóstico Ejecutivo\n\nTexto.");

    $this->assertStringNotContainsString('<h1', $html);
    $this->assertStringContainsString('<h2>Diagnóstico Ejecutivo</h2>', $html);
  }

  /**
   * Los encabezados de sección conservan su nivel.
   */
  public function testLosEncabezadosDeSeccionConservanSuNivel(): void {
    $html = $this->renderer->render("## Madurez por dimensión\n\n### Detalle");

    $this->assertStringContainsString('<h2>Madurez por dimensión</h2>', $html);
    $this->assertStringContainsString('<h3>Detalle</h3>', $html);
  }

  /**
   * Nada peligroso sobrevive, tampoco dentro de una tabla.
   *
   * Admitir tablas amplía la lista blanca, y toda ampliación hay que
   * reprobarla: una celda es un sitio tan bueno como otro para intentar colar
   * marcado. Se prueban los vectores de una vez para que añadir otra etiqueta
   * en el futuro obligue a volver a pasar por aquí.
   *
   * @param string $entrada
   *   Lo que devolvería un modelo comprometido o equivocado.
   */
  #[DataProvider('vectores')]
  public function testNadaPeligrosoSobrevive(string $entrada): void {
    $html = $this->renderer->render($entrada);

    $this->assertDoesNotMatchRegularExpression(
      '/<script|<iframe|<img|<svg|<style|<form|<input|onerror|onclick|onmouseover|javascript:|<a /i',
      $html,
    );
  }

  /**
   * Intentos de colar marcado ejecutable.
   *
   * @return array<string, array{string}>
   *   Cada caso con su entrada.
   */
  public static function vectores(): array {
    return [
      'script suelto' => ['<script>alert(1)</script>'],
      'script en una celda' => ["| a | b |\n|---|---|\n| <script>alert(1)</script> | x |"],
      'imagen con onerror' => ['<img src=x onerror=alert(1)>'],
      'enlace de phishing' => ['[Pulsa aquí](https://malo.example/robar)'],
      'enlace dentro de una celda' => ["| a |\n|---|\n| [ir](https://malo.example) |"],
      'protocolo javascript' => ['[x](javascript:alert(1))'],
      'iframe' => ['<iframe src=https://malo.example></iframe>'],
      'celda con onclick' => ["| <td onclick=alert(1)>x</td> |\n|---|\n| y |"],
      'hoja de estilos' => ['<style>body{display:none}</style>'],
      'html crudo en un encabezado' => ['## <b onmouseover=alert(1)>Título</b>'],
      'svg con script' => ['<svg><script>alert(1)</script></svg>'],
      'formulario que roba credenciales' => ['<form action=https://malo.example><input name=pass></form>'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = new MarkdownRenderer();
  }

  /**
   * El Markdown legítimo se convierte en el HTML esperado.
   */
  public function testConvierteMarkdownLegitimo(): void {
    $html = $this->renderer->render("Hola **José**.\n\n- Uno\n- Dos\n\n### Título\n\nCon `código`.");

    $this->assertStringContainsString('<strong>José</strong>', $html);
    $this->assertStringContainsString('<ul>', $html);
    $this->assertStringContainsString('<li>Uno</li>', $html);
    $this->assertStringContainsString('<h3>', $html);
    $this->assertStringContainsString('<code>código</code>', $html);
  }

  /**
   * Los acentos y la eñe sobreviven a la conversión.
   *
   * Una corrupción de codificación aquí llegaría directa a la pantalla del
   * alumno en un producto que se usa en español.
   */
  public function testPreservaCaracteresAcentuados(): void {
    $html = $this->renderer->render('Diseño de la organización comercial: mañana.');

    $this->assertStringContainsString('Diseño de la organización comercial: mañana.', $html);
  }

  /**
   * Ningún intento de inyección sobrevive.
   */
  #[DataProvider('proveedorDeAtaques')]
  public function testNeutralizaInyecciones(string $descripcion, string $entrada): void {
    $html = $this->renderer->render($entrada);

    $this->assertDoesNotMatchRegularExpression(
      '/<\s*(script|iframe|img|object|embed|style|svg|form|input|a)\b/i',
      $html,
      sprintf('El renderizador dejó pasar una etiqueta peligrosa con: %s', $descripcion),
    );

    $this->assertDoesNotMatchRegularExpression(
      '/\son[a-z]+\s*=/i',
      $html,
      sprintf('El renderizador dejó pasar un manejador de eventos con: %s', $descripcion),
    );

    $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
  }

  /**
   * Casos de inyección conocidos.
   *
   * @return array<string, array{string, string}>
   *   Cada caso, con su descripción y la entrada maliciosa.
   */
  public static function proveedorDeAtaques(): array {
    return [
      'script directo' => ['script directo', 'Texto<script>alert(1)</script>'],
      'imagen con onerror' => ['imagen con onerror', '<img src=x onerror=alert(1)>'],
      'iframe' => ['iframe', '<iframe src="https://evil.test"></iframe>'],
      'enlace javascript' => ['enlace javascript', '[pulsa](javascript:alert(1))'],
      'enlace normal' => ['enlace normal', '[Salesbumm](https://salesbumm.com)'],
      'div con onclick' => ['div con onclick', '<div onclick="robar()">texto</div>'],
      'style con expresion' => ['style con expresion', '<style>body{display:none}</style>'],
      'svg con onload' => ['svg con onload', '<svg onload=alert(1)></svg>'],
      'formulario de phishing' => [
        'formulario de phishing',
        '<form action="https://evil.test"><input name="pass"></form>',
      ],
      'markdown con html incrustado' => ['markdown con html incrustado', "**negrita** y <script>alert(1)</script>"],
    ];
  }

  /**
   * Los enlaces se eliminan incluso siendo legítimos.
   *
   * Es una decisión deliberada, no un descuido: un enlace generado por un
   * modelo es un vector de phishing y un diagnóstico conversacional no
   * necesita emitirlos. El test la fija para que nadie la relaje sin querer.
   */
  public function testNoEmiteEnlacesNiSiquieraLegitimos(): void {
    $html = $this->renderer->render('Visita [nuestra web](https://salesbumm.com) para más.');

    $this->assertStringNotContainsString('<a ', $html);
    $this->assertStringContainsString('nuestra web', $html, 'El texto del enlace debe conservarse.');
  }

  /**
   * Una entrada vacía no produce marcado.
   */
  public function testEntradaVaciaDevuelveCadenaVacia(): void {
    $this->assertSame('', $this->renderer->render(''));
    $this->assertSame('', $this->renderer->render("   \n  "));
  }

}
