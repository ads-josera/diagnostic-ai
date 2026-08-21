<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Carga del agente de diagnóstico proporcionado por el cliente.
 *
 * El prompt, las instrucciones y la metodología son propiedad del cliente
 * (§15). Este formulario los almacena tal cual: el módulo no los reescribe, no
 * los complementa y no genera preguntas propias.
 */
final class DiagnosticConfigForm extends ConfigFormBase {

  private const CONFIG_NAME = 'sales_leadership_diagnostic.diagnostic';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sales_leadership_diagnostic_agent';
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
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('El contenido de esta página lo proporciona el cliente. El módulo lo aplica sin modificarlo: no reescribe el prompt, no altera la metodología y no inventa preguntas.') . '</p>',
    ];

    $form['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Versión del diagnóstico'),
      '#required' => TRUE,
      '#maxlength' => 32,
      '#size' => 16,
      '#description' => $this->t('Identifica qué prompt e instrucciones se usaron en cada sesión. Cada diagnóstico guarda la versión con la que se ejecutó, de modo que un resultado antiguo siempre es reproducible. Súbela cuando cambie el contenido de abajo.'),
      '#config_target' => new ConfigTarget(
        self::CONFIG_NAME,
        'version',
        toConfig: static fn (?string $value): string => trim((string) $value),
      ),
    ];

    $form['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt principal'),
      '#rows' => 15,
      '#description' => $this->t('Prompt del agente, tal como lo entrega el cliente. Se envía al proveedor de IA sin alteraciones.'),
      '#config_target' => self::CONFIG_NAME . ':system_prompt',
    ];

    $form['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Instrucciones y metodología'),
      '#rows' => 15,
      '#description' => $this->t('Instrucciones, metodología, criterios de evaluación y reglas de análisis del cliente.'),
      '#config_target' => self::CONFIG_NAME . ':instructions',
    ];

    $form['output_contract'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Contrato de salida'),
      '#rows' => 10,
      '#description' => $this->t('Única parte añadida por el módulo, no por el cliente. Indica al agente que devuelva su respuesta en el formato estructurado que Drupal puede validar y almacenar, dejando la parte conversacional en el campo correspondiente. Se define en la fase de integración del agente, una vez conocida la estructura de resultados del cliente.'),
      '#config_target' => self::CONFIG_NAME . ':output_contract',
    ];

    $form['warning'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--warning">'
      . $this->t('Los diagnósticos que ya estén en curso conservan la versión con la que empezaron: guardan una copia del prompt en el momento de iniciarse. Los cambios de esta página solo afectan a los diagnósticos que se inicien a partir de ahora.')
      . '</div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Avisa si cambia el contenido del agente sin cambiar la versión.
   *
   * No es un error, pero rompe la trazabilidad: dos sesiones registradas con
   * la misma versión habrían usado prompts distintos, y un resultado histórico
   * dejaría de ser reproducible (§57).
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Los valores previos se capturan ANTES de delegar en la clase padre:
    // ConfigFormBase::validateForm() vuelca los valores del formulario en el
    // objeto de configuración para validarlo contra el esquema, de modo que
    // después de esa llamada el objeto ya contiene los valores nuevos y
    // compararlo consigo mismo nunca detectaría un cambio.
    $config = $this->config(self::CONFIG_NAME);
    $previousPrompt = trim((string) $config->get('system_prompt'));
    $previousInstructions = trim((string) $config->get('instructions'));
    $previousVersion = trim((string) $config->get('version'));

    parent::validateForm($form, $form_state);

    $contentChanged =
      trim((string) $form_state->getValue('system_prompt')) !== $previousPrompt
      || trim((string) $form_state->getValue('instructions')) !== $previousInstructions;

    $versionChanged =
      trim((string) $form_state->getValue('version')) !== $previousVersion;

    if ($contentChanged && !$versionChanged) {
      $this->messenger()->addWarning($this->t('Ha cambiado el prompt o las instrucciones sin cambiar la versión (@version). Los diagnósticos anteriores quedarán registrados con esa misma versión pese a haberse ejecutado con un contenido distinto, lo que impide reproducirlos con exactitud. Considere subir la versión.', [
        '@version' => $previousVersion,
      ]));
    }
  }

}
