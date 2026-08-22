<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticReadiness;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineFactory;
use Drupal\sales_leadership_diagnostic\Service\WordPress\PluginVersionTracker;

/**
 * Informe de estado del módulo en /admin/reports/status.
 *
 * El objetivo es que un administrador detecte una configuración incompleta
 * ANTES de que un alumno se encuentre con un error. El módulo falla de forma
 * cerrada (§13), así que una configuración a medias no se traduce en un
 * comportamiento degradado sino en accesos denegados: sin este informe, la
 * causa sería difícil de diagnosticar.
 *
 * La decisión de qué falta la toma DiagnosticReadiness, el mismo servicio que
 * consulta el panel del alumno. Aquí solo se traduce a lenguaje administrativo.
 */
final class DiagnosticRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly DiagnosticReadiness $readiness,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DiagnosticEngineFactory $engineFactory,
    private readonly PluginVersionTracker $pluginVersions,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [
      'sales_leadership_diagnostic_secrets' => $this->checkSecrets(),
      'sales_leadership_diagnostic_wordpress' => $this->checkWordPress(),
      'sales_leadership_diagnostic_agent' => $this->checkAgent(),
      'sales_leadership_diagnostic_plugin' => $this->checkPluginVersion(),
    ];

    if ($this->engineFactory->isMockActive()) {
      $requirements['sales_leadership_diagnostic_mock_engine'] = $this->warnAboutMockEngine();
    }

    return $requirements;
  }

  /**
   * Informa de la versión del plugin de WordPress instalada en el cliente.
   *
   * Este requisito nació de un problema concreto: el módulo empezó a necesitar
   * un dato que el plugin del cliente todavía no enviaba, y Drupal no tenía
   * forma de saberlo. Degradó sin romper —que era lo correcto— pero nadie se
   * enteró hasta leer el registro.
   */
  private function checkPluginVersion(): array {
    $title = $this->t('Diagnostic AI: plugin de WordPress');
    $minimum = SalesLeadershipDiagnostic::MINIMUM_PLUGIN_VERSION;

    if (!$this->pluginVersions->hasObserved()) {
      return [
        'title' => $title,
        'value' => $this->t('Sin datos todavía'),
        'severity' => RequirementSeverity::OK,
        'description' => $this->t('La versión se conocerá la primera vez que un alumno acceda o que se compruebe una autorización.'),
      ];
    }

    $seenAt = $this->pluginVersions->getSeenAt();
    $version = $this->pluginVersions->getVersion();

    if ($version === NULL) {
      // Informar de la versión se añadió en la 1.1.0, de modo que no decirla
      // identifica sin ambigüedad a un plugin anterior.
      return [
        'title' => $title,
        'value' => $this->t('Anterior a @minima', ['@minima' => $minimum]),
        'severity' => RequirementSeverity::Warning,
        'description' => $this->t('El plugin instalado no informa de su versión, lo que solo ocurre en versiones anteriores a la @minima. Algunas funciones se comportarán de forma degradada: por ejemplo, el límite de un diagnóstico por periodo no puede aplicarse porque falta la fecha de inicio del acceso. Actualiza el plugin en el WordPress del cliente.', [
          '@minima' => $minimum,
        ]),
      ];
    }

    if (!$this->pluginVersions->meetsMinimum($minimum)) {
      return [
        'title' => $title,
        'value' => $this->t('@version (se necesita @minima)', [
          '@version' => $version,
          '@minima' => $minimum,
        ]),
        'severity' => RequirementSeverity::Warning,
        'description' => $this->t('El plugin instalado en WordPress es anterior al que este módulo necesita. Actualízalo para que todas las funciones se comporten como corresponde.'),
      ];
    }

    return [
      'title' => $title,
      'value' => $version,
      'severity' => RequirementSeverity::OK,
      'description' => $this->t('Comprobado por última vez el @fecha.', [
        '@fecha' => $seenAt === NULL
          ? $this->t('(desconocido)')
          : $this->dateFormatter->format($seenAt, 'short'),
      ]),
    ];
  }

  /**
   * Avisa de forma destacada si el motor simulado está activo.
   *
   * Es la red de seguridad del ajuste: un entorno que lo tuviera habilitado
   * por error entregaría diagnósticos inventados a alumnos reales, y nada en
   * la interfaz del alumno lo delataría. El informe de estado sí.
   */
  private function warnAboutMockEngine(): array {
    return [
      'title' => $this->t('Diagnostic AI: motor de IA'),
      'value' => $this->t('SIMULADO — no se está usando IA real'),
      'severity' => RequirementSeverity::Error,
      'description' => $this->t("El ajuste <code>@ajuste</code> está activo, de modo que los diagnósticos se generan con respuestas de prueba y no con el proveedor de IA. Es correcto en desarrollo; en staging o producción debe retirarse de settings.php de inmediato.", [
        // El nombre del ajuste va como argumento y no incrustado en la cadena:
        // así el texto traducible no arrastra comillas escapadas y quien
        // traduzca no puede romperlo por accidente.
        '@ajuste' => "\$settings['" . DiagnosticEngineFactory::MOCK_SETTING . "']",
      ]),
    ];
  }

  /**
   * Verifica que los secretos estén presentes en el entorno.
   *
   * Reporta únicamente el nombre de las variables ausentes. Nunca su valor,
   * ni siquiera truncado (§43).
   */
  private function checkSecrets(): array {
    $missing = $this->readiness->missingSecrets();

    if ($missing === []) {
      return [
        'title' => $this->t('Diagnostic AI: secretos'),
        'value' => $this->t('Configurados'),
        'severity' => RequirementSeverity::OK,
      ];
    }

    $names = array_map(static fn (string $name): string => strtoupper($name), $missing);

    return [
      'title' => $this->t('Diagnostic AI: secretos'),
      'value' => $this->formatPlural(
        count($missing),
        'Falta 1 variable de entorno',
        'Faltan @count variables de entorno',
      ),
      'severity' => RequirementSeverity::Error,
      'description' => $this->t('Sin estas variables el módulo deniega el acceso por diseño. Defínalas en el entorno: @names. Consulte .env.example para la descripción de cada una.', [
        '@names' => implode(', ', $names),
      ]),
    ];
  }

  /**
   * Verifica que la integración con WordPress esté configurada.
   */
  private function checkWordPress(): array {
    $missing = $this->readiness->missingWordPressSettings();

    if ($missing === []) {
      return [
        'title' => $this->t('Diagnostic AI: WordPress / LearnDash'),
        'value' => $this->t('Configurado'),
        'severity' => RequirementSeverity::OK,
      ];
    }

    $labels = [
      'api_base_url' => $this->t('URL base de la API'),
      'course_id' => $this->t('ID del curso autorizador'),
    ];

    $pending = array_map(
      static fn (string $key): string => (string) ($labels[$key] ?? $key),
      $missing,
    );

    return [
      'title' => $this->t('Diagnostic AI: WordPress / LearnDash'),
      'value' => $this->t('Configuración incompleta'),
      'severity' => RequirementSeverity::Warning,
      'description' => $this->t('Falta por definir: @items. Ningún alumno podrá ser autorizado hasta completarlo.', [
        '@items' => implode(', ', $pending),
      ]),
    ];
  }

  /**
   * Verifica que el agente del cliente esté cargado.
   *
   * El prompt y las instrucciones son propiedad del cliente (§15) y se cargan
   * desde la interfaz administrativa. Mientras falten, el diagnóstico no puede
   * ejecutarse.
   */
  private function checkAgent(): array {
    if (!$this->readiness->isAgentLoaded()) {
      return [
        'title' => $this->t('Diagnostic AI: agente'),
        'value' => $this->t('Sin prompt cargado'),
        'severity' => RequirementSeverity::Warning,
        'description' => $this->t('El prompt y las instrucciones del agente los proporciona el cliente. Hasta cargarlos no es posible iniciar diagnósticos.'),
      ];
    }

    $version = trim((string) $this->configFactory
      ->get('sales_leadership_diagnostic.diagnostic')
      ->get('version'));

    return [
      'title' => $this->t('Diagnostic AI: agente'),
      'value' => $this->t('Cargado (versión @version)', [
        '@version' => $version !== '' ? $version : $this->t('sin definir'),
      ]),
      'severity' => RequirementSeverity::OK,
    ];
  }

}
