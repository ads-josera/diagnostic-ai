<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Form\PromptStudioForm;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
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
      $container->get(DiagnosticMessageRepository::class),
      $container->get(MarkdownRenderer::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Construye la página del estudio.
   */
  public function view(): array {
    $session = $this->sandbox->getOrCreate($this->currentUser());
    $sessionId = (int) $session->id();

    return [
      '#theme' => 'sld_studio',
      '#form' => $this->formBuilder()->getForm(PromptStudioForm::class),
      '#session_id' => $sessionId,
      '#messages' => $this->buildMessages($sessionId),
      '#reset_url' => Url::fromRoute('sales_leadership_diagnostic.studio_reset')->toString(),
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
  public function reset(): RedirectResponse {
    $this->sandbox->reset($this->currentUser());

    $this->messenger()->addStatus($this->t('Conversación de prueba reiniciada.'));

    return new RedirectResponse(
      Url::fromRoute('sales_leadership_diagnostic.studio')->toString(),
    );
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
