<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Service\WordPress\PluginVersionTracker;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * La fecha del plugin dice cuándo apareció esa versión, no cuándo se habló.
 *
 * Nace de una confusión real, el 01-10-2026: el informe de estado decía
 * «Comprobado por última vez el 13 Sep» con un plugin que había respondido esa
 * misma semana, y quien lo leyó pensó que la integración llevaba dieciocho días
 * muda. No era eso: el registro solo escribe cuando la versión CAMBIA —a
 * propósito, para no escribir en la base en cada consulta de autorización—, así
 * que la marca de tiempo es la de la primera vez que se vio esa versión.
 *
 * El comportamiento es correcto; lo que estaba mal era la etiqueta. Esta prueba
 * fija la semántica para que, si alguien cambia el ahorro de escrituras, se
 * entere de que hay un texto que depende de ella.
 */
#[CoversClass(PluginVersionTracker::class)]
final class PluginVersionTrackerTest extends KernelTestBase {

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

  /**
   * Volver a ver la MISMA versión no mueve la fecha.
   *
   * Es lo que ocurre en cada entrada de un alumno, y es la razón por la que la
   * fecha no se puede leer como «última vez que hablamos con WordPress».
   */
  public function testLaMismaVersionNoMueveLaFecha(): void {
    $registro = $this->registro();
    $registro->record('1.3.0');
    $primera = $registro->getSeenAt();

    $this->avanzarElReloj(3600);
    $registro->record('1.3.0');

    $this->assertSame($primera, $registro->getSeenAt());
  }

  /**
   * Una versión distinta sí la mueve, y es el único caso que la mueve.
   */
  public function testUnaVersionDistintaSiLaMueve(): void {
    $registro = $this->registro();
    $registro->record('1.3.0');
    $primera = $registro->getSeenAt();

    $this->avanzarElReloj(3600);
    $registro->record('1.4.0');

    $this->assertNotSame($primera, $registro->getSeenAt());
    $this->assertSame('1.4.0', $registro->getVersion());
  }

  /**
   * Un plugin que no informa su versión también se anota, y se distingue.
   *
   * NULL no es «no sabemos»: es «respondió un plugin anterior al que empezó a
   * informarlo», y por eso tiene fecha.
   */
  public function testElPluginQueNoInformaSuVersionTambienSeAnota(): void {
    $registro = $this->registro();
    $registro->record(NULL);

    $this->assertNull($registro->getVersion());
    $this->assertTrue($registro->hasObserved());
    $this->assertNotNull($registro->getSeenAt());
  }

  /**
   * El registro bajo prueba.
   */
  private function registro(): PluginVersionTracker {
    return $this->container->get(PluginVersionTracker::class);
  }

  /**
   * Mueve el reloj del contenedor, que en pruebas está congelado.
   */
  private function avanzarElReloj(int $segundos): void {
    $ahora = $this->container->get('datetime.time')->getRequestTime();
    $peticion = $this->container->get('request_stack')->getCurrentRequest();
    $peticion->server->set('REQUEST_TIME', $ahora + $segundos);
    $peticion->server->set('REQUEST_TIME_FLOAT', (float) ($ahora + $segundos));
  }

}
