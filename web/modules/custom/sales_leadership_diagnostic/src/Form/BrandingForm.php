<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\sales_leadership_diagnostic\Service\Branding\Branding;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Personalización visual del diagnóstico.
 *
 * Expone deliberadamente pocos ajustes. Un panel de personalización completo
 * —cada color, cada espaciado, cada tipografía— parece más generoso, pero
 * produce combinaciones ilegibles y traslada al cliente un trabajo de diseño
 * que no ha pedido. Aquí solo se abre lo que identifica a una marca: el
 * logotipo, el color principal y el mensaje de bienvenida.
 *
 * Nótese que no hay ajuste de tipografía. La variable existe en el CSS y hereda
 * la del tema del sitio a propósito: así el diagnóstico se lee igual que el
 * resto de las páginas sin cargar una fuente más.
 */
final class BrandingForm extends ConfigFormBase {

  /**
   * Extensiones admitidas para el logotipo.
   *
   * SVG queda fuera a propósito: un SVG es un documento XML que puede contener
   * scripts, y se serviría desde el mismo dominio que el diagnóstico. El
   * beneficio —un logotipo nítido a cualquier tamaño— no compensa abrir esa vía
   * en un sitio que maneja datos de alumnos.
   */
  private const LOGO_EXTENSIONS = 'png jpg jpeg webp';

  /**
   * Tamaño máximo del logotipo.
   */
  private const LOGO_MAX_SIZE = '2 MB';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeManagerInterface $entityTypes,
    private readonly FileUsageInterface $fileUsage,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('file.usage'),
    );
  }

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

    $form['logotipo'] = [
      '#type' => 'details',
      '#title' => $this->t('Logotipo'),
      '#open' => TRUE,
    ];

    $form['logotipo']['logo_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Archivo'),
      '#upload_location' => 'public://sales-diagnostic/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => self::LOGO_EXTENSIONS],
        'FileSizeLimit' => ['fileLimit' => self::LOGO_MAX_SIZE],
      ],
      '#description' => $this->t('Formatos admitidos: @formatos. Máximo @tamano. No se admite SVG por seguridad: puede contener scripts.', [
        '@formatos' => str_replace(' ', ', ', self::LOGO_EXTENSIONS),
        '@tamano' => self::LOGO_MAX_SIZE,
      ]),
      '#config_target' => new ConfigTarget(
        Branding::CONFIG_NAME,
        'logo_fid',
        // managed_file trabaja con un array de identificadores y la
        // configuración guarda uno solo. Las dos funciones hacen esa
        // traducción en cada sentido.
        fromConfig: static fn ($value): array => $value > 0 ? [(int) $value] : [],
        toConfig: static fn ($value): int => is_array($value) && $value !== [] ? (int) reset($value) : 0,
      ),
    ];

    $form['logotipo']['logo_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto alternativo'),
      '#description' => $this->t('Lo que se lee en voz alta a quien usa un lector de pantalla, y lo que se muestra si la imagen no carga.'),
      '#maxlength' => 128,
      '#config_target' => Branding::CONFIG_NAME . ':logo_alt',
    ];

    $form['colores'] = [
      '#type' => 'details',
      '#title' => $this->t('Colores'),
      '#open' => TRUE,
      '#description' => $this->t('Dejar un color vacío mantiene el de la paleta por defecto. Los colores de estado —error, aviso, éxito— no se pueden cambiar: un error debe seguir pareciendo un error.'),
    ];

    $form['colores']['color_primary'] = [
      '#type' => 'color',
      '#title' => $this->t('Color principal'),
      '#description' => $this->t('Botones, enlaces y elementos destacados.'),
      '#config_target' => Branding::CONFIG_NAME . ':color_primary',
    ];

    $form['colores']['color_primary_hover'] = [
      '#type' => 'color',
      '#title' => $this->t('Color principal al pasar el ratón'),
      '#description' => $this->t('Conviene una versión algo más oscura del principal.'),
      '#config_target' => Branding::CONFIG_NAME . ':color_primary_hover',
    ];

    $form['colores']['color_accent'] = [
      '#type' => 'color',
      '#title' => $this->t('Color de acento'),
      '#description' => $this->t('Confirmaciones y elementos positivos, como el diagnóstico completado.'),
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
   * {@inheritdoc}
   *
   * Un archivo subido por managed_file nace temporal y lo borra la limpieza
   * automática de Drupal a las seis horas. Marcarlo permanente y registrar el
   * uso evita que el logotipo desaparezca solo unas horas después de ponerlo.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $fids = $form_state->getValue('logo_fid');

    if (!is_array($fids) || $fids === []) {
      return;
    }

    $file = $this->entityTypes->getStorage('file')->load((int) reset($fids));

    if (!$file instanceof FileInterface) {
      return;
    }

    if ($file->isPermanent()) {
      return;
    }

    $file->setPermanent();
    $file->save();

    $this->fileUsage->add($file, 'sales_leadership_diagnostic', 'config', (string) $file->id());
  }

}
