<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Drupal\sales_leadership_diagnostic\Service\Conversation\SourcesAppendix;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Las fuentes de cada cuenta llegan enlazadas a lo que lee la persona.
 *
 * Nace de un dilema medido el 02-10-2026: pedirle al agente que pusiera los
 * enlaces en su texto le hacía dejar de buscar a los compradores (0 de 3
 * misiones), y sin pedírselo solo 5 de 14 fuentes quedaban a la vista. Las
 * pone la plataforma, a partir de `accounts[].sources`.
 *
 * Varias pruebas pasan el resultado por el renderizador real a propósito: lo
 * que importa no es el Markdown que se genera, sino el enlace que acaba en la
 * pantalla.
 */
#[CoversClass(SourcesAppendix::class)]
final class SourcesAppendixTest extends UnitTestCase {

  /**
   * Cada fuente de cada cuenta acaba siendo un enlace, con su dominio a la vista.
   */
  public function testCadaFuenteAcabaSiendoUnEnlaceConSuDominio(): void {
    $html = $this->pantalla([
      'accounts' => [
        [
          'name' => 'Mabe Ecuador',
          'sources' => [
            ['url' => 'https://www.eluniverso.com/mabe-planta', 'label' => 'Nueva planta en Guayaquil', 'published' => '2026-04-12'],
          ],
        ],
        [
          'name' => 'Banco Guayaquil',
          'sources' => [
            ['url' => 'https://www.bancoguayaquil.com/prensa', 'label' => 'Sala de prensa', 'published' => ''],
          ],
        ],
      ],
    ]);

    $this->assertStringContainsString('href="https://www.eluniverso.com/mabe-planta"', $html);
    $this->assertStringContainsString('href="https://www.bancoguayaquil.com/prensa"', $html);
    $this->assertStringContainsString('(eluniverso.com)', $html);
    $this->assertStringContainsString('<strong>Mabe Ecuador</strong>', $html);
    $this->assertStringContainsString('2026-04-12', $html);
  }

  /**
   * Una cuenta sin fuentes no deja un encabezado vacío.
   */
  public function testUnaCuentaSinFuentesNoDejaEncabezadoVacio(): void {
    $markdown = (new SourcesAppendix())->build([
      'accounts' => [
        ['name' => 'Tuti', 'sources' => []],
        ['name' => 'Mabe', 'sources' => [['url' => 'https://a.ec/x', 'label' => 'x']]],
      ],
    ]);

    $this->assertStringNotContainsString('Tuti', $markdown);
    $this->assertStringContainsString('Mabe', $markdown);
  }

  /**
   * Sin fuentes en ninguna cuenta, no se añade nada.
   *
   * El diagnóstico de liderazgo no trae cuentas, y un título «Fuentes» sin nada
   * debajo es peor que no tenerlo.
   */
  public function testSinFuentesNoSeAnadeNada(): void {
    $apendice = new SourcesAppendix();

    $this->assertSame('', $apendice->build(NULL));
    $this->assertSame('', $apendice->build(['summary' => 'Diagnóstico de liderazgo']));
    $this->assertSame('', $apendice->build(['accounts' => [['name' => 'X', 'sources' => []]]]));
  }

  /**
   * Un paréntesis en la URL no parte el enlace por la mitad.
   *
   * Sin codificarlo, el Markdown cerraría el enlace en el primer «)» y dejaría
   * el resto de la dirección como texto suelto: un enlace que lleva a otra
   * página, que es peor que no tenerlo.
   */
  public function testUnParentesisEnLaUrlNoParteElEnlace(): void {
    $html = $this->pantalla([
      'accounts' => [[
        'name' => 'Pronaca',
        'sources' => [['url' => 'https://es.wikipedia.org/wiki/Pronaca_(empresa)', 'label' => 'Ficha']],
      ],
      ],
    ]);

    $this->assertStringContainsString('href="https://es.wikipedia.org/wiki/Pronaca_%28empresa%29"', $html);
  }

  /**
   * Lo que no es una dirección web no se escribe.
   */
  public function testLoQueNoEsUnaDireccionWebNoSeEscribe(): void {
    $markdown = (new SourcesAppendix())->build([
      'accounts' => [[
        'name' => 'X',
        'sources' => [
          ['url' => 'javascript:alert(1)', 'label' => 'malo'],
          ['url' => 'Informe interno 2025', 'label' => 'sin url'],
        ],
      ],
      ],
    ]);

    $this->assertSame('', $markdown);
  }

  /**
   * Una etiqueta con corchetes no se convierte en otro enlace.
   */
  public function testUnaEtiquetaConCorchetesNoRompeElEnlace(): void {
    $html = $this->pantalla([
      'accounts' => [[
        'name' => 'Tía',
        'sources' => [['url' => 'https://expreso.ec/tia', 'label' => 'Tía [402 locales](trampa)']],
      ],
      ],
    ]);

    $this->assertSame(1, substr_count($html, '<a '));
    $this->assertStringContainsString('href="https://expreso.ec/tia"', $html);
  }

  /**
   * La misma URL dos veces en una cuenta se lista una sola vez.
   */
  public function testLaMismaUrlNoSeRepite(): void {
    $markdown = (new SourcesAppendix())->build([
      'accounts' => [[
        'name' => 'Mabe',
        'sources' => [
          ['url' => 'https://a.ec/x', 'label' => 'uno'],
          ['url' => 'https://a.ec/x', 'label' => 'otra vez'],
        ],
      ],
      ],
    ]);

    $this->assertSame(1, substr_count($markdown, 'https://a.ec/x'));
  }

  /**
   * El apéndice tal como lo verá la persona.
   */
  private function pantalla(array $payload): string {
    return (new MarkdownRenderer())->render((new SourcesAppendix())->build($payload));
  }

}
