<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentChooser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba la pantalla de «¿sobre qué agente trabajas?».
 *
 * La comparten el Estudio del prompt y los Documentos de conocimiento. Hasta
 * el 02-09-2026 cada pantalla la construía por su cuenta y, como pasa siempre
 * con el marcado duplicado, divergieron: una pintaba botones y la otra una
 * lista de viñetas, de modo que la misma pregunta se veía distinta según por
 * qué pestaña se hubiera entrado.
 *
 * La prueba que de verdad importa es la de la hoja de estilos. El fallo que
 * originó todo esto fue que la rama de elección del Estudio salía ANTES de
 * adjuntarla: la pantalla se servía sin ningún formato y los botones aparecían
 * pegados uno a otro, leyéndose como un solo nombre. Que la hoja viaje CON el
 * selector, y no la adjunte quien llama, es lo que impide que vuelva a pasar
 * en la siguiente pantalla que lo use.
 */
#[CoversClass(AgentChooser::class)]
final class AgentChooserTest extends KernelTestBase {

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

  private const RUTA = 'sales_leadership_diagnostic.studio_agent';

  /**
   * Servicio bajo prueba.
   */
  private AgentChooser $chooser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['sales_leadership_diagnostic']);
    $this->container->get('router.builder')->rebuild();

    $this->chooser = $this->container->get(AgentChooser::class);
  }

  /**
   * El selector se lleva su hoja de estilos consigo.
   *
   * Sin esto la pantalla sale sin formato, y es exactamente el fallo que la
   * hizo nacer.
   */
  public function testElSelectorSeLlevaSuHojaDeEstilos(): void {
    $selector = $this->construir(['agente_a', 'agente_b']);

    $this->assertContains(
      'sales_leadership_diagnostic/studio',
      $selector['#attached']['library'],
      'La hoja debe viajar con el selector, no adjuntarla quien lo llama.',
    );
  }

  /**
   * Hay una opción por agente, y cada una lleva a la suya.
   */
  public function testHayUnaOpcionPorAgente(): void {
    $selector = $this->construir(['agente_a', 'agente_b']);

    $this->assertArrayHasKey('agente_a', $selector['opciones']);
    $this->assertArrayHasKey('agente_b', $selector['opciones']);
    $this->assertSame(
      '/admin/config/salesbumm/diagnostic/estudio/agente/agente_b',
      $selector['opciones']['agente_b']['#url']->toString(),
    );
  }

  /**
   * Las opciones van dentro de su propio contenedor.
   *
   * Es lo que las separa. Sueltas salían pegadas —«Sales Leadership Diagnostic
   * AIGAP Prospecting AI»— porque son elementos en línea y la clase de botón
   * no las distancia por sí sola.
   */
  public function testLasOpcionesVanEnSuContenedor(): void {
    $selector = $this->construir(['agente_a', 'agente_b']);

    $this->assertContains(
      'sld-chooser__options',
      $selector['opciones']['#attributes']['class'],
    );
  }

  /**
   * Sin ningún agente se avisa, en lugar de ofrecer una lista vacía.
   *
   * Son dos situaciones distintas: «elige cuál» y «no hay ninguno todavía».
   * Enseñar una pregunta sin respuestas posibles deja a quien mira buscando
   * unos botones que no existen.
   */
  public function testSinAgentesSeAvisaEnLugarDePreguntar(): void {
    $selector = $this->construir([]);

    $this->assertArrayNotHasKey('opciones', $selector);
    $this->assertSame('No hay ninguno.', $selector['texto']['#markup']);
  }

  /**
   * Construye el selector con los agentes indicados.
   *
   * @param string[] $ids
   *   Agentes a crear y ofrecer.
   *
   * @return array<string, mixed>
   *   El elemento de renderizado.
   */
  private function construir(array $ids): array {
    $almacen = $this->container->get('entity_type.manager')->getStorage('sld_agent');
    $agentes = [];

    foreach ($ids as $id) {
      $almacen->create([
        'id' => $id,
        'label' => strtoupper($id),
        'status' => TRUE,
        'version' => '1.0',
        'course_id' => '35884',
        'system_prompt' => 'Prompt.',
      ])->save();

      $agentes[$id] = $almacen->load($id);
    }

    return $this->chooser->build($agentes, self::RUTA, '¿Cuál?', 'No hay ninguno.');
  }

}
