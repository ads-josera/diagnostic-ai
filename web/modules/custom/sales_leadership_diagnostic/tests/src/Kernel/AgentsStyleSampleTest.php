<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Hook\DiagnosticThemeHooks;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Branding\HomePage;
use Drupal\sales_leadership_diagnostic\Service\Manager\ManagerNavigation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\Route;

/**
 * El estilo «AI Sales Agents» sale en el panel y en ninguna otra pantalla.
 *
 * El 13-09-2026 José Raúl pidió llevar a las pantallas del alumno el estilo
 * oscuro de la landing de la membresía, «con mucho cuidado de no romper
 * nada». Primero se enseña en una sola pantalla, el panel, para que lo apruebe.
 * Mientras tanto las demás comparten plantilla de marco con el panel, así que
 * esta prueba fija que el estilo no se les cuela.
 */
#[CoversClass(DiagnosticThemeHooks::class)]
final class AgentsStyleSampleTest extends KernelTestBase {

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
  }

  /**
   * Las pantallas del alumno, y si llevan el estilo.
   *
   * @return array<string, array{0: string, 1: bool}>
   *   Ruta y si debe llevarlo.
   */
  public static function pantallas(): array {
    // Se va ampliando por fases, y cada fase mueve aquí sus pantallas a TRUE.
    // Fase 1 (13-09-2026): la página de cada agente y «Mis cuentas».
    // Fase 2: el informe. Fase 3: la conversación. Fase 4: la portada, el
    // inicio de sesión y «sin acceso»; con eso, todas las del alumno.
    return [
      'panel' => ['sales_leadership_diagnostic.dashboard', TRUE],
      'página de un agente' => ['sales_leadership_diagnostic.agent_page', TRUE],
      'mis cuentas' => ['sales_leadership_diagnostic.accounts', TRUE],
      'informe' => ['sales_leadership_diagnostic.result', TRUE],
      'conversación' => ['sales_leadership_diagnostic.session', TRUE],
      'portada' => ['sales_leadership_diagnostic.welcome', TRUE],
      'inicio de sesión' => ['user.login', TRUE],
      'recuperar la contraseña' => ['user.pass', TRUE],
      'sin acceso' => ['sales_leadership_diagnostic.sso_denied', TRUE],
    ];
  }

  /**
   * Solo el panel recibe la clase del marco y la hoja de estilos.
   */
  #[DataProvider('pantallas')]
  public function testSoloElPanelLlevaElEstilo(string $ruta, bool $lleva): void {
    $ganchos = new DiagnosticThemeHooks(
      new RouteMatch($ruta, new Route('/prueba')),
      $this->container->get(HomePage::class),
      $this->container->get(AgentRegistry::class),
      $this->container->get(ManagerNavigation::class),
    );

    $variables = [];
    $ganchos->preprocessPage($variables);

    $librerias = $variables['#attached']['library'] ?? [];
    $this->assertSame($lleva, in_array('sales_leadership_diagnostic/agentes', $librerias, TRUE), 'La hoja de estilos.');
    $this->assertSame($lleva ? 'agentes' : NULL, $variables['sld_tema'] ?? NULL, 'La clase del marco.');
  }

  /**
   * Cada agente guarda su color, y uno fuera de la paleta cae en cian.
   *
   * Hasta el 13-09-2026 el color iba por posición en el panel: con otro orden,
   * la tarjeta y la página del mismo agente habrían dicho colores distintos.
   */
  public function testCadaAgenteTieneSuColor(): void {
    $almacen = $this->container->get('entity_type.manager')->getStorage('sld_agent');

    $lima = $almacen->create(['id' => 'con_lima', 'label' => 'Lima', 'accent' => 'lima']);
    $lima->save();
    $this->assertSame('lima', $almacen->load('con_lima')->getAccent(), 'Se guarda y se exporta.');

    $this->assertSame('cian', $almacen->create(['id' => 'sin_color', 'label' => 'Sin'])->getAccent(), 'Sin elegir, cian.');
    $this->assertSame('cian', $almacen->create(['id' => 'raro', 'label' => 'Raro', 'accent' => 'fucsia'])->getAccent(), 'Fuera de la paleta, cian.');
  }

}
