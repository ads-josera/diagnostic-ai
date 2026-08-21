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
  function initChat(root) {
    const settings = drupalSettings.salesLeadershipDiagnostic || {};
    const log = root.querySelector('[data-sld-log]');
    const composer = root.querySelector('[data-sld-composer]');
    const typing = root.querySelector('[data-sld-typing]');
    const errorBox = root.querySelector('[data-sld-error]');

    scrollToEndWhenSettled(log);

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
