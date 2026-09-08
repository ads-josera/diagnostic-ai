# 0002 · La caché del prompt: cómo se comporta de verdad

- **Fase:** 2 — Knowledge Retrieval Contract y orden para caché
- **Fecha:** 7 de septiembre de 2026
- **Estado:** medido; **no se cambió nada en el código**
- **Modelo:** `gpt-5.6-terra` · endpoint `/v1/responses`

Este documento existe para que nadie —empezando por quien lo escribe— vuelva a
gastar media tarde y unos $37 MXN redescubriendo esto.

## Lo primero: la caché ya funcionaba

El plan de la Fase 2 decía «reordenar el prompt para caché». Se fue a mirar
antes de tocarlo y **el orden ya era correcto**: lo estático va delante y lo
variable —la memoria del alumno— al final.

Medido con el prompt real de GAP:

| | Entrada | Cacheado |
|---|---:|---:|
| Alumno A, turno 1 | 105,047 | 0 % |
| Alumno A, turno 2 | 105,051 | **100 %** |

**Dentro de una sesión se cachea entero desde el segundo turno.** Reordenar no
habría ganado nada, y habría metido 130 000 tokens de documentos por delante
del «You are GAP Prospecting AI…», que sí puede cambiar el comportamiento.

## El tamaño real del prompt

| Agente | Caracteres | Tokens medidos |
|---|---:|---:|
| GAP Prospecting AI | 534,732 | **105,017** |
| Sales Leadership Diagnostic AI | 55,839 | **12,058** |

Salen **5.09 caracteres por token** en GAP y 4.63 en el otro. La estimación del
módulo —palabras × 1,4— se queda un 6 % corta, que es suficiente para lo que
sirve: que el gestor calibre el tamaño de la biblioteca.

**La cifra de ~130 000 tokens que se manejó antes era un 20 % alta.**

## Lo que cuesta de verdad una misión

Ocho turnos, con la caché que ya existe:

| | MXN |
|---|---:|
| Si no hubiera caché | ~$31 |
| **Con la caché actual** | **~$8.50** |

Al mes, con cuatro misiones: **~$34**. El techo de $100 por alumno se cumple
hoy, sin desarrollar nada.

## La regla que gobierna la caché

**El proveedor no hace coincidencia parcial.** Si algo difiere antes del último
mensaje, descarta el prefijo entero aunque sea idéntico:

| Escenario | Cacheado |
|---|---:|
| Mismo alumno, otra pregunta | **100 %** |
| Alumnos SIN memoria, mensaje de sistema idéntico | **100 %** |
| Alumnos con memoria distinta, memoria dentro del prompt | **0 %** |
| Alumnos con memoria distinta, memoria en mensaje aparte | **0 %** |

Las dos últimas filas comparten **105,017 tokens idénticos** y cachean cero.

También se probó `prompt_cache_key`, el parámetro de OpenAI para fijar el
reparto entre máquinas: **no cambia nada**.

## Lo que se intentó y se descartó

Se llegó a implementar sacar la memoria del alumno a un campo propio de la
sesión y mandarla como mensaje aparte. Funcionaba: 270 pruebas en verde, phpcs
limpio, cinco pruebas nuevas que cazaban la regresión.

**Se revirtió, porque no ahorraba nada.** La tabla de arriba, últimas dos
filas: da igual dónde esté la memoria; mientras cada alumno lleve la suya antes
del último mensaje, no hay prefijo compartido.

Quedaba un campo en la base de datos, una rama permanente en el armado de
mensajes y unos comentarios que explicaban un ahorro inexistente. Eso es peor
que no tenerlo.

**No volver a intentarlo sin medir primero.** La única forma de que dos alumnos
compartan caché es que no haya NADA distinto entre ellos antes del último
mensaje, y la memoria es, por definición, distinta.

## Qué queda pendiente de esta fase

El **Knowledge Retrieval Contract** del Documento 13 §9 no se puede construir
todavía. Es un mapa tarea → documentos, y no se sabe la tarea hasta que la
conversación avanza; el prompt se congela al iniciar la sesión.

Su sitio natural es **después de la Fase 6**, cuando el agente pueda pedir
documentos como herramienta, que es como funciona en el GPT del cliente.

Su ahorro sí es real —bajar de 105 000 a unos 40 000 tokens recorta la misión
a la mitad—, pero es menor de lo que se pensaba, porque la caché ya se lleva la
mayor parte.
