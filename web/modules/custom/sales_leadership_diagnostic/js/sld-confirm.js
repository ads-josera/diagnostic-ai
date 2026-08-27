/**
 * @file
 * Confirmación antes de una acción que no se puede deshacer.
 *
 * Existe por un descubrimiento incómodo: el botón «Publicar» del estudio
 * llevaba el atributo `data-sld-confirm` desde el principio y NUNCA hubo nada
 * que lo leyera. Parecía protegido y no lo estaba. Publicar cambia el prompt
 * con el que conversarán todos los alumnos de ese agente a partir de ese
 * momento, y hasta ahora bastaba un clic distraído.
 *
 * Se escucha el envío del FORMULARIO y no el clic del botón. Un formulario
 * también se envía pulsando Intro en un campo de texto, y ahí no hay ningún
 * clic que interceptar: el estudio tiene un campo «Versión» de una sola línea,
 * que es exactamente donde eso pasa.
 *
 * El mensaje viaja en el atributo en lugar de estar escrito aquí, para que
 * quien añada otra acción delicada no tenga que tocar este archivo ni acabe
 * reutilizando un texto que no le corresponde.
 */

((Drupal, once) => {
  'use strict';

  /**
   * Pregunta antes de dejar pasar el envío.
   *
   * `event.submitter` dice qué botón lo provocó, que es lo que permite
   * distinguir «Publicar» de «Guardar borrador» dentro del mismo formulario.
   * Si el navegador no lo informara, se deja pasar: bloquear el envío por no
   * poder preguntar dejaría la pantalla inservible, y esto es una red de
   * seguridad, no un control de acceso — quien decide de verdad si se puede
   * publicar es el servidor.
   */
  const initConfirm = (form) => {
    form.addEventListener('submit', (event) => {
      const boton = event.submitter;

      if (!boton) {
        return;
      }

      const pregunta = boton.getAttribute('data-sld-confirm');

      if (!pregunta) {
        return;
      }

      if (!window.confirm(pregunta)) {
        event.preventDefault();
      }
    });
  };

  Drupal.behaviors.salesLeadershipDiagnosticConfirm = {
    attach(context) {
      // Se buscan los BOTONES y se sube a su formulario, en lugar de
      // seleccionar el formulario con `:has()`. El selector sería más corto,
      // pero esto no depende de nada moderno y se comporta igual en
      // cualquier navegador que abra un gestor.
      //
      // El conjunto evita enganchar dos veces el mismo formulario cuando
      // tiene más de un botón con confirmación.
      const formularios = new Set();

      once('sld-confirm', '[data-sld-confirm]', context).forEach((boton) => {
        const formulario = boton.closest('form');

        if (formulario) {
          formularios.add(formulario);
        }
      });

      formularios.forEach(initConfirm);
    },
  };
})(Drupal, once);
