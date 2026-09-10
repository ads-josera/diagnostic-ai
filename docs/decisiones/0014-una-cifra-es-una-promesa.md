# 0014 — Una cifra cuenta lo que promete su etiqueta

**10-09-2026**

## Lo que se encontró

Dos contadores decían contar una cosa y contaban otra. Ninguna prueba lo vio,
porque el número existía, era un entero y parecía razonable.

### El que dolía: el ledger se comía el cupo de búsquedas

El gateway **exime** correctamente a las herramientas del ledger de los topes de
investigación —consultar lo ya guardado no sale a internet y no cuesta dinero—.
Pero el contador que aplica esos topes, `usedInMission()`, **las sumaba igual**.

Cada consulta o anotación le quitaba en silencio una búsqueda a la misión. Con
50 de tope y 5 usos del ledger, el presupuesto real eran **45**. Lo mismo en el
tope por persona y periodo, vía `usedByUserSince()`.

**Por qué no se vio en meses:** el agente se quedaba sin margen antes de tiempo
y lo declaraba honestamente —marcaba lo pendiente como no verificado—, que es
exactamente lo que hace cuando el tope se agota de verdad. **Indistinguible
desde fuera.**

### El de la pantalla

`summarySince()` alimenta el bloque titulado **«Búsquedas externas»** y contaba
todas las herramientas. Decía 55 donde hubo 48. En la primera misión medida
habría dicho 32 donde hubo 27, y ese 27 acabó en un documento para el cliente.

## La causa, que es la que importa

Eximir y contar vivían en sitios distintos y **se desincronizaron sin que nadie
lo notara**. La lista de herramientas exentas estaba dentro de `isLedgerTool()`,
y los contadores no sabían de su existencia.

## El arreglo

Una sola lista, pública, en la clase que **posee la regla**:

```php
public const EXENTAS_DE_TOPE = [
  LedgerReadTool::NAME,
  LedgerWriteTool::NAME,
];
```

`isLedgerTool()` la usa para eximir. Los contadores la reciben como argumento
para no contar. Quien añada ahí una herramienta la exime y la descuenta **de una
sola vez**, que es el punto: no se pueden volver a separar.

Los cuatro métodos del repositorio aceptan ahora `array $excluding` y lo aplican
por un ayudante compartido, para que excluyan igual. Sin lista, devuelven el
total crudo: es lo que quiere quien mide `ledger_reads` para el §10.

## Lo que se comprobó, y cómo

La prueba nueva **se rompió a propósito** antes de darla por buena. Con el
arreglo deshecho:

```
testUsarElLedgerNoEncogeElCupoDeBusquedas
Failed asserting that 0 is identical to 2.
```

Cero búsquedas donde debían pasar dos: el fallo reproducido exactamente. Con el
arreglo puesto, verde.

La pantalla se miró **en el navegador**, no se dedujo: el bloque pasó de 55 a
**48**, que es lo que dice la base de datos filtrando por `buscar_web`.

## La prueba que fijaba el fallo como correcto

`testConsultarElLedgerNoGastaCupoPeroSeAnota` afirmaba
`assertSame(2, usedInMission(42)['calls'])` con el comentario «cuenta como
llamada concedida». **Documentaba el fallo como si fuera la intención.** Ahora
comprueba las dos mitades por separado: el total crudo las ve —eso hace medible
el `ledger_reads` del §10— y el contador tal como lo llama el gateway ve cero.

## Lo que se corrigió aguas abajo

- La decisión [0012](0012-topes-para-discovery.md): 7 afirmaciones → **3**. El 7
  era el total de la tabla, y cuatro filas eran de una prueba anterior.
- `bin/mision-real.php`: sumaba el ledger como búsquedas (32 en vez de 27).
- El documento del cliente, dos veces: el 7, y **dos filas bajo una columna
  titulada «Medido hoy» que en realidad eran derivadas**. Ahora cada cifra lleva
  marcado si está medida o calculada, y las dos cuentas van escritas al lado
  para que se puedan rehacer.

## La regla que queda

**Al escribir una consulta que produce una cifra, poner el filtro que su
etiqueta implica aunque parezca redundante, y comentar por qué está** —para que
el siguiente no lo quite por limpieza—. Y antes de meter un número en un
documento del cliente, rehacer la cuenta contra la base en vez de copiarla de lo
ya escrito.

## Lo que se auditó y estaba bien

No todo estaba mal, y conviene dejar dicho qué se miró:

- `AiUsageRepository`: `costForUser()` excluye ensayos y `costGlobal()` los
  incluye, cada uno con su motivo escrito. `periodSummary()` los incluye y los
  desglosa aparte. Los porcentajes de la pantalla cuadran:
  `full_price = input - cached` y `cached_pct = cached / input`.
- `EvidenceLedger::summarySince()`: filtra por fecha, y anotaciones,
  reutilizaciones y ámbitos dicen lo que cuentan.
- `recent()` en ambos repositorios: enseña filas completas, sin agregar nada.

340 pruebas, 1232 aserciones, phpcs limpio, humo 45/45.
