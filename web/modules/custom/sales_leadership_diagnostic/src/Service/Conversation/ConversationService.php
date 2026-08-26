<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Exception\DiagnosticException;
use Drupal\sales_leadership_diagnostic\Exception\SessionBusyException;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticContextBuilder;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineInterface;
use Drupal\sales_leadership_diagnostic\Service\Security\RateLimiter;

/**
 * Orquesta un turno completo de la conversación (§27).
 *
 * Es el único sitio donde se conoce el orden en que ocurren las cosas. El
 * controller solo traduce petición y respuesta; el motor solo genera texto;
 * los repositorios solo guardan. La secuencia vive aquí.
 *
 * El orden importa y no es arbitrario:
 *
 *  1. Se toma el bloqueo ANTES de cualquier lectura de estado. Comprobar y
 *     después bloquear deja una ventana en la que dos peticiones pasan la
 *     comprobación a la vez.
 *  2. El límite de uso se comprueba antes de llamar al proveedor, porque su
 *     propósito es precisamente evitar esa llamada.
 *  3. El mensaje del alumno se guarda antes de llamar al motor. Si el motor
 *     falla, lo que el alumno escribió no se pierde.
 *  4. El contador de límite se registra al final, solo si el turno tuvo éxito:
 *     un fallo del sistema no debe consumir cupo del alumno.
 */
final class ConversationService {

  /**
   * Segundos que se espera por el bloqueo antes de rendirse.
   *
   * Es deliberadamente corto: si otro turno está en curso, lo correcto es
   * decírselo al alumno enseguida, no dejarlo esperando.
   */
  private const LOCK_TIMEOUT = 60.0;

  /**
   * Canal de log del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly DiagnosticMessageRepository $messages,
    private readonly DiagnosticContextBuilder $contextBuilder,
    private readonly DiagnosticEngineInterface $engine,
    private readonly MarkdownRenderer $markdown,
    private readonly RateLimiter $rateLimiter,
    private readonly LockBackendInterface $lock,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Procesa un mensaje del alumno y devuelve la respuesta del agente.
   *
   * @return array{message_html: string, session_status: string, completed: bool, result_id: int|null}
   *   Lo que el navegador necesita para pintar el turno y reaccionar al cierre.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\DiagnosticException
   */
  public function submitMessage(DiagnosticSessionInterface $session, string $text): array {
    $sessionId = (int) $session->id();
    $uid = (int) $session->getOwnerId();
    $lockName = 'sld_session:' . $sessionId;

    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      throw new SessionBusyException(sprintf('Ya hay un turno en curso para la sesión %d.', $sessionId));
    }

    try {
      // El estado se relee bajo el bloqueo: entre la carga de la ruta y este
      // punto, otro turno pudo cerrar la sesión.
      $session = $this->reloadSession($sessionId);

      if (!$session->getStatus()->acceptsMessages()) {
        throw new DiagnosticException(sprintf(
          'La sesión %d no admite mensajes en estado "%s".',
          $sessionId,
          $session->getStatus()->value,
        ));
      }

      $this->rateLimiter->assertCanSendMessage($uid);

      $this->messages->append($sessionId, MessageRole::User, $text);

      $context = $this->contextBuilder->build($session);
      $turn = $this->engine->process($context);

      $this->messages->append($sessionId, MessageRole::Assistant, $turn->message, $turn->raw);

      $resultId = $this->finalizeSession($session, $turn);

      $this->rateLimiter->registerMessage($uid);

      $this->logger->info('Turno completado en la sesión @id (turno @n).', [
        '@id' => $sessionId,
        '@n' => $session->getTurnCount(),
      ]);

      return [
        'message_html' => $this->markdown->render($turn->message),
        'session_status' => $session->getStatus()->value,
        'completed' => $turn->completed,
        'result_id' => $resultId,
      ];
    }
    finally {
      // Se libera siempre, también si algo falló: un bloqueo huérfano dejaría
      // la sesión inutilizable hasta que expirase.
      $this->lock->release($lockName);
    }
  }

  /**
   * Actualiza el estado de la sesión y crea el resultado si procede.
   *
   * @return int|null
   *   Identificador del resultado creado, si el diagnóstico concluyó.
   */
  private function finalizeSession(DiagnosticSessionInterface $session, DiagnosticTurn $turn): ?int {
    $session->incrementTurnCount();

    if (!$turn->completed) {
      $session->setStatus(DiagnosticStatus::InProgress);
      $session->save();

      return NULL;
    }

    $resultId = $this->createResult($session, $turn);
    $session->setStatus(DiagnosticStatus::Completed);
    $session->save();

    $this->logger->info('Diagnóstico completado: sesión @id, versión @version.', [
      '@id' => $session->id(),
      '@version' => $session->getDiagnosticVersion(),
    ]);

    $this->encolarExtraccionDeMemoria($session);

    return $resultId;
  }

  /**
   * Deja encargada la extracción de la memoria del alumno.
   *
   * Solo se encola: extraer aquí mismo añadiría al alumno una segunda espera
   * ante el modelo, justo cuando acaba de esperar su informe, por algo que no
   * va a ver. Y un fallo del proveedor en ese momento se le presentaría como
   * si su diagnóstico hubiera fallado.
   *
   * No se encolan los ensayos del gestor. El extractor vuelve a comprobarlo
   * por su cuenta —es él quien no debe escribir memoria de una simulación—,
   * pero encolar algo que se va a descartar sería gastar un cron para nada.
   */
  private function encolarExtraccionDeMemoria(DiagnosticSessionInterface $session): void {
    if ((bool) $session->get('is_sandbox')->value) {
      return;
    }

    $this->queueFactory
      ->get('sld_memory_extraction')
      ->createItem(['session_id' => (int) $session->id()]);
  }

  /**
   * Crea la entidad de resultado a partir del turno final.
   */
  private function createResult(DiagnosticSessionInterface $session, DiagnosticTurn $turn): int {
    $payload = $turn->result ?? [];

    $entity = $this->entityTypeManager->getStorage('sld_diagnostic_result')->create([
      // El resultado hereda el propietario de la sesión. Derivarlo de la
      // sesión y no del usuario en curso evita que un cambio futuro en la capa
      // de acceso pueda atribuir un resultado a quien no le corresponde.
      'uid' => $session->getOwnerId(),
      'session_id' => $session->id(),
      // Se copia de la sesión, igual que la versión: la sesión puede
      // eliminarse y el historial tiene que seguir diciendo con qué agente se
      // conversó. Deducirlo del curso no serviría, porque el curso que
      // concede un agente puede cambiar después.
      'agent' => $session->getAgentId(),
      // La marca de ensayo se hereda de la sesión. Sin esto, el resultado de
      // una prueba del gestor nace marcado como real y aparece en el listado
      // que el cliente usa para dar soporte, mezclado con los diagnósticos de
      // alumnos de verdad — que es justamente lo que la separación existe para
      // impedir. El listado filtra por este campo, así que dejarlo vacío no
      // fallaba de forma visible.
      'is_sandbox' => (bool) $session->get('is_sandbox')->value,
      'diagnostic_version' => $session->getDiagnosticVersion(),
      'summary' => (string) ($payload['summary'] ?? ''),
      'score' => isset($payload['score']) && is_numeric($payload['score'])
        ? (int) $payload['score']
        : NULL,
    ]);

    if (!$entity instanceof DiagnosticResultInterface) {
      throw new DiagnosticException('El almacenamiento devolvió un resultado de un tipo inesperado.');
    }

    $entity->setPayload($payload);
    $entity->save();

    return (int) $entity->id();
  }

  /**
   * Recarga la sesión desde el almacenamiento, ya bajo el bloqueo.
   */
  private function reloadSession(int $sessionId): DiagnosticSessionInterface {
    $storage = $this->entityTypeManager->getStorage('sld_diagnostic_session');
    $storage->resetCache([$sessionId]);
    $session = $storage->load($sessionId);

    if (!$session instanceof DiagnosticSessionInterface) {
      throw new DiagnosticException(sprintf('La sesión %d ha dejado de existir.', $sessionId));
    }

    return $session;
  }

  /**
   * Devuelve la conversación de una sesión, lista para renderizar.
   *
   * @return \Drupal\sales_leadership_diagnostic\DTO\ConversationMessage[]
   *   Los turnos de la sesión, en orden.
   */
  public function getConversation(int $sessionId): array {
    return $this->messages->loadForSession($sessionId);
  }

  /**
   * Momento actual, para sellar la respuesta que ve el navegador.
   */
  public function now(): int {
    return $this->time->getRequestTime();
  }

}
