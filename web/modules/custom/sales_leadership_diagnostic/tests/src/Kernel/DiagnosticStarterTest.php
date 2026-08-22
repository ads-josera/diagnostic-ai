<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\Exception\CannotStartDiagnosticException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Authorization\CourseAccessProviderInterface;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticStarter;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\Tests\sales_leadership_diagnostic\Kernel\Stub\StubCourseAccessProvider;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Comprueba las reglas que deciden si un alumno puede empezar un diagnóstico.
 *
 * Es el único punto del módulo que crea sesiones, así que aquí se concentran
 * cuatro reglas que antes no existían en ninguna parte: hay que estar
 * autorizado, no se duplican sesiones a medias, se respeta la política de
 * repetición y se cuenta el uso.
 *
 * El proveedor de autorización se sustituye por un doble para poder controlar
 * el inicio del periodo, que es el dato del que depende la política. Sin esa
 * sustitución el test necesitaría hablar con el WordPress real.
 */
#[CoversClass(DiagnosticStarter::class)]
final class DiagnosticStarterTest extends KernelTestBase {

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
   * Doble del proveedor de autorización.
   *
   * @var \Drupal\Tests\sales_leadership_diagnostic\Kernel\Stub\StubCourseAccessProvider
   */
  private StubCourseAccessProvider $provider;

  /**
   * Alumno de las pruebas.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $alumno;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    // Se sustituye la implementación por el doble ANTES de que se construya
    // nada: el resto de la cadena de autorización se conserva intacta, de modo
    // que lo que se prueba sigue siendo el código real.
    $container->setDefinition(
      StubCourseAccessProvider::class,
      (new Definition(StubCourseAccessProvider::class))->setPublic(TRUE),
    );
    $container->setAlias(CourseAccessProviderInterface::class, StubCourseAccessProvider::class)->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installSchema('externalauth', ['authmap']);
    $this->installConfig(['sales_leadership_diagnostic']);

    // El uid 1 es superusuario y saltaría toda comprobación de permisos.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $rol = Role::create([
      'id' => SalesLeadershipDiagnostic::STUDENT_ROLE_ID,
      'label' => 'Alumno',
    ]);
    $rol->grantPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS);
    $rol->save();

    $this->alumno = User::create([
      'name' => 'sld_wp_4821',
      'status' => 1,
      'roles' => [SalesLeadershipDiagnostic::STUDENT_ROLE_ID],
    ]);
    $this->alumno->save();

    // Sin entrada en el authmap la cuenta no procede de WordPress y el
    // servicio la rechaza antes de mirar nada más.
    $this->container->get('externalauth.authmap')->save(
      $this->alumno,
      SalesLeadershipDiagnostic::AUTHMAP_PROVIDER,
      '4821',
    );

    $this->provider = $this->container->get(StubCourseAccessProvider::class);
    $this->configurarModulo();
  }

  /**
   * Con acceso vigente y sin límite, se crea la sesión.
   */
  public function testCreaLaSesionCuandoTodoEstaEnOrden(): void {
    $this->concederAcceso();

    $session = $this->starter()->start($this->alumno);

    $this->assertSame('4821', $session->getWordPressUserId());
    $this->assertSame(DiagnosticStatus::Draft, $session->getStatus());
    $this->assertNotSame('', $session->getPromptSnapshot(), 'La sesión debe guardar copia del prompt (§57).');
    $this->assertSame(
      hash('sha256', $session->getPromptSnapshot()),
      $session->getPromptHash(),
      'El hash debe corresponder al prompt guardado.',
    );
  }

  /**
   * Una cuenta que no procede de WordPress no puede empezar.
   */
  public function testUnaCuentaLocalNoPuedeEmpezar(): void {
    $this->concederAcceso();

    $local = User::create(['name' => 'creado_a_mano', 'status' => 1]);
    $local->save();

    $this->expectException(CannotStartDiagnosticException::class);
    $this->starter()->start($local);
  }

  /**
   * Sin autorización vigente no se crea nada.
   */
  public function testSinAutorizacionNoSeCreaSesion(): void {
    $this->provider->decision = new AccessDecision(
      granted: FALSE,
      courseId: '35884',
      checkedAt: 1_000_000,
    );

    try {
      $this->starter()->start($this->alumno);
      $this->fail('Debería haberse rechazado por falta de autorización.');
    }
    catch (CannotStartDiagnosticException $e) {
      $this->assertSame(CannotStartDiagnosticException::REASON_NOT_AUTHORIZED, $e->getReason());
    }

    $this->assertSame(0, $this->contarSesiones());
  }

  /**
   * Pulsar dos veces con una conversación a medias devuelve la misma.
   *
   * Sin esta regla, un doble clic dejaría dos sesiones abiertas y el alumno
   * perdería de vista la primera.
   */
  public function testNoDuplicaUnaSesionEnCurso(): void {
    $this->concederAcceso();

    $primera = $this->starter()->start($this->alumno);
    $segunda = $this->starter()->start($this->alumno);

    $this->assertSame($primera->id(), $segunda->id());
    $this->assertSame(1, $this->contarSesiones());
  }

  /**
   * Sin límite configurado, un diagnóstico terminado no impide otro.
   */
  public function testSinLimitePuedeRepetir(): void {
    $this->concederAcceso(periodStart: 1_000_000);
    $this->crearSesionCompletada(1_500_000);

    $this->starter()->start($this->alumno);

    $this->assertSame(2, $this->contarSesiones());
  }

  /**
   * Con «uno por periodo», el segundo intento del mismo periodo se rechaza.
   */
  public function testUnoPorPeriodoBloqueaElSegundo(): void {
    $this->activarLimitePorPeriodo();
    $this->concederAcceso(periodStart: 1_000_000);
    $this->crearSesionCompletada(1_500_000);

    try {
      $this->starter()->start($this->alumno);
      $this->fail('Debería haberse rechazado por haber agotado el diagnóstico del periodo.');
    }
    catch (CannotStartDiagnosticException $e) {
      $this->assertSame(CannotStartDiagnosticException::REASON_ALREADY_DONE, $e->getReason());
    }

    $this->assertSame(1, $this->contarSesiones());
  }

  /**
   * Un periodo nuevo devuelve el derecho a repetir.
   *
   * Es el comportamiento que se pidió: la compra reactiva el diagnóstico sola,
   * sin que nadie tenga que intervenir. Se modela como un periodo que empieza
   * DESPUÉS del diagnóstico anterior.
   */
  public function testUnPeriodoNuevoDevuelveElDerecho(): void {
    $this->activarLimitePorPeriodo();
    $this->crearSesionCompletada(1_500_000);

    // La compra nueva reinició el reloj en WordPress: el periodo vigente
    // empieza después de aquel diagnóstico, que ya pertenece al pasado.
    $this->concederAcceso(periodStart: 2_000_000);

    $this->starter()->start($this->alumno);

    $this->assertSame(2, $this->contarSesiones());
  }

  /**
   * Una sesión fallida también consume el diagnóstico del periodo.
   *
   * Contar solo los resultados dejaría un hueco: reintentar provocando fallos
   * daría intentos ilimitados contra el proveedor de IA.
   */
  public function testUnaSesionFallidaTambienCuenta(): void {
    $this->activarLimitePorPeriodo();
    $this->concederAcceso(periodStart: 1_000_000);
    $this->crearSesionCompletada(1_500_000, DiagnosticStatus::Failed);

    $this->expectException(CannotStartDiagnosticException::class);
    $this->starter()->start($this->alumno);
  }

  /**
   * Si WordPress no informa del periodo, no se aplica el límite.
   *
   * Rechazar sería peor que no limitar: dejaría sin su diagnóstico a alguien
   * que ha pagado, por un dato que no depende de él. Queda constancia en el
   * registro para que el administrador actúe sobre la causa.
   */
  public function testSinInicioDePeriodoNoSeAplicaElLimite(): void {
    $this->activarLimitePorPeriodo();
    $this->concederAcceso(periodStart: NULL);
    $this->crearSesionCompletada(1_500_000);

    $this->starter()->start($this->alumno);

    $this->assertSame(2, $this->contarSesiones());
  }

  /**
   * El servicio bajo prueba.
   */
  private function starter(): DiagnosticStarter {
    return $this->container->get(DiagnosticStarter::class);
  }

  /**
   * Configura el doble para que conceda acceso.
   *
   * @param int|null $periodStart
   *   Inicio del periodo que debe informar.
   */
  private function concederAcceso(?int $periodStart = NULL): void {
    $this->provider->decision = new AccessDecision(
      granted: TRUE,
      courseId: '35884',
      checkedAt: 2_500_000,
      startedAt: $periodStart,
    );
  }

  /**
   * Activa la política de un diagnóstico por periodo.
   */
  private function activarLimitePorPeriodo(): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('diagnostic.repeat_policy', 'once_per_period')
      ->save();
  }

  /**
   * Deja el módulo en condiciones de diagnosticar.
   *
   * El servicio se niega a crear una sesión si falta configuración, así que un
   * test que no la ponga fallaría siempre por el motivo equivocado. Se ponen
   * los tres requisitos: secretos, integración y prompt.
   *
   * Los secretos van por Settings y no por configuración, que es justamente lo
   * que exige §29 para que no acaben en un archivo exportable.
   */
  private function configurarModulo(): void {
    $this->setSetting(SecretsProvider::JWT_SHARED_SECRET, str_repeat('a', 32));
    $this->setSetting(SecretsProvider::WP_HMAC_SECRET, str_repeat('b', 32));
    $this->setSetting(SecretsProvider::OPENAI_API_KEY, 'sk-test-no-se-usa');

    $this->config('sales_leadership_diagnostic.settings')
      ->set('wordpress.api_base_url', 'https://ejemplo.test')
      ->set('wordpress.course_id', '35884')
      ->save();

    $this->config('sales_leadership_diagnostic.diagnostic')
      ->set('version', '1.0-TEST')
      ->set('system_prompt', 'Eres un consultor de liderazgo comercial.')
      ->save();
  }

  /**
   * Crea una sesión ya terminada en el momento indicado.
   */
  private function crearSesionCompletada(int $created, DiagnosticStatus $status = DiagnosticStatus::Completed): void {
    $session = DiagnosticSession::create([
      'uid' => $this->alumno->id(),
      'wp_user_id' => '4821',
      'course_id' => '35884',
      'diagnostic_version' => '1.0-TEST',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
      'created' => $created,
    ]);
    $session->setStatus($status);
    $session->save();
  }

  /**
   * Cuenta las sesiones del alumno.
   */
  private function contarSesiones(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->alumno->id())
      ->count()
      ->execute();
  }

}
