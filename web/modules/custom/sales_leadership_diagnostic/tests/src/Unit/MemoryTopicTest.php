<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\sales_leadership_diagnostic\MemoryTopic;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Cada tema tiene dos nombres: el del panel y el que lee el modelo.
 *
 * El panel le habla de tú al alumno y mostraba «Su empresa». El nombre en
 * tercera persona es el correcto dentro de la memoria que se le da al modelo,
 * así que no se cambió: se añadió el del panel.
 */
#[CoversNothing]
final class MemoryTopicTest extends UnitTestCase {

  /**
   * Ningún tema se queda sin nombre para el alumno.
   */
  public function testCadaTemaTieneNombreParaElAlumno(): void {
    foreach (MemoryTopic::cases() as $tema) {
      $this->assertNotSame('', trim($tema->studentLabel()->getUntranslatedString()), $tema->value);
    }
  }

  /**
   * El panel no le habla de usted.
   */
  public function testElPanelNoLeHablaDeUsted(): void {
    foreach (MemoryTopic::cases() as $tema) {
      $this->assertDoesNotMatchRegularExpression('/\bSu\b|\bvende\b/u', $tema->studentLabel()->getUntranslatedString(), $tema->value);
    }
  }

  /**
   * Lo que lee el modelo sigue igual.
   */
  public function testLoQueLeeElModeloNoCambia(): void {
    $this->assertSame('Su empresa', MemoryTopic::Empresa->label()->getUntranslatedString());
    $this->assertSame('A quién le vende', MemoryTopic::Icp->label()->getUntranslatedString());
  }

}
