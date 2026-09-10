<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Controller\ChatController;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Hook\StuckTurnHooks;
use Drupal\sales_leadership_diagnostic\Plugin\QueueWorker\DiagnosticTurnWorker;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineFactory;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba los turnos que se generan fuera de la petición web.
 *
 * Existen por un número medido: un turno con dos búsquedas sobre una sola
 * cuenta tardó **76 segundos**, y una misión que criba diez cuentas son
 * decenas de búsquedas y veinte minutos. Eso no cabe en una petición web y no
 * se arregla subiendo un timeout, porque nadie mira una pantalla en blanco
 * veinte minutos.
 *
 * Lo que estas pruebas cuidan son las dos formas de romperlo:
 *
 * - **Que se ejecute dos veces.** Costaría dos llamadas al proveedor y dejaría
 *   dos respuestas en la conversación. Su §14 lo pide por su nombre:
 *   idempotencia, «no consumir otra misión automáticamente».
 * - **Que se quede atascado.** Una sesión en «procesando» no admite mensajes.
 *   Si el proceso muere a mitad, la conversación queda inutilizable para
 *   siempre y la persona no puede ni reintentar ni entender por qué.
 */
#[CoversClass(ConversationService::class)]
#[CoversClass(DiagnosticTurnWorker::class)]
final class BackgroundTurnTest extends KernelTestBase {

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
      'sld_ai_usage',
      'sld_tool_call',
      'sld_research_entitlement',
      'sld_evidence',
    ]);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();
    $this->alumno = User::create(['name' => 'alumna', 'status' => 1]);
    $this->alumno->save();

    $this->setSetting(DiagnosticEngineFactory::MOCK_SETTING, TRUE);
  }

  /**
   * Sin capacidad de investigar, el turno se genera en el acto.
   *
   * Un turno normal responde en segundos. Mandarlo a la cola le añadiría la
   * espera del siguiente cron por nada.
   */
  public function testSinInvestigarElTurnoSeGeneraEnElActo(): void {
    $sesion = $this->crearSesion();

    $salida = $this->conversacion()->submitMessage($sesion, 'Hola.');

    $this->assertFalse($salida['processing']);
    $this->assertNotSame('', $salida['message_html']);
    $this->assertSame(0, $this->cola()->numberOfItems());
  }

  /**
   * Con capacidad de investigar, se encola y la sesión pasa a «procesando».
   */
  public function testConCapacidadDeInvestigarSeEncola(): void {
    $this->habilitarBusqueda();
    $sesion = $this->crearSesion();

    $salida = $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');

    $this->assertTrue($salida['processing']);
    $this->assertSame('', $salida['message_html'], 'Todavía no hay respuesta que pintar.');
    $this->assertSame(1, $this->cola()->numberOfItems());
    $this->assertSame(DiagnosticStatus::Processing, $this->recargar($sesion)->getStatus());
  }

  /**
   * Cada conversación se titula con SU agente.
   *
   * El título estaba escrito a mano en tres sitios —la ruta, la plantilla de
   * página y la barra— de cuando había un solo agente. Con dos, quien hablaba
   * con el de prospección leía arriba y en la pestaña el nombre del otro. Lo
   * vio el usuario el 10-09-2026.
   *
   * Es el mismo arreglo que ya tenía la página de resultado; al chat se le
   * pasó, y por eso esta prueba existe: para que la próxima vez que se añada
   * un agente no haya que acordarse.
   */
  public function testCadaConversacionSeTitulaConSuAgente(): void {
    $this->container->get('entity_type.manager')->getStorage('sld_agent')->create([
      'id' => 'prospecting_diagnostic',
      'label' => 'GAP Prospecting AI',
      'course_id' => '35884',
      'system_prompt' => 'PROMPT',
    ])->save();

    $chat = ChatController::create($this->container);

    $this->assertSame('GAP Prospecting AI', $chat->title($this->crearSesion()));
  }

  /**
   * Una conversación cuyo agente ya no existe se sigue pudiendo abrir.
   *
   * Va junto a la anterior porque el título es lo primero que se resuelve al
   * pintar la página: si reventara ahí, la conversación entera dejaría de
   * abrirse por un agente borrado.
   */
  public function testUnaConversacionSinAgenteSigueTeniendoTitulo(): void {
    $chat = ChatController::create($this->container);

    $this->assertNotSame('', $chat->title($this->crearSesion()));
  }

  /**
   * La pantalla que CARGA con un turno corriendo sabe que tiene que sondear.
   *
   * Es la mitad que faltaba de la ejecución en segundo plano, y falló en manos
   * del cliente el 10-09-2026. El sondeo solo arrancaba al enviar un mensaje;
   * quien recargaba, cerraba la pestaña y volvía, o entraba desde su panel, se
   * quedaba con un aviso fijo que no volvía a cambiar nunca. El trabajo
   * terminaba y nadie se enteraba.
   *
   * Se comprueba el ajuste que enciende el sondeo. Es el único hilo entre el
   * servidor, que sabe que hay un turno corriendo, y el navegador, que es
   * quien tiene que preguntar: si alguien lo quita, la pantalla se vuelve a
   * quedar muda y no lo nota ninguna otra prueba.
   */
  public function testLaPantallaConTurnoCorriendoPideSondear(): void {
    $sesion = $this->crearSesion();
    $chat = ChatController::create($this->container);

    $ajustes = static fn (array $construido): array => $construido['#attached']['drupalSettings']['salesLeadershipDiagnostic'];

    $this->assertFalse(
      $ajustes($chat->view($sesion))['processing'],
      'Una conversación normal no debe ponerse a sondear.',
    );

    $sesion->setStatus(DiagnosticStatus::Processing)->save();

    $construido = $chat->view($this->recargar($sesion));

    $this->assertTrue($ajustes($construido)['processing']);
    $this->assertNotEmpty(
      $ajustes($construido)['statusEndpoint'],
      'Y con a dónde preguntar: sin endpoint no hay sondeo posible.',
    );
  }

  /**
   * Un agente sin búsqueda concedida NO se va a la cola.
   *
   * Es la prueba de la puerta del agente, y lo que comprueba de verdad no es
   * la cola: es que ese turno no le gasta a la alumna su misión de la semana.
   * La misión es una por persona y se comparte entre todos sus agentes, así
   * que sin esta puerta el agente que no investiga deja sin investigar al que
   * sí, y no falla de forma visible: días después, el otro agente simplemente
   * dice que ya no puede.
   *
   * El interruptor general está encendido aquí. Es el punto: encenderlo NO
   * reparte la capacidad por su cuenta.
   */
  public function testUnAgenteSinBusquedaConcedidaNoSeEncola(): void {
    $this->habilitarBusqueda(agenteBusca: FALSE);
    $sesion = $this->crearSesion();

    $salida = $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');

    $this->assertFalse($salida['processing'], 'Sin búsqueda concedida el turno se genera en el acto.');
    $this->assertSame(0, $this->cola()->numberOfItems());
    $this->assertSame(DiagnosticStatus::InProgress, $this->recargar($sesion)->getStatus());
  }

  /**
   * Mientras procesa, no admite mensajes nuevos.
   *
   * El estado hace de cerrojo por sí solo mientras el trabajo ocurre fuera de
   * la petición.
   */
  public function testMientrasProcesaNoAdmiteMensajes(): void {
    $this->habilitarBusqueda();
    $sesion = $this->crearSesion();
    $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');

    $this->expectExceptionMessage('no admite mensajes');

    $this->conversacion()->submitMessage($this->recargar($sesion), 'Otra cosa.');
  }

  /**
   * El trabajador lo genera y la conversación sigue.
   */
  public function testElTrabajadorGeneraElTurno(): void {
    $this->habilitarBusqueda();
    $sesion = $this->crearSesion();
    $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');

    $this->procesarLaCola();

    $sesion = $this->recargar($sesion);

    $this->assertSame(DiagnosticStatus::InProgress, $sesion->getStatus());
    $this->assertCount(2, $this->conversacion()->getConversation((int) $sesion->id()));
  }

  /**
   * Procesarlo dos veces NO genera dos turnos.
   *
   * Es la idempotencia que pide su §14. La cola puede reintentar un elemento
   * —porque el proceso murió después de trabajar pero antes de borrarlo—, y
   * repetir el turno costaría otra llamada al proveedor y dejaría dos
   * respuestas seguidas del agente.
   */
  public function testProcesarloDosVecesNoGeneraDosTurnos(): void {
    $this->habilitarBusqueda();
    $sesion = $this->crearSesion();
    $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');
    $id = (int) $sesion->id();

    $this->conversacion()->processQueuedTurn($id);
    $this->conversacion()->processQueuedTurn($id);

    $this->assertCount(2, $this->conversacion()->getConversation($id), 'Un mensaje del alumno y UNA respuesta.');
  }

  /**
   * Una conversación atascada se recupera sola.
   *
   * Sin esto, un proceso que muere a mitad deja la conversación inutilizable
   * para siempre: el estado no admite mensajes y nadie puede reintentar.
   */
  public function testUnaConversacionAtascadaSeRecuperaSola(): void {
    $this->habilitarBusqueda();
    $sesion = $this->crearSesion();
    $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');

    $this->envejecer($sesion, 60 * 60);
    $this->desatascar();

    $this->assertSame(DiagnosticStatus::InProgress, $this->recargar($sesion)->getStatus());
  }

  /**
   * Pero una que acaba de empezar NO se toca.
   *
   * Desatascar un turno que sigue vivo sería peor que el problema: la persona
   * escribiría encima de un trabajo en marcha.
   */
  public function testUnaRecienEmpezadaNoSeToca(): void {
    $this->habilitarBusqueda();
    $sesion = $this->crearSesion();
    $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');

    $this->desatascar();

    $this->assertSame(DiagnosticStatus::Processing, $this->recargar($sesion)->getStatus());
  }

  /**
   * El cron completo hace las dos cosas: procesa la cola y desatasca.
   *
   * Se comprueba junto porque en el servidor van juntas. La primera vez costó
   * una prueba mal escrita: se esperaba que el cron dejara el turno en
   * «procesando» y lo que hizo fue terminarlo, que es lo correcto.
   */
  public function testElCronProcesaLaColaAdemasDeDesatascar(): void {
    $this->habilitarBusqueda();
    $sesion = $this->crearSesion();
    $this->conversacion()->submitMessage($sesion, 'Investiga Cemex.');

    $this->container->get('cron')->run();

    $this->assertSame(DiagnosticStatus::InProgress, $this->recargar($sesion)->getStatus());
    $this->assertCount(2, $this->conversacion()->getConversation((int) $sesion->id()));
  }

  /**
   * Corre solo la recuperación de turnos atascados.
   *
   * Se llama al gancho directamente y no al cron entero porque el cron hace
   * dos cosas —procesar la cola y desatascar— y aquí se está comprobando la
   * segunda.
   */
  private function desatascar(): void {
    $this->container->get(StuckTurnHooks::class)->onCron();
  }

  /**
   * Enciende la búsqueda y da capacidad de investigar.
   */
  private function habilitarBusqueda(bool $agenteBusca = TRUE): void {
    $this->setSetting('sld_search_api_key', 'clave-de-prueba');
    $this->config('sales_leadership_diagnostic.settings')->set('search.enabled', TRUE)->save();
    $this->container->get(ResearchEntitlementService::class)->forUser((int) $this->alumno->id());

    // El interruptor general no basta: la búsqueda se concede POR AGENTE, y
    // sin agente concedido no hay investigación ni, por tanto, cola.
    $this->container->get('entity_type.manager')->getStorage('sld_agent')->create([
      'id' => 'prospecting_diagnostic',
      'label' => 'Prospección',
      'course_id' => '35884',
      'system_prompt' => 'PROMPT',
      'can_search' => $agenteBusca,
    ])->save();
  }

  /**
   * Crea una conversación lista para recibir mensajes.
   */
  private function crearSesion() {
    $sesion = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->create([
        'uid' => $this->alumno->id(),
        'wp_user_id' => '4821',
        'course_id' => '35884',
        'agent' => 'prospecting_diagnostic',
        'diagnostic_version' => '1.2',
        'prompt_snapshot' => 'PROMPT',
        'started_at' => $this->container->get('datetime.time')->getRequestTime(),
      ]);
    $sesion->setStatus(DiagnosticStatus::InProgress);
    $sesion->save();

    return $sesion;
  }

  /**
   * Hace como si la conversación llevara un rato sin moverse.
   */
  private function envejecer($sesion, int $segundos): void {
    $this->container->get('database')->update('sld_diagnostic_session')
      ->fields(['changed' => $this->container->get('datetime.time')->getRequestTime() - $segundos])
      ->condition('id', $sesion->id())
      ->execute();

    $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->resetCache([(int) $sesion->id()]);
  }

  /**
   * Vacía la cola ejecutando lo que haya.
   */
  private function procesarLaCola(): void {
    $cola = $this->cola();
    $trabajador = $this->container->get('plugin.manager.queue_worker')
      ->createInstance(DiagnosticTurnWorker::QUEUE);

    while ($item = $cola->claimItem(60)) {
      $trabajador->processItem($item->data);
      $cola->deleteItem($item);
    }
  }

  /**
   * Vuelve a leer la conversación desde la base.
   */
  private function recargar($sesion) {
    $almacen = $this->container->get('entity_type.manager')->getStorage('sld_diagnostic_session');
    $almacen->resetCache([(int) $sesion->id()]);

    return $almacen->load($sesion->id());
  }

  /**
   * La cola de turnos.
   */
  private function cola() {
    return $this->container->get('queue')->get(DiagnosticTurnWorker::QUEUE);
  }

  /**
   * Quien conduce la conversación.
   */
  private function conversacion(): ConversationService {
    return $this->container->get(ConversationService::class);
  }

}
