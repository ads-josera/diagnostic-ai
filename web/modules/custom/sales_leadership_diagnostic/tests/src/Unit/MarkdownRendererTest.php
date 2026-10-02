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
   * Una fuente citada conserva su enlace, que es el entregable.
   *
   * Hasta el 02-10-2026 no: <a> estaba fuera de la lista blanca y el filtro se
   * comía la URL dejando solo el texto. El pack del agente de prospección vive
   * de que cada señal se pueda comprobar, y el cliente lo comparó con uno
   * lleno de fuentes enlazadas. El nuestro pareció palabrería.
   */
  public function testUnaFuenteCitadaConservaSuEnlace(): void {
    $html = $this->renderer->render('[SAP News Center](https://news.sap.com/x)');

    $this->assertStringContainsString('href="https://news.sap.com/x"', $html);
    $this->assertStringContainsString('SAP News Center', $html);
  }

  /**
   * Y se ve a dónde lleva, que es lo que desactiva el engaño.
   *
   * Un enlace es peligroso cuando el texto dice una cosa y el destino es otra.
   * Con el dominio impreso al lado, la persona lo ve antes de pulsar.
   */
  public function testElDominioDelDestinoSiempreSeVe(): void {
    $html = $this->renderer->render('[www.deloitte.com](https://malo.example/robar)');

    $this->assertStringContainsString('(malo.example)', $html);
  }

  /**
   * El «www.» se quita como PREFIJO, no como un puñado de caracteres.
   *
   * `ltrim($host, 'www.')` parecía servir y convertía «wired.com» en
   * «ired.com»: un dominio mal escrito al lado de un enlace es peor que no
   * ponerlo, porque invita a desconfiar de la fuente correcta.
   */
  public function testElDominioNoSeRecortaDeMas(): void {
    $this->assertStringContainsString(
      '(wired.com)',
      $this->renderer->render('[Wired](https://wired.com/a)'),
    );
    $this->assertStringContainsString(
      '(primicias.ec)',
      $this->renderer->render('[Primicias](https://www.primicias.ec/b)'),
    );
  }

  /**
   * Si el texto ya es el dominio, no se repite.
   */
  public function testNoSeRepiteElDominioSiYaEstaEnElTexto(): void {
    $html = $this->renderer->render('[news.sap.com](https://news.sap.com/x)');

    $this->assertStringNotContainsString('(news.sap.com)', $html);
  }

  /**
   * Un enlace que no sea http o https pierde el enlace y conserva el texto.
   *
   * Y no queda una etiqueta vacía: el primer intento comprobaba «<a » con
   * espacio, y cuando el filtro ya había quitado el href la etiqueta era «<a>»
   * sin espacio, se saltaba el saneado y el ancla vacía llegaba a la pantalla.
   */
  #[DataProvider('enlacesQueNoDebenSobrevivir')]
  public function testUnEnlacePeligrosoSoloDejaSuTexto(string $markdown, string $texto): void {
    $html = $this->renderer->render($markdown);

    $this->assertStringNotContainsString('<a', $html);
    $this->assertStringContainsString($texto, $html);
  }

  /**
   * Protocolos que no deben acabar en un enlace.
   *
   * @return array<string, array{string, string}>
   *   Markdown y el texto que debe sobrevivir.
   */
  public static function enlacesQueNoDebenSobrevivir(): array {
    return [
      'javascript' => ['[pulsa](javascript:alert(1))', 'pulsa'],
      'archivo local' => ['[archivo](file:///etc/passwd)', 'archivo'],
      'datos' => ['[imagen](data:text/html;base64,PHNjcmlwdD4=)', 'imagen'],
    ];
  }

  /**
   * Un enlace permitido se abre fuera y sin dejar pasar la página de destino.
   */
  public function testElEnlaceSeAbreFueraSinReferencia(): void {
    $html = $this->renderer->render('[Primicias](https://primicias.ec/x)');

    $this->assertStringContainsString('rel="nofollow noopener noreferrer"', $html);
    $this->assertStringContainsString('target="_blank"', $html);
  }

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
   * Una fuente citada DENTRO de una tabla sigue siendo enlace.
   *
   * Es el sitio donde de verdad van a caer: el Weekly GOLD Pack pone casi todo
   * en tablas —el pool auditable, las señales por cuenta—, y desde el
   * 02-10-2026 el contrato le pide al agente el enlace pegado a la afirmación.
   * Los enlaces se probaron en prosa, que es el caso fácil: una celda pasa por
   * la extensión de tablas antes de pasar por el filtro, y ahí es donde se
   * rompería sin que lo viera ninguna de las otras pruebas.
   */
  public function testUnaFuenteCitadaDentroDeUnaTablaSigueSiendoEnlace(): void {
    $html = $this->renderer->render(
      "| Cuenta | Señal |\n|---|---|\n| Mabe | planta nueva ([Primicias](https://primicias.ec/nota)) |"
    );

    $this->assertStringContainsString('<td>', $html);
    $this->assertStringContainsString('href="https://primicias.ec/nota"', $html);
    $this->assertStringContainsString('primicias.ec', $html);
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
      // Los enlaces http(s) ya NO están aquí: desde el 02-10-2026 se permiten,
      // porque la evidencia del Pack vive de ellos. Lo que los hace seguros no
      // es prohibirlos sino enseñar su destino, y eso lo fijan las pruebas
      // testElDominioDelDestinoSiempreSeVe y la del rel/target.
      // Lo que sigue prohibido es todo lo demás.
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
   * Hasta el 02-10-2026 esta prueba decía lo contrario: que no se emitía
   * NINGÚN enlace, ni siquiera legítimo. Era deliberado y el motivo seguía
   * siendo cierto —un enlace de un modelo es un vector de phishing—, pero el
   * precio resultó mayor que el riesgo: el entregable del agente de
   * prospección ES evidencia verificable, y el filtro se comía la fuente de
   * cada afirmación. El cliente lo comparó con un pack lleno de fuentes
   * enlazadas y el nuestro pareció palabrería.
   *
   * La política no se relajó: cambió de forma. Antes se prohibía el enlace;
   * ahora se obliga a enseñar a dónde va, que es lo que de verdad desactiva el
   * engaño. Quien quiera volver atrás tiene que leer esto primero.
   */
  public function testUnEnlaceLegitimoMuestraSuDestino(): void {
    $html = $this->renderer->render('Visita [nuestra web](https://salesbumm.com) para más.');

    $this->assertStringContainsString('href="https://salesbumm.com"', $html);
    $this->assertStringContainsString('nuestra web', $html);
    $this->assertStringContainsString('(salesbumm.com)', $html, 'El destino tiene que verse.');
  }

  /**
   * Una entrada vacía no produce marcado.
   */
  public function testEntradaVaciaDevuelveCadenaVacia(): void {
    $this->assertSame('', $this->renderer->render(''));
    $this->assertSame('', $this->renderer->render("   \n  "));
  }

}
