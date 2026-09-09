# 0013 — La búsqueda se concede por agente

**08-09-2026**

## El problema

`search.enabled` era **una sola llave para toda la casa**. Encenderla se la daba
a los dos agentes; apagarla se la quitaba a los dos. Y solo uno la necesita:

| Agente | ¿Necesita salir a internet? |
|---|---|
| Diagnóstico de liderazgo comercial (GAP) | **No.** Diagnostica a la persona con lo que ella cuenta. |
| GAP Prospecting AI | **Sí.** Su trabajo es mirar cuentas, señales y Buyers. |

Con una sola llave, encenderla costaba de tres maneras:

1. **Le gasta a la persona la misión de la semana.** El entitlement es uno por
   persona y periodo y **se comparte entre agentes** —así lo pide su §2, «compartido
   entre conversaciones y Entry Modes»—. Un alumno cuyo agente de diagnóstico
   sale a buscar el lunes se queda sin investigación el miércoles, cuando quiera
   hacer Discovery de verdad. Y **no falla de forma visible**: nadie ve un error,
   el agente simplemente dice después que no puede investigar.
2. **Tira la caché del prompt.** Declarar herramientas cambia el prefijo. En la
   misión medida en [0012](0012-topes-para-discovery.md) el 90 % de la entrada
   venía reutilizada; ese descuento es la diferencia entre $0.58 y varios dólares.
3. **Manda el turno a la cola**, porque desde [0011](0011-turnos-en-segundo-plano.md)
   todo turno que puede investigar se ejecuta fuera de la petición. Un agente que
   no investiga no debería esperar por eso.

Por eso la búsqueda estaba apagada aunque Discovery ya funcionara.

## Lo que se hizo

Una casilla por agente, `can_search`, junto a «Disponible para los alumnos» —las
dos dicen lo que el agente **puede hacer**, y esa es la lectura que importa—.

Ahora la búsqueda externa tiene que superar **tres puertas**, de la más general a
la más particular:

1. **El módulo** (`search.enabled`, más que haya buscador configurado). Es el
   corte de emergencia. Apagarlo apaga el sitio entero.
2. **El agente** (`can_search`). «¿Este agente necesita salir a internet?»
3. **La persona** (el Research Entitlement). «¿Le queda misión esta semana?»

La segunda existe porque la tercera **se comparte**. Sin ella, quien no necesita
buscar le gasta la misión a quien sí.

Comprobado contra el sistema real, con el interruptor general **encendido**:

```
AGENTE                         SE ENCOLA    HERRAMIENTAS QUE VE EL MODELO
prospecting_diagnostic         SI           consultar_evidencia, anotar_evidencia, buscar_web
sales_leadership_diagnostic    no           consultar_evidencia, anotar_evidencia
agente_borrado                 no           consultar_evidencia, anotar_evidencia
```

Encender el interruptor general **ya no reparte nada por su cuenta**. Eso era el
objetivo.

## Tres decisiones dentro

**Nace en NO.** Un agente nuevo no hereda la capacidad cara. Se concede a mano,
sabiendo lo que se concede.

**El ledger NO depende de esta puerta.** Un agente sin búsqueda sigue viendo
`consultar_evidencia` y `anotar_evidencia`. Es el mismo reparto que ya hacía el
entitlement agotado, decidido una puerta más arriba: el agente de diagnóstico no
sale fuera, pero tiene que poder mirar lo que ya se sabe de la persona.
Quitárselo lo dejaría sin nada que consultar.

**Un agente que no se encuentra devuelve NO.** De las dos lecturas posibles es la
prudente: la única forma de llegar ahí sin agente es que la sesión nombre uno
borrado, y conceder la capacidad cara a un agente que ya no existe no lo arregla.

## Dónde vive la decisión

En `ToolBoxFactory`, que ya era el único sitio que construye herramientas. Los
dos métodos que deciden consultan la misma puerta:

- `forTurn()` — si el modelo llega a **ver** que la herramienta existe. La
  clasificación ocurre antes de exponerla, como pide su §3: no puede pedir lo que
  no sabe que hay, y eso cierra el bypass por prompt injection sin depender de
  que el gateway diga que no una y otra vez.
- `mayResearch()` — si el turno **se encola**. Se pregunta antes de empezar el
  turno, así que recibe el agente como argumento en vez de leerlo del turno en
  curso.

El agente viaja hasta ahí dentro de `CurrentTurn`, que es donde ya viajaba el
resto del contexto del turno. La sesión lo sabe, pero la sesión no llega al
gateway.

## El interruptor general queda ENCENDIDO

Ya se puede. Es lo que esta puerta desbloquea: la búsqueda está viva, la tiene
solo prospección, y el agente de diagnóstico sigue exactamente igual de barato y
de rápido que antes.

## Lo que se rompió al hacerlo, y por qué está bien

Cuatro pruebas fallaron: las que daban por hecho que la búsqueda era global. Es
la señal correcta —el contrato cambió— y ahora tienen que declarar de qué agente
es el turno, que es lo que pasa también en producción. Se añadieron dos que
prueban la puerta nueva por los dos lados: que un agente sin conceder **no se
encola**, y que **conserva el ledger**.

338 pruebas, 1227 aserciones, phpcs limpio, humo 45/45.
