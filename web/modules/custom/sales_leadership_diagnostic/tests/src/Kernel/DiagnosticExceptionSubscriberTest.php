<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
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
 * Las dos pruebas que más importan aquí no son las del caso feliz, sino las
 * que fijan lo que este suscriptor NO debe tocar: los 403 y 404, que son
 * respuestas con significado, y las rutas ajenas al módulo. Un 403 secuestrado
 * rompería el control de acceso, que es lo que sostiene el aislamiento entre
 * alumnos.
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
   * Un 403 se deja pasar intacto.
   *
   * Es la prueba que protege el aislamiento entre alumnos: si este suscriptor
   * convirtiera los accesos denegados en páginas de error, dejaría de
   * distinguirse «no es tuyo» de «algo se rompió», y cualquier revisión futura
   * del control de acceso se volvería ciega.
   */
  public function testUnAccesoDenegadoNoSeToca(): void {
    $evento = $this->lanzar(
      new AccessDeniedHttpException('No es tuyo.'),
      'sales_leadership_diagnostic.result',
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
   */
  private function lanzar(\Throwable $error, ?string $ruta): ExceptionEvent {
    $peticion = Request::create('/sales-diagnostic');

    if ($ruta !== NULL) {
      $peticion->attributes->set('_route', $ruta);
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
