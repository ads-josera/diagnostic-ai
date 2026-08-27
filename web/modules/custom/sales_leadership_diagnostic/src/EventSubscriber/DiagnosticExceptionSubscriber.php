<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Security\ExceptionRedactor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Último recinto ante un error que nadie esperaba (§13, §58).
 *
 * Los controladores del módulo capturan sus propias excepciones y le dan al
 * alumno un mensaje neutro. Lo que no cubrían era lo IMPREVISTO: un TypeError,
 * un fallo de la base de datos, cualquier cosa que no herede de
 * DiagnosticException. Eso llegaba hasta Drupal, que respondía con su página
 * genérica —fuera del marco del módulo, sin la marca del cliente y en inglés—
 * o, en el endpoint del chat, con HTML donde el navegador esperaba JSON.
 *
 * Se comprobó midiendo, no suponiendo (26-08-2026), y conviene dejar escrito
 * lo que NO estaba roto para que nadie lo «arregle» de nuevo:
 *
 *  - En producción NO se filtraban trazas. La configuración exportada lleva
 *    `error_level: hide`; lo que se ve en local viene de `settings.ddev.php`,
 *    que no viaja.
 *  - El chat degradaba bien: su JavaScript nunca lee el cuerpo de una
 *    respuesta que no sea correcta, así que el alumno veía un aviso normal.
 *
 * Lo que sí faltaba: que el alumno se quedara ante una página que no dice nada
 * útil ni ofrece por dónde salir, y que quedara registro con el contexto del
 * módulo.
 *
 * El mensaje del error se registra REDACTADO. Un error de base de datos
 * arrastra en su mensaje la consulta entera con los valores enlazados —el
 * texto del alumno—, y escribirlo tal cual es justo lo que §43 prohíbe.
 *
 * **Solo actúa sobre las rutas del módulo.** El resto del sitio es problema
 * del sitio, y un módulo que secuestrara los errores de todas las páginas
 * sería una sorpresa desagradable para quien lo instale.
 */
final class DiagnosticExceptionSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Prefijo de las rutas del módulo.
   */
  private const ROUTE_PREFIX = 'sales_leadership_diagnostic.';

  /**
   * Rutas que responden JSON, y que por tanto deben fallar en JSON.
   *
   * Se enumeran en lugar de deducirlo de la petición: el navegador puede
   * mandar las cabeceras que quiera, y una lista explícita no se equivoca.
   *
   * @var string[]
   */
  private const JSON_ROUTES = [
    self::ROUTE_PREFIX . 'session_message',
    self::ROUTE_PREFIX . 'studio_message',
  ];

  /**
   * Canal de registro del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   *
   * La prioridad no es arbitraria. El registro del núcleo corre a 50 y los
   * constructores de respuesta a 0: en 20 se llega después de que el error
   * quede anotado por el sitio y antes de que Drupal componga su página, que
   * es justo lo que se quiere reemplazar.
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => [['onException', 20]]];
  }

  /**
   * Convierte un error imprevisto en una respuesta presentable.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();

    // Un 403 o un 404 no son fallos: son respuestas con significado, y Drupal
    // ya las presenta bien. Tocarlas rompería el control de acceso, que es
    // justamente lo que sostiene el aislamiento entre alumnos.
    if ($exception instanceof HttpExceptionInterface) {
      return;
    }

    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route');

    if (!str_starts_with($route, self::ROUTE_PREFIX)) {
      return;
    }

    $this->registrar($exception, $request, $route);

    $event->setResponse($this->responder($route));
    // Se detiene la propagación para que Drupal no vuelva a componer la suya
    // encima. El registro del núcleo, que corre antes, ya ha ocurrido.
    $event->stopPropagation();
  }

  /**
   * Deja constancia con el contexto del módulo.
   *
   * Se anotan la ruta, la clase y el mensaje del error; NUNCA el contenido de
   * la conversación ni el del diagnóstico (§43). El identificador de sesión sí
   * va: es lo que permite a soporte localizar el caso sin leer nada.
   */
  private function registrar(\Throwable $exception, Request $request, string $route): void {
    $sesion = $request->attributes->get('sld_diagnostic_session');

    $this->logger->error(
      'Error imprevisto en la ruta @ruta@sesion: @clase: @mensaje (@archivo:@linea).',
      [
        '@ruta' => $route,
        '@sesion' => $sesion === NULL ? '' : ', sesión ' . (is_object($sesion) ? $sesion->id() : $sesion),
        '@clase' => get_class($exception),
        // El mensaje pasa por el redactor: no es una etiqueta escrita por un
        // programador, es texto no fiable. El de un error de base de datos
        // arrastra la consulta con sus valores, o sea el texto del alumno.
        '@mensaje' => ExceptionRedactor::redact($exception),
        '@archivo' => $exception->getFile(),
        '@linea' => $exception->getLine(),
      ],
    );
  }

  /**
   * La respuesta que recibe quien se encontró el error.
   *
   * Es deliberadamente autónoma: no pasa por el sistema de temas ni consulta
   * configuración. Una página de error que depende de la misma maquinaria que
   * acaba de fallar puede fallar por lo mismo, y entonces el alumno se queda
   * peor que al principio. Se renuncia al marco con la marca a cambio de que
   * esto no pueda romperse.
   */
  private function responder(string $route): Response {
    $mensaje = (string) $this->t('No hemos podido completar esta operación. Vuelve a intentarlo en unos minutos; si sigue ocurriendo, avísanos.');

    if (in_array($route, self::JSON_ROUTES, TRUE)) {
      return new JsonResponse(
        ['error' => $mensaje],
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }

    return new Response(
      $this->pagina($mensaje),
      Response::HTTP_INTERNAL_SERVER_ERROR,
      ['Content-Type' => 'text/html; charset=UTF-8'],
    );
  }

  /**
   * Página mínima, con salida hacia el panel.
   *
   * Lo importante no es que sea bonita sino que diga algo cierto en el idioma
   * del alumno y ofrezca por dónde seguir. La alternativa era la página del
   * sitio, que no hace ninguna de las dos cosas.
   */
  private function pagina(string $mensaje): string {
    $titulo = (string) $this->t('Algo no ha ido bien');
    $volver = (string) $this->t('Volver a mi panel');

    return <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>{$titulo}</title>
      <style>
        body { margin: 0; display: grid; min-height: 100vh; place-items: center;
               background: #222254; color: #404040;
               font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 34rem; margin: 1.5rem; padding: 2.5rem;
               border-radius: 12px; background: #ffffff; }
        h1 { margin: 0 0 1rem; font-size: 1.5rem; }
        p { margin: 0 0 1.5rem; line-height: 1.6; }
        a { display: inline-block; padding: 0.7rem 1.4rem; border-radius: 6px;
            background: #222254; color: #ffffff; font-weight: 600;
            text-decoration: none; }
        a:focus-visible { outline: 2px solid #222254; outline-offset: 2px; }
      </style>
    </head>
    <body>
      <main>
        <h1>{$titulo}</h1>
        <p>{$mensaje}</p>
        <a href="/sales-diagnostic">{$volver}</a>
      </main>
    </body>
    </html>
    HTML;
  }

}
