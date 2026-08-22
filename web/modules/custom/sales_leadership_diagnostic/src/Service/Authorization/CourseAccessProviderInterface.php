<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Authorization;

use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;

/**
 * Contrato de la fuente que decide el derecho de acceso a un curso (§8).
 *
 * Aísla al módulo del LMS concreto. Hoy la implementación consulta a
 * LearnDash a través del puente de WordPress; si el cliente cambiara de LMS,
 * se escribe otra implementación y nada más del módulo se entera.
 *
 * El decorador de cache implementa esta misma interfaz, de modo que quien la
 * consume no sabe —ni necesita saber— si la respuesta viene de la red o de
 * una consulta anterior.
 */
interface CourseAccessProviderInterface {

  /**
   * Consulta si un usuario externo tiene derecho a un curso.
   *
   * @param string $externalUserId
   *   Identificador del usuario en el sistema externo, no en Drupal.
   * @param string $courseId
   *   Curso a comprobar.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException
   *   Si no se ha podido determinar. Nunca se lanza para expresar que el
   *   alumno no tiene acceso: eso es una respuesta válida, no un fallo.
   */
  public function checkAccess(string $externalUserId, string $courseId): AccessDecision;

}
