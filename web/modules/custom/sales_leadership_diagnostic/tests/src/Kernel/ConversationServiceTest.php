<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Queue\QueueInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineFactory;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba lo que queda escrito cuando una conversación llega al final.
 *
 * El resultado sobrevive a su sesión: el historial del alumno se lee del
 * resultado, y las sesiones son las que acaban purgándose. Todo lo que haga
 * falta para interpretarlo más tarde —la versión y el agente— tiene que
 * copiarse en el momento de crearlo, porque después ya no habrá de dónde
 * sacarlo.
 *
 * Se conversa con el motor simulado, que recorre el mismo circuito real
 * —bloqueo, persistencia, validación y cierre— sin salir a la red (§48).
 */
#[CoversClass(ConversationService::class)]
final class ConversationServiceTest extends KernelTestBase {

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
   *
   * @var \Drupal\user\Entity\User
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
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installConfig(['sales_leadership_diagnostic']);

    // El uid 1 es superusuario y no debe usarse como sujeto de prueba.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->alumno = User::create(['name' => 'sld_wp_4821', 'status' => 1]);
    $this->alumno->save();

    // Sin este ajuste el módulo se niega a inventar diagnósticos, que es
    // justamente lo que protege a producción de entregar datos falsos.
    $this->setSetting(DiagnosticEngineFactory::MOCK_SETTING, TRUE);
  }

  /**
   * El resultado nace sabiendo de qué agente salió.
   *
   * Con un solo agente esto no se notaba: todo el historial era del mismo. En
   * cuanto hay varios, un resultado sin agente es un resultado que ya no se
   * puede atribuir, y la sesión que lo diría puede haberse purgado.
   */
  public function testElResultadoConservaElAgenteDeLaSesion(): void {
    $session = $this->crearSesion('agente_gap');

    $resultado = $this->conversarHastaElFinal($session);

    $this->assertSame('agente_gap', $resultado->getAgentId());
  }

  /**
   * El resultado conserva también la versión con la que se conversó.
   *
   * Se comprueba junto al agente porque los dos se copian por el mismo motivo
   * y por el mismo camino: si alguien vuelve a romper esa copia, conviene que
   * salte aquí y no meses después al mirar un historial ilegible.
   */
  public function testElResultadoConservaLaVersionDeLaSesion(): void {
    $session = $this->crearSesion('agente_gap');

    $resultado = $this->conversarHastaElFinal($session);

    $this->assertSame('1.0-TEST', $resultado->getDiagnosticVersion());
    $this->assertSame((int) $session->id(), $resultado->getSessionId());
    $this->assertSame((int) $this->alumno->id(), (int) $resultado->getOwnerId());
  }

  /**
   * Terminar deja encargada la extracción de la memoria.
   *
   * Se comprueba que se ENCOLA y no que se extraiga: extraer en el mismo turno
   * añadiría al alumno una segunda espera ante el modelo justo cuando acaba de
   * esperar su informe, y un fallo del proveedor en ese momento se le
   * presentaría como si su diagnóstico hubiera fallado.
   */
  public function testAlTerminarSeEncolaLaExtraccionDeLaMemoria(): void {
    $session = $this->crearSesion('agente_gap');

    $this->assertSame(0, $this->cola()->numberOfItems(), 'La cola debe empezar vacía.');

    $this->conversarHastaElFinal($session);

    $this->assertSame(1, $this->cola()->numberOfItems());
    $this->assertSame(
      ['session_id' => (int) $session->id()],
      $this->cola()->claimItem()->data,
    );
  }

  /**
   * Un ensayo del gestor no encola nada.
   *
   * Su contenido es una simulación, y escribirlo en la memoria de la cuenta
   * que ensaya mezclaría el negocio inventado con el de esa persona.
   */
  public function testUnEnsayoNoEncolaExtraccion(): void {
    $session = $this->crearSesion('agente_gap', ensayo: TRUE);

    $this->conversarHastaElFinal($session);

    $this->assertSame(0, $this->cola()->numberOfItems());
  }

  /**
   * La cola de extracción.
   */
  private function cola(): QueueInterface {
    return $this->container->get('queue')->get('sld_memory_extraction');
  }

  /**
   * Conversa hasta que el agente da el diagnóstico por terminado.
   *
   * El guion del motor simulado se agota tras unos turnos; se acota el bucle
   * para que un cambio en ese guion no deje el test dando vueltas.
   */
  private function conversarHastaElFinal(DiagnosticSessionInterface $session): DiagnosticResultInterface {
    $servicio = $this->container->get(ConversationService::class);
    $resultId = NULL;

    for ($turno = 0; $turno < 10 && $resultId === NULL; $turno++) {
      $respuesta = $servicio->submitMessage($session, 'Mensaje ' . $turno);
      $resultId = $respuesta['result_id'];
    }

    $this->assertNotNull($resultId, 'La conversación debería haber terminado en un resultado.');

    $resultado = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_result')
      ->load($resultId);

    $this->assertInstanceOf(DiagnosticResultInterface::class, $resultado);

    return $resultado;
  }

  /**
   * Crea una sesión en curso del agente indicado.
   */
  private function crearSesion(string $agentId, bool $ensayo = FALSE): DiagnosticSessionInterface {
    $session = DiagnosticSession::create([
      'uid' => $this->alumno->id(),
      'wp_user_id' => '4821',
      'course_id' => '35884',
      'agent' => $agentId,
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
