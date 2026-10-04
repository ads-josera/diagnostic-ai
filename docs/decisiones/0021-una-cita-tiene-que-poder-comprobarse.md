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

## Cuatro resultados, no dos

La revisión no responde «sí o no». Separa:

- **Respaldada** — la URL salió de una búsqueda de esta misión. Es el único
  respaldo fuerte.
- **Declarada** — el agente ya la tenía anotada en el ledger, puede que semanas
  antes. Reutilizar evidencia es justo para lo que el ledger existe, y su URL no
  sale de ninguna búsqueda de ESTA misión: sin esta categoría, la revisión
  avisaría de invención cada vez que el sistema hace lo que debe, y en dos
  semanas nadie volvería a mirar el aviso. Va aparte y **no cuenta como
  respaldo**, porque esas filas las escribió el propio agente: si se sumaran a
  lo recuperado, bastaría con que anotara una URL inventada para quedar
  respaldado por sí mismo.
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

Y hay un quinto estado que importa tanto como los otros: **sin registro**. En
una misión anterior a este cambio no se guardaron las URL y no se pueden
reconstruir. Devolver ahí «catorce citas no vistas» sería una acusación falsa, y
es exactamente la clase de cifra que luego alguien repite.

### Dos silencios que significan lo contrario

La primera versión de esta clase tenía el peor fallo posible: el que no avisa.
Declaraba «sin registro» siempre que no hubiera URL con que comparar, y eso
confunde dos situaciones opuestas.

| Búsquedas concedidas | Con URL guardadas | Qué significa | Qué hace |
|---|---|---|---|
| 0 | — | Lo citado **no puede venir de ninguna parte** | Todo a «no vista», y avisa |
| >0 | 0 | Misión anterior al cambio | «Sin registro», y también deja línea |
| >0 | >0 | Caso normal | Compara |

La primera fila es exactamente el caso de las catorce citas inventadas —una
misión sin búsquedas, o con todas denegadas, que entrega un pack lleno de
fuentes— y quedaba muda. Lo encontró la revisión de Jarvis, no una prueba.

Y el «sin registro» también escribe en el registro, con su propio mensaje
(`citas_sin_comprobar`). Callar ahí era el mismo error en pequeño: el silencio
se lee como «todo bien».

### Se auditan los dos sitios donde puede estar una cita

Las `sources` de cada cuenta, que es lo que se guarda, **y los enlaces del
Markdown que la persona lee**. Desde el mismo día, el contrato le pide al agente
los enlaces en el `message`; auditar solo las `sources` dejaría sin revisar
justo lo que se le acababa de pedir poner en el otro sitio.

## Lo que NO hace, y por qué

**No altera el entregable.** Mide y deja constancia en el registro.

Borrar una cita que el agente puso puede romper un pack bueno —el caso «otra
página» puede ser legítimo— y qué hacer con una cita sin respaldo es una
decisión de producto, no de código: avisar al alumno, degradar la cuenta a
CHECK REQUIRED, o quitarla. Esa decisión se toma **con la medida delante**,
cuando se sepa si ocurre y cuánto. Primero hay que saberlo.

También se revisa **después** de guardar el resultado, a propósito: una cita sin
respaldo no puede impedir que el alumno reciba su entregable.

Y por lo mismo va envuelta en un `try/catch`. Corre antes de marcar la sesión
como completada, así que una excepción dejaría al alumno con entregable y la
sesión a medias, con el turno ya pagado. Cubrir ese guardia costó extraer
`CitationAuditInterface`: la primera prueba tiraba la tabla que la revisión
consulta y **pasaba igual sin el try/catch**, porque la revisión solo toca la
base si el entregable cita algo y el motor simulado no cita nada. Se retiró —una
prueba verde que no demuestra nada es peor que ninguna— y con la interfaz la
prueba sustituye la revisión por una que revienta siempre.

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

## Lo que enseñó la primera misión de control (02-10-2026, 15:37)

**Un 404 que pasó en silencio.** La búsqueda devolvió una URL con
`…tras-10-anos-de-ausencia…`; el agente escribió `…tras-diez-anos…`, que da
404, la anotó en el ledger **en la misma conversación** y la citó. Como estaba en
el ledger, salió como «declarada», y las declaradas no avisan. Era exactamente el
auto-respaldo que la categoría existía para impedir, por la puerta de atrás.

Ahora solo cuenta como declarada la evidencia de **conversaciones anteriores**.
Lo anotado en la conversación en curso se contrasta contra lo que las búsquedas
trajeron, como cualquier otra cita: ese caso habría salido como «otra página del
mismo sitio».

**Los compradores desaparecieron.** Las dos misiones con el contrato de
`c26306e` se saltaron la ronda de búsqueda de ejecutivos que la misión anterior
sí hizo (5 búsquedas, 5 compradores con nombre). Tres misiones no prueban la
causa, pero la frase sospechosa era nuestra y empujaba en la dirección
equivocada: «un comprador con nombre y cargo sin enlace a la vista expone a quien
lo use» se puede leer como «no nombres a nadie». Se sustituyó por una
instrucción en positivo: el enlace es formato y no cambia qué se investiga ni
cuánto. Y se endureció la otra mitad: copiar cada URL exactamente como la
devolvió la búsqueda, sin reescribir una letra.

Y cuando todo cuadra, la revisión ahora también lo dice (`citas_respaldadas`, en
nivel informativo), para que «revisó y no encontró nada» deje de verse igual que
«no se ejecutó».

## El modelo investiga, la plataforma enlaza (02-10-2026, 16:10)

La frase quitada no era la causa. Con el bloque de enlaces en el contrato, el
agente se saltó la ronda de búsqueda de compradores en **0 de 3** misiones, también
sin la frase. Con el contrato anterior, en una misión de control con el mismo
mensaje y la cuenta limpia, la hizo: 16 búsquedas, 4 a ejecutivos concretos, 4
compradores con nombre. Y la de las 11:38 también: 2 de 2.

| Contrato | Misiones | Ronda de compradores | Fuentes enlazadas en el texto |
|---|---|---|---|
| Sin bloque de enlaces | 2 | 2 de 2 | 5 de 14 |
| Con bloque de enlaces | 3 | 0 de 3 | 10 de 11, 7 de 7, 11 de 12 |

Cinco misiones no prueban la causa, pero el cambio de comportamiento coincide
exactamente con el bloque y con nada más. Qué parte del bloque lo provoca no se
aisló; la sospecha es que exigir un enlace junto a cada nombre y cargo le hace
preferir no nombrar.

> **Corrección del mismo día, 20:00: la sospecha NO se confirmó.** Se repitió
> en local con el contrato de `c26306e` —comprobado en el `prompt_snapshot` de
> cada sesión— y las 2 misiones hicieron la ronda de compradores (4 y 3
> búsquedas a ejecutivos) y nombraron a 5 y a 2. Con el contrato actual, 3 de 3
> la hicieron. Lo de producción fue una coincidencia de tres misiones seguidas,
> no una causa. Qué la produjo no se sabe.
>
> La decisión de abajo se mantiene igual, pero por su propia razón: que el
> formato dependa de que el modelo lo obedezca es frágil —salían 10 de 11, no
> todas— y la plataforma lo hace siempre.

La salida no era elegir entre compradores y enlaces, sino **dejar de pedirle al
modelo un trabajo de la plataforma**. El contrato vuelve al de `eda301b`, y al
entregar el Pack la plataforma añade «Fuentes por cuenta» a partir de
`accounts[].sources`, que el agente llena igual. Se guarda con el mensaje.

Lo que se cede: los enlaces van agrupados por cuenta al final, no pegados a cada
frase. El agente sigue poniendo algunos en el texto por su cuenta.

## Recordar el paso de compradores (03-10-2026)

Con el sistema de `dc3aef0`, de 8 misiones medidas, solo 4 nombraron compradores:
2 buscaron y no pudieron verificar —la metodología del cliente es estricta— y 2
**ni siquiera hicieron esa ronda**, entre ellas la prueba en producción con la
cuenta de Omar. Un Pack sin nombres pierde contra ChatGPT, que dio dos.

Se añade al contrato de salida —nuestro, no el prompt del cliente— un
recordatorio antes del Pack: buscar al Buyer de cada cuenta GOLD o SILVER por su
cargo en esa empresa concreta, sin relajar ninguna regla de verificación.

Medido con `bin/mision-real.php -- regresion 5`:

| | Antes (8) | Después (5) |
|---|---|---|
| Hacen la ronda de compradores | 6 | 5 de 5 |
| Nombran al menos 1 | 4 | 5 de 5 |
| Nombran 2 o más | 4 | 4 de 5 |

Las demás puertas, 5 de 5; citas sin origen, 0. El agente busca más (11-21
búsquedas) y la misión sale a unos 0,44 USD. Cinco misiones no son una garantía;
si en producción vuelve a salir un Pack sin nombres, el siguiente paso está
diseñado: que la plataforma pida una pasada de compradores antes de guardar,
como ya hace cuando la suma de un informe no cuadra.
