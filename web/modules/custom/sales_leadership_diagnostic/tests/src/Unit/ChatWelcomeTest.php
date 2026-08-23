<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
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
 */
#[CoversClass(ChatWelcome::class)]
final class ChatWelcomeTest extends UnitTestCase {

  /**
   * Las sugerencias configuradas se devuelven en orden.
   */
  public function testDevuelveLasSugerenciasEnOrden(): void {
    $welcome = $this->welcome([
      'welcome_suggestions' => ['Primera', 'Segunda', 'Tercera'],
    ]);

    $this->assertSame(['Primera', 'Segunda', 'Tercera'], $welcome->getSuggestions());
  }

  /**
   * Un hueco en medio no deja un botón vacío ni descoloca el resto.
   *
   * Es el caso real: el gestor borra la segunda sugerencia y guarda.
   */
  public function testUnHuecoEnMedioNoProduceUnBotonVacio(): void {
    $welcome = $this->welcome([
      'welcome_suggestions' => ['Primera', '', 'Tercera', '   '],
    ]);

    $this->assertSame(['Primera', 'Tercera'], $welcome->getSuggestions());
  }

  /**
   * Los espacios sobrantes se recortan.
   *
   * El texto viaja a un atributo de datos y de ahí al mensaje que se envía al
   * agente: un salto de línea pegado al final llegaría hasta el modelo.
   */
  public function testRecortaLosEspacios(): void {
    $welcome = $this->welcome([
      'welcome_suggestions' => ["  Con espacios  \n"],
    ]);

    $this->assertSame(['Con espacios'], $welcome->getSuggestions());
  }

  /**
   * Sin sugerencias configuradas, la lista está vacía y no falla.
   */
  public function testSinSugerenciasNoFalla(): void {
    $this->assertSame([], $this->welcome([])->getSuggestions());
    $this->assertSame([], $this->welcome(['welcome_suggestions' => 'no es una lista'])->getSuggestions());
  }

  /**
   * Un texto introductorio vacío se trata como ausencia.
   *
   * Devolver una cadena vacía haría que la plantilla pintase un párrafo sin
   * contenido, que ocupa espacio y no dice nada.
   */
  public function testElIntroVacioEsAusencia(): void {
    $this->assertNull($this->welcome(['welcome_intro' => '   '])->getIntro());
    $this->assertSame('Hola', $this->welcome(['welcome_intro' => '  Hola  '])->getIntro());
  }

  /**
   * Sin nada configurado no hay pantalla que mostrar.
   */
  public function testSinContenidoNoHayPantalla(): void {
    $this->assertFalse($this->welcome([])->hasContent());
    $this->assertTrue($this->welcome(['welcome_intro' => 'Algo'])->hasContent());
    $this->assertTrue($this->welcome(['welcome_suggestions' => ['Una']])->hasContent());
  }

  /**
   * Un icono cuyo archivo ya no existe no deja una imagen rota.
   *
   * Puede pasar: el archivo se borra desde la administración de archivos sin
   * pasar por el formulario, y la configuración sigue apuntando a él.
   */
  public function testUnIconoBorradoNoDejaImagenRota(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $entityTypes = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypes->method('getStorage')->willReturn($storage);

    $welcome = new ChatWelcome(
      $this->getConfigFactoryStub([
        ChatWelcome::CONFIG_NAME => ['welcome_icon_fid' => 42],
      ]),
      $entityTypes,
      $this->createMock(FileUrlGeneratorInterface::class),
    );

    $this->assertNull($welcome->getIconUrl());
  }

  /**
   * Sin icono configurado no se consulta el almacén de archivos.
   */
  public function testSinIconoNoSeConsultaElAlmacen(): void {
    $entityTypes = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypes->expects($this->never())->method('getStorage');

    $welcome = new ChatWelcome(
      $this->getConfigFactoryStub([
        ChatWelcome::CONFIG_NAME => ['welcome_icon_fid' => 0],
      ]),
      $entityTypes,
      $this->createMock(FileUrlGeneratorInterface::class),
    );

    $this->assertNull($welcome->getIconUrl());
  }

  /**
   * Construye el servicio con la configuración dada.
   *
   * @param array<string, mixed> $values
   *   Ajustes de la pantalla de bienvenida.
   */
  private function welcome(array $values): ChatWelcome {
    return new ChatWelcome(
      $this->getConfigFactoryStub([
        ChatWelcome::CONFIG_NAME => $values + [
          'welcome_icon_fid' => 0,
          'welcome_intro' => '',
          'welcome_suggestions' => [],
        ],
      ]),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
    );
  }

}
