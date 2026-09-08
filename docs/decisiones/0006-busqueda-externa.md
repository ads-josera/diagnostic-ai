# 0006 · El motor con herramientas y el buscador

- **Fase:** 6
- **Fecha:** 8 de septiembre de 2026
- **Estado:** implementado y **verificado contra el buscador real**

## Lo que hubo que preguntarle a la API

Dos cosas que no están en la documentación que se consultó y que se
descubrieron sondeando. Las dos tienen prueba, porque sin ella una limpieza
razonable del código las deshace.

**1. Hay que reenviarle su propio razonamiento.** Cuando el modelo pide una
herramienta y se le devuelve solo el resultado, contesta con un 400:

> «Item 'fc_…' of type 'function_call' was provided without its required
> 'reasoning' item: 'rs_…'.»

Por eso se reenvía la salida ENTERA del turno anterior, sin filtrar.

**2. El modelo no para de pedir.** Nada más recibir la primera búsqueda pidió
**dos más**. El tope de vueltas no es defensivo: es el caso normal. Cada vuelta
es otra llamada y, con búsqueda, varias búsquedas más.

## Lo que hubo que preguntarle al buscador

**La fecha solo viene con `topic: news`.** En una búsqueda normal el campo
sencillamente no está. Y sin fecha no se puede distinguir una señal de esta
semana de una de 2023 —la primera prueba devolvió justo eso—, que es
exactamente el caso T07 de su Documento 15.

Así que la herramienta le ofrece al modelo elegir entre `general` y `noticias`,
y la descripción le dice por qué importa. No es una opción de alcance: es la
única forma de que pueda afirmar que algo es reciente.

**La profundidad avanzada no compra nada hoy.** Devuelve ~2 000 caracteres
frente a ~1 300, y aquí se recortan a 1 200 de todos modos. Pagar el doble por
texto que se tira no tiene sentido; subir antes el recorte sí lo tendría.

## Verificación con el agente real

Misión de Account Intelligence sobre Cemex, con búsqueda encendida. El agente
buscó, encontró una señal pública real —una vacante que describe «10 000 zonas
de transporte»— y **la clasificó SILVER, no GOLD**, negándose a liberar
outreach por no tener señal fresca verificable ni Buyer validado.

Es su metodología funcionando con datos reales: no infló lo que encontró.

## Lo que cuesta, medido

Un turno con búsqueda son **tres llamadas al modelo**:

| Llamada | Entrada | Cacheados | Coste | Tiempo |
|---|---:|---:|---:|---:|
| Primera | 105 485 | **0** | $0.2136 | 6.2 s |
| Vuelta 1 | 107 988 | 105 482 | $0.0293 | 5.9 s |
| Vuelta 2 | 115 627 | 107 985 | $0.0672 | 44.6 s |

**Total: $0.3102 USD = $5.27 MXN.**

Y la buena noticia está en la columna de cacheados: **las vueltas de
herramientas son baratas**. El prompt se mantiene cacheado y lo único que se
paga entero son los resultados que entran. Las dos vueltas añadieron un 45 %
sobre la primera llamada, no un 200 %.

## El problema que esto destapa: el tiempo

**El turno entero tardó 76 segundos**, y una de las llamadas 44.6 por sí sola.
El límite configurado son 60.

Ya no es una previsión: **con búsqueda, un turno se sale de lo que aguanta una
petición web**, y eso con solo dos vueltas sobre una cuenta concreta. Una misión
Discovery, que criba diez cuentas y profundiza tres, no cabe ni de lejos.

Es la prueba que faltaba de por qué la ejecución en segundo plano no es un
lujo. Queda anotado para la Etapa 2.

## Estado en que queda

**La búsqueda queda APAGADA**, y no por prudencia genérica: el interruptor es
global y encenderlo se la daría también al agente de diagnóstico, que no la
necesita. Peor: declarar herramientas cambia el prefijo del prompt, así que ese
agente perdería su caché a cambio de nada.

Se enciende en **Configuración → Salesbumm → Diagnostic AI** cuando se quiera
probar. Deja de necesitar el interruptor en la **Fase 8**, cuando el Research
Entitlement decida por agente y por turno quién puede buscar.

## Lo que queda pendiente de contar

`WebSearchTool` registra las búsquedas que **fallan**, no las que salen bien.
La telemetría de búsquedas —`search_count`, `tool_calls`, tokens web
recuperados— es de la Fase 7, y es lo que el §14 de la especificación del
cliente pide medir.
