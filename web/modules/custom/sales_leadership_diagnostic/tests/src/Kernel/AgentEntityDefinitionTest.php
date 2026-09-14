<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * El agente de diagnóstico queda registrado como tipo de entidad instalado.
 *
 * El 14-09-2026 el informe de estado local decía que el tipo de entidad
 * «Agente de diagnóstico» necesitaba ser instalado: los agentes llegaron al
 * módulo cuando ya estaba instalado y su definición nunca se registró.
 * sales_leadership_diagnostic_update_10024() lo repara, y esta prueba
 * reproduce el entorno roto para comprobarlo.
 */
final class AgentEntityDefinitionTest extends KernelTestBase {

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
   * Con el registro borrado, la actualización lo deja como debe.
   */
  public function testLaActualizacionRegistraElAgente(): void {
    $gestor = $this->container->get('entity.definition_update_manager');

    // El entorno roto: la definición del agente sin registrar.
    $this->container->get('keyvalue')->get('entity.definitions.installed')->delete('sld_agent.entity_type');
    $this->container->get('entity.last_installed_schema.repository')->deleteLastInstalledDefinition('sld_agent');
    $this->assertArrayHasKey('sld_agent', $gestor->getChangeSummary(), 'Reproduce el aviso del informe de estado.');

    $this->container->get('module_handler')->loadInclude('sales_leadership_diagnostic', 'install');
    sales_leadership_diagnostic_update_10024();

    $this->assertArrayNotHasKey('sld_agent', $gestor->getChangeSummary(), 'El aviso desaparece.');
    $this->assertStringContainsString('nada que hacer', sales_leadership_diagnostic_update_10024(), 'Repetirla no hace nada.');
  }

}
