<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Controller\AdminResultsController;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filtros del listado de administración de resultados.
 *
 * Envía por GET a propósito. Un formulario POST dejaría el listado filtrado en
 * una URL que no se puede compartir ni volver a abrir: quien atiende una
 * incidencia necesita poder pasarle a un compañero el enlace exacto de lo que
 * está mirando, y volver a él desde el historial del navegador.
 *
 * Como consecuencia, este formulario NO tiene submitForm(): el navegador
 * navega solo con los valores en la URL, y el controlador los lee de la
 * petición. No hay nada que procesar.
 */
final class ResultsFilterForm extends FormBase {

  public function __construct(
    private readonly AgentRegistry $agents,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(AgentRegistry::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sld_results_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;

    // Sin token CSRF: el formulario no cambia nada, solo consulta. Drupal lo
    // omite por sí mismo en formularios GET, y añadirlo ensuciaría la URL que
    // precisamente se quiere poder compartir.
    $form['#method'] = 'get';

    $form['filtros'] = [
      '#type' => 'details',
      '#title' => $this->t('Filtrar'),
      // Abierto cuando hay algún filtro puesto: si está plegado, quien abre un
      // enlace compartido no ve por qué la lista está recortada.
      '#open' => $this->hasActiveFilters(),
      '#attributes' => ['class' => ['sld-admin-filters']],
    ];

    $form['filtros']['alumno'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alumno'),
      '#description' => $this->t('Parte del nombre de usuario o del correo.'),
      '#default_value' => (string) $query->get('alumno', ''),
      '#size' => 30,
    ];

    // Con un solo agente el desplegable sobra: no hay nada que elegir.
    $agentes = $this->agents->getUsable();

    if (count($agentes) > 1) {
      $opciones = ['' => $this->t('- Cualquiera -')];

      foreach ($agentes as $agente) {
        $opciones[(string) $agente->id()] = $agente->label();
      }

      $form['filtros']['agente'] = [
        '#type' => 'select',
        '#title' => $this->t('Agente'),
        '#options' => $opciones,
        '#default_value' => (string) $query->get('agente', ''),
      ];
    }

    $form['filtros']['estado'] = [
      '#type' => 'select',
      '#title' => $this->t('Estado'),
      '#options' => [
        '' => $this->t('- Cualquiera -'),
        AdminResultsController::ESTADO_SIN_TERMINAR => $this->t('Sin terminar'),
      ] + DiagnosticStatus::allowedValues(),
      '#description' => $this->t('«Sin terminar» reúne los que empezaron y no llegaron a resultado.'),
      '#default_value' => (string) $query->get('estado', ''),
    ];

    $form['filtros']['desde'] = [
      '#type' => 'date',
      '#title' => $this->t('Desde'),
      '#default_value' => (string) $query->get('desde', ''),
    ];

    $form['filtros']['hasta'] = [
      '#type' => 'date',
      '#title' => $this->t('Hasta'),
      '#default_value' => (string) $query->get('hasta', ''),
    ];

    $form['filtros']['acciones'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Filtrar'),
      ],
      'reset' => [
        '#type' => 'link',
        '#title' => $this->t('Limpiar'),
        '#url' => Url::fromRoute('sales_leadership_diagnostic.admin_results'),
        '#attributes' => ['class' => ['button']],
        // Solo aparece si hay algo que limpiar.
        '#access' => $this->hasActiveFilters(),
      ],
    ];

    return $form;
  }

  /**
   * Indica si hay algún filtro aplicado en la URL actual.
   */
  private function hasActiveFilters(): bool {
    $query = $this->getRequest()->query;

    foreach (['alumno', 'desde', 'hasta', 'agente', 'estado'] as $key) {
      if (trim((string) $query->get($key, '')) !== '') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * El formulario se envía por GET y el navegador construye la URL solo. No
   * hay envío que procesar, pero FormInterface obliga a declarar el método.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
