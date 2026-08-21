<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vista de conversación de un diagnóstico (§19–§22).
 *
 * La comprobación de propiedad no ocurre aquí: la ruta declara la sesión como
 * parámetro de entidad con _entity_access, de modo que el enrutador resuelve
 * el identificador y aplica el handler de acceso antes de invocar este método.
 * Un identificador ajeno nunca llega a ejecutar esta clase.
 */
final class ChatController extends ControllerBase {

  public function __construct(
    private readonly DiagnosticMessageRepository $messages,
    private readonly MarkdownRenderer $markdown,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(DiagnosticMessageRepository::class),
      $container->get(MarkdownRenderer::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renderiza la conversación de una sesión.
   */
  public function view(DiagnosticSessionInterface $sld_diagnostic_session): array {
    $session = $sld_diagnostic_session;
    $status = $session->getStatus();
    $statusLabels = DiagnosticStatus::allowedValues();

    return [
      '#theme' => 'sld_chat',
      '#session_id' => (int) $session->id(),
      '#status' => $status->value,
      '#status_label' => $statusLabels[$status->value] ?? $status->value,
      '#accepts_messages' => $status->acceptsMessages(),
      '#messages' => $this->buildMessages((int) $session->id()),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/chat'],
        'drupalSettings' => [
          'salesLeadershipDiagnostic' => [
            'sessionId' => (int) $session->id(),
            'acceptsMessages' => $status->acceptsMessages(),
            // Solo se entrega el endpoint si la sesión admite mensajes. Una
            // sesión cerrada no debe siquiera ofrecer a dónde escribir; el
            // servidor lo rechazaría igualmente, pero no tiene sentido que el
            // navegador conozca una ruta que no puede usar.
            'messageEndpoint' => $status->acceptsMessages()
              ? Url::fromRoute('sales_leadership_diagnostic.session_message', [
                'sld_diagnostic_session' => $session->id(),
              ])->toString()
              : NULL,
            'csrfTokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        // La conversación cambia con la sesión. Los mensajes viven en una
        // tabla propia, así que su etiqueta de cache es la de la sesión que
        // los contiene: es la sesión la que se guarda en cada turno.
        'tags' => ['sld_diagnostic_session:' . $session->id()],
      ],
    ];
  }

  /**
   * Prepara los mensajes para la plantilla.
   *
   * El cuerpo de un mensaje del agente se convierte de Markdown a HTML ya
   * saneado y se envuelve en Markup, de modo que Twig lo imprima sin volver a
   * escaparlo. Esta es la única razón por la que existe un Markup en el
   * módulo, y solo se crea después de pasar por MarkdownRenderer.
   *
   * El mensaje del alumno se pasa como cadena y Twig lo escapa. Los saltos de
   * línea se respetan por CSS, no reintroduciendo etiquetas.
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
