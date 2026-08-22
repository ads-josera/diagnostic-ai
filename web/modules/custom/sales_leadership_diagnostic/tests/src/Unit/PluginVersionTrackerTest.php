<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\sales_leadership_diagnostic\Service\WordPress\PluginVersionTracker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba cómo se interpreta la versión del plugin de WordPress.
 *
 * El caso que importa es el que parece un detalle: un plugin ANTERIOR a la
 * 1.1.0 no informa de su versión, así que su ausencia no significa «no lo sé»
 * sino «está desactualizado». Confundir las dos cosas dejaría invisible
 * exactamente el problema que este servicio existe para detectar, que ya
 * ocurrió una vez con el dato `started_at`.
 */
#[CoversClass(PluginVersionTracker::class)]
final class PluginVersionTrackerTest extends UnitTestCase {

  /**
   * Almacén simulado del estado.
   *
   * @var array<string, mixed>
   */
  private array $almacen = [];

  /**
   * Una instalación que nunca ha consultado no se da por desactualizada.
   *
   * Un informe en rojo nada más instalar sería ruido: todavía no ha habido
   * ocasión de hablar con WordPress.
   */
  public function testSinConsultarNoSeAcusaDeNada(): void {
    $tracker = $this->tracker();

    $this->assertFalse($tracker->hasObserved());
    $this->assertNull($tracker->getVersion());
    $this->assertTrue($tracker->meetsMinimum('1.1.0'));
  }

  /**
   * Un plugin que responde SIN versión se considera anterior al mínimo.
   *
   * Es el caso real: la versión empezó a informarse en la 1.1.0.
   */
  public function testResponderSinVersionEsEstarDesactualizado(): void {
    $tracker = $this->tracker();
    $tracker->record(NULL);

    $this->assertTrue($tracker->hasObserved(), 'Se ha hablado con WordPress, aunque no dijera su versión.');
    $this->assertNull($tracker->getVersion());
    $this->assertFalse(
      $tracker->meetsMinimum('1.1.0'),
      'No informar de la versión identifica a un plugin anterior a la 1.1.0.',
    );
  }

  /**
   * Una versión igual al mínimo cumple.
   */
  public function testLaVersionMinimaCumple(): void {
    $tracker = $this->tracker();
    $tracker->record('1.1.0');

    $this->assertSame('1.1.0', $tracker->getVersion());
    $this->assertTrue($tracker->meetsMinimum('1.1.0'));
  }

  /**
   * Una versión posterior también cumple.
   */
  public function testUnaVersionPosteriorCumple(): void {
    foreach (['1.1.1', '1.2.0', '1.10.0', '2.0.0'] as $version) {
      $this->almacen = [];
      $tracker = $this->tracker();
      $tracker->record($version);

      $this->assertTrue(
        $tracker->meetsMinimum('1.1.0'),
        sprintf('%s debería considerarse igual o posterior a 1.1.0.', $version),
      );
    }
  }

  /**
   * El salto de decena se compara como versión y no como texto.
   *
   * «1.10.0» es MENOR que «1.9.0» alfabéticamente y MAYOR como versión. Una
   * comparación de cadenas dejaría de reconocer las versiones nuevas justo al
   * llegar a la décima, que es cuando ya nadie recuerda esta sutileza.
   */
  public function testElSaltoDeDecenaSeCompraBien(): void {
    $this->almacen = [];
    $tracker = $this->tracker();
    $tracker->record('1.10.0');

    $this->assertTrue($tracker->meetsMinimum('1.9.0'));
    $this->assertGreaterThan(0, strcmp('1.9.0', '1.10.0'), 'Como texto, 1.9.0 parece mayor: por eso no se comparan como texto.');
  }

  /**
   * Una versión anterior no cumple.
   */
  public function testUnaVersionAnteriorNoCumple(): void {
    $tracker = $this->tracker();
    $tracker->record('1.0.9');

    $this->assertFalse($tracker->meetsMinimum('1.1.0'));
  }

  /**
   * Un valor que no es una cadena útil se trata como ausencia.
   */
  public function testLosValoresBasuraSeTratanComoAusencia(): void {
    foreach ([123, '', '   ', [], TRUE] as $basura) {
      $this->almacen = [];
      $tracker = $this->tracker();
      $tracker->record($basura);

      $this->assertNull($tracker->getVersion());
    }
  }

  /**
   * Repetir la misma versión no vuelve a escribir en el estado.
   *
   * El estado es una tabla de la base de datos y esto se ejecuta en cada
   * consulta de autorización. Sin la comprobación, cada visita de cada alumno
   * provocaría una escritura para un dato que cambia dos veces al año.
   */
  public function testNoEscribeSiLaVersionNoHaCambiado(): void {
    $escrituras = 0;

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn (string $key) => $this->almacen[$key] ?? NULL);
    $state->method('set')->willReturnCallback(
      function (string $key, $value) use (&$escrituras): void {
        $escrituras++;
        $this->almacen[$key] = $value;
      },
    );

    $tracker = new PluginVersionTracker($state, $this->tiempo());

    $tracker->record('1.1.0');
    $tracker->record('1.1.0');
    $tracker->record('1.1.0');

    $this->assertSame(1, $escrituras, 'Solo el primer registro debería escribir.');

    $tracker->record('1.2.0');

    $this->assertSame(2, $escrituras, 'Un cambio de versión sí debe escribirse.');
  }

  /**
   * Construye el servicio con un estado en memoria.
   */
  private function tracker(): PluginVersionTracker {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn (string $key) => $this->almacen[$key] ?? NULL);
    $state->method('set')->willReturnCallback(
      function (string $key, $value): void {
        $this->almacen[$key] = $value;
      },
    );

    return new PluginVersionTracker($state, $this->tiempo());
  }

  /**
   * Reloj fijo.
   */
  private function tiempo(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);

    return $time;
  }

}
