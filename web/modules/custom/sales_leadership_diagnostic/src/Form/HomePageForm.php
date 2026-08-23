<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\sales_leadership_diagnostic\Service\Branding\HomePage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administra el contenido de la portada.
 *
 * Expone las imágenes y los textos, y deja fuera la maquetación. Abrir también
 * la disposición habría convertido esto en un editor de páginas incompleto:
 * mucha superficie que mantener para un resultado peor que el de una plantilla
 * bien hecha.
 */
final class HomePageForm extends ConfigFormBase {

  /**
   * Extensiones admitidas.
   *
   * Mismas que el resto del módulo, SVG incluido: pasa por el filtro que
   * rechaza el que contenga código ejecutable.
   */
  private const EXTENSIONS = 'png jpg jpeg webp svg';

  /**
   * Tamaño máximo de la imagen de fondo.
   *
   * Es la imagen más pesada del sitio y se descarga antes de que se vea nada,
   * así que el límite es a la vez un tope de seguridad y un recordatorio de
   * que conviene optimizarla.
   */
  private const BACKGROUND_MAX_SIZE = '1 MB';

  /**
   * Tamaño máximo de cada logotipo.
   */
  private const LOGO_MAX_SIZE = '512 KB';

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
    return 'sales_leadership_diagnostic_home';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [HomePage::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['ayuda'] = [
      '#type' => 'item',
      '#markup' => $this->t('La portada ocupa la pantalla completa, sin la cabecera ni el pie de Drupal. El color principal —la barra y el botón— sale de la paleta de <em>Marca</em>, para que el azul de la marca se defina en un solo sitio.'),
    ];

    $form['imagenes'] = [
      '#type' => 'details',
      '#title' => $this->t('Imágenes'),
      '#open' => TRUE,
    ];

    $form['imagenes']['background_fid'] = $this->fileField(
      $this->t('Fondo'),
      $this->t('Ocupa toda la pantalla tras la tarjeta. Se oscurece ligeramente para que el texto se lea sobre cualquier imagen.'),
      'background_fid',
      self::BACKGROUND_MAX_SIZE,
    );

    $form['imagenes']['logo_light_fid'] = $this->fileField(
      $this->t('Logotipo de la cabecera'),
      $this->t('Va sobre la barra de color, así que debe ser la versión clara del logotipo.'),
      'logo_light_fid',
      self::LOGO_MAX_SIZE,
    );

    $form['imagenes']['logo_color_fid'] = $this->fileField(
      $this->t('Logotipo del pie'),
      $this->t('Va sobre la banda clara del pie, así que debe ser la versión a color.'),
      'logo_color_fid',
      self::LOGO_MAX_SIZE,
    );

    $form['textos'] = [
      '#type' => 'details',
      '#title' => $this->t('Textos'),
      '#open' => TRUE,
      '#description' => $this->t('Se muestran como texto: no se interpreta HTML ni Markdown.'),
    ];

    $form['textos']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Titular'),
      '#maxlength' => 120,
      '#config_target' => HomePage::CONFIG_NAME . ':title',
    ];

    $form['textos']['intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texto de presentación'),
      '#rows' => 3,
      '#config_target' => HomePage::CONFIG_NAME . ':intro',
    ];

    $form['textos']['button_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etiqueta del botón'),
      '#description' => $this->t('El botón lleva al sitio configurado en <em>Integración</em>, que es donde el alumno tiene su cuenta.'),
      '#maxlength' => 60,
      '#config_target' => HomePage::CONFIG_NAME . ':button_label',
    ];

    $form['textos']['help_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Aclaración bajo el botón'),
      '#description' => $this->t('En letra pequeña. Sirve a quien llega y no consigue entrar. Dejarlo vacío la oculta.'),
      '#rows' => 2,
      '#config_target' => HomePage::CONFIG_NAME . ':help_text',
    ];

    $form['colores'] = [
      '#type' => 'details',
      '#title' => $this->t('Colores propios de la portada'),
      '#open' => FALSE,
      '#description' => $this->t('Solo estos dos: el resto sale de <em>Marca</em>. Se separan porque no tienen equivalente en esa paleta.'),
    ];

    $form['colores']['accent_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Acento'),
      '#description' => $this->t('El botón de acceso de la cabecera.'),
      '#default_value' => $this->colorValue('accent_color'),
      '#config_target' => HomePage::CONFIG_NAME . ':accent_color',
    ];

    $form['colores']['band_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Banda del pie'),
      '#default_value' => $this->colorValue('band_color'),
      '#config_target' => HomePage::CONFIG_NAME . ':band_color',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach (array_keys(HomePage::DEFAULT_COLORS) as $key) {
      $value = trim((string) $form_state->getValue($key));

      if ($value === '' || preg_match(HomePage::COLOR_PATTERN, $value) === 1) {
        continue;
      }

      $form_state->setErrorByName($key, $this->t('Debe ser un color hexadecimal, por ejemplo #3b40e4.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    foreach (['background_fid', 'logo_light_fid', 'logo_color_fid'] as $key) {
      $this->makePermanent($form_state->getValue($key));
    }
  }

  /**
   * Construye un campo de archivo con sus validadores.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   Etiqueta del campo.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   Explicación de para qué sirve.
   * @param string $key
   *   Clave de configuración donde se guarda el identificador.
   * @param string $maxSize
   *   Tamaño máximo, en formato legible.
   */
  private function fileField($title, $description, string $key, string $maxSize): array {
    return [
      '#type' => 'managed_file',
      '#title' => $title,
      '#upload_location' => 'public://sales-diagnostic/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => self::EXTENSIONS],
        // En BYTES: la restricción de core lo declara como ?int y con una
        // cadena lanza un TypeError que aborta la subida entera.
        'FileSizeLimit' => ['fileLimit' => (int) Bytes::toNumber($maxSize)],
        'SldSafeSvg' => [],
      ],
      '#description' => $this->t('@que Formatos: @formatos. Máximo @tamano.', [
        '@que' => $description,
        '@formatos' => str_replace(' ', ', ', self::EXTENSIONS),
        '@tamano' => $maxSize,
      ]),
      '#config_target' => new ConfigTarget(
        HomePage::CONFIG_NAME,
        $key,
        fromConfig: static fn ($value): array => $value > 0 ? [(int) $value] : [],
        toConfig: static fn ($value): int => is_array($value) && $value !== [] ? (int) reset($value) : 0,
      ),
    ];
  }

  /**
   * Color a mostrar en el selector.
   *
   * Nunca vacío: <input type="color"> no admite el valor vacío, lo normaliza a
   * negro y eso es lo que enviaría al guardar.
   *
   * @param string $key
   *   Clave del color.
   */
  private function colorValue(string $key): string {
    $stored = trim((string) $this->config(HomePage::CONFIG_NAME)->get($key));

    return preg_match(HomePage::COLOR_PATTERN, $stored) === 1
      ? $stored
      : HomePage::DEFAULT_COLORS[$key];
  }

  /**
   * Marca un archivo subido como permanente.
   *
   * Sin esto, la limpieza automática de Drupal lo borra a las seis horas y la
   * portada se queda sin imágenes sola.
   *
   * @param mixed $fids
   *   Lo que devuelve el campo: un array de identificadores, o nada.
   */
  private function makePermanent(mixed $fids): void {
    if (!is_array($fids) || $fids === []) {
      return;
    }

    $file = $this->entityTypes->getStorage('file')->load((int) reset($fids));

    if (!$file instanceof FileInterface || $file->isPermanent()) {
      return;
    }

    $file->setPermanent();
    $file->save();

    $this->fileUsage->add($file, 'sales_leadership_diagnostic', 'config', (string) $file->id());
  }

}
