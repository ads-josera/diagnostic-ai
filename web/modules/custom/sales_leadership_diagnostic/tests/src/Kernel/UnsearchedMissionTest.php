<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\MissionState;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineInterface;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolGateway;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\sales_leadership_diagnostic\Service\Research\UnsearchedMission;
use Drupal\sales_leadership_diagnostic\Service\Search\TavilySearchProvider;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\ProviderAccountStatus;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Una misión que no pudo buscar por el corte del buscador no le cuenta.
 *
 * Es lo que pasó el 05-10-2026: se agotó el plan del buscador, el agente cerró
 * un Pack sin investigar y el alumno se quedó sin su misión de la semana. Aquí
 * se simula el corte sin salir a la red.
 */
#[CoversClass(UnsearchedMission::class)]
#[CoversClass(ConversationService::class)]
#[CoversClass(ResearchEntitlementService::class)]
final class UnsearchedMissionTest extends KernelTestBase {

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
   * Alumno dueño de la conversación.
   */
  private User $alumno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installSchema('sales_leadership_diagnostic', [
      'sld_diagnostic_message',
      'sld_research_entitlement',
      'sld_evidence',
      'sld_tool_call',
    ]);
    $this->installConfig(['sales_leadership_diagnostic']);

    // El uid 1 es superusuario y no debe usarse como sujeto de prueba.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->alumno = User::create(['name' => 'sld_wp_4821', 'status' => 1]);
    $this->alumno->save();

    // Un motor que entrega el Pack en el primer turno: lo que se prueba es lo
    // que pasa con la misión al cerrarlo.
    $this->container->set('sales_leadership_diagnostic.engine', new class() implements DiagnosticEngineInterface {

      /**
       * {@inheritdoc}
       */
      public function process(DiagnosticContext $context): DiagnosticTurn {
        return new DiagnosticTurn(
          message: 'Weekly GOLD Pack',
          completed: TRUE,
          result: ['summary' => 'Pack', 'accounts' => []],
          raw: [],
        );
      }

    });
  }

  /**
   * Buscador cortado y nada encontrado: la misión vuelve y el Pack lo dice.
   */
  public function testConElBuscadorCortadoSinResultadosLaMisionVuelve(): void {
    $this->cortarBuscador();
    $session = $this->abrirMision();
    $this->buscar($session, 0);

    $guardado = $this->entregar($session);

    $this->assertSame(MissionState::Available, $this->estado());
    $this->assertStringStartsWith(UnsearchedMission::NOTA, $guardado);
    $this->assertStringContainsString('Weekly GOLD Pack', $guardado, 'El Pack se conserva.');

    // Y puede abrir otra: es lo que significa que no le cuenta.
    $otra = $this->crearSesion();
    $this->assertTrue($this->entitlements()->startMission((int) $this->alumno->id(), (int) $otra->id()));
  }

  /**
   * El caso del 05-10-2026 por el camino real: el buscador responde 432.
   *
   * Sin filas preparadas a mano: la búsqueda pasa por el gateway, que abre la
   * misión; el buscador contesta que el plan se agotó y se marca sin servicio;
   * el agente cierra el Pack igualmente. Si alguna pieza dejara de anotar lo
   * que esta regla lee, aquí se vería.
   */
  public function testConUn432RealLaMisionVuelve(): void {
    $gateway = new ToolGateway(
      new ToolBox([
        new WebSearchTool($this->buscadorSinPlan(), $this->container->get('logger.factory')->get('sld')),
      ]),
      $this->container->get(CurrentTurn::class),
      $this->container->get(ToolCallRepository::class),
      $this->container->get(SpendGuard::class),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->entitlements(),
    );

    $this->container->set('sales_leadership_diagnostic.engine', new class($gateway) implements DiagnosticEngineInterface {

      public function __construct(private readonly ToolGateway $gateway) {}

      /**
       * {@inheritdoc}
       */
      public function process(DiagnosticContext $context): DiagnosticTurn {
        $this->gateway->run(WebSearchTool::NAME, ['consulta' => 'Banco Guayaquil CEO']);

        return new DiagnosticTurn(message: 'Weekly GOLD Pack', completed: TRUE, result: ['accounts' => []], raw: []);
      }

    });

    $session = $this->crearSesion();
    $guardado = $this->entregar($session);

    $this->assertTrue($this->container->get(ProviderAccountStatus::class)->isUnavailable(ProviderAccountStatus::BUSCADOR));
    $this->assertSame(MissionState::Available, $this->estado());
    $this->assertStringStartsWith(UnsearchedMission::NOTA, $guardado);
  }

  /**
   * El aviso no nombra al proveedor ni habla de saldo: no es asunto del alumno.
   */
  public function testElAvisoNoHablaDeProveedoresNiDeSaldo(): void {
    foreach (['tavily', 'openai', 'saldo', 'crédito', 'plan'] as $palabra) {
      $this->assertStringNotContainsStringIgnoringCase($palabra, UnsearchedMission::NOTA);
    }
  }

  /**
   * Si alguna búsqueda trajo algo antes del corte, la misión cuenta.
   */
  public function testSiAlgunaBusquedaTrajoAlgoLaMisionCuenta(): void {
    $this->cortarBuscador();
    $session = $this->abrirMision();
    $this->buscar($session, 5);
    $this->buscar($session, 0);

    $guardado = $this->entregar($session);

    $this->assertSame(MissionState::Completed, $this->estado());
    $this->assertStringNotContainsString(UnsearchedMission::NOTA, $guardado);
  }

  /**
   * Sin resultados pero con el buscador en marcha, la misión cuenta.
   *
   * Si no, bastaría con no buscar nada para repetir misiones a voluntad.
   */
  public function testSinCorteLaMisionCuentaAunqueNoEncontraraNada(): void {
    $session = $this->abrirMision();
    $this->buscar($session, 0);

    $guardado = $this->entregar($session);

    $this->assertSame(MissionState::Completed, $this->estado());
    $this->assertStringNotContainsString(UnsearchedMission::NOTA, $guardado);
  }

  /**
   * Un ensayo del gestor no toca la misión de nadie, ni lleva el aviso.
   */
  public function testUnEnsayoNoLlevaElAviso(): void {
    $this->cortarBuscador();
    $session = $this->crearSesion(TRUE);

    $guardado = $this->entregar($session);

    $this->assertStringNotContainsString(UnsearchedMission::NOTA, $guardado);
  }

  /**
   * Solo la conversación que abrió la misión puede devolverla.
   */
  public function testOtraConversacionNoDevuelveLaMision(): void {
    $session = $this->abrirMision();
    $otra = $this->crearSesion();

    $this->assertFalse($this->entitlements()->releaseMission((int) $this->alumno->id(), (int) $otra->id()));
    $this->assertSame(MissionState::Active, $this->estado());

    $this->assertTrue($this->entitlements()->releaseMission((int) $this->alumno->id(), (int) $session->id()));
    $this->assertSame(MissionState::Available, $this->estado());
  }

  /**
   * Un buscador que responde lo que respondió aquel día: plan agotado.
   */
  private function buscadorSinPlan(): TavilySearchProvider {
    return new TavilySearchProvider(
      new Client(['handler' => HandlerStack::create(new MockHandler([new Response(432, [], '{}')]))]),
      new SecretsProvider(new Settings([SecretsProvider::SEARCH_API_KEY => 'tvly-de-prueba'])),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->container->get(ProviderAccountStatus::class),
    );
  }

  /**
   * Marca el buscador como sin servicio, como lo hace su cliente con un 432.
   */
  private function cortarBuscador(): void {
    $this->container->get(ProviderAccountStatus::class)
      ->markUnavailable(ProviderAccountStatus::BUSCADOR, 'plan agotado');
  }

  /**
   * Una conversación con la misión de la semana abierta.
   */
  private function abrirMision(): DiagnosticSessionInterface {
    $session = $this->crearSesion();
    $this->assertTrue($this->entitlements()->startMission((int) $this->alumno->id(), (int) $session->id()));

    return $session;
  }

  /**
   * Anota una búsqueda concedida con tantos resultados.
   */
  private function buscar(DiagnosticSessionInterface $session, int $resultados): void {
    $this->container->get(ToolCallRepository::class)->record(
      uid: (int) $this->alumno->id(),
      sessionId: (int) $session->id(),
      tool: WebSearchTool::NAME,
      query: 'x',
      allowed: TRUE,
      results: $resultados,
      resultUrls: $resultados > 0 ? ['https://a.ec/x'] : [],
    );
  }

  /**
   * Cierra la conversación con el Pack y devuelve lo que quedó guardado.
   */
  private function entregar(DiagnosticSessionInterface $session): string {
    $servicio = $this->container->get(ConversationService::class);
    $servicio->submitMessage($session, 'Haz el trabajo por mí');

    $conversacion = $servicio->getConversation((int) $session->id());

    return (string) end($conversacion)->content;
  }

  /**
   * En qué está la misión de la semana del alumno.
   */
  private function estado(): MissionState {
    return $this->entitlements()->forUser((int) $this->alumno->id())->state;
  }

  /**
   * El servicio de misiones.
   */
  private function entitlements(): ResearchEntitlementService {
    return $this->container->get(ResearchEntitlementService::class);
  }

  /**
   * Una conversación en curso.
   */
  private function crearSesion(bool $ensayo = FALSE): DiagnosticSessionInterface {
    $session = DiagnosticSession::create([
      'uid' => $this->alumno->id(),
      'wp_user_id' => '4821',
      'course_id' => '35884',
      'agent' => 'prospecting_diagnostic',
      'diagnostic_version' => '1.0-TEST',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
      'is_sandbox' => $ensayo,
    ]);
    $session->setStatus(DiagnosticStatus::InProgress);
    $session->save();

    return $session;
  }

}
