/**
 * @file
 * Comportamiento de la interfaz conversacional (§23, §24).
 *
 * Vanilla JS sobre Drupal.behaviors: el chat son unas doscientas líneas y un
 * framework añadiría un paso de compilación y dependencias sin aportar nada.
 *
 * Reglas que este archivo respeta y conviene no romper:
 *
 *  - Ninguna llamada sale hacia el proveedor de IA. Todo pasa por Drupal.
 *  - El HTML del agente llega ya saneado desde el servidor. Aquí nunca se
 *    interpreta Markdown ni se construye marcado a partir de texto del modelo.
 *  - El bloqueo del botón mientras hay una petición en vuelo es comodidad, no
 *    seguridad: el control real es el bloqueo por sesión en el servidor.
 */

((Drupal, once, drupalSettings) => {
  'use strict';

  /**
   * Mensaje único que ve el alumno ante cualquier fallo técnico (§58).
   */
  const GENERIC_ERROR = Drupal.t(
    'No hemos podido procesar tu solicitud en este momento. Por favor intenta nuevamente.',
  );

  /**
   * Token CSRF de sesión, cacheado tras la primera petición.
   */
  let csrfToken = null;

  /**
   * Obtiene el token CSRF que exige el endpoint de mensajes.
   */
  async function getCsrfToken(url) {
    if (csrfToken !== null) {
      return csrfToken;
    }

    const response = await fetch(url, { credentials: 'same-origin' });

    if (!response.ok) {
      throw new Error('csrf');
    }

    csrfToken = (await response.text()).trim();

    return csrfToken;
  }

  /**
   * Ajusta el alto del textarea a su contenido, hasta el máximo del CSS.
   */
  function autoGrow(input) {
    input.style.height = 'auto';
    input.style.height = `${input.scrollHeight}px`;
  }

  /**
   * Lleva la conversación al final.
   */
  function scrollToEnd(log) {
    log.scrollTop = log.scrollHeight;
  }

  /**
   * Lleva la conversación al final una vez el layout se ha estabilizado.
   *
   * Hacerlo solo al inicializar deja el último mensaje cortado: las fuentes web
   * llegan después y cambian la altura del contenido, de modo que la posición
   * calculada deja de ser el final. Se repite tras el siguiente reflujo y de
   * nuevo cuando las fuentes están listas.
   */
  function scrollToEndWhenSettled(log) {
    // Con la pantalla de bienvenida no se desplaza: llevar al final recortaría
    // por arriba justo lo primero que hay que leer —el icono y la frase que
    // explican de qué va esto—. Solo tiene sentido ir al final cuando hay
    // conversación, que es donde lo último es lo relevante.
    if (log.querySelector('.sld-chat__welcome')) {
      return;
    }

    scrollToEnd(log);

    requestAnimationFrame(() => {
      requestAnimationFrame(() => scrollToEnd(log));
    });

    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(() => scrollToEnd(log)).catch(() => {});
    }
  }

  /**
   * Añade un mensaje al registro.
   *
   * `html` solo se usa para mensajes del agente, y siempre con marcado que ya
   * saneó el servidor. Para el alumno se usa `text`, que se inserta como nodo
   * de texto y por tanto no puede introducir marcado.
   */
  function appendMessage(log, { role, author, time, text, html }) {
    const empty = log.querySelector('.sld-chat__empty');
    if (empty) {
      empty.remove();
    }

    // La pantalla de bienvenida cumple su función hasta el primer turno. Si se
    // dejara puesta, el alumno seguiría viendo botones de «empezar» sobre una
    // conversación ya empezada, y pulsarlos mandaría un segundo mensaje de
    // arranque en mitad del diagnóstico.
    const welcome = log.querySelector('.sld-chat__welcome');
    if (welcome) {
      welcome.remove();
    }

    const article = document.createElement('article');
    article.className = `sld-chat__message sld-chat__message--${role}`;

    const meta = document.createElement('div');
    meta.className = 'sld-chat__meta';

    const authorEl = document.createElement('span');
    authorEl.className = 'sld-chat__author';
    authorEl.textContent = author;

    const timeEl = document.createElement('time');
    timeEl.className = 'sld-chat__time';
    timeEl.textContent = time;

    meta.append(authorEl, timeEl);

    const bubble = document.createElement('div');
    bubble.className = 'sld-chat__bubble';

    if (typeof html === 'string') {
      bubble.innerHTML = html;
    }
    else {
      bubble.textContent = text;
    }

    article.append(meta, bubble);
    log.append(article);

    return article;
  }

  /**
   * Formatea la hora del mensaje optimista con la configuración del navegador.
   */
  function nowLabel() {
    return new Date().toLocaleString();
  }

  /**
   * Inicializa un chat.
   */
  /**
   * Cada cuanto se pregunta como va, y cuanto se espera como maximo.
   *
   * Dos segundos es lo bastante frecuente para que la cuenta de busquedas se
   * mueva a la vista, y lo bastante espaciado para no castigar al servidor
   * durante una mision de veinte minutos.
   *
   * El maximo son treinta minutos. Mas alla, algo se rompio: es preferible
   * decirlo a dejar a alguien mirando indefinidamente.
   */
  const INTERVALO_SONDEO = 2000;
  const ESPERA_MAXIMA = 30 * 60 * 1000;

  /**
   * Espera a que termine un turno que corre en segundo plano.
   *
   * Vive FUERA de initChat porque hacen falta dos caminos y tienen que ser el
   * mismo: el de quien acaba de enviar un mensaje, y el de quien carga la
   * pagina con un turno ya corriendo —porque recargo, cerro la pestana y
   * volvio, o entro desde otro sitio—.
   *
   * El segundo camino faltaba hasta el 10-09-2026, y el sintoma era el peor
   * posible: la pantalla decia «se esta generando tu resultado» y NO volvia a
   * preguntar nunca. El trabajo terminaba y nadie se enteraba. Desde fuera,
   * «esta pensando» y «esta muerto» se ven exactamente igual.
   *
   * Mientras espera, ensena cuantas busquedas lleva hechas. No es un adorno:
   * es la diferencia entre una espera y una espera que se entiende. Un
   * indicador que no se mueve durante quince minutos se lee como «se colgo», y
   * la persona cierra la pestana justo cuando el trabajo iba bien.
   *
   * @param {object} settings
   *   Ajustes del modulo; hace falta statusEndpoint.
   * @param {HTMLElement} log
   *   Donde colgar el aviso de que se esta trabajando.
   * @param {Function} onError
   *   Que hacer si no se pudo terminar. Recibe el texto ya traducido.
   *
   * @return {Promise<object|null>}
   *   El estado final, o null si no se pudo terminar.
   */
  const esperarTurno = async (settings, log, onError) => {
    if (!settings.statusEndpoint) {
      onError(GENERIC_ERROR);
      return null;
    }

    // Se reutiliza el indicador de «analizando», con sus puntos y su version
    // sin movimiento. Inventar aqui un aviso distinto daria dos cosas que
    // hacen lo mismo y se ven distinto, que es de los defectos que mas se
    // notan; y, sobre todo, un texto quieto durante minutos se lee como
    // colgado. Es el mismo problema que el turno que nadie sondeaba, esta vez
    // en lo visual.
    const aviso = document.createElement('div');
    aviso.className = 'sld-chat__typing sld-chat__working';
    aviso.setAttribute('role', 'status');

    const puntos = document.createElement('span');
    puntos.className = 'sld-chat__typing-dots';
    puntos.setAttribute('aria-hidden', 'true');
    puntos.innerHTML = '<span></span><span></span><span></span>';

    const etiqueta = document.createElement('span');
    etiqueta.className = 'sld-chat__typing-label';
    etiqueta.textContent = Drupal.t('Investigando');

    aviso.append(puntos, etiqueta);
    log.appendChild(aviso);
    scrollToEnd(log);

    const hasta = Date.now() + ESPERA_MAXIMA;

    while (Date.now() < hasta) {
      await new Promise((resolver) => window.setTimeout(resolver, INTERVALO_SONDEO));

      let estado;

      try {
        const respuesta = await fetch(settings.statusEndpoint, { credentials: 'same-origin' });

        if (!respuesta.ok) {
          aviso.remove();
          onError(GENERIC_ERROR);
          return null;
        }

        estado = await respuesta.json();
      }
      catch (error) {
        // Un fallo de red suelto no cancela la espera: el trabajo sigue
        // corriendo en el servidor y la siguiente vuelta puede funcionar.
        continue;
      }

      if (!estado.processing) {
        aviso.remove();
        return estado;
      }

      // Se toca la ETIQUETA, no el aviso: escribir sobre el aviso entero se
      // llevaria por delante los puntos.
      etiqueta.textContent = estado.searches > 0
        ? Drupal.formatPlural(estado.searches, 'Investigando · 1 búsqueda hecha', 'Investigando · @count búsquedas hechas')
        : Drupal.t('Investigando');
    }

    aviso.remove();
    onError(Drupal.t('La investigación está tardando más de lo normal. Recarga la página en unos minutos: el trabajo sigue en marcha y no se ha perdido.'));

    return null;
  };

  function initChat(root) {
    const settings = drupalSettings.salesLeadershipDiagnostic || {};
    const log = root.querySelector('[data-sld-log]');
    const composer = root.querySelector('[data-sld-composer]');
    const typing = root.querySelector('[data-sld-typing]');
    const errorBox = root.querySelector('[data-sld-error]');

    scrollToEndWhenSettled(log);

    /**
     * La página cargó con un turno YA corriendo.
     *
     * Pasa cada vez que alguien recarga, cierra la pestaña y vuelve, o entra
     * desde su panel mientras la misión se cocina. Sin esto, la pantalla se
     * queda con un aviso fijo que no vuelve a cambiar nunca: el trabajo
     * termina y nadie se entera.
     *
     * Se recarga al acabar en vez de pintar el mensaje a mano, porque en este
     * camino hay que devolver también el compositor, y el servidor sabe
     * montarlo mejor que nosotros.
     */
    if (settings.processing) {
      const estatico = root.querySelector('[data-sld-processing]');

      // El aviso vivo dice cuántas búsquedas lleva; el fijo no dice nada.
      if (estatico) {
        estatico.hidden = true;
      }

      esperarTurno(settings, log, (mensaje) => {
        if (estatico) {
          estatico.hidden = false;
          estatico.textContent = mensaje;
        }
      }).then((estado) => {
        if (estado) {
          window.location.reload();
        }
      });

      return;
    }

    // Sesión cerrada: no hay compositor que inicializar.
    if (!composer) {
      return;
    }

    const input = composer.querySelector('[data-sld-input]');
    const send = composer.querySelector('[data-sld-send]');
    const hint = root.querySelector('[data-sld-hint]');

    // Sin endpoint, el compositor queda en modo lectura. Es el estado durante
    // la fase en la que la interfaz existe pero la capa de conversación aún
    // no: es preferible a lanzar peticiones contra una ruta inexistente.
    if (!settings.messageEndpoint) {
      input.disabled = true;
      send.disabled = true;
      send.setAttribute('aria-disabled', 'true');
      input.placeholder = Drupal.t('El envío de mensajes todavía no está disponible.');
      if (hint) {
        hint.textContent = Drupal.t('La conversación se activará al completar la integración.');
      }
      return;
    }

    let pending = false;

    const setPending = (value) => {
      pending = value;
      input.disabled = value;
      send.disabled = value;
      send.setAttribute('aria-disabled', String(value));
      typing.hidden = !value;
      if (value) {
        scrollToEnd(log);
      }
    };

    const showError = (message) => {
      errorBox.textContent = message;
      errorBox.hidden = false;
    };

    const clearError = () => {
      errorBox.hidden = true;
      errorBox.textContent = '';
    };

    const submit = async () => {
      if (pending) {
        return;
      }

      const message = input.value.trim();

      if (message === '') {
        return;
      }

      clearError();

      // Se pinta el mensaje del alumno de inmediato: la espera de la IA es
      // larga y ver su propio texto confirma que el envío ocurrió.
      appendMessage(log, {
        role: 'user',
        author: Drupal.t('Tú'),
        time: nowLabel(),
        text: message,
      });

      input.value = '';
      autoGrow(input);
      setPending(true);

      try {
        const token = await getCsrfToken(settings.csrfTokenUrl);

        const response = await fetch(settings.messageEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
          body: JSON.stringify({ message }),
        });

        if (!response.ok) {
          // 409 significa que ya hay un turno en curso para esta sesión: el
          // servidor lo rechaza mediante su bloqueo, no el navegador.
          showError(
            response.status === 409
              ? Drupal.t('Ya hay una respuesta en curso. Espera un momento.')
              : GENERIC_ERROR,
          );
          return;
        }

        const data = await response.json();

        // El turno puede haberse ido a segundo plano. Ocurre cuando el agente
        // tiene capacidad de investigar: una mision que criba cuentas son
        // decenas de busquedas y minutos de trabajo, y eso no cabe en una
        // peticion web. En ese caso aqui no hay respuesta todavia: hay que
        // preguntar cada pocos segundos hasta que la haya.
        if (data.processing) {
          const resultado = await esperarTurno(settings, log, showError);

          if (resultado === null) {
            return;
          }

          appendMessage(log, {
            role: 'assistant',
            author: Drupal.t('Diagnostic AI'),
            time: nowLabel(),
            html: resultado.message_html,
          });

          if (resultado.session_status && resultado.session_status !== 'in_progress') {
            window.location.reload();
          }

          return;
        }

        appendMessage(log, {
          role: 'assistant',
          author: Drupal.t('Diagnostic AI'),
          time: data.time || nowLabel(),
          html: data.message_html,
        });

        if (data.session_status && data.session_status !== 'in_progress') {
          // El estado lo decide el servidor. Recargar es la forma más simple y
          // fiable de reflejar una sesión que ha cambiado de estado.
          window.location.reload();
          return;
        }
      }
      catch (error) {
        showError(GENERIC_ERROR);
      }
      finally {
        setPending(false);
        scrollToEnd(log);
        input.focus();
      }
    };

    composer.addEventListener('submit', (event) => {
      event.preventDefault();
      submit();
    });

    /**
     * Sugerencias de la pantalla de bienvenida.
     *
     * Rellenan el campo y envían, en lugar de enviar directamente: así el
     * alumno ve en el compositor lo mismo que va a mandarse, y si la petición
     * falla el texto sigue ahí para reintentar en vez de haberse evaporado.
     *
     * El texto se toma del atributo de datos y no de la etiqueta visible,
     * porque el navegador recorta espacios y saltos al leer textContent y el
     * mensaje llegaría distinto de como se redactó.
     */
    root.querySelectorAll('[data-sld-suggestion]').forEach((button) => {
      button.addEventListener('click', () => {
        if (pending) {
          return;
        }

        input.value = button.getAttribute('data-sld-suggestion') || '';
        autoGrow(input);
        submit();
      });
    });

    input.addEventListener('input', () => autoGrow(input));

    input.addEventListener('keydown', (event) => {
      // Enter envía; Mayús+Enter inserta un salto de línea. Es la convención
      // de los asistentes conversacionales y lo que el usuario espera.
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
      }
    });
  }

  Drupal.behaviors.salesLeadershipDiagnosticChat = {
    attach(context) {
      once('sld-chat', '[data-sld-chat]', context).forEach(initChat);
    },
  };
})(Drupal, once, drupalSettings);
