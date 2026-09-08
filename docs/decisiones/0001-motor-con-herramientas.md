# 0001 · El motor migra a `/v1/responses`

- **Fase:** 0 — comprobación técnica
- **Fecha:** 7 de septiembre de 2026
- **Estado:** decidido
- **Modelo en uso al decidir:** `gpt-5.6-terra`

## La pregunta

La búsqueda externa exige que el modelo pueda **pedir herramientas**. Hoy el
módulo habla por `/v1/chat/completions` y manda `response_format` estricto en
todas sus llamadas. Antes de planificar el trabajo había que saber si esa
combinación admite herramientas o si obliga a cambiar de endpoint.

No se dio por supuesto: se midió contra la API real, como exige el propio
docblock de `OpenAIClient`.

## Lo que se midió

| Sonda | Endpoint | Qué se envió | Resultado |
|---|---|---|---|
| A | `chat/completions` | `tools` | **400** — no soportado |
| B | `chat/completions` | `tools` + `response_format` estricto | **400** — no soportado |
| C | `chat/completions` | `tools` + `reasoning_effort: none` | 200 · `tool_calls` · razonamiento **0** |
| D | `chat/completions` | igual que C + `response_format` estricto | 200 · `tool_calls` · razonamiento **0** |
| E | `responses` | `tools` | 200 · `function_call` · razonamiento **16** |
| F | `responses` | `tools` + salida estructurada estricta | 200 · `function_call` · razonamiento **22** |
| G | `responses` | mismo prefijo de 5 551 tokens, dos veces | 2.ª llamada: **5 538 cacheados (99.7 %)** |

El error de A y B lo explicó la propia API:

> «Function tools with reasoning_effort are not supported for gpt-5.6-terra in
> /v1/chat/completions. To use function tools, use /v1/responses or set
> reasoning_effort to 'none'.»

## La decisión

**El motor migra a `/v1/responses`.**

Es el único camino que conserva el razonamiento del modelo, y la sonda F
demuestra que ahí conviven herramientas y salida estructurada estricta, que es
lo que el módulo necesita en la misma llamada.

## Por qué no el otro camino

Quedarse en `chat/completions` era posible apagando el razonamiento
(`reasoning_effort: none`). Se descarta por tres motivos:

1. **El módulo está construido alrededor del razonamiento.** Por eso el
   presupuesto de tokens por respuesta es holgado —8 000— y por eso está
   documentado que el modelo consume parte de ese presupuesto antes de escribir.
2. **La metodología de GAP es analítica.** Scoring, gates, comparación entre
   diez o más cuentas y un documento entero —el 11— dedicado a QA, Red Team y
   anti-alucinación. Apagar el razonamiento **justo cuando se añaden datos
   externos sin verificar** es el peor momento posible para hacerlo.
3. **Hay un solo cliente y una sola configuración.** Apagarlo para el agente de
   prospección lo apagaría también para el de diagnóstico, que ya tiene
   diagnósticos hechos con el comportamiento actual.

## Qué hay que tocar

Todo queda dentro de `OpenAIClient` y sus pruebas:

| Hoy | Con `/v1/responses` |
|---|---|
| `messages` | `input` |
| `max_completion_tokens` | `max_output_tokens` |
| `response_format.json_schema` | `text.format` |
| `choices[0].message.content` | array `output[]` con elementos tipados |
| `finish_reason` | `status` (`completed` / `incomplete`) |
| `prompt_tokens` / `completion_tokens` | `input_tokens` / `output_tokens` |
| — | `input_tokens_details.cached_tokens` |

Y una pieza nueva que hoy no existe: **el bucle de herramientas**. Hoy es una
petición y una respuesta; con herramientas son varias vueltas hasta que el
modelo deja de pedir y contesta.

`extractObject()` tendrá que recorrer `output[]`, donde el elemento de tipo
`function_call` llega **junto al de razonamiento** y el mensaje puede no venir.

## Efecto secundario que conviene aprovechar

La sonda G confirma que la caché de prefijo funciona en el endpoint nuevo, y
funciona bien: **99.7 %** del prefijo estático llegó cacheado en la segunda
llamada. Es la premisa económica de la Fase 2 y queda comprobada antes de
depender de ella.

También queda comprobado que `/v1/responses` **informa de los tokens
cacheados**, que es lo que la telemetría de la Fase 4 necesita para separar lo
que se paga entero de lo que se paga con descuento.

## Proveedor de búsqueda

Recomendación de partida, **a confirmar al llegar a la Fase 6**: precios y
planes cambian, y la elección es barata de revertir porque el proveedor vive
detrás del gateway.

| Proveedor | Por 1 000 búsquedas | Devuelve |
|---|---|---|
| Serper | ~$1 USD | Resultados de Google; **sin** el texto de las páginas |
| Tavily, por suscripción | ~$3 USD | Contenido ya extraído |
| Brave | ~$5 USD | Resultados; contenido parcial |
| Tavily, pago por uso | $0.008 por crédito | Contenido ya extraído |
| Búsqueda integrada de OpenAI | ~$10 USD | Contenido, **sin control nuestro** |

**Se propone Tavily.** GAP no necesita enlaces: necesita leer para verificar
identidad, empleo, cargo y recencia de un Buyer. Serper es más barato pero
obligaría a añadir nuestro propio raspado de páginas. Y frente a la búsqueda
integrada de OpenAI, el proveedor propio es lo que permite cumplir el §6 de la
especificación del cliente: **decidir cuánto texto entra al modelo**, que es
donde está la mayor parte del gasto.

Precios consultados el 07-09-2026. El de pago por uso está tomado de la propia
página de Tavily; los comparadores citaban $0.003, que es la tarifa por
suscripción y no la de entrada.

## Cómo reproducirlo

Las sondas fueron temporales y no quedaron en el repositorio. Se replican con
una petición a cada endpoint declarando una función cualquiera y mirando
`finish_reason` / `status` y la presencia de `tool_calls` / `function_call`.

Las siete llamadas costaron menos de **$0.50 MXN** en total.
