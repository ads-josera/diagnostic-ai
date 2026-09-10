<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\sales_leadership_diagnostic\Service\Manager\ManagerNavigation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\Route;

/**
 * La navegación del gestor no depende de dónde esté.
 *
 * Antes sí dependía, y era el fallo: sus secciones eran las **pestañas
 * locales** de Drupal, que solo se pintan en las rutas que cuelgan de la ruta
 * base del árbol de tareas. Las tenían las cinco pantallas de aterrizaje, y
 * ninguna de las de dentro: entrar a editar un agente era un callejón.
 *
 * Lo reportó el usuario el 10-09-2026, y era la segunda vez que se quedaba sin
 * salida: la primera fue el 04-09-2026, sin barra ni cerrar sesión.
 */
#[CoversClass(ManagerNavigation::class)]
final class ManagerNavigationTest extends KernelTestBase {

  use UserCreationTrait;

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

    $this->installEntitySchema('user');
    $this->installConfig(['sales_leadership_diagnostic']);

    // Quien administra ve todas las secciones. Se comprueba con permisos y no
    // como uid 1: el superusuario pasa cualquier control y no probaría nada.
    $this->setUpCurrentUser([], [
      'administer sales leadership diagnostic',
      'edit diagnostic prompt',
      'view all diagnostic results',
    ]);
  }

  /**
   * Las pantallas de DENTRO también llevan la navegación entera.
   *
   * Es lo que fallaba. Cada una de estas es una pantalla a la que el gestor
   * entra desde su listado y de la que antes no podía salir a ninguna otra
   * sección.
   *
   * @param string $ruta
   *   Ruta en la que se comprueba.
   */
  #[DataProvider('pantallasDeDentro')]
  public function testLasPantallasDeDentroLlevanLaNavegacion(string $ruta): void {
    $secciones = $this->navegacionEn($ruta)->secciones();

    $this->assertCount(5, $secciones, sprintf('En %s deberían verse las cinco secciones.', $ruta));

    $titulos = array_column($secciones, 'titulo');
    $this->assertSame(
      ['Resultados', 'Agentes', 'Estudio', 'Documentos', 'Consumo'],
      $titulos,
      'Y en el orden del trabajo, que no es el alfabético.',
    );
  }

  /**
   * Pantallas internas del gestor.
   *
   * @return array<string, array{string}>
   *   Una por entrada.
   */
  public static function pantallasDeDentro(): array {
    return [
      'editar un agente' => ['entity.sld_agent.edit_form'],
      'añadir un agente' => ['entity.sld_agent.add_form'],
      'borrar un agente' => ['entity.sld_agent.delete_form'],
      'estudio de un agente' => ['sales_leadership_diagnostic.studio_agent'],
      'documentos de un agente' => ['sales_leadership_diagnostic.knowledge_agent'],
      'resultado de un alumno' => ['sales_leadership_diagnostic.result'],
    ];
  }

  /**
   * La sección que contiene la pantalla queda marcada.
   *
   * Una navegación que no dice dónde estás es media navegación: se ve a dónde
   * ir y no de dónde se viene.
   */
  public function testLaSeccionQueContieneLaPantallaQuedaMarcada(): void {
    $activas = static fn (array $s): array => array_column(
      array_filter($s, static fn (array $x): bool => $x['activa']),
      'titulo',
    );

    $this->assertSame(
      ['Agentes'],
      $activas($this->navegacionEn('entity.sld_agent.edit_form')->secciones()),
      'Editando un agente sigue marcada Agentes.',
    );

    $this->assertSame(
      ['Estudio'],
      $activas($this->navegacionEn('sales_leadership_diagnostic.studio_agent')->secciones()),
    );

    $this->assertSame(
      ['Consumo'],
      $activas($this->navegacionEn('sales_leadership_diagnostic.usage')->secciones()),
    );
  }

  /**
   * Solo se enseña lo que esa persona puede abrir.
   *
   * Una sección que se enseña y da 403 al pulsarla es peor que no enseñarla:
   * hace dudar de si el sistema está roto o de si uno no tiene permiso.
   */
  public function testNoSeEnsenaLoQueNoSePuedeAbrir(): void {
    // Un alumno no administra nada: no debe ver ninguna sección del gestor.
    $this->setUpCurrentUser([], ['access sales leadership diagnostic']);

    $this->assertSame([], $this->navegacionEn('sales_leadership_diagnostic.usage')->secciones());
  }

  /**
   * La navegación tal como la vería alguien parado en esa ruta.
   */
  private function navegacionEn(string $ruta): ManagerNavigation {
    $coincidencia = $this->createMock(RouteMatchInterface::class);
    $coincidencia->method('getRouteName')->willReturn($ruta);
    $coincidencia->method('getRouteObject')->willReturn(new Route('/'));

    return new ManagerNavigation($coincidencia);
  }

}
