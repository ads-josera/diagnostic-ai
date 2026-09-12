# 0018 — Las cuentas entre semanas

**11-09-2026**

## Por qué existe

Omar **no lo pidió**. No está en sus siete puntos, ni en la especificación del
backend, ni en la lista de la Etapa 2. Sale de su Documento 8 —«Weekly
Execution, Feedback & Learning Engine»— y lo decidió José Raúl: «vamos a
hacerlo aunque no lo haya pedido Omar, vamos a entregar algo de calidad».

El argumento que lo decidió es el plazo. El alumno tiene acceso **un año**. Con
diez cuentas por semana son del orden de quinientas, y hasta este día el
sistema no recordaba ninguna de una semana a otra: el GOLD Pack guarda lo que
se encontró en fuentes públicas, no lo que pasó al contactar. El agente podía
volver a proponer una cuenta que ya no contestó, y el alumno tenía que volver a
contarlo cada lunes.

## Lo que se hizo

Tres piezas, en tres commits:

1. **El registro** (`e77ff69`). Dos tablas: `sld_account`, una fila por cuenta
   y alumno con solo identidad y fechas, y `sld_account_event`, que **solo
   crece**. Cada Pack que incluye una cuenta deja un evento; cada resultado que
   anota el alumno, otro. Se carga solo al guardarse un Pack, y
   `update_10019` cargó los que ya existían: 213 cuentas de 21 Packs.
2. **La memoria del agente** (`e77ff69`). Al empezar una sesión se añade un
   bloque `ACCOUNT_HISTORY` con las cuentas de ese alumno **y de ese agente**:
   cuándo salió cada una, cuántas veces, y qué pasó si se anotó. El bloque se
   congela con la sesión, como la memoria, para que la caché y la
   reproducibilidad no dependan de lo que se anote a mitad de conversación.
3. **La pantalla «Mis cuentas»** (`35083bb`). Se llega desde el panel y desde
   cada Pack.

## Las reglas que vienen del Documento 8

- **§4, «new evidence appends… never retroactively alters the original
  thesis».** De ahí que los eventos solo crezcan y que la tesis no se copie:
  vive en el resultado del Pack, que ya es inmutable, y cada evento lo
  referencia.
- **§6, «Never Simulate Work».** No existe el estado «RELEASED»: el sistema no
  sabe si algo se envió, solo lo que el alumno dice que pasó. Un estado sin
  anotar se manda al agente como `NOT RECORDED`, que significa «no se sabe», no
  «no pasó».
- **§11, «Feedback Without Forms».** Un clic para el estado, y como mucho una
  etiqueta y una frase. Los cinco botones son exactamente las cinco preguntas de
  su §12; el resto de estados y la verdad del Buyer (§7) van plegados.
- **§13, «Activity Is Not Pipeline».** Las cifras separan lo que avanza de lo
  que ya es oportunidad, en vez de sumar todo lo que se movió.
- **§26, la puerta de reciclaje.** Qué hacer con una cuenta que vuelve **lo
  decide el agente**, con su metodología. El módulo le da el historial y no
  aplica reglas propias: sería alterar su metodología (§15).

## Decisiones no obvias

**El estado no se guarda en la cuenta: se deduce del último evento al leer.**
Un estado cacheado y su historial acaban diciendo cosas distintas, y entonces
el que se cree es el que confunde.

**El reconocimiento de nombres es conservador.** «Traxión» y «Traxion» son la
misma cuenta; «Grupo Traxión» no. Juntar de más mezcla dos empresas en una sola
historia, y eso es peor que un duplicado: el duplicado se ve, la mezcla no.

**Hasta 100 cuentas en el bloque del agente**, las anotadas primero y luego las
más recientes. Con 141 cuentas reales ocupa unas 3 600 fichas.

**Escribir exige POST con token, y una cuenta ajena es un 403 sin más.** No se
distingue «no es tuya» de «no existe»: distinguirlo le diría a quien prueba
números cuáles son de otra persona. Una nota colada en la cuenta de otro no se
vería en ninguna pantalla: la leería su agente y le cambiaría la criba.

## Lo que salió al recorrerla

- **Intro en la frase anotaba «Sin acción todavía».** El envío implícito usa el
  primer botón del formulario, y el primero era un estado. Ahora va antes un
  botón oculto que no manda estado: sin elección, avisa y no escribe.
- **La clasificación se cortaba sin aviso.** La columna era de 32 caracteres y
  el agente escribió «SILVER — HOLD FOR OWNERSHIP CHECK», 33. Se ensanchó a 128
  y `update_10020` reparó las dos filas releyendo el Pack.
- **La pantalla no tenía título ni vuelta.** El recorredor la dio por buena
  porque «Ver en el Pack» apunta a `/sales-diagnostic`: tener un enlace no es
  tener salida. Se miró la captura, no solo el veredicto.

## Lo que queda fuera

- **Integración con un CRM.** Su §29 la deja opcional, con la anotación del
  vendedor como alternativa. El módulo es «an execution aid, not a replacement
  CRM».
- **Una vista del gestor sobre las cuentas de los alumnos.** Nadie la ha pedido,
  y abre preguntas de privacidad que habría que resolver antes.
