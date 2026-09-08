# 0008 · El Research Entitlement

- **Fase:** 8
- **Fecha:** 8 de septiembre de 2026
- **Estado:** implementado y verificado con el agente real

## La decisión que gobierna todo lo demás

Su §14 la escribe como un escenario:

> «Usuario abre chat nuevo después de completar misión: **sigue bloqueado**
> para nueva Research Mission.»

De ahí sale todo: **el estado no cuelga de la conversación, cuelga de la
persona y la semana.** Si colgara de la conversación, cerrar el chat y abrir
otro devolvería una misión nueva, y el control sería decorativo.

Su §2 lo dice también sin escenario: «scope: user_id + entitlement_period,
**compartido entre conversaciones y Entry Modes**».

## Cuándo se abre la misión

**En la primera búsqueda concedida, no al empezar la conversación.**

Abrirla al empezar quemaría la misión semanal de quien entra solo a preguntar
algo. Y la abre el gateway, no el modelo: su §4 exige que la transición la haga
el backend, y así es —el modelo busca, el backend decide que eso abre una
misión—.

## La clase del turno no se le pregunta al modelo

Su §3 lo pide así: «la clasificación debe ocurrir **antes** de exponer
herramientas de búsqueda al modelo. Un follow-up no puede convertirse
silenciosamente en una misión nueva.»

Por eso la clase **se deduce del estado guardado**, nunca del texto:

| Estado | Clase del turno | Investigación |
|---|---|---|
| `AVAILABLE` / `ACTIVE` | `RESEARCH_MISSION` | `ALLOWED` |
| `COMPLETED` con comprobaciones | `TARGETED_RECHECK` | `TARGETED_ONLY` |
| `COMPLETED` sin comprobaciones | `MISSION_FOLLOW_UP` | `NOT_AVAILABLE` |

Un clasificador por palabras se engañaría pidiendo «investiga de nuevo», que es
el bypass por prompt injection que nombra su §6. Aquí no hay nada que engañar:
nadie le pregunta al modelo qué cree que está haciendo.

## Dos capas, no una

Cuando el entitlement no permite nada, **la herramienta ni siquiera se le
declara al modelo**: no puede pedir lo que no sabe que existe. Y el gateway lo
comprueba otra vez antes de ejecutar, porque el estado puede cambiar a mitad de
turno —otra petición puede cerrar la misión— y para entonces la herramienta ya
estaba declarada.

## El lock atómico

Su §14 pide «lock atómico por user/week para impedir dos misiones ACTIVE». No
hizo falta un lock: lo da la propia base. Clave única por persona y semana, más
un `UPDATE` condicionado al estado anterior. Dos peticiones simultáneas no
abren dos misiones; la segunda no toca ninguna fila y **se entera**, porque
`startMission()` devuelve si fue ella quien la abrió.

## El bloque que ve el agente

Los campos son los del §5, en sus palabras, porque su prompt razona con ellas.
Y cumple su regla más delicada:

> «No exponer costos internos, secretos, límites monetarios ni lógica sensible
> al usuario. El modelo necesita conocer permiso/capacidad operativa, no la
> contabilidad.»

**Ni un número de dinero, ni cuántas búsquedas quedan, ni qué topes hay.** Hay
una prueba que fija la lista exacta de campos: si alguien añade uno, falla.

Va **detrás del prompt** y no al final de la conversación: el prompt del
cliente razona con él desde su primera decisión. Cuesta algo —cuando el estado
pasa de AVAILABLE a ACTIVE cambia el prefijo y se pierde la caché de ese turno,
unos veinte centavos, una vez por misión— y se paga a gusto: la alternativa es
que el agente decida sin saber qué puede hacer.

## La renovación

Semanal, en **la zona horaria de cada persona**, y sin ningún proceso que la
dispare: al cambiar la semana cambia la clave, no se encuentra fila y nace una
nueva en AVAILABLE. Lo anterior se conserva.

Con el servidor en UTC y la persona en México, calcular la semana con la del
servidor haría que renovara a media tarde del domingo, cuando nadie la espera.

## Verificación con el agente real

| | `mission_state` | `external_research` |
|---|---|---|
| Antes de buscar | `AVAILABLE` | `ALLOWED` |
| Tras la primera búsqueda | **`ACTIVE`** + `mission_id` | `ALLOWED` |
| Al cerrar el diagnóstico | `COMPLETED` | `TARGETED_ONLY` |
| **Conversación nueva, mismo alumno** | **`COMPLETED`** | `TARGETED_ONLY` |

El último renglón es el escenario del §14, comprobado de punta a punta.

## Verificación

- **309 pruebas** (8 nuevas), estilo limpio, humo en **38 de 38**.
- El cierre de la misión va atado al cierre del diagnóstico, y un ensayo del
  gestor no cierra la misión de nadie: no la abrió.

## Lo que queda para la Fase 9

`evidence_ledger_available` va siempre en `false`, y es honesto: todavía no hay
ledger. Cuando lo haya, los follow-ups posteriores a la misión podrán trabajar
con la evidencia guardada en vez de quedarse sin nada, que es el «después de
completar, los follow-ups siguen funcionando con evidencia persistida» del §2.
