<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Hook\DiagnosticRequirements;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Ningún documento de conocimiento puede quedar en la carpeta pública.
 *
 * Son la metodología propietaria del cliente. Hasta el 12-09-2026 el cargador
 * de agentes los escribía en `public://` y se descargaban sin iniciar sesión:
 * HTTP 200 y el archivo entero. Nada lo delataba. El informe de estado es
 * ahora la red, y esta prueba fija que la red funcione.
 */
#[CoversClass(DiagnosticRequirements::class)]
final class KnowledgePrivacyTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);
  }

  /**
   * Un documento en la carpeta pública es un error, y se nombra.
   */
  public function testUnDocumentoPublicoEsUnError(): void {
    $this->crearAgente('prospeccion', [
      $this->crearArchivo('private://sales-diagnostic/knowledge/protegido.docx'),
      $this->crearArchivo('public://knowledge/expuesto.docx'),
    ]);

    $aviso = $this->aviso();

    $this->assertSame(RequirementSeverity::Error, $aviso['severity']);
    $this->assertStringContainsString('expuesto.docx', (string) $aviso['description']);
    $this->assertStringNotContainsString('protegido.docx', (string) $aviso['description']);
  }

  /**
   * Con los documentos en privado, el aviso es correcto.
   */
  public function testTodoEnPrivadoEsCorrecto(): void {
    $this->crearAgente('prospeccion', [
      $this->crearArchivo('private://sales-diagnostic/knowledge/protegido.docx'),
    ]);

    $this->assertSame(RequirementSeverity::OK, $this->aviso()['severity']);
  }

  /**
   * El agente desactivado también cuenta.
   *
   * Su documento se descarga igual aunque ningún alumno pueda usarlo.
   */
  public function testElAgenteDesactivadoTambienCuenta(): void {
    $this->crearAgente('apagado', [$this->crearArchivo('public://knowledge/olvidado.docx')], FALSE);

    $this->assertSame(RequirementSeverity::Error, $this->aviso()['severity']);
  }

  /**
   * El aviso de privacidad del informe de estado.
   *
   * @return array<string, mixed>
   *   La entrada del informe.
   */
  private function aviso(): array {
    $requisitos = $this->container->get(DiagnosticRequirements::class)->runtime();

    return $requisitos['sales_leadership_diagnostic_knowledge_privacy'];
  }

  /**
   * Un registro de archivo con esa ubicación.
   *
   * No hace falta el archivo físico: lo que se comprueba es dónde está.
   */
  private function crearArchivo(string $uri): int {
    $archivo = File::create(['uri' => $uri, 'filename' => basename($uri), 'status' => 1]);
    $archivo->save();

    return (int) $archivo->id();
  }

  /**
   * Un agente con esos documentos.
   *
   * @param string $id
   *   Identificador.
   * @param int[] $fids
   *   Documentos de conocimiento.
   * @param bool $activo
   *   Si está activo.
   */
  private function crearAgente(string $id, array $fids, bool $activo = TRUE): void {
    $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->create([
        'id' => $id,
        'label' => strtoupper($id),
        'status' => $activo,
        'version' => '1.0',
        'course_id' => '35884',
        'system_prompt' => 'Prompt.',
        'knowledge_fids' => $fids,
      ])->save();
  }

}
