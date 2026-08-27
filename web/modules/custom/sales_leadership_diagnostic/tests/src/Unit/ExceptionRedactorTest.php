<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\sales_leadership_diagnostic\Service\Security\ExceptionRedactor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Comprueba que un mensaje de excepción no arrastre datos hasta el registro.
 *
 * Parte de una idea que no es evidente y que costó descubrir: el mensaje de
 * una excepción es texto NO FIABLE. Se trata como si fuera una etiqueta
 * escrita por un programador, y muchas veces no lo es.
 *
 * El caso real, medido el 26-08-2026: Drupal envuelve los errores de base de
 * datos y su mensaje incluye la consulta completa CON los valores enlazados.
 * Un fallo al guardar un turno escribía el texto del alumno en el registro,
 * que es justo lo que §43 prohíbe.
 *
 * La regla que se fija aquí: quedarse con el diagnóstico y tirar el resto.
 * Perder detalle es aceptable; filtrar contenido no.
 */
#[CoversClass(ExceptionRedactor::class)]
final class ExceptionRedactorTest extends UnitTestCase {

  /**
   * El mensaje real de un error de base de datos pierde los valores.
   *
   * Es el caso que motiva la clase, con el texto exacto que produce Drupal.
   */
  public function testUnErrorDeBaseDeDatosPierdeLosValores(): void {
    $real = <<<'TXT'
    SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'role' at row 1: INSERT INTO "sld_diagnostic_message" ("session_id", "role", "content") VALUES (:db_insert_placeholder_0, :db_insert_placeholder_1, :db_insert_placeholder_2); Array
    (
        [:db_insert_placeholder_2] => SECRETO DEL ALUMNO: facturamos 40 millones
    )
    TXT;

    $redactado = ExceptionRedactor::redactMessage($real);

    $this->assertStringNotContainsString('SECRETO DEL ALUMNO', $redactado);
    $this->assertStringNotContainsString('40 millones', $redactado);
    $this->assertStringNotContainsString('INSERT INTO', $redactado);
    // Y conserva lo que sirve para diagnosticar.
    $this->assertStringContainsString('SQLSTATE[22001]', $redactado);
    $this->assertStringContainsString("column 'role'", $redactado);
  }

  /**
   * Un mensaje normal se deja tal cual.
   *
   * La mayoría de los mensajes del módulo los escribimos nosotros y son
   * seguros. Recortarlos de más haría el registro inútil.
   */
  public function testUnMensajeNormalSeDejaComoEsta(): void {
    $mensaje = 'La sesión 47 no admite mensajes en estado "completed".';

    $this->assertSame($mensaje, ExceptionRedactor::redactMessage($mensaje));
  }

  /**
   * Se corta en cuanto aparece algo que delata datos.
   *
   * @param string $entrada
   *   Mensaje tal como llegaría.
   * @param string $noDebeAparecer
   *   Fragmento que no puede sobrevivir.
   */
  #[DataProvider('mensajesConDatos')]
  public function testSeCortaEnCuantoAparecenDatos(string $entrada, string $noDebeAparecer): void {
    $this->assertStringNotContainsString(
      $noDebeAparecer,
      ExceptionRedactor::redactMessage($entrada),
    );
  }

  /**
   * Mensajes que arrastran contenido por distintas vías.
   *
   * @return array<string, array{string, string}>
   *   Cada caso con su entrada y lo que no debe sobrevivir.
   */
  public static function mensajesConDatos(): array {
    return [
      'consulta de seleccion' => [
        'Error: SELECT * FROM x WHERE nota = "margen 12%"',
        'margen 12%',
      ],
      'consulta de actualizacion' => [
        'Fallo: UPDATE tabla SET contenido = "lo que dijo el alumno"',
        'lo que dijo el alumno',
      ],
      'marcador de condicion' => [
        'Error [:db_condition_placeholder_0] => datos privados',
        'datos privados',
      ],
      'volcado de array' => [
        'Fallo raro Array ( [0] => facturamos 40 millones )',
        '40 millones',
      ],
      'segunda linea' => [
        "Primera línea del error\nSECRETO en la segunda",
        'SECRETO en la segunda',
      ],
    ];
  }

  /**
   * Un mensaje larguísimo se acota aunque no traiga marcadores.
   *
   * Es la segunda red: si una biblioteca mete datos sin ninguno de los
   * marcadores conocidos, el tope de longitud limita cuánto se escapa.
   */
  public function testUnMensajeLarguisimoSeAcota(): void {
    $redactado = ExceptionRedactor::redactMessage(str_repeat('a', 5000));

    $this->assertLessThan(250, mb_strlen($redactado));
    $this->assertStringEndsWith('…', $redactado);
  }

  /**
   * Si no queda nada, se dice, en vez de dejar la entrada muda.
   *
   * Cortarlo todo es posible y no es un fallo: significa que el mensaje era
   * íntegramente datos. Un registro con el motivo en blanco haría pensar que
   * el registro está roto.
   */
  public function testSiNoQuedaNadaSeDice(): void {
    $redactado = ExceptionRedactor::redactMessage('Array ( [0] => solo datos )');

    $this->assertStringContainsString('omitido', $redactado);
  }

  /**
   * Funciona igual recibiendo la excepción entera.
   */
  public function testTambienRedactaUnaExcepcion(): void {
    $redactado = ExceptionRedactor::redact(
      new \RuntimeException('Fallo: SELECT nota FROM x -- margen 12%'),
    );

    $this->assertStringNotContainsString('margen 12%', $redactado);
    $this->assertStringContainsString('Fallo', $redactado);
  }

}
