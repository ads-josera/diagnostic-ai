<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba a qué agentes tiene derecho un alumno según lo que ha comprado.
 *
 * Es la pieza que sustituye al «un curso, un agente» del principio. Un error
 * aquí no se ve: el alumno entra igual, pero al agente que no le toca, o deja
 * de ver uno que sí pagó.
 *
 * Se cubre expresamente el caso del plugin de WordPress ANTERIOR a la 1.2.0,
 * que no envía la lista de cursos. Sin ese respaldo, actualizar Drupal antes
 * que WordPress dejaría a todos los alumnos sin ningún agente.
 */
#[CoversClass(AgentRegistry::class)]
final class AgentRegistryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  private const CURSO_A = '35884';
  private const CURSO_B = '99999';

  /**
   * Registro bajo prueba.
   */
  private AgentRegistry $registro;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['sales_leadership_diagnostic']);
    $this->registro = $this->container->get(AgentRegistry::class);

    $this->crearAgente('agente_a', self::CURSO_A, 0);
    $this->crearAgente('agente_b', self::CURSO_B, 10);
  }

  /**
   * Quien tiene un curso obtiene su agente, y solo ese.
   */
  public function testUnCursoDaSuAgente(): void {
    $this->assertSame(
      ['agente_a'],
      $this->agentesDe([self::CURSO_A]),
      'El alumno debe recibir el agente del curso que compró.',
    );
  }

  /**
   * Quien compró dos cursos ve los dos agentes.
   */
  public function testDosCursosDanDosAgentes(): void {
    $this->assertSame(
      ['agente_a', 'agente_b'],
      $this->agentesDe([self::CURSO_A, self::CURSO_B]),
      'Comprar un segundo curso debe sumar su agente, no sustituir al primero.',
    );
  }

  /**
   * Un curso que no concede nada no da agentes.
   */
  public function testUnCursoDesconocidoNoDaNada(): void {
    $this->assertSame([], $this->agentesDe(['00000']));
  }

  /**
   * El acceso denegado manda sobre los cursos.
   *
   * Un periodo caducado no da derecho a nada aunque los cursos sigan
   * comprados. Se comprueba porque la tentación al leer el código es pensar
   * que basta con mirar la lista de cursos.
   */
  public function testElAccesoDenegadoNoDaAgentes(): void {
    $decision = new AccessDecision(
      granted: FALSE,
      courseId: self::CURSO_A,
      checkedAt: 0,
      ownedCourses: [self::CURSO_A],
    );

    $this->assertSame([], array_keys($this->registro->forDecision($decision)));
  }

  /**
   * Sin decisión no hay agentes.
   */
  public function testSinDecisionNoHayAgentes(): void {
    $this->assertSame([], array_keys($this->registro->forDecision(NULL)));
  }

  /**
   * Un plugin anterior a la 1.2.0 no envía la lista, y aun así funciona.
   */
  public function testElPluginAntiguoSigueFuncionando(): void {
    $decision = new AccessDecision(
      granted: TRUE,
      courseId: self::CURSO_A,
      checkedAt: 0,
      // Vacío: es lo que envía un plugin que no conoce `owned_courses`.
      ownedCourses: [],
    );

    $this->assertSame(
      ['agente_a'],
      array_keys($this->registro->forDecision($decision)),
      'Sin este respaldo, actualizar Drupal antes que WordPress dejaría a '
      . 'todos los alumnos sin ningún agente.',
    );
  }

  /**
   * Un agente deshabilitado no se ofrece.
   */
  public function testElAgenteDeshabilitadoNoSeOfrece(): void {
    $agente = $this->almacen()->load('agente_b');
    $agente->set('status', FALSE)->save();

    $this->assertSame(
      ['agente_a'],
      $this->agentesDe([self::CURSO_A, self::CURSO_B]),
    );
  }

  /**
   * Un agente a medias no se ofrece.
   *
   * Sin prompt no puede conversar. Ofrecerlo y que falle al abrirlo es peor
   * que no ofrecerlo: el alumno ya ha invertido la expectativa.
   */
  public function testElAgenteSinPromptNoSeOfrece(): void {
    $agente = $this->almacen()->load('agente_b');
    $agente->set('system_prompt', '')->save();

    $this->assertSame(
      ['agente_a'],
      $this->agentesDe([self::CURSO_A, self::CURSO_B]),
    );
  }

  /**
   * Solo se pregunta por los cursos que conceden algún agente.
   */
  public function testLosCursosSalenDeLosAgentes(): void {
    $this->assertSame(
      [self::CURSO_A, self::CURSO_B],
      $this->registro->getCourseIds(),
    );
  }

  /**
   * Identificadores de los agentes que dan unos cursos.
   *
   * @param string[] $cursos
   *   Cursos que posee el alumno.
   *
   * @return string[]
   *   Identificadores de agente.
   */
  private function agentesDe(array $cursos): array {
    $decision = new AccessDecision(
      granted: TRUE,
      courseId: $cursos[0] ?? '',
      checkedAt: 0,
      ownedCourses: $cursos,
    );

    return array_keys($this->registro->forDecision($decision));
  }

  /**
   * Crea un agente utilizable.
   */
  private function crearAgente(string $id, string $curso, int $peso): void {
    $this->almacen()->create([
      'id' => $id,
      'label' => strtoupper($id),
      'status' => TRUE,
      'weight' => $peso,
      'version' => '1.0',
      'course_id' => $curso,
      'system_prompt' => 'Prompt de ' . $id,
      'output_contract' => 'Contrato.',
    ])->save();
  }

  /**
   * Almacén de agentes.
   */
  private function almacen() {
    return $this->container->get('entity_type.manager')->getStorage('sld_agent');
  }

}
