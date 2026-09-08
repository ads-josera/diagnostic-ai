# 0004 · Telemetría del consumo de IA

- **Fase:** 4
- **Fecha:** 7 de septiembre de 2026
- **Estado:** implementado y verificado

## El problema que resuelve

El módulo apuntaba tokens de entrada y salida en el registro del sistema, una
línea suelta por llamada. Imposible de sumar, imposible de acotar por periodo
y —lo grave— **sin los tokens cacheados**.

Con la caché funcionando eso no redondea al alza: multiplica. Medido con una
conversación real de dos turnos:

| | |
|---|---:|
| Lo que decía el registro | $7.47 MXN |
| **Lo que costó** | **$1.03 MXN** |

**Un factor de 7.** Y sobre esa cifra iban a montarse los topes de la Fase 5.
Un tope calculado siete veces alto corta el servicio a alumnos que no han
gastado lo que parece, y lo hace sin que nadie entienda por qué.

## Cómo llega el dato sin romper el aislamiento

Hay una tensión real: el coste se mide donde se conoce —dentro del cliente de
OpenAI, que ve tokens y tiempo—, pero ese cliente **no sabe de qué alumno se
trata y no debe saberlo**: lo que se le envía al proveedor está
deliberadamente libre de identidad (§31, §43).

Se resuelve en dos pasos y sin estado escondido:

1. `OpenAIClient` deposita en `AiUsageCollector` lo que sabe: modelo, tokens,
   latencia, intentos, error. Nada de identidad.
2. `ConversationService`, que sí conoce alumno, agente y sesión, lo recoge y lo
   guarda.

El vaciado va **en el `finally`**, no detrás del `return`. Es el mismo criterio
que ya seguía el limitador de mensajes: lo que cuesta dinero es el intento, y
detrás de un `return` se perdería justo el consumo de los turnos que se
rompieron, que son de los más caros —una respuesta cortada por presupuesto de
salida agotó el presupuesto entero antes de fallar—.

Por eso también se anota **antes** de comprobar si el contenido sirve: una
respuesta 200 con el JSON cortado ya está pagada.

## Decisiones que costaron un intento

- **Tabla, no entidad de contenido.** Son cifras que se escriben una vez, no se
  editan jamás, se consultan sumadas y crecen sin techo. Como entidad quedarían
  expuestas a Views y cada suma cargaría miles de objetos para sumar enteros.
- **Las tarifas van como LISTA, no como mapa `modelo => tarifa`.** La
  configuración de Drupal no admite puntos en las claves, y todos los modelos
  los llevan: `gpt-5.6-terra` revienta al guardar.
- **El coste se congela al escribirlo.** Recalcularlo al leer reescribiría el
  pasado cada vez que el proveedor cambiara de precios.
- **Un modelo sin tarifa cuesta cero, no una aproximación.** Un número
  inventado en la pantalla de consumo es peor que un hueco: el hueco se ve y
  lleva a configurar la tarifa; el número se cree.
- **El tipo de cambio a pesos es solo para mostrar.** No entra en ningún
  cálculo ni gobernará los topes: lo que cobra el proveedor son dólares.

## Los ensayos del estudio

Cuestan dinero real, así que se registran. Pero **no cuentan contra el cupo de
ningún alumno**: los hace el gestor para probar un prompt, y cargárselos a
quien figure como dueño de la sesión le comería su diagnóstico sin haber hecho
nada. Sí cuentan en el total global, porque la factura no distingue.

## Qué se ve

Pestaña **Consumo**, junto a Resultados y Estudio. Cuatro tablas: resumen del
periodo, por agente, quién consume y las últimas llamadas una a una, con sus
marcas de ensayo, fallo y reintentos.

El resumen enseña a propósito **cuánto habría costado sin el descuento por
reutilización**. Sin esa comparación el ahorro es una afirmación nuestra; con
ella es una resta que cualquiera repite.

## Verificación

- **275 pruebas** (10 nuevas), estilo limpio, humo en **38 de 38**.
- Las pruebas se rompieron a propósito ignorando la caché: dos fallan, incluida
  la que fija el 10 %.
- Conversación real de dos turnos: 210 763 tokens de entrada, **210 530
  cacheados (99.9 %)**, $0.0607 USD. La pantalla lo muestra desglosado.
