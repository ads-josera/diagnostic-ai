<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Branding\HomePage;
use Drupal\sales_leadership_diagnostic\Service\Manager\ManagerNavigation;

/**
 * Plantillas que aporta el módulo.
 *
 * Viven en el módulo y no en el tema del sitio: §4 exige que la lógica y la
 * presentación del diagnóstico sean autocontenidas, de modo que cambiar el
 * tema del sitio no rompa la experiencia ni obligue a tocar el módulo.
 */
final class DiagnosticThemeHooks {

  /**
   * Ruta que usa la plantilla de página dedicada.
   */
  private const CHAT_ROUTE = 'sales_leadership_diagnostic.session';

  /**
   * Ruta de la portada, que usa su propio marco de pagina.
   */
  private const HOME_ROUTE = 'sales_leadership_diagnostic.welcome';

  /**
   * Rutas internas del alumno: comparten marco entre ellas y con la portada.
   *
   * Decisión del cliente, 23-08-2026: todo lo que ve el alumno —portada,
   * inicio de sesion, panel y resultado— debe verse como una sola
   * experiencia. Antes el panel y el resultado eran paginas normales del
   * sitio; ahora prescinden del marco generico de Drupal igual que las otras.
   *
   * Las dos usan la MISMA plantilla de pagina, porque su marco es identico.
   *
   * @var string[]
   */
  /**
   * La pantalla de resultado, que comparten el alumno y el gestor.
   */
  private const RESULT_ROUTE = 'sales_leadership_diagnostic.result';

  private const INNER_ROUTES = [
    'sales_leadership_diagnostic.dashboard',
    'sales_leadership_diagnostic.agent_page',
    'sales_leadership_diagnostic.result',
    'sales_leadership_diagnostic.accounts',
  ];

  /**
   * Pantallas del GESTOR, que llevan su propio marco.
   *
   * Su rol tiene «ver el tema de administracion» pero NO «acceder a la barra
   * de herramientas», asi que sus paginas se servian con el tema de
   * administracion y sin la barra que trae la navegacion y el cerrar sesion.
   * No habia forma de salir desde ninguna de sus secciones.
   *
   * Se enumeran una a una y no por prefijo de URL a proposito: por el prefijo
   * entrarian tambien las pantallas de solo-administrador —secretos, marca,
   * portada—, que no son suyas y no deben cambiar de aspecto.
   *
   * @var string[]
   */
  private const MANAGER_ROUTES = [
    'sales_leadership_diagnostic.admin_results',
    'sales_leadership_diagnostic.usage',
    'sales_leadership_diagnostic.studio',
    'sales_leadership_diagnostic.studio_agent',
    'sales_leadership_diagnostic.knowledge',
    'sales_leadership_diagnostic.knowledge_agent',
    'entity.sld_agent.collection',
    'entity.sld_agent.add_form',
    'entity.sld_agent.edit_form',
    'entity.sld_agent.delete_form',
  ];

  /**
   * Rutas de inicio de sesion que comparten el marco de la portada.
   *
   * Se incluye tambien la peticion de contrasena: son la misma puerta y
   * dejarla con el marco generico de Drupal habria delatado justo lo que este
   * trabajo pretende ocultar.
   *
   * @var string[]
   */
  private const LOGIN_ROUTES = [
    'user.login',
    'user.pass',
    'user.reset.form',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly HomePage $home,
    private readonly AgentRegistry $agents,
    private readonly ManagerNavigation $navegacion,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'sld_dashboard' => [
        'variables' => [
          'user_name' => '',
          'welcome_text' => NULL,
          'can_start' => FALSE,
          // Una entrada por agente disponible. Vacío significa que el alumno
          // no tiene derecho a ninguno, que es distinto de que el sistema no
          // esté listo (`can_start`).
          'agents' => [],
          // Cierto si tiene más de uno. Cambia la forma de la pantalla
          // entera: con varios aparecen las tarjetas y el historial se va a
          // la página de cada agente.
          'multiple_agents' => FALSE,
          // Cierto solo si NO se pudo comprobar la autorización. Es distinto
          // de no tener derecho a ningún agente, y al alumno hay que decirle
          // cosas distintas.
          'cannot_verify' => FALSE,
          'repeat_notice' => NULL,
          'unavailable_notice' => NULL,
          'expiry_notice' => NULL,
          'history' => [],
          // Enlace y recuento de «Mis cuentas», o NULL si no tiene ninguna.
          'accounts' => NULL,
          // Cierto cuando `history` no es el historial completo sino lo que
          // quedó sin página de agente donde salir. Lo normal es que entonces
          // esté vacío, y la sección desaparece.
          'history_is_leftover' => FALSE,
          // Lo que el sistema recuerda del alumno. Vacio en su primera visita
          // y mientras no termine ningun diagnostico.
          'memory' => [],
          'memory_forget_all_url' => '',
        ],
      ],
      // Página de UN agente. Solo la ve el alumno con varios: teniendo uno
      // solo, el panel lo enseña entero y no hay segundo nivel.
      'sld_agent_page' => [
        'variables' => [
          'agent_label' => '',
          'agent_description' => '',
          'icon_url' => NULL,
          'intro' => NULL,
          // Identificador de la conversación a medias con ESTE agente, si la
          // hay. Solo cambia el texto del botón.
          'resume_session_id' => NULL,
          'start_url' => '',
          'dashboard_url' => '',
          'repeat_notice' => NULL,
          // Historial de este agente. No lleva columna «Agente» a propósito:
          // la página entera ya dice de quién es.
          'history' => [],
        ],
      ],
      'sld_chat' => [
        'variables' => [
          'session_id' => 0,
          // El nombre del agente, encima de la bienvenida.
          'welcome_title' => NULL,
          'welcome_icon' => NULL,
          'welcome_intro' => NULL,
          'welcome_suggestions' => [],
          'status' => '',
          'status_label' => '',
          'accepts_messages' => FALSE,
          'messages' => [],
        ],
      ],
      'sld_studio' => [
        'variables' => [
          'form' => NULL,
          'session_id' => 0,
          'messages' => [],
          'reset_url' => '',
        ],
      ],
      // Pantalla de consumo del gestor. Se mira de un vistazo, así que el
      // orden de lectura es parte de lo que hay que diseñar y vive en su
      // plantilla, no en el orden en que el controlador arme unas tablas.
      'sld_usage' => [
        'variables' => [
          // Cómo va el tope de gasto. Nulo cuando nadie ha fijado uno, que no
          // es lo mismo que estar al 0 %.
          'budget' => NULL,
          'periods' => [],
          // Búsquedas externas. Nulo si no ha habido ninguna: un panel a cero
          // ocupa sitio y no dice nada.
          'searches' => NULL,
          // Evidencia reutilizable. Nulo si no hay nada anotado.
          'ledger' => NULL,
          'has_data' => FALSE,
          'totals' => [],
          'agents' => [],
          'people' => [],
          'calls' => [],
        ],
      ],
      'sld_result' => [
        'variables' => [
          // El titulo lo imprime la plantilla y no el bloque del tema: ese
          // bloque vive en la region `content_above`, que el marco interno no
          // pinta. Sin esta variable la pagina se quedaba sin encabezado.
          'title' => '',
          'summary' => NULL,
          'score' => NULL,
          // Banda de madurez, confianza global y tabla por dimensión. Vacías
          // en los diagnósticos anteriores al 26-08-2026, que no las
          // guardaban: su tabla quedó solo en la conversación.
          'maturity' => '',
          'confidence' => '',
          'dimensions' => [],
          // El Weekly GOLD Pack, cuenta por cuenta, y el pool cribado. Vacíos
          // en los resultados anteriores al 10-09-2026 y en los del agente de
          // diagnóstico, que no produce cuentas.
          'accounts' => [],
          'pool' => NULL,
          // A dónde ir a registrar qué pasó con las cuentas. Solo para su
          // dueño: el gestor que mira el Pack de otro no registra nada.
          'accounts_url' => NULL,
          'sections' => [],
          'version' => '',
          'created' => '',
          // A donde vuelve quien mira, con su texto: el alumno a su panel, el
          // gestor al listado. Antes el enlace era fijo y dejaba al gestor en
          // una pantalla que no es suya.
          'back' => ['url' => '', 'label' => ''],
        ],
      ],
      // «Mis cuentas». Ver AccountsController.
      'sld_accounts' => [
        'variables' => [
          'accounts' => [],
          'view' => 'todas',
          'tabs' => [],
          'figures' => [],
          'quick_states' => [],
          'other_states' => [],
          'truths' => [],
          'note_max' => 280,
          'dashboard_url' => '',
        ],
      ],
      'sld_welcome' => [
        'variables' => [
          'background' => NULL,
          'header_logo' => NULL,
          'footer_logo' => NULL,
          'title' => NULL,
          'intro' => NULL,
          'button_label' => '',
          'help_text' => NULL,
          'accent_color' => '',
          'band_color' => '',
          'origin_url' => NULL,
          'has_access' => FALSE,
        ],
      ],
      'sld_sso_denied' => [
        'variables' => [
          'message' => '',
        ],
      ],
      // Variante de `page` para la vista de conversación. `base hook` hace que
      // se le apliquen los mismos preprocesos que a cualquier página.
      'page__sales_diagnostic_chat' => [
        'template' => 'page--sales-diagnostic-chat',
        'base hook' => 'page',
      ],
      // La portada tambien prescinde del marco del tema: es lo primero que ve
      // quien llega por un enlace suelto, y con la cabecera generica de Drupal
      // parecia una instalacion a medio terminar.
      'page__sales_diagnostic_home' => [
        'template' => 'page--sales-diagnostic-home',
        'base hook' => 'page',
      ],
      // El inicio de sesion reutiliza el marco de la portada. Drupal ya busca
      // por si mismo la sugerencia page--user-login, asi que basta con
      // declarar la plantilla para que la encuentre.
      'page__user__login' => [
        'template' => 'page--user-login',
        'base hook' => 'page',
      ],
      // Marco de las pantallas del gestor: la barra del cliente alrededor del
      // contenido de administracion, que se deja como esta.
      'page__sld_manager' => [
        'template' => 'page--sld-manager',
        'base hook' => 'page',
      ],
      // Marco compartido por el panel y el resultado. Una sola plantilla para
      // las dos: su marco es identico y el contenido de la tarjeta lo ponen
      // sus controladores con `sld_dashboard` y `sld_result`.
      'page__sld_inner' => [
        'template' => 'page--sld-inner',
        'base hook' => 'page',
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_page_alter().
   */
  #[Hook('theme_suggestions_page_alter')]
  public function themeSuggestionsPageAlter(array &$suggestions): void {
    $routeName = $this->routeMatch->getRouteName();

    if ($routeName === self::CHAT_ROUTE) {
      $suggestions[] = 'page__sales_diagnostic_chat';
    }

    if ($routeName === self::HOME_ROUTE) {
      $suggestions[] = 'page__sales_diagnostic_home';
    }

    if (in_array($routeName, self::INNER_ROUTES, TRUE)) {
      $suggestions[] = 'page__sld_inner';
    }

    if (in_array($routeName, self::LOGIN_ROUTES, TRUE)) {
      $suggestions[] = 'page__user__login';
    }

    if (in_array($routeName, self::MANAGER_ROUTES, TRUE)) {
      $suggestions[] = 'page__sld_manager';
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for page.
   *
   * Lleva a las plantillas que comparten el marco de la portada —inicio de
   * sesion y panel del alumno— las imagenes y los colores que ya administra
   * la portada. Son la misma experiencia en momentos distintos: duplicar los
   * ajustes habria obligado a subir las imagenes varias veces y a acordarse
   * de cambiarlas en varios sitios.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    // El nombre del agente para la barra del chat.
    //
    // Va ANTES de la comprobación del marco de portada porque la conversación
    // no usa ese marco y se quedaría sin título. Estuvo escrito a mano en la
    // plantilla —«Sales Leadership Diagnostic AI»— de cuando había un solo
    // agente, y era la TERCERA copia del mismo nombre: la ruta, la plantilla
    // de página y el título de la pestaña. Con dos agentes, quien hablaba con
    // el de prospección leía arriba el nombre del otro.
    $sesion = $this->routeMatch->getParameter('sld_diagnostic_session');

    if ($sesion instanceof DiagnosticSessionInterface) {
      $variables['sld_page_title'] = $this->agents->get($sesion->getAgentId())?->label() ?? '';
    }

    // Las secciones del gestor. Van SIEMPRE que la pantalla lleve su marco,
    // incluidas las de dentro: es lo que impide que editar un agente sea un
    // callejón sin vuelta.
    if (
      in_array($this->routeMatch->getRouteName(), self::MANAGER_ROUTES, TRUE)
      || ($this->routeMatch->getRouteName() === self::RESULT_ROUTE && $this->miraElGestor())
    ) {
      $variables['sld_manager_nav'] = $this->navegacion->secciones();

      // La capa que sube el contenido del tema de administración al lenguaje
      // de la pantalla de consumo. Va aquí y no en cada controlador porque
      // varias de estas pantallas las pinta Drupal —el listado de agentes, el
      // formulario de la entidad— y no pasan por código nuestro.
      //
      // En la pantalla de resultado NO se adjunta: esa la pinta el tema
      // público y ya trae su propia hoja. Ahí lo único que hace falta del
      // gestor es su navegación, que la barra del marco del alumno pinta si
      // le llega.
      if ($this->routeMatch->getRouteName() !== self::RESULT_ROUTE) {
        $variables['#attached']['library'][] = 'sales_leadership_diagnostic/manager';
      }
    }

    if (!$this->usesHomeFrame()) {
      return;
    }

    $variables['sld_background'] = $this->home->getBackgroundUrl();
    $variables['sld_header_logo'] = $this->home->getHeaderLogoUrl();
    $variables['sld_footer_logo'] = $this->home->getFooterLogoUrl();
    $variables['sld_accent_color'] = $this->home->getAccentColor();
    $variables['sld_band_color'] = $this->home->getBandColor();

    // La hoja de estilos de la portada no se carga sola en una ruta que no es
    // del modulo, asi que se adjunta aqui. En el panel se suma a la libreria
    // `dashboard` que ya adjunta el controlador: una trae el marco y la otra
    // el contenido de la tarjeta.
    $variables['#attached']['library'][] = 'sales_leadership_diagnostic/welcome';

    // Sin estas etiquetas, cambiar el fondo o el logotipo no se veria en esta
    // pagina hasta que caducara por otro motivo.
    $variables['#cache']['tags'] = array_merge(
      $variables['#cache']['tags'] ?? [],
      $this->home->getCacheTags(),
    );
  }

  /**
   * Si quien mira el resultado es personal y no su dueño.
   *
   * Se decide por el DUEÑO del resultado y no por el rol: el gestor podría
   * tener también su propio diagnóstico, y al abrir el suyo debe verlo como
   * cualquier alumno. Lo que cambia el marco es mirar el de otra persona.
   */
  private function miraElGestor(): bool {
    $resultado = $this->routeMatch->getParameter('sld_diagnostic_result');

    if (!$resultado instanceof DiagnosticResultInterface) {
      return FALSE;
    }

    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    return (string) \Drupal::currentUser()->id() !== (string) $resultado->getOwnerId();
  }

  /**
   * Si la ruta actual usa el marco compartido de la portada.
   */
  private function usesHomeFrame(): bool {
    return in_array(
      $this->routeMatch->getRouteName(),
      // La conversación va incluida desde el 10-09-2026. Su barra llevaba solo
      // un título y ninguna salida: el alumno podía volver al panel y nada
      // más, sin cerrar sesión desde la pantalla donde más tiempo pasa. Es el
      // mismo defecto que se le arregló al gestor el 04-09-2026, en la
      // pantalla que entonces no se miró.
      [self::CHAT_ROUTE, ...self::LOGIN_ROUTES, ...self::INNER_ROUTES, ...self::MANAGER_ROUTES],
      TRUE,
    );
  }

}
