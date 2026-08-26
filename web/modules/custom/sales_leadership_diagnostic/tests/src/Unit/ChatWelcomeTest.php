<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ChatWelcome;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba la pantalla que ve el alumno antes de escribir nada.
 *
 * Los textos los redacta el gestor, así que llegan como llegan: con huecos, con
 * espacios sueltos, o vacíos del todo si decide quitarlos. Nada de eso debe
 * producir botones sin texto ni una pantalla rota, porque es lo primero que ve
 * el alumno y no hay una segunda oportunidad de causar buena impresión.
 *
 * Desde el 26-08-2026 la bienvenida sale del AGENTE y no de un objeto de
 * configuración único. Con varios agentes, lo segundo significaba que quien
 * comprara el curso de prospección se encontrara presentándose el diagnóstico
 * de liderazgo.
 */
#[CoversClass(ChatWelcome::class)]
final class ChatWelcomeTest extends UnitTestCase {

  /**
   * Las sugerencias configuradas se devuelven en orden.
   */
  public function testDevuelveLasSugerenciasEnOrden(): void {
    $welcome = $this->welcome();

    $this->assertSame(
      ['Primera', 'Segunda', 'Tercera'],
      $welcome->getSuggestions($this->agente(['suggestions' => ['Primera', 'Segunda', 'Tercera']])),
    );
  }

  /**
   * Un hueco en medio no deja un botón vacío ni descoloca el resto.
   *
   * Es el caso real: el gestor borra la segunda sugerencia y guarda.
   */
  public function testUnHuecoEnMedioNoProduceUnBotonVacio(): void {
    $this->assertSame(
      ['Primera', 'Tercera'],
      $this->welcome()->getSuggestions($this->agente([
        'suggestions' => ['Primera', '', 'Tercera', '   '],
      ])),
    );
  }

  /**
   * Los espacios sobrantes se recortan.
   *
   * El texto viaja a un atributo de datos y de ahí al mensaje que se envía al
   * agente: un salto de línea pegado al final llegaría hasta el modelo.
   */
  public function testRecortaLosEspacios(): void {
    $this->assertSame(
      ['Con espacios'],
      $this->welcome()->getSuggestions($this->agente([
        'suggestions' => ["  Con espacios  \n"],
      ])),
    );
  }

  /**
   * Sin sugerencias configuradas, la lista está vacía y no falla.
   */
  public function testSinSugerenciasNoFalla(): void {
    $this->assertSame([], $this->welcome()->getSuggestions($this->agente()));
  }

  /**
   * Un texto introductorio vacío se trata como ausencia.
   *
   * Devolver una cadena vacía haría que la plantilla pintase un párrafo sin
   * contenido, que ocupa espacio y no dice nada.
   */
  public function testElIntroVacioEsAusencia(): void {
    $welcome = $this->welcome();

    $this->assertNull($welcome->getIntro($this->agente(['intro' => '   '])));
    $this->assertSame('Hola', $welcome->getIntro($this->agente(['intro' => '  Hola  '])));
  }

  /**
   * Sin nada configurado no hay pantalla que mostrar.
   */
  public function testSinContenidoNoHayPantalla(): void {
    $welcome = $this->welcome();

    $this->assertFalse($welcome->hasContent($this->agente()));
    $this->assertTrue($welcome->hasContent($this->agente(['intro' => 'Algo'])));
    $this->assertTrue($welcome->hasContent($this->agente(['suggestions' => ['Una']])));
  }

  /**
   * Una sesión cuyo agente ya no existe no rompe la pantalla.
   *
   * Ocurre si el gestor borra o deshabilita un agente con conversaciones
   * abiertas. El alumno se queda sin cartel de bienvenida, que es molesto;
   * una página rota lo sería mucho más.
   */
  public function testSinAgenteNoHayPantallaNiFallo(): void {
    $welcome = $this->welcome();

    $this->assertNull($welcome->getIntro(NULL));
    $this->assertSame([], $welcome->getSuggestions(NULL));
    $this->assertNull($welcome->getIconUrl(NULL));
    $this->assertFalse($welcome->hasContent(NULL));
  }

  /**
   * Un icono cuyo archivo ya no existe no deja una imagen rota.
   *
   * Puede pasar: el archivo se borra desde la administración de archivos sin
   * pasar por el formulario, y el agente sigue apuntando a él.
   */
  public function testUnIconoBorradoNoDejaImagenRota(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $entityTypes = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypes->method('getStorage')->willReturn($storage);

    $welcome = new ChatWelcome($entityTypes, $this->createMock(FileUrlGeneratorInterface::class));

    $this->assertNull($welcome->getIconUrl($this->agente(['icon' => 42])));
  }

  /**
   * Sin icono configurado no se consulta el almacén de archivos.
   */
  public function testSinIconoNoSeConsultaElAlmacen(): void {
    $entityTypes = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypes->expects($this->never())->method('getStorage');

    $welcome = new ChatWelcome($entityTypes, $this->createMock(FileUrlGeneratorInterface::class));

    $this->assertNull($welcome->getIconUrl($this->agente(['icon' => 0])));
  }

  /**
   * Doble de un agente con la bienvenida indicada.
   *
   * @param array{intro?: string, suggestions?: string[], icon?: int} $valores
   *   Lo que debe devolver el agente.
   */
  private function agente(array $valores = []): DiagnosticAgentInterface {
    $agente = $this->createMock(DiagnosticAgentInterface::class);
    $agente->method('getWelcomeIntro')->willReturn($valores['intro'] ?? '');
    $agente->method('getWelcomeSuggestions')->willReturn($valores['suggestions'] ?? []);
    $agente->method('getWelcomeIconFid')->willReturn($valores['icon'] ?? 0);

    return $agente;
  }

  /**
   * El servicio, con las dependencias que estas pruebas no ejercitan.
   */
  private function welcome(): ChatWelcome {
    return new ChatWelcome(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
    );
  }

}
