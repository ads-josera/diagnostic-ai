<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;

/**
 * Consultas sobre resultados de diagnóstico.
 */
final class DiagnosticResultRepository {

  public const ENTITY_TYPE = 'sld_diagnostic_result';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resultados de un alumno, indexados por la sesión que los produjo.
   *
   * Devolverlos indexados evita que el panel tenga que hacer una consulta por
   * cada fila del historial para saber si esa sesión tiene resultado.
   *
   * @return array<int, \Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface>
   */
  public function loadForUserIndexedBySession(int $uid): array {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $indexed = [];

    foreach ($storage->loadMultiple($ids) as $result) {
      if (!$result instanceof DiagnosticResultInterface) {
        continue;
      }

      $sessionId = $result->getSessionId();

      if ($sessionId !== NULL) {
        $indexed[$sessionId] = $result;
      }
    }

    return $indexed;
  }

}
