<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Queue\QueueInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Controller\ConversationApiController;
use Drupal\sales_leadership_diagnostic\Exception\ProviderAccountException;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\ProviderAccountStatus;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineInterface;
use Drupal\sales_leadership_diagnostic\Service\Research\CitationAudit;
use Drupal\sales_leadership_diagnostic\Service\Research\CitationAuditInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Exception\DiagnosticException;
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
    $this->installSchema('sales_leadership_diagnostic', [
      'sld_diagnostic_message',
      'sld_research_entitlement',
      'sld_evidence',
      // Al cerrar una misión se revisan las citas del entregable contra las
      // URL que trajeron las búsquedas, y eso se lee de aquí. En producción la
      // tabla siempre está; faltaba solo en este montaje.
      'sld_tool_call',
    ]);
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
   * El resultado de un ensayo nace marcado como ensayo.
   *
   * El listado que el gestor usa para dar soporte filtra por esta marca. Sin
   * heredarla de la sesión, los ensayos aparecen ahí mezclados con los
   * diagnósticos de alumnos reales, que es justamente lo que la separación
   * existe para impedir. Y no falla de forma visible: solo aparecen filas de
   * más que parecen legítimas.
   */
  public function testElResultadoDeUnEnsayoQuedaMarcadoComoEnsayo(): void {
    $ensayo = $this->conversarHastaElFinal($this->crearSesion('agente_gap', ensayo: TRUE));
    $real = $this->conversarHastaElFinal($this->crearSesion('agente_gap'));

    $this->assertTrue((bool) $ensayo->get('is_sandbox')->value);
    $this->assertFalse((bool) $real->get('is_sandbox')->value);
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
   * Un turno que falla TAMBIÉN consume cupo.
   *
   * Lo que cuesta dinero es el intento, no el acierto: la llamada al proveedor
   * se paga entera aunque la respuesta llegue mal, y las que fallan por
   * presupuesto de tokens son de las más caras, porque el modelo llegó a
   * generar.
   *
   * Hasta el 26-08-2026 el consumo se registraba solo al terminar con éxito, y
   * eso dejaba abierto justo lo que este límite existe para cerrar: un alumno
   * atascado en un fallo podía reintentar sin tope, pagando cada vez.
   */
  public function testUnTurnoQueFallaTambienConsumeCupo(): void {
    $session = $this->crearSesion('agente_gap');
    $uid = (int) $this->alumno->id();
    $flood = $this->container->get('flood');
    $evento = 'sales_leadership_diagnostic.message';

    $this->assertTrue($flood->isAllowed($evento, 1, 300, (string) $uid), 'Debe empezar sin consumo.');

    // Se rompe el motor para que el turno falle DESPUÉS de haber llamado.
    $this->setSetting(DiagnosticEngineFactory::MOCK_SETTING, FALSE);
    $this->setSetting('sld_openai_api_key', '');

    try {
      $this->container->get(ConversationService::class)->submitMessage($session, 'Hola');
      $this->fail('El turno debería haber fallado.');
    }
    catch (DiagnosticException) {
      // Es lo esperado: sin credenciales el motor se niega de forma visible.
    }

    $this->assertFalse(
      $flood->isAllowed($evento, 1, 300, (string) $uid),
      'El intento fallido debe haber quedado contado.',
    );
  }

  /**
   * Si la revisión de citas revienta, el alumno recibe su entregable igual.
   *
   * La revisión corre al crear el resultado, DESPUÉS de guardarlo pero ANTES de
   * marcar la sesión como completada. Una excepción ahí —un fallo de base de
   * datos, por ejemplo— dejaba al alumno con entregable y la sesión a medias, y
   * el turno ya se había pagado.
   *
   * La primera versión de esta prueba tiraba la tabla que la revisión consulta,
   * y pasaba IGUAL sin el try/catch: la revisión solo toca la base si el
   * entregable cita algo, y el motor simulado no cita nada. Se retiró y se
   * extrajo `CitationAuditInterface` para poder hacer esto, que es lo único que
   * prueba el guardia: una revisión que revienta siempre, pase lo que pase.
   */
  public function testSiLaRevisionDeCitasRevientaElEntregableLlegaIgual(): void {
    $this->container->set(CitationAudit::class, new class() implements CitationAuditInterface {

      /**
       * {@inheritdoc}
       */
      public function review(int $uid, int $sessionId, array $payload, string $message = ''): array {
        throw new \RuntimeException('La revisión de citas falló.');
      }

    });

    $session = $this->crearSesion('agente_gap');

    $resultado = $this->conversarHastaElFinal($session);

    $this->assertNotNull($resultado->id(), 'El resultado tiene que haberse guardado.');
    $this->assertSame(
      'completed',
      $this->container->get('entity_type.manager')
        ->getStorage('sld_diagnostic_session')
        ->load($session->id())
        ->get('status')->value,
      'La sesión tiene que quedar cerrada, no a medias.',
    );
  }

  /**
   * Al entregar el Pack, sus fuentes y su correo quedan formateados al guardar.
   *
   * La plataforma las añade a partir de `accounts[].sources`, en lugar de
   * pedirle al agente un formato que unas veces sigue y otras no. Se
   * comprueba en lo GUARDADO y no solo en lo que se pinta en el momento: al
   * recargar la conversación, lo que se lee es lo guardado.
   */
  public function testElPackEntregadoGuardaSusFuentesEnlazadasConElCorreoCitado(): void {
    $this->container->set('sales_leadership_diagnostic.engine', new class() implements DiagnosticEngineInterface {

      /**
       * {@inheritdoc}
       */
      public function process(DiagnosticContext $context): DiagnosticTurn {
        return new DiagnosticTurn(
          message: "Weekly GOLD Pack\n\nMabe abre planta.\n\n**Asunto:** Planta de Guayaquil\n\nLiliana, vi el anuncio de la nueva planta que Mabe instala en Guayaquil.",
          completed: TRUE,
          result: [
            'summary' => 'Pack',
            'accounts' => [
              [
                'name' => 'Mabe Ecuador',
                'outreach_message' => "Asunto: Planta de Guayaquil\n\nLiliana, vi el anuncio de la nueva planta que Mabe instala en Guayaquil.",
                'sources' => [
                  [
                    'url' => 'https://www.eluniverso.com/mabe-planta',
                    'label' => 'Nueva planta',
                    'published' => '',
                  ],
                ],
              ],
            ],
          ],
          raw: [],
        );
      }

    });

    $session = $this->crearSesion('agente_gap');
    $respuesta = $this->container->get(ConversationService::class)->submitMessage($session, 'Haz el trabajo por mí');

    $this->assertStringContainsString('href="https://www.eluniverso.com/mabe-planta"', $respuesta['message_html']);

    $conversacion = $this->container->get(ConversationService::class)->getConversation((int) $session->id());
    $guardado = end($conversacion);
    $this->assertStringContainsString('https://www.eluniverso.com/mabe-planta', $guardado->content);
    $this->assertStringContainsString('Fuentes por cuenta', $guardado->content);

    // Y el correo de contacto, marcado como cita en lo guardado.
    $this->assertStringContainsString('> Liliana, vi el anuncio', $guardado->content);
    $this->assertStringContainsString('<blockquote>', $respuesta['message_html']);
  }

  /**
   * Un turno que no entrega el Pack no recibe fuentes.
   *
   * A media conversación el agente pregunta; añadirle una sección de fuentes
   * ahí sería ruido, aunque el turno trajera algo parecido a un resultado.
   */
  public function testUnTurnoQueNoEntregaNoRecibeFuentes(): void {
    $this->container->set('sales_leadership_diagnostic.engine', new class() implements DiagnosticEngineInterface {

      /**
       * {@inheritdoc}
       */
      public function process(DiagnosticContext $context): DiagnosticTurn {
        return new DiagnosticTurn(
          message: '¿Cuál es tu empresa?',
          completed: FALSE,
          result: ['accounts' => [['name' => 'X', 'sources' => [['url' => 'https://a.ec/x', 'label' => 'x']]]]],
          raw: [],
        );
      }

    });

    $session = $this->crearSesion('agente_gap');
    $respuesta = $this->container->get(ConversationService::class)->submitMessage($session, 'Hola');

    $this->assertStringNotContainsString('Fuentes por cuenta', $respuesta['message_html']);
  }

  /**
   * Sin saldo en el proveedor, un turno encolado libera la conversación.
   *
   * Antes la sesión se quedaba en «procesando» hasta que el cron la
   * desatascaba a los 45 minutos, y la cola reintentaba cada minuto algo que
   * no podía salir bien. Ahora vuelve a admitir mensajes y no relanza, así que
   * la cola suelta el elemento.
   */
  public function testSinSaldoUnTurnoEncoladoLiberaLaConversacion(): void {
    $this->container->set('sales_leadership_diagnostic.engine', new class() implements DiagnosticEngineInterface {

      /**
       * {@inheritdoc}
       */
      public function process(DiagnosticContext $context): DiagnosticTurn {
        throw new ProviderAccountException('sin saldo', 402);
      }

    });

    $session = $this->enEspera();

    // Si se relanzara, la cola lo reintentaría cada minuto: no debe lanzar.
    $this->container->get(ConversationService::class)->processQueuedTurn((int) $session->id());

    $this->assertSame(
      DiagnosticStatus::InProgress->value,
      $this->recargar($session)->get('status')->value,
      'La conversación tiene que volver a admitir mensajes.',
    );
  }

  /**
   * La pantalla se entera de que el turno no se hizo, y de por qué.
   *
   * Con un proveedor sin servicio, el mismo texto que ante el tope de gasto:
   * no depende del alumno y reintentar no sirve. Sin él, el genérico.
   */
  public function testElEstadoDiceQueElTurnoNoSeHizo(): void {
    $session = $this->enEspera();
    $session->setStatus(DiagnosticStatus::InProgress);
    $session->save();
    $controlador = ConversationApiController::create($this->container);

    $sinProblema = json_decode((string) $controlador->status($this->recargar($session))->getContent(), TRUE);
    $this->assertTrue($sinProblema['failed'] ?? FALSE);
    $this->assertStringContainsString('intenta nuevamente', $sinProblema['error']);

    $this->container->get(ProviderAccountStatus::class)->markUnavailable(ProviderAccountStatus::IA, 'sin saldo');
    $conProblema = json_decode((string) $controlador->status($this->recargar($session))->getContent(), TRUE);
    $this->assertStringContainsString('Avisa a tu instructor', $conProblema['error']);
    $this->assertStringNotContainsString('saldo', $conProblema['error'], 'Al alumno no se le habla de saldos.');
  }

  /**
   * Un turno que sí terminó no se marca como fallido.
   */
  public function testUnTurnoTerminadoNoSeMarcaComoFallido(): void {
    $session = $this->enEspera();
    $this->container->get(DiagnosticMessageRepository::class)->append((int) $session->id(), MessageRole::Assistant, 'Respuesta');
    $session->setStatus(DiagnosticStatus::InProgress);
    $session->save();

    $estado = json_decode((string) ConversationApiController::create($this->container)->status($this->recargar($session))->getContent(), TRUE);

    $this->assertArrayNotHasKey('failed', $estado);
  }

  /**
   * Una sesión con un mensaje del alumno esperando su turno en la cola.
   */
  private function enEspera(): DiagnosticSessionInterface {
    $session = $this->crearSesion('agente_gap');
    $this->container->get(DiagnosticMessageRepository::class)->append((int) $session->id(), MessageRole::User, 'Haz el trabajo por mí');
    $session->setStatus(DiagnosticStatus::Processing);
    $session->save();

    return $session;
  }

  /**
   * La sesión tal como está guardada ahora.
   */
  private function recargar(DiagnosticSessionInterface $session): DiagnosticSessionInterface {
    $almacen = $this->container->get('entity_type.manager')->getStorage('sld_diagnostic_session');
    $almacen->resetCache([$session->id()]);

    return $almacen->load($session->id());
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
