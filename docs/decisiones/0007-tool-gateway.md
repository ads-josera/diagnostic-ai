# 0007 · El Tool Gateway

- **Fase:** 7
- **Fecha:** 8 de septiembre de 2026
- **Estado:** implementado y verificado con el agente real

## Lo que exige el cliente, con sus palabras

> **§6** «Todas las búsquedas externas pasan por un gateway backend; nunca dar
> acceso directo no medido. **Antes de cada tool call** validar user_id,
> mission_id, estado, clasificación del turno, presupuesto y límites.»
>
> **§15, Definition of Done** «Tool Gateway **bloquea físicamente** research no
> autorizado.»

La palabra es *físicamente*. Un registro de lo que se buscó de más no es un
gateway: la búsqueda ya se pagó y su contenido ya entró al contexto.

## El agujero que la propia especificación nombra

> **§6** «Evitar bypass por **nueva conversación**, nuevo dispositivo, Entry
> Mode distinto, Account Intelligence repetido o prompt injection.»

Un tope por misión **solo** parece suficiente hasta que alguien abre otra
conversación. Por eso hay dos contadores:

| Tope | Qué acota | Se salta abriendo otra conversación |
|---|---|---|
| Por misión | Llamadas y texto externo de esa conversación | sí |
| **Por persona y mes** | Llamadas de esa persona, venga de donde venga | **no** |

La prueba `testAbrirOtraMisionNoDevuelveElCupo` es la que fija esto. Al quitar
el segundo contador a propósito, es la única que falla.

## Dónde se pone el control

Envolviendo la caja de herramientas, no metiéndose dentro de cada una. Así una
herramienta nueva **nace controlada sin que nadie tenga que acordarse**: no hay
forma de llegar a ella sin pasar por el gateway, porque la fábrica es el único
sitio que construye herramientas y nunca devuelve la caja desnuda.

Para validar «user_id, mission_id» hizo falta resolver otra vez la misma
tensión: esa identidad no puede viajar en lo que se manda al proveedor (§31,
§43). Con la telemetría se resolvió al revés —el cliente deposita, quien conoce
al alumno recoge después—, pero aquí el dato hace falta **durante** la llamada.
Así que quien conduce la conversación declara el turno antes y lo retira en un
`finally`.

**Sin turno declarado, el gateway deniega.** Es preferible una búsqueda que no
ocurre a una que ocurre sin que se sepa a cuenta de quién.

## Qué se le dice al modelo

Una negativa no es un error: es una respuesta que tiene que poder leer. Se le
dice que no puede buscar y **que declare la limitación en lugar de inventar**.

Devolverle una lista vacía sería peor que denegarle: creería que buscó y no
encontró nada, y con eso cerraría una misión como ZERO-GOLD sin haber mirado.

## Verificación con el agente real

Búsqueda encendida, tope de la misión en **una** llamada, y una petición que
pedía investigar a fondo:

| | |
|---|---|
| 1.ª búsqueda | **CONCEDIDA** · 5 resultados · 3 975 caracteres |
| 2.ª, 3.ª, 4.ª | **DENEGADAS** · `tope_llamadas_mision` · `policy_denial` en el registro |

Y el agente hizo exactamente lo que manda su metodología:

> «No puedo construir una recomendación ni atribuir señales recientes: la
> investigación externa quedó bloqueada por el límite de búsquedas […] señales
> recientes, cambios de liderazgo logístico y expansión de red: **NO
> VERIFICADOS**.»

No inventó nada.

## Los números son de partida

12 llamadas por misión, 40 000 caracteres de contenido externo por misión y 60
llamadas por persona y mes. Son un punto de partida y **hay que calibrarlos con
el benchmark**, como pide el §9 de la especificación. Viven en configuración
por eso mismo: «los valores numéricos finales deben calibrarse con benchmark;
no hardcodear».

A diferencia de los topes de gasto, estos **sí nacen con valores**. La
herramienta solo existe si alguien enciende la búsqueda a propósito, así que un
tope de fábrica no puede sorprender a nadie, y el §6 pide denegar «de forma
determinística».

## Lo que se ve

Panel **Búsquedas externas** en la pantalla de Consumo, con las concedidas y
**las denegadas con el mismo peso**, y por qué se denegaron.

Las denegadas son la única forma de ver que el gateway está haciendo algo, y de
distinguir un agente que no quiso buscar de uno al que no se le dejó. Sin ese
panel, «bloquea físicamente» solo se podría comprobar leyendo el registro del
sistema.

El punto de las denegadas va en color de **aviso**, no de peligro: una
denegación es el control funcionando, no un fallo.

## Verificación

- **301 pruebas** (9 nuevas), estilo limpio, humo en **38 de 38**.
- Quitado el contador por persona a propósito: falla la prueba del bypass.
- Las llamadas denegadas **no consumen cupo**: no gastaron nada, y contarlas
  dejaría al agente sin margen por haber intentado algo que no se le dejó.

## Lo que queda para la Fase 8

El §6 pide validar también «estado y clasificación del turno». Eso es el
Research Entitlement: la máquina de estados y saber si el turno es una misión
nueva, un follow-up o un recheck. La costura está hecha —el gateway ya es el
único paso— y añadirlo no toca el motor.
