<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Exception\DiagnosticException;
use Drupal\sales_leadership_diagnostic\Exception\RateLimitException;
use Drupal\sales_leadership_diagnostic\Exception\SessionBusyException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint de envío de mensajes de la conversación (§23).
 *
 * El navegador habla con Drupal y Drupal habla con el proveedor de IA. En
 * ningún momento el navegador se comunica con el proveedor: eso obligaría a
 * exponerle la API key.
 *
 * El controller no decide nada del diagnóstico. Valida la forma de la petición,
 * delega en el servicio y traduce el resultado —o la excepción— a HTTP.
 */
final class ConversationApiController extends ControllerBase {

  /**
   * Longitud máxima admitida para un mensaje del alumno.
   *
   * Coincide con el maxlength del formulario. Se vuelve a comprobar aquí
   * porque el atributo del navegador es una comodidad, no un control: una
   * petición fabricada a mano lo ignora.
   */
  private const MAX_MESSAGE_LENGTH = 4000;

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly ConversationService $conversation,
    private readonly DateFormatterInterface $dateFormatter,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ConversationService::class),
      $container->get('date.formatter'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Procesa un mensaje del alumno.
   */
  public function send(Request $request, DiagnosticSessionInterface $sld_diagnostic_session): JsonResponse {
    $message = $this->readMessage($request);

    if ($message === NULL) {
      return $this->error($this->t('El mensaje está vacío o tiene un formato incorrecto.'), Response::HTTP_BAD_REQUEST);
    }

    try {
      $outcome = $this->conversation->submitMessage($sld_diagnostic_session, $message);
    }
    catch (SessionBusyException $e) {
      // No se registra como error: es el comportamiento previsto cuando el
      // alumno envía dos veces seguidas o tiene dos pestañas abiertas.
      $this->logger->info('Envío rechazado por turno en curso: @message', ['@message' => $e->getMessage()]);

      return $this->error(
        $this->t('Ya hay una respuesta en curso. Espera un momento.'),
        Response::HTTP_CONFLICT,
      );
    }
    catch (RateLimitException $e) {
      $this->logger->warning('Límite de uso alcanzado: @message', ['@message' => $e->getMessage()]);

      return $this->error(
        $this->t('Has enviado demasiados mensajes en poco tiempo. Espera unos minutos antes de continuar.'),
        Response::HTTP_TOO_MANY_REQUESTS,
      );
    }
    catch (DiagnosticException $e) {
      // El detalle técnico queda en el log; al alumno le llega un mensaje
      // neutro (§58). Nunca se registra el contenido de la conversación.
      $this->logger->error('Fallo procesando un turno de la sesión @id: @type: @message', [
        '@id' => $sld_diagnostic_session->id(),
        '@type' => get_class($e),
        '@message' => $e->getMessage(),
      ]);

      return $this->error(
        $this->t('No hemos podido procesar tu solicitud en este momento. Por favor intenta nuevamente.'),
        Response::HTTP_SERVICE_UNAVAILABLE,
      );
    }

    return new JsonResponse([
      'message_html' => $outcome['message_html'],
      'session_status' => $outcome['session_status'],
      'completed' => $outcome['completed'],
      'result_id' => $outcome['result_id'],
      'time' => $this->dateFormatter->format($this->conversation->now(), 'short'),
    ]);
  }

  /**
   * Extrae y valida el mensaje del cuerpo de la petición.
   *
   * Devuelve NULL si el cuerpo no tiene la forma esperada. No se lanza una
   * excepción porque una petición malformada no es un fallo del sistema.
   */
  private function readMessage(Request $request): ?string {
    $payload = json_decode((string) $request->getContent(), TRUE);

    if (!is_array($payload) || !isset($payload['message']) || !is_string($payload['message'])) {
      return NULL;
    }

    $message = trim($payload['message']);

    if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
      return NULL;
    }

    return $message;
  }

  /**
   * Respuesta de error con forma estable.
   */
  private function error(string|\Stringable $message, int $status): JsonResponse {
    return new JsonResponse(['error' => (string) $message], $status);
  }

}
