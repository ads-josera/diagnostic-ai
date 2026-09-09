# 0009 · El Evidence Ledger

- **Fase:** 9
- **Fecha:** 8 de septiembre de 2026
- **Estado:** implementado y verificado con el agente real

## No guarda búsquedas: guarda afirmaciones

Su §7 no pide un archivo de resultados. Pide siete campos concretos —`claim`,
`source`, `observed_at`, `evidence_type`, `scope`, `confidence`, `status`— y el
primero es **la afirmación que el agente usó**.

La diferencia no es de matiz. Una página no es una afirmación: guardar páginas
obligaría a releerlas para reutilizarlas, que es justo lo que el §9 prohíbe
—«no reenviar contenido completo si basta el Evidence Ledger»—.

**Sin fuente no se guarda.** La metodología del cliente prohíbe afirmar sin
procedencia, así que una anotación sin ella reaparecería más tarde pareciendo
comprobable sin serlo.

## Cuelga de la persona, no de la misión

Su §14 lo pide con un caso: «una cuenta ya investigada que reaparece la semana
siguiente: reutilizar ledger y hacer delta/freshness, **no empezar de cero**».

Eso solo funciona si la evidencia sobrevive al cierre de la misión que la
recogió. Hay una prueba que lo fija.

## La decisión que define la fase

**Las herramientas del ledger se le ofrecen al agente aunque no pueda
investigar.**

Es lo que hace cierto el «después de completar, los follow-ups siguen
funcionando con evidencia persistida» del §2. Con la misión cerrada, mirar lo
que ya se sabe es lo único que le queda: quitárselo lo dejaría mudo.

La de buscar, en cambio, sí depende del entitlement, y cuando no hay capacidad
**ni siquiera se le declara**: no puede pedir lo que no sabe que existe.

Al mover las del ledger dentro de esa condición a propósito, falla exactamente
una prueba.

## Lo viejo se detecta al leer, no se marca

El estado `STALE` no se guarda: se deduce al consultar, comparando la fecha de
observación con el umbral. Guardarlo exigiría un proceso que fuera marcando
filas viejas, y **una evidencia no cambia de naturaleza porque nadie haya
pasado a revisarla**.

Lo que el agente declaró `CONTRADICTED` o `SUPERSEDED` sí manda sobre la
antigüedad: algo contradicho no vuelve a valer por ser reciente.

Cada anotación vuelve con sus días de antigüedad. Que el agente sepa que algo
tiene ochenta días es la diferencia entre reutilizar y repetir el error de su
prueba T07: presentar una señal vieja como un why-now.

## Verificación con el agente real

**Misión 1**, con capacidad para investigar. Buscó sobre Grupo Lala y anotó
**cuatro evidencias con fuentes reales** —enlaces de `lala.com.mx`, uno de su
reporte anual—. Distinguió correctamente la declaración del propio usuario como
fuente, en lugar de mezclarla con lo verificado.

**Misión 2**, conversación nueva, misión cerrada y sin comprobaciones:

```
mission_state: COMPLETED
external_research: NOT_AVAILABLE
research_budget: EXHAUSTED
evidence_ledger_available: true
allowed_actions: follow_up
```

Contestó con hechos y sus fuentes, y lo declaró él mismo:

> «Cobertura reutilizada: evidencia corporativa de Grupo Lala; **no se realizó
> investigación externa nueva en este turno** (la misión está cerrada y el
> presupuesto de research está agotado).»

Cuatro reutilizaciones anotadas. **Cuatro búsquedas que no se hicieron.**

## Un hueco que se vio al medir

Las lecturas del ledger no quedaban registradas: el gateway las dejaba pasar
sin anotarlas, por no someterlas a los topes. Pero el §10 pide medir
`ledger_reads`, y sin esa fila no hay forma de distinguir **un follow-up que se
resolvió con lo guardado de uno que se quedó sin decir nada** —que son las dos
caras del ahorro que el ledger existe para producir—.

Ahora se anotan como llamada concedida con cero texto externo: cuentan para la
telemetría y no consumen el tope de contenido de la misión.

## Lo que se ve

Panel **Evidencia reutilizable** en la pantalla de Consumo. Lo que destaca no
son las anotaciones sino **las reutilizaciones**: una anotación que nadie
vuelve a mirar no ahorró nada; una reutilizada es una búsqueda que no se hizo.

## Verificación

- **318 pruebas** (9 nuevas), estilo limpio, humo en **38 de 38**.
- `evidence_ledger_available` deja de mentir: refleja si esa persona tiene algo
  guardado. Decirle que sí con el ledger vacío le haría mirar ahí primero para
  no encontrar nada, y perder un turno en ello.
