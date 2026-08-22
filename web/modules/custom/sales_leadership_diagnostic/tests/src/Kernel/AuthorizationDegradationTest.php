<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException;
use Drupal\sales_leadership_diagnostic\Service\Authorization\CachedCourseAccessProvider;
use Drupal\sales_leadership_diagnostic\Service\Authorization\CourseAccessProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba la política de degradación cuando WordPress no responde (§13).
 *
 * Es la lógica más delicada del módulo porque decide quién entra cuando el
 * sistema del que depende la autorización está caído. Un error hacia el lado
 * permisivo daría acceso a quien no lo tiene; uno hacia el restrictivo dejaría
 * fuera a todos los alumnos ante cualquier microcorte.
 *
 * Ninguno de estos tests toca la red: el proveedor externo se sustituye por un
 * doble que puede simular la caída a voluntad (§48).
 */
#[CoversClass(CachedCourseAccessProvider::class)]
final class AuthorizationDegradationTest extends KernelTestBase {

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

  private const CURSO = '35884';

  /**
   * Doble del proveedor externo.
   */
  private object $proveedorExterno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['sales_leadership_diagnostic']);

    $this->proveedorExterno = new class() implements CourseAccessProviderInterface {

      /**
       * Cuántas veces se ha consultado al proveedor.
       *
       * @var int
       */
      public int $llamadas = 0;

      /**
       * Si se simula que WordPress no responde.
       *
       * @var bool
       */
      public bool $caido = FALSE;

      /**
       * Qué respuesta se devuelve cuando sí responde.
       *
       * @var bool
       */
      public bool $concede = TRUE;

      /**
       * {@inheritdoc}
       */
      public function checkAccess(string $externalUserId, string $courseId): AccessDecision {
        $this->llamadas++;

        if ($this->caido) {
          throw new WordPressUnavailableException('simulado');
        }

        return new AccessDecision($this->concede, $courseId, \Drupal::time()->getRequestTime());
      }

    };
  }

  /**
   * Una respuesta reciente se reutiliza sin volver a preguntar (§14).
   */
  public function testLaCacheEvitaConsultasRepetidas(): void {
    $proveedor = $this->crearProveedor();

    $primera = $proveedor->checkAccess('1', self::CURSO);
    $segunda = $proveedor->checkAccess('1', self::CURSO);
    $tercera = $proveedor->checkAccess('1', self::CURSO);

    $this->assertSame(1, $this->proveedorExterno->llamadas, 'Se consultó a WordPress más de una vez.');
    $this->assertSame(AccessDecision::SOURCE_LIVE, $primera->source);
    $this->assertSame(AccessDecision::SOURCE_CACHE, $segunda->source);
    $this->assertSame(AccessDecision::SOURCE_CACHE, $tercera->source);
  }

  /**
   * Cada alumno y cada curso tienen su propia entrada.
   *
   * Compartirlas concedería a un alumno el acceso comprobado para otro.
   */
  public function testLasEntradasNoSeMezclanEntreAlumnosNiCursos(): void {
    $proveedor = $this->crearProveedor();

    $proveedor->checkAccess('1', self::CURSO);
    $proveedor->checkAccess('2', self::CURSO);
    $proveedor->checkAccess('1', '99999');

    $this->assertSame(3, $this->proveedorExterno->llamadas);
  }

  /**
   * Sin nada en cache y con WordPress caído, no se concede acceso.
   *
   * Es el comportamiento por defecto que exige §13.
   */
  public function testSinCacheNiWordPressSeDeniega(): void {
    $this->proveedorExterno->caido = TRUE;

    $this->expectException(WordPressUnavailableException::class);

    $this->crearProveedor()->checkAccess('nuevo', self::CURSO);
  }

  /**
   * Una concesión reciente sobrevive a una caída, dentro del periodo de gracia.
   *
   * Quien ya fue verificado hace poco no debe quedarse fuera por una avería
   * ajena, y echarlo no aportaría ninguna seguridad.
   */
  public function testUnaConcesionRecienteSobreviveLaCaida(): void {
    $this->sembrarCache('7', TRUE, 1000);
    $this->proveedorExterno->caido = TRUE;

    $decision = $this->crearProveedor()->checkAccess('7', self::CURSO);

    $this->assertTrue($decision->granted);
    $this->assertSame(AccessDecision::SOURCE_CACHE, $decision->source);
  }

  /**
   * Fuera del periodo de gracia se deniega, aunque hubiera concesión.
   */
  public function testUnaConcesionAntiguaNoSirve(): void {
    $this->sembrarCache('8', TRUE, 7200);
    $this->proveedorExterno->caido = TRUE;

    $this->expectException(WordPressUnavailableException::class);

    $this->crearProveedor()->checkAccess('8', self::CURSO);
  }

  /**
   * Una DENEGACIÓN previa nunca se reutiliza como gracia.
   *
   * Este test cierra la puerta a que una avería acabe concediendo acceso a
   * quien no lo tenía.
   */
  public function testUnaDenegacionPreviaNoConcedeAcceso(): void {
    $this->sembrarCache('9', FALSE, 120);
    $this->proveedorExterno->caido = TRUE;

    $this->expectException(WordPressUnavailableException::class);

    $this->crearProveedor()->checkAccess('9', self::CURSO);
  }

  /**
   * Con el periodo de gracia a cero, la excepción desaparece por completo.
   */
  public function testGraciaCeroRestauraElFailClosedEstricto(): void {
    $this->config('sales_leadership_diagnostic.settings')
      ->set('wordpress.cache_grace_period', 0)
      ->save();

    $this->sembrarCache('10', TRUE, 1000);
    $this->proveedorExterno->caido = TRUE;

    $this->expectException(WordPressUnavailableException::class);

    $this->crearProveedor()->checkAccess('10', self::CURSO);
  }

  /**
   * Una denegación reciente se responde desde cache, sin consultar.
   */
  public function testUnaDenegacionFrescaSeSirveDesdeCache(): void {
    $this->sembrarCache('11', FALSE, 10);

    $decision = $this->crearProveedor()->checkAccess('11', self::CURSO);

    $this->assertFalse($decision->granted);
    $this->assertSame(0, $this->proveedorExterno->llamadas, 'No debió consultarse a WordPress.');
  }

  /**
   * Construye el decorador sobre el doble.
   */
  private function crearProveedor(): CachedCourseAccessProvider {
    return new CachedCourseAccessProvider(
      $this->proveedorExterno,
      $this->container->get('cache.default'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.factory'),
    );
  }

  /**
   * Escribe una decisión con una antigüedad concreta.
   *
   * La entrada se guarda con caducidad lejana pero con marca de tiempo
   * antigua: así se distingue "ya no es fresca" de "ya no existe", que es
   * justo la diferencia que activa el periodo de gracia.
   *
   * @param string $usuario
   *   Identificador externo del alumno.
   * @param bool $concedido
   *   Si la decisión guardada concede acceso.
   * @param int $antiguedadSegundos
   *   Cuánto hace que se tomó.
   */
  private function sembrarCache(string $usuario, bool $concedido, int $antiguedadSegundos): void {
    $ahora = $this->container->get('datetime.time')->getRequestTime();

    $this->container->get('cache.default')->set(
      'sld:authorization:' . $usuario . ':' . self::CURSO,
      [
        'granted' => $concedido,
        'courseId' => self::CURSO,
        'checkedAt' => $ahora - $antiguedadSegundos,
      ],
      $ahora + 86400,
      [CachedCourseAccessProvider::CACHE_TAG],
    );
  }

}
