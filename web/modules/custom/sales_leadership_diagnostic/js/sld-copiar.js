/**
 * @file
 * Copia al portapapeles el mensaje preparado de una cuenta del GOLD Pack.
 *
 * Su metodologia llama a esto «copy/paste»: el mensaje esta escrito para
 * enviarse tal cual, y obligar a seleccionarlo a mano invita a perder un salto
 * de linea o a cortarlo a medias.
 *
 * Si el navegador no deja copiar —sin permiso, sin HTTPS, o con la API
 * ausente— el boton lo DICE en vez de fingir que funciono. Un control que no
 * hace nada es peor que no ponerlo: quien lo usa concluye que el sistema esta
 * roto, y tiene razon.
 */

((Drupal, once) => {
  'use strict';

  /**
   * Deja el boton diciendo que paso, y lo devuelve a su sitio.
   *
   * @param {HTMLElement} boton
   *   El boton pulsado.
   * @param {string} texto
   *   Lo que debe leerse durante unos segundos.
   * @param {boolean} bien
   *   Si la copia salio o no.
   */
  function avisar(boton, texto, bien) {
    const original = boton.dataset.sldOriginal || boton.textContent;

    boton.dataset.sldOriginal = original;
    boton.textContent = texto;

    if (bien) {
      boton.dataset.sldCopied = 'si';
    }

    window.setTimeout(() => {
      boton.textContent = original;
      delete boton.dataset.sldCopied;
    }, 2500);
  }

  Drupal.behaviors.sldCopiarMensaje = {
    attach(context) {
      once('sld-copiar', '[data-sld-copy]', context).forEach((boton) => {
        boton.addEventListener('click', async () => {
          // El mensaje es el hermano del encabezado que contiene el boton.
          const caja = boton.closest('.sld-pack__message');
          const cuerpo = caja && caja.querySelector('.sld-pack__message-body');

          if (!cuerpo) {
            return;
          }

          try {
            await navigator.clipboard.writeText(cuerpo.textContent);
            avisar(boton, Drupal.t('Copiado'), true);
          }
          catch (e) {
            avisar(boton, Drupal.t('Selecciónalo y copia'), false);
          }
        });
      });
    },
  };
})(Drupal, once);
