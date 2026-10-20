#!/usr/bin/env python3
"""Genera el PDF para el cliente con los límites de uso de los dos agentes.

Nace del 19-10-2026: José Raúl quiere que el cliente tenga por escrito los
límites vigentes de Sales Leadership Diagnostic AI y de GAP Prospecting AI,
cuáles salen de su especificación y qué ve el alumno al llegar a cada uno.

Vive en el repositorio por la misma razón que documento-cliente.py: un
entregable que no se puede volver a generar se rehace a mano cada vez.

Reglas de contenido al tocarlo:

- **Cada cifra es la de producción.** Se leyeron el 19-10-2026 de la
  configuración (sales_leadership_diagnostic.settings y la ficha de cada
  agente). Si cambian, este documento miente: actualízalo con ellas.
- **Separar regla de cifra.** Las reglas (misión semanal compartida,
  comprobaciones puntuales después, topes calibrados midiendo) vienen de la
  especificación del cliente. Las cifras las calibramos nosotros, y los topes
  de gasto los fijó José Raúl. No atribuirle al cliente los números.
- **Los mensajes al alumno van literales**, copiados del código
  (StartController, ConversationApiController). Si cambian allí, aquí también.
- **Nada de servidor ni de proveedores.** Lo lee el cliente.

Uso:
    python3 bin/limites-agentes.py [ruta-de-salida.pdf]

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
    else "Limites-de-uso-de-los-agentes.pdf"
)
CABECERA = "Salesbumm · Límites de uso de los agentes"

# La misma paleta que documento-cliente.py y comparacion-chatgpt.py, para que
# los documentos que recibe el cliente se lean como de la misma casa.
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
    "celda_atenuada": ParagraphStyle(
        "celda_atenuada", parent=base["Normal"], fontName="Helvetica",
        fontSize=9, leading=12, textColor=TINTA_SUAVE,
    ),
    "celda_cabecera": ParagraphStyle(
        "celda_cabecera", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=9, leading=12, textColor=TINTA,
    ),
    "cita": ParagraphStyle(
        "cita", parent=base["Normal"], fontName="Helvetica-Oblique",
        fontSize=9.5, leading=13.5, textColor=TINTA_SUAVE, leftIndent=14,
        spaceAfter=7,
    ),
    "firma": ParagraphStyle(
        "firma", parent=base["Normal"], fontName="Helvetica",
        fontSize=10, leading=14.5, textColor=TINTA, spaceBefore=6,
    ),
}

ANCHO = LETTER[0] - 40 * mm

# Lo que no le aplica a un agente se escribe así y se pinta atenuado: el
# lector tiene que ver de un vistazo qué límites son de cada uno.
NO_APLICA = "No aplica"


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


def celda(texto, cabecera=False):
    """Una celda: cabecera, atenuada si no aplica, o normal."""
    if cabecera:
        return Paragraph(texto, ESTILOS["celda_cabecera"])
    if texto == NO_APLICA:
        return Paragraph(texto, ESTILOS["celda_atenuada"])
    return Paragraph(texto, ESTILOS["celda"])


def tabla(filas, anchos):
    """Una tabla con cabecera, en el estilo de los demás documentos."""
    datos = [
        [celda(c, cabecera=(i == 0 or j == 0)) for j, c in enumerate(f)]
        for i, f in enumerate(filas)
    ]
    t = Table(datos, colWidths=[ANCHO * a for a in anchos], hAlign="LEFT", repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), FONDO_CABECERA),
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


def tabla_por_agente():
    """Los límites de cada agente, lado a lado."""
    return tabla([
        ["", "Sales Leadership Diagnostic AI", "GAP Prospecting AI"],
        ["Investigación en internet", "No la usa: diagnostica con lo que cuenta el alumno", "Sí"],
        ["Misión de investigación", NO_APLICA, "1 por alumno y semana"],
        ["Comprobaciones puntuales después de la misión", NO_APLICA, "3 por semana"],
        ["Búsquedas por misión", NO_APLICA, "40"],
        ["Lectura de páginas por misión", NO_APLICA, "400 000 caracteres"],
        ["Búsquedas por alumno al mes", NO_APLICA, "200"],
        ["Conversaciones o diagnósticos", "Sin límite", "Sin límite"],
        ["Conversaciones nuevas por día", "3 con este agente", "3 con este agente"],
        ["Turnos por conversación", "60", "60"],
        ["Mensajes seguidos", "20 cada 5 minutos", "20 cada 5 minutos"],
    ], [0.36, 0.34, 0.30])


def tabla_consumo():
    """Los topes de consumo, que son comunes a los dos agentes."""
    return tabla([
        ["Tope", "Valor", "A quién aplica"],
        ["Consumo de IA por alumno", "8 USD al mes", "Uno solo para los dos agentes juntos, no 8 USD por agente"],
        ["Consumo de IA de toda la plataforma", "50 USD al mes", "A todos los alumnos sumados"],
        ["Aviso previo", "Al 80 % de cada tope", "Queda anotado para la administración, una vez por alumno y mes; el alumno no nota nada hasta el 100 %"],
    ], [0.36, 0.22, 0.42])


historia = [
    p("Límites de uso de los agentes", "titulo"),
    p("Sales Leadership Diagnostic AI y GAP Prospecting AI", "subtitulo"),
    p("Octubre de 2026 · valores vigentes en la plataforma", "fecha"),

    p("Hola, Omar:"),
    p(
        "Te comparto por escrito los límites con los que funcionan hoy los dos "
        "agentes, qué parte de ellos sale de tu especificación y qué ve el alumno "
        "cuando llega a cada uno."
    ),

    p("Las reglas que vienen de tu especificación", "seccion"),
    p(
        "La forma de los límites de investigación la definiste tú, y la "
        "plataforma la aplica tal cual:"
    ),
    punto(
        "<b>Una misión de investigación por alumno y por semana.</b> La semana va "
        "de lunes a domingo, en la zona horaria del alumno."
    ),
    punto(
        "<b>La misión es de la persona, no de la conversación.</b> Se comparte "
        "entre conversaciones y modos de entrada: abrir un chat nuevo o cambiar de "
        "modo no da otra misión."
    ),
    punto(
        "<b>Se abre con la primera búsqueda y se cierra al entregar el Pack.</b> "
        "Quien entra solo a preguntar algo no gasta su misión."
    ),
    punto(
        "<b>Después de cerrarla, seguimiento y comprobaciones puntuales.</b> El "
        "agente sigue trabajando con la investigación ya hecha (comparar, "
        "priorizar, preparar mensajes) y puede hacer verificaciones concretas, "
        "pero no una investigación nueva hasta la semana siguiente."
    ),
    punto(
        "<b>Los topes se calibran midiendo, no por decreto.</b> Por eso las cifras "
        "de búsqueda de abajo salen de medir misiones reales con tu caso de "
        "prueba (Deloitte Ecuador), y no de un número fijado de antemano."
    ),
    KeepTogether([
        p("<b>Un ejemplo para verlo claro:</b>", "cuerpo"),
        p(
            "Un alumno investiga Deloitte Ecuador el martes con GAP Prospecting AI "
            "y recibe su Weekly GOLD Pack completo. El jueves abre otra "
            "conversación y pide investigar una empresa distinta. Esa segunda "
            "petición <b>no tendrá una investigación completa nueva</b>: el agente "
            "le explica que ya usó su misión de la semana, sigue trabajando con lo "
            "que ya investigó (comparar, priorizar, preparar mensajes) y puede "
            "hacer hasta 3 comprobaciones puntuales. El lunes siguiente vuelve a "
            "tener una investigación completa disponible.",
            "cita",
        ),
        p(
            "Por eso conviene elegir bien la empresa y el mercado de la misión de "
            "cada semana. Sales Leadership Diagnostic AI no tiene esta "
            "restricción: no sale a internet y sus diagnósticos no tienen límite.",
        ),
    ]),

    # Sin KeepTogether: la tabla es larga y, entera, deja media página vacía.
    # Se parte por filas y repite la cabecera (repeatRows en tabla()).
    p("Límites de cada agente", "seccion"),
    p(
        "Solo GAP Prospecting AI sale a internet; por eso los límites de "
        "investigación son solo suyos. Los de uso son iguales para los dos."
    ),
    tabla_por_agente(),
    Spacer(1, 8),

    KeepTogether([
        p("Topes de consumo, comunes a los dos agentes", "seccion"),
        p(
            "Protegen el costo de la plataforma. Se cuentan por mes natural y se "
            "pueden ajustar desde la configuración, sin tocar el agente."
        ),
        tabla_consumo(),
        Spacer(1, 8),
    ]),

    p("Qué ve el alumno al llegar a cada límite", "seccion"),
    p(
        "En ningún caso se pierde lo que el alumno ya generó: sus diagnósticos y "
        "sus Packs se pueden seguir consultando siempre."
    ),
    KeepTogether([
        punto("<b>Al usar su misión de la semana</b> (GAP Prospecting AI):"),
        p(
            "El agente se lo explica en la conversación y sigue ayudándole con la "
            "investigación ya hecha. La misión vuelve a estar disponible el lunes.",
            "cita",
        ),
    ]),
    KeepTogether([
        punto("<b>Al abrir más de 3 conversaciones en un día con el mismo agente:</b>"),
        p(
            "«Ya iniciaste varias sesiones con [agente] hoy. Vuelve a intentarlo "
            "mañana; con tus otros agentes puedes seguir.»",
            "cita",
        ),
    ]),
    KeepTogether([
        punto("<b>Al enviar más de 20 mensajes en 5 minutos:</b>"),
        p(
            "«Has enviado demasiados mensajes en poco tiempo. Espera unos minutos "
            "antes de continuar.»",
            "cita",
        ),
    ]),
    KeepTogether([
        punto("<b>Al llegar al tope de consumo del mes:</b>"),
        p(
            "«Esta conversación no puede continuar en este momento. No se ha perdido "
            "nada: lo que ya generaste sigue disponible. Avisa a tu instructor para "
            "reanudarla.»",
            "cita",
        ),
    ]),

    KeepTogether([
        p("Una protección para el alumno", "seccion"),
        p(
            "Si un servicio externo de búsqueda deja de responder y la misión termina "
            "sin haber podido investigar, <b>esa misión no le cuenta al alumno</b>: "
            "el Pack lo indica y puede repetirla cuando el servicio se restablezca. "
            "Así nadie pierde su semana por una falla que no es suya."
        ),
    ]),

    p("Si quieres ajustar algún límite", "seccion"),
    p(
        "Cualquiera de estas cifras se puede cambiar. Los topes de consumo se "
        "ajustan al momento; para los de investigación te proponemos medir antes "
        "el efecto con tu caso de prueba, como hicimos para fijarlos."
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
    title="Límites de uso de los agentes",
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
