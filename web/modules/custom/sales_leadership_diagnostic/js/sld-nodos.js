/**
 * @file
 * Red de nodos animada detrás del panel: el estilo «AI Sales Agents».
 *
 * La misma de la landing de la membresía, con tres cuidados que allí faltaban:
 *
 *  - Quien pide menos movimiento en su sistema recibe un cuadro quieto.
 *  - Con la pestaña oculta se detiene: no gasta batería dibujando para nadie.
 *  - Es decorativa: oculta a los lectores de pantalla y sin puntero, así que no
 *    tapa ni un clic.
 *
 * Solo se carga en la ruta del panel (librería `agentes`).
 */
((Drupal, once) => {
  Drupal.behaviors.sldNodos = {
    attach(context) {
      once('sld-nodos', '.sld-home--agentes .sld-home__frame', context).forEach((marco) => {
        const lienzo = document.createElement('canvas');
        lienzo.className = 'sld-nodos';
        lienzo.setAttribute('aria-hidden', 'true');
        marco.prepend(lienzo);

        const ctx = lienzo.getContext('2d');
        if (!ctx) {
          return;
        }

        const quieto = window.matchMedia('(prefers-reduced-motion: reduce)');
        const RADIO = 150;
        let nodos = [];
        let ancho = 0;
        let alto = 0;
        let dpr = 1;
        let cuadro = 0;

        const medir = () => {
          const caja = lienzo.getBoundingClientRect();
          if (!caja.width) {
            return;
          }
          dpr = Math.min(window.devicePixelRatio || 1, 2);
          ancho = caja.width;
          alto = caja.height;
          lienzo.width = Math.round(ancho * dpr);
          lienzo.height = Math.round(alto * dpr);

          const cuantos = Math.round(Math.min(46, Math.max(18, (ancho * alto) / 18000)));
          if (nodos.length !== cuantos) {
            nodos = Array.from({ length: cuantos }, () => ({
              x: Math.random() * ancho,
              y: Math.random() * alto,
              vx: (Math.random() - 0.5) * 0.16,
              vy: (Math.random() - 0.5) * 0.16,
              lima: Math.random() < 0.18,
            }));
          }
        };

        const pintar = (mover) => {
          ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
          ctx.clearRect(0, 0, ancho, alto);

          nodos.forEach((p) => {
            if (mover) {
              p.x += p.vx;
              p.y += p.vy;
            }
            // Lo que sale por un borde entra por el opuesto.
            if (p.x < 0) {
              p.x = ancho;
            }
            if (p.x > ancho) {
              p.x = 0;
            }
            if (p.y < 0) {
              p.y = alto;
            }
            if (p.y > alto) {
              p.y = 0;
            }
          });

          for (let i = 0; i < nodos.length; i++) {
            for (let j = i + 1; j < nodos.length; j++) {
              const d = Math.hypot(nodos[i].x - nodos[j].x, nodos[i].y - nodos[j].y);
              if (d < RADIO) {
                const a = (1 - d / RADIO) * 0.3;
                ctx.strokeStyle = nodos[i].lima || nodos[j].lima
                  ? `rgba(166,255,77,${a * 0.7})`
                  : `rgba(78,226,242,${a})`;
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(nodos[i].x, nodos[i].y);
                ctx.lineTo(nodos[j].x, nodos[j].y);
                ctx.stroke();
              }
            }
          }

          nodos.forEach((p) => {
            ctx.fillStyle = p.lima ? 'rgba(166,255,77,0.85)' : 'rgba(78,226,242,0.75)';
            ctx.fillRect(p.x - 1.4, p.y - 1.4, 2.8, 2.8);
          });
        };

        const bucle = () => {
          pintar(true);
          cuadro = window.requestAnimationFrame(bucle);
        };

        const arrancar = () => {
          window.cancelAnimationFrame(cuadro);
          cuadro = 0;
          if (quieto.matches || document.hidden) {
            pintar(false);
            return;
          }
          bucle();
        };

        medir();
        arrancar();

        let espera;
        window.addEventListener('resize', () => {
          window.clearTimeout(espera);
          espera = window.setTimeout(() => {
            medir();
            if (!cuadro) {
              pintar(false);
            }
          }, 150);
        });
        document.addEventListener('visibilitychange', arrancar);
        if (quieto.addEventListener) {
          quieto.addEventListener('change', arrancar);
        }
      });
    },
  };
})(Drupal, once);
