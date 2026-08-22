<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuración de integración, proveedor de IA y límites de seguridad.
 *
 * Este formulario NO edita secretos. Muestra si están presentes, nunca su
 * valor: la configuración de Drupal es exportable a Git y un secreto guardado
 * aquí acabaría en el repositorio (§29).
 */
final class SettingsForm extends ConfigFormBase {

  private const CONFIG_NAME = 'sales_leadership_diagnostic.settings';

  /**
   * Hosts para los que se tolera http:// en lugar de https://.
   *
   * Sirven exclusivamente a entornos de desarrollo local. Cualquier otro host
   * debe usar HTTPS: el canal transporta identidades de alumnos y decisiones
   * de autorización (§9).
   */
  private const LOCAL_HOST_SUFFIXES = [
    '.ddev.site',
    '.test',
    '.local',
    '.localhost',
  ];

  private const LOCAL_HOSTS = [
    'localhost',
    '127.0.0.1',
    '::1',
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly SecretsProvider $secrets,
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
      $container->get(SecretsProvider::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sales_leadership_diagnostic_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['secrets'] = $this->buildSecretsStatus();
    $form['wordpress'] = $this->buildWordPressSection();
    $form['openai'] = $this->buildEngineSection();
    $form['security'] = $this->buildSecuritySection();

    return parent::buildForm($form, $form_state);
  }

  /**
   * Estado de los secretos, sin exponer ningún valor.
   */
  private function buildSecretsStatus(): array {
    $labels = [
      SecretsProvider::JWT_SHARED_SECRET => $this->t('Firma del token SSO de WordPress'),
      SecretsProvider::WP_HMAC_SECRET => $this->t('Firma de las llamadas hacia WordPress'),
      SecretsProvider::OPENAI_API_KEY => $this->t('API key del proveedor de IA'),
    ];

    $rows = [];
    foreach ($labels as $name => $label) {
      $configured = $this->secrets->has($name);
      $rows[] = [
        $label,
        ['data' => ['#markup' => '<code>' . strtoupper($name) . '</code>']],
        $configured ? $this->t('✔ Configurado') : $this->t('✘ No configurado'),
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Secretos'),
      '#open' => $this->secrets->missing() !== [],
      'description' => [
        '#markup' => '<p>' . $this->t('Los secretos se definen mediante variables de entorno y no son editables desde aquí: la configuración de Drupal se exporta a Git y un secreto guardado en ella acabaría en el repositorio.') . '</p>'
        . '<p>' . $this->t('En desarrollo se definen en <code>.ddev/config.local.yaml</code> y se aplican con <code>ddev restart</code>. En staging y producción, en las variables de entorno del servidor. Consulte <code>.env.example</code>.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Uso'), $this->t('Variable de entorno'), $this->t('Estado')],
        '#rows' => $rows,
      ],
    ];
  }

  /**
   * Integración con WordPress / LearnDash.
   */
  private function buildWordPressSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('WordPress / LearnDash'),
      '#open' => TRUE,
      // Sin #tree, los valores de todas las secciones se aplanan en un mismo
      // espacio de nombres y campos homónimos de secciones distintas (por
      // ejemplo los dos "timeout") se sobrescriben entre sí en silencio.
      '#tree' => TRUE,
      '#description' => $this->t('WordPress es la fuente de verdad sobre el derecho de acceso. Drupal solo pregunta si el alumno tiene el curso indicado.'),

      'api_base_url' => [
        '#type' => 'url',
        '#title' => $this->t('URL base de la API de WordPress'),
        '#description' => $this->t('Raíz del sitio WordPress, sin la ruta del endpoint. Ejemplo: <code>https://salesbumm.com</code>'),
        '#maxlength' => 255,
        // Normaliza la barra final para que el cliente HTTP no tenga que
        // defenderse de URLs con doble barra.
        '#config_target' => new ConfigTarget(
          self::CONFIG_NAME,
          'wordpress.api_base_url',
          toConfig: static fn (?string $value): string => rtrim(trim((string) $value), '/'),
        ),
      ],

      'course_id' => [
        '#type' => 'textfield',
        '#title' => $this->t('ID del curso o producto que autoriza el diagnóstico'),
        '#description' => $this->t('Identificador en LearnDash del curso cuya compra habilita el diagnóstico. Configurable a propósito: no debe estar escrito en el código.'),
        '#maxlength' => 64,
        '#config_target' => new ConfigTarget(
          self::CONFIG_NAME,
          'wordpress.course_id',
          toConfig: static fn (?string $value): string => trim((string) $value),
        ),
      ],

      'timeout' => [
        '#type' => 'number',
        '#title' => $this->t('Timeout de las peticiones (segundos)'),
        '#description' => $this->t('Agotado el plazo, el módulo deniega el acceso salvo que exista una autorización válida en cache.'),
        '#min' => 1,
        '#max' => 120,
        '#config_target' => self::CONFIG_NAME . ':wordpress.timeout',
      ],

      'cache_ttl_granted' => [
        '#type' => 'number',
        '#title' => $this->t('Cache de autorizaciones concedidas (segundos)'),
        '#description' => $this->t('Reduce la carga sobre WordPress. Cuanto mayor sea, más tarda en aplicarse una revocación de acceso. Recomendado: 300–900.'),
        '#min' => 0,
        '#max' => 86400,
        '#config_target' => self::CONFIG_NAME . ':wordpress.cache_ttl_granted',
      ],

      'cache_ttl_denied' => [
        '#type' => 'number',
        '#title' => $this->t('Cache de autorizaciones denegadas (segundos)'),
        '#description' => $this->t('Debe ser bajo: un alumno que acaba de comprar el curso no debería esperar a que expire. Recomendado: 30–120.'),
        '#min' => 0,
        '#max' => 3600,
        '#config_target' => self::CONFIG_NAME . ':wordpress.cache_ttl_denied',
      ],

      'cache_grace_period' => [
        '#type' => 'number',
        '#title' => $this->t('Periodo de gracia si WordPress no responde (segundos)'),
        '#description' => $this->t('Si WordPress no está disponible, un alumno verificado dentro de este plazo conserva el acceso; el resto lo pierde. Solo se reutilizan autorizaciones concedidas, nunca denegaciones, así que una caída jamás da acceso a quien no lo tenía. Poner <strong>0</strong> desactiva la excepción y deniega siempre que no se pueda comprobar.'),
        '#min' => 0,
        '#max' => 86400,
        '#config_target' => self::CONFIG_NAME . ':wordpress.cache_grace_period',
      ],
    ];
  }

  /**
   * Proveedor de IA.
   */
  private function buildEngineSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Proveedor de IA'),
      '#open' => TRUE,
      '#tree' => TRUE,

      'available_models' => [
        '#type' => 'textarea',
        '#title' => $this->t('Catálogo de modelos disponibles'),
        '#rows' => 5,
        '#description' => $this->t('Un identificador de modelo por línea, tal como lo espera la API del proveedor. Añadir o retirar un modelo se hace aquí, sin tocar código ni desplegar (§30). El identificador debe copiarse literalmente de la documentación del proveedor: no es el nombre comercial.'),
        '#config_target' => new ConfigTarget(
          self::CONFIG_NAME,
          'openai.available_models',
          // La configuración guarda una lista; el formulario muestra una línea
          // por elemento. Aquí se traduce entre ambas formas.
          fromConfig: static fn (?array $value): string => implode("\n", $value ?? []),
          toConfig: [self::class, 'parseModelList'],
        ),
      ],

      'model' => [
        '#type' => 'select',
        '#title' => $this->t('Modelo en uso'),
        '#description' => $this->t('Se elige del catálogo de arriba. Guarda el catálogo primero si acabas de añadir un modelo nuevo.'),
        '#options' => $this->getModelOptions(),
        '#empty_option' => $this->t('- Ninguno seleccionado -'),
        '#config_target' => new ConfigTarget(
          self::CONFIG_NAME,
          'openai.model',
          toConfig: static fn (?string $value): string => trim((string) $value),
        ),
      ],

      'timeout' => [
        '#type' => 'number',
        '#title' => $this->t('Timeout de las peticiones (segundos)'),
        '#description' => $this->t('El turno que genera el resultado final suele ser el más lento del diagnóstico.'),
        '#min' => 5,
        '#max' => 300,
        '#config_target' => self::CONFIG_NAME . ':openai.timeout',
      ],

      'max_completion_tokens' => [
        '#type' => 'number',
        '#title' => $this->t('Tokens máximos por respuesta'),
        '#description' => $this->t('Los modelos que razonan consumen parte de este presupuesto antes de empezar a escribir. Si se agota, la respuesta llega cortada y el diagnóstico falla con un error de formato cuya causa real no es evidente. Conviene que sea holgado. Recomendado: 2000 o más.'),
        '#min' => 256,
        '#max' => 32000,
        '#config_target' => self::CONFIG_NAME . ':openai.max_completion_tokens',
      ],

      'max_retries' => [
        '#type' => 'number',
        '#title' => $this->t('Reintentos ante errores transitorios'),
        '#description' => $this->t('Solo se reintentan errores transitorios (5xx y timeouts). Un error 4xx nunca se reintenta: repetirlo no cambia el resultado y multiplica el coste.'),
        '#min' => 0,
        '#max' => 5,
        '#config_target' => self::CONFIG_NAME . ':openai.max_retries',
      ],
    ];
  }

  /**
   * Límites de seguridad y uso.
   */
  private function buildSecuritySection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Seguridad y límites de uso'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('Estos límites protegen frente al abuso y acotan el coste del proveedor de IA.'),

      'messages_per_window' => [
        '#type' => 'number',
        '#title' => $this->t('Mensajes por ventana y alumno'),
        '#min' => 1,
        '#max' => 1000,
        '#config_target' => self::CONFIG_NAME . ':security.messages_per_window',
      ],

      'window_seconds' => [
        '#type' => 'number',
        '#title' => $this->t('Duración de la ventana (segundos)'),
        '#min' => 10,
        '#max' => 86400,
        '#config_target' => self::CONFIG_NAME . ':security.window_seconds',
      ],

      'max_diagnostics_per_day' => [
        '#type' => 'number',
        '#title' => $this->t('Diagnósticos que un alumno puede iniciar por día'),
        '#min' => 1,
        '#max' => 100,
        '#config_target' => self::CONFIG_NAME . ':security.max_diagnostics_per_day',
      ],

      'max_turns' => [
        '#type' => 'number',
        '#title' => $this->t('Turnos máximos por diagnóstico'),
        '#description' => $this->t('Un diagnóstico tiene un final. Este tope evita que una conversación crezca indefinidamente en latencia y coste: cada turno reenvía todo el historial al proveedor.'),
        '#min' => 4,
        '#max' => 200,
        '#config_target' => self::CONFIG_NAME . ':security.max_turns',
      ],

      'session_timeout' => [
        '#type' => 'number',
        '#title' => $this->t('Inactividad que cierra una sesión de diagnóstico (segundos)'),
        '#min' => 300,
        '#max' => 604800,
        '#config_target' => self::CONFIG_NAME . ':security.session_timeout',
      ],

      'sso_token_ttl' => [
        '#type' => 'number',
        '#title' => $this->t('Vigencia máxima aceptada del token SSO (segundos)'),
        '#description' => $this->t('El token solo tiene que sobrevivir a una redirección. Cuanto más corto, menor es la ventana de un token robado. Recomendado: 60–120.'),
        '#min' => 30,
        '#max' => 300,
        '#config_target' => self::CONFIG_NAME . ':security.sso_token_ttl',
      ],

      'sso_token_leeway' => [
        '#type' => 'number',
        '#title' => $this->t('Tolerancia de desfase de reloj (segundos)'),
        '#description' => $this->t('WordPress y Drupal son máquinas distintas y sus relojes no coinciden exactamente. Un valor alto amplía la vida efectiva del token. Recomendado: 30.'),
        '#min' => 0,
        '#max' => 120,
        '#config_target' => self::CONFIG_NAME . ':security.sso_token_leeway',
      ],
    ];
  }

  /**
   * Convierte el textarea del catálogo en la lista que guarda la config.
   *
   * Es público y estático porque lo invoca ConfigTarget, que no dispone de la
   * instancia del formulario.
   *
   * @return string[]
   *   Los identificadores, sin espacios, vacíos ni duplicados.
   */
  public static function parseModelList(?string $value): array {
    $lines = preg_split('/\R/', (string) $value) ?: [];

    $models = array_map(static fn (string $line): string => trim($line), $lines);
    $models = array_filter($models, static fn (string $line): bool => $line !== '');

    // array_values reindexa: la configuración de tipo sequence espera una
    // lista, y unos índices con huecos la convertirían en un mapa.
    return array_values(array_unique($models));
  }

  /**
   * Opciones del desplegable, construidas desde el catálogo guardado.
   *
   * @return array<string, string>
   *   Los modelos del catálogo, listos para un desplegable.
   */
  private function getModelOptions(): array {
    $models = $this->config(self::CONFIG_NAME)->get('openai.available_models');
    $models = is_array($models) ? $models : [];

    return array_combine($models, $models) ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $this->validateWordPressUrl($form_state);
    $this->validateCacheRelationship($form_state);
    $this->validateSelectedModel($form_state);
    $this->validateModelIdentifiers($form_state);
  }

  /**
   * Avisa de identificadores que parecen nombres comerciales.
   *
   * Los proveedores de IA nombran sus modelos de dos formas: un nombre
   * comercial para las personas («GPT-5.6 Terra») y un identificador para la
   * API, en minúsculas y sin espacios («gpt-5.6-terra-2026-01-15»). Solo el
   * segundo funciona en una llamada.
   *
   * Confundirlos no da un error al guardar sino mucho después, cuando un
   * alumno intenta hacer su diagnóstico y el proveedor responde que el modelo
   * no existe. Este aviso adelanta ese descubrimiento al momento de
   * configurarlo.
   *
   * No bloquea el guardado: es una heurística sobre convenciones de terceros,
   * no una regla que podamos garantizar. Avisar y dejar decidir es preferible
   * a impedir una configuración que quizá sea correcta.
   */
  private function validateModelIdentifiers(FormStateInterface $form_state): void {
    $catalogue = self::parseModelList((string) $form_state->getValue(['openai', 'available_models']));

    $suspicious = array_filter(
      $catalogue,
      static fn (string $model): bool => preg_match('/^[a-z0-9][a-z0-9._\-]*$/', $model) !== 1,
    );

    if ($suspicious === []) {
      return;
    }

    $this->messenger()->addWarning($this->t('Estos identificadores parecen nombres comerciales y no identificadores de API: @models. Un identificador de API va en minúsculas, sin espacios y con guiones. Si son estos los que has copiado de la interfaz del proveedor, busca el identificador técnico en su documentación: de lo contrario los diagnósticos fallarán con un error de "modelo no encontrado" en el momento de usarlos.', [
      '@models' => implode(', ', $suspicious),
    ]));
  }

  /**
   * Avisa si el modelo elegido desaparece del catálogo.
   *
   * Ocurre al retirar del catálogo el modelo que estaba en uso. No se bloquea
   * el guardado —puede ser justo lo que el administrador quiere hacer antes de
   * elegir otro— pero sí se advierte, porque el diagnóstico deja de funcionar.
   */
  private function validateSelectedModel(FormStateInterface $form_state): void {
    $selected = trim((string) $form_state->getValue(['openai', 'model']));

    if ($selected === '') {
      return;
    }

    $catalogue = self::parseModelList((string) $form_state->getValue(['openai', 'available_models']));

    if (!in_array($selected, $catalogue, TRUE)) {
      $this->messenger()->addWarning($this->t('El modelo en uso (@model) ya no está en el catálogo. Elige otro o vuelve a añadirlo, o los diagnósticos fallarán.', [
        '@model' => $selected,
      ]));
    }
  }

  /**
   * Exige HTTPS salvo en hosts de desarrollo local.
   *
   * El canal transporta identidades de alumnos y decisiones de autorización;
   * sobre HTTP viajarían en claro (§9).
   */
  private function validateWordPressUrl(FormStateInterface $form_state): void {
    $url = trim((string) $form_state->getValue(['wordpress', 'api_base_url']));

    if ($url === '') {
      return;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));

    if ($scheme === 'https') {
      return;
    }

    if ($this->isLocalHost($host)) {
      $this->messenger()->addWarning($this->t('La URL de WordPress usa HTTP. Se acepta porque %host es un host de desarrollo local, pero en staging y producción debe ser HTTPS.', [
        '%host' => $host,
      ]));
      return;
    }

    $form_state->setErrorByName(
      'wordpress][api_base_url',
      $this->t('La URL de WordPress debe usar HTTPS. Este canal transporta identidades de alumnos y decisiones de autorización.'),
    );
  }

  /**
   * Avisa si la cache de denegaciones supera a la de concesiones.
   *
   * No es un error, pero casi siempre es un descuido: retrasaría el acceso de
   * un alumno que acaba de comprar el curso más de lo que retrasa la
   * revocación de uno que lo perdió, que es justo lo contrario de lo deseable.
   */
  private function validateCacheRelationship(FormStateInterface $form_state): void {
    $granted = (int) $form_state->getValue(['wordpress', 'cache_ttl_granted']);
    $denied = (int) $form_state->getValue(['wordpress', 'cache_ttl_denied']);

    if ($denied > $granted && $granted > 0) {
      $this->messenger()->addWarning($this->t('La cache de denegaciones (@denied s) es mayor que la de concesiones (@granted s). Un alumno que acabe de comprar el curso tardará más en obtener acceso del que tarda en aplicarse una revocación.', [
        '@denied' => $denied,
        '@granted' => $granted,
      ]));
    }
  }

  /**
   * Indica si un host pertenece a un entorno de desarrollo local.
   */
  private function isLocalHost(string $host): bool {
    if (in_array($host, self::LOCAL_HOSTS, TRUE)) {
      return TRUE;
    }

    foreach (self::LOCAL_HOST_SUFFIXES as $suffix) {
      if (str_ends_with($host, $suffix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
