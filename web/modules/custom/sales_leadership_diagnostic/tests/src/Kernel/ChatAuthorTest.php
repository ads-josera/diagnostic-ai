<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Render\Markup;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Los mensajes del agente firman con SU nombre.
 *
 * Hasta el 14-09-2026 firmaban todos «Diagnostic AI», también los del agente
 * de prospección: con dos agentes, quien hablaba con uno leía en cada mensaje
 * el nombre del otro producto. Lo vio José Raúl revisando el rediseño.
 *
 * Se prueba la plantilla del chat del alumno y la del Estudio del gestor, que
 * reproduce su marcado: el mismo fallo estaba en las dos.
 */
final class ChatAuthorTest extends KernelTestBase {

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
   * Las dos pantallas que pintan conversaciones.
   *
   * @return array<string, array{0: string}>
   *   El tema de cada una.
   */
  public static function pantallas(): array {
    return [
      'chat del alumno' => ['sld_chat'],
      'estudio del gestor' => ['sld_studio'],
    ];
  }

  /**
   * Con agente, su nombre; el alumno sigue siendo «Tú».
   */
  #[DataProvider('pantallas')]
  public function testFirmaConElNombreDelAgente(string $tema): void {
    $html = $this->pintar($tema, 'GAP Prospecting AI');

    $this->assertStringContainsString('GAP Prospecting AI', $this->firmas($html)[0], 'El agente firma con su nombre.');
    $this->assertSame('Tú', $this->firmas($html)[1], 'El alumno sigue siendo «Tú».');
    $this->assertStringNotContainsString('Diagnostic AI', $html);
  }

  /**
   * Sin agente (borrado después de la conversación), el nombre genérico.
   */
  #[DataProvider('pantallas')]
  public function testSinAgenteQuedaElGenerico(string $tema): void {
    $this->assertSame('Diagnostic AI', $this->firmas($this->pintar($tema, NULL))[0]);
  }

  /**
   * La conversación de prueba: un turno del agente y uno del alumno.
   */
  private function pintar(string $tema, ?string $agente): string {
    $mensaje = fn(string $rol, int $n) => [
      'role' => $rol,
      'is_assistant' => $rol === 'assistant',
      'body' => Markup::create('Texto ' . $n),
      'time' => '10:0' . $n,
      'sequence' => $n,
    ];

    $build = [
      '#theme' => $tema,
      '#session_id' => 7,
      '#agent_name' => $agente,
      '#messages' => [$mensaje('assistant', 1), $mensaje('user', 2)],
    ];

    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Las firmas de los mensajes, en orden.
   *
   * @return string[]
   *   El texto de cada `.sld-chat__author`.
   */
  private function firmas(string $html): array {
    preg_match_all('#<span class="sld-chat__author">\s*(.*?)\s*</span>#s', $html, $coincidencias);

    return $coincidencias[1];
  }

}
