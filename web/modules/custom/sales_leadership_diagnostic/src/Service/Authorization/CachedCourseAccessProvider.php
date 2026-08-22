<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Authorization;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Añade cache y política de degradación a la consulta de autorización.
 *
 * Es un decorador: implementa la misma interfaz que envuelve, de modo que
 * quien la consume no sabe si la respuesta viene de la red o de una consulta
 * anterior. Separarlo así mantiene LearnDashAccessProvider dedicado a hablar
 * con WordPress y esta clase dedicada a decidir cuándo hace falta preguntar.
 *
 * Resuelve dos problemas distintos que conviene no confundir.
 *
 * CACHE (§14). Evita consultar a WordPress en cada clic. Una entrada reciente
 * se usa sin preguntar. Los TTL son diferenciados a propósito: una concesión
 * se cachea durante minutos, pero una denegación solo durante segundos, porque
 * un alumno que acaba de comprar el curso no debería esperar a que expire.
 * Cachear ambas por igual haría que la compra tardase en surtir efecto tanto
 * como tarda una revocación, que es justo al revés de lo deseable.
 *
 * DEGRADACIÓN (§13). Si WordPress no responde y no hay entrada fresca, la
 * regla por defecto es denegar. La única excepción, explícitamente diseñada,
 * es una concesión anterior dentro del periodo de gracia: alguien que ya fue
 * verificado hace poco no debería quedarse fuera por una caída ajena. Una
 * denegación anterior nunca se reutiliza de este modo, y una avería jamás
 * concede acceso a quien no lo tenía ya.
 */
final class CachedCourseAccessProvider implements CourseAccessProviderInterface {

  /**
   * Prefijo de las claves de cache.
   */
  private const CACHE_PREFIX = 'sld:authorization:';

  /**
   * Etiqueta que permite invalidar todas las autorizaciones de una vez.
   */
  public const CACHE_TAG = 'sld_authorization';

  /**
   * Canal de log del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly CourseAccessProviderInterface $inner,
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(string $externalUserId, string $courseId): AccessDecision {
    $key = self::CACHE_PREFIX . $externalUserId . ':' . $courseId;
    $stored = $this->readStored($key);
    $now = $this->time->getRequestTime();

    if ($stored !== NULL && $this->isFresh($stored, $now)) {
      return $stored->fromCache();
    }

    try {
      $decision = $this->inner->checkAccess($externalUserId, $courseId);
    }
    catch (WordPressUnavailableException $e) {
      return $this->degrade($stored, $courseId, $now, $e);
    }

    $this->store($key, $decision);

    return $decision;
  }

  /**
   * Decide qué hacer cuando no se ha podido consultar a WordPress.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException
   */
  private function degrade(?AccessDecision $stored, string $courseId, int $now, WordPressUnavailableException $e): AccessDecision {
    $grace = $this->getGracePeriod();

    // Solo se reutiliza una CONCESIÓN anterior, y solo dentro del periodo de
    // gracia. Reutilizar una denegación no aportaría nada —el resultado sería
    // el mismo que denegar— y reutilizar cualquier cosa fuera de la ventana
    // convertiría la cache en una autorización permanente.
    if ($stored !== NULL
      && $stored->granted
      && $grace > 0
      && ($now - $stored->checkedAt) <= $grace) {

      $this->logger->warning('WordPress no responde; se mantiene una autorización verificada hace @age s dentro del periodo de gracia de @grace s.', [
        '@age' => $now - $stored->checkedAt,
        '@grace' => $grace,
      ]);

      return $stored->fromCache();
    }

    // Fail closed: sin forma de comprobar y sin concesión reciente, no se
    // concede acceso nuevo. Se propaga la excepción para que quien llame
    // pueda distinguirlo de una denegación legítima.
    throw $e;
  }

  /**
   * Lee una decisión guardada, si existe y tiene la forma esperada.
   */
  private function readStored(string $key): ?AccessDecision {
    $item = $this->cache->get($key);

    if ($item === FALSE || !is_array($item->data)) {
      return NULL;
    }

    return AccessDecision::fromArray($item->data);
  }

  /**
   * Indica si una decisión guardada sigue siendo lo bastante reciente.
   */
  private function isFresh(AccessDecision $decision, int $now): bool {
    $ttl = $decision->granted ? $this->getGrantedTtl() : $this->getDeniedTtl();

    if ($ttl <= 0) {
      return FALSE;
    }

    return ($now - $decision->checkedAt) < $ttl;
  }

  /**
   * Guarda una decisión.
   *
   * La entrada vive físicamente lo que dure el periodo de gracia, aunque deje
   * de considerarse fresca mucho antes: es lo que permite que siga disponible
   * como último recurso si WordPress cae.
   */
  private function store(string $key, AccessDecision $decision): void {
    $ttl = $decision->granted ? $this->getGrantedTtl() : $this->getDeniedTtl();
    $lifetime = max($ttl, $decision->granted ? $this->getGracePeriod() : 0);

    if ($lifetime <= 0) {
      return;
    }

    $this->cache->set(
      $key,
      $decision->toArray(),
      $this->time->getRequestTime() + $lifetime,
      [self::CACHE_TAG],
    );
  }

  /**
   * TTL de una concesión, en segundos.
   */
  private function getGrantedTtl(): int {
    return (int) $this->config()->get('wordpress.cache_ttl_granted');
  }

  /**
   * TTL de una denegación, en segundos.
   */
  private function getDeniedTtl(): int {
    return (int) $this->config()->get('wordpress.cache_ttl_denied');
  }

  /**
   * Periodo de gracia, en segundos. Cero lo desactiva por completo.
   */
  private function getGracePeriod(): int {
    return (int) $this->config()->get('wordpress.cache_grace_period');
  }

  /**
   * Configuración del módulo.
   */
  private function config() {
    return $this->configFactory->get('sales_leadership_diagnostic.settings');
  }

}
