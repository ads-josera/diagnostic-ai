<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Controller\ResultsController;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba el encabezado de la página de resultado.
 *
 * Cada agente puede fijar el suyo desde el 02-09-2026. Nació porque el segundo
 * agente no entrega un diagnóstico: cierra con un Weekly GOLD Pack —cuentas,
 * buyers, routing y outreach—, y encabezar esa página con «Resultado de tu
 * diagnóstico» describe mal lo que la persona tiene delante.
 *
 * Las dos pruebas que importan no son la del título propio, sino las de los
 * respaldos: un agente que no dice nada debe seguir viéndose como siempre, y
 * un resultado cuyo agente ya no existe no puede reventar la página. Lo segundo
 * pasa de verdad —el administrador puede borrar un agente y sus resultados
 * siguen ahí— y dejaría sin ver un diagnóstico que alguien pagó.
 */
#[CoversClass(ResultsController::class)]
final class ResultTitleTest extends KernelTestBase {

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

  private const POR_DEFECTO = 'Resultado de tu diagnóstico';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('sld_diagnostic_result');
    $this->installConfig(['system', 'sales_leadership_diagnostic']);
  }

  /**
   * El agente que fija un encabezado propio, manda.
   */
  public function testElAgenteConEncabezadoPropioManda(): void {
    $this->crearAgente('prospeccion', 'Tu Weekly GOLD Pack');

    $this->assertSame(
      'Tu Weekly GOLD Pack',
      $this->tituloDe('prospeccion'),
    );
  }

  /**
   * El agente que no dice nada usa el de siempre.
   *
   * Es lo que garantiza que el primer agente no cambie: su ficha no lleva este
   * campo y no tiene por qué llevarlo.
   */
  public function testElAgenteSinEncabezadoUsaElDeSiempre(): void {
    $this->crearAgente('liderazgo', '');

    $this->assertSame(self::POR_DEFECTO, $this->tituloDe('liderazgo'));
  }

  /**
   * Un encabezado con solo espacios cuenta como vacío.
   *
   * Quien deja el campo con un espacio al guardar no está pidiendo una página
   * sin título.
   */
  public function testUnEncabezadoEnBlancoCuentaComoVacio(): void {
    $this->crearAgente('descuidado', '   ');

    $this->assertSame(self::POR_DEFECTO, $this->tituloDe('descuidado'));
  }

  /**
   * Un resultado cuyo agente ya NO existe sigue abriéndose.
   *
   * El administrador puede borrar un agente, y sus resultados no se van con
   * él. Sin este respaldo, la página moriría al pedirle el título a algo que
   * ya no está, y el alumno perdería el acceso a un diagnóstico suyo.
   */
  public function testUnResultadoSinAgenteSigueAbriendose(): void {
    $this->assertSame(self::POR_DEFECTO, $this->tituloDe('agente_borrado'));
  }

  /**
   * Un resultado antiguo, sin agente anotado, también.
   *
   * Los hay: el campo `agent` se rellena desde las fases multi-agente, y antes
   * de eso los resultados nacían sin él.
   */
  public function testUnResultadoAntiguoSinAgenteTambien(): void {
    $this->assertSame(self::POR_DEFECTO, $this->tituloDe(''));
  }

  /**
   * Título que da el controller para un resultado de ese agente.
   */
  private function tituloDe(string $agentId): string {
    return ResultsController::create($this->container)->title($this->crearResultado($agentId));
  }

  /**
   * Crea un agente utilizable con el encabezado indicado.
   */
  private function crearAgente(string $id, string $titulo): void {
    $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->create([
        'id' => $id,
        'label' => strtoupper($id),
        'status' => TRUE,
        'version' => '1.0',
        'course_id' => '35884',
        'system_prompt' => 'Prompt.',
        'result_title' => $titulo,
      ])->save();
  }

  /**
   * Crea un resultado atribuido a un agente.
   */
  private function crearResultado(string $agentId): DiagnosticResultInterface {
    $resultado = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_result')
      ->create([
        'uid' => 5,
        'session_id' => 1,
        'agent' => $agentId,
        'diagnostic_version' => '1.0',
        'summary' => 'Resumen.',
      ]);
    $resultado->save();

    return $resultado;
  }

}
