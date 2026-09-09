# 0010 · El cierre: criterios del cliente y ajuste al entorno

- **Fase:** 10
- **Fecha:** 8 de septiembre de 2026
- **Estado:** cerrado

## Su suite de aceptación, criterio por criterio

Los A01–A12 del §13 de su especificación no son una lista nuestra: son contra
lo que él dijo que revisaría el trabajo. Cada prueba lleva su identificador en
el nombre para que se puedan cotejar de un vistazo.

| ID | Qué comprueba | |
|---|---|---|
| A01 | Primera misión: `AVAILABLE → ACTIVE → COMPLETED`, medida | ✅ |
| A02 | Chat nuevo después: no permite segunda misión | ✅ |
| A03 | Cambiar de Entry Mode no resetea el entitlement | ✅ |
| A04 | Follow-up: responde con evidencia existente, sin web | ✅ |
| A05 | Recheck puntual: solo lo autorizado, sin reabrir discovery | ✅ |
| A06 | Tope a mitad de misión: se bloquea sin fingir investigación | ✅ |
| A07 | Concurrencia: una sola misión activa | ✅ |
| A08 | Renovación semanal: habilita una vez | ✅ |
| A09 | Reutiliza la evidencia de la semana anterior | ✅ |
| A10 | `PREPARED` sin mensaje → el validador lo rechaza | **fuera de alcance** |
| A11 | Pool ≥10 → el validador exige cuentas nominales | **fuera de alcance** |
| A12 | Telemetría completa con su coste | ✅ |

**A10 y A11 no se escriben, y conviene decir por qué en vez de dejarlos sin
marcar.** Los dos comprueban la **salida estructurada del Weekly GOLD Pack**,
que quedó explícitamente fuera del alcance acordado: hoy el Pack se entrega
dentro de la conversación y no como objeto validable cuenta por cuenta.
Escribir esas pruebas ahora sería fingir cobertura.

## El ajuste al entorno real

Sus dos prompts declaran `MULTIMODAL-FIRST` —analizar hojas de cálculo,
dashboards y capturas— y **la plataforma no admite archivos**: ni en el chat,
ni en el JavaScript, ni en el endpoint. Los archivos quedaron acordados para
una etapa posterior.

Sin decírselo, el agente pide lo que nadie le puede dar. Se vio el 07-09-2026:
en una misión de territorio pidió «un deck comercial, caso de cliente o ficha
de producto», y no hay forma de enviárselos. La persona se queda mirando una
petición que no puede atender.

**El arreglo no toca su prompt** (§15). Es una entrada más, en el mismo
lenguaje que su contrato ya usa —su regla de Tool Reality dice «úsalos solo si
disponibles Y autorizados»—:

```
PLATFORM_RUNTIME
file_upload: NOT_AVAILABLE
multimodal_input: NOT_AVAILABLE
user_can_paste_text: true
guidance: NO pidas archivos, capturas ni hojas de cálculo…
```

Va **siempre**, con búsqueda o sin ella: no tener archivos es cierto en los dos
casos. Primero se escribió dentro del bloque de investigación y solo aparecía
con la búsqueda encendida; era un error y se separó.

### Verificado con el agente real

Misma petición que la que provocó el problema:

| | |
|---|---|
| **Antes** | «compárteme una sola pieza: **un deck comercial, caso de cliente, ficha de producto**…» |
| **Ahora** | «comparte la URL oficial **o pega aquí el texto** de su página principal…» |

Cero menciones a archivos, decks, adjuntos, capturas, Excel o PDF.

## La prueba de humo

Sube a **42 comprobaciones**. Las nuevas miran la pantalla de consumo, y miran
su **estructura, no sus cifras**: un panel que desaparece porque no hay datos es
correcto; uno que desaparece porque el controlador reventó, no. Desde fuera se
ven igual, y por eso se comprueba que haya o el resumen o el estado vacío, pero
nunca ninguno de los dos.

También comprueba que **no vuelvan las tablas crudas de administración**, que
es como se entregó esa pantalla la primera vez.

Una de las comprobaciones nuevas falló al escribirla, y el fallo era de la
comprobación: la etiqueta se pinta con `text-transform` y `innerText` devuelve
**lo renderizado**, no lo escrito. Comparar literalmente daba un fallo que no
lo era.

## Estado al cerrar la etapa

- **328 pruebas**, phpcs en cero, humo en **42 de 42**, cero errores de consola.
- Diez decisiones escritas, de la 0001 a la 0010.
- La búsqueda queda **apagada**: el interruptor es global y encenderla se la
  daría también al agente de diagnóstico. Deja de necesitar interruptor cuando
  el entitlement decida **por agente**, que hoy decide por persona.
