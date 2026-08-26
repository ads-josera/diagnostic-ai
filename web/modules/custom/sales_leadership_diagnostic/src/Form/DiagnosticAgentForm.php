<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Alta y edición de un agente.
 *
 * Reúne lo que define al agente: qué curso lo concede, su metodología y su
 * pantalla de bienvenida. Los documentos de conocimiento NO están aquí: se
 * administran en su propia pantalla porque subir archivos es otra clase de
 * interacción —cada uno se sube y se lee al momento— y mezclarla con campos
 * que se guardan al pulsar «Guardar» produce un formulario donde unas cosas
 * surten efecto y otras no.
 */
final class DiagnosticAgentForm extends EntityForm {

  /**
   * Formatos admitidos para el icono de bienvenida.
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
    private readonly EntityTypeManagerInterface $entityTypes,
    private readonly FileUsageInterface $fileUsage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file.usage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $agente = $this->entity;
    assert($agente instanceof DiagnosticAgentInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre'),
      '#description' => $this->t('Lo lee el alumno cuando tiene más de un diagnóstico disponible.'),
      '#default_value' => $agente->label(),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $agente->id(),
      '#machine_name' => [
        'exists' => [$this, 'agentExists'],
        'source' => ['label'],
      ],
      // Cambiar el identificador de un agente en uso dejaría huérfanas las
      // sesiones que lo nombran, y el historial diría que se hicieron con un
      // agente que ya no existe.
      '#disabled' => !$agente->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disponible para los alumnos'),
      '#description' => $this->t('Deshabilitarlo lo retira del panel sin borrar nada. Las conversaciones ya hechas se conservan.'),
      '#default_value' => $agente->isNew() ? TRUE : $agente->status(),
    ];

    $form['course_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Curso que lo concede'),
      '#description' => $this->t('Identificador del curso en WordPress. El alumno que lo haya comprado verá este agente; quien no, no. Debe estar también en la lista de cursos del plugin de WordPress.'),
      '#default_value' => $agente->getCourseId(),
      '#required' => TRUE,
      '#maxlength' => 64,
    ];

    $form['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Versión de la metodología'),
      '#description' => $this->t('Se guarda en cada diagnóstico. Súbela cuando cambies el prompt, para poder distinguir después con qué versión se generó cada resultado.'),
      '#default_value' => $agente->getVersion(),
      '#maxlength' => 32,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Descripción'),
      '#description' => $this->t('Se le muestra al alumno bajo el nombre, cuando tiene más de un diagnóstico.'),
      '#default_value' => $agente->getDescription(),
      '#rows' => 2,
    ];

    $form['metodologia'] = [
      '#type' => 'details',
      '#title' => $this->t('Metodología'),
      '#open' => $agente->isNew(),
      '#description' => $this->t('Es propiedad del cliente y se usa tal cual. Si la metodología vive en documentos, cárgalos en la pantalla de Documentos: el prompt puede referirse a ellos.'),
    ];

    $form['metodologia']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt principal'),
      '#default_value' => $agente->getSystemPrompt(),
      '#rows' => 12,
      '#required' => TRUE,
    ];

    $form['metodologia']['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Instrucciones de conducción'),
      '#default_value' => $agente->getInstructions(),
      '#rows' => 8,
    ];

    $form['metodologia']['output_contract'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Contrato de salida'),
      '#description' => $this->t('Añadido técnico, no del cliente: sin él la respuesta no puede validarse ni guardarse de forma estructurada.'),
      '#default_value' => $agente->getOutputContract(),
      '#rows' => 8,
    ];

    $form['bienvenida'] = [
      '#type' => 'details',
      '#title' => $this->t('Pantalla de bienvenida'),
      '#description' => $this->t('Lo que ve el alumno antes de escribir el primer mensaje.'),
    ];

    $form['bienvenida']['welcome_icon_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Icono'),
      '#upload_location' => 'public://sales-diagnostic/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => self::ICON_EXTENSIONS],
        // El límite viaja en BYTES: la restricción de core lo declara como
        // ?int y lanza un TypeError con una cadena como «512 KB», que aborta
        // la subida entera antes de comprobar nada.
        'FileSizeLimit' => ['fileLimit' => (int) Bytes::toNumber(self::ICON_MAX_SIZE)],
        'SldSafeSvg' => [],
      ],
      '#description' => $this->t('Se muestra sobre el texto introductorio, a 72 píxeles de alto. Formatos: @formatos. Máximo @tamano.', [
        '@formatos' => str_replace(' ', ', ', self::ICON_EXTENSIONS),
        '@tamano' => self::ICON_MAX_SIZE,
      ]),
      '#default_value' => $agente->getWelcomeIconFid() > 0
        ? [$agente->getWelcomeIconFid()]
        : [],
    ];

    $form['bienvenida']['welcome_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texto introductorio'),
      '#default_value' => $agente->getWelcomeIntro(),
      '#rows' => 3,
    ];

    $form['bienvenida']['welcome_suggestions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sugerencias para empezar'),
      '#description' => $this->t('Una por línea. Se le ofrecen al alumno como botones.'),
      '#default_value' => implode("\n", $agente->getWelcomeSuggestions()),
      '#rows' => 4,
    ];

    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Orden'),
      '#description' => $this->t('Los más bajos aparecen antes en el panel del alumno.'),
      '#default_value' => $agente->getWeight(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Las sugerencias se escriben una por línea y la entidad las guarda como
   * lista. La conversión va AQUÍ y no en submitForm() porque EntityForm copia
   * los valores a la entidad durante la VALIDACIÓN, antes de que submitForm()
   * llegue a ejecutarse: intentar transformarlas allí hacía que la cadena del
   * textarea se asignara a una propiedad tipada como array y el guardado
   * reventara con un TypeError, con la página de alta cargando sin un aviso.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    // Los navegadores envían los textos largos con saltos CRLF, y guardarlos
    // así tiene un efecto molesto: abrir el agente y pulsar «Guardar» sin
    // tocar nada cambiaba el prompt —un retorno de carro por línea, 258 en el
    // del cliente—, con él su huella, y dejaba un cambio en la configuración
    // exportada que no correspondía a ninguna edición real.
    foreach (['system_prompt', 'instructions', 'output_contract', 'welcome_intro'] as $campo) {
      $texto = $form_state->getValue($campo);

      if (is_string($texto)) {
        $form_state->setValue($campo, str_replace("\r\n", "\n", $texto));
      }
    }

    // El elemento de subida entrega una LISTA de identificadores y la entidad
    // guarda uno solo. La conversión va aquí por el mismo motivo que la de las
    // sugerencias, y con la misma guarda: este método corre dos veces.
    $icono = $form_state->getValue('welcome_icon_fid');

    if (is_array($icono)) {
      $form_state->setValue(
        'welcome_icon_fid',
        $icono === [] ? 0 : (int) reset($icono),
      );
    }

    $valor = $form_state->getValue('welcome_suggestions');

    // Este método se ejecuta DOS veces —al validar y al enviar— y la segunda
    // el valor ya viene convertido. Sin esta guarda, la segunda pasada
    // intentaba tratar el array como cadena y dejaba un aviso de PHP en
    // pantalla junto al mensaje de guardado correcto.
    if (is_string($valor)) {
      $lineas = preg_split('/\R/', $valor) ?: [];

      $form_state->setValue('welcome_suggestions', array_values(array_filter(
        array_map('trim', $lineas),
        static fn (string $s): bool => $s !== '',
      )));
    }

    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    // Se lee ANTES de guardar: después, la entidad ya tiene el nuevo y no
    // habría forma de saber cuál era el anterior para liberarlo.
    $anterior = $this->iconoGuardado();

    $estado = parent::save($form, $form_state);

    $this->registrarIcono($anterior);

    $this->messenger()->addStatus($this->t('Agente «@nombre» guardado.', [
      '@nombre' => $this->entity->label(),
    ]));

    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $estado;
  }

  /**
   * Identificador del icono que el agente tenía guardado, antes de esta vez.
   *
   * Devuelve 0 si es un agente nuevo o si no tenía icono.
   */
  private function iconoGuardado(): int {
    $id = $this->entity->id();

    if ($id === NULL) {
      return 0;
    }

    $guardado = $this->entityTypes->getStorage('sld_agent')->loadUnchanged($id);

    return $guardado instanceof DiagnosticAgentInterface
      ? $guardado->getWelcomeIconFid()
      : 0;
  }

  /**
   * Consolida el icono subido y suelta el que deja de usarse.
   *
   * Un archivo recién subido nace TEMPORAL y Drupal lo borra a las pocas
   * horas: sin marcarlo como permanente, el icono desaparece solo al cabo de
   * un rato y nadie relaciona la causa con el efecto.
   *
   * Soltar el anterior importa por lo contrario: un archivo con uso
   * registrado no se puede borrar desde la administración de archivos, así
   * que sin esto cada cambio de icono dejaría el viejo clavado para siempre.
   *
   * @param int $anterior
   *   Icono que tenía antes de guardar.
   */
  private function registrarIcono(int $anterior): void {
    $agente = $this->entity;
    assert($agente instanceof DiagnosticAgentInterface);

    $actual = $agente->getWelcomeIconFid();

    if ($actual === $anterior) {
      return;
    }

    $almacen = $this->entityTypes->getStorage('file');

    if ($actual > 0) {
      $nuevo = $almacen->load($actual);

      if ($nuevo instanceof FileInterface) {
        if (!$nuevo->isPermanent()) {
          $nuevo->setPermanent();
          $nuevo->save();
        }

        $this->fileUsage->add($nuevo, 'sales_leadership_diagnostic', 'sld_agent', (string) $agente->id());
      }
    }

    if ($anterior > 0) {
      $viejo = $almacen->load($anterior);

      if ($viejo instanceof FileInterface) {
        $this->fileUsage->delete($viejo, 'sales_leadership_diagnostic', 'sld_agent', (string) $agente->id());
      }
    }
  }

  /**
   * Si ya existe un agente con ese identificador.
   *
   * @param string $id
   *   Identificador propuesto.
   */
  public function agentExists(string $id): bool {
    return $this->entityTypes->getStorage('sld_agent')->load($id) !== NULL;
  }

}
