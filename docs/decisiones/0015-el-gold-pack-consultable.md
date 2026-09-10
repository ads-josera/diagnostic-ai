# 0015 — El Weekly GOLD Pack deja de ser prosa

**10-09-2026**

## El problema

El agente entregaba el Pack completo y bien. Pero lo entregaba **como texto**:
cada cuenta era una línea dentro de `opportunities`, una lista de cadenas.

Eso tenía tres consecuencias, y solo la primera se veía:

1. **No se podía consultar.** Ni listar, ni filtrar, ni saber de un vistazo
   cuáles se pueden enviar hoy, ni seguir una cuenta de una semana a otra. Para
   retomar el lunes lo del lunes anterior había que releer el chat entero.
2. **El sistema no podía comprobar nada.** Sus criterios A10 y A11 llevaban
   meses declarados «fuera de alcance» por esto: no había objeto que validar.
3. **El aprendizaje no se podía cerrar.** Su Documento 8 es un motor de
   feedback por cuenta, y sin registros no hay dónde anotar qué pasó.

Es exactamente el problema que tuvieron las dimensiones del otro agente el
26-08-2026 —«quedaba en la conversación como prosa y en ningún sitio
consultable»— y se resuelve igual: **dándole campos**.

## Lo que se hizo

Una cuenta pasa de ser una cadena a ser un registro de quince campos, con los
nombres de **su propio vocabulario** —la línea del CORE que enumera el Pack—
para que el agente los reconozca sin traducir nada: `disposition`, `rank`,
`outreach_status`, `why_now`, `gap_hypothesis`, `competing_alternative`,
`buyer`, `do_not_claim`, `routing`, `outreach_message`, `next_step`, `sources`.

Más `pool_declared`: cuántas cuentas se cribaron. Su metodología pide un «pool
auditable», y auditable quiere decir contrastable con la lista.

**Nada de esto toca su prompt (§15).** El contrato de salida es nuestro y lo
dice en su primera línea: «Esta sección NO forma parte de la metodología de
Salesbumm».

## A10 y A11, que ya no están fuera

**A10 — «PREPARED sin mensaje: el validador lo corrige a BLOCKED».** Corrige,
no avisa: el estado de envío es lo que mira alguien para decidir si manda algo
hoy, y una cuenta en PREPARED con el mensaje vacío es una promesa que no se
puede cumplir. Se cubren **los dos** estados que prometen mensaje: su criterio
nombra PREPARED, pero RELEASED sin mensaje es peor —dice que se puede enviar
ya— y taparlo solo en uno dejaría abierta la puerta más ancha.

**A11 — «Pool declarado ≥10: el validador exige cuentas nominales».** Si declara
diez y nombra tres, la cifra baja a tres y **lo declarado se conserva** en
`pool_claimed`. La diferencia entre lo dicho y lo sostenido es justo el dato que
dice si el agente infla el pool, así que no se descarta: **se enseña en
pantalla**, porque si se queda en el registro del sistema no la ve nadie.

Las dos pruebas se **rompieron a propósito** antes de darlas por buenas: con las
correcciones deshechas, las dos se ponen en rojo.

## Por qué existen si el agente ya se porta bien

En la misión medida bloqueó las diez cuentas él solo, correctamente. Esto no
está aquí porque falle: está para que **el sistema lo compruebe** en vez de
confiar en que salga bien cada vez. Es la diferencia entre un agente que se
porta bien y una garantía.

## La pantalla

Una tarjeta por cuenta, no una tabla: una cuenta tiene quince campos y varios
son párrafos. En una tabla habría que elegir cuatro y esconder el resto, y los
que se esconderían —la alternativa que compite, qué NO afirmar— son justo los
que impiden que alguien salga a decir algo no verificado.

- **Una franja lateral** dice el estado de envío sin leer nada.
- **El color semántico va solo ahí y en los avisos.** La clasificación usa peso
  y borde. Si todo lleva color, el color deja de significar nada.
- **Botón de copiar** en el mensaje: su metodología lo llama «copy/paste», y
  obligar a seleccionar a mano invita a perder un salto de línea. Si el
  navegador no deja copiar, el botón **lo dice** en vez de fingir que funcionó.
- **Sin mensaje no hay botón.** Un control que no hace nada es peor que no
  ponerlo.

De paso se destapó que los colores semánticos **no tenían versión oscura**: un
verde `#1a7f4b` sobre el gris `#1f2228` son 2,1:1, por debajo de lo legible. No
se había notado porque casi nada los usaba. Los nuevos están **medidos**: entre
7,0:1 y 8,6:1 en oscuro, y entre 4,7:1 y 8,5:1 en claro.

## Comprobado contra el agente real

No solo con pruebas. Se abrió una misión Discovery de verdad (sesión 96) y el
agente rellenó la estructura con datos reales:

| | |
|---|---|
| Cuentas en el Pack | **10**, todas con nombre |
| Ranking | 1–4 en las SILVER, 0 en las que no entran |
| Estado de envío | Las diez BLOCKED, y ninguna con mensaje |
| Fuentes | Adjuntas donde las tenía |
| Búsquedas | 16, con 35 062 caracteres |
| Coste | **$0.4446 USD** |

Que las diez salieran bloqueadas **es coherente**: declaró que no había
verificado Buyer en ninguna, así que no hay nada que enviar. El validador no
tuvo que corregir nada, que es lo que se espera cuando el agente hace su
trabajo.

En pantalla: diez tarjetas, «Cribadas 10 cuentas» sin discrepancia, cero
botones de copiar y diez bloques de «Qué NO afirmar».

## Lo que sigue faltando

Esto hace el Pack **consultable dentro de un resultado**. Lo que todavía no
existe es seguirlo **entre semanas**: una vista de cuentas del alumno donde
marcar qué pasó con cada una y alimentar el motor de aprendizaje del §8. Es el
paso siguiente natural, y ahora es posible porque hay registros.

343 pruebas, 1243 aserciones, phpcs limpio, humo 45/45.
