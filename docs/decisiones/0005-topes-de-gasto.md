# 0005 · Los topes de gasto

- **Fase:** 5
- **Fecha:** 8 de septiembre de 2026
- **Estado:** implementado y verificado

## La palabra que define la fase es «impide»

Un contador que anota el exceso después de gastarlo no es un tope: es un
informe de daños. El proveedor cobra el intento, así que el único momento en
que un tope sirve para algo es **antes** de la petición.

Por eso la prueba central no mira el mensaje de error. Mira que **al cliente
HTTP no le llegó ninguna petición**, y su pareja comprueba que sin tope sí
llega exactamente una —sin esa segunda, la primera pasaría igual con un cliente
roto que no llama nunca—.

Verificado además contra el motor real, con contraste:

| | Apuntes de consumo |
|---|---|
| Tope de $0.01 con $0.06 ya gastados | 2 → 2 · **no se pagó nada** |
| Sin tope | 2 → 3 · la llamada se hizo |

## Dónde se comprueba cada cosa

Hay una tensión que ya apareció con la telemetría: el cliente de OpenAI **no
sabe de qué alumno es la llamada y no debe saberlo** (§31, §43). Se resuelve
partiendo la comprobación en dos, y cada mitad vive donde le corresponde:

- **El tope global va en `OpenAIClient`**, el único punto por donde pasan todas
  las llamadas del módulo, incluidas las que no pertenecen a ningún alumno. No
  necesita identidad, así que puede vivir en una clase que no la tiene. Es la
  red que sigue puesta aunque un camino nuevo se olvide del tope individual:
  lo peor que puede pasar con un presupuesto no es que un alumno lo agote, es
  que nadie lo esté mirando de madrugada.
- **El tope individual va donde se conoce al alumno**: `ConversationService`
  antes de cada turno y `DiagnosticStarter` antes de crear la sesión.

Empezar no cuesta dinero, pero se comprueba igual: dejar que alguien abra la
conversación, escriba su primer mensaje y se lo rechacen es peor que decírselo
antes de entrar.

## Decisiones

- **El periodo es el mes natural**, no una ventana móvil de treinta días. Es
  como razona quien paga —«cien pesos por alumno al mes»— y, sobre todo, se
  reinicia en una fecha que se puede decir. Con una ventana móvil, quien se
  pasó queda bloqueado sin una fecha clara en la que deje de estarlo, y no hay
  nada que contestarle. Se calcula en la zona horaria del sitio.
- **Cero significa sin tope**, y es el valor de fábrica. Activar un límite por
  defecto cortaría el servicio en instalaciones que nunca pidieron uno.
- **Los topes van en dólares**, que es lo que cobra el proveedor. El tipo de
  cambio a pesos existe solo para enseñar la cifra al lado y no entra en
  ningún cálculo.
- **Los ensayos del estudio se saltan el tope individual** —los hace quien
  administra y cargárselos al dueño de la sesión le comería su cupo sin haber
  hecho nada— pero **sí cuentan contra el global**, porque la factura no
  distingue.
- **El aviso del 80 % se emite una vez por alumno y mes.** Un aviso que se
  repite treinta veces se deja de leer, y entonces el que importa pasa
  desapercibido.

## Lo que ve cada quien

**El alumno** no se entera de que existe un presupuesto ni de por dónde va: no
es asunto suyo y no puede hacer nada (§43, §58). Lee que no puede continuar
ahora, **que no ha perdido nada** —que es lo que de verdad le preocupa— y a
quién avisar. Sus resultados anteriores siguen consultables siempre.

**Quien opera** lo ve en la pantalla de Consumo, arriba del todo y solo cuando
hay un tope puesto: una banda con el gasto, el tope y lo que queda, en ámbar a
partir del 80 % y en rojo al agotarse. El guardián también lo anota en el
registro, pero quien opera no vive en el registro: si el aviso no está en la
pantalla que mira, no existe.

Dos estados y solo dos —se acerca, o ya no se puede gastar—. Un semáforo de
cinco colores en una barra de presupuesto no dice nada más y se ignora.

## Verificación

- **285 pruebas** (10 nuevas), estilo limpio, humo en **38 de 38**.
- Se quitó la comprobación previa a propósito: falla la prueba que demuestra
  que la petición no sale.
- Los tres campos se fijan desde **Configuración → Salesbumm → Diagnostic AI**,
  sin desplegar.
