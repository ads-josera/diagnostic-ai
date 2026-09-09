# 0012 — Los topes que hacen falta para cribar

**08-09-2026**

## El problema

Con `max_calls_per_mission: 12` una misión Discovery real se quedaba sin margen
a mitad. El agente lo declaraba honestamente —marcaba lo no verificado en vez de
inventarlo, que es el comportamiento correcto— pero no cribaba, que es para lo
que existe la puerta.

No era un fallo del gateway: era un tope calibrado para otra cosa. Los 12 se
eligieron pensando en **una cuenta**. La metodología del cliente pide otra cosa
para Discovery, y lo dice con estas palabras en su propio prompt:

> «En Discovery, antes de profundizar/STOP WHEN SUFFICIENT, haz normalmente
> screening ligero de ≥10 candidatos plausibles distintos, salvo menos
> candidatos reales o límite runtime. Es piso, NO cuota.»

Diez cuentas cribadas más tres a cinco profundizadas no caben en doce búsquedas.

## Lo que se cambió

| Ajuste | Antes | Ahora |
|---|---|---|
| `tools.max_calls_per_mission` | 12 | **50** |
| `tools.max_retrieved_chars_per_mission` | 40 000 | **200 000** |
| `tools.max_calls_per_user_period` | 60 | **220** |
| `search.max_tool_rounds` | 4 | **20** |

Las vueltas eran el tope que más apretaba y el que menos se veía: el modelo pide
dos o tres búsquedas por vuelta, así que con 4 vueltas el techo real eran unas
diez búsquedas, no las doce del otro tope.

**Subir las vueltas ya no alarga ninguna espera.** Antes habría sido caro: cada
vuelta es una llamada más dentro de la misma petición web. Desde
[0011](0011-turnos-en-segundo-plano.md) estos turnos corren en la cola, así que
lo único que crece es el trabajo del cron.

## Lo que NO cambia: el tope de dinero

Parecen números generosos y no lo son, porque no son los que acotan el gasto.
Lo que acota el gasto es el entitlement —**una misión de investigación por
persona y semana**— y, por debajo de todo, el tope global en dólares. Estos
topes solo impiden que UNA misión se desboque.

Por eso 220 al mes: cuatro misiones de 50, con margen. Si alguien encontrara la
manera de gastar más que eso, el problema estaría en el entitlement, no aquí.

## La misión que se midió

Sesión 95, agente de prospección, tres turnos, sin ensayo. Un director comercial
de una empresa de telemetría para flotillas pesadas; territorio Bajío y Nuevo
León; sectores manufactura, alimentos y bebidas, y transporte de carga.

| | |
|---|---|
| Búsquedas usadas | **27** de 50 |
| Texto externo incorporado | **81 627** de 200 000 caracteres |
| Llamadas al modelo | 11 |
| Tokens de entrada | 1 257 395, **90 % reutilizados** por la caché |
| Tokens de salida | 8 047 (2 014 de razonamiento) |
| Tiempo de modelo | 118 s |
| Tiempo de búsqueda | ~55 s (2,1 s por búsqueda) |
| **Coste de la misión completa** | **$0.5806 USD** |
| Afirmaciones guardadas en el ledger | 7 |

El turno pesado —el que criba— gastó 25 búsquedas y unos 95 s de modelo. En una
petición web habrían sido dos minutos y medio de página en blanco; en la cola,
la petición del alumno duró **0,05 s**.

Los topes quedaron con holgura de casi el doble en las dos dimensiones. Es
deliberado: la primera cuenta que necesite quince búsquedas en vez de diez no
debe chocar contra el techo.

## Lo que entregó

Diez cuentas cribadas con disposición y motivo; ranking separado de oportunidad
y de ejecución; tres cuentas profundizadas con why-now fechado, hipótesis GAP,
la alternativa que compite con la tesis, Buyer, qué NO afirmar, routing, copy
preparado y siguiente acción. Ninguna cuenta liberada para envío sin el chequeo
de CRM/DNC/owner, que es lo que su Director Workload Test pide.

Dos cosas que conviene subrayar porque son suyas, no nuestras:

- **No asumió el territorio.** Preguntó el battlefield antes de buscar, y lo dijo:
  «No asumiré que México nacional es el territorio solo porque la empresa sea
  mexicana.»
- **Degradó el proof sin que nadie se lo pidiera.** Se le dio un caso de cliente
  con una cifra; lo trató como proof interno no autorizado para mensajes
  externos.

## Estos números son provisionales

Su §9 pide que los topes se calibren por benchmark y no por decreto. Esto es
**un** benchmark: una misión, un sector, un territorio. Sirve para saber que 12
era corto y que 50 sobra; no sirve para afirmar que 50 sea el número. Se revisa
cuando haya media docena de misiones reales medidas.

## El interruptor sigue apagado

`search.enabled` volvió a **NO** al terminar la prueba. No es por los topes: es
porque el interruptor es **global** y la búsqueda debería decidirse **por
agente**. Hoy el entitlement decide por persona, así que encenderlo daría
herramientas también al agente de diagnóstico GAP, que no las necesita y que
gastaría la misión semanal de esa persona sin dar nada a cambio.

Es lo único que separa a Discovery de estar en producción.
