<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Authorization;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Traduce la consulta de autorización a una respuesta de sí o no (§12).
 *
 * Es la fachada que usa el resto del módulo. Su valor está en concentrar en un
 * único punto la conversión de una excepción en una denegación: cualquier otro
 * lugar del código que capturase WordPressUnavailableException tendría que
 * acordarse de denegar, y bastaría un `catch` mal escrito para conceder
 * acceso por error.
 *
 * Aquí solo hay una forma de salir con permiso concedido, y es que la consulta
 * lo diga explícitamente.
 */
final class DiagnosticAccessChecker {

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly CourseAccessProviderInterface $provider,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Indica si un alumno tiene derecho al diagnóstico.
   *
   * @param string $externalUserId
   *   Identificador del alumno en WordPress.
   *
   * @return bool
   *   TRUE únicamente si la autorización se ha podido confirmar. Cualquier
   *   otra circunstancia devuelve FALSE (§13).
   */
  public function isAuthorized(string $externalUserId): bool {
    return $this->decide($externalUserId)?->granted === TRUE;
  }

  /**
   * Devuelve la decisión completa, incluida la fecha de caducidad.
   *
   * NULL significa que no se ha podido determinar. Lo usa el panel para
   * avisar al alumno cuando su acceso está a punto de expirar.
   */
  public function decide(string $externalUserId): ?AccessDecision {
    $courseId = $this->getCourseId();

    if ($courseId === '') {
      $this->logger->error('No hay curso autorizador configurado; se deniega el acceso.');

      return NULL;
    }

    try {
      $decision = $this->provider->checkAccess($externalUserId, $courseId);
    }
    catch (WordPressUnavailableException $e) {
      $this->logger->warning('No se pudo determinar la autorización; se deniega el acceso. Motivo: @reason', [
        '@reason' => $e->getMessage(),
      ]);

      return NULL;
    }

    if (!$decision->granted) {
      $this->logger->info('Acceso denegado al usuario externo @uid.', [
        '@uid' => $externalUserId,
      ]);

      return $decision;
    }

    $this->logger->info('Acceso concedido al usuario externo @uid (curso @course, origen @source).', [
      '@uid' => $externalUserId,
      '@course' => $decision->courseId,
      '@source' => $decision->source,
    ]);

    return $decision;
  }

  /**
   * Curso configurado como autorizador.
   */
  public function getCourseId(): string {
    return trim((string) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('wordpress.course_id'));
  }

}
