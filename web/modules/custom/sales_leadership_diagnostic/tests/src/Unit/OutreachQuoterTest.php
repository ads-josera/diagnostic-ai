<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Drupal\sales_leadership_diagnostic\Service\Conversation\OutreachQuoter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Cada correo listo para enviar se ve apartado, como cita.
 *
 * El agente a veces lo escribía con «>» y a veces no; en la misión del
 * 02-10-2026 a las 16:15 salieron como párrafos normales, confundidos con el
 * análisis. No se le pide en el contrato porque ese día se midió que una
 * instrucción de formato le quitaba la búsqueda de compradores.
 *
 * El Pack de prueba imita el real: el correo va tras «Copy/paste — email:», con
 * «Asunto:» en negrita en el texto y sin negrita en `outreach_message`.
 */
#[CoversClass(OutreachQuoter::class)]
final class OutreachQuoterTest extends UnitTestCase {

  /**
   * El Pack tal como lo escribió el agente en producción, recortado.
   */
  private const PACK = <<<MD
1) Claro Ecuador — SILVER | PREPARED

- **Buyer verificado:** Alfredo Escobar San Lucas, Presidente Ejecutivo.
- **Copy/paste — email:**

**Asunto:** Despliegue 5G en Ecuador

Alfredo, vi el anuncio de inversión de Claro para ampliar redes y cobertura 5G en el país.

Cuando un despliegue así combina tecnología, nuevas ciudades y compromisos de inversión, suele aparecer una conversación de dirección.

¿Te haría sentido una conversación breve?

- **Siguiente paso:** confirmar en CRM ownership/DNC.
MD;

  /**
   * El mismo correo como lo entrega el agente en `outreach_message`.
   */
  private const CORREO = "Asunto: Despliegue 5G en Ecuador\n\nAlfredo, vi el anuncio de inversión de Claro para ampliar redes y cobertura 5G en el país.\n\nCuando un despliegue así combina tecnología, nuevas ciudades y compromisos de inversión, suele aparecer una conversación de dirección.\n\n¿Te haría sentido una conversación breve?";

  /**
   * El correo entero acaba dentro de UNA cita, y lo demás queda fuera.
   *
   * Una sola: si las líneas en blanco de dentro no llevaran «>», el correo
   * saldría partido en cuatro citas sueltas.
   */
  public function testElCorreoEnteroAcabaDentroDeUnaSolaCita(): void {
    $html = $this->pantalla(self::PACK, self::CORREO);

    $this->assertSame(1, substr_count($html, '<blockquote>'));

    $cita = $this->entre($html, '<blockquote>', '</blockquote>');
    $this->assertStringContainsString('Asunto:', $cita);
    $this->assertStringContainsString('Alfredo, vi el anuncio', $cita);
    $this->assertStringContainsString('conversación breve', $cita);
    $this->assertStringNotContainsString('Siguiente paso', $cita);
    $this->assertStringNotContainsString('Buyer verificado', $cita);
  }

  /**
   * Si el correo no aparece entero, no se toca nada.
   *
   * Es la garantía de que esto no puede estropear un Pack: en el peor caso
   * queda como lo escribió el agente.
   */
  public function testSiElCorreoNoApareceEnteroNoSeTocaNada(): void {
    $reescrito = str_replace('¿Te haría sentido una conversación breve?', '¿Hablamos?', self::CORREO);

    $this->assertSame(self::PACK, $this->citado(self::PACK, $reescrito));
  }

  /**
   * Un correo ya escrito como cita no se cita dos veces.
   */
  public function testUnCorreoQueYaEraCitaNoSeCitaDosVeces(): void {
    $yaCitado = str_replace(
      [
        "**Asunto:** Despliegue",
        "\n\nAlfredo,",
        "\n\nCuando",
        "\n\n¿Te",
      ],
      [
        "> **Asunto:** Despliegue",
        "\n>\n> Alfredo,",
        "\n>\n> Cuando",
        "\n>\n> ¿Te",
      ],
      self::PACK,
    );

    $html = $this->pantalla($yaCitado, self::CORREO);

    $this->assertSame(1, substr_count($html, '<blockquote>'));
    $this->assertStringNotContainsString('&gt;', $html);
  }

  /**
   * Un párrafo que no es del correo, en medio, impide citarlo.
   *
   * Si el correo aparece partido por otra cosa, no es el mismo bloque, y
   * citarlo metería en la cita lo que el agente escribió entre medias.
   */
  public function testUnaLineaAjenaEnMedioImpideCitarlo(): void {
    $partido = str_replace("\n\nCuando un", "\n\nNota interna del análisis.\n\nCuando un", self::PACK);

    $this->assertSame($partido, $this->citado($partido, self::CORREO));
  }

  /**
   * Sin correo, o con uno demasiado corto, no se busca nada.
   *
   * Un «Hola» suelto coincidiría con cualquier línea: se citaría lo que no es.
   */
  public function testSinCorreoNiConUnoMuyCortoSeBuscaAlgo(): void {
    $quoter = new OutreachQuoter();

    $this->assertSame(self::PACK, $quoter->quote(self::PACK, NULL));
    $this->assertSame(self::PACK, $quoter->quote(self::PACK, ['accounts' => [['outreach_message' => '']]]));
    $this->assertSame(self::PACK, $quoter->quote(self::PACK, ['accounts' => [['outreach_message' => 'Alfredo,']]]));
  }

  /**
   * Dos cuentas, dos correos, dos citas.
   */
  public function testDosCuentasDosCitas(): void {
    $segundo = "Asunto: Expansión TÍA\n\nLuis, vi que TÍA está combinando nuevas aperturas con proyectos logísticos.";
    $pack = self::PACK . "\n\n2) Almacenes TÍA\n\n**Asunto:** Expansión TÍA\n\nLuis, vi que TÍA está combinando nuevas aperturas con proyectos logísticos.\n";

    $html = (new MarkdownRenderer())->render((new OutreachQuoter())->quote($pack, [
      'accounts' => [
        ['outreach_message' => self::CORREO],
        ['outreach_message' => $segundo],
      ],
    ]));

    $this->assertSame(2, substr_count($html, '<blockquote>'));
  }

  /**
   * El Markdown citado.
   */
  private function citado(string $pack, string $correo): string {
    return (new OutreachQuoter())->quote($pack, ['accounts' => [['outreach_message' => $correo]]]);
  }

  /**
   * Lo que verá la persona.
   */
  private function pantalla(string $pack, string $correo): string {
    return (new MarkdownRenderer())->render($this->citado($pack, $correo));
  }

  /**
   * El texto entre dos marcas.
   */
  private function entre(string $texto, string $desde, string $hasta): string {
    $inicio = strpos($texto, $desde);
    $fin = strpos($texto, $hasta, (int) $inicio);

    return $inicio === FALSE || $fin === FALSE ? '' : substr($texto, $inicio, $fin - $inicio);
  }

}
