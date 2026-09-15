<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgent;
use Drupal\sales_leadership_diagnostic\Form\DiagnosticAgentForm;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El curso que concede un agente es un solo número, y si no lo es se dice.
 *
 * Nace de producción, 14-09-2026: en la ficha de cada agente se copió la
 * lista de cursos del plugin («35884, 38125, 38128», el tercero una lección).
 * Se guardó sin queja, el alumno entró por WordPress y vio un panel vacío,
 * porque ese texto no coincide con ninguno de sus cursos.
 */
#[CoversNothing]
final class AgentCourseIdTest extends KernelTestBase {

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
   * Valores de curso y si valen.
   *
   * @return array<string, array{string, bool}>
   *   Valor escrito y si es un curso válido.
   */
  public static function cursos(): array {
    return [
      'un número' => ['35884', TRUE],
      'con espacios alrededor' => ['  35884 ', TRUE],
      'una lista' => ['35884, 38125', TRUE],
      'una lista sin espacios' => ['35884,38125', TRUE],
      'una coma de sobra' => ['35884, 38125,', TRUE],
      'separados por espacio' => ['35884 38125', FALSE],
      'un trozo que no es número' => ['35884, curso-test', FALSE],
      'texto' => ['curso-test', FALSE],
      'vacío' => ['', FALSE],
    ];
  }

  /**
   * Solo un número cuenta como curso, y solo con él se ofrece el agente.
   */
  #[DataProvider('cursos')]
  public function testSoloUnNumeroEsUnCurso(string $curso, bool $valido): void {
    $agente = $this->agente($curso);

    $this->assertSame($valido, $agente->hasValidCourseId());
    $this->assertSame($valido, $agente->isUsable(), 'Con un curso que no coincide con nadie, el agente no debe contarse como disponible.');
  }

  /**
   * La ficha no deja guardar la lista y explica qué poner.
   */
  #[DataProvider('cursos')]
  public function testLaFichaRechazaLoQueNoEsUnCurso(string $curso, bool $valido): void {
    if ($curso === '') {
      // Vacío lo frena el «obligatorio» del propio campo, no esta validación.
      $this->addToAssertionCount(1);
      return;
    }

    $formulario = DiagnosticAgentForm::create($this->container);
    $formulario->setEntity($this->agente('35884'));

    $estado = new FormState();
    $estado->setValue('course_id', $curso);
    $form = [];
    $formulario->validateForm($form, $estado);

    $errores = $estado->getErrors();

    if ($valido) {
      $this->assertArrayNotHasKey('course_id', $errores);
      $this->assertMatchesRegularExpression('/^\d+(, \d+)*$/', $estado->getValue('course_id'), 'Se guarda normalizado: «35884, 38125».');
    }
    else {
      $this->assertArrayHasKey('course_id', $errores);
      $this->assertStringContainsString('separados por comas', (string) $errores['course_id']);
    }
  }

  /**
   * La lista se lee curso a curso, sin repetidos ni huecos.
   */
  public function testLaListaSeLeeCursoPorCurso(): void {
    $this->assertSame(['35884', '38125'], $this->agente(' 35884 ,38125,, 35884 ')->getCourseIds());
    $this->assertSame([], $this->agente('')->getCourseIds());
  }

  /**
   * Un agente activo con prompt y el curso indicado.
   */
  private function agente(string $curso): DiagnosticAgent {
    return DiagnosticAgent::create([
      'id' => 'prueba',
      'label' => 'Agente de prueba',
      'status' => TRUE,
      'course_id' => $curso,
      'system_prompt' => 'Eres un agente de prueba.',
    ]);
  }

}
