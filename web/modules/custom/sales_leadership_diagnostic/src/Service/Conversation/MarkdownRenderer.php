<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

use Drupal\Component\Utility\Xss;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Exception\CommonMarkException;

/**
 * Convierte el Markdown del agente en HTML seguro (§25).
 *
 * Nunca se confía en el HTML que produce un modelo de lenguaje. La defensa es
 * doble y deliberada:
 *
 *  1. CommonMark se configura con `html_input: strip`, de modo que cualquier
 *     etiqueta HTML incrustada en el Markdown se descarta antes de convertir.
 *  2. El HTML resultante pasa por Xss::filter() con una lista blanca explícita.
 *
 * Bastaría con una de las dos para el caso normal. Están las dos porque el
 * coste es nulo y el fallo de una sola sería una inyección de HTML arbitrario
 * en la página del alumno.
 *
 * La configuración de seguridad es interna a propósito: si fuese inyectable,
 * un cambio en services.yml podría abrir el agujero sin que se note en la
 * revisión de este archivo.
 */
final class MarkdownRenderer {

  /**
   * Etiquetas permitidas en la salida.
   *
   * No incluye <a>: un enlace generado por un modelo es un vector de phishing
   * y un diagnóstico conversacional no necesita emitir enlaces. Si la
   * metodología del cliente los requiriese, habilitarlo debe ser una decisión
   * explícita, no un descuido heredado.
   *
   * Tampoco incluye <img>, <iframe>, <script> ni <style>, por lo mismo.
   *
   * @var string[]
   */
  private const ALLOWED_TAGS = [
    'p',
    'br',
    'strong',
    'em',
    'b',
    'i',
    'ul',
    'ol',
    'li',
    'h3',
    'h4',
    'h5',
    'blockquote',
    'code',
    'pre',
    'hr',
  ];

  /**
   * Conversor de Markdown con la configuración de seguridad ya aplicada.
   *
   * @var \League\CommonMark\CommonMarkConverter
   */
  private readonly CommonMarkConverter $converter;

  public function __construct() {
    $this->converter = new CommonMarkConverter([
      // Descarta el HTML incrustado en lugar de escaparlo: no hay ningún caso
      // legítimo en el que el agente deba emitir marcado propio.
      'html_input' => 'strip',
      'allow_unsafe_links' => FALSE,
      // Acota el anidamiento para que una respuesta malformada no consuma
      // memoria de forma desproporcionada.
      'max_nesting_level' => 12,
    ]);
  }

  /**
   * Convierte Markdown en HTML ya saneado.
   *
   * Si la conversión falla, se devuelve el texto plano escapado en lugar de
   * propagar el error: el alumno debe poder leer la respuesta del agente
   * aunque su formato esté mal (§58).
   */
  public function render(string $markdown): string {
    if (trim($markdown) === '') {
      return '';
    }

    try {
      $html = (string) $this->converter->convert($markdown);
    }
    catch (CommonMarkException) {
      return '<p>' . Xss::filter($markdown, []) . '</p>';
    }

    return Xss::filter($html, self::ALLOWED_TAGS);
  }

}
