# 0017 — La pantalla que carga con un turno ya corriendo

**10-09-2026**

## Cómo apareció

Lo encontró el cliente usando el producto. Mandó una captura: «Procesando», «Se
está generando tu resultado», y **nada más durante minutos**.

Había dos cosas, y solo la segunda es nuestra.

## La primera: el cron no corría

En local el cron no se ejecuta solo, y llevaba **2 horas y 44 minutos** parado.
Tres turnos esperando en la cola sin nadie que los sacara.

No es un fallo del módulo —en el servidor va cada minuto, y está en
`docs/DESPLIEGUE.md` como requisito desde que existe la búsqueda— pero conviene
anotarlo porque el síntoma que produce es idéntico al del fallo de verdad.

De paso se vio funcionar la recuperación de turnos atascados: al correr el cron,
devolvió a un estado utilizable dos sesiones que llevaban 52 minutos colgadas.

## La segunda: el sondeo solo arrancaba al enviar

**Este sí es nuestro, y venía de [0011](0011-turnos-en-segundo-plano.md).**

`esperarTurno()` vivía dentro del flujo de enviar un mensaje. Quien **cargaba**
la página con un turno ya corriendo —porque recargó, cerró la pestaña y volvió,
o entró desde su panel— recibía un aviso fijo en HTML y **nadie volvía a
preguntar nunca**.

El trabajo terminaba en el servidor y la pantalla se quedaba igual. Para
siempre.

La causa, en una línea:

```js
// Sesión cerrada: no hay compositor que inicializar.
if (!composer) {
  return;
}
```

En estado «procesando» la plantilla no pinta el compositor, así que `initChat`
se salía **antes** de llegar al sondeo. Correcto para una sesión cerrada, y
exactamente lo contrario de lo que hacía falta aquí.

Lo peor no es que fallara: es que **desde fuera «está pensando» y «está muerto»
se ven igual**. Nadie puede distinguirlos, así que nadie reporta el fallo hasta
que se cansa de esperar.

## El arreglo

`esperarTurno()` sale de `initChat` y pasa a vivir a nivel de módulo, porque
hacen falta **dos caminos y tienen que ser el mismo**: el de quien acaba de
enviar y el de quien llega con el turno en marcha. Recibe el error por callback
para que cada camino lo enseñe donde le corresponde.

Al cargar en «procesando»:

1. Se **oculta** el aviso fijo y se pone el vivo, que cuenta las búsquedas. El
   fijo no dice nada; el vivo dice que algo pasa.
2. Se sondea cada dos segundos.
3. Al terminar, **se recarga**. En este camino hay que devolver también el
   compositor, y el servidor sabe montarlo mejor que nosotros.

El servidor lo enciende con un ajuste nuevo, `processing`, en el chat del alumno
y en el estudio del gestor —el gestor recarga tanto como el alumno y el síntoma
sería el mismo—.

## Comprobado

En el navegador, el ciclo entero:

| | Con el turno corriendo | Al terminar |
|---|---|---|
| Aviso fijo | oculto | — |
| Aviso vivo | «Investigando…» | quitado |
| Compositor | ausente | **vuelve** |
| Peticiones de estado | 2 en 5 segundos | — |

La página **se recargó sola** al cambiar el estado por detrás, sin tocar nada.

Y una prueba fija el hilo que lo enciende: el ajuste `processing` que va del
servidor al navegador. **Rota a propósito**, se pone en rojo con
`Undefined array key "processing"`. Es el único hilo entre el servidor, que sabe
que hay un turno corriendo, y el navegador, que es quien tiene que preguntar.

## Lo que deja escrito

Cuando el trabajo se saca de la petición, **no basta con avisar de que empezó**.
Hay que poder contestar «¿ya?» a quien llegue después, y quien llega después es
todo el mundo: la gente recarga, cierra pestañas y vuelve al día siguiente.

344 pruebas, 1251 aserciones, phpcs limpio, humo 45/45.
