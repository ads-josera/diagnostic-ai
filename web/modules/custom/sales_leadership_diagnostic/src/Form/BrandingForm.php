<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sales_leadership_diagnostic\Service\Branding\Branding;

/**
 * Personalización visual del diagnóstico.
 *
 * Expone deliberadamente pocos ajustes. Un panel de personalización completo
 * —cada color, cada espaciado, cada tipografía— parece más generoso, pero
 * produce combinaciones ilegibles y traslada al cliente un trabajo de diseño
 * que no ha pedido. Aquí solo se abre lo que identifica a una marca: los
 * colores y el mensaje de bienvenida.
 *
 * NO hay logotipo. Lo hubo, y se retiró el 23-08-2026 a petición del usuario:
 * desde que las páginas del alumno llevan el marco del diseño del cliente, el
 * logotipo lo pone la barra superior, que se administra en la pestaña
 * «Portada». El de aquí se pintaba además dentro de la tarjeta, duplicando la
 * marca en la misma pantalla, y al quitarlo de la plantilla se quedó sin
 * ningún sitio donde mostrarse. Un ajuste que no se ve en ninguna parte es
 * peor que no tenerlo: invita a subir un archivo y a preguntarse por qué no
 * pasa nada.
 *
 * Nótese que no hay ajuste de tipografía. La variable existe en el CSS y hereda
 * la del tema del sitio a propósito: así el diagnóstico se lee igual que el
 * resto de las páginas sin cargar una fuente más.
 */
final class BrandingForm extends ConfigFormBase {

  /**
   * Paleta por defecto, la misma que declara sld-base.css.
   *
   * Los selectores de color se rellenan con estos valores cuando no hay nada
   * configurado, en lugar de dejarse vacíos.
   *
   * El motivo es que <input type="color"> NO admite el valor vacío: el
   * navegador lo normaliza a #000000, avisa por consola y, al guardar, envía
   * negro. Es decir, dejar el campo «vacío» y pulsar guardar pintaba de negro
   * los botones y enlaces del alumno sin que nadie lo hubiera pedido.
   *
   * Mostrando el color real que se está usando, el selector deja de mentir: lo
   * que se ve es lo que hay, y cambiarlo es una decisión explícita.
   *
   * @var array<string, string>
   */
  private const DEFAULT_PALETTE = [
    'color_primary' => '#1f4788',
    'color_primary_hover' => '#16345f',
    'color_accent' => '#1a7f4b',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sales_leadership_diagnostic_branding';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [Branding::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['ayuda'] = [
      '#type' => 'item',
      '#markup' => $this->t('Estos ajustes afectan a las páginas del diagnóstico: el panel del alumno, la conversación y el resultado. La cabecera, el pie y los menús los sigue pintando el tema del sitio, que se configura en Apariencia.'),
    ];

    $form['colores'] = [
      '#type' => 'details',
      '#title' => $this->t('Colores'),
      '#open' => TRUE,
      '#description' => $this->t('Los selectores muestran el color que se está usando ahora. Los colores de estado —error, aviso, éxito— no se pueden cambiar: un error debe seguir pareciendo un error aunque la marca sea verde.'),
    ];

    $form['colores']['color_primary'] = [
      '#type' => 'color',
      '#title' => $this->t('Color principal'),
      '#description' => $this->t('Botones, enlaces y elementos destacados.'),
      '#default_value' => $this->colorValue('color_primary'),
      '#config_target' => Branding::CONFIG_NAME . ':color_primary',
    ];

    $form['colores']['color_primary_hover'] = [
      '#type' => 'color',
      '#title' => $this->t('Color principal al pasar el ratón'),
      '#description' => $this->t('Conviene una versión algo más oscura del principal.'),
      '#default_value' => $this->colorValue('color_primary_hover'),
      '#config_target' => Branding::CONFIG_NAME . ':color_primary_hover',
    ];

    $form['colores']['color_accent'] = [
      '#type' => 'color',
      '#title' => $this->t('Color de acento'),
      '#description' => $this->t('Confirmaciones y elementos positivos, como el diagnóstico completado.'),
      '#default_value' => $this->colorValue('color_accent'),
      '#config_target' => Branding::CONFIG_NAME . ':color_accent',
    ];

    $form['textos'] = [
      '#type' => 'details',
      '#title' => $this->t('Textos'),
      '#open' => TRUE,
    ];

    $form['textos']['welcome_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mensaje de bienvenida del panel'),
      '#description' => $this->t('Aparece bajo el saludo, antes del botón de empezar. Se muestra como texto: no se interpreta HTML ni Markdown. Dejarlo vacío oculta el mensaje.'),
      '#rows' => 3,
      '#config_target' => Branding::CONFIG_NAME . ':welcome_text',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Los colores se validan aquí para avisar a quien los introduce, y OTRA VEZ
   * al emitirlos en Branding::buildCss(). No es redundancia inútil: la
   * configuración también puede cambiarse por drush o importarse desde un
   * archivo, caminos que no pasan por este formulario.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach (['color_primary', 'color_primary_hover', 'color_accent'] as $key) {
      $value = trim((string) $form_state->getValue($key));

      if ($value === '' || preg_match(Branding::COLOR_PATTERN, $value) === 1) {
        continue;
      }

      $form_state->setErrorByName(
        $key,
        $this->t('Debe ser un color hexadecimal, por ejemplo #1f4788.'),
      );
    }
  }

  /**
   * Color a mostrar en un selector: el configurado, o el de la paleta.
   *
   * @param string $key
   *   Clave del color.
   */
  private function colorValue(string $key): string {
    $stored = trim((string) $this->config(Branding::CONFIG_NAME)->get($key));

    // Un valor guardado que no sea un color válido —importado a mano, por
    // ejemplo— se ignora aquí igual que lo ignora Branding al emitir el CSS.
    // Mostrarlo en el selector lo convertiría en negro sin avisar.
    if ($stored !== '' && preg_match(Branding::COLOR_PATTERN, $stored) === 1) {
      return $stored;
    }

    return self::DEFAULT_PALETTE[$key];
  }

}
