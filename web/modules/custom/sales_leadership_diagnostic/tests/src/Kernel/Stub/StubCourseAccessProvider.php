<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel\Stub;

use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Service\Authorization\CourseAccessProviderInterface;

/**
 * Proveedor de autorización controlable, para los tests.
 *
 * Sustituye únicamente la consulta a WordPress. Todo lo que hay por encima
 * —la fachada de autorización, la política ante averías, el servicio que crea
 * las sesiones— sigue siendo el código real, que es lo que se quiere probar.
 *
 * Se implementa como clase y no como mock de PHPUnit porque hace falta
 * inyectarlo en el contenedor de servicios, y un mock creado dentro del test
 * no existe todavía cuando el contenedor se construye.
 */
final class StubCourseAccessProvider implements CourseAccessProviderInterface {

  /**
   * Decisión que se devolverá en la siguiente consulta.
   *
   * Pública a propósito: el test la cambia entre una llamada y otra para
   * representar, por ejemplo, la renovación del acceso.
   *
   * @var \Drupal\sales_leadership_diagnostic\DTO\AccessDecision|null
   */
  public ?AccessDecision $decision = NULL;

  /**
   * {@inheritdoc}
   */
  public function checkAccess(string $externalUserId, string $courseId): AccessDecision {
    // Sin decisión preparada se deniega. Un test que olvide configurarla debe
    // fallar de forma evidente, no pasar por accidente.
    return $this->decision ?? new AccessDecision(
      granted: FALSE,
      courseId: $courseId,
      checkedAt: 0,
    );
  }

}
