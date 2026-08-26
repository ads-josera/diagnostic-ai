<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\ReadinessBlocker;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticReadiness;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba qué considera el módulo estar en condiciones de diagnosticar.
 *
 * Es la decisión que gobierna el botón del alumno y el informe de estado del
 * administrador, y una divergencia entre los dos deja al alumno con un error
 * incomprensible.
 *
 * La parte delicada es la del agente. Hasta el 26-08-2026 se preguntaba por el
 * `system_prompt` de la configuración antigua, que dejó de usarse al pasar a
 * varios agentes: contestaba «sí» porque allí había quedado un prompt viejo,
 * no porque hubiera nada utilizable, y el día que se vaciara habría dicho que
 * no se puede empezar teniendo el agente perfectamente cargado. Ahora se
 * pregunta a los agentes, y estas pruebas fijan esa correspondencia.
 */
#[CoversClass(DiagnosticReadiness::class)]
final class DiagnosticReadinessTest extends KernelTestBase {

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

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['sales_leadership_diagnostic']);

    $this->setSetting(SecretsProvider::JWT_SHARED_SECRET, str_repeat('a', 32));
    $this->setSetting(SecretsProvider::WP_HMAC_SECRET, str_repeat('b', 32));
    $this->setSetting(SecretsProvider::OPENAI_API_KEY, 'sk-de-prueba');

    $this->config('sales_leadership_diagnostic.settings')
      ->set('wordpress.api_base_url', 'https://ejemplo.test')
      ->set('wordpress.course_id', '35884')
      ->save();
  }

  /**
   * Sin ningún agente no se puede diagnosticar.
   *
   * Es el estado de una instalación recién hecha: todo configurado y nada que
   * ofrecer todavía.
   */
  public function testSinAgentesNoEstaListo(): void {
    $this->assertFalse($this->readiness()->isReady());
    $this->assertContains(ReadinessBlocker::AgentNotLoaded, $this->readiness()->blockers());
  }

  /**
   * Con un agente utilizable, sí.
   */
  public function testConUnAgenteUtilizableEstaListo(): void {
    $this->crearAgente();

    $this->assertTrue($this->readiness()->isReady());
    $this->assertSame([], $this->readiness()->blockers());
  }

  /**
   * Un agente deshabilitado no cuenta.
   */
  public function testUnAgenteDeshabilitadoNoCuenta(): void {
    $this->crearAgente()->disable()->save();

    $this->assertFalse($this->readiness()->isReady());
  }

  /**
   * Un agente sin prompt tampoco.
   *
   * Nacería condenado a fallar en el primer mensaje, así que ofrecerlo sería
   * peor que decir que no hay nada disponible.
   */
  public function testUnAgenteSinPromptNoCuenta(): void {
    $this->crearAgente()->set('system_prompt', '')->save();

    $this->assertFalse($this->readiness()->isReady());
  }

  /**
   * El prompt de la configuración antigua ya NO da por listo al módulo.
   *
   * Es la prueba que fija la corrección: por muy lleno que esté ese objeto de
   * configuración, sin agentes no hay nada que ofrecer.
   */
  public function testLaConfiguracionAntiguaYaNoDaPorListoAlModulo(): void {
    $this->config('sales_leadership_diagnostic.diagnostic')
      ->set('system_prompt', 'Un prompt que quedó de cuando había un solo agente.')
      ->save();

    $this->assertFalse(
      $this->readiness()->isReady(),
      'La configuración antigua no debe bastar: el diagnóstico lo conducen los agentes.',
    );
  }

  /**
   * Las etiquetas de cache siguen a los agentes.
   *
   * Sin esto, crear o deshabilitar un agente no se vería en el panel del
   * alumno hasta que caducara por otro motivo.
   */
  public function testLasEtiquetasDeCacheDependenDeLosAgentes(): void {
    $this->assertContains('config:sld_agent_list', $this->readiness()->getCacheTags());
  }

  /**
   * Crea un agente utilizable y lo devuelve.
   */
  private function crearAgente(): object {
    $agente = $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->create([
        'id' => 'agente_prueba',
        'label' => 'Agente de prueba',
        'status' => TRUE,
        'version' => '1.0-TEST',
        'course_id' => '35884',
        'system_prompt' => 'Prompt de prueba.',
      ]);
    $agente->save();

    return $agente;
  }

  /**
   * El servicio bajo prueba, recién pedido al contenedor.
   */
  private function readiness(): DiagnosticReadiness {
    return $this->container->get(DiagnosticReadiness::class);
  }

}
