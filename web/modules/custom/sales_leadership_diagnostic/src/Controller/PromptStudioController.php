<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Form\PromptStudioForm;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\SandboxSessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Estudio del prompt.
 *
 * Editor a la izquierda, conversación de prueba a la derecha.
 *
 * Las dos mitades en la misma pantalla porque ajustar un prompt es un ciclo
 * corto —cambiar, probar, volver a cambiar— y separarlo en dos páginas obliga a
 * recordar de memoria lo que se acaba de escribir mientras se lee la respuesta.
 *
 * La conversación de la derecha es real: mismo motor, mismo servicio y mismo
 * recorrido que el del alumno. Lo único distinto es que la sesión nace marcada
 * como prueba, y esa marca la mantiene fuera del listado de resultados y del
 * límite de diagnósticos por periodo.
 */
final class PromptStudioController extends ControllerBase {

  public function __construct(
    private readonly SandboxSessionManager $sandbox,
    private readonly AgentRegistry $agents,
    private readonly DiagnosticMessageRepository $messages,
    private readonly MarkdownRenderer $markdown,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SandboxSessionManager::class),
      $container->get(AgentRegistry::class),
      $container->get(DiagnosticMessageRepository::class),
      $container->get(MarkdownRenderer::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Construye la página del estudio.
   */
  public function view(?DiagnosticAgentInterface $sld_agent = NULL): array {
    $agente = $this->resolverAgente($sld_agent);

    // Sin agente elegido no hay nada que ensayar: se pinta solo el formulario,
    // que en ese caso es la pantalla de elección. Crear una conversación de
    // prueba antes de saber de qué agente sería crearla del equivocado.
    //
    // Esta rama SÍ adjunta la hoja de estilos, aunque no adjunte el JavaScript
    // del chat. Sin ella la pantalla salía sin ningún estilo —los botones de
    // elección se pintaban como enlaces pegados uno a otro— y nadie se
    // enteraba, porque la prueba de humo entra directa al estudio de un agente
    // y nunca pasaba por aquí. Lo encontró el usuario el 02-09-2026.
    if ($agente === NULL) {
      return [
        '#theme' => 'sld_studio',
        '#form' => $this->formBuilder()->getForm(PromptStudioForm::class, NULL),
        // Cero significa «no hay ensayo», y la plantilla se apoya en eso para
        // no pintar el panel de la conversación. Antes lo pintaba igual: se
        // ofrecía una caja de texto que no podía funcionar, porque el JS que
        // la anima ni siquiera estaba en la página.
        '#session_id' => 0,
        '#messages' => [],
        '#reset_url' => '',
        '#attached' => ['library' => ['sales_leadership_diagnostic/studio']],
        '#cache' => ['contexts' => ['user'], 'max-age' => 0],
      ];
    }

    $session = $this->sandbox->getOrCreate($this->currentUser(), $agente);
    $sessionId = (int) $session->id();

    return [
      '#theme' => 'sld_studio',
      '#form' => $this->formBuilder()->getForm(PromptStudioForm::class, $agente),
      '#session_id' => $sessionId,
      '#messages' => $this->buildMessages($sessionId),
      '#reset_url' => Url::fromRoute(
        'sales_leadership_diagnostic.studio_reset',
        ['sld_agent' => $agente->id()],
      )->toString(),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/studio'],
        'drupalSettings' => [
          'salesLeadershipDiagnostic' => [
            // El JS del chat del alumno se reutiliza tal cual: lee su destino
            // de aquí, así que basta con apuntarlo al endpoint del ensayo.
            'messageEndpoint' => Url::fromRoute(
              'sales_leadership_diagnostic.studio_message',
              ['sld_diagnostic_session' => $sessionId],
            )->toString(),
            // El nombre lo fija el JS, que es el mismo archivo que usa el
            // alumno: 'csrfTokenUrl', no otro.
            'csrfTokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
          ],
        ],
      ],
      '#cache' => [
        // Depende de quién mira y cambia con cada turno. No se cachea.
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Reinicia la conversación de prueba y vuelve al estudio.
   */
  public function reset(DiagnosticAgentInterface $sld_agent): RedirectResponse {
    $this->sandbox->reset($this->currentUser(), $sld_agent);

    $this->messenger()->addStatus($this->t('Conversación de prueba reiniciada.'));

    return new RedirectResponse(
      Url::fromRoute(
        'sales_leadership_diagnostic.studio_agent',
        ['sld_agent' => $sld_agent->id()],
      )->toString(),
    );
  }

  /**
   * Decide sobre qué agente se trabaja.
   *
   * Con uno solo se entra directo; con varios hace falta elegir, y devolver
   * NULL es lo que hace que se pinte la pantalla de elección. Es la misma
   * regla que la pantalla de documentos, y por el mismo motivo: elegir uno en
   * silencio lleva a editar el que no era.
   */
  private function resolverAgente(?DiagnosticAgentInterface $sld_agent): ?DiagnosticAgentInterface {
    if ($sld_agent instanceof DiagnosticAgentInterface) {
      return $sld_agent;
    }

    $disponibles = $this->agents->getUsable();

    return count($disponibles) === 1 ? reset($disponibles) : NULL;
  }

  /**
   * Turnos de la conversación, en el formato que espera la plantilla del chat.
   *
   * Se compone igual que en la página del alumno, saneador incluido: si el
   * ensayo no pasara por el mismo tratamiento, el gestor vería un texto
   * distinto del que verá el alumno y podría dar por bueno un prompt cuya
   * salida se recorta al mostrarse de verdad.
   *
   * @return array<int, array<string, mixed>>
   *   Mensajes listos para pintar.
   */
  private function buildMessages(int $sessionId): array {
    $rendered = [];

    foreach ($this->messages->loadForSession($sessionId) as $message) {
      $isAssistant = $message->role === MessageRole::Assistant;

      $rendered[] = [
        'role' => $message->role->value,
        'is_assistant' => $isAssistant,
        'body' => $isAssistant
          ? Markup::create($this->markdown->render($message->content))
          : $message->content,
        'time' => $this->dateFormatter->format($message->created, 'short'),
        'sequence' => $message->sequence,
      ];
    }

    return $rendered;
  }

}
