<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Exception\RateLimitException;
use Drupal\sales_leadership_diagnostic\Service\Security\RateLimiter;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * El límite de sesiones por día se cuenta por agente.
 *
 * Decisión de José Raúl, 16-09-2026. Contado en total, el usuario de prueba
 * se quedó sin poder iniciar nada tras una sesión con cada agente y una
 * repetición, que es un uso normal con dos agentes.
 */
#[CoversNothing]
final class DailyStartLimitTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  private const ALUMNO = 7;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['sales_leadership_diagnostic']);
    $this->config('sales_leadership_diagnostic.settings')
      ->set('security.max_diagnostics_per_day', 3)
      ->save();
  }

  /**
   * Agotar un agente no bloquea al otro.
   */
  public function testAgotarUnAgenteNoBloqueaAlOtro(): void {
    $limite = $this->container->get(RateLimiter::class);

    for ($i = 0; $i < 3; $i++) {
      $limite->assertCanStartDiagnostic(self::ALUMNO, 'agente_a');
      $limite->registerDiagnostic(self::ALUMNO, 'agente_a');
    }

    // El otro agente sigue libre: sin excepción.
    $limite->assertCanStartDiagnostic(self::ALUMNO, 'agente_b');

    $this->expectException(RateLimitException::class);
    $limite->assertCanStartDiagnostic(self::ALUMNO, 'agente_a');
  }

  /**
   * Otro alumno no comparte contador.
   */
  public function testCadaAlumnoTieneSuContador(): void {
    $limite = $this->container->get(RateLimiter::class);

    for ($i = 0; $i < 3; $i++) {
      $limite->registerDiagnostic(self::ALUMNO, 'agente_a');
    }

    $limite->assertCanStartDiagnostic(self::ALUMNO + 1, 'agente_a');
    $this->addToAssertionCount(1);
  }

}
