<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * La decisión que vuelve de la caché es la misma que se guardó.
 *
 * Nace de producción, 14-09-2026: `fromCache()` construía la copia por
 * posición y se dejaba fuera la lista de cursos. Cada lectura de la caché la
 * vaciaba y el alumno con dos cursos veía todos sus agentes la primera vez y
 * uno solo las siguientes.
 */
#[CoversNothing]
final class AccessDecisionTest extends UnitTestCase {

  /**
   * El viaje completo a la caché y de vuelta conserva todos los cursos.
   */
  public function testLaCacheConservaTodosLosCursos(): void {
    $viva = new AccessDecision(
      granted: TRUE,
      courseId: '35884',
      checkedAt: 1000,
      expiresAt: 2000,
      startedAt: 500,
      ownedCourses: ['35884', '38125'],
    );

    // Lo que hace CachedCourseAccessProvider: guardar, leer, marcar.
    $leida = AccessDecision::fromArray($viva->toArray())->fromCache();

    $this->assertSame(AccessDecision::SOURCE_CACHE, $leida->source);
    $this->assertSame(['35884', '38125'], $leida->getOwnedCourses());
    $this->assertSame($viva->expiresAt, $leida->expiresAt);
    $this->assertSame($viva->startedAt, $leida->startedAt);
  }

  /**
   * Marcar como caché no cambia nada más que el origen.
   */
  public function testMarcarComoCacheSoloCambiaElOrigen(): void {
    $viva = new AccessDecision(
      granted: TRUE,
      courseId: '35884',
      checkedAt: 1000,
      ownedCourses: ['35884', '38125'],
    );

    $marcada = $viva->fromCache();

    $esperado = get_object_vars($viva);
    $esperado['source'] = AccessDecision::SOURCE_CACHE;

    $this->assertSame($esperado, get_object_vars($marcada), 'Una propiedad nueva que fromCache() olvide copiar cae aquí.');
  }

}
