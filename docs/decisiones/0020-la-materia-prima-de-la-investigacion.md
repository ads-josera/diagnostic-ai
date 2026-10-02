# 0020 · La materia prima de la investigación

**Fecha:** 02-10-2026
**Estado:** aplicada

## El día que lo provocó

El cliente comparó el Weekly GOLD Pack de nuestro agente con el que le dio
ChatGPT usando **el mismo prompt y los mismos documentos**. El de ChatGPT cribó
diez cuentas con trece fuentes enlazadas y dos compradores verificados con
nombre y cargo. El nuestro, en la conversación que más dolió, entregó **una**.

No fue el modelo, ni el prompt, ni una caída. Fueron tres causas distintas, y
esta decisión cubre la tercera. Las otras dos están en sus propios commits: el
`research_budget` congelado en `LIMITED` y los enlaces de las fuentes que el
renderizador destruía.

## Lo medido en producción, antes de tocar nada

De las tres misiones de ese día, contando solo `buscar_web`:

| | Consultas | Resultados | Caracteres | Por consulta | Por fuente |
|---|---|---|---|---|---|
| Sesión 26 | 16 | 69 | 44 114 | 2 757 | 639 |
| Sesión 27 | 24 | 96 | 93 281 | 3 887 | 972 |
| Sesión 28 | 22 | 94 | 89 747 | 4 079 | 955 |
| **Total** | **62** | **259** | **227 142** | **3 664** | **877** |

**Cero denegaciones. Ningún tope tocado**: la misión que más buscó hizo 24 de
40 llamadas, y la que más texto trajo sumó 93 281 de 160 000.

Unos 877 caracteres por fuente es el tamaño de un extracto de buscador, no el
de una página. Con eso no se lee un comunicado ni una memoria anual. Del otro
lado, ChatGPT citó **trece páginas concretas**.

## Qué se cambió, y qué no

| Ajuste | Antes | Ahora | Por qué |
|---|---|---|---|
| `search.max_results` | 5 | **10** | **Gratis**: los créditos de Tavily no dependen del número de resultados |
| `search.depth` | `basic` | **`advanced`** | **+60 % de texto, medido**; cuesta 1 crédito más por búsqueda |
| `tools.max_retrieved_chars_per_mission` | 160 000 | **400 000** | Si no, lo anterior choca contra el tope que antes sobraba |

### Lo que NO se cambió, y por qué

**No se le dice nada al agente sobre dónde buscar personas.** La hipótesis era
que las redes profesionales no estaban indexadas —cuatro búsquedas a LinkedIn
volvieron vacías en producción— y los sondeos la **desmintieron**: una consulta
acotada a LinkedIn devolvió cinco resultados con 850 caracteres de media, y una
consulta general sobre el mismo cargo devolvió 127. Dos sondeos contradictorios
no justifican escribirle una recomendación al agente. Lo que varía es cómo se
formula la consulta, y eso no se sabe con dos muestras.

Queda anotado como lo que es: **no comprobado**.

## Cómo se midió `advanced`

Los dos primeros sondeos se contradecían —en una consulta `advanced` trajo la
mitad de texto que `basic` y en otra cinco veces más—, así que la recomendación
se retiró y se repitió con seis consultas reales de prospección sobre Ecuador:

| | Resultados | Caracteres | Por resultado |
|---|---|---|---|
| `basic` | 59 | 25 236 | 427 |
| `advanced` | 60 | **40 485** | **674** |

**+60 % de texto.** Doce búsquedas de sondeo costaron unos 0,14 USD, que es un
precio razonable por no decidir un ajuste de pago con dos muestras.

## Lo que esto cuesta

Una misión de unas veinte búsquedas:

- Tavily: de 0,16 a **0,32 USD** (20 búsquedas a 2 créditos, 0,008 cada uno).
- El texto adicional entra al modelo como tokens de entrada, y la mayoría se
  cachea al 10 % en las llamadas siguientes del mismo turno.
- Estimado total de una misión: de ~0,25 a **~0,55 USD**.

**Consecuencia que hay que decidir aparte:** un alumno activo pasa de 1,50-3,50
a unos 2,50-5,00 USD al mes, y eso roza el tope por alumno, que está en 5. Si
se mantiene este ajuste hay que subirlo a 8 o 10. No es un problema de dinero
—son unos 160 pesos por alumno en el peor caso— pero es una decisión del
negocio, no del código.

## Lo que queda fuera, y es el siguiente paso si esto no basta

**Leer páginas enteras.** Es lo que de verdad hace ChatGPT, y en Tavily es
barato: su endpoint de extracción cobra **1 crédito por cada cinco URLs**, o
sea 0,0016 USD por página. Lo caro no es Tavily, son los tokens.

No se hace ahora a propósito: es una herramienta nueva —con su descripción, su
gobierno de topes y sus pruebas— y conviene decidirla **con el resultado de
este cambio delante**, no con un pronóstico. Primero se mide si con 10
resultados y `advanced` el pack ya se sostiene.

## Cómo comprobar si funcionó

Repetir **el mismo caso**: «Deloitte Ecuador» y «Ecuador», en conversación
nueva —la que falló arrastraba catorce búsquedas sobre otro tema— y comparar
contra el correo del cliente. Lo que hay que mirar, por orden:

1. Cuántas cuentas criba. La metodología exige diez.
2. Cuántas señales llevan fuente, y si la fuente es una página concreta.
3. Si aparece **algún comprador con nombre y cargo**, que es donde más clara
   fue la diferencia.
