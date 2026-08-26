<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\sales_leadership_diagnostic\Service\Memory\MemoryExtractor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extrae la memoria del alumno de una conversación ya terminada.
 *
 * Va en cola y no en el mismo turno a propósito. El alumno acaba de esperar a
 * que se genere su informe final; encadenarle una segunda llamada al modelo
 * antes de enseñárselo le añadiría una espera por algo que no va a ver, y un
 * fallo del proveedor en ese momento aparecería como si el diagnóstico hubiera
 * fallado.
 *
 * El precio es que la memoria tarda en escribirse lo que tarde el siguiente
 * cron. Es aceptable: solo influye en la conversación SIGUIENTE, que por
 * definición es otro día.
 */
#[QueueWorker(
  id: 'sld_memory_extraction',
  title: new TranslatableMarkup('Extracción de la memoria del alumno'),
  cron: ['time' => 90],
)]
final class MemoryExtractionWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * El servicio que hace el trabajo.
   *
   * @var \Drupal\sales_leadership_diagnostic\Service\Memory\MemoryExtractor
   */
  private MemoryExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->extractor = $container->get(MemoryExtractor::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $sessionId = is_array($data) ? (int) ($data['session_id'] ?? 0) : 0;

    if ($sessionId <= 0) {
      return;
    }

    // No se propaga nada: el extractor ya captura sus propios fallos y los
    // registra. Dejar que una excepción saliera de aquí devolvería el elemento
    // a la cola, y una sesión que falla siempre —porque el modelo no sabe qué
    // hacer con ella— se reintentaría en cada cron indefinidamente.
    $this->extractor->extractFromSession($sessionId);
  }

}
