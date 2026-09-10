/**
 * @file
 * Recorre TODOS los caminos del producto, con la cuenta de cada rol.
 *
 * Existe porque los fallos que llegaron al cliente no eran de logica: eran de
 * recorrido. Una pantalla que se abre pero no tiene salida. Un boton que se
 * pinta pero con el texto del color del fondo. Un formulario que carga bien y
 * revienta al guardar. Ninguno lo ve una prueba unitaria, y ninguno se ve
 * mirando la pantalla en la que se estaba trabajando.
 *
 * Comprueba tres cosas por pantalla, y las tres nacieron de un fallo real:
 *
 *  1. **El codigo que devuelve**, en los dos sentidos. Que cada rol entre donde
 *     debe Y que reciba 403 donde no debe. Comprobar solo lo permitido deja
 *     pasar una pantalla que se abre a quien no toca.
 *  2. **Que tenga salida.** El 04-09-2026 el gestor se quedo sin barra en sus
 *     cuatro secciones; el 10-09-2026 se quedo sin navegacion en cuanto entraba
 *     a editar algo, y la conversacion del alumno no tenia cerrar sesion.
 *  3. **El contraste efectivo** de cada texto sobre su fondo real. El
 *     10-09-2026 un boton salio con texto rojo sobre azul: 1,06 a 1, invisible,
 *     y la captura parecia razonable porque habia un boton con texto de color.
 *
 * Uso:
 * @code
 *   SLD_GESTOR="$(ddev drush uli --uid=24 --no-browser | tail -1)" \
 *   SLD_ALUMNO="$(ddev drush uli --uid=25 --no-browser | tail -1)" \
 *   SLD_ADMIN="$(ddev drush uli --uid=1  --no-browser | tail -1)" \
 *   node ~/.claude/skills/browser-automation/browser.mjs \
 *     https://diagnostic-ai.ddev.site/ --script bin/caminos.mjs
 * @endcode
 *
 * Los uid son los de este entorno; en otro habra que ajustarlos. Se usan
 * enlaces de un solo uso y no contrasenas para no tocar ninguna cuenta.
 *
 * **Los enlaces se generan justo antes de correr.** Son de un solo uso: si se
 * reutiliza uno ya gastado, Drupal no entra y el recorrido corre como anonimo.
 * Cuando pasa, cada fallo lo dice —«entro como login»— en vez de dejar creer
 * que las pantallas se rompieron.
 */

const SITIO = 'https://diagnostic-ai.ddev.site';

/**
 * Las pantallas, con el rol que las usa y lo que DEBE devolver.
 *
 * Un 403 esperado es un camino como cualquier otro y por eso esta en la lista.
 */
const CAMINOS = [
  { url: '/admin/config/salesbumm', que: 'Ajustes', roles: ['admin'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic', que: 'Integracion', roles: ['admin'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/marca', que: 'Marca', roles: ['admin'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/portada', que: 'Portada', roles: ['admin'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/agentes', que: 'Agentes', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/agentes/anadir', que: 'Anadir agente', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/agentes/prospecting_diagnostic', que: 'Editar agente', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/agentes/prospecting_diagnostic/borrar', que: 'Borrar agente', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/documentos', que: 'Documentos', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/documentos/prospecting_diagnostic', que: 'Documentos de un agente', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/estudio', que: 'Estudio', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/estudio/agente/prospecting_diagnostic', que: 'Estudio de un agente', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/config/salesbumm/diagnostic/consumo', que: 'Consumo', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/admin/content/sales-diagnostic', que: 'Resultados', roles: ['admin', 'gestor'], espera: 200 },
  { url: '/sales-diagnostic', que: 'Panel del alumno', roles: ['alumno'], espera: 200 },
  { url: '/sales-diagnostic/agente/prospecting_diagnostic', que: 'Pagina de agente', roles: ['alumno'], espera: 200 },
  { url: '/bienvenida', que: 'Bienvenida publica', roles: ['alumno', 'gestor', 'admin'], espera: 200, sinSalida: true },
  // Lo que NO debe abrirse. Sin esto, media comprobacion.
  { url: '/admin/config/salesbumm/diagnostic/marca', que: 'Marca (ajena)', roles: ['gestor'], espera: 403 },
  { url: '/admin/config/salesbumm/diagnostic/consumo', que: 'Consumo (ajeno)', roles: ['alumno'], espera: 403 },
];

/**
 * Mide el contraste de cada texto sobre el fondo que de verdad tiene detras.
 */
async function contraste(page) {
  return page.evaluate(() => {
    // Dos notaciones: `rgb()` va de 0 a 255 y `color(srgb ...)` de 0 a 1.
    // Confundirlas da casi negro para cualquier color y la herramienta reporta
    // fallos que no existen.
    const leer = (c) => {
      if (!c) return null;
      const n = c.match(/-?\d+(\.\d+)?/g);
      if (!n || n.length < 3) return null;
      const v = n.map(Number);
      const unidad = /^color\(/.test(c);
      return [
        unidad ? v[0] * 255 : v[0],
        unidad ? v[1] * 255 : v[1],
        unidad ? v[2] * 255 : v[2],
        v.length > 3 ? v[3] : 1,
      ];
    };

    const lumDe = (rgb) => {
      const v = rgb.slice(0, 3).map((x) => {
        const u = x / 255;
        return u <= 0.03928 ? u / 12.92 : ((u + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    };

    const sobre = (frente, atras) => frente.slice(0, 3)
      .map((x, i) => x * frente[3] + atras[i] * (1 - frente[3]))
      .concat([1]);

    const fondoDe = (el) => {
      const capas = [];
      let e = el;
      while (e) {
        const c = leer(getComputedStyle(e).backgroundColor);
        if (c && c[3] > 0) {
          capas.push(c);
          if (c[3] === 1) break;
        }
        e = e.parentElement;
      }
      let base = [255, 255, 255, 1];
      for (let i = capas.length - 1; i >= 0; i--) base = sobre(capas[i], base);
      return base;
    };

    const flojos = [];

    for (const el of document.querySelectorAll('a, button, .button, th, td, label, p, h1, h2, h3, span, li, summary')) {
      const texto = (el.textContent || '').trim();
      if (!texto || texto.length > 120 || el.children.length > 0) continue;

      const caja = el.getBoundingClientRect();
      if (caja.width < 4 || caja.height < 4) continue;

      const s = getComputedStyle(el);
      if (s.visibility === 'hidden' || s.display === 'none' || +s.opacity < 0.2) continue;

      const cTexto = leer(s.color);
      if (!cTexto) continue;

      const cFondo = fondoDe(el);
      const lt = lumDe(sobre(cTexto, cFondo));
      const lf = lumDe(cFondo);
      const ratio = (Math.max(lt, lf) + 0.05) / (Math.min(lt, lf) + 0.05);

      // 4,5 para texto normal; el grande admite 3.
      const grande = parseFloat(s.fontSize) >= 24
        || (parseFloat(s.fontSize) >= 18.66 && +s.fontWeight >= 700);
      const minimo = grande ? 3 : 4.5;

      if (ratio < minimo) {
        flojos.push({ texto: texto.slice(0, 40), ratio: +ratio.toFixed(2), minimo });
      }
    }

    return flojos;
  });
}

export default async function run(page) {
  await page.setViewportSize({ width: 1440, height: 950 });

  const enlaces = {
    gestor: process.env.SLD_GESTOR,
    alumno: process.env.SLD_ALUMNO,
    admin: process.env.SLD_ADMIN,
  };

  const fallos = [];
  let recorridos = 0;

  for (const rol of Object.keys(enlaces)) {
    if (!enlaces[rol]) {
      fallos.push(`${rol}: falta su enlace de acceso en el entorno`);
      continue;
    }

    // Drupal NO procesa un enlace de acceso si ya hay alguien dentro: se queda
    // como estaba y todo el recorrido siguiente corre con el rol equivocado,
    // devolviendo resultados que parecen buenos. Se limpia y se comprueba.
    await page.context().clearCookies();
    await page.goto(enlaces[rol], { waitUntil: 'networkidle' });
    await page.goto(`${SITIO}/user`, { waitUntil: 'networkidle' });
    const quien = page.url();

    for (const camino of CAMINOS.filter((c) => c.roles.includes(rol))) {
      recorridos++;

      let http = 0;
      let cuerpo = '';

      try {
        const r = await page.goto(SITIO + camino.url, { waitUntil: 'networkidle', timeout: 25000 });
        http = r ? r.status() : 0;
        cuerpo = await page.locator('body').innerText();
      }
      catch (e) {
        fallos.push(`${rol} · ${camino.que}: no cargo (${e.message.slice(0, 60)})`);
        continue;
      }

      if (/unexpected error|must not be accessed|Recoverable fatal|The website encountered/i.test(cuerpo)) {
        fallos.push(`${rol} · ${camino.que}: ERROR FATAL en la pagina`);
        continue;
      }

      if (http !== camino.espera) {
        fallos.push(`${rol} · ${camino.que}: devolvio ${http} y se esperaba ${camino.espera} (entro como ${quien.split('/').pop()})`);
        continue;
      }

      if (http !== 200) continue;

      // Salidas: navegacion, volver o cerrar sesion. Una pantalla sin ninguna
      // es un callejon, y de esos ya han salido tres.
      if (!camino.sinSalida) {
        const salidas = await page.locator('a[href*="/user/logout"], .sld-manager__nav-link, a[href*="/admin/config/salesbumm"], a[href*="/sales-diagnostic"]').count();
        if (salidas < 2) {
          fallos.push(`${rol} · ${camino.que}: solo ${salidas} salida(s); es un callejon`);
        }
      }

      for (const f of await contraste(page)) {
        fallos.push(`${rol} · ${camino.que}: contraste ${f.ratio}:1 (minimo ${f.minimo}) en «${f.texto}»`);
      }
    }
  }

  return {
    resultado: fallos.length === 0 ? 'TODO BIEN' : `${fallos.length} FALLO(S)`,
    recorridos,
    fallos,
  };
}
