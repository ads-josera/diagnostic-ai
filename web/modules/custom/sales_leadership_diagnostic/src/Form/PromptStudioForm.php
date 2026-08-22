<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\PromptDraft;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\SandboxSessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Editor del prompt, con borrador y publicación separados.
 *
 * NO es un ConfigFormBase, aunque acabe escribiendo en configuración. Un
 * formulario de configuración guarda al pulsar «Guardar», y aquí guardar y
 * publicar son cosas distintas: guardar deja un borrador que solo afecta a los
 * ensayos del gestor, y publicar cambia el prompt con el que conversarán todos
 * los alumnos a partir de ese momento.
 *
 * Esa separación es lo que hace seguro el estudio. Sin ella, la única forma de
 * probar un cambio sería aplicarlo a todo el mundo.
 */
final class PromptStudioForm extends FormBase {

  private const CONFIG_NAME = 'sales_leadership_diagnostic.diagnostic';

  public function __construct(
    private readonly ConfigFactoryInterface $configs,
    private readonly PromptDraft $draft,
    private readonly SandboxSessionManager $sandbox,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get(PromptDraft::class),
      $container->get(SandboxSessionManager::class),
      $container->get('date.formatter'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sales_leadership_diagnostic_prompt_studio';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $values = $this->currentValues();

    $form['#attributes']['class'][] = 'sld-studio__form';

    if ($this->draft->exists()) {
      $form['aviso'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sld__notice', 'sld__notice--warning'], 'role' => 'status'],
        'texto' => [
          '#markup' => $this->t('Hay cambios sin publicar, guardados el @fecha. Los alumnos siguen recibiendo el prompt publicado; la conversación de prueba usa este borrador.', [
            '@fecha' => $this->dateFormatter->format($this->draft->getSavedAt() ?? 0, 'short'),
          ]),
        ],
      ];
    }

    $form['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Versión'),
      '#description' => $this->t('Identifica esta redacción del prompt. Queda grabada en cada diagnóstico, de modo que dentro de un año se pueda saber con qué instrucciones se produjo (§57).'),
      '#default_value' => $values['version'],
      '#size' => 24,
      '#required' => TRUE,
    ];

    $form['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt del sistema'),
      '#description' => $this->t('Quién es el agente y qué metodología aplica. Es contenido del cliente y se usa tal cual (§15).'),
      '#default_value' => $values['system_prompt'],
      '#rows' => 12,
    ];

    $form['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Instrucciones de conducción'),
      '#description' => $this->t('Cómo debe llevar la conversación: ritmo, número de preguntas, cuándo concluir.'),
      '#default_value' => $values['instructions'],
      '#rows' => 10,
    ];

    $form['output_contract'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Contrato de salida'),
      '#description' => $this->t('Lo único que aporta el módulo y no el cliente: la forma que debe tener la respuesta para poder validarse y guardarse. Cambiarlo sin conocer el esquema puede romper todos los diagnósticos.'),
      '#default_value' => $values['output_contract'],
      '#rows' => 8,
    ];

    $form['actions'] = [
      '#type' => 'actions',

      'guardar' => [
        '#type' => 'submit',
        '#value' => $this->t('Guardar borrador y reiniciar la prueba'),
        '#submit' => ['::saveDraft'],
        // Sin validación de la versión: un borrador a medias es legítimo.
        '#limit_validation_errors' => [],
      ],

      'publicar' => [
        '#type' => 'submit',
        '#value' => $this->t('Publicar'),
        '#submit' => ['::publish'],
        '#button_type' => 'primary',
        '#attributes' => [
          'class' => ['button--danger'],
          // Publicar afecta a todos los alumnos a partir de ese momento, así
          // que no debe poder ocurrir por un clic distraído.
          'data-sld-confirm' => 'true',
        ],
      ],

      'descartar' => [
        '#type' => 'submit',
        '#value' => $this->t('Descartar borrador'),
        '#submit' => ['::discardDraft'],
        '#limit_validation_errors' => [],
        '#access' => $this->draft->exists(),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Solo se valida al publicar. El botón de guardar borrador omite la
   * validación a propósito: un borrador a medio escribir es su estado normal.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('version')) === '') {
      $form_state->setErrorByName('version', $this->t('Un prompt publicado necesita versión: es lo que permite saber después con qué instrucciones se produjo cada diagnóstico.'));
    }

    if (trim((string) $form_state->getValue('system_prompt')) === '') {
      $form_state->setErrorByName('system_prompt', $this->t('No se puede publicar un prompt vacío: los alumnos se quedarían sin agente.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * FormInterface lo exige, pero aquí cada botón tiene el suyo.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * Guarda el borrador y reinicia la conversación de prueba.
   *
   * Se reinicia siempre, y no es un extra: una conversación empezada con el
   * prompt anterior seguiría usándolo —cada sesión congela su copia— así que
   * seguir en ella daría la impresión de que el cambio no ha surtido efecto.
   */
  public function saveDraft(array &$form, FormStateInterface $form_state): void {
    $this->draft->save([
      'version' => $form_state->getValue('version'),
      'system_prompt' => $form_state->getValue('system_prompt'),
      'instructions' => $form_state->getValue('instructions'),
      'output_contract' => $form_state->getValue('output_contract'),
    ]);

    $this->sandbox->reset($this->account);

    $this->messenger()->addStatus($this->t('Borrador guardado. La conversación de prueba se ha reiniciado con él; los alumnos siguen con el prompt publicado.'));
  }

  /**
   * Publica el borrador: a partir de aquí, es el prompt de los alumnos.
   */
  public function publish(array &$form, FormStateInterface $form_state): void {
    $config = $this->configs->getEditable(self::CONFIG_NAME);
    $previousVersion = trim((string) $config->get('version'));
    $newVersion = trim((string) $form_state->getValue('version'));

    $contentChanged =
      trim((string) $form_state->getValue('system_prompt')) !== trim((string) $config->get('system_prompt'))
      || trim((string) $form_state->getValue('instructions')) !== trim((string) $config->get('instructions'));

    foreach (PromptDraft::FIELDS as $field) {
      $config->set($field, trim((string) $form_state->getValue($field)));
    }

    $config->save();

    // Publicado deja de ser borrador. Conservarlo haría que el estudio siguiera
    // avisando de cambios sin publicar cuando ya no los hay.
    $this->draft->discard();
    $this->sandbox->reset($this->account);

    $this->messenger()->addStatus($this->t('Prompt publicado. Los diagnósticos que empiecen a partir de ahora lo usarán; las conversaciones ya en curso conservan el prompt con el que empezaron.'));

    if ($contentChanged && $newVersion === $previousVersion) {
      $this->messenger()->addWarning($this->t('Ha publicado un contenido distinto conservando la versión @version. Los diagnósticos anteriores quedarán registrados con esa misma versión pese a haberse producido con otras instrucciones, lo que impide reproducirlos con exactitud.', [
        '@version' => $previousVersion,
      ]));
    }
  }

  /**
   * Descarta el borrador y vuelve al prompt publicado.
   */
  public function discardDraft(array &$form, FormStateInterface $form_state): void {
    $this->draft->discard();
    $this->sandbox->reset($this->account);

    $this->messenger()->addStatus($this->t('Borrador descartado. La prueba vuelve a usar el prompt publicado.'));
  }

  /**
   * Valores a mostrar: el borrador si existe, y si no lo publicado.
   *
   * @return array<string, string>
   *   Los cuatro campos del prompt.
   */
  private function currentValues(): array {
    if ($this->draft->exists()) {
      return $this->draft->get();
    }

    $config = $this->configs->get(self::CONFIG_NAME);
    $values = [];

    foreach (PromptDraft::FIELDS as $field) {
      $values[$field] = (string) $config->get($field);
    }

    return $values;
  }

}
