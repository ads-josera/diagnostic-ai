<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Knowledge\DocumentTextExtractor;
use Drupal\sales_leadership_diagnostic\Service\Knowledge\KnowledgeLibrary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Documentos de conocimiento del agente.
 *
 * Los administra el gestor, no el administrador del sitio: son metodología,
 * igual que el prompt, y quien decide cómo diagnostica el agente es la misma
 * persona. Por eso esta pantalla cuelga del listado de resultados y no de la
 * página de ajustes, que el gestor no puede abrir.
 *
 * No es un ConfigFormBase. Aquí no se editan campos que se guardan al pulsar
 * «Guardar»: se añaden y se quitan documentos, y cada acción tiene su efecto
 * inmediato. Forzar ese flujo dentro de la Form API de configuración habría
 * dejado un formulario donde subir un archivo no hace nada hasta guardar, que
 * es exactamente el tipo de ajuste que parece roto.
 */
final class KnowledgeDocumentsForm extends FormBase {

  /**
   * Tamaño máximo por documento.
   *
   * Generoso a propósito: una metodología en PDF con imágenes ocupa varios
   * megas y el límite no está para ahorrar disco, sino para que un archivo
   * equivocado no se suba por accidente.
   */
  private const MAX_SIZE = '20 MB';

  /**
   * Biblioteca de documentos.
   */
  private KnowledgeLibrary $library;

  /**
   * Fábrica de configuración.
   */
  private ConfigFactoryInterface $configs;

  /**
   * Gestor de tipos de entidad.
   */
  private EntityTypeManagerInterface $entityTypes;

  /**
   * Registro de uso de archivos.
   */
  private FileUsageInterface $fileUsage;

  /**
   * Registro de agentes.
   */
  private AgentRegistry $agents;

  /**
   * Agente cuya biblioteca se está editando.
   */
  private ?DiagnosticAgentInterface $agente = NULL;

  /**
   * Construye el formulario.
   *
   * Las propiedades NO son `readonly`, y no es un descuido.
   *
   * Un formulario con subida AJAX se guarda en la caché de formularios entre
   * la construcción y el envío: Drupal serializa el objeto y, al recuperarlo,
   * `DependencySerializationTrait` vuelve a inyectarle los servicios. Esa
   * reinyección ocurre en el ámbito del trait, no en el de esta clase, y una
   * propiedad `readonly` solo puede inicializarse desde la clase que la
   * declara. El resultado era un error fatal al enviar: «$library must not be
   * accessed before initialization», con la página de subida cargando
   * perfectamente.
   *
   * @param \Drupal\sales_leadership_diagnostic\Service\Knowledge\KnowledgeLibrary $library
   *   Biblioteca de documentos.
   * @param \Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry $agents
   *   Registro de agentes.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configs
   *   Fábrica de configuración.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypes
   *   Gestor de tipos de entidad.
   * @param \Drupal\file\FileUsage\FileUsageInterface $fileUsage
   *   Registro de uso de archivos.
   */
  public function __construct(
    KnowledgeLibrary $library,
    AgentRegistry $agents,
    ConfigFactoryInterface $configs,
    EntityTypeManagerInterface $entityTypes,
    FileUsageInterface $fileUsage,
  ) {
    $this->library = $library;
    $this->agents = $agents;
    $this->configs = $configs;
    $this->entityTypes = $entityTypes;
    $this->fileUsage = $fileUsage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(KnowledgeLibrary::class),
      $container->get(AgentRegistry::class),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('file.usage'),
    );
  }

  /**
   * Vuelve a inyectar los servicios tras recuperar el formulario de la cache.
   *
   * Un formulario con subida AJAX se guarda serializado entre la construcción
   * y el envío. Al recuperarlo, los servicios inyectados por el contenedor no
   * vuelven solos: se comprobó midiéndolo —serializar y deserializar este
   * formulario dejaba las cuatro propiedades sin inicializar— y el síntoma era
   * un error fatal al pulsar «Añadir», con la página de subida cargando sin un
   * solo aviso.
   *
   * Se restauran aquí, en el ámbito de esta clase, que es el único sitio donde
   * se pueden asignar con garantías.
   */
  public function __wakeup(): void {
    parent::__wakeup();

    // Es la excepción en la que el contenedor SÍ se pide de forma estática:
    // __wakeup() no recibe argumentos, así que no hay ningún otro sitio por
    // donde inyectarlo. El propio Drupal lo resuelve igual en
    // DependencySerializationTrait.
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $container = \Drupal::getContainer();

    $this->library = $container->get(KnowledgeLibrary::class);
    $this->agents = $container->get(AgentRegistry::class);
    $this->configs = $container->get('config.factory');
    $this->entityTypes = $container->get('entity_type.manager');
    $this->fileUsage = $container->get('file.usage');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sales_leadership_diagnostic_knowledge_documents';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DiagnosticAgentInterface $sld_agent = NULL): array {
    $disponibles = $this->agents->getUsable();

    // Con la ruta sin agente hay que decidir cuál se edita. Con uno solo es
    // obvio; con varios NO se elige por él: hacerlo silenciosamente llevaba a
    // editar la biblioteca de un agente creyendo estar en la de otro, porque
    // el orden lo decidía el peso y el nombre. Se le pide que elija.
    $this->agente = $sld_agent ?? (count($disponibles) === 1 ? reset($disponibles) : NULL);

    if ($this->agente === NULL) {
      $form['elegir'] = $disponibles === []
        ? [
          '#type' => 'item',
          '#markup' => $this->t('No hay ningún agente configurado todavía. Crea uno antes de cargarle documentos.'),
        ]
        : [
          '#theme' => 'item_list',
          '#title' => $this->t('¿A qué agente quieres cargarle documentos?'),
          '#items' => array_map(
            fn ($agent) => [
              '#type' => 'link',
              '#title' => $agent->label(),
              '#url' => Url::fromRoute(
                'sales_leadership_diagnostic.knowledge_agent',
                ['sld_agent' => $agent->id()],
              ),
            ],
            array_values($disponibles),
          ),
        ];

      return $form;
    }

    $form['#attached']['library'][] = 'sales_leadership_diagnostic/studio';

    // Se dice de QUIEN es la biblioteca de forma destacada y con enlace para
    // cambiar. Antes iba en una línea de texto corriente sobre el párrafo de
    // ayuda, y se leía como descripción y no como «estás editando este
    // agente»: el usuario preguntó cómo se asignaban los documentos a un
    // agente teniendo delante la pantalla que ya lo hacía.
    $otros = array_filter(
      $disponibles,
      fn ($a): bool => $a->id() !== $this->agente->id(),
    );

    $form['agente'] = [
      '#type' => 'item',
      '#wrapper_attributes' => ['class' => ['sld-knowledge__agent']],
      '#markup' => $this->t('Documentos de <strong>@agente</strong>', [
        '@agente' => $this->agente->label(),
      ]),
    ];

    if ($otros !== []) {
      $form['agente']['#description'] = $this->t('Cada agente tiene su propia biblioteca.');
      $form['cambiar'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Ver la de otro agente'),
        // Es navegación secundaria, no una sección del formulario: sin acotar
        // su tamaño se pintaba como un encabezado mayor que «Documentos
        // activos» y competía con lo que de verdad se viene a hacer aquí.
        '#attributes' => ['class' => ['sld-knowledge__switch']],
        '#items' => array_map(
          fn ($a) => [
            '#type' => 'link',
            '#title' => $a->label(),
            '#url' => Url::fromRoute(
              'sales_leadership_diagnostic.knowledge_agent',
              ['sld_agent' => $a->id()],
            ),
          ],
          array_values($otros),
        ),
      ];
    }

    $form['ayuda'] = [
      '#type' => 'item',
      '#markup' => $this->t('El agente lee estos documentos como su metodología autorizada, junto al prompt. Se envían al modelo en cada mensaje de la conversación, así que conviene mantener solo los que hagan falta.'),
    ];

    $this->buildList($form);
    $this->buildUpload($form);
    $this->buildReuse($form);

    return $form;
  }

  /**
   * Tabla de documentos activos, cada uno con su botón de quitar.
   */
  private function buildList(array &$form): void {
    $documentos = $this->library->getDocuments($this->agente);

    $form['lista'] = [
      '#type' => 'details',
      '#title' => $this->t('Documentos activos (@n)', ['@n' => count($documentos)]),
      '#open' => TRUE,
    ];

    if ($documentos === []) {
      $form['lista']['vacio'] = [
        '#type' => 'item',
        '#markup' => $this->t('Todavía no hay ningún documento. El agente trabajará solo con el prompt.'),
      ];

      return;
    }

    $form['lista']['tabla'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Documento'),
        $this->t('Tamaño'),
        $this->t('Tokens aprox.'),
        $this->t('Estado'),
        $this->t('Acciones'),
      ],
    ];

    foreach ($documentos as $doc) {
      $fila = &$form['lista']['tabla'][$doc['fid']];

      $fila['nombre']['#markup'] = $doc['nombre'];
      // ByteSizeMarkup y no format_size(): esa función se retiró en Drupal 11
      // y llamarla da un fatal que se sirve con HTTP 200 en algunas rutas.
      $fila['bytes']['#markup'] = $doc['bytes'] > 0
        ? ByteSizeMarkup::create($doc['bytes'])
        : '—';
      $fila['tokens']['#markup'] = $doc['tokens'] > 0
        ? number_format($doc['tokens'])
        : '—';

      // El motivo del fallo se enseña en la propia fila. Un documento que
      // llegó vacío y no lo dice se da por bueno, y el agente diagnostica sin
      // la metodología que el gestor cree haberle dado.
      $fila['estado']['#markup'] = $doc['correcto']
        ? $this->t('Leído')
        : $this->t('Sin texto: @motivo', ['@motivo' => $doc['motivo']]);

      $fila['acciones'] = [
        '#type' => 'submit',
        '#value' => $this->t('Quitar'),
        '#name' => 'quitar_' . $doc['fid'],
        '#submit' => ['::quitarDocumento'],
        // Quitar no debe exigir que el resto del formulario valide: no hay
        // nada que validar y bloquearlo dejaría al gestor sin poder retirar
        // un archivo defectuoso.
        '#limit_validation_errors' => [],
        '#document_fid' => $doc['fid'],
      ];
    }

    $total = $this->library->getTotalTokens($this->agente);

    $form['lista']['total'] = [
      '#type' => 'item',
      '#markup' => $total > KnowledgeLibrary::TOKENS_AVISO
        ? $this->t('Total: <strong>@n tokens</strong>. Es un volumen alto y viaja al proveedor en cada mensaje: revisa si todos los documentos son necesarios.', ['@n' => number_format($total)])
        : $this->t('Total: <strong>@n tokens</strong> por mensaje.', ['@n' => number_format($total)]),
    ];
  }

  /**
   * Campo de subida.
   */
  private function buildUpload(array &$form): void {
    $form['subir'] = [
      '#type' => 'details',
      '#title' => $this->t('Añadir documentos'),
      '#open' => TRUE,
    ];

    $form['subir']['nuevos'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Archivos'),
      '#multiple' => TRUE,
      // Privado y no público: son la metodología propietaria del cliente. En
      // `public://` bastaría con acertar la URL para descargarlos sin pasar
      // por ningún control de acceso.
      '#upload_location' => 'private://sales-diagnostic/knowledge/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => implode(' ', DocumentTextExtractor::EXTENSIONS)],
        // En BYTES: la restricción de core lo declara como ?int y una cadena
        // como «20 MB» lanza un TypeError que aborta la subida AJAX entera.
        'FileSizeLimit' => ['fileLimit' => (int) Bytes::toNumber(self::MAX_SIZE)],
      ],
      '#description' => $this->t('Formatos: @formatos. Máximo @tamano por archivo. Un PDF escaneado no sirve: hace falta que su texto sea seleccionable.', [
        '@formatos' => implode(', ', DocumentTextExtractor::EXTENSIONS),
        '@tamano' => self::MAX_SIZE,
      ]),
    ];

    $form['subir']['acciones'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Añadir a la biblioteca'),
      ],
    ];
  }

  /**
   * Documentos que ya están en otro agente y pueden reutilizarse aquí.
   *
   * Sin esto, dar el mismo documento a dos agentes obligaba a subirlo dos
   * veces. Además de ocupar el doble, dejaba que una copia se actualizara y la
   * otra no, y a partir de ahí los dos agentes dirían cosas distintas sin que
   * nadie lo notara.
   */
  private function buildReuse(array &$form): void {
    $reutilizables = $this->library->getReusable($this->agente);

    if ($reutilizables === []) {
      return;
    }

    $opciones = [];

    foreach ($reutilizables as $doc) {
      $opciones[$doc['fid']] = $this->t('@nombre — @tokens tokens (en @agentes)', [
        '@nombre' => $doc['nombre'],
        '@tokens' => number_format($doc['tokens']),
        '@agentes' => implode(', ', $doc['agentes']),
      ]);
    }

    $form['reutilizar'] = [
      '#type' => 'details',
      '#title' => $this->t('Reutilizar documentos de otro agente'),
      // Abierta: esta sección solo se pinta cuando hay algo que reutilizar,
      // así que cuando aparece es relevante. Cerrada, la opción existía y no
      // la encontraba nadie, que es como si no estuviera.
      '#open' => TRUE,
      '#description' => $this->t('Es el mismo archivo, no una copia: si lo actualizas, se actualiza para todos los agentes que lo usen.'),
    ];

    $form['reutilizar']['existentes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Documentos disponibles'),
      '#options' => $opciones,
    ];

    $form['reutilizar']['acciones'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Añadir los seleccionados'),
        '#submit' => ['::reutilizarDocumentos'],
        '#limit_validation_errors' => [['existentes']],
      ],
    ];
  }

  /**
   * Añade a este agente documentos que ya están en otro.
   *
   * No se toca el archivo ni su texto: solo se suma su identificador a la
   * lista de este agente. El registro de uso tampoco cambia, porque el módulo
   * ya lo tenía anotado cuando se subió.
   */
  public function reutilizarDocumentos(array &$form, FormStateInterface $form_state): void {
    $elegidos = array_filter((array) $form_state->getValue('existentes'));

    if ($elegidos === []) {
      $this->messenger()->addWarning($this->t('No has seleccionado ningún documento.'));

      return;
    }

    $this->guardarLista(array_merge(
      $this->agente->getKnowledgeFids(),
      array_map('intval', array_keys($elegidos)),
    ));

    $this->messenger()->addStatus($this->formatPlural(
      count($elegidos),
      'Se ha añadido 1 documento a @agente.',
      'Se han añadido @count documentos a @agente.',
      ['@agente' => $this->agente->label()],
    ));
  }

  /**
   * {@inheritdoc}
   *
   * Añade a la biblioteca los archivos recién subidos.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nuevos = $form_state->getValue('nuevos');

    if (!is_array($nuevos) || $nuevos === []) {
      $this->messenger()->addWarning($this->t('No has seleccionado ningún archivo.'));

      return;
    }

    $fids = $this->agente->getKnowledgeFids();

    foreach ($nuevos as $fid) {
      $file = $this->entityTypes->getStorage('file')->load((int) $fid);

      if (!$file instanceof FileInterface) {
        continue;
      }

      // Un archivo subido nace temporal y la limpieza de Drupal lo borra a las
      // seis horas. Sin esto, la metodología desaparecía sola esa misma noche.
      if (!$file->isPermanent()) {
        $file->setPermanent();
        $file->save();
        $this->fileUsage->add($file, 'sales_leadership_diagnostic', 'config', (string) $file->id());
      }

      $resultado = $this->library->remember($file);

      if (!$resultado->correcto) {
        // Se añade igualmente y se avisa: dejarlo fuera en silencio haría
        // pensar que la subida falló, cuando lo que falla es el documento.
        $this->messenger()->addWarning($this->t('«@archivo» se añadió pero no se pudo leer. @motivo', [
          '@archivo' => $file->getFilename(),
          '@motivo' => $resultado->motivo,
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('«@archivo» añadido: @n tokens aproximados.', [
          '@archivo' => $file->getFilename(),
          '@n' => number_format($this->library->estimateTokens($resultado->texto)),
        ]));
      }

      $fids[] = (int) $file->id();
    }

    $this->guardarLista($fids);
  }

  /**
   * Quita un documento de la biblioteca.
   *
   * El archivo NO se borra del disco: quien decide si un archivo sobra es
   * quien administra los archivos del sitio. Aquí solo deja de alimentar al
   * agente, que es lo que se ha pedido.
   */
  public function quitarDocumento(array &$form, FormStateInterface $form_state): void {
    $boton = $form_state->getTriggeringElement();
    $fid = (int) ($boton['#document_fid'] ?? 0);

    if ($fid <= 0) {
      return;
    }

    $this->guardarLista(array_filter(
      $this->agente->getKnowledgeFids(),
      static fn (int $activo): bool => $activo !== $fid,
    ));

    $this->library->forget($fid);

    $file = $this->entityTypes->getStorage('file')->load($fid);

    if ($file instanceof FileInterface) {
      $this->fileUsage->delete($file, 'sales_leadership_diagnostic', 'config', (string) $fid);
      $this->messenger()->addStatus($this->t('«@archivo» ya no alimenta al agente. El archivo sigue en el sitio.', [
        '@archivo' => $file->getFilename(),
      ]));

      return;
    }

    $this->messenger()->addStatus($this->t('Documento retirado de la biblioteca.'));
  }

  /**
   * Guarda la lista de identificadores, sin duplicados y reindexada.
   *
   * @param int[] $fids
   *   Identificadores de archivo.
   */
  private function guardarLista(array $fids): void {
    $this->agente->setKnowledgeFids($fids)->save();
  }

}
