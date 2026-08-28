<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Access\DiagnosticAccessCheck;
use Drupal\sales_leadership_diagnostic\EventSubscriber\DiagnosticExceptionSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Comprueba qué recibe alguien cuando algo falla de forma imprevista.
 *
 * Los controladores capturan sus propias excepciones. Lo que no cubrían era lo
 * IMPREVISTO —un TypeError, un fallo de base de datos—, que llegaba hasta
 * Drupal y salía como su página genérica, o como HTML donde el navegador
 * esperaba JSON.
 *
 * Desde el 28-08-2026 también compone la página de un acceso denegado en las
 * rutas del módulo, para poder distinguir «no pudimos comprobarlo» de «no
 * tienes acceso». Esa distinción no es cosmética: decirle a quien pagó que no
 * tiene acceso le manda a reclamarle al cliente por una compra que está bien,
 * y pasó de verdad.
 *
 * Las pruebas que más importan aquí no son las del caso feliz, sino las que
 * fijan los límites:
 *
 *  - Un acceso denegado SIGUE respondiendo 403. Cambia el texto, nunca el
 *    código: si dejara de ser un 403, cualquier revisión futura del
 *    aislamiento entre alumnos se volvería ciega.
 *  - Los 404 y las rutas ajenas al módulo no se tocan.
 */
#[CoversClass(DiagnosticExceptionSubscriber::class)]
final class DiagnosticExceptionSubscriberTest extends KernelTestBase {

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
   * Un error imprevisto en una página del módulo da una respuesta presentable.
   */
  public function testUnErrorImprevistoDaUnaPaginaPresentable(): void {
    $evento = $this->lanzar(
      new \TypeError('Detalle técnico con la clave sk-secreta.'),
      'sales_leadership_diagnostic.dashboard',
    );

    $respuesta = $evento->getResponse();

    $this->assertInstanceOf(Response::class, $respuesta);
    $this->assertSame(500, $respuesta->getStatusCode());
    $this->assertStringContainsString('Algo no ha ido bien', (string) $respuesta->getContent());
    $this->assertStringContainsString('/sales-diagnostic', (string) $respuesta->getContent());
  }

  /**
   * El detalle técnico NO llega a quien mira.
   *
   * El mensaje de una excepción puede arrastrar cualquier cosa: una clave, una
   * consulta, una ruta del servidor. Va al registro, nunca a la pantalla
   * (§43, §58).
   */
  public function testElDetalleTecnicoNoLlegaAlAlumno(): void {
    $evento = $this->lanzar(
      new \TypeError('Detalle técnico con la clave sk-secreta y /var/www/html/ruta.php.'),
      'sales_leadership_diagnostic.result',
    );

    $contenido = (string) $evento->getResponse()->getContent();

    $this->assertStringNotContainsString('sk-secreta', $contenido);
    $this->assertStringNotContainsString('/var/www/html', $contenido);
    $this->assertStringNotContainsString('TypeError', $contenido);
  }

  /**
   * En el endpoint del chat se responde JSON, no HTML.
   *
   * Su navegador espera JSON. Devolverle la página de error del sitio le da un
   * cuerpo que no puede leer y una cabecera que miente sobre lo que es.
   */
  public function testEnElEndpointDelChatSeRespondeJson(): void {
    $evento = $this->lanzar(
      new \TypeError('Fallo.'),
      'sales_leadership_diagnostic.session_message',
    );

    $respuesta = $evento->getResponse();

    $this->assertInstanceOf(JsonResponse::class, $respuesta);
    $this->assertSame(500, $respuesta->getStatusCode());
    $this->assertStringContainsString('application/json', (string) $respuesta->headers->get('Content-Type'));
    $this->assertArrayHasKey('error', json_decode((string) $respuesta->getContent(), TRUE));
  }

  /**
   * Un acceso denegado SIGUE SIENDO un 403.
   *
   * Es la prueba que protege el control de acceso. Desde el 28-08-2026 el
   * módulo pinta su propia página en un 403 de sus rutas, para poder decirle
   * al alumno la verdad; lo que NO puede cambiar nunca es el código de
   * estado. Si un acceso denegado dejara de responder 403, cualquier revisión
   * futura del aislamiento entre alumnos se volvería ciega.
   */
  public function testUnAccesoDenegadoSigueSiendoUn403(): void {
    $evento = $this->lanzar(
      new AccessDeniedHttpException('No es tuyo.'),
      'sales_leadership_diagnostic.result',
    );

    $this->assertSame(403, $evento->getResponse()->getStatusCode());
  }

  /**
   * Sin poder verificar se dice eso, y NO que no tenga acceso.
   *
   * El aviso lo deja el control de acceso, que es el único que sabe si la
   * denegación vino de una comprobación o de una avería. Decirle a quien pagó
   * que no tiene acceso le manda a reclamar una compra que está bien: pasó de
   * verdad el día que el alojamiento del cliente bloqueó nuestra IP.
   */
  public function testSinPoderVerificarNoSeDiceQueNoTengaAcceso(): void {
    $evento = $this->lanzar(
      new AccessDeniedHttpException('No se pudo comprobar.'),
      'sales_leadership_diagnostic.dashboard',
      sinVerificar: TRUE,
    );

    $contenido = (string) $evento->getResponse()->getContent();

    $this->assertSame(403, $evento->getResponse()->getStatusCode());
    $this->assertStringContainsString('No hemos podido verificar tu acceso', $contenido);
    $this->assertStringContainsString('No es un problema con tu compra', $contenido);
    $this->assertStringNotContainsString('no tiene acceso a este diagnóstico', $contenido);
  }

  /**
   * Una denegación comprobada sí dice que no tiene acceso.
   *
   * La otra mitad: quien de verdad no tiene el curso debe saberlo, y no
   * quedarse esperando a que «vuelva a funcionar».
   */
  public function testUnaDenegacionComprobadaSiLoDice(): void {
    $evento = $this->lanzar(
      new AccessDeniedHttpException('No tiene el curso.'),
      'sales_leadership_diagnostic.dashboard',
    );

    $contenido = (string) $evento->getResponse()->getContent();

    $this->assertStringContainsString('no tiene acceso a este diagnóstico', $contenido);
    $this->assertStringNotContainsString('No hemos podido verificar', $contenido);
  }

  /**
   * Un acceso denegado en una ruta AJENA no se toca.
   *
   * El resto del sitio lo presenta el sitio.
   */
  public function testUnAccesoDenegadoAjenoNoSeToca(): void {
    $evento = $this->lanzar(
      new AccessDeniedHttpException('Otra parte.'),
      'system.admin_content',
    );

    $this->assertFalse($evento->hasResponse());
  }

  /**
   * Un 404 tampoco.
   */
  public function testUnaPaginaInexistenteNoSeToca(): void {
    $evento = $this->lanzar(
      new NotFoundHttpException('No existe.'),
      'sales_leadership_diagnostic.result',
    );

    $this->assertFalse($evento->hasResponse());
  }

  /**
   * Las rutas ajenas al módulo se dejan en paz.
   *
   * El resto del sitio es problema del sitio. Un módulo que secuestrara los
   * errores de todas las páginas sería una sorpresa desagradable para quien lo
   * instale.
   */
  public function testLasRutasAjenasAlModuloSeDejanEnPaz(): void {
    $evento = $this->lanzar(new \TypeError('Fallo en otra parte.'), 'system.admin_content');

    $this->assertFalse($evento->hasResponse());
  }

  /**
   * Una petición sin ruta no rompe el suscriptor.
   *
   * Ocurre cuando el error salta antes de que el enrutador resuelva nada.
   */
  public function testUnaPeticionSinRutaNoRompeNada(): void {
    $evento = $this->lanzar(new \TypeError('Fallo temprano.'), NULL);

    $this->assertFalse($evento->hasResponse());
  }

  /**
   * Corre el suscriptor sobre el error y la ruta indicados.
   *
   * @param \Throwable $error
   *   Error que se produjo.
   * @param string|null $ruta
   *   Nombre de la ruta, o NULL si el enrutador no llegó a resolverla.
   * @param bool $sinVerificar
   *   Si la denegación vino de no haber podido comprobar la autorización.
   */
  private function lanzar(\Throwable $error, ?string $ruta, bool $sinVerificar = FALSE): ExceptionEvent {
    $peticion = Request::create('/sales-diagnostic');

    if ($ruta !== NULL) {
      $peticion->attributes->set('_route', $ruta);
    }

    if ($sinVerificar) {
      $peticion->attributes->set(DiagnosticAccessCheck::ATRIBUTO_SIN_VERIFICAR, TRUE);
    }

    $evento = new ExceptionEvent(
      $this->container->get('http_kernel'),
      $peticion,
      HttpKernelInterface::MAIN_REQUEST,
      $error,
    );

    $this->container
      ->get(DiagnosticExceptionSubscriber::class)
      ->onException($evento);

    return $evento;
  }

}
