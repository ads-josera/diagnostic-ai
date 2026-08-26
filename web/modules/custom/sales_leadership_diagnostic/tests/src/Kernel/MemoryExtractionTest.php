<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\MemoryTopic;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Memory\MemoryExtractor;
use Drupal\sales_leadership_diagnostic\Service\Memory\StudentMemoryStore;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Comprueba qué se recuerda de una conversación terminada, y qué no.
 *
 * La extracción es la pieza que escribe en la memoria, y corre en segundo
 * plano, sin usuario en curso y sin nadie mirando. Eso hace que sus dos
 * riesgos sean silenciosos: escribir en la ficha de quien no es, y tumbar algo
 * cuando el proveedor falle.
 *
 * El proveedor se sustituye por un doble de Guzzle en lugar de por un doble
 * del cliente, de modo que la petición recorre el camino de verdad —esquema,
 * reintentos, decodificación— y lo único simulado es la red (§48).
 */
#[CoversClass(MemoryExtractor::class)]
final class MemoryExtractionTest extends KernelTestBase {

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
   * Respuestas que devolverá el proveedor simulado.
   *
   * Es estática porque el contenedor se construye antes que el test y necesita
   * poder alcanzarla desde la definición del servicio.
   *
   * @var \GuzzleHttp\Handler\MockHandler|null
   */
  private static ?MockHandler $proveedor = NULL;

  /**
   * Alumna dueña de la conversación.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $ana;

  /**
   * Otro alumno, cuya ficha no debe tocarse.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $bruno;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    self::$proveedor = new MockHandler();

    // Se sustituye el cliente HTTP y nada más: el cliente de OpenAI, el
    // extractor y el almacén son los de verdad.
    $container->setDefinition('http_client', (new Definition(Client::class, [
      ['handler' => HandlerStack::create(self::$proveedor)],
    ]))->setPublic(TRUE));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_student_memory');
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installConfig(['sales_leadership_diagnostic']);

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();
    $this->ana = $this->crearAlumno('ana');
    $this->bruno = $this->crearAlumno('bruno');

    $this->setSetting('sld_openai_api_key', 'sk-de-prueba');
    $this->config('sales_leadership_diagnostic.settings')
      ->set('openai.model', 'gpt-prueba')
      ->save();
  }

  /**
   * Lo que devuelve el modelo queda recordado, con su procedencia.
   */
  public function testGuardaLoQueDevuelveElModelo(): void {
    $session = $this->crearSesionConversada($this->ana);

    $this->responder([
      'empresa' => 'Distribuidora de material eléctrico, 40 empleados.',
      'equipo' => 'Seis vendedores y un jefe de ventas.',
      'icp' => '',
      'cuentas' => '',
      'proceso' => '',
      'objetivos' => '',
    ]);

    $this->assertTrue($this->extractor()->extractFromSession((int) $session->id()));

    $memoria = $this->store()->forUser((int) $this->ana->id());

    $this->assertSame(['empresa', 'equipo'], array_keys($memoria));
    $this->assertSame('agente_gap', $memoria['empresa']->getSourceAgentId());
    $this->assertSame((int) $session->id(), $memoria['empresa']->getSourceSessionId());
  }

  /**
   * Se escribe en la ficha del dueño de la sesión, y solo en esa.
   *
   * Es la comprobación que importa: la extracción corre sin usuario en curso,
   * así que si tomara el alumno de cualquier otro sitio que no sea la propia
   * sesión, el error no lo detendría nada.
   */
  public function testEscribeSoloEnLaFichaDelDuenoDeLaSesion(): void {
    $session = $this->crearSesionConversada($this->ana);

    $this->responder(['empresa' => 'Distribuidora de material eléctrico.'] + $this->temasVacios());

    $this->extractor()->extractFromSession((int) $session->id());

    $this->assertCount(1, $this->store()->forUser((int) $this->ana->id()));
    $this->assertTrue($this->store()->isEmpty((int) $this->bruno->id()));
  }

  /**
   * Lo que ya se sabía se le manda al modelo para que lo actualice.
   *
   * Sin esto, cada extracción escribiría solo lo que se dijo en la última
   * conversación y borraría el resto de la ficha. La comprobación es sobre la
   * petición que sale, no sobre lo que vuelve.
   */
  public function testLeMandaAlModeloLoQueYaSeSabia(): void {
    $store = $this->store();
    $store->remember((int) $this->ana->id(), MemoryTopic::Icp, 'Instaladores pequeños del noreste.', 'agente_previo');

    $session = $this->crearSesionConversada($this->ana);
    $this->responder($this->temasVacios());

    $this->extractor()->extractFromSession((int) $session->id());

    // Se decodifica antes de mirar: Guzzle escapa los acentos al serializar,
    // así que buscar el texto tal cual en el cuerpo crudo no encontraría nada
    // aunque estuviera.
    $enviado = json_decode((string) self::$proveedor->getLastRequest()->getBody(), TRUE);
    $mensajeDelUsuario = $enviado['messages'][1]['content'];

    $this->assertStringContainsString('Lo que ya se sabía', $mensajeDelUsuario);
    $this->assertStringContainsString('Instaladores pequeños del noreste', $mensajeDelUsuario);
    $this->assertStringContainsString('Distribuimos material eléctrico', $mensajeDelUsuario);
  }

  /**
   * Un ensayo del gestor no escribe memoria, ni llama al proveedor.
   */
  public function testUnEnsayoNoDejaMemoria(): void {
    $session = $this->crearSesionConversada($this->ana, ensayo: TRUE);

    // No se prepara ninguna respuesta: si llegara a llamar, el doble fallaría.
    $this->assertFalse($this->extractor()->extractFromSession((int) $session->id()));
    $this->assertTrue($this->store()->isEmpty((int) $this->ana->id()));
  }

  /**
   * Si el proveedor falla, no se propaga nada y la memoria queda intacta.
   *
   * La memoria es una comodidad: que no se extraiga significa que el alumno
   * tendrá que volver a contar su empresa, no que nada más deba romperse.
   */
  public function testUnFalloDelProveedorNoRompeNadaNiBorraLoAnterior(): void {
    $store = $this->store();
    $store->remember((int) $this->ana->id(), MemoryTopic::Empresa, 'Lo que ya se sabía.', 'agente_previo');

    $session = $this->crearSesionConversada($this->ana);
    self::$proveedor->append(new Response(401, [], (string) json_encode([
      'error' => ['code' => 'invalid_api_key'],
    ])));

    $this->assertFalse($this->extractor()->extractFromSession((int) $session->id()));
    $this->assertSame(
      'Lo que ya se sabía.',
      $store->forUser((int) $this->ana->id())['empresa']->getContent(),
    );
  }

  /**
   * Una sesión que no existe se ignora sin ruido.
   *
   * Ocurre de verdad: el elemento lleva en la cola desde antes de que alguien
   * borrara la sesión.
   */
  public function testUnaSesionQueYaNoExisteSeIgnora(): void {
    $this->assertFalse($this->extractor()->extractFromSession(987654));
  }

  /**
   * Prepara la respuesta del proveedor con los temas indicados.
   *
   * @param array<string, string> $temas
   *   Contenido de cada tema.
   */
  private function responder(array $temas): void {
    self::$proveedor->append(new Response(200, [], (string) json_encode([
      'choices' => [
        [
          'finish_reason' => 'stop',
          'message' => ['content' => json_encode($temas)],
        ],
      ],
      'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
    ])));
  }

  /**
   * Devuelve la lista de temas, cada uno vacío.
   *
   * Sirve para completar una respuesta parcial: el esquema estricto obliga a
   * que estén los seis, así que un test que solo quiera fijar uno necesita
   * rellenar el resto.
   *
   * @return array<string, string>
   *   Un valor vacío por tema.
   */
  private function temasVacios(): array {
    return array_fill_keys(MemoryTopic::order(), '');
  }

  /**
   * Crea una sesión terminada con unos cuantos turnos dentro.
   */
  private function crearSesionConversada(User $alumno, bool $ensayo = FALSE): DiagnosticSessionInterface {
    $session = DiagnosticSession::create([
      'uid' => $alumno->id(),
      'wp_user_id' => '4821',
      'course_id' => '35884',
      'agent' => 'agente_gap',
      'diagnostic_version' => '1.0-TEST',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
      'is_sandbox' => $ensayo,
    ]);
    $session->setStatus(DiagnosticStatus::Completed);
    $session->save();

    $mensajes = $this->container->get(DiagnosticMessageRepository::class);
    $mensajes->append((int) $session->id(), MessageRole::Assistant, '¿A qué se dedica tu empresa?');
    $mensajes->append((int) $session->id(), MessageRole::User, 'Distribuimos material eléctrico. Somos cuarenta.');

    return $session;
  }

  /**
   * Crea un alumno.
   */
  private function crearAlumno(string $nombre): User {
    $usuario = User::create(['name' => $nombre, 'status' => 1]);
    $usuario->save();

    return $usuario;
  }

  /**
   * El servicio bajo prueba.
   */
  private function extractor(): MemoryExtractor {
    return $this->container->get(MemoryExtractor::class);
  }

  /**
   * El almacén de la memoria.
   */
  private function store(): StudentMemoryStore {
    return $this->container->get(StudentMemoryStore::class);
  }

}
