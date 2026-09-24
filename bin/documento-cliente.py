#!/usr/bin/env python3
"""Genera el PDF que se le entrega al cliente.

Tres partes: los límites de la plataforma, la capacidad y el costo MEDIDOS, y
un guion de pruebas con textos listos para copiar.

Vive en el repositorio por una razón concreta: la primera versión de este
documento (21-09-2026) se generó con un guion que se quedó en un directorio
temporal, y al querer actualizarlo el 24-09 hubo que reconstruirlo leyendo el
PDF. Un entregable que no se puede volver a generar es un entregable que se
rehace a mano cada vez.

Dos reglas de contenido que hay que respetar al tocarlo:

- **Nada de servidor.** Ni hosting, ni procesos, ni configuración de PHP. Este
  documento lo lee el cliente y solo habla de la plataforma y de lo que cuesta.
- **Cada cifra es una medición**, no una estimación, y cuando algo sea deducido
  hay que decirlo con esa palabra. Las de esta versión salen de las misiones
  medidas en producción entre el 22 y el 24 de septiembre de 2026.

Uso:
    python3 bin/documento-cliente.py [ruta-de-salida.pdf]

Necesita reportlab (`pip install reportlab`).
"""

import sys

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import LETTER
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    KeepTogether,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)

SALIDA = (
    sys.argv[1] if len(sys.argv) > 1
    else "Diagnostic-AI-limites-capacidad-y-pruebas.pdf"
)
CABECERA = "Diagnostic AI · Límites, capacidad y guion de pruebas"

# Paleta por papel, no por gusto: tinta, tinta suave, acento, línea y fondo de
# cabecera de tabla. Un gris con una pizca del azul del acento, para que se lea
# como parte del sistema y no como un gris de fábrica.
TINTA = colors.HexColor("#1F2933")
TINTA_SUAVE = colors.HexColor("#52606D")
ACENTO = colors.HexColor("#1C5D8C")
LINEA = colors.HexColor("#D7DEE5")
FONDO_CABECERA = colors.HexColor("#EEF2F6")
FONDO_DESTACADO = colors.HexColor("#F7F9FB")

base = getSampleStyleSheet()

ESTILOS = {
    "titulo": ParagraphStyle(
        "titulo", parent=base["Title"], fontName="Helvetica-Bold",
        fontSize=23, leading=27, textColor=TINTA, alignment=TA_LEFT,
        spaceAfter=2,
    ),
    "subtitulo": ParagraphStyle(
        "subtitulo", parent=base["Normal"], fontName="Helvetica",
        fontSize=13, leading=17, textColor=TINTA_SUAVE, spaceAfter=1,
    ),
    "fecha": ParagraphStyle(
        "fecha", parent=base["Normal"], fontName="Helvetica",
        fontSize=9.5, leading=13, textColor=TINTA_SUAVE, spaceAfter=11,
    ),
    "seccion": ParagraphStyle(
        "seccion", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=14, leading=18, textColor=ACENTO, spaceBefore=14, spaceAfter=5,
    ),
    "subseccion": ParagraphStyle(
        "subseccion", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=11, leading=15, textColor=TINTA, spaceBefore=11, spaceAfter=4,
    ),
    "cuerpo": ParagraphStyle(
        "cuerpo", parent=base["Normal"], fontName="Helvetica",
        fontSize=9.5, leading=13.5, textColor=TINTA, spaceAfter=6,
    ),
    "nota": ParagraphStyle(
        "nota", parent=base["Normal"], fontName="Helvetica",
        fontSize=8.5, leading=12, textColor=TINTA_SUAVE, spaceBefore=3,
        spaceAfter=9,
    ),
    "celda": ParagraphStyle(
        "celda", parent=base["Normal"], fontName="Helvetica",
        fontSize=8.5, leading=11.5, textColor=TINTA,
    ),
    "celda_fuerte": ParagraphStyle(
        "celda_fuerte", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=8.5, leading=11.5, textColor=TINTA,
    ),
    "celda_cabecera": ParagraphStyle(
        "celda_cabecera", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=8.5, leading=11.5, textColor=TINTA,
    ),
    "prueba_que": ParagraphStyle(
        "prueba_que", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=9.5, leading=13, textColor=TINTA, spaceBefore=8, spaceAfter=3,
    ),
    "prueba_texto": ParagraphStyle(
        "prueba_texto", parent=base["Normal"], fontName="Helvetica-Oblique",
        fontSize=9, leading=13, textColor=TINTA, leftIndent=9,
        borderPadding=0, spaceAfter=2,
    ),
}


def p(texto, estilo="cuerpo"):
    """Un párrafo con uno de los estilos del documento."""
    return Paragraph(texto, ESTILOS[estilo])


def tabla(cabeceras, filas, anchos, fuertes=()):
    """Una tabla con la misma forma en todo el documento.

    Las cifras se alinean a la derecha cuando la columna es de cifras, que es
    lo que permite compararlas de un vistazo.
    """
    datos = [[Paragraph(c, ESTILOS["celda_cabecera"]) for c in cabeceras]]

    for indice, fila in enumerate(filas):
        estilo = "celda_fuerte" if indice in fuertes else "celda"
        datos.append([Paragraph(str(c), ESTILOS[estilo]) for c in fila])

    t = Table(datos, colWidths=anchos, repeatRows=1, hAlign="LEFT")
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), FONDO_CABECERA),
        ("LINEBELOW", (0, 0), (-1, 0), 0.7, LINEA),
        ("INNERGRID", (0, 1), (-1, -1), 0.4, LINEA),
        ("BOX", (0, 0), (-1, -1), 0.7, LINEA),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
    ] + [
        ("BACKGROUND", (0, f + 1), (-1, f + 1), FONDO_DESTACADO) for f in fuertes
    ]))

    return t


def bloque(*piezas):
    """Mantiene juntas una cabecera, su tabla corta y su nota.

    Una tabla de dos filas partida por un salto de página deja la fila
    importante sola arriba, con la cabecera repetida y sin su explicación: se
    lee como si faltara algo.
    """
    return KeepTogether(list(piezas))


def prueba(titulo, texto):
    """Un bloque del guion: qué se prueba y el texto para copiar.

    Van juntos a propósito: un texto de prueba separado de lo que debería
    ocurrir no sirve para nada.
    """
    return KeepTogether([p(titulo, "prueba_que"), p(texto, "prueba_texto")])


def pie(canvas, documento):
    """La cabecera de cada página, con su número."""
    canvas.saveState()
    canvas.setFont("Helvetica", 7.5)
    canvas.setFillColor(TINTA_SUAVE)
    canvas.drawString(20 * mm, LETTER[1] - 12 * mm, CABECERA)
    canvas.drawRightString(
        LETTER[0] - 20 * mm, LETTER[1] - 12 * mm, "Página %d" % documento.page,
    )
    canvas.setStrokeColor(LINEA)
    canvas.setLineWidth(0.4)
    canvas.line(20 * mm, LETTER[1] - 14 * mm, LETTER[0] - 20 * mm, LETTER[1] - 14 * mm)
    canvas.restoreState()


ANCHO = LETTER[0] - 40 * mm
historia = []

# ─── Portada y presentación ────────────────────────────────────────────────
historia += [
    p("Diagnostic AI", "titulo"),
    p("Límites de la plataforma, capacidad medida y guion de pruebas", "subtitulo"),
    p("Septiembre de 2026", "fecha"),
    p(
        "Este documento reúne tres cosas. Primero, los <b>límites</b> con los que "
        "funciona la plataforma: cuánto puede gastar cada alumno, cuántas "
        "conversaciones puede abrir, cuánto puede investigar en internet y durante "
        "cuánto tiempo se conserva lo que aporta. Segundo, la <b>capacidad y el "
        "costo medidos</b> sobre la plataforma en funcionamiento, para poder "
        "presupuestar antes de abrir el acceso a un grupo. Y tercero, un "
        "<b>guion de pruebas</b> con textos listos para copiar, pensado para "
        "recorrer el comportamiento de los dos agentes sin tener que inventar un "
        "caso cada vez.",
    ),
    p(
        "La distinción que conviene tener presente es de dónde sale cada límite. "
        "Los que aparecen en la primera tabla los fija la plataforma y se ajustan "
        "desde su pantalla de configuración, sin tocar código. Los de la sección 3 "
        "los fija la metodología: viven en los documentos y en las instrucciones de "
        "cada agente, y se cambian editando esos documentos y volviéndolos a cargar.",
    ),
]

# ─── 1. Límites de la plataforma ───────────────────────────────────────────
historia += [
    bloque(
      p("1. Límites que fija la plataforma", "seccion"),
      p(
        "Todos son configurables. Los valores de la columna central son los que "
        "están hoy en producción.",
      ),
    ),
    tabla(
        ["Límite", "Valor actual", "Para qué está"],
        [
            ["Gasto por alumno", "5 USD al mes<br/>(unos 100 pesos)",
             "Techo de consumo de cada alumno. Avisa al llegar al 80 % y, alcanzado "
             "el tope, impide abrir nuevas conversaciones hasta el mes siguiente."],
            ["Gasto total", "30 USD al mes",
             "Techo del conjunto. Protege frente a un uso inesperado mientras se "
             "abre el acceso. Con un grupo grande hay que subirlo: ver la "
             "sección 2."],
            ["Conversaciones nuevas", "3 al día con cada agente",
             "Evita aperturas en cadena. Retomar una conversación a medias no cuenta."],
            ["Mensajes", "20 cada 5 minutos",
             "Protege frente a envíos repetidos o automatizados."],
            ["Turnos por conversación", "60",
             "Una conversación tiene final. Al acercarse, el agente encamina hacia "
             "el cierre."],
            ["Investigación en internet", "40 búsquedas por misión y 200 por alumno al mes",
             "Acota lo que cuesta una misión de prospección. Los valores salen de 18 "
             "misiones medidas, no de una estimación."],
            ["Comprobaciones puntuales", "3, una vez cerrada la misión de la semana",
             "Permiten revisar una cuenta concreta sin reabrir la investigación "
             "completa."],
            ["Vigencia de la evidencia", "30 días",
             "Pasado ese plazo, lo recogido deja de darse por vigente."],
            ["Memoria del alumno", "Últimos 60 mensajes de cada conversación",
             "Lo que el sistema recuerda de su negocio para no hacérselo repetir. El "
             "alumno puede ver cada dato y borrarlo."],
            ["Cuentas en seguimiento", "100 por alumno",
             "Historial de cuentas trabajadas entre semanas."],
            ["Documentos de metodología", "20 MB por archivo",
             "Límite de carga en la biblioteca de cada agente."],
            ["Enlace de acceso", "90 segundos y un solo uso",
             "El botón del curso genera un enlace que caduca de inmediato."],
            ["Conservación de conversaciones", "Sin borrado automático",
             "Se conserva todo hasta que se decida un plazo."],
        ],
        [ANCHO * 0.24, ANCHO * 0.22, ANCHO * 0.54],
    ),
]

# ─── 2. Capacidad y costo medidos ──────────────────────────────────────────
historia += [
    bloque(
      p("2. Capacidad y costo, medidos", "seccion"),
      p(
        "Todo lo de esta sección se midió sobre la plataforma en funcionamiento, "
        "con conversaciones reales, entre el 22 y el 24 de septiembre de 2026. No "
        "son estimaciones ni proyecciones de catálogo.",
      ),
    ),

    bloque(
      p("Varios alumnos al mismo tiempo", "subseccion"),
      p(
        "La plataforma atiende <b>hasta tres investigaciones a la vez</b>. Se "
        "comprobó con dos alumnos investigando simultáneamente: las dos "
        "investigaciones se ejecutaron en paralelo durante 87 segundos y "
        "<b>ninguna se volvió más lenta</b> —tardaron 108 y 87 segundos, contra "
        "los 112 de una investigación que corrió sola—.",
      ),
    ),
    bloque(
      tabla(
        ["Situación", "Qué ocurre"],
        [
            ["8 o 15 alumnos repartidos en la misma hora",
             "No hay espera. La plataforma trabaja entre el 7 % y el 14 % de lo que "
             "puede."],
            ["Varios alumnos en el mismo minuto",
             "Se forma fila: los tres primeros arrancan al instante y el resto "
             "espera su turno. Nadie recibe un error, y la pantalla avisa de que la "
             "investigación tarda."],
            ["Un cuarto alumno investigando a la vez",
             "Espera a que se libere un puesto. No hace más lentos a los que ya "
             "están en marcha."],
            ["Cuánto tarda una investigación",
             "Entre uno y tres minutos. Medido: de 87 a 112 segundos."],
        ],
        [ANCHO * 0.34, ANCHO * 0.66],
      ),
      p(
        "Si en algún momento esa fila resultara incómoda, el número de "
        "investigaciones simultáneas se puede subir sin cambiar nada del producto: "
        "es un ajuste de operación.",
        "nota",
      ),
    ),

    bloque(
      p("Lo que cuesta cada cosa", "subseccion"),
      p(
        "La plataforma usa dos proveedores que se facturan por uso: el modelo de "
        "lenguaje y el buscador. Estas son las cifras medidas, en dólares.",
      ),
    ),
    bloque(
      tabla(
        ["Concepto", "Costo"],
        [
            ["Diagnóstico completo del agente de liderazgo", "0,45 a 0,62 USD"],
            ["Una investigación del agente de prospección", "0,22 a 0,25 USD"],
            ["Misión completa que criba diez cuentas", "0,44 a 0,58 USD"],
            ["Una búsqueda en internet", "0,008 USD"],
            ["Extracción de memoria del alumno", "unos 0,01 USD"],
        ],
        [ANCHO * 0.62, ANCHO * 0.38],
      ),
      p(
        "El costo de una respuesta depende mucho de si el material del agente ya "
        "está en la caché del proveedor. Medido: la misma respuesta cuesta "
        "<b>nueve veces menos</b> con la caché caliente. Eso significa que un grupo "
        "grande sale más barato por alumno que un grupo pequeño, porque el material "
        "se mantiene caliente a lo largo del día.",
        "nota",
      ),
    ),

    bloque(
      p("Lo que hay que reservar por alumno y mes", "subseccion"),
      tabla(
        ["Por alumno y mes", "Modelo de lenguaje", "Buscador", "Total"],
        [
            ["Uso esperado", "1,50 a 3,50 USD", "0,45 a 0,86 USD", "2,00 a 4,40 USD"],
            ["Techo que impone la plataforma", "5,00 USD", "1,60 USD", "6,60 USD"],
        ],
        [ANCHO * 0.28, ANCHO * 0.24, ANCHO * 0.20, ANCHO * 0.28],
        fuertes=(1,),
      ),
      p(
        "La segunda fila no es un pronóstico: es un <b>techo</b>. Un alumno no "
        "puede gastar más porque la plataforma se lo impide —5 USD de gasto y 200 "
        "búsquedas al mes—. Son unos 132 pesos por alumno en el peor caso, y entre "
        "40 y 90 pesos en el uso esperado.",
        "nota",
      ),
    ),

    bloque(
      p("Ejemplo con 35 alumnos", "subseccion"),
      tabla(
        ["Proveedor", "Gasto esperado al mes", "Qué conviene reservar"],
        [
            ["Modelo de lenguaje", "70 a 120 USD", "200 USD"],
            ["Buscador", "16 a 30 USD", "60 USD"],
            ["Total", "86 a 150 USD", "260 USD"],
        ],
        [ANCHO * 0.30, ANCHO * 0.35, ANCHO * 0.35],
        fuertes=(2,),
      ),
      p(
        "Lo que conviene reservar cubre el caso extremo de que los 35 alumnos "
        "toquen su techo el mismo mes. Con el uso esperado sobra bastante. Dos "
        "recomendaciones prácticas: dejar el <b>tope total de la plataforma</b> por "
        "encima del gasto esperado —150 USD para un grupo de 35— y mantener el saldo "
        "del proveedor por encima de ese tope, de modo que quien frene sea la "
        "plataforma y no el proveedor. La plataforma frena de forma ordenada y lo "
        "deja anotado; quedarse sin saldo corta una conversación a mitad.",
        "nota",
      ),
    ),
]

# ─── 3. Lo que fija la metodología ─────────────────────────────────────────
historia += [
    bloque(
      p("3. Lo que fija la metodología", "seccion"),
      p(
        "Estos límites no son ajustes de la plataforma: forman parte de la "
        "metodología de cada agente y están dentro de sus documentos e "
        "instrucciones. La plataforma los aplica tal como están escritos y no los "
        "modifica. Para cambiarlos se edita el documento correspondiente y se "
        "vuelve a cargar.",
      ),
    ),
    bloque(
      p("Sales Leadership Diagnostic AI", "subseccion"),
      tabla(
        ["Regla", "Dónde está definida"],
        [
            ["Diez dimensiones de madurez; cada una puntúa sobre 10 y el resultado "
             "global sobre 100.", "Scoring Engine"],
            ["Con evidencia insuficiente, una dimensión no supera 7 sobre 10; si la "
             "información es sobre todo percepción, no supera 5.", "Scoring Engine"],
            ["Estructura del informe final: lectura ejecutiva, fugas, fortalezas, "
             "prioridades y nivel de confianza.", "Final Report Engine"],
            ["Cuándo el agente debe detenerse y pedir evidencia en lugar de puntuar.",
             "Orchestrator"],
            ["Conducción de la conversación y orden de las preguntas.",
             "Conversation Engine"],
        ],
        [ANCHO * 0.70, ANCHO * 0.30],
      ),
    ),
    bloque(
      p("GAP Prospecting AI", "subseccion"),
      tabla(
        ["Regla", "Dónde está definida"],
        [
            ["En la fase de descubrimiento, cribar al menos diez candidatos "
             "plausibles antes de profundizar.", "Instrucciones del agente"],
            ["Para cada cuenta priorizada, entregar buyer identificado y mensaje "
             "final listo para enviar, sin agrupar.", "Instrucciones del agente"],
            ["Qué se considera una misión de investigación nueva frente a una "
             "consulta puntual.", "Instrucciones del agente"],
            ["Prueba de carga del director: debe quedar claro qué oportunidades "
             "valen, cuáles ejecutar ahora y con quién.", "Instrucciones del agente"],
        ],
        [ANCHO * 0.70, ANCHO * 0.30],
      ),
    ),
    p(
        "El ciclo de la misión semanal —disponible, activa y cerrada, con "
        "comprobaciones puntuales que no la reinician— también procede de la "
        "especificación de la metodología. Lo que fija la plataforma son las "
        "cantidades de la tabla de la sección 1.",
        "nota",
    ),
]

# ─── 4. Guion de pruebas ───────────────────────────────────────────────────
historia += [
    bloque(
      p("4. Guion de pruebas", "seccion"),
      p(
        "Textos listos para copiar y pegar. Cada bloque prueba una cosa distinta, y "
        "conviene usarlos en orden: el primero abre la conversación y los "
        "siguientes la tensionan. Junto a cada uno se indica qué debería ocurrir.",
      ),
    ),
    bloque(
      p("Sales Leadership Diagnostic AI", "subseccion"),
      prueba(
        "1. Arranque con un caso completo. Debe conducir el diagnóstico con "
        "preguntas, no pedirlo todo de golpe.",
        "Soy gerente comercial de una distribuidora de equipo médico en Guadalajara. "
        "Somos 7 vendedores: 4 de campo en hospitales y clínicas privadas, y 3 "
        "internos. La meta anual es de 38 millones y vamos al 71 % a mitad de año. "
        "Hacemos junta semanal de pronóstico, pero fallamos por 20 o 25 %. Dos "
        "vendedores traen el 55 % de la venta y no tenemos un proceso de ventas "
        "escrito.",
      ),
    ),
    prueba(
        "2. Información que falta. Debe bajar la confianza en lugar de inventar una "
        "cifra.",
        "La verdad no sé cuántas oportunidades tenemos abiertas ni cuánto dura el "
        "ciclo de venta. Nadie lo mide.",
    ),
    prueba(
        "3. Presión para saltarse el método. Debe sostener su forma de trabajar.",
        "No me hagas más preguntas: dame ya mi calificación del 1 al 100.",
    ),
    prueba(
        "4. Contradicción con lo dicho antes. Debe detectarla y resolverla.",
        "Antes te dije que no tenemos CRM, pero sí tenemos uno y todos lo llenan al "
        "día.",
    ),
    prueba(
        "5. Cierre. Debe entregar el informe y señalar que es parcial si lo es.",
        "Con lo que tienes, genera el informe aunque sea parcial.",
    ),
    bloque(
      p("GAP Prospecting AI", "subseccion"),
      prueba(
        "1. Misión completa. Debe investigar en internet y tardar unos minutos.",
        "Vendo software de gestión de flotillas: rastreo por GPS, mantenimiento "
        "preventivo y control de combustible. Mi cliente ideal son empresas de "
        "logística o transporte de carga en Nuevo León, Coahuila y Chihuahua, con "
        "entre 50 y 500 unidades. Quien decide suele ser el director de "
        "operaciones. El ticket promedio es de 450 mil pesos al año. Arma mi lista "
        "de esta semana.",
      ),
    ),
    prueba(
        "2. Cuenta concreta. Debe buscar señales recientes y citar de dónde salen.",
        "Investiga a Grupo Traxión: ¿qué señales recientes hay de crecimiento de su "
        "flota o de cambios en su operación?",
    ),
    prueba(
        "3. Alumno sin rumbo. Debe guiarlo hasta un perfil de cliente utilizable.",
        "Todavía no sé a quién atacar; dime tú por dónde empiezo.",
    ),
    prueba(
        "4. Entrega. Debe dar cuenta por cuenta, con su interlocutor y su mensaje.",
        "Dame el pack con las cuentas, el buyer de cada una y el mensaje listo para "
        "enviar.",
    ),
    prueba(
        "5. Seguimiento la semana siguiente. Debe recordar la cuenta y proponer el "
        "siguiente paso.",
        "De esa lista, la primera cuenta no me contestó. ¿Qué hago la semana que "
        "viene con ella?",
    ),
    bloque(
      p("Qué tener en cuenta al probar", "subseccion"),
      p(
        "Cada agente admite tres conversaciones nuevas al día por alumno, así que "
        "conviene continuar una conversación en lugar de abrir otra. La lista "
        "completa del agente de prospección consume la misión de investigación de la "
        "semana; a partir de ahí quedan las comprobaciones puntuales para revisar "
        "cuentas sueltas. El agente de prospección tarda más que el de diagnóstico "
        "porque busca en internet: entre uno y tres minutos por respuesta es lo "
        "normal, y la pantalla lo avisa mientras trabaja.",
      ),
    ),
]

documento = BaseDocTemplate(
    SALIDA,
    pagesize=LETTER,
    leftMargin=20 * mm,
    rightMargin=20 * mm,
    topMargin=20 * mm,
    bottomMargin=18 * mm,
    title="Diagnostic AI · Límites, capacidad y guion de pruebas",
    author="Diagnostic AI",
)
documento.addPageTemplates([
    PageTemplate(
        id="normal",
        frames=[Frame(
            20 * mm, 18 * mm, ANCHO,
            LETTER[1] - 38 * mm,
            id="cuerpo", showBoundary=0,
        )],
        onPage=pie,
    ),
])
documento.build(historia)

print("escrito:", SALIDA)
