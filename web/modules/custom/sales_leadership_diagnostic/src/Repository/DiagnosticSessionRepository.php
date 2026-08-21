<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;

/**
 * Consultas sobre sesiones de diagnóstico.
 *
 * Concentra aquí las consultas para que los controllers no construyan
 * entity queries: una condición de propiedad olvidada en un controller es
 * exactamente el descuido que produce una fuga de datos entre alumnos.
 */
final class DiagnosticSessionRepository {

  public const ENTITY_TYPE = 'sld_diagnostic_session';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Sesiones de un alumno, de la más reciente a la más antigua.
   *
   * @return \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[]
   */
  public function loadForUser(int $uid, int $limit = 50): array {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);

    $ids = $storage->getQuery()
      // La comprobación de acceso se hace por entidad en el handler y en la
      // ruta. Aquí se filtra explícitamente por propietario, que es una
      // condición de negocio, no un sustituto del control de acceso.
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();

    if ($ids === []) {
      return [];
    }

    /** @var \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions */
    $sessions = $storage->loadMultiple($ids);

    return array_filter(
      $sessions,
      static fn ($session): bool => $session instanceof DiagnosticSessionInterface,
    );
  }

  /**
   * Número de diagnósticos que un alumno ha iniciado desde una fecha.
   *
   * Lo usa el control de límite diario (§44).
   */
  public function countForUserSince(int $uid, int $timestamp): int {
    $count = $this->entityTypeManager->getStorage(self::ENTITY_TYPE)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('created', $timestamp, '>=')
      ->count()
      ->execute();

    return (int) $count;
  }

}
