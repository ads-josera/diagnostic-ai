<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

use Drupal\Component\Utility\Xss;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

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
    // Desde h2, no desde h1: la página ya tiene el suyo, y dos encabezados de
    // primer nivel son un problema de accesibilidad real, no una minucia. Un
    // «#» del modelo se rebaja a h2 antes de filtrar (§ver render()).
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'blockquote',
    'code',
    'pre',
    'hr',
    // Tablas. El informe final del cliente trae una de diez filas —la madurez
    // por dimensión— y sin esto se pintaba como un párrafo lleno de barras
    // verticales. Son etiquetas inertes: no ejecutan nada ni navegan a
    // ninguna parte, así que admitirlas no abre ningún vector.
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
  ];

  /**
   * Conversor de Markdown con la configuración de seguridad ya aplicada.
   *
   * @var \League\CommonMark\MarkdownConverter
   */
  private readonly MarkdownConverter $converter;

  public function __construct() {
    $entorno = new Environment([
      // Descarta el HTML incrustado en lugar de escaparlo: no hay ningún caso
      // legítimo en el que el agente deba emitir marcado propio.
      'html_input' => 'strip',
      'allow_unsafe_links' => FALSE,
      // Acota el anidamiento para que una respuesta malformada no consuma
      // memoria de forma desproporcionada.
      'max_nesting_level' => 12,
    ]);

    $entorno->addExtension(new CommonMarkCoreExtension());
    // Las tablas NO son parte de CommonMark, son una extensión. El informe del
    // cliente usa una, así que sin activarla su entregable se leía mal.
    $entorno->addExtension(new TableExtension());

    $this->converter = new MarkdownConverter($entorno);
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
      $html = (string) $this->converter->convert($this->rebajarEncabezados($markdown));
    }
    catch (CommonMarkException) {
      return '<p>' . Xss::filter($markdown, []) . '</p>';
    }

    return Xss::filter($html, self::ALLOWED_TAGS);
  }

  /**
   * Rebaja un encabezado de primer nivel a segundo.
   *
   * La página ya tiene su «h1» —el saludo del panel, el título del resultado—
   * y el contenido del modelo es una sección dentro de ella. Antes esto se
   * resolvía dejando «h1» fuera de la lista blanca, pero el efecto era peor de
   * lo que parecía: el filtro quita la etiqueta y CONSERVA el texto, así que
   * el encabezado quedaba como un párrafo suelto sin ninguna jerarquía. El
   * informe del cliente abre con uno.
   *
   * Solo se toca el nivel uno. Los demás ya caen dentro de la página.
   */
  private function rebajarEncabezados(string $markdown): string {
    return preg_replace('/^# (?=\S)/m', '## ', $markdown) ?? $markdown;
  }

}
