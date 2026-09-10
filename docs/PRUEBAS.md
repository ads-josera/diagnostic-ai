# Cómo se lanzan las pruebas

Tres redes distintas, y ninguna sustituye a las otras.

## 1. La suite automatizada

```
ddev exec 'cd web && \
  SIMPLETEST_DB="mysql://db:db@db/db" \
  BROWSERTEST_OUTPUT_DIRECTORY=/tmp \
  ../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/sales_leadership_diagnostic/tests --no-coverage'
```

**Las dos variables de entorno no son opcionales.** Sin `SIMPLETEST_DB` no hay
conexión a la base y **las 177 pruebas de tipo Kernel dan error, no fallo**. La
diferencia importa: parece que el módulo está roto cuando lo único que pasa es
que faltaba una variable. Costó un susto el 07-09-2026.

Lo que cuenta como pasar es `OK`. Salen dos avisos de obsolescencia de
`league/commonmark`, que es una librería de terceros y no depende de nosotros.

## 2. El estilo

```
ddev exec 'cd /var/www/html && vendor/bin/phpcs \
  --standard=Drupal,DrupalPractice --extensions=php,module,install \
  web/modules/custom/sales_leadership_diagnostic'
```

Sin salida es que está limpio.

## 3. La prueba de humo de la interfaz

```
SLD_ULI="$(ddev drush uli --no-browser | tail -1)" \
  node ~/.claude/skills/browser-automation/browser.mjs \
  https://diagnostic-ai.ddev.site/ --script bin/humo.mjs
```

**`SLD_ULI` tampoco es opcional.** Es un enlace de acceso de un solo uso para
el administrador. Sin él la prueba corre igual, pero se salta las pantallas de
solo-administrador y avisa: pasa de 36 comprobaciones a 22 y declara el fallo
en lugar de callarlo.

Existe porque hay una clase entera de fallos que las dos anteriores no ven —un
error fatal de PHP se sirve con código 200, un formulario carga y no guarda, un
color se inyecta y no pinta—. La cabecera de `bin/humo.mjs` los enumera, y
todos los enumerados los encontró el cliente usando el producto.

Solo pasa si devuelve la lista de fallos **vacía**.

## 5. Una misión de verdad contra el agente

Las tres anteriores no gastan un céntimo y no hablan con el modelo. Esta sí:
conduce una conversación real, con los documentos y el prompt del cliente, y la
mide.

```
ddev drush php:script bin/mision-real.php -- arrancar
ddev drush php:script bin/mision-real.php -- decir <sesión> "…"
ddev drush php:script bin/mision-real.php -- medir <sesión>
```

**Gasta dinero de verdad.** Cada turno es una llamada al proveedor y, si el
agente investiga, varias búsquedas. Conviene mirar antes el tope global en
`/admin/config/salesbumm/diagnostic/consumo`.

Se conversa **turno a turno y no de un tirón**, porque el agente pregunta antes
de investigar —el territorio, la empresa— y lo que se le conteste decide lo que
busca después. Un guion cerrado mediría otra cosa.

Es de donde salen los números que se le dan al cliente, y lo que hace falta para
el benchmark que pide su §9: los topes se calibran midiendo, no por decreto. Lo
medido hasta hoy está en `docs/decisiones/0012-topes-para-discovery.md`.

**No es una de las cuatro.** No se corre para dar algo por terminado: se corre
cuando hace falta un número o cuando se toca algo que solo se ve hablando con
el modelo de verdad.

### El benchmark

Para calibrar los topes hace falta más de una misión. `bin/benchmark.php` corre
varias seguidas con el **mismo protocolo y escenarios distintos** —sector,
territorio y tipo de oferta— y saca los percentiles:

```
ddev drush php:script bin/benchmark.php -- correr 5
ddev drush php:script bin/benchmark.php -- correr 5 5   # las cinco siguientes
ddev drush php:script bin/benchmark.php -- informe
```

Gasta unos **$0.40 USD por misión** y tarda unos **dos minutos y medio** cada
una. El tope global de la pantalla de consumo es la red: si se agota, las
llamadas dejan de ocurrir y el benchmark se detiene solo.

Dos cosas se apartan de producción a propósito y hay que tenerlas presentes al
leer los números: se **devuelve el entitlement** entre misiones —en producción
es uno por persona y semana— y **una sola persona las corre todas**, lo que
mantiene la caché caliente y no representa el primer turno de alguien que llega
de cero.

## 4. El recorrido de todos los caminos

Las anteriores comprueban lógica y una pantalla. Esta recorre **el producto
entero, con la cuenta de cada rol**:

```
SLD_GESTOR="$(ddev drush uli --uid=24 --no-browser | tail -1)" \
SLD_ALUMNO="$(ddev drush uli --uid=25 --no-browser | tail -1)" \
SLD_ADMIN="$(ddev drush uli --uid=1  --no-browser | tail -1)" \
  node ~/.claude/skills/browser-automation/browser.mjs \
  https://diagnostic-ai.ddev.site/ --script bin/caminos.mjs
```

Comprueba tres cosas por pantalla, y las tres nacieron de un fallo que llegó al
cliente:

- **El código que devuelve, en los dos sentidos.** Que cada rol entre donde debe
  y que **reciba 403 donde no debe**. Comprobar solo lo permitido deja pasar una
  pantalla que se abre a quien no toca.
- **Que tenga salida.** Ya han salido tres callejones distintos.
- **El contraste efectivo** de cada texto sobre el fondo que de verdad tiene
  detrás. Un botón salió con texto rojo sobre azul —1,06 a 1— y la captura
  parecía razonable.

Solo pasa si devuelve la lista de fallos **vacía**.

**Los uid son los de este entorno.** Y usa enlaces de un solo uso, no
contraseñas: no toca ninguna cuenta.

## El cron, en local

DDEV no ejecuta cron por su cuenta, y desde que los turnos que investigan corren
en segundo plano **el cron es quien los saca de la cola**. Sin él, un mensaje
deja la pantalla en «procesando» para siempre, porque el trabajo nunca empieza.

Va montado en `.ddev/config.cron.yaml` como demonio del contenedor, cada minuto,
igual que en producción. No hay que hacer nada: arranca con `ddev start`.

Para comprobar que corre:

```
ddev drush php:eval 'printf("hace %d s\n", \Drupal::time()->getRequestTime() - (int) \Drupal::state()->get("system.cron_last", 0));'
```

Si pasa de 120 segundos, no está corriendo. **Importa saberlo antes de dar por
roto un turno lento**: el 10-09-2026 se perdió un rato buscando un fallo en el
código que era el cron parado, y desde fuera «está pensando» y «está muerto» se
ven exactamente igual.

## Antes de dar algo por terminado

Las cuatro primeras, y además abrir en el navegador la pantalla que se tocó. Una
prueba verde dice que el código hace lo que se le pidió, no que la pantalla se
vea bien ni que el enlace que lleva a ella exista.
