# 0011 · Los turnos que investigan corren fuera de la petición web

- **Fase:** fuera del plan de once fases — trabajo de la Etapa 2
- **Fecha:** 8 de septiembre de 2026
- **Estado:** implementado y verificado

## Por qué

Por un número, no por prudencia. Un turno con **dos búsquedas sobre una sola
cuenta tardó 76 segundos**. Una misión Discovery criba diez cuentas y
profundiza en tres: son decenas de búsquedas y veinte minutos.

Eso no cabe en una petición web, y **no se arregla subiendo un timeout**:
aunque el servidor aguantara, nadie mira una pantalla en blanco veinte minutos.

## Solo cuando hace falta

Un turno normal responde en segundos; mandarlo a la cola le añadiría la espera
del siguiente cron **a cambio de nada**. Así que la decisión se toma por turno:
si el agente tiene capacidad de investigar, va a la cola; si no, se genera en
el acto.

Son dos caminos, y eso tiene un coste de mantenimiento. Se paga con **un solo
cuerpo**: `executeTurn()` lo comparten los dos, y ninguno adquiere el cerrojo
—quien llama ya lo tiene—. Si hubiera dos cuerpos, el que se usa menos acabaría
siendo el que tiene los fallos.

Medido: la petición pasa de **6 segundos a 0.02** cuando el turno se encola.

## El estado hace de cerrojo

Al encolar, la sesión pasa a `Processing`, que **no admite mensajes**. No hizo
falta inventar nada: el estado ya existía en el modelo de datos sin usar, y por
sí solo impide que alguien escriba encima de un turno en marcha.

## Las dos formas de romperlo

**Que se ejecute dos veces.** Costaría dos llamadas al proveedor y dejaría dos
respuestas seguidas del agente. La cola reintenta un elemento si el proceso
murió después de trabajar y antes de borrarlo, así que el servicio comprueba el
estado antes de nada y **se sale sin hacer nada** si ya no está pendiente. Es la
idempotencia que pide el §14: «no consumir otra misión automáticamente».

**Que se quede atascado.** Si el proceso muere a mitad, la sesión se queda en
`Processing` **para siempre**, y ese estado no admite mensajes: la conversación
queda inutilizable y la persona no puede ni reintentar ni entender por qué. El
cron la devuelve a un estado escribible a los 45 minutos, configurable.

**No se reintenta el turno solo.** Reintentar sin que nadie lo pida podría
gastar otra ronda de búsquedas por un error que quizá se repita. Se devuelve la
conversación a la persona y decide ella.

## Dos cosas que se aprendieron probando

**El campo `changed` no era de fiar.** La recuperación se apoyaba en él y
funcionaba por casualidad: si hubiera valido cero, toda conversación se habría
dado por atascada nada más empezar. Ahora la marca de tiempo se pone a mano.

**El cron procesa la cola además de desatascar.** Una prueba esperaba que el
cron dejara el turno en `Processing` y lo que hizo fue terminarlo, que es lo
correcto. La prueba estaba mal, no el código; ahora hay una que comprueba las
dos cosas juntas, porque en el servidor van juntas.

## Un 403 que solo se ve en el navegador

El punto donde el navegador pregunta cómo va el turno se protegió al principio
con el control de acceso del alumno, que **comprueba la autorización del curso
contra WordPress**. Una sesión de ensayo del estudio no tiene curso que
comprobar: devolvía **403** y desde fuera la página se veía perfecta.

El estudio tiene ahora su propia ruta, con el control que le corresponde. Y la
prueba de humo comprueba que ese punto **responde**, no solo que existe.

## Lo que ve la persona

Mientras espera, **cuántas búsquedas lleva hechas**. No es un adorno: un
indicador que no se mueve durante quince minutos se lee como «se colgó», y se
cierra la pestaña justo cuando el trabajo iba bien.

El dato no hubo que inventarlo: el gateway ya anota cada búsqueda al
concederla.

## Lo que exige del servidor

**El cron cada minuto**, no cada quince. Un turno encolado espera al siguiente
cron para arrancar; con quince minutos, alguien escribiría y esperaría un
cuarto de hora a que empezara.

Es viable porque el servidor es propio. En alojamiento compartido no suele
permitirse, y sería el argumento para no encender la búsqueda ahí.

A cambio, **el muro de los 75 segundos de cPanel deja de aplicar** a estos
turnos: por línea de órdenes PHP no tiene límite de tiempo.

## Verificación

- **336 pruebas** (8 nuevas), estilo limpio, humo en **45 de 45**.
- Verificado de punta a punta: petición de 0.02 s, sesión en `Processing`, un
  elemento en la cola, y el trabajador resolviéndolo en 52 segundos con su
  respuesta completa.
- La recuperación se rompió a propósito: falla la prueba que la fija.

## Lo que falta para Discovery

La ejecución está resuelta. Queda **subir los topes** —12 búsquedas por misión
no bastan para cribar diez cuentas— y comprobar una misión Discovery completa.
Con los topes actuales, una misión real se quedó sin margen a mitad y lo
declaró en vez de inventar, que es el comportamiento correcto pero no el que se
busca cuando el objetivo es cribar.
