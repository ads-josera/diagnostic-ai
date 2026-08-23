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
use Drupal\sales_leadership_diagnostic\Service\Conversation\ChatWelcome;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administra la pantalla de bienvenida del chat.
 *
 * Va en su propia pestaña y no dentro del estudio del prompt, aunque las dos
 * las use el mismo gestor. El prompt tiene borrador y publicación porque un
 * cambio a medias rompería las conversaciones nuevas; estos textos no: son
 * carteles, se cambian y ya está. Meterlos bajo el mismo botón de «Publicar»
 * habría dado a entender que corrigir una errata es tan delicado como
 * reescribir la metodología.
 */
final class ChatWelcomeForm extends ConfigFormBase {

  /**
   * Extensiones admitidas para el icono.
   *
   * Mismas que el logotipo, SVG incluido: pasa por el mismo filtro que rechaza
   * el que contenga código ejecutable.
   */
  private const ICON_EXTENSIONS = 'png jpg jpeg webp svg';

  /**
   * Tamaño máximo del icono.
   *
   * Muy por debajo del logotipo: es un icono pequeño, y un archivo de un mega
   * para pintar 72 píxeles solo retrasa la primera pantalla del alumno.
   */
  private const ICON_MAX_SIZE = '512 KB';

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
    return 'sales_leadership_diagnostic_chat_welcome';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ChatWelcome::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['ayuda'] = [
      '#type' => 'item',
      '#markup' => $this->t('Esto es lo primero que ve el alumno al abrir un diagnóstico, antes de escribir nada. Todo es opcional: lo que se deje vacío simplemente no aparece.'),
    ];

    $form['welcome_icon_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Icono'),
      '#upload_location' => 'public://sales-diagnostic/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => self::ICON_EXTENSIONS],
        // El límite viaja en BYTES: la restricción de core lo declara
        // como ?int y lanza un TypeError con una cadena como «2 MB»,
        // que aborta la subida entera antes de comprobar nada.
        'FileSizeLimit' => ['fileLimit' => (int) Bytes::toNumber(self::ICON_MAX_SIZE)],
        'SldSafeSvg' => [],
      ],
      '#description' => $this->t('Se muestra sobre el texto introductorio, a 72 píxeles de alto. Formatos: @formatos. Máximo @tamano.', [
        '@formatos' => str_replace(' ', ', ', self::ICON_EXTENSIONS),
        '@tamano' => self::ICON_MAX_SIZE,
      ]),
      '#config_target' => new ConfigTarget(
        ChatWelcome::CONFIG_NAME,
        'welcome_icon_fid',
        fromConfig: static fn ($value): array => $value > 0 ? [(int) $value] : [],
        toConfig: static fn ($value): int => is_array($value) && $value !== [] ? (int) reset($value) : 0,
      ),
    ];

    $form['welcome_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texto introductorio'),
      '#description' => $this->t('Qué es este diagnóstico y qué va a obtener el alumno. Se muestra como texto: no se interpreta HTML ni Markdown.'),
      '#rows' => 4,
      '#config_target' => ChatWelcome::CONFIG_NAME . ':welcome_intro',
    ];

    $form['sugerencias'] = [
      '#type' => 'details',
      '#title' => $this->t('Sugerencias para empezar'),
      '#open' => TRUE,
      '#description' => $this->t('Botones que el alumno puede pulsar para arrancar la conversación sin tener que redactar el primer mensaje. Al pulsarlos se envían tal cual como su primer turno, así que conviene redactarlos en primera persona. Dejar una vacía la oculta.'),
      // Sin #tree: cada sugerencia tiene su propio destino en configuración y
      // se maneja por separado, no como un grupo.
    ];

    $suggestions = $this->config(ChatWelcome::CONFIG_NAME)->get('welcome_suggestions');
    $suggestions = is_array($suggestions) ? array_values($suggestions) : [];

    for ($i = 0; $i < ChatWelcome::SUGGESTION_SLOTS; $i++) {
      $form['sugerencias']['sugerencia_' . $i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Sugerencia @numero', ['@numero' => $i + 1]),
        '#default_value' => (string) ($suggestions[$i] ?? ''),
        '#maxlength' => 160,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Las sugerencias se recomponen a mano porque en configuración son una lista
   * y en el formulario son cuatro campos sueltos. Los huecos se descartan al
   * guardar, no al mostrar: así la configuración guardada no arrastra cadenas
   * vacías que después habría que filtrar en cada página del alumno.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $suggestions = [];

    for ($i = 0; $i < ChatWelcome::SUGGESTION_SLOTS; $i++) {
      $value = trim((string) $form_state->getValue('sugerencia_' . $i));

      if ($value !== '') {
        $suggestions[] = $value;
      }
    }

    $this->configFactory->getEditable(ChatWelcome::CONFIG_NAME)
      ->set('welcome_suggestions', $suggestions)
      ->save();

    $this->makeIconPermanent($form_state);
  }

  /**
   * Marca el icono como permanente para que la limpieza no lo borre.
   *
   * Un archivo subido por managed_file nace temporal y Drupal lo elimina a las
   * seis horas. Sin esto, el icono desaparecería solo esa misma tarde.
   */
  private function makeIconPermanent(FormStateInterface $form_state): void {
    $fids = $form_state->getValue('welcome_icon_fid');

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
