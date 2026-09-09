<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Genera fuera de la petición web los turnos que pueden investigar.
 *
 * Existe por un número medido, no por prudencia. Un turno con dos búsquedas
 * sobre una sola cuenta tardó **76 segundos**; una misión Discovery criba diez
 * cuentas y profundiza en tres, que son decenas de búsquedas y veinte minutos.
 *
 * Eso no cabe en una petición web, y **no es cuestión de subir un timeout**:
 * aunque el servidor aguantara, nadie mira una pantalla en blanco veinte
 * minutos. Aquí el proceso corre por línea de órdenes, donde PHP no tiene
 * límite de tiempo, y el navegador va preguntando cómo va.
 *
 * El tiempo de cron es alto a propósito: si se cortara a los noventa segundos,
 * el trabajador moriría a mitad de una misión larga y el turno quedaría
 * atascado. Vale más que un cron tarde en devolver el control que dejar una
 * conversación colgada.
 */
#[QueueWorker(
  id: 'sld_diagnostic_turn',
  title: new TranslatableMarkup('Turnos de diagnóstico en segundo plano'),
  cron: ['time' => 900],
)]
final class DiagnosticTurnWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Nombre de la cola.
   *
   * Vive aquí y no en quien encola para que no haya dos cadenas que tengan
   * que coincidir: una cola mal escrita no falla, simplemente no se procesa
   * nunca.
   */
  public const QUEUE = 'sld_diagnostic_turn';

  /**
   * Quien conduce la conversación.
   *
   * @var \Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService
   */
  private ConversationService $conversation;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->conversation = $container->get(ConversationService::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $sessionId = (int) ($data['session_id'] ?? 0);

    if ($sessionId <= 0) {
      return;
    }

    // No se captura nada: si falla, la cola conserva el elemento y lo
    // reintenta. La idempotencia la garantiza el propio servicio, que se sale
    // sin hacer nada si la sesión ya no está procesando —lo pide el §14 de la
    // especificación del cliente: «no consumir otra misión automáticamente»—.
    $this->conversation->processQueuedTurn($sessionId);
  }

}
