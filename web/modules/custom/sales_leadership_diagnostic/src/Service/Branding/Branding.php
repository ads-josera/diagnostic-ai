<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Branding;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Personalización visual del diagnóstico.
 *
 * El CSS del módulo está escrito sobre variables CSS desde el principio, así
 * que personalizar la marca no exige un tema propio: basta con redefinir un
 * puñado de variables y el resto de las hojas de estilo las consume sin
 * cambios. Dónde se redefinen no es indiferente; está explicado en
 * buildCss(), donde costó un fallo averiguarlo.
 *
 * Esa decisión tiene un coste que conviene reconocer: la personalización llega
 * hasta donde llegan las páginas del módulo. La cabecera, el pie y los menús
 * del sitio los sigue pintando el tema de Drupal. Se eligió así porque el
 * alumno solo ve tres páginas —panel, conversación y resultado— y un tema
 * propio habría añadido una superficie que hay que mantener actualizada con
 * cada versión de core a cambio de muy poco.
 *
 * SEGURIDAD: los colores acaban dentro de un bloque <style>. Un valor que no
 * sea un color hexadecimal podría cerrar la declaración e inyectar reglas
 * arbitrarias. Por eso esta clase NO confía en que el formulario haya validado:
 * vuelve a comprobar cada valor antes de emitirlo, y descarta el que no encaje.
 * Es la misma disciplina que con el texto del modelo de IA: quien escribe la
 * salida es quien valida.
 */
final class Branding {

  /**
   * Nombre del objeto de configuración.
   */
  public const CONFIG_NAME = 'sales_leadership_diagnostic.branding';

  /**
   * Color hexadecimal de tres o seis dígitos.
   */
  public const COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

  /**
   * Correspondencia entre ajuste de color y variable CSS del módulo.
   *
   * Solo se exponen los colores que un cliente querría cambiar. El resto de la
   * paleta —bordes, superficies, estados— se deja fija: dejarla abierta invita
   * a combinaciones ilegibles, y los estados de error deben seguir pareciendo
   * errores aunque la marca sea verde.
   */
  private const COLOR_VARIABLES = [
    'color_primary' => '--sld-color-primary',
    'color_primary_hover' => '--sld-color-primary-hover',
    'color_accent' => '--sld-color-success',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Bloque CSS con las variables personalizadas, o cadena vacía.
   *
   * Devuelve cadena vacía cuando no hay nada personalizado, para no añadir un
   * <style> inútil a todas las páginas del módulo.
   */
  public function buildCss(): string {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $declarations = [];

    foreach (self::COLOR_VARIABLES as $key => $variable) {
      $value = (string) $config->get($key);

      // Se descarta en silencio lo que no sea un color válido. Fallar aquí de
      // forma ruidosa dejaría la página del alumno rota por un ajuste mal
      // puesto; caer en la paleta por defecto es degradar sin romper.
      if ($value === '' || preg_match(self::COLOR_PATTERN, $value) !== 1) {
        continue;
      }

      $declarations[] = sprintf('  %s: %s;', $variable, $value);
    }

    if ($declarations === []) {
      return '';
    }

    // Los selectores importan, y hay dos motivos encadenados por los que no
    // puede ser `:root`.
    //
    // El primero: una variable declarada en el PROPIO elemento gana siempre a
    // la heredada, sin que la especificidad de `:root` cuente para nada. El
    // módulo declara sus tokens en `.sld`, `.sld-page` y `.sld-home`, así que
    // una redefinición en `:root` no llegaba a aplicarse nunca.
    //
    // El segundo: escribiéndolos en esas mismas clases la especificidad
    // empataba, y Drupal coloca este bloque ANTES de las hojas del módulo, con
    // lo que seguía perdiendo. Por eso van duplicadas: `.sld.sld` es el mismo
    // elemento con el doble de especificidad, y así gana sin depender del
    // orden en que se sirvan los archivos.
    //
    // Se comprobó midiendo el color efectivo en el navegador. Comprobar que el
    // <style> existía no bastaba: existía, y no hacía nada.
    $selectores = implode(",\n", [
      '.sld.sld',
      '.sld-page.sld-page',
      '.sld-home.sld-home',
    ]);

    return $selectores . " {\n" . implode("\n", $declarations) . "\n}\n";
  }

  /**
   * Texto de bienvenida del panel, o NULL si no se ha personalizado.
   */
  public function getWelcomeText(): ?string {
    $value = trim((string) $this->configFactory->get(self::CONFIG_NAME)->get('welcome_text'));

    return $value === '' ? NULL : $value;
  }

  /**
   * Etiquetas de cache del objeto de configuración.
   *
   * Quien pinte la marca debe declararlas, o un cambio de color o del texto de
   * bienvenida no se vería hasta que caducara la página por otro motivo.
   *
   * @return string[]
   *   Etiquetas de cache.
   */
  public function getCacheTags(): array {
    return $this->configFactory->get(self::CONFIG_NAME)->getCacheTags();
  }

}
