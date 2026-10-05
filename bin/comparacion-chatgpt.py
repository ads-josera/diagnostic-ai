#!/usr/bin/env python3
"""Genera el PDF para el cliente con la nueva prueba frente a ChatGPT.

Nace del 02-10-2026: el cliente comparó el Weekly GOLD Pack del agente GAP
Prospecting con el que le dio ChatGPT con el mismo prompt y los mismos
documentos, y ganó ChatGPT. Tras las mejoras se repitió su mismo caso el
03-10-2026 con su cuenta, en producción (misión 37), y este documento le
cuenta qué se aprendió, qué se mejoró y cómo quedó la comparación.

Vive en el repositorio por la misma razón que documento-cliente.py: un
entregable que no se puede volver a generar se rehace a mano cada vez.

Reglas de contenido al tocarlo:

- **Nada de servidor ni de proveedores.** Ni hosting, ni nombres de servicios
  externos, ni herramientas internas. Lo lee el cliente.
- **Cada cifra es una medición.** Las de ChatGPT salen del correo del cliente
  (10 cuentas, 13 fuentes, 2 compradores verificados, 3 mensajes, ZERO GOLD);
  las nuestras, de la misión 37 y de las fuentes que se abrieron una por una.
- **Lo que no se sostiene se dice.** El comprador de Banco Guayaquil está
  identificado pero mal respaldado, y el documento lo cuenta antes de que el
  cliente lo descubra.

Uso:
    python3 bin/comparacion-chatgpt.py [ruta-de-salida.pdf]

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
    else "GAP-Prospecting-nueva-prueba-frente-a-ChatGPT.pdf"
)
CABECERA = "GAP Prospecting AI · Nueva prueba frente a ChatGPT"

# La misma paleta que documento-cliente.py, para que los documentos que recibe
# el cliente se lean como de la misma casa.
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
        fontSize=21, leading=25, textColor=TINTA, alignment=TA_LEFT,
        spaceAfter=2,
    ),
    "subtitulo": ParagraphStyle(
        "subtitulo", parent=base["Normal"], fontName="Helvetica",
        fontSize=12.5, leading=16, textColor=TINTA_SUAVE, spaceAfter=1,
    ),
    "fecha": ParagraphStyle(
        "fecha", parent=base["Normal"], fontName="Helvetica",
        fontSize=9.5, leading=13, textColor=TINTA_SUAVE, spaceAfter=12,
    ),
    "seccion": ParagraphStyle(
        "seccion", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=13.5, leading=17, textColor=ACENTO, spaceBefore=13, spaceAfter=5,
    ),
    "cuerpo": ParagraphStyle(
        "cuerpo", parent=base["Normal"], fontName="Helvetica",
        fontSize=10, leading=14.5, textColor=TINTA, spaceAfter=7,
    ),
    "punto": ParagraphStyle(
        "punto", parent=base["Normal"], fontName="Helvetica",
        fontSize=10, leading=14.5, textColor=TINTA, leftIndent=14,
        bulletIndent=3, spaceAfter=5,
    ),
    "celda": ParagraphStyle(
        "celda", parent=base["Normal"], fontName="Helvetica",
        fontSize=9, leading=12, textColor=TINTA,
    ),
    "celda_cabecera": ParagraphStyle(
        "celda_cabecera", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=9, leading=12, textColor=TINTA,
    ),
    "firma": ParagraphStyle(
        "firma", parent=base["Normal"], fontName="Helvetica",
        fontSize=10, leading=14.5, textColor=TINTA, spaceBefore=6,
    ),
}


def p(texto, estilo="cuerpo"):
    """Un párrafo con uno de los estilos del documento."""
    return Paragraph(texto, ESTILOS[estilo])


def punto(texto, marca="•"):
    """Un elemento de lista, con su viñeta o su número."""
    return Paragraph(texto, ESTILOS["punto"], bulletText=marca)


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


def comparacion():
    """La tabla de la comparación, con la columna nuestra destacada."""
    filas = [
        ["", "ChatGPT", "GAP Prospecting AI"],
        ["Cuentas cribadas (pool auditable)", "10", "10"],
        ["Compradores identificados con nombre y cargo", "2", "3 (2 confirmados con fuente reciente)"],
        ["Fuentes enlazadas", "13", "15, ordenadas por cuenta"],
        ["Fuentes trazables a la investigación realizada", "No verificable", "Todas"],
        ["Cuentas preparadas con mensaje de contacto", "3", "3, listos para copiar"],
        ["Resultado de liberación", "ZERO GOLD (sin acceso a CRM)", "ZERO GOLD (sin acceso a CRM)"],
    ]
    datos = [
        [Paragraph(c, ESTILOS["celda_cabecera" if i == 0 or j == 0 else "celda"]) for j, c in enumerate(f)]
        for i, f in enumerate(filas)
    ]
    t = Table(datos, colWidths=[ANCHO * 0.42, ANCHO * 0.25, ANCHO * 0.33], hAlign="LEFT")
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), FONDO_CABECERA),
        ("BACKGROUND", (2, 1), (2, -1), FONDO_DESTACADO),
        ("LINEBELOW", (0, 0), (-1, 0), 0.7, LINEA),
        ("INNERGRID", (0, 1), (-1, -1), 0.4, LINEA),
        ("BOX", (0, 0), (-1, -1), 0.7, LINEA),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
    ]))
    return t


historia = [
    p("GAP Prospecting AI", "titulo"),
    p("Nueva prueba frente a ChatGPT: qué mejoramos y cómo quedó", "subtitulo"),
    p("Octubre de 2026", "fecha"),

    p("Hola, Omar:"),
    p(
        "Después de tu comparación con ChatGPT revisamos a fondo el agente GAP "
        "Prospecting. Te comparto qué aprendimos, qué mejoramos y el resultado de "
        "repetir tu mismo caso."
    ),

    p("Lo que aprendimos de la prueba anterior y lo que mejoramos", "seccion"),
    p(
        "Tu prueba con un caso real nos permitió afinar el agente en cuatro puntos "
        "que solo se ven en uso real. Los optimizamos uno por uno:"
    ),
    punto(
        "<b>Ahora investiga con todo su margen.</b> El agente administraba su "
        "investigación de forma demasiado conservadora y se detenía antes de "
        "tiempo. Ajustamos cómo estima su margen, y hoy investiga a fondo cada "
        "misión.", "1."
    ),
    punto(
        "<b>Fuentes visibles y enlazadas.</b> Cada fuente aparece ahora enlazada, "
        "con su medio y su fecha, agrupada por cuenta.", "2."
    ),
    punto(
        "<b>Investigación más profunda.</b> Ampliamos lo que trae cada búsqueda: "
        "más resultados y más contenido de cada página.", "3."
    ),
    punto(
        "<b>Identificación de compradores reforzada.</b> El agente completa siempre "
        "este paso antes de cerrar el Pack, sin relajar ninguna de las reglas de "
        "verificación de tu metodología.", "4."
    ),
    p("Además, añadimos dos controles de calidad permanentes:"),
    punto(
        "<b>Antes de cualquier cambio en el agente, repetimos automáticamente tu "
        "mismo caso</b> y comprobamos que cribe 10 cuentas, que investigue a fondo, "
        "que busque a los compradores y que todas sus fuentes sean reales. Si algo "
        "no cumple, el cambio no se publica."
    ),
    punto(
        "<b>El sistema verifica cada fuente citada</b> contra la investigación "
        "realmente realizada en esa misión, y nos avisa si un servicio externo deja "
        "de responder."
    ),

    KeepTogether([
        p("El resultado de repetir tu caso", "seccion"),
        p(
            "Repetimos tu mismo caso (Deloitte Ecuador, mercado Ecuador) con tu "
            "cuenta:"
        ),
        comparacion(),
        Spacer(1, 8),
    ]),

    p("Dónde estamos por delante", "seccion"),
    punto(
        "<b>Cada fuente se puede comprobar.</b> En este Pack, todas salieron de "
        "la investigación realizada en la misión: ninguna está inventada ni "
        "reconstruida de memoria. ChatGPT no ofrece esa garantía."
    ),
    punto(
        "<b>Las fuentes están organizadas por cuenta</b>, con enlace, medio y "
        "fecha, para revisarlas en segundos."
    ),
    punto(
        "<b>Prefiere no nombrar a nombrar mal.</b> Tu metodología exige confirmar "
        "empleo, cargo, alcance y recencia antes de dar a alguien por verificado, y "
        "el agente la respeta: cuando no puede confirmarlo, deja el rol y lo marca "
        "para verificar, en lugar de arriesgar un nombre equivocado."
    ),
    punto(
        "<b>Trabaja con tu metodología, de forma consistente y con memoria</b>: "
        "guarda el historial de cuentas y la evidencia de cada semana para la "
        "siguiente."
    ),

    p("Una precisión que preferimos darte nosotros", "seccion"),
    p(
        "De los tres compradores, dos están confirmados con fuentes de esta misma "
        "semana: Roberto Andino (CEO de Tigo Ecuador) y Fernando Oliveira (country "
        "manager de DP World para Ecuador y Colombia). En el tercero (Banco "
        "Guayaquil), la persona identificada es correcta, pero la fuente que citó el "
        "agente no confirma el cargo con fecha. El propio Pack marca todas las "
        "cuentas como «no enviar hasta pasar los checks», y uno de esos checks es "
        "justamente verificar al comprador. Aun así, ya estamos reforzando que el "
        "agente cite siempre la fuente exacta que prueba a cada comprador."
    ),

    p("Sobre la comparación en sí", "seccion"),
    p(
        "Tanto ChatGPT como nuestro agente funcionan con modelos de lenguaje, y "
        "ninguno entrega exactamente el mismo resultado dos veces. Una sola "
        "ejecución de cada uno es una muestra, no una medida. En nuestras pruebas, "
        "el agente cribó 10 cuentas en todas las ejecuciones e identificó dos o más "
        "compradores con nombre en 4 de cada 5."
    ),

    p("Quedo atento a tus comentarios.", "firma"),
    p("Saludos,<br/>José Raúl", "firma"),
]

documento = BaseDocTemplate(
    SALIDA,
    pagesize=LETTER,
    leftMargin=20 * mm,
    rightMargin=20 * mm,
    topMargin=20 * mm,
    bottomMargin=18 * mm,
    title="GAP Prospecting AI · Nueva prueba frente a ChatGPT",
    author="Salesbumm",
)
documento.addPageTemplates([
    PageTemplate(
        id="normal",
        frames=[Frame(20 * mm, 18 * mm, ANCHO, LETTER[1] - 38 * mm, id="cuerpo", showBoundary=0)],
        onPage=pie,
    ),
])
documento.build(historia)

print("escrito:", SALIDA)
