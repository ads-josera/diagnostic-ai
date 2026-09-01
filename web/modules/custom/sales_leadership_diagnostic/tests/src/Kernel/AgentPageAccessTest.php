<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Controller\AgentPageController;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Authorization\CourseAccessProviderInterface;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\Tests\sales_leadership_diagnostic\Kernel\Stub\StubCourseAccessProvider;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Comprueba quién puede ver la página de un agente.
 *
 * La página de agente nació el 31-08-2026 al partir el panel en dos niveles, y
 * con ella una superficie nueva: una URL que lleva el identificador del agente
 * dentro. Que el identificador viaje en la dirección no concede nada, y esta
 * prueba es la que lo fija.
 *
 * El caso que de verdad importa es el segundo. Un alumno con un curso podría
 * escribir el nombre del otro agente en la barra de direcciones; si eso
 * funcionara, vería la metodología y la presentación de un producto que no ha
 * comprado. No es una fuga de datos de otro alumno —eso lo cubre
 * DiagnosticAccessTest— pero sí de algo que se vende.
 */
#[CoversClass(AgentPageController::class)]
final class AgentPageAccessTest extends KernelTestBase {

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

  private const CURSO_COMPRADO = '35884';
  private const CURSO_AJENO = '99999';

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

    // Se sustituye solo la consulta a WordPress: el resto de la cadena de
    // autorización sigue siendo el código real.
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
    $this->installSchema('externalauth', ['authmap']);
    $this->installConfig(['system', 'sales_leadership_diagnostic']);

    // La página compone URLs con nombre de ruta; sin reconstruir el enrutador
    // no existen todavía en el contenedor del test.
    $this->container->get('router.builder')->rebuild();

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

    // Sin entrada en el authmap la cuenta no procede de WordPress y no se
    // puede resolver ninguna autorización.
    $this->container->get('externalauth.authmap')->save(
      $this->alumno,
      SalesLeadershipDiagnostic::AUTHMAP_PROVIDER,
      '4821',
    );

    $this->container->get('current_user')->setAccount($this->alumno);

    $this->provider = $this->container->get(StubCourseAccessProvider::class);

    $this->setSetting(SecretsProvider::JWT_SHARED_SECRET, str_repeat('a', 32));
    $this->setSetting(SecretsProvider::WP_HMAC_SECRET, str_repeat('b', 32));
    $this->setSetting(SecretsProvider::OPENAI_API_KEY, 'sk-test-no-se-usa');

    $this->config('sales_leadership_diagnostic.settings')
      ->set('wordpress.api_base_url', 'https://ejemplo.test')
      ->set('wordpress.course_id', self::CURSO_COMPRADO)
      ->save();

    $this->crearAgente('agente_comprado', self::CURSO_COMPRADO);
    $this->crearAgente('agente_ajeno', self::CURSO_AJENO);
  }

  /**
   * El agente que compró se puede ver.
   */
  public function testElAgenteCompradoSeVe(): void {
    $this->concederAcceso([self::CURSO_COMPRADO]);

    $pagina = $this->ver('agente_comprado');

    $this->assertSame('AGENTE_COMPRADO', $pagina['#agent_label']);
  }

  /**
   * El agente que NO compró da acceso denegado.
   *
   * Es la prueba que protege lo que se vende. Sin ella, escribir el nombre del
   * otro agente en la barra de direcciones enseñaría su presentación completa.
   */
  public function testElAgenteAjenoSeDeniega(): void {
    $this->concederAcceso([self::CURSO_COMPRADO]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->ver('agente_ajeno');
  }

  /**
   * Con el acceso denegado no se ve ni el agente propio.
   *
   * Un periodo caducado no da derecho a ningún agente aunque los cursos sigan
   * comprados.
   */
  public function testSinAccesoNoSeVeNinguno(): void {
    $this->provider->decision = new AccessDecision(
      granted: FALSE,
      courseId: self::CURSO_COMPRADO,
      checkedAt: 0,
    );

    $this->expectException(AccessDeniedHttpException::class);
    $this->ver('agente_comprado');
  }

  /**
   * El historial de la página trae solo las sesiones de ese agente.
   */
  public function testElHistorialTraeSoloEseAgente(): void {
    $this->concederAcceso([self::CURSO_COMPRADO, self::CURSO_AJENO]);

    $this->crearSesion('agente_comprado');
    $this->crearSesion('agente_comprado');
    $this->crearSesion('agente_ajeno');

    $this->assertCount(2, $this->ver('agente_comprado')['#history']);
  }

  /**
   * Corre el controller sobre un agente.
   *
   * @return array<string, mixed>
   *   El array de renderizado de la página.
   */
  private function ver(string $agentId): array {
    $agente = $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->load($agentId);

    return AgentPageController::create($this->container)->view($agente);
  }

  /**
   * Deja al alumno autorizado con los cursos indicados.
   *
   * @param string[] $cursos
   *   Cursos que posee.
   */
  private function concederAcceso(array $cursos): void {
    $this->provider->decision = new AccessDecision(
      granted: TRUE,
      courseId: $cursos[0],
      checkedAt: 2_500_000,
      ownedCourses: $cursos,
    );
  }

  /**
   * Crea un agente utilizable.
   */
  private function crearAgente(string $id, string $curso): void {
    $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->create([
        'id' => $id,
        'label' => strtoupper($id),
        'status' => TRUE,
        'version' => '1.0',
        'course_id' => $curso,
        'system_prompt' => 'Prompt de ' . $id,
      ])->save();
  }

  /**
   * Crea una sesión del alumno con un agente.
   */
  private function crearSesion(string $agentId): void {
    $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->create([
        'uid' => $this->alumno->id(),
        'wp_user_id' => '4821',
        'course_id' => self::CURSO_COMPRADO,
        'agent' => $agentId,
        'diagnostic_version' => '1.0',
        'prompt_snapshot' => 'prompt',
        'prompt_hash' => 'huella',
      ])->save();
  }

}
