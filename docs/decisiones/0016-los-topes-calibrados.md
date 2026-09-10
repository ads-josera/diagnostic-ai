# 0016 — Los topes, ya con datos

**10-09-2026**

Su §9 pide calibrar los topes por benchmark y no por decreto. Hasta hoy salían
de **una** misión medida, que dice dónde estaba el techo aquel día y no dónde
está.

## Lo medido

18 misiones Discovery reales, con **escenarios distintos** —telemetría de
flotillas, automatización de almacén, agua industrial, ciberseguridad OT,
metrología, riesgo crediticio…— cada una con su territorio y sus sectores, y el
**mismo protocolo** en todas. La herramienta es `bin/benchmark.php`.

| | mínimo | mediana | p90 | máximo |
|---|---|---|---|---|
| Búsquedas | 8 | 16 | **22** | 25 |
| Caracteres | 21 020 | 56 513 | **78 398** | 89 487 |
| USD | 0.2018 | 0.2650 | 0.4327 | 0.4570 |

**Las búsquedas varían tres veces entre misiones; las cuentas entregadas no.**
Casi todas devolvieron diez, alguna once o doce, ninguna menos. El agente ajusta
el esfuerzo al territorio en vez de gastar hasta el límite, que es lo contrario
de lo que se teme al poner un tope generoso.

## Los topes

| Ajuste | Antes | Ahora | Por qué |
|---|---|---|---|
| `max_calls_per_mission` | 50 | **40** | 1,8 × p90; 1,6 × la misión más cara |
| `max_retrieved_chars_per_mission` | 200 000 | **160 000** | 2 × p90 |
| `max_calls_per_user_period` | 220 | **200** | 5 misiones a la más cara, más rechecks |
| `search.max_tool_rounds` | 20 | **15** | 2 × las 7 vueltas de la que más dio |

**Sobre el p90 con holgura, no sobre la media.** La media deja fuera a la mitad
de las misiones.

Y **se yerra por arriba a propósito**, porque los dos errores no cuestan lo
mismo:

- Un tope **alto de más** deja que una misión rara gaste un poco más. El gasto
  ya lo acotan el entitlement —una misión por persona y semana— y el tope en
  dólares.
- Un tope **bajo de más** hace que el agente investigue a medias. Y eso **no se
  ve**: declara honestamente lo que no pudo verificar, exactamente igual que
  cuando termina bien.

Es la misma asimetría de [0014](0014-una-cifra-es-una-promesa.md): lo que no
falla de forma visible es lo que hay que proteger.

## Tres cosas que encontró el benchmark

### 1. El tope por persona y mes funciona. Lo vimos dispararse

En la misión quince el benchmark chocó con él: **220 búsquedas exactas**, que
era el tope. Las cinco siguientes salieron vacías, con 28 denegaciones
registradas y su motivo.

No es una prueba unitaria: es el mecanismo actuando bajo carga real, por primera
vez. **El que estaba mal era el método**, no el tope: veinte misiones en una
sola persona son cinco veces lo que el sistema concede a nadie. Corregido —una
persona por misión, que además es lo que pasa en producción.

### 2. El GOLD Pack había dejado la salida al borde del techo

**Dos misiones se cortaron a la mitad** con el error «el proveedor agotó el
presupuesto de tokens». El tope estaba en 8 000 y las dos llegaron exactamente
a 8 000. Peor: las que sí cerraron ocupaban **entre 7 100 y 7 900**. Menos de
900 tokens de margen no es margen, es suerte.

La causa es de ayer: el Pack se escribe **dos veces** —en Markdown para la
persona, que es lo único que lee, y en campos para que el sistema pueda
comprobarlo—. Es inherente al diseño y no se arregla componiendo la prosa
nosotros: eso sería escribir su entregable.

`max_completion_tokens` sube a **16 000**. Subirlo no cuesta nada por sí solo:
solo se paga lo que el modelo escribe, y lo que escribe lo decide el contenido.
Repetida una de las cortadas con el tope nuevo, cerró con sus diez cuentas.

**Sin el benchmark esto habría llegado a un alumno**, y como respuesta cortada a
la mitad.

### 3. El agente se niega a arrancar sin lo que su metodología pide

Cinco misiones no cerraron porque el agente pidió antes un dato: el país cuando
el territorio decía solo «nacional», o el nombre de la empresa. **Eran huecos
de mis escenarios, no fallos suyos** —y es la conducta correcta, la misma que ya
declaró él mismo: «no asumiré que México nacional es el territorio solo porque
la empresa sea mexicana»—.

Se apartan del cálculo y el informe **dice cuántas y por qué**, en vez de
promediarlas: contarlas como misiones de cero búsquedas hundiría los percentiles
y haría parecer que una misión necesita menos de lo que necesita.

## Lo que este benchmark NO dice

**El coste está por debajo del real.** Las misiones corren seguidas y comparten
la caché del prompt. Un alumno hace una por semana, con la caché fría, y paga
además el primer turno completo. La cifra buena para hablar con el cliente sigue
siendo la de una misión medida entera y en frío: **$0.44–0.58 USD**.

Coste del benchmark: **$5.12 USD** en 29 misiones corridas.

343 pruebas, phpcs limpio, humo 45/45.
