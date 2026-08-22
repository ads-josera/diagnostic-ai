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
