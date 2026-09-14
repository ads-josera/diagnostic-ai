/**
 * @file
 * Toma la «huella» de cada pantalla para demostrar qué cambió y qué no.
 *
 * Nació el 13-09-2026, con el rediseño oscuro del alumno. José Raúl pidió
 * mucho cuidado de no romper nada, y mirar capturas no basta para eso: un gris
 * que pasa de #5b6470 a #5b6471 no se ve y sí es un cambio. Por cada pantalla
 * se recorren TODOS sus elementos y se anotan sus estilos calculados —color,
 * fondo, letra, tamaños, bordes, posición—. Dos huellas iguales significan que
 * la pantalla se pinta exactamente igual.
 *
 * Se usa en dos pasadas: una ANTES de tocar el CSS y otra DESPUÉS. La segunda
 * compara y dice qué pantallas cambiaron y en qué elementos. Las que tenían
 * que cambiar deben aparecer; todas las demás, no.
 *
 * Uso:
 * @code
 *   SLD_ALUMNO="$(ddev drush uli --uid=25 --no-browser | tail -1)" \
 *   SLD_GESTOR="$(ddev drush uli --uid=24 --no-browser | tail -1)" \
 *   SLD_HUELLA=antes SLD_SALIDA=/ruta/temporal \
 *   node ~/.claude/skills/browser-automation/browser.mjs \
 *     https://diagnostic-ai.ddev.site/ --script bin/huella-pantallas.mjs
 * @endcode
 *
 * Con `SLD_COMPARAR=antes` la pasada compara contra esa carpeta. Los enlaces
 * de acceso son de un solo uso: se generan justo antes de cada pasada.
 *
 * Los identificadores (sesiones, resultados) son los de este entorno; en otro
 * habrá que ajustarlos. Solo se visitan pantallas que se leen: ninguna de la
 * lista crea ni cambia datos.
 */

import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';

const SITIO = 'https://diagnostic-ai.ddev.site';

const PANTALLAS = {
  alumno: [
    '/sales-diagnostic',
    '/sales-diagnostic/agente/sales_leadership_diagnostic',
    '/sales-diagnostic/agente/prospecting_diagnostic',
    '/sales-diagnostic/cuentas',
    '/sales-diagnostic/results/14',
    '/sales-diagnostic/results/48',
    '/sales-diagnostic/session/66',
    '/sales-diagnostic/session/73',
    '/sales-diagnostic/sin-acceso',
  ],
  gestor: [
    '/admin/content/sales-diagnostic',
    '/sales-diagnostic/results/14',
    '/admin/config/salesbumm/diagnostic/estudio',
    '/admin/config/salesbumm/diagnostic/consumo',
  ],
  anonimo: [
    '/bienvenida',
    '/user/login',
  ],
};

const ANCHOS = [1280, 390];

const PROPIEDADES = [
  'color', 'background-color', 'background-image', 'font-family', 'font-size',
  'font-weight', 'line-height', 'letter-spacing', 'text-transform',
  'border-top-color', 'border-top-width', 'border-left-color', 'border-left-width',
  'border-radius', 'box-shadow', 'padding', 'margin', 'display', 'opacity',
];

/** Estilos calculados de cada elemento visible, en orden de documento. */
async function huella(page) {
  return page.evaluate((props) => {
    const filas = [];
    for (const el of document.body.querySelectorAll('*')) {
      if (['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName)) continue;
      // La zona que Drupal crea para anunciar a los lectores de pantalla llega
      // cuando llega: con ella dos pasadas idénticas salían distintas.
      if (el.id === 'drupal-live-announce') continue;
      const cs = getComputedStyle(el);
      // Lo que no se pinta no puede cambiar la pantalla. La lista de mensajes
      // vacía de Olivero (oculta) recibe su margen de un script a destiempo, y
      // hacía distintas dos pasadas idénticas (visto tres veces, 13-09-2026).
      // Si algo visible se ocultara, o al revés, cambiaría la lista de
      // elementos y se detectaría igual.
      if (cs.display === 'none') continue;
      const r = el.getBoundingClientRect();
      const clase = typeof el.className === 'string' ? el.className.trim() : '';
      filas.push([
        `${el.tagName.toLowerCase()}${clase ? '.' + clase.split(/\s+/).join('.') : ''}`,
        props.map((p) => cs.getPropertyValue(p)).join('|'),
        // Tamaño y no posición: dentro del chat, que tiene su propio
        // desplazamiento, la posición bailaba un píxel entre cargas.
        [r.width, r.height].map(Math.round).join('x'),
      ].join(' ~ '));
    }
    return filas;
  }, PROPIEDADES);
}

async function captura(page, ruta) {
  const cdp = await page.context().newCDPSession(page);
  const alto = await page.evaluate(() => document.documentElement.scrollHeight);
  const ancho = page.viewportSize().width;
  const { data } = await cdp.send('Page.captureScreenshot', {
    format: 'jpeg', quality: 70, captureBeyondViewport: true,
    clip: { x: 0, y: 0, width: ancho, height: Math.min(alto, 4000), scale: 1 },
  });
  writeFileSync(ruta, Buffer.from(data, 'base64'));
  await cdp.detach();
}

function nombre(rol, url, ancho) {
  return `${rol}${url.replace(/[^a-z0-9]+/gi, '_')}_${ancho}`;
}

export default async function run(page) {
  const etiqueta = process.env.SLD_HUELLA || 'antes';
  const dir = `${process.env.SLD_SALIDA}/${etiqueta}`;
  mkdirSync(dir, { recursive: true });

  const firmas = {};
  const accesos = { alumno: process.env.SLD_ALUMNO, gestor: process.env.SLD_GESTOR, anonimo: null };

  for (const [rol, urls] of Object.entries(PANTALLAS)) {
    await page.context().clearCookies();
    if (accesos[rol]) {
      await page.goto(accesos[rol]);
      if (/\/user\/login/.test(page.url())) {
        throw new Error(`El enlace de ${rol} ya estaba gastado: generar uno nuevo.`);
      }
    }
    for (const url of urls) {
      for (const ancho of ANCHOS) {
        await page.setViewportSize({ width: ancho, height: 900 });
        const resp = await page.goto(SITIO + url, { waitUntil: 'networkidle' });
        // Deja asentarse las fuentes y lo que los scripts de Drupal ajustan al
        // terminar de cargar (el margen de los mensajes, por ejemplo).
        await page.evaluate(() => document.fonts.ready.then(() => true)).catch(() => true);
        await page.waitForTimeout(1500);
        const clave = nombre(rol, url, ancho);
        const filas = await huella(page);
        writeFileSync(`${dir}/${clave}.txt`, filas.join('\n'));
        await captura(page, `${dir}/${clave}.jpg`);
        firmas[clave] = { estado: resp.status(), final: page.url().replace(SITIO, ''), elementos: filas.length };
      }
    }
  }
  writeFileSync(`${dir}/firmas.json`, JSON.stringify(firmas, null, 1));

  const base = process.env.SLD_COMPARAR;
  if (!base) return { etiqueta, pantallas: Object.keys(firmas).length, firmas };

  const antes = `${process.env.SLD_SALIDA}/${base}`;
  // Una pantalla que no cargó no «cambió»: la comparación no vale nada. Pasó el
  // 13-09-2026 al correrla a la vez que bin/humo.mjs, que entra y sale con la
  // misma cuenta de gestor: sus cuatro pantallas dieron 403 y salían como
  // «distintas». Se dice aparte y primero, para que no se confunda.
  const noCargaron = Object.entries(firmas)
    .filter(([, f]) => f.estado !== 200)
    .map(([clave, f]) => `${clave} (HTTP ${f.estado})`);
  const informe = { noCargaron, iguales: [], distintas: {} };
  for (const clave of Object.keys(firmas)) {
    const a = existsSync(`${antes}/${clave}.txt`) ? readFileSync(`${antes}/${clave}.txt`, 'utf8').split('\n') : [];
    const d = readFileSync(`${dir}/${clave}.txt`, 'utf8').split('\n');
    if (a.join('\n') === d.join('\n')) { informe.iguales.push(clave); continue; }
    const cambios = [];
    for (let i = 0; i < Math.max(a.length, d.length) && cambios.length < 6; i++) {
      if (a[i] !== d[i]) cambios.push({ i, antes: (a[i] || '').slice(0, 220), despues: (d[i] || '').slice(0, 220) });
    }
    informe.distintas[clave] = { elementosAntes: a.length, elementosDespues: d.length, cambios };
  }
  return informe;
}
