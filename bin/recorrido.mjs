/**
 * @file
 * Recorrido de ACCIONES antes de desplegar: lo que el humo y los caminos no
 * hacen.
 *
 * Nació el 14-09-2026, antes del primer despliegue a producción. José Raúl lo
 * pidió así: «que no salgan errores como el tema de guardar, y otros detalles
 * que encontrábamos». Los caminos abren cada pantalla y el humo guarda dos
 * formularios; ninguno guardaba Integración, Portada ni Documentos, ni guardaba
 * nada con los permisos del GESTOR, ni anotaba una cuenta, ni mandaba un
 * mensaje de verdad. Los fallos que llegaron al cliente estaban justo ahí:
 * pantallas que cargan bien y revientan al usarlas.
 *
 * Qué hace, por rol:
 *  - Administrador: guarda Integración, Portada, Marca, la ficha de los dos
 *    agentes y los documentos.
 *  - Gestor: lo mismo con SUS permisos, filtra resultados y abre informes de
 *    un alumno.
 *  - Alumno: abre todos sus informes, anota el resultado de una cuenta,
 *    comprueba que Intro en la nota no anota nada (fallo del 11-09-2026) y,
 *    con SLD_CHAT=1, manda un mensaje REAL y exige que la respuesta llegue
 *    firmada por su agente. Eso cuesta unos centavos de OpenAI.
 *  - Sin sesión: contraseña equivocada (el error tiene que leerse) y páginas
 *    públicas.
 *  - En todas: sin errores de consola y sin desplazamiento horizontal.
 *
 * Deja datos de prueba (una anotación, un mensaje): los borra el comando de
 * limpieza antes de abrir a alumnos reales.
 *
 * Uso (los enlaces son de un solo uso; se generan justo antes):
 * @code
 *   SLD_ADMIN="$(ddev drush uli --uid=1 --no-browser | tail -1)" \
 *   SLD_GESTOR="$(ddev drush uli --uid=24 --no-browser | tail -1)" \
 *   SLD_ALUMNO="$(ddev drush uli --uid=25 --no-browser | tail -1)" \
 *   SLD_INFORMES="14,48" SLD_CHAT=1 \
 *   node ~/.claude/skills/browser-automation/browser.mjs \
 *     https://diagnostic-ai.ddev.site/ --script bin/recorrido.mjs
 * @endcode
 *
 * NO correrlo a la vez que bin/humo.mjs: los dos entran con las mismas cuentas
 * y uno le cierra la sesión al otro.
 */

const SITIO = 'https://diagnostic-ai.ddev.site';
const CONTRASTE_MINIMO = 4.5;

export default async function run(page) {
  const hechas = [];
  const fallos = [];
  const consola = [];

  const anotar = (ok, que, detalle) => {
    if (ok) {
      hechas.push(que);
    }
    else {
      fallos.push(`${que} — ${detalle}`);
    }
  };

  // Un paso que revienta se ANOTA y el recorrido sigue. En la primera pasada
  // (14-09-2026) un botón que no se dejaba pulsar cortó el recorrido entero y
  // se perdieron los resultados de todo lo que ya había pasado.
  const seguro = async (que, fn) => {
    try {
      await fn();
    }
    catch (e) {
      anotar(false, que, `el paso reventó: ${String(e.message).split('\n')[0].slice(0, 160)}`);
    }
  };

  // Los 403 los provocan los caminos a propósito; aquí no se buscan, pero se
  // ignoran por si alguna comprobación los roza.
  page.on('console', (m) => {
    if (m.type() === 'error' && !/status of 40[34]/.test(m.text())) {
      consola.push(`${page.url().replace(SITIO, '')}: ${m.text().slice(0, 160)}`);
    }
  });
  page.on('pageerror', (e) => consola.push(`${page.url().replace(SITIO, '')}: ${e.message.slice(0, 160)}`));

  const entrar = async (enlace, rol) => {
    await page.context().clearCookies();
    await page.goto(enlace, { waitUntil: 'networkidle' });
    if (/\/user\/login/.test(page.url())) {
      throw new Error(`El enlace de ${rol} ya estaba gastado: generar uno nuevo.`);
    }
  };

  const errorFatal = async () => (await page.locator('text=/Error fatal|Fatal error|unexpected error|error inesperado/i').count()) > 0;

  const abrir = async (ruta, que) => seguro(`abre: ${que}`, async () => {
    const r = await page.goto(SITIO + ruta, { waitUntil: 'networkidle' });
    const fatal = await errorFatal();
    anotar(r.status() === 200 && !fatal, `abre: ${que}`, fatal ? 'error fatal en la página' : `HTTP ${r.status()}`);
  });

  const guardar = async (ruta, boton, que) => seguro(`guardar: ${que}`, async () => {
    await page.goto(SITIO + ruta, { waitUntil: 'networkidle' });
    const b = page.locator(`input[type=submit][value="${boton}"], button[type=submit]:text-is("${boton}")`).first();
    if (!(await b.count())) {
      anotar(false, `guardar: ${que}`, `no hay botón «${boton}» en ${ruta}`);
      return;
    }
    await Promise.all([page.waitForLoadState('networkidle'), b.click()]);
    await page.waitForTimeout(800);
    const errores = await page.locator('.messages--error').allInnerTexts();
    const bien = await page.locator('.messages--status').count();
    const fatal = await errorFatal();
    anotar(!fatal && errores.length === 0 && bien > 0, `guardar: ${que}`,
      fatal ? 'error fatal al guardar' : errores.length ? errores.join(' ').replace(/\s+/g, ' ').slice(0, 160) : 'no confirmó el guardado');
  });

  const sinDesborde = async (rutas, quien) => {
    for (const ancho of [390, 1280]) {
      await page.setViewportSize({ width: ancho, height: 900 });
      for (const ruta of rutas) {
        await page.goto(SITIO + ruta, { waitUntil: 'networkidle' });
        const sobra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        anotar(sobra <= 1, `sin desplazamiento horizontal a ${ancho}px: ${quien} ${ruta}`, `la página se sale ${sobra}px`);
      }
    }
    await page.setViewportSize({ width: 1280, height: 900 });
  };

  const contraste = async (selector) => page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) {
      return null;
    }
    const rgb = (c) => (c.match(/\d+(\.\d+)?/g) || []).slice(0, 3).map(Number);
    const lum = ([r, g, b]) => {
      const f = [r, g, b].map((v) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
    };
    let nodo = el;
    let fondo = 'rgba(0, 0, 0, 0)';
    while (nodo && /rgba\(0, 0, 0, 0\)/.test(fondo)) {
      fondo = getComputedStyle(nodo).backgroundColor;
      nodo = nodo.parentElement;
    }
    const a = lum(rgb(getComputedStyle(el).color));
    const b = lum(rgb(fondo));
    return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
  }, selector);

  // --- Administrador --------------------------------------------------------
  await entrar(process.env.SLD_ADMIN, 'administrador');
  await guardar('/admin/config/salesbumm/diagnostic', 'Guardar configuración', 'Integración (admin)');
  await guardar('/admin/config/salesbumm/diagnostic/portada', 'Guardar configuración', 'Portada (admin)');
  await guardar('/admin/config/salesbumm/diagnostic/marca', 'Guardar configuración', 'Marca (admin)');
  await guardar('/admin/config/salesbumm/diagnostic/agentes/sales_leadership_diagnostic', 'Guardar', 'ficha del agente de diagnóstico (admin)');
  await guardar('/admin/config/salesbumm/diagnostic/agentes/prospecting_diagnostic', 'Guardar', 'ficha del agente de prospección (admin)');
  // Documentos NO tiene un guardar general: sus botones añaden o quitan
  // documentos de la biblioteca, que cambiaría la del agente. Se abre.
  await abrir('/admin/config/salesbumm/diagnostic/documentos/sales_leadership_diagnostic', 'documentos del agente de diagnóstico (admin)');
  await abrir('/admin/config/salesbumm/diagnostic/agentes/anadir', 'alta de agente (admin)');

  // El informe de estado, sin una sola línea en rojo. El 14-09-2026 José Raúl
  // encontró a mano «No coinciden las definiciones de entidad» y ninguna de
  // las comprobaciones lo miraba. Los avisos en amarillo no cuentan aquí.
  await seguro('informe de estado sin errores (admin)', async () => {
    await page.goto(`${SITIO}/admin/reports/status`, { waitUntil: 'networkidle' });
    const errores = await page.locator('.system-status-report__entry--error').evaluateAll(
      (filas) => filas.map((f) => f.innerText.replace(/\s+/g, ' ').trim().slice(0, 140)),
    );
    anotar(errores.length === 0, 'informe de estado sin errores (admin)', errores.join(' | '));
  });

  // --- Gestor ---------------------------------------------------------------
  await entrar(process.env.SLD_GESTOR, 'gestor');
  await guardar('/admin/config/salesbumm/diagnostic/agentes/sales_leadership_diagnostic', 'Guardar', 'ficha del agente de diagnóstico (gestor)');
  await guardar('/admin/config/salesbumm/diagnostic/agentes/prospecting_diagnostic', 'Guardar', 'ficha del agente de prospección (gestor)');
  await abrir('/admin/config/salesbumm/diagnostic/documentos/prospecting_diagnostic', 'documentos del agente de prospección (gestor)');

  // Filtrar el listado de resultados: formulario GET, sin mensaje de guardado.
  // Los filtros vienen PLEGADOS; como haría una persona, se despliegan antes.
  await seguro('gestor: filtrar resultados', async () => {
    await page.goto(`${SITIO}/admin/content/sales-diagnostic`, { waitUntil: 'networkidle' });
    const panel = page.locator('#sld-results-filter details').first();
    if ((await panel.count()) && !(await panel.evaluate((d) => d.open))) {
      await panel.locator('summary').first().click();
    }
    const filtrar = page.locator('#sld-results-filter input[type=submit][value="Filtrar"]').first();
    await Promise.all([page.waitForLoadState('networkidle'), filtrar.click()]);
    anotar(!(await errorFatal()) && (await page.locator('.messages--error').count()) === 0, 'gestor: filtrar resultados', 'error al filtrar');
  });

  const informes = (process.env.SLD_INFORMES || '').split(',').filter(Boolean);
  for (const id of informes.slice(0, 2)) {
    await abrir(`/sales-diagnostic/results/${id}`, `informe ${id} de un alumno (gestor)`);
  }
  await sinDesborde(['/admin/content/sales-diagnostic', '/admin/config/salesbumm/diagnostic/consumo'], 'gestor');

  // --- Alumno ---------------------------------------------------------------
  await entrar(process.env.SLD_ALUMNO, 'alumno');
  for (const id of informes) {
    await abrir(`/sales-diagnostic/results/${id}`, `su informe ${id} (alumno)`);
  }

  // Anotar el resultado de una cuenta con un clic.
  await seguro('alumno: anotar el resultado de una cuenta', async () => {
  await page.goto(`${SITIO}/sales-diagnostic/cuentas`, { waitUntil: 'networkidle' });
  const cuenta = page.locator('li.sld-account').first();
  if (await cuenta.count()) {
    const id = await cuenta.getAttribute('id');
    const boton = cuenta.locator('button.sld-account__state:not(.is-current)').first();
    const estado = (await boton.innerText()).trim();
    await Promise.all([page.waitForLoadState('networkidle'), boton.click()]);
    const aviso = (await page.locator('.messages--status').allInnerTexts()).join(' ');
    const actual = (await page.locator(`#${id} button.sld-account__state.is-current`).innerText().catch(() => '')).trim();
    anotar(aviso.includes('Anotado') && actual === estado, 'alumno: anotar el resultado de una cuenta',
      `aviso «${aviso.slice(0, 80)}», estado marcado «${actual}», esperado «${estado}»`);

    // Intro en la nota, sin elegir estado, NO anota nada: avisa. Fue un fallo
    // real (11-09-2026): anotaba «Sin acción» porque el primer botón del
    // formulario era un estado.
    const antes = (await page.locator(`#${id} button.sld-account__state.is-current`).innerText().catch(() => '')).trim();
    await page.locator(`#${id} .sld-account__more summary`).click();
    await page.locator(`#${id} input[name=note]`).fill('Nota del recorrido previo al despliegue');
    await Promise.all([page.waitForLoadState('networkidle'), page.locator(`#${id} input[name=note]`).press('Enter')]);
    const avisoIntro = (await page.locator('.messages--warning').allInnerTexts()).join(' ');
    const despues = (await page.locator(`#${id} button.sld-account__state.is-current`).innerText().catch(() => '')).trim();
    anotar(avisoIntro.includes('Elige') && despues === antes, 'alumno: Intro en la nota no anota un estado',
      `aviso «${avisoIntro.slice(0, 80)}», estado antes «${antes}» y después «${despues}»`);
  }
  else {
    anotar(false, 'alumno: anotar el resultado de una cuenta', 'no hay cuentas en la lista');
  }
  });

  // Un mensaje REAL: el motor responde y la respuesta firma con su agente.
  if (process.env.SLD_CHAT) await seguro('alumno: el agente responde y firma con su nombre', async () => {
    await page.goto(`${SITIO}/sales-diagnostic/agente/sales_leadership_diagnostic`, { waitUntil: 'networkidle' });
    await Promise.all([page.waitForURL(/\/session\/\d+/), page.locator('.sld-dashboard__start button[type=submit]').click()]);
    await page.waitForLoadState('networkidle');
    const agente = (await page.locator('.sld-page__title').innerText()).trim();
    const previas = await page.locator('.sld-chat__message--assistant').count();
    await page.locator('[data-sld-input]').fill('Prueba del recorrido previo al despliegue: responde solo con una línea para confirmar que me lees.');
    await page.locator('[data-sld-send]').click();
    const llego = await page.waitForFunction(
      (n) => document.querySelectorAll('.sld-chat__message--assistant').length > n,
      previas, { timeout: 180000 },
    ).then(() => true).catch(() => false);
    const firma = llego ? (await page.locator('.sld-chat__message--assistant .sld-chat__author').last().innerText()).trim() : '';
    const errorChat = await page.locator('[data-sld-error]:not([hidden])').innerText().catch(() => '');
    anotar(llego && firma.toLowerCase() === agente.toLowerCase() && !errorChat, 'alumno: el agente responde y firma con su nombre',
      llego ? `firma «${firma}», agente «${agente}» ${errorChat}` : `sin respuesta en 3 minutos ${errorChat}`);
  });

  await sinDesborde([
    '/sales-diagnostic',
    '/sales-diagnostic/agente/sales_leadership_diagnostic',
    '/sales-diagnostic/agente/prospecting_diagnostic',
    '/sales-diagnostic/cuentas',
    ...informes.map((id) => `/sales-diagnostic/results/${id}`),
    '/sales-diagnostic/session/66',
    '/sales-diagnostic/session/73',
  ], 'alumno');

  // Cerrar sesión lleva fuera de verdad.
  await seguro('alumno: cerrar sesión le saca del panel', async () => {
    await page.goto(`${SITIO}/sales-diagnostic`, { waitUntil: 'networkidle' });
    await Promise.all([page.waitForLoadState('networkidle'), page.locator('.sld-home__logout').first().click()]);
    const tras = await page.goto(`${SITIO}/sales-diagnostic`, { waitUntil: 'networkidle' });
    anotar(!/\/sales-diagnostic$/.test(page.url()) || tras.status() !== 200 || (await page.locator('.sld-dashboard').count()) === 0,
      'alumno: cerrar sesión le saca del panel', `tras salir sigue viendo el panel (${page.url()})`);
  });

  // --- Sin sesión -----------------------------------------------------------
  await page.context().clearCookies();
  await sinDesborde(['/bienvenida', '/user/login', '/user/password', '/sales-diagnostic/sin-acceso'], 'anónimo');

  await seguro('anónimo: la contraseña equivocada se avisa y se lee', async () => {
    await page.goto(`${SITIO}/user/login`, { waitUntil: 'networkidle' });
    await page.locator('#edit-name').fill('alumno.demo');
    await page.locator('#edit-pass').fill('esta-no-es-la-clave');
    await Promise.all([page.waitForLoadState('networkidle'), page.locator('#edit-submit').click()]);
    const hayError = await page.locator('.messages--error').count();
    const legible = hayError ? await contraste('.messages--error') : null;
    anotar(hayError > 0 && legible !== null && legible >= CONTRASTE_MINIMO, 'anónimo: la contraseña equivocada se avisa y se lee',
      hayError ? `contraste ${legible}` : 'no apareció ningún aviso');
  });

  return {
    resultado: fallos.length || consola.length ? `${fallos.length} FALLO(S), ${consola.length} ERROR(ES) DE CONSOLA` : 'TODO BIEN',
    comprobaciones: hechas.length + fallos.length,
    fallos,
    consola,
  };
}
