<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticPromptManager;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\PromptDraft;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\SandboxSessionManager;
use Drupal\sales_leadership_diagnostic\Service\Knowledge\KnowledgeLibrary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Editor del prompt de un agente, con borrador y publicación separados.
 *
 * NO es un ConfigFormBase. Un formulario de configuración guarda al pulsar
 * «Guardar», y aquí guardar y publicar son cosas distintas: guardar deja un
 * borrador que solo afecta a los ensayos del gestor, y publicar cambia el
 * prompt con el que conversarán todos los alumnos de ese agente a partir de
 * ese momento.
 *
 * Esa separación es lo que hace seguro el estudio. Sin ella, la única forma de
 * probar un cambio sería aplicarlo a todo el mundo.
 *
 * **Trabaja sobre un AGENTE**, desde el 26-08-2026. Antes editaba y publicaba
 * en un objeto de configuración único que dejó de gobernar nada al pasar a
 * varios agentes: el gestor ensayaba un prompt que no usaba ningún alumno, sin
 * los documentos de conocimiento, y al publicar escribía en un sitio que nadie
 * leía. Era la pantalla más engañosa del módulo, porque parecía funcionar.
 */
final class PromptStudioForm extends FormBase {

  /**
   * Agente sobre el que se está trabajando.
   *
   * NULL solo mientras el gestor todavía no ha elegido, cuando hay varios.
   *
   * @var \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface|null
   */
  private ?DiagnosticAgentInterface $agente = NULL;

  public function __construct(
    private readonly AgentRegistry $agents,
    private readonly PromptDraft $draft,
    private readonly SandboxSessionManager $sandbox,
    private readonly DiagnosticPromptManager $prompts,
    private readonly KnowledgeLibrary $knowledge,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AgentRegistry::class),
      $container->get(PromptDraft::class),
      $container->get(SandboxSessionManager::class),
      $container->get(DiagnosticPromptManager::class),
      $container->get(KnowledgeLibrary::class),
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
  public function buildForm(array $form, FormStateInterface $form_state, ?DiagnosticAgentInterface $sld_agent = NULL): array {
    $disponibles = $this->agents->getUsable();

    // Con un solo agente se entra directo: poner una pantalla para elegir
    // entre una sola opción es empeorarla. Con varios hay que preguntar, que
    // fue justo el fallo de la pantalla de documentos: elegía uno en silencio
    // y el gestor acababa editando el que no era.
    $this->agente = $sld_agent ?? (count($disponibles) === 1 ? reset($disponibles) : NULL);

    if ($this->agente === NULL) {
      $form['elegir'] = $this->buildChooser($disponibles);

      return $form;
    }

    $form['#attributes']['class'][] = 'sld-studio__form';
    $form['agente'] = $this->buildHeader($disponibles);

    $values = $this->currentValues();

    if ($this->draft->exists($this->agentId())) {
      $form['aviso'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sld__notice', 'sld__notice--warning'], 'role' => 'status'],
        'texto' => [
          '#markup' => $this->t('Hay cambios sin publicar en este agente, guardados el @fecha. Los alumnos siguen recibiendo el prompt publicado; la conversación de prueba usa este borrador.', [
            '@fecha' => $this->dateFormatter->format($this->draft->getSavedAt($this->agentId()) ?? 0, 'short'),
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
      '#description' => $this->t('Cómo debe llevar la conversación: ritmo, número de preguntas, cuándo concluir. Puede dejarse vacío si el prompt del cliente ya lo cubre.'),
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

    $form['tamano'] = $this->buildSizeReport($values);

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
        '#value' => $this->t('Publicar en este agente'),
        '#submit' => ['::publish'],
        '#button_type' => 'primary',
        '#attributes' => [
          'class' => ['button--danger'],
          // La pregunta viaja en el atributo, y la lee sld-confirm.js. Antes
          // aquí ponía «true» y NADA lo leía: el botón parecía protegido y no
          // lo estaba. Publicar cambia el prompt de todos los alumnos de este
          // agente a partir de ese momento.
          'data-sld-confirm' => $this->t('Se va a publicar este prompt en «@agente». A partir de ahora, los diagnósticos que empiecen lo usarán. ¿Continuar?', [
            '@agente' => $this->agente?->label(),
          ]),
        ],
      ],

      'descartar' => [
        '#type' => 'submit',
        '#value' => $this->t('Descartar borrador'),
        '#submit' => ['::discardDraft'],
        '#limit_validation_errors' => [],
        '#access' => $this->draft->exists($this->agentId()),
      ],
    ];

    return $form;
  }

  /**
   * Pantalla de elección, cuando hay varios agentes o ninguno.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface[] $disponibles
   *   Agentes utilizables.
   *
   * @return array<string, mixed>
   *   Elemento de renderizado.
   */
  private function buildChooser(array $disponibles): array {
    if ($disponibles === []) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['sld__notice', 'sld__notice--warning']],
        'texto' => [
          '#markup' => $this->t('Todavía no hay ningún agente disponible. Cree uno antes de ajustar su prompt.'),
        ],
      ];
    }

    $enlaces = [];

    foreach ($disponibles as $agent) {
      $enlaces[] = [
        '#type' => 'link',
        '#title' => $agent->label(),
        '#url' => Url::fromRoute(
          'sales_leadership_diagnostic.studio_agent',
          ['sld_agent' => $agent->id()],
        ),
        '#attributes' => ['class' => ['sld__button', 'sld__button--secondary']],
      ];
    }

    return [
      '#type' => 'container',
      'titulo' => [
        '#markup' => '<p>' . $this->t('¿De qué agente quiere ajustar el prompt?') . '</p>',
      ],
      'enlaces' => $enlaces,
    ];
  }

  /**
   * Cabecera que deja claro sobre qué agente se trabaja.
   *
   * Prominente a propósito: publicar aquí cambia el prompt de los alumnos de
   * ESE agente, y equivocarse de agente no da ningún aviso.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface[] $disponibles
   *   Agentes utilizables, para ofrecer el cambio.
   *
   * @return array<string, mixed>
   *   Elemento de renderizado.
   */
  private function buildHeader(array $disponibles): array {
    $cabecera = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sld-knowledge__agent']],
      'nombre' => [
        '#markup' => '<p class="sld-knowledge__agent-name">'
        . $this->t('Ajustando el prompt de: <strong>@nombre</strong>', ['@nombre' => $this->agente?->label()])
        . '</p>',
      ],
    ];

    $otros = array_filter(
      $disponibles,
      fn (DiagnosticAgentInterface $a): bool => $a->id() !== $this->agentId(),
    );

    if ($otros === []) {
      return $cabecera;
    }

    $enlaces = [];

    foreach ($otros as $a) {
      $enlaces[] = [
        '#type' => 'link',
        '#title' => $a->label(),
        '#url' => Url::fromRoute(
          'sales_leadership_diagnostic.studio_agent',
          ['sld_agent' => $a->id()],
        ),
        '#attributes' => ['class' => ['sld__link']],
      ];
    }

    $cabecera['cambiar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sld-knowledge__switch']],
      'etiqueta' => ['#markup' => '<span>' . $this->t('Cambiar a:') . '</span> '],
      'enlaces' => $enlaces,
    ];

    return $cabecera;
  }

  /**
   * Cuánto ocupa el prompt que se está ensayando, y qué cuesta.
   *
   * Se enseña porque no es evidente hasta que falla. El prompt viaja ENTERO en
   * cada turno, así que el tamaño se paga una y otra vez, y los documentos de
   * conocimiento pesan mucho más que el texto que el gestor está escribiendo.
   *
   * @param array<string, string> $values
   *   Campos que se están editando.
   *
   * @return array<string, mixed>
   *   Elemento de renderizado.
   */
  private function buildSizeReport(array $values): array {
    $agente = $this->agente;
    assert($agente instanceof DiagnosticAgentInterface);

    $completo = $this->prompts->composeDraft($agente, $values);
    $documentos = $this->knowledge->getTotalTokens($agente);
    $total = $this->knowledge->estimateTokens($completo);

    return [
      '#type' => 'details',
      '#title' => $this->t('Tamaño del prompt'),
      '#open' => FALSE,
      'detalle' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Metodología escrita aquí: @tokens tokens aproximados.', [
            '@tokens' => number_format(max(0, $total - $documentos)),
          ]),
          $this->t('Documentos de conocimiento (@cuantos): @tokens tokens aproximados.', [
            '@cuantos' => count($this->knowledge->getDocuments($agente)),
            '@tokens' => number_format($documentos),
          ]),
          $this->t('Total que viaja en CADA turno: @tokens tokens aproximados.', [
            '@tokens' => number_format($total),
          ]),
        ],
      ],
      'aviso' => [
        '#markup' => '<p class="sld__muted">'
        . $this->t('El prompt entero se reenvía en cada turno, así que este tamaño se paga en todos. La respuesta tiene su propio presupuesto, que se configura aparte: si el informe final no cabe en él, el diagnóstico se pierde al concluir.')
        . '</p>',
      ],
    ];
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
    $agente = $this->agenteDelEnvio($form_state);

    // Se lee de la ENTRADA CRUDA y no de getValue(). Este botón lleva
    // `#limit_validation_errors` vacío para que un borrador a medias sea
    // legítimo, y ese ajuste hace que Drupal descarte TODOS los valores del
    // estado del formulario: getValue() devolvía cadena vacía y el borrador se
    // guardaba en blanco, siempre. Venía así de antes de que el estudio
    // trabajara con agentes.
    $entrada = $form_state->getUserInput();

    $this->draft->save((string) $agente->id(), [
      'version' => $this->limpiar($entrada['version'] ?? ''),
      'system_prompt' => $this->limpiar($entrada['system_prompt'] ?? ''),
      'instructions' => $this->limpiar($entrada['instructions'] ?? ''),
      'output_contract' => $this->limpiar($entrada['output_contract'] ?? ''),
    ]);

    $this->sandbox->reset($this->account, $agente);

    $this->messenger()->addStatus($this->t('Borrador guardado. La conversación de prueba se ha reiniciado con él; los alumnos siguen con el prompt publicado.'));
  }

  /**
   * Publica el borrador en el agente: a partir de aquí, es el de sus alumnos.
   */
  public function publish(array &$form, FormStateInterface $form_state): void {
    $agente = $this->agenteDelEnvio($form_state);

    $versionAnterior = $agente->getVersion();
    $versionNueva = trim((string) $form_state->getValue('version'));

    $cambioContenido =
      trim((string) $form_state->getValue('system_prompt')) !== $agente->getSystemPrompt()
      || trim((string) $form_state->getValue('instructions')) !== $agente->getInstructions();

    // Se normalizan los saltos de línea por lo mismo que en el formulario del
    // agente: los navegadores envían CRLF y guardarlo así cambia el prompt y
    // su huella sin que nadie haya editado nada.
    $agente->set('version', $versionNueva);
    $agente->set('system_prompt', $this->limpiar($form_state->getValue('system_prompt')));
    $agente->set('instructions', $this->limpiar($form_state->getValue('instructions')));
    $agente->set('output_contract', $this->limpiar($form_state->getValue('output_contract')));
    $agente->save();

    // Publicado deja de ser borrador. Conservarlo haría que el estudio siguiera
    // avisando de cambios sin publicar cuando ya no los hay.
    $this->draft->discard((string) $agente->id());
    $this->sandbox->reset($this->account, $agente);

    $this->messenger()->addStatus($this->t('Prompt publicado en «@agente». Los diagnósticos que empiecen a partir de ahora lo usarán; las conversaciones ya en curso conservan el prompt con el que empezaron.', [
      '@agente' => $agente->label(),
    ]));

    if ($cambioContenido && $versionNueva === $versionAnterior) {
      $this->messenger()->addWarning($this->t('Ha publicado un contenido distinto conservando la versión @version. Los diagnósticos anteriores quedarán registrados con esa misma versión pese a haberse producido con otras instrucciones, lo que impide reproducirlos con exactitud.', [
        '@version' => $versionAnterior,
      ]));
    }
  }

  /**
   * Descarta el borrador y vuelve al prompt publicado del agente.
   */
  public function discardDraft(array &$form, FormStateInterface $form_state): void {
    $agente = $this->agenteDelEnvio($form_state);

    $this->draft->discard((string) $agente->id());
    $this->sandbox->reset($this->account, $agente);

    $this->messenger()->addStatus($this->t('Borrador descartado. La prueba vuelve a usar el prompt publicado.'));
  }

  /**
   * El agente sobre el que actúa este envío.
   *
   * Se recupera del formulario reconstruido y no de una propiedad, porque el
   * formulario se rehace en cada petición y la propiedad puede no haberse
   * poblado si el envío llegó por otro camino. Si no hubiera ninguno, no habría
   * llegado a pintarse un botón.
   */
  private function agenteDelEnvio(FormStateInterface $form_state): DiagnosticAgentInterface {
    if ($this->agente instanceof DiagnosticAgentInterface) {
      return $this->agente;
    }

    $disponibles = $this->agents->getUsable();
    $primero = reset($disponibles);

    assert($primero instanceof DiagnosticAgentInterface);

    return $primero;
  }

  /**
   * Identificador del agente en curso.
   */
  private function agentId(): string {
    return (string) $this->agente?->id();
  }

  /**
   * Recorta y normaliza los saltos de línea de un texto del formulario.
   */
  private function limpiar(mixed $texto): string {
    return trim(str_replace("\r\n", "\n", (string) $texto));
  }

  /**
   * Valores a mostrar: el borrador si existe, y si no lo publicado del agente.
   *
   * @return array<string, string>
   *   Los cuatro campos del prompt.
   */
  private function currentValues(): array {
    $agente = $this->agente;
    assert($agente instanceof DiagnosticAgentInterface);

    if ($this->draft->exists($this->agentId())) {
      return $this->draft->get($this->agentId());
    }

    return [
      'version' => $agente->getVersion(),
      'system_prompt' => $agente->getSystemPrompt(),
      'instructions' => $agente->getInstructions(),
      'output_contract' => $agente->getOutputContract(),
    ];
  }

}
