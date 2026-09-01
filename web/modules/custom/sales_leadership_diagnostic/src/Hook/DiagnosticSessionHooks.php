<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;

/**
 * Reacciones al ciclo de vida de una sesión de diagnóstico.
 */
final class DiagnosticSessionHooks {

  public function __construct(
    private readonly DiagnosticMessageRepository $messages,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_delete() for sld_diagnostic_session.
   *
   * Borra en cascada los mensajes de la sesión y su resultado.
   *
   * Ninguno de los dos se limpia solo. Los mensajes viven en una tabla propia,
   * que Entity API no conoce; y el resultado apunta a la sesión, no al revés,
   * así que borrar la sesión lo deja apuntando a algo que ya no existe.
   *
   * Sin esto quedaban huérfanos indefinidamente, conservando el análisis del
   * negocio del alumno en una sesión que el sistema considera eliminada (§43).
   * Y además INVISIBLE: el historial se construye a partir de las sesiones, de
   * modo que un resultado sin la suya no se puede alcanzar ni borrar desde
   * ninguna pantalla. Se encontró el 31-08-2026 al borrar una sesión de
   * pruebas y comprobar que su diagnóstico seguía en la base de datos.
   *
   * Ojo con no confundirlo con la retención de conversaciones, que borra
   * mensajes y NUNCA resultados: allí la sesión sigue viva y el diagnóstico
   * sigue siendo alcanzable, que es justo lo que aquí no pasa.
   */
  #[Hook('sld_diagnostic_session_delete')]
  public function onSessionDelete(EntityInterface $entity): void {
    if (!$entity instanceof DiagnosticSessionInterface) {
      return;
    }

    $id = $entity->id();

    if ($id === NULL) {
      return;
    }

    $this->messages->deleteForSession((int) $id);
    $this->borrarResultado((int) $id);
  }

  /**
   * Borra el resultado que produjo una sesión, si lo hubo.
   *
   * @param int $sessionId
   *   Sesión que se está eliminando.
   */
  private function borrarResultado(int $sessionId): void {
    $storage = $this->entityTypeManager->getStorage('sld_diagnostic_result');

    $ids = $storage->getQuery()
      // Sin comprobación de acceso: esto corre como parte de un borrado ya
      // autorizado, y a veces sin usuario en curso —desde cron, o desde el
      // borrado de una cuenta—, donde comprobarlo no devolvería nada.
      ->accessCheck(FALSE)
      ->condition('session_id', $sessionId)
      ->execute();

    if ($ids === []) {
      return;
    }

    $storage->delete($storage->loadMultiple($ids));
  }

}
