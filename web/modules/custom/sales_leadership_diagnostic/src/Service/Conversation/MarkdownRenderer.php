<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
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
   * Incluye <a> DESDE EL 02-10-2026, y es la decisión explícita que este
   * comentario pedía. Antes no estaba, por una razón que sigue siendo cierta
   * —un enlace generado por un modelo es un vector de phishing—, pero el
   * precio resultó ser mayor que el riesgo: el entregable del agente de
   * prospección **es** evidencia verificable, y el filtro se comía la fuente
   * de cada afirmación. `[SAP News Center](https://…)` llegaba al alumno como
   * «SAP News Center» a secas, sin forma de comprobar nada. El cliente lo
   * comparó con un pack lleno de fuentes enlazadas y el nuestro pareció
   * palabrería.
   *
   * El riesgo se trata, no se ignora, y en cuatro capas: CommonMark con
   * `allow_unsafe_links` apagado, `Xss::filter()` filtrando protocolos,
   * `enlacesSeguros()` descartando todo lo que no sea http(s) y, la que de
   * verdad desactiva el engaño, **el destino siempre visible**: el phishing
   * vive de que el texto diga una cosa y el enlace lleve a otra, y aquí el
   * dominio se imprime al lado.
   *
   * No incluye <img>, <iframe>, <script> ni <style>.
   *
   * @var string[]
   */
  private const ALLOWED_TAGS = [
    'a',
    // <span> no se le permite al modelo: lo añade enlacesSeguros() después de
    // filtrar, para marcar el dominio de cada fuente.
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
    // Y las URL escritas sueltas pasan a ser enlace. El agente lista a veces
    // sus fuentes como «Medio — fecha: https://…», y el 03-10-2026 salieron
    // en texto plano, sin poder pulsarse. Los correos que esta extensión
    // también enlaza los desarma después enlacesSeguros(), que solo admite
    // direcciones web.
    $entorno->addExtension(new AutolinkExtension());

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

    return $this->enlacesSeguros(Xss::filter($html, self::ALLOWED_TAGS));
  }

  /**
   * Deja los enlaces en condiciones de enseñárselos a una persona.
   *
   * Tres cosas, y cada una tapa un agujero distinto:
   *
   *  1. **Solo http y https.** `Xss::filter()` ya descarta los protocolos
   *     peligrosos, pero esto es explícito y no depende de que esa lista no
   *     cambie nunca. Lo que no pasa el filtro pierde el enlace y conserva el
   *     texto: la frase sigue leyéndose.
   *  2. **`rel` y `target`.** Se abren fuera para no perder la conversación a
   *     medias, y con `noopener noreferrer` para que la página de destino no
   *     pueda tocar la nuestra ni saber de dónde viene.
   *  3. **El dominio, visible.** Es la que de verdad importa. Un enlace
   *     engaña cuando el texto dice «SAP News Center» y lleva a otro sitio;
   *     con el dominio impreso al lado, la persona ve a dónde va antes de
   *     pulsar. Si el texto ya lo contiene, no se repite.
   *
   * Lo que esto NO resuelve, y se asume: que el agente cite una fuente real
   * pero irrelevante. Eso es un problema de la metodología, no del filtro.
   */
  private function enlacesSeguros(string $html): string {
    // Con expresión y no con `str_contains('<a ')`: cuando Xss::filter ya ha
    // quitado un href peligroso, la etiqueta queda como `<a>` SIN espacio, el
    // atajo no la veía y el enlace vacío llegaba a la pantalla. Lo destapó la
    // prueba del caso `javascript:`.
    if (preg_match('/<a[\s>]/i', $html) !== 1) {
      return $html;
    }

    $documento = Html::load($html);

    foreach (iterator_to_array($documento->getElementsByTagName('a')) as $enlace) {
      $destino = (string) $enlace->getAttribute('href');
      $host = strtolower((string) parse_url($destino, PHP_URL_HOST));
      $esquema = strtolower((string) parse_url($destino, PHP_URL_SCHEME));

      if ($host === '' || !in_array($esquema, ['http', 'https'], TRUE)) {
        // Se queda el texto y se va el enlace: quitar la frase entera sería
        // perder lo que el agente quiso decir por culpa de una URL mala.
        $enlace->parentNode?->replaceChild(
          $documento->createTextNode($enlace->textContent),
          $enlace,
        );

        continue;
      }

      $enlace->setAttribute('rel', 'nofollow noopener noreferrer');
      $enlace->setAttribute('target', '_blank');

      // Con str_starts_with y no con ltrim: `ltrim($host, 'www.')` quita
      // CARACTERES sueltos, no el prefijo, y convertía «wired.com» en
      // «ired.com». Un dominio mal escrito al lado de un enlace es peor que
      // no ponerlo: invita a desconfiar de la fuente correcta.
      $visible = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

      if (!str_contains(strtolower($enlace->textContent), $visible)) {
        // En su propio elemento y no como texto suelto, para poder atenuarlo:
        // el dominio es una ayuda para decidir si pulsar, no parte de la
        // frase, y con el mismo peso competiría con lo que se está leyendo.
        // Esta etiqueta la creamos nosotros DESPUÉS de filtrar, así que no
        // viene del modelo.
        $fuente = $documento->createElement('span', ' (' . $visible . ')');
        $fuente->setAttribute('class', 'sld-fuente');
        $enlace->parentNode?->insertBefore($fuente, $enlace->nextSibling);
      }
    }

    return Html::serialize($documento);
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
