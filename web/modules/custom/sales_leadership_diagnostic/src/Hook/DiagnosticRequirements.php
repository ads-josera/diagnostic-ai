<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;

/**
 * Informe de estado del módulo en /admin/reports/status.
 *
 * El objetivo es que un administrador detecte una configuración incompleta
 * ANTES de que un alumno se encuentre con un error. El módulo falla de forma
 * cerrada (§13), así que una configuración a medias no se traduce en un
 * comportamiento degradado sino en accesos denegados: sin este informe, la
 * causa sería difícil de diagnosticar.
 */
final class DiagnosticRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly SecretsProvider $secrets,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    return [
      'sales_leadership_diagnostic_secrets' => $this->checkSecrets(),
      'sales_leadership_diagnostic_wordpress' => $this->checkWordPress(),
      'sales_leadership_diagnostic_agent' => $this->checkAgent(),
    ];
  }

  /**
   * Verifica que los secretos estén presentes en el entorno.
   *
   * Reporta únicamente el nombre de las variables ausentes. Nunca su valor,
   * ni siquiera truncado (§43).
   */
  private function checkSecrets(): array {
    $missing = $this->secrets->missing();

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
    $config = $this->configFactory->get('sales_leadership_diagnostic.settings');
    $pending = [];

    if (trim((string) $config->get('wordpress.api_base_url')) === '') {
      $pending[] = $this->t('URL base de la API');
    }
    if (trim((string) $config->get('wordpress.course_id')) === '') {
      $pending[] = $this->t('ID del curso autorizador');
    }

    if ($pending === []) {
      return [
        'title' => $this->t('Diagnostic AI: WordPress / LearnDash'),
        'value' => $this->t('Configurado'),
        'severity' => RequirementSeverity::OK,
      ];
    }

    return [
      'title' => $this->t('Diagnostic AI: WordPress / LearnDash'),
      'value' => $this->t('Configuración incompleta'),
      'severity' => RequirementSeverity::Warning,
      'description' => $this->t('Falta por definir: @items. Ningún alumno podrá ser autorizado hasta completarlo.', [
        '@items' => implode(', ', array_map(static fn ($item): string => (string) $item, $pending)),
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
    $config = $this->configFactory->get('sales_leadership_diagnostic.diagnostic');
    $version = trim((string) $config->get('version'));
    $hasPrompt = trim((string) $config->get('system_prompt')) !== '';

    if (!$hasPrompt) {
      return [
        'title' => $this->t('Diagnostic AI: agente'),
        'value' => $this->t('Sin prompt cargado'),
        'severity' => RequirementSeverity::Warning,
        'description' => $this->t('El prompt y las instrucciones del agente los proporciona el cliente. Hasta cargarlos no es posible iniciar diagnósticos.'),
      ];
    }

    return [
      'title' => $this->t('Diagnostic AI: agente'),
      'value' => $this->t('Cargado (versión @version)', ['@version' => $version !== '' ? $version : $this->t('sin definir')]),
      'severity' => RequirementSeverity::OK,
    ];
  }

}
