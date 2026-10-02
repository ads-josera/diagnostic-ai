# 0021 · Una cita tiene que poder comprobarse

**Fecha:** 02-10-2026
**Estado:** aplicada

## El día que lo provocó

Tras los arreglos de la [0020](0020-la-materia-prima-de-la-investigacion.md) se
repitió el caso que el cliente había perdido contra ChatGPT. El Weekly GOLD Pack
nuevo cribó diez cuentas, citó **catorce fuentes con su URL** y verificó **cinco
compradores con nombre y cargo** —ChatGPT había verificado dos—.

Al revisar ese resultado en producción apareció lo que nadie había preguntado
todavía: **no había forma de comprobar que esas catorce URL existieran.**

- `sld_tool_call` guardaba la consulta, cuántos resultados y cuántos
  caracteres. **Las URL que devolvía cada búsqueda, no.**
- `sld_evidence.source` guarda la procedencia que **declara el agente**, no lo
  que la herramienta trajo.

O sea: cada fuente del entregable estaba sobre la palabra del modelo, y el
sistema no tenía con qué contrastarla.

## Por qué esto es lo peor que puede fallar aquí

Un error visible se arregla. Este no se ve: **una URL inventada no parece un
error, parece un hecho.** Y el entregable la pone justo donde más duele, al lado
de «Presidente Ejecutivo de Banco Guayaquil». Quien lo reciba va a salir a
decirlo, y lo va a decir con nuestro nombre detrás.

Con cinco ejecutivos citados por nombre y cargo, el pack sin forma de auditar
sus fuentes es **más arriesgado** que el que fallaba, no menos.

## Qué se hizo

| | Dónde | Qué |
|---|---|---|
| Guardar | `sld_tool_call.result_urls` | Las URL que devolvió cada llamada, como lista JSON |
| Extraer | `ToolGateway::urlsOf()` | Del mismo desarmado que ya servía para contar los resultados |
| Leer | `ToolCallRepository::retrievedUrlsInMission()` | Todo lo que las búsquedas de una misión trajeron de verdad |
| Contrastar | `CitationAudit` | Cada fuente citada contra esa lista, al guardar el resultado |

Las URL se sacan **en el gateway** y no en la herramienta porque allí ya está
desarmada la respuesta para contarla: hacerlo en otro sitio obligaría a
desarmarla dos veces y a que las dos lecturas pudieran decir cosas distintas.
Y vale para cualquier herramienta futura que devuelva `resultados` con su `url`,
sin tocarla.

## Tres resultados, no dos

La revisión no responde «sí o no». Separa:

- **Respaldada** — la URL salió de una búsqueda de esta misión.
- **Otra página** — el sitio sí se visitó, esa página concreta no. Puede ser
  legítimo: el agente pudo seguir un enlace que venía dentro de un resultado.
- **No vista** — ese sitio no aparece en ninguna búsqueda. No tiene origen.

La comparación se hace con la **dirección completa**, no con el dominio. Es
deliberado y es el punto que la prueba defiende: la invención más probable no es
un dominio falso, es **una ruta inventada dentro de un dominio real**, y
normalizar hasta el dominio la dejaría pasar por buena. Sí se igualan las
diferencias que no cambian la página —el esquema, el `www.`, la barra final, el
fragmento—, porque si contaran, la revisión avisaría de invenciones que no lo
son y en dos semanas nadie volvería a mirar el aviso.

Y hay un cuarto estado que importa tanto como los otros: **sin registro**. En
una misión anterior a este cambio no se guardaron las URL y no se pueden
reconstruir. Devolver ahí «catorce citas no vistas» sería una acusación falsa, y
es exactamente la clase de cifra que luego alguien repite.

## Lo que NO hace, y por qué

**No altera el entregable.** Mide y deja constancia en el registro.

Borrar una cita que el agente puso puede romper un pack bueno —el caso «otra
página» puede ser legítimo— y qué hacer con una cita sin respaldo es una
decisión de producto, no de código: avisar al alumno, degradar la cuenta a
CHECK REQUIRED, o quitarla. Esa decisión se toma **con la medida delante**,
cuando se sepa si ocurre y cuánto. Primero hay que saberlo.

También se revisa **después** de guardar el resultado, a propósito: una cita sin
respaldo no puede impedir que el alumno reciba su entregable.

Y el aviso anota la misión y las URL —páginas públicas—, nunca el texto de la
conversación ni quién es la persona: el §31 y el §43 lo prohíben, y una cita sin
respaldo se investiga igual de bien sabiendo en qué misión fue.

## Cómo comprobar si funcionó

En la siguiente misión real, buscar en el registro `citas_sin_respaldo`. Las
tres cifras del aviso son la medida:

- Si no aparece el aviso, las catorce de aquel día fueron reales y el riesgo era
  teórico.
- Si aparece con **no vistas**, hay invención y toca decidir qué se hace.
- Si aparece solo con **otra página**, el agente está siguiendo enlaces dentro
  de los resultados, y entonces lo que falta es la herramienta de leer páginas
  enteras que la 0020 dejó fuera.

**Lo que no se puede hacer es mirar atrás.** De las misiones anteriores al
02-10-2026 no se guardaron las URL. Las catorce de aquel pack solo se pueden
comprobar abriéndolas a mano.
