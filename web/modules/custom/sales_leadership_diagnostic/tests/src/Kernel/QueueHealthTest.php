<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Queue\DatabaseQueue;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Plugin\QueueWorker\DiagnosticTurnWorker;
use Drupal\sales_leadership_diagnostic\Plugin\QueueWorker\MemoryExtractionWorker;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\QueueHealth;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * La foto de la cola separa lo que espera de lo que ya se está generando.
 *
 * Nace de una demo con un cliente el 22-09-2026: se sintió lenta y nadie podía
 * decir si era la investigación —que tarda de verdad— o cola acumulada. Las
 * dos cosas se arreglan de maneras distintas, así que confundirlas lleva a
 * tocar lo que no toca.
 */
#[CoversClass(QueueHealth::class)]
final class QueueHealthTest extends KernelTestBase {

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
   * Sin nada encolado, y ni siquiera con la tabla creada, la foto sale vacía.
   *
   * La tabla de colas la crea Drupal la primera vez que alguien encola algo.
   * En un sitio recién instalado no existe, y eso no es una avería: es que no
   * hay nada pendiente. Sin esta comprobación, la pantalla de consumo reventaba
   * el primer día.
   */
  public function testSinColaLaFotoSaleVacia(): void {
    $foto = $this->salud()->snapshot();

    $this->assertSame(0, $foto->esperando);
    $this->assertSame(0, $foto->enCurso);
    $this->assertSame(0, $foto->esperaMasLarga);
    $this->assertTrue($foto->tranquila());
  }

  /**
   * Lo encolado espera; lo reclamado por un recogedor está en curso.
   */
  public function testDistingueLoQueEsperaDeLoQueYaSeEstaGenerando(): void {
    $cola = $this->cola(DiagnosticTurnWorker::QUEUE);
    $cola->createItem(['session_id' => 1]);
    $cola->createItem(['session_id' => 2]);

    $this->assertSame(2, $this->salud()->snapshot()->esperando);

    // Un recogedor toma el primero: deja de estar esperando.
    $cola->claimItem(900);

    $foto = $this->salud()->snapshot();

    $this->assertSame(1, $foto->esperando);
    $this->assertSame(1, $foto->enCurso);
    $this->assertFalse($foto->tranquila());
  }

  /**
   * Un turno cuya reserva caducó vuelve a contar como esperando.
   *
   * Es el caso del proceso que murió a mitad. Para la cola el elemento vuelve
   * a estar libre, y para el alumno lleva esperando desde el principio: si
   * contara como «en curso», la pantalla diría que hay trabajo en marcha
   * cuando no lo hay.
   */
  public function testLaReservaCaducadaCuentaComoEspera(): void {
    $cola = $this->cola(DiagnosticTurnWorker::QUEUE);
    $cola->createItem(['session_id' => 3]);
    $cola->claimItem(900);

    $ahora = $this->container->get('datetime.time')->getRequestTime();

    $this->container->get('database')->update(DatabaseQueue::TABLE_NAME)
      ->fields(['expire' => $ahora - 1])
      ->condition('name', DiagnosticTurnWorker::QUEUE)
      ->execute();

    $foto = $this->salud()->snapshot();

    $this->assertSame(1, $foto->esperando);
    $this->assertSame(0, $foto->enCurso);
  }

  /**
   * La espera más larga se mide sobre el más antiguo que no ha empezado.
   */
  public function testMideLaEsperaDelMasAntiguo(): void {
    $cola = $this->cola(DiagnosticTurnWorker::QUEUE);
    $cola->createItem(['session_id' => 4]);
    $cola->createItem(['session_id' => 5]);

    $ahora = $this->container->get('datetime.time')->getRequestTime();

    // El primero lleva cinco minutos; el segundo, recién llegado.
    $this->container->get('database')->update(DatabaseQueue::TABLE_NAME)
      ->fields(['created' => $ahora - 300])
      ->condition('name', DiagnosticTurnWorker::QUEUE)
      ->condition('item_id', 1)
      ->execute();

    $this->assertSame(300, $this->salud()->snapshot()->esperaMasLarga);
  }

  /**
   * La extracción de memoria se cuenta aparte: no hace esperar a nadie.
   */
  public function testLaMemoriaPendienteVaPorSuCuenta(): void {
    $this->cola(MemoryExtractionWorker::QUEUE)->createItem(['session_id' => 6]);

    $foto = $this->salud()->snapshot();

    $this->assertSame(1, $foto->memoriaPendiente);
    $this->assertSame(0, $foto->esperando, 'La memoria no es un turno esperando.');
    $this->assertTrue($foto->tranquila(), 'Y no debe inquietar: la conversación ya terminó.');
  }

  /**
   * El servicio bajo prueba.
   */
  private function salud(): QueueHealth {
    return $this->container->get(QueueHealth::class);
  }

  /**
   * Una cola por su nombre.
   */
  private function cola(string $nombre) {
    return $this->container->get('queue')->get($nombre);
  }

}
