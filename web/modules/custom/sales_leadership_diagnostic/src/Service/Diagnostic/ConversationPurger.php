<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Retira las conversaciones viejas, conservando los diagnósticos.
 *
 * Se midió antes de diseñarlo (26-08-2026) y el resultado descartó el motivo
 * que parecía obvio: con diez sesiones reales el módulo entero ocupaba menos
 * de 700 KB, y con los 35 alumnos previstos rondaría los 6 MB. **El espacio no
 * es el problema.** Esto existe por privacidad, y solo por eso.
 *
 * De ahí las tres decisiones que lo definen:
 *
 * **Se borran los MENSAJES, no las sesiones.** Lo sensible es lo que escribió
 * la persona. La fila de la sesión guarda además la copia literal del prompt
 * con el que se condujo, que es lo que permite saber años después con qué
 * instrucciones se produjo un diagnóstico (§57). Borrar la sesión entera
 * habría cambiado un problema de privacidad por uno de trazabilidad.
 *
 * **Los diagnósticos NO se tocan, nunca.** Son el entregable que el alumno
 * compró, y desde el 26-08-2026 guardan además su tabla por dimensión: siguen
 * siendo legibles y comparables sin la conversación detrás.
 *
 * **Solo se purgan conversaciones TERMINADAS.** Una a medias sigue viva
 * aunque lleve meses parada: el alumno puede volver a ella, y vaciarla le
 * dejaría una pantalla sin sentido en lugar de su trabajo.
 */
final class ConversationPurger {

  /**
   * Sesiones que se procesan por ejecución.
   *
   * El cron comparte tiempo con todo lo demás del sitio. Con un tope se tarda
   * varias ejecuciones en poner al día una instalación con historial, y no se
   * corre el riesgo de que una sola se quede sin tiempo a medio camino.
   */
  private const POR_EJECUCION = 200;

  /**
   * Canal de registro del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DiagnosticMessageRepository $messages,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Vacía las conversaciones que ya superaron el plazo.
   *
   * @return int
   *   Cuántas conversaciones se vaciaron.
   */
  public function purge(): int {
    $dias = $this->getRetentionDays();

    // Cero significa conservar indefinidamente, y es el valor de fábrica: una
    // instalación existente no debe empezar a borrar datos porque alguien
    // actualice el módulo.
    if ($dias <= 0) {
      return 0;
    }

    $limite = $this->time->getRequestTime() - ($dias * 86400);
    $sesiones = $this->buscarCaducadas($limite);
    $vaciadas = 0;

    foreach ($sesiones as $sessionId) {
      if ($this->messages->deleteForSession($sessionId) > 0) {
        $vaciadas++;
      }
    }

    if ($vaciadas > 0) {
      // Cifras, nunca contenido (§43).
      $this->logger->info(
        'Retención: se vaciaron @n conversación(es) terminadas hace más de @dias días. Los diagnósticos y las copias del prompt se conservan.',
        ['@n' => $vaciadas, '@dias' => $dias],
      );
    }

    return $vaciadas;
  }

  /**
   * Sesiones terminadas cuya conversación ya puede retirarse.
   *
   * @param int $limite
   *   Momento a partir del cual una sesión se considera vieja.
   *
   * @return int[]
   *   Identificadores de sesión.
   */
  private function buscarCaducadas(int $limite): array {
    $ids = $this->entityTypeManager
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      // Sin comprobación de acceso: esto corre en cron, sin usuario, y
      // filtrar por permisos dejaría datos sin purgar según quién lo dispare.
      ->accessCheck(FALSE)
      ->condition('status', [
        DiagnosticStatus::Completed->value,
        DiagnosticStatus::Failed->value,
      ], 'IN')
      ->condition('changed', $limite, '<')
      ->sort('changed')
      ->range(0, self::POR_EJECUCION)
      ->execute();

    return array_map('intval', array_values($ids));
  }

  /**
   * Días que se conservan las conversaciones. Cero es indefinidamente.
   */
  public function getRetentionDays(): int {
    return max(0, (int) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('diagnostic.conversation_retention_days'));
  }

}
