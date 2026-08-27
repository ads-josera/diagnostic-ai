<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Memory\StudentMemoryStore;
use Drupal\user\UserInterface;

/**
 * Lo que el módulo hace cuando desaparece una cuenta.
 *
 * Entity API no borra en cascada nada de esto: `uid` es una referencia como
 * otra cualquiera y nadie limpia las entidades que apuntan a un usuario
 * borrado. Hasta el 26-08-2026 solo se limpiaba la memoria, y lo demás —las
 * conversaciones y los diagnósticos— sobrevivía a su dueño.
 *
 * Eso fallaba por dos sitios a la vez, y el segundo es el que menos se ve:
 *
 * **Privacidad.** Lo que queda no son metadatos: es el análisis del negocio de
 * una persona y lo que escribió en su conversación. Una petición de borrado de
 * datos no se estaba cumpliendo, aunque en la interfaz no se viera nada.
 *
 * **Aislamiento con retraso.** Drupal reutiliza identificadores de usuario. Un
 * registro cuyo propietario es un uid que ya no existe se lo encuentra suyo la
 * siguiente cuenta a la que le toque ese número — y lo vería en su panel como
 * propio, porque el control de acceso compara exactamente eso: uid contra uid.
 * Toda la verificación del aislamiento entre alumnos descansa en la propiedad,
 * así que un propietario huérfano es un agujero en el cimiento.
 *
 * Se BORRA, no se desvincula. Desvincular conservaría datos agregados para el
 * cliente, pero entonces no sería un borrado de verdad y no serviría si
 * alguien lo exigiera. Si algún día hacen falta estadísticas, se agregan antes
 * de borrar.
 */
final class StudentDataHooks {

  /**
   * Canal de registro del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly StudentMemoryStore $memory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for user.
   *
   * Retira todo lo que el módulo guardaba de esa cuenta.
   */
  #[Hook('user_delete')]
  public function onUserDelete(EntityInterface $entity): void {
    if (!$entity instanceof UserInterface) {
      return;
    }

    $uid = $entity->id();

    if ($uid === NULL) {
      return;
    }

    $uid = (int) $uid;

    // Los resultados van PRIMERO. Referencian a su sesión, y borrar la sesión
    // antes dejaría, aunque fuese un instante, un resultado apuntando a algo
    // que ya no existe.
    $resultados = $this->borrar('sld_diagnostic_result', $uid);

    // Al borrar la sesión se llevan sus mensajes: viven en una tabla propia y
    // los limpia DiagnosticSessionHooks.
    $sesiones = $this->borrar('sld_diagnostic_session', $uid);

    $recuerdos = $this->memory->forgetAll($uid);

    if ($resultados + $sesiones + $recuerdos === 0) {
      return;
    }

    // Cifras, nunca contenido (§43). Sirven para poder acreditar el borrado
    // sin conservar nada de lo borrado.
    $this->logger->info(
      'Cuenta @uid eliminada: se retiraron @sesiones conversación(es), @resultados diagnóstico(s) y @recuerdos dato(s) de memoria.',
      [
        '@uid' => $uid,
        '@sesiones' => $sesiones,
        '@resultados' => $resultados,
        '@recuerdos' => $recuerdos,
      ],
    );
  }

  /**
   * Borra las entidades del tipo indicado que pertenezcan a la cuenta.
   *
   * @param string $tipo
   *   Tipo de entidad.
   * @param int $uid
   *   Cuenta eliminada.
   *
   * @return int
   *   Cuántas se borraron.
   */
  private function borrar(string $tipo, int $uid): int {
    $almacen = $this->entityTypeManager->getStorage($tipo);

    $ids = $almacen->getQuery()
      // Sin comprobación de acceso: esto corre durante el borrado de la
      // cuenta, no a petición de nadie, y filtrar por los permisos de quien
      // esté mirando dejaría datos sin borrar según quién lo hiciera.
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();

    if ($ids === []) {
      return 0;
    }

    $almacen->delete($almacen->loadMultiple($ids));

    return count($ids);
  }

}
