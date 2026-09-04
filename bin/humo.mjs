/**
 * Prueba de humo de la interfaz.
 *
 * Existe porque los tests y las comprobaciones por línea de órdenes no ven una
 * clase entera de fallos, y todos los de esa clase los encontró el cliente
 * usando el producto:
 *
 *   · Un error fatal de PHP se sirve CON código 200. Comprobar el código deja
 *     pasar una página rota.
 *   · Un formulario puede no guardar aunque su página cargue. Fijar los mismos
 *     valores por drush funciona y oculta el fallo durante semanas.
 *   · Una subida de archivos falla en la petición AJAX, no al cargar.
 *   · El contraste solo se rompe con el sistema en modo oscuro, y hay que
 *     medirlo: mirarlo no basta.
 *   · Una función inalcanzable da «sí» en toda comprobación de permisos si lo
 *     que falta es el enlace que lleva a ella.
 *   · Un <style> puede inyectarse con los valores correctos y no pintar nada.
 *     Hay que medir el color EFECTIVO del elemento, no la presencia del
 *     bloque.
 *
 * Uso:
 *   node ~/.claude/skills/browser-automation/browser.mjs \
 *     https://diagnostic-ai.ddev.site/ --script bin/humo.mjs
 *
 * Devuelve la lista de fallos. Lista vacía es lo único que cuenta como pasar:
 * no hay avisos ni resultados a medias, para que no quepa interpretarlo.
 */

const SITIO = 'https://diagnostic-ai.ddev.site';

const CUENTAS = {
  gestor: { usuario: 'gestor.sam', clave: 'GestorSAM2026!' },
  alumno: { usuario: 'alumno.demo', clave: 'AlumnoDemo2026!' },
};

/**
 * Contraste mínimo aceptable entre texto y fondo.
 *
 * 4.5 es el umbral AA de WCAG para texto normal. Se comprueba con un número y
 * no a ojo porque los fallos reales de este proyecto fueron de texto casi
 * blanco sobre blanco: a ojo se ve que «algo falta», pero no se sabe qué ni se
 * puede afirmar que está arreglado.
 */
const CONTRASTE_MINIMO = 4.5;

export default async function run(page) {
  const fallos = [];
  const hechas = [];

  const anotar = (ok, que, detalle) => {
    hechas.push(que);
    if (!ok) fallos.push(`${que} — ${detalle}`);
  };

  /** Inicia sesión con usuario y contraseña, como lo hace una persona. */
  const entrar = async ({ usuario, clave }) => {
    await page.goto(`${SITIO}/user/logout/confirm`, { waitUntil: 'networkidle' }).catch(() => {});

    // Se pulsa SOLO si de verdad estamos en la confirmacion de cierre de
    // sesion. Pulsar «el primer boton de la pagina» a ciegas costo dinero el
    // 31-08-2026: al estar ya fuera se aterrizaba en el panel del alumno, y
    // ahi el primer boton es «Iniciar diagnostico». Asi, la prueba de humo
    // abria una conversacion real contra OpenAI en cada ejecucion.
    if (page.url().includes('/user/logout')) {
      await page.locator('form input[type=submit], form button[type=submit]').first().click().catch(() => {});
    }
    await page.waitForTimeout(500);
    await page.goto(`${SITIO}/user/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#edit-name', usuario);
    await page.fill('#edit-pass', clave);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('#edit-submit'),
    ]);
    return page.url();
  };

  /** Carga una página y comprueba que no traiga un fatal disfrazado de 200. */
  const abrir = async (ruta, quien) => {
    const respuesta = await page.goto(`${SITIO}${ruta}`, { waitUntil: 'networkidle' });
    const estado = respuesta ? respuesta.status() : 0;
    const cuerpo = await page.content();
    const fatal = cuerpo.includes('Fatal error') || cuerpo.includes('The website encountered an unexpected error');

    anotar(estado === 200 && !fatal, `${quien}: ${ruta}`,
      fatal ? `HTTP ${estado} pero la página contiene un error fatal` : `HTTP ${estado}`);

    return estado === 200 && !fatal;
  };

  /** Relación de contraste entre el texto de un elemento y su fondo efectivo. */
  const contraste = async (selector) => await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;

    const aRgb = (c) => (c.match(/\d+(\.\d+)?/g) || []).slice(0, 3).map(Number);
    const luminancia = ([r, g, b]) => {
      const f = [r, g, b].map((v) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
    };

    // El fondo se busca subiendo hasta encontrar uno que no sea transparente:
    // el elemento del texto casi nunca lo declara.
    let nodo = el;
    let fondo = 'rgba(0, 0, 0, 0)';
    while (nodo && fondo === 'rgba(0, 0, 0, 0)') {
      fondo = getComputedStyle(nodo).backgroundColor;
      nodo = nodo.parentElement;
    }
    if (fondo === 'rgba(0, 0, 0, 0)') fondo = 'rgb(255, 255, 255)';

    const a = luminancia(aRgb(getComputedStyle(el).color));
    const b = luminancia(aRgb(fondo));
    return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
  }, selector);

  /** Comprueba el contraste de un texto en los dos modos del sistema. */
  const legibleEnAmbosModos = async (ruta, selector, que) => {
    for (const modo of ['light', 'dark']) {
      await page.emulateMedia({ colorScheme: modo });
      await page.goto(`${SITIO}${ruta}`, { waitUntil: 'networkidle' });
      const valor = await contraste(selector);

      if (valor === null) {
        anotar(false, `contraste ${modo}: ${que}`, `no se encontró «${selector}» en ${ruta}`);
        continue;
      }

      anotar(valor >= CONTRASTE_MINIMO, `contraste ${modo}: ${que}`,
        `relación ${valor}, por debajo de ${CONTRASTE_MINIMO}`);
    }
    await page.emulateMedia({ colorScheme: 'light' });
  };

  /** Envía un formulario de administración y exige que confirme el guardado. */
  const guarda = async (ruta, que) => {
    await page.goto(`${SITIO}${ruta}`, { waitUntil: 'networkidle' });
    await page.locator('input[type=submit][value*="Guardar"], button[type=submit]').first().click();
    await page.waitForTimeout(2500);

    const error = await page.locator('.messages--error').innerText().catch(() => '');
    const estado = await page.locator('.messages--status').innerText().catch(() => '');

    anotar(error === '' && estado !== '', `guardar: ${que}`,
      error !== '' ? error.replace(/\s+/g, ' ').slice(0, 120) : 'no confirmó el guardado');
  };

  // --- Administrador: todas las páginas cargan de verdad --------------------
  if (process.env.SLD_ULI) {
    await page.goto(process.env.SLD_ULI, { waitUntil: 'networkidle' });

    for (const ruta of [
      '/',
      '/admin/content/sales-diagnostic',
      '/admin/config/salesbumm/diagnostic',
      '/admin/config/salesbumm/diagnostic/estudio',
      // Las pestañas «Agente» y «Bienvenida» se retiraron el 26-08-2026: la
      // primera editaba un prompt que ya no usaba nadie y la segunda una
      // bienvenida que debía ser de cada agente. Su contenido vive ahora en la
      // ficha del agente, que es la que se comprueba.
      '/admin/config/salesbumm/diagnostic/agentes',
      '/admin/config/salesbumm/diagnostic/agentes/sales_leadership_diagnostic',
      '/admin/config/salesbumm/diagnostic/documentos',
      '/admin/config/salesbumm/diagnostic/marca',
      '/admin/reports/status',
    ]) {
      await abrir(ruta, 'admin');
    }

    // Los formularios se ENVÍAN. Que su página cargue no dice nada: los tres
    // fallos de configuración de este proyecto solo aparecían al guardar.
    await guarda('/admin/config/salesbumm/diagnostic/marca', 'Marca');
    // La ficha del agente reúne prompt y bienvenida. Guardarla SIN tocar nada
    // tiene que dejarla idéntica: los navegadores envían saltos CRLF y hubo un
    // momento en que cada guardado engordaba el prompt y cambiaba su huella.
    await guarda(
      '/admin/config/salesbumm/diagnostic/agentes/sales_leadership_diagnostic',
      'Ficha del agente',
    );

    await legibleEnAmbosModos(
      '/admin/config/salesbumm/diagnostic/estudio',
      '.sld-studio__panel-title',
      'título del estudio',
    );
  } else {
    fallos.push('admin: falta SLD_ULI en el entorno, no se comprobó nada como administrador');
  }

  // --- La marca se APLICA, no solo se inyecta ------------------------------
  //
  // Se comprueba el color efectivo y no la presencia del <style>. La diferencia
  // no es teórica: durante días el bloque se inyectó con los valores correctos
  // y no pintó nada, porque los escribía en `:root` mientras el módulo declara
  // sus tokens en `.sld`, y una variable declarada en el propio elemento gana
  // siempre a la heredada. Mirar que el <style> existiera daba «bien».
  for (const [ruta, selector, quien] of [
    ['/', '.sld-home__frame', 'portada'],
    ['/sales-diagnostic', '.sld', 'panel del alumno'],
  ]) {
    await page.goto(`${SITIO}${ruta}`, { waitUntil: 'networkidle' });

    const comparacion = await page.evaluate((sel) => {
      const bloque = document.querySelector('style[data-sld-branding]');
      if (!bloque) return { sinMarca: true };

      const pedido = (bloque.textContent.match(/--sld-color-primary:\s*(#[0-9a-fA-F]{3,6})/) || [])[1];
      const el = document.querySelector(sel);
      if (!pedido || !el) return { sinMarca: true };

      return {
        pedido: pedido.toLowerCase(),
        efectivo: getComputedStyle(el).getPropertyValue('--sld-color-primary').trim().toLowerCase(),
      };
    }, selector);

    if (comparacion.sinMarca) {
      // Sin marca configurada no hay nada que comprobar: es un estado válido.
      continue;
    }

    anotar(comparacion.pedido === comparacion.efectivo, `marca aplicada: ${quien}`,
      `el <style> pide ${comparacion.pedido} y el elemento usa ${comparacion.efectivo}`);
  }

  // --- El formulario de inicio de sesion es usable --------------------------
  //
  // Se miden las etiquetas. Una regla de la portada llego a dejar el formulario
  // dentro de una rejilla de doce columnas, ocupando una sola: el campo medía
  // 31 pixeles y «Nombre de usuario» se rompia letra por letra en vertical. La
  // pagina cargaba con codigo 200 y sin un solo error de consola.
  await page.goto(`${SITIO}/user/logout/confirm`, { waitUntil: 'networkidle' }).catch(() => {});
  await page.locator('input[type=submit], button[type=submit]').first().click().catch(() => {});
  await page.goto(`${SITIO}/user/login`, { waitUntil: 'networkidle' });

  const formulario = await page.evaluate(() => {
    const campo = document.querySelector('#edit-name');
    const etiquetas = [...document.querySelectorAll('.sld-login label')];

    return {
      anchoCampo: campo ? Math.round(campo.getBoundingClientRect().width) : 0,
      etiquetasRotas: etiquetas
        .filter((l) => {
          const r = l.getBoundingClientRect();
          // Estrecha y alta significa texto partido en vertical.
          return r.width < 60 && r.height > 40;
        })
        .map((l) => l.textContent.trim().slice(0, 24)),
    };
  });

  anotar(formulario.anchoCampo > 200, 'login: el campo tiene ancho usable',
    `el campo de usuario mide ${formulario.anchoCampo}px`);

  anotar(formulario.etiquetasRotas.length === 0, 'login: las etiquetas se leen en horizontal',
    `rotas en vertical: ${formulario.etiquetasRotas.join(', ')}`);

  // --- Gestor: llega a sus herramientas por ENLACE, no escribiendo la URL ---
  const dondeAterrizaGestor = await entrar(CUENTAS.gestor);

  anotar(dondeAterrizaGestor.includes('/admin/content/sales-diagnostic'),
    'gestor: aterriza en su listado', `aterrizó en ${dondeAterrizaGestor}`);

  // El gestor tiene que poder SALIR desde todas sus pantallas. Su rol lleva
  // «ver el tema de administracion» pero NO «acceder a la barra de
  // herramientas», asi que durante semanas se le sirvieron sus cuatro
  // secciones sin barra: sin navegacion y sin cerrar sesion. Lo encontro el
  // usuario el 04-09-2026, y no lo vio ninguna prueba porque todas entraban
  // como administrador, que si tiene la barra.
  for (const [ruta, nombre] of [
    ['/admin/content/sales-diagnostic', 'resultados'],
    ['/admin/config/salesbumm/diagnostic/agentes', 'agentes'],
    ['/admin/config/salesbumm/diagnostic/estudio', 'estudio'],
    ['/admin/config/salesbumm/diagnostic/documentos', 'documentos'],
  ]) {
    await page.goto(`${SITIO}${ruta}`, { waitUntil: 'networkidle' });

    const marco = await page.evaluate(() => {
      const barra = document.querySelector('.sld-home__bar');
      return {
        salida: document.querySelectorAll('a[href*="/user/logout"]').length,
        // El fondo se mide: sin los tokens del modulo la barra sale
        // TRANSPARENTE, no con el azul de fabrica, y el logotipo blanco
        // desaparece sobre ella.
        fondo: barra ? getComputedStyle(barra).backgroundColor : null,
      };
    });

    anotar(marco.salida > 0, `gestor: puede cerrar sesion desde ${nombre}`,
      'no hay ningun enlace de salida en esta pantalla');
    anotar(marco.fondo !== null && marco.fondo !== 'rgba(0, 0, 0, 0)',
      `gestor: la barra de ${nombre} lleva su color`,
      `fondo ${marco.fondo}`);
  }

  await page.goto(`${SITIO}/admin/content/sales-diagnostic`, { waitUntil: 'networkidle' });

  const enlaceEstudio = await page.locator('a[href*="/estudio"]').count();
  anotar(enlaceEstudio > 0, 'gestor: tiene enlace al estudio',
    'ningún enlace lleva al estudio; tener permiso no basta si no hay por dónde llegar');

  if (enlaceEstudio > 0) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('a[href*="/estudio"]'),
    ]);

    // Con VARIOS agentes el estudio pregunta primero cual, en vez de abrir el
    // editor. Es correcto, asi que la prueba elige uno y sigue; dar por hecho
    // que siempre se entra directo la hacia fallar en cuanto habia dos.
    const eleccion = await page.locator('a[href*="/estudio/agente/"]').count();

    if (eleccion > 0) {
      // La pantalla de eleccion NO debe ofrecer la caja del chat. Durante dias
      // la ofrecio: el gestor escribia, no pasaba nada y parecia roto, porque
      // el JS que la anima no se adjunta hasta que hay agente. Un control
      // muerto es peor que ninguno. Esta prueba existe porque el fallo se
      // colo justo por aqui: el humo entraba DIRECTO al estudio de un agente y
      // nunca miraba esta pantalla.
      anotar(
        await page.locator('[data-sld-composer]').count() === 0,
        'gestor: la eleccion de agente no ofrece un chat que no funciona',
        'hay caja de mensaje en una pantalla sin conversacion',
      );

      // Y debe llevar sus estilos. Sin ellos los botones salian pegados uno a
      // otro y se leian como un solo nombre.
      const separados = await page.evaluate(() => {
        const marco = document.querySelector('.sld-studio');
        return marco ? getComputedStyle(marco).display === 'grid' : false;
      });
      anotar(separados, 'gestor: la eleccion de agente lleva sus estilos',
        'la hoja del estudio no se cargo en esta pantalla');

      // Documentos hace la MISMA pregunta y comparte el selector desde el
      // 02-09-2026. Se comprueba aparte porque antes cada pantalla construia
      // el suyo y divergieron sin que nadie lo viera: una pintaba botones y la
      // otra una lista de viñetas.
      await page.goto(`${SITIO}/admin/config/salesbumm/diagnostic/documentos`, { waitUntil: 'networkidle' });

      const docs = await page.evaluate(() => {
        const caja = document.querySelector('.sld-chooser__options');
        return {
          hayCaja: !!caja,
          separacion: caja ? getComputedStyle(caja).gap : null,
          opciones: caja ? caja.querySelectorAll('a').length : 0,
        };
      });

      anotar(docs.hayCaja && docs.opciones > 1 && docs.separacion !== '0px' && docs.separacion !== 'normal',
        'gestor: documentos usa el mismo selector de agente que el estudio',
        `caja ${docs.hayCaja}, ${docs.opciones} opcion(es), separacion ${docs.separacion}`);

      await page.goBack({ waitUntil: 'networkidle' });

      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('a[href*="/estudio/agente/"]'),
      ]);
    }

    const campos = await page.locator('.sld-studio__form textarea').count();
    const chat = await page.locator('[data-sld-input]').count();
    anotar(campos >= 3 && chat === 1, 'gestor: el estudio trae editor y chat',
      `${campos} campos de prompt y ${chat} compositor de chat`);
  }

  // --- Alumno: entra, ve la bienvenida y puede escribir --------------------
  const dondeAterrizaAlumno = await entrar(CUENTAS.alumno);

  anotar(dondeAterrizaAlumno.includes('/sales-diagnostic'),
    'alumno: aterriza en su panel', `aterrizó en ${dondeAterrizaAlumno}`);

  // Con VARIOS agentes el panel enseña tarjetas y el boton de inicio vive en
  // la pagina de cada agente (31-08-2026). Con uno solo sigue estando en el
  // panel. La prueba recorre el camino que le toque al alumno de pruebas, que
  // es justamente lo que hay que comprobar: que llega a poder empezar.
  const tarjetas = await page.locator('.sld-card').count();

  if (tarjetas > 0) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('.sld-card'),
    ]);

    anotar(page.url().includes('/agente/'), 'alumno: la tarjeta lleva a su agente',
      `acabó en ${page.url()}`);
  }

  const boton = await page.locator('.sld-dashboard__start button').count();
  anotar(boton > 0, 'alumno: puede iniciar un diagnóstico',
    tarjetas > 0
      ? 'no hay botón de inicio en la página del agente'
      : 'no hay botón de inicio en el panel');

  if (boton > 0) {
    // Iniciar no gasta llamadas al proveedor de IA: solo las gasta enviar un
    // mensaje. Por eso la prueba llega hasta aquí y no más.
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('.sld-dashboard__start button'),
    ]);

    const enConversacion = page.url().includes('/session/');
    anotar(enConversacion, 'alumno: llega a la conversación', `acabó en ${page.url()}`);

    if (enConversacion) {
      // La bienvenida solo existe ANTES del primer turno, y eso es correcto:
      // una vez hay conversación el alumno ya sabe de qué va esto y el cartel
      // estorbaría al leer.
      //
      // El botón del panel dice «Continuar» cuando el alumno tiene una
      // conversación a medias, así que esta prueba puede aterrizar en una que
      // ya tiene mensajes. Durante días esto produjo tres fallos permanentes
      // que no eran del código, y una prueba que falla siempre se deja de
      // leer, que es peor que no tenerla. Se comprueba primero DÓNDE se
      // aterrizó y solo entonces qué debe haber.
      const turnos = await page.locator('.sld-chat__message').count();

      if (turnos === 0) {
        const sugerencias = await page.locator('.sld-chat__suggestion').count();
        anotar(sugerencias > 0, 'alumno: ve las sugerencias de inicio',
          'la conversación arranca vacía, sin nada que oriente al alumno');

        await legibleEnAmbosModos(
          page.url().replace(SITIO, ''),
          '.sld-chat__welcome-title',
          'título de la bienvenida',
        );
      }
      else {
        // Reanudar también hay que comprobarlo: que se vean los turnos
        // anteriores y que pueda seguir escribiendo.
        const compositor = await page.locator('[data-sld-input]').count();
        anotar(compositor === 1, 'alumno: puede seguir su conversación a medias',
          `reanudó con ${turnos} turno(s) pero no hay dónde escribir`);
      }
    }
  }

  return {
    resultado: fallos.length === 0 ? 'TODO BIEN' : `${fallos.length} FALLO(S)`,
    fallos,
    comprobaciones: hechas.length,
  };
}
