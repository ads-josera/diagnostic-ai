# Metodología y prompts del cliente

Material que entrega **Salesbumm** y que define **qué debe hacer y cómo debe
razonar** cada agente. No es código nuestro y **no se modifica**: si algo
parece incoherente, se le señala al cliente documento y regla, y se espera su
respuesta (§15).

Vive aquí por una razón concreta: **son la fuente de la que se cargan los
agentes**, y hasta el 05-09-2026 solo existían en la carpeta de descargas de
una máquina y en el historial de una conversación. Un borrado accidental
costaba semanas.

## Qué hay

### Los dos prompts, baseline actual

| Archivo | Qué es |
|---|---|
| `GAP_Prospecting_AI_CORE_INSTRUCTIONS_v1.4.3_COMPACT.txt` | Baseline **activo** de GAP, confirmado por Omar el 04-09-2026. Sustituye a la v1.4.1. |
| `Sales_Leadership_Diagnostic_AI_prompt_final_aprobado.txt` | Baseline del agente de diagnóstico. |

**Ninguno de los dos está cargado todavía.** Ver «Por qué no» más abajo.

### La especificación de backend

`GAP_Prospecting_AI_Backend_Research_Entitlement_Implementation_Spec_v1.0.docx`
— define Research Entitlement, máquina de estados, Tool Gateway, Evidence
Ledger, telemetría, hard limits y los criterios de aceptación A01–A12. Es el
documento contra el que se implementa la Etapa 1.

**Ojo**: su cabecera dice «BASELINE CONGELADO: CORE v1.4.1». Omar confirmó por
correo que **manda la v1.4.3**; la línea del documento quedó del envío
anterior.

### Los 15 documentos de Knowledge de GAP

Numerados del 01 al 13, más `Master` y el 15. **El Documento 14 no existe** y
no es una pieza faltante: lo aclara el propio Documento 13.

El **Documento 13, sección 9**, contiene el *Knowledge Retrieval Contract*: qué
documentos son obligatorios para cada tarea. Es la pieza que evita mandar los
15 en cada turno.

## Versiones: no reconciliar por cuenta propia

El manifiesto del Documento 13 lista toda la arquitectura en v1.2, pero lo
entregado mezcla versiones: 01, 02 y 05 en v1.3; 07 en v1.4; 12 en v1.5; 15 en
v1.7; y 03, 04, 08, 09, 10 y 11 en **v1.1**.

Omar lo respondió el 04-09-2026: **es el conjunto disponible, no hay versiones
más recientes**, y su criterio es que el Documento 13 y las reglas explícitas
de autoridad gobiernen las relaciones entre Engines. **No hay que inferir que
un número de versión superior altere la autoridad de otro documento.**

Si aparece una contradicción material concreta, se le indica *documento + regla
en conflicto + impacto en la implementación* antes de resolverla. Es su propia
prueba K02: señalar la colisión, nunca mezclar en silencio.

## Por qué no están cargados todavía

Tres motivos, y los tres se resuelven en la Etapa 1:

1. **Coste.** El módulo concatena hoy todos los documentos de un agente en
   cada turno. Los 15 suman ~130 000 tokens: unos **$35 MXN por conversación
   de prueba**. El Knowledge Retrieval Contract lo arregla, y es parte de la
   Etapa 1.
2. **`MULTIMODAL-FIRST`.** Ambos prompts piden analizar Excel, dashboards y
   capturas. **La plataforma no admite archivos** —ni en el chat, ni en el
   JavaScript, ni en el endpoint—. Acordado con el cliente: los archivos son
   Etapa 2.
3. **Presupuesto.** Cargar y verificar son las primeras horas de la Etapa 1,
   que a 05-09-2026 el cliente todavía estaba gestionando con sus
   inversionistas.

## Al cargarlos

- El prompt del **agente 1** cambia un agente que ya tiene diagnósticos hechos:
  hay que **subir la versión** y dejar constancia, porque cada sesión guarda
  copia del prompt con el que se generó (§57).
- El del **agente 2** sustituye al de muestra que se escribió el 02-09 para
  poder enseñar la pantalla con dos agentes.
- Los documentos entran por la biblioteca de conocimiento del módulo, que
  extrae el texto de PDF, DOCX, TXT y Markdown.
