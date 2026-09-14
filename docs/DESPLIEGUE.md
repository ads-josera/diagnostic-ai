# Manual de despliegue

## Sales Leadership Diagnostic AI

> **Para el despliegue en labai.salesbumm.com, empezar por
> `docs/ARRANQUE-PRODUCCION.md`**: dice en qué orden y con qué datos. Este
> manual es el detalle de cada paso. Revisado entero el 14-09-2026, después del
> ensayo de instalación limpia, para que los dos digan lo mismo.

Procedimiento para levantar el sitio en un entorno nuevo (staging o producción).

**Este procedimiento se ha ensayado**, no solo redactado: se ejecutó una
instalación limpia desde el repositorio en el entorno local, se verificó el
resultado y se restauró el estado anterior. Los comandos de abajo son los que
funcionaron.

---

## Principio: la base de datos nunca viaja

El sitio **no** se despliega copiando la base de datos de desarrollo. Se
instala desde cero a partir del repositorio y la configuración exportada.

Copiar la base de datos arrastraría usuarios de prueba, sesiones de diagnóstico
ficticias, logs y un `uuid` de sitio que no corresponde. Instalar desde
configuración produce un entorno limpio y reproducible, y obliga a que todo lo
que importa esté versionado.

---

## 1. Requisitos del servidor

| Requisito | Versión |
|---|---|
| PHP | **8.4** (la de desarrollo); mínimo 8.3. La de la línea de órdenes, la misma que la de la web: el cron corre por línea de órdenes |
| Composer | **2.10.3 o superior** (CVE-2026-84361) |
| Base de datos | MariaDB 10.6+ / MySQL 8.0+ / PostgreSQL 12+ |
| Composer | 2.x |
| HTTPS | **Obligatorio.** El token de acceso viaja en la URL |
| Cron | **Cada minuto** si se usa la búsqueda externa; cada 15 en otro caso |
| Tiempo de espera de PHP | **300 s** si se usa la búsqueda externa. Ver más abajo |

### Los tiempos de espera de PHP, si se va a usar la búsqueda externa

**Solo aplica con la búsqueda encendida.** Sin ella un turno tarda entre 5 y 25
segundos y ningún límite de fábrica estorba.

Con búsqueda no. Un turno deja de ser una llamada al proveedor y pasa a ser
varias: el modelo pide una búsqueda, recibe el resultado y con él pide otra.
**Medido el 08-09-2026 con una cuenta concreta: 3 llamadas, 76 segundos en
total, y una sola de ellas 44.6.** Una misión que criba diez cuentas tarda
bastante más.

**Desde el 08-09-2026, esos turnos ya no corren en la petición web**: si el
agente tiene capacidad de investigar, el turno se encola y lo genera el cron
por línea de órdenes, donde PHP no tiene límite de tiempo. Los límites de abajo
siguen importando —el turno síncrono existe y el estudio del prompt también—
pero dejan de ser la diferencia entre funcionar y no funcionar.

Con **WHM (acceso de raíz)** los cuatro límites se pueden poner; con cPanel sin
acceso de raíz, hay que pedírselos al proveedor del alojamiento. Hay que revisarlos **en este orden**, porque el primero es el
que mata turnos sin que nadie entienda por qué:

| # | Qué | Dónde | Valor |
|---|---|---|---|
| 1 | `request_terminate_timeout` de PHP-FPM | WHM → MultiPHP Manager → ajustes de PHP-FPM del dominio | **300** |
| 2 | `max_execution_time` | WHM → MultiPHP INI Editor | **300** |
| 3 | `ProxyTimeout` y `Timeout` de Apache | WHM → Apache Configuration → Include Editor | **300** |
| 4 | Límites de CloudLinux, si está instalado | WHM → CloudLinux LVE Manager | revisar `lveps` bajo carga |

**El número 1 es el que sorprende.** En cPanel, PHP-FPM suele venir con
`request_terminate_timeout` en **75 segundos**, y ese valor **manda sobre
`max_execution_time`**: por mucho que se suba el segundo, el proceso muere a los
75. Nuestro turno medido tardó 76. Se comprueba en el archivo del pool del
dominio, bajo `/opt/cpanel/ea-phpXX/root/etc/php-fpm.d/`, y se cambia desde
MultiPHP Manager para que sobreviva a las actualizaciones de cPanel.

**Por qué 300 y no más.** El techo teórico del módulo es
`search.max_tool_rounds × openai.timeout` — hoy 4 × 180 = 720 segundos—, pero
ese caso exige que las cuatro vueltas se agoten al máximo, y lo medido son 76.
Con 300 hay cuatro veces el caso real. **Si se sube el número de vueltas, hay
que volver aquí**: el techo sube con él.

**Después de tocarlos, compruébelo** con un turno real desde el Estudio del
prompt y con la búsqueda encendida. Un límite mal puesto no da error de
configuración: da un turno que muere a mitad, y el alumno solo ve que no pasó
nada.

### La IP del servidor tiene que estar autorizada en el WordPress del cliente

**Requisito previo al despliegue.** El módulo consulta la autorización de cada
alumno contra `salesbumm.com`. Si el alojamiento del cliente marca al servidor
como abusivo y le corta, **se quedan fuera todos los alumnos a la vez**.

No es hipotético: ocurrió el 28-08-2026 con la IP de la oficina. Hostinger la
bloqueó por el volumen de consultas durante el desarrollo, y el diagnóstico
dejó de conceder acceso.

Lo que hay que hacer, en el hPanel de Hostinger del cliente:

1. **Averiguar la IP de SALIDA del servidor**, desde el propio servidor. Con NAT
   o balanceadores la de salida puede no ser la de entrada, y entonces se
   estaría autorizando una que nunca aparece en las peticiones:

       curl https://api.ipify.org

2. Autorizar exactamente esa IP en el panel del cliente.

> Comprobado el 14-09-2026 desde el servidor de labai.salesbumm.com: la IP de
> salida es `72.167.47.47`, la misma que se autorizó el 28-08-2026. Es el mismo
> servidor; basta con confirmar que sigue en la lista blanca.


3. Confirmar que esa pantalla del panel **autoriza** y no **bloquea**. En
   algunas versiones sirve para lo contrario, y añadir ahí la IP la dejaría
   fuera. En el panel del cliente a 28-08-2026 es una **lista blanca**, que es
   lo correcto; si el panel cambia, se comprueba cargando el sitio desde esa
   red sin VPN.

Aun con todo autorizado, el módulo aguanta una caída: mantiene el acceso de
quien fue verificado dentro del periodo de gracia y, desde el 28-08-2026, deja
de insistir mientras el servicio no responda en lugar de reintentar en cada
página.

### El cron no es opcional

La memoria del alumno —lo que el sistema recuerda de su negocio para no
hacérselo repetir— se extrae **en cola**, no en el mismo turno en que termina
el diagnóstico. Esa decisión es a propósito: encadenarle al alumno una segunda
llamada al modelo antes de enseñarle su informe le añadiría una espera por algo
que no va a ver, y un fallo del proveedor en ese momento se le presentaría como
si su diagnóstico hubiera fallado.

El precio es que **sin cron la memoria no se escribe nunca**. Nada falla de
forma visible: los diagnósticos siguen saliendo, los resultados se guardan, y
el alumno simplemente vuelve a contarlo todo cada vez, sin que nadie sepa por
qué. Si el cron de Drupal no está programado en el servidor, prográmelo.

    */15 * * * * cd /home/labai/public_html && /opt/cpanel/ea-php84/root/usr/bin/php vendor/bin/drush cron

#### Con la búsqueda encendida, cada MINUTO

Desde el 08-09-2026 el cron hace algo mucho más urgente que escribir memoria:
**genera los turnos que investigan**. Un turno con capacidad de búsqueda se
encola y espera al siguiente cron para empezar.

Con el cron cada quince minutos, un alumno escribiría y **esperaría hasta un
cuarto de hora a que su turno arrancara**. Con el cron cada minuto, arranca casi
en el acto.

    * * * * * cd /home/labai/public_html && /opt/cpanel/ea-php84/root/usr/bin/php vendor/bin/drush cron

(En este servidor el PHP de la línea de órdenes es 8.1: por eso el cron nombra
el binario de PHP 8.4 de cPanel.)

Esto es viable aquí porque el servidor es propio. En alojamiento compartido no
suele permitirse, y sería el argumento para no encender la búsqueda ahí.

El mismo cron hace otra cosa que importa: **desatasca conversaciones**. Si el
proceso muere a mitad de un turno, la conversación se queda en «procesando»,
un estado que no admite mensajes. Sin cron, esa conversación queda inutilizable
para siempre; con él, se recupera sola a los 45 minutos —configurable— y la
persona puede volver a escribir.

También hay directorio privado que crear, para los documentos de conocimiento:

    mkdir -p web/sites/default/files-private
    chmod 775 web/sites/default/files-private

Si falta, subir un documento falla con un error poco explícito.

### El registro de Drupal tiene que durar días, no minutos

Drupal guarda por defecto **las últimas 1 000 entradas** de su registro, y con
el cron cada minuto el propio cron escribe unas once por pasada. Eso deja el
registro en **hora y media**: un error de un alumno se borra antes de que nadie
lo mire.

Pasó en local el 11-09-2026. Un alumno vio «No hemos podido procesar tu
solicitud» a las 22:24; a las 22:32 la entrada que explicaba por qué ya no
existía, y hubo que reproducir el fallo a ciegas.

Súbalo a 100 000 (unos seis días con el cron cada minuto):

```bash
drush config:set dblog.settings row_limit 100000 -y
```

Si el servidor ya envía el registro a syslog o a otro destino con retención
propia, esto sobra: lo que importa es que un error se pueda leer al día
siguiente.

### Conservación de las conversaciones

En **Configuración → Salesbumm → Diagnostic AI → Reglas de uso** hay un plazo
en días. Pasado ese tiempo se borra lo que el alumno **escribió** en los
diagnósticos ya terminados.

Lo que NO se toca, y conviene saberlo antes de fijar el número:

- **Los diagnósticos**, que son el entregable del alumno. Desde que guardan la
  puntuación por dimensión siguen siendo legibles sin la conversación detrás.
- **La copia del prompt** de cada sesión, que es lo que permite saber años
  después con qué instrucciones se produjo cada diagnóstico (§57).
- **Las conversaciones a medias**, lleven lo que lleven paradas: el alumno
  puede volver a ellas.

De fábrica viene en **cero**, que conserva todo indefinidamente. Es a
propósito: actualizar el módulo no debe empezar a borrar datos de nadie.

Esto es una decisión de privacidad, no de espacio. Medido el 26-08-2026 con
diez sesiones reales, el módulo entero ocupaba menos de 700 KB.

## 2. Código y dependencias

En el servidor de producción (cPanel, cuenta `labai`) el repositorio vive en
**`/home/labai/public_html`** y su carpeta `web` es la raíz del dominio. Como
cPanel ya había dejado archivos en esa carpeta, se sacó con `git init` +
`remote add` + `fetch` + `checkout` en lugar de `git clone`, y esos archivos se
excluyen en `.git/info/exclude`. El paso a paso está en
`docs/ARRANQUE-PRODUCCION.md`.

El PHP de la línea de órdenes es 8.1 y Drupal 11 necesita 8.4: al abrir cada
sesión de SSH se pone el de cPanel por delante.

```bash
export PATH=/opt/cpanel/ea-php84/root/usr/bin:$PATH
cd /home/labai/public_html

composer --version    # 2.10.3 o superior; si no: composer self-update
# --no-dev excluye las herramientas de desarrollo. Drush NO: desde el
# 14-09-2026 es dependencia de producción, porque el cron, los cargadores y la
# limpieza de datos de prueba lo necesitan en el servidor.
composer install --no-dev --optimize-autoloader
vendor/bin/drush --version
```

**PHP 8.4 del dominio lo decide PHP-FPM.** El dominio corre con PHP-FPM
(MultiPHP Manager, versión 8.4), así que la versión la fija la configuración
del servidor y no el `.htaccess`: `web/.htaccess` es el de Drupal, que
`composer install` regenera sin que eso afecte a PHP. Con FPM encendido,
cPanel quita su bloque `AddHandler` del `.htaccess`; el repositorio no lo
vuelve a poner (se probó el 14-09-2026 y se retiró, porque peleaba con el
panel). Si alguna vez se apagara PHP-FPM, la versión volvería a depender de
ese bloque y `composer install` lo borraría: no apagarlo.

Tras **cambiar la raíz del documento** en cPanel hay que volver a aplicar
PHP-FPM (apagar y encender en MultiPHP Manager): si no, el pool sigue en la
raíz vieja y todo `.php` responde 404 «No input file specified» (ver la sonda
en `docs/ARRANQUE-PRODUCCION.md`, paso 2).

## 3. Secretos y ajustes del entorno

**Antes** de instalar. En cPanel van en **`web/sites/default/settings.local.php`**
(git lo ignora y `settings.php` lo carga al final), no en variables de
entorno: las del servidor web no llegan a la línea de órdenes, y el cron —que
genera los turnos que investigan y escribe la memoria— corre por línea de
órdenes. La plantilla completa, con la base de datos, está en
`docs/ARRANQUE-PRODUCCION.md` (paso 3). Lo esencial:

```php
$settings['hash_salt'] = '...';               // openssl rand -hex 32
$settings['trusted_host_patterns'] = ['^labai\.salesbumm\.com$'];
$settings['sld_jwt_shared_secret'] = '...';   // idéntico al de wp-config.php
$settings['sld_wp_hmac_secret'] = '...';      // idéntico al de wp-config.php
$settings['sld_openai_api_key'] = '...';
// OPCIONAL. Sin ella el agente no busca en internet: lo declara y sigue con lo
// que no dependa de ello, que es un estado válido de su metodología y no una
// instalación a medias. No aparece como pendiente en el informe de estado.
$settings['sld_search_api_key'] = '...';
```

Los valores los escribe José Raúl directamente en el servidor: nunca pasan por
un chat ni por el repositorio. Donde el servidor sí entregue variables de
entorno a PHP y a la línea de órdenes, `settings.php` también las lee (`SLD_*`,
`DRUPAL_HASH_SALT`, `DRUPAL_TRUSTED_HOST`; ver `.env.example`).

### Reglas que no son opcionales

1. **Cada secreto, mínimo 32 caracteres.** `firebase/php-jwt` rechaza claves
   HMAC más cortas y lo hace con un error genérico durante el login, no con un
   mensaje útil. Genera con `openssl rand -hex 32`.
2. **Los dos secretos del puente, distintos entre sí.**
3. **Secretos nuevos para cada entorno** (§49). Los de producción no se
   comparten con desarrollo ni con staging, y los que se usaron durante el
   desarrollo deben rotarse antes de producción. Esto vale para los dos
   secretos del puente. Las llaves de OpenAI y de búsqueda son, por decisión de
   José Raúl (14-09-2026), las suyas mientras el cliente prueba; se cambian por
   las del cliente cuando él lo indique, editando dos líneas del
   `settings.local.php` y limpiando la caché.
4. **`sld_use_mock_engine` NO debe existir** en `settings.php`. Si está, los
   diagnósticos se generan con respuestas de prueba. El informe de estado lo
   marca como error, pero conviene comprobarlo antes.

## 4. Base de datos y instalación

```bash
# Crea la base de datos vacía y configura $databases en settings.php.

drush site:install --existing-config \
  --account-name=admin \
  --account-pass="$(openssl rand -base64 18)" \
  -y
```

`--existing-config` instala el sitio a partir de `config/sync`, adoptando su
`uuid`. Es lo que hace que el entorno nuevo sea idéntico al versionado.

**La cuenta de administración se crea aquí**, en este comando. Es la respuesta a
«¿cómo entro al sitio recién desplegado?».

> Anota la contraseña que imprime el comando: no queda registrada en ningún
> sitio. Cámbiala en el primer inicio de sesión si prefieres una propia.

### Sobre el rol de administrador

El instalador asigna automáticamente el rol `administrator` a la cuenta que
crea, porque ese rol lleva `is_admin: true` en la configuración exportada. No
hace falta asignarlo a mano.

Verificado en el ensayo: la cuenta queda con `authenticated, administrator`, de
modo que sus permisos no dependen únicamente del privilegio implícito del
usuario 1.

## 5. Verificación posterior

```bash
drush updatedb:status     # esperado: "No database updates required."
drush config:status       # esperado: "No differences between DB and sync directory."
drush core:requirements | grep -i diagnostic
```

Un despliegue recién hecho deja el informe así:

```
[OK]  Diagnostic AI: agentes               => 2 agentes disponibles
[OK]  Diagnostic AI: documentos            => Protegidos
[OK]  Diagnostic AI: plugin de WordPress   => Sin datos todavía
[OK]  Diagnostic AI: secretos              => Configurados
[OK]  Diagnostic AI: WordPress / LearnDash => Configurado
```

Es lo que dio el ensayo de instalación limpia del 14-09-2026. Los dos agentes
llegan con la configuración exportada, pero **sin documentos, icono ni
logotipos** hasta pasar los cargadores (§6). Un agente cuenta como disponible
cuando está **activo, tiene curso y tiene prompt**: si falta alguna de las
tres, no aparece y los alumnos no pueden empezar.

La línea del plugin se completa en cuanto se consulta la autorización de
alguien por primera vez.

**No debe aparecer ninguna línea sobre el motor simulado**: si aparece, retira
`sld_use_mock_engine` de `settings.php` y limpia caché.

## 6. Configuración desde la interfaz

En **Configuración → Salesbumm → Sales Leadership Diagnostic AI**:

| Sección | Qué revisar |
|---|---|
| WordPress | URL base y curso vienen del repositorio; confirma que son los del entorno |
| Proveedor de IA | Cargar el catálogo de modelos y elegir uno |
| Seguridad | Revisar límites y periodo de gracia |
| Reglas de uso | Política de repetición y plazo de conservación (§Conservación) |
| Marca y Portada | Colores, logotipos y textos del cliente |

### Los agentes

Desde el 26-08-2026 el diagnóstico lo conducen **agentes**, no un prompt único.
Los dos de Salesbumm **llegan con la configuración exportada**: no hay que
crearlos. Lo que la configuración no puede traer son sus archivos —documentos,
icono— ni los logotipos de la portada: guarda el NÚMERO de cada archivo, y el
que viene es el del entorno de desarrollo. Se ponen con los cargadores (abajo),
y el módulo `config_ignore` impide que un despliegue posterior pise esos
números.

Para un agente NUEVO: **Configuración → Salesbumm → Sales Leadership
Diagnostic AI → Agentes**, «Añadir agente». Un agente necesita tres cosas para
estar disponible:

1. Estar **activo**.
2. Tener el **curso de LearnDash** que lo concede. Es lo que decide qué alumno
   lo ve: quien compró ese curso, y nadie más.
3. Tener **prompt del sistema**. Lo aporta el cliente y se usa tal cual (§15).

Si falta cualquiera de las tres no aparece en el informe de estado ni se le
ofrece a ningún alumno.

En su ficha van además el contrato de salida, la pantalla de bienvenida y el
icono. Los **documentos de conocimiento** se cargan aparte, en su propia
pantalla, y necesitan el directorio privado del §1.

Para probar un agente antes de exponerlo hay el **Estudio del prompt**:
conversa con el motor y el prompt reales, marcando la sesión como ensayo, de
modo que no gasta el cupo de nadie ni ensucia el listado del gestor.

#### No copie el prompt a mano

Para los dos agentes de Salesbumm hay un cargador que lee los archivos del
repositorio y los deja en su sitio:

```
drush php:script bin/cargar-agentes.php
drush php:script bin/cargar-marca.php
```

El segundo pone el fondo y los dos logotipos de la portada desde `docs/marca/`:
sin él, **ninguna pantalla del alumno tiene logotipo**. El primero pone el
prompt de cada agente desde `docs/knowledge-cliente/`, su **icono** desde
`docs/marca/`, su **contrato de salida** desde `docs/contratos-de-salida/` y
sus documentos de conocimiento. En un entorno nuevo dice «carga inicial» y no
sube la versión del agente: es la misma metodología, solo que aún no estaba.
Los documentos son estos:
los quince del de prospección, en el orden del manifiesto del cliente, y los
nueve del de diagnóstico, desde `docs/Knowledge documents/`, en el orden de
autoridad de su Orchestrator. El «Documento Maestro Interno» del Framework NO
se carga: es de uso interno de Salesbumm.

**Los documentos van a la carpeta PRIVADA.** Son la metodología propietaria
del cliente. Hasta el 12-09-2026 el cargador los escribía en la pública
(`sites/default/files/knowledge/`), desde donde se descargaban sin iniciar
sesión con solo acertar la URL. Si en este servidor se ejecutó el cargador
antes de esa fecha, `drush updb` los mueve a la privada
(`sales_leadership_diagnostic_update_10022`) y borra las copias públicas.
Compruébelo después en el informe de estado: la línea «Diagnostic AI:
documentos de conocimiento» debe decir **Protegidos**. Si dice «a la vista», no
abra el diagnóstico a nadie hasta resolverlo.

El contrato de salida es la parte NUESTRA del prompt: le dice al modelo cómo
entregar la respuesta a la plataforma —JSON, campos del resultado, qué hacer si
la persona cierra antes del informe—. Hasta el 12-09-2026 solo existía en la
base de datos local, así que una instalación limpia obligaba a pegarlo a mano.
Si se cambia, se cambia en el archivo y se vuelve a pasar el cargador, que sube
la versión del agente.

Úselo en vez del textarea. Los prompts rondan los 8 000 caracteres y llevan
guiones largos, flechas y comillas tipográficas; una copia manual los aplana
sin que se note, y eso ya pasó una vez —«0–39» quedó en «0-39»—. El cargador
**se niega** a subir un prompt que haya perdido esos caracteres, y no sube la
versión del agente si el prompt no cambió.

### La cuenta del gestor

Los roles llegan con la configuración exportada, pero **las cuentas no**. Quien
vaya a dar soporte necesita una:

```bash
drush user:create gestor.sam --mail="..." \
  --password="$(openssl rand -base64 18)"
drush user:role:add gestor_sam gestor.sam
```

Con ese rol entra a **Contenido → Resultados de diagnóstico**, que es su sitio:
el listado de diagnósticos, los agentes, el estudio del prompt y los
documentos. NO necesita el rol de administrador, y no conviene dárselo: el
permiso de gestor no incluye ver los secretos ni la integración.

## 7. Conectar WordPress

En **salesbumm.com → Ajustes → Diagnostic AI**, rellenar la
**URL de acceso en Drupal** con la ruta de este entorno:

```
https://labai.salesbumm.com/sales-diagnostic/sso
```

> ⚠ Ese campo apunta a los alumnos reales. Nunca debe contener una URL de
> desarrollo: un alumno que pulse el botón acabaría en un sitio inexistente.

## 8. Cuentas de prueba en el WordPress del cliente

Acordado con el cliente el 29-08-2026: **dos usuarios de prueba en su
WordPress**, uno por agente. Permiten enseñar el producto y verificar la
cadena entera sin inventar ningún atajo en el módulo.

| | Curso en LearnDash | Verá |
|---|---|---|
| Usuario A | el del primer agente | Solo ese agente |
| Usuario B | el del segundo agente | Solo el otro |
| Usuario C | la **membresía** (curso de suscripción, plugin 1.3.0, §10) | Los dos agentes, sin caducidad |

Además, **`alumno.demo`** se conserva siempre en producción para pruebas
(decisión de José Raúl, 14-09-2026), enlazado a un usuario de prueba de
WordPress con curso: ver `docs/ARRANQUE-PRODUCCION.md`, paso 6.

Tres cosas que evitan sustos:

- **Un curso por usuario, nunca los dos.** Es lo que hace útil la prueba:
  demuestra que quien compró uno ve SOLO su agente. Con los dos cursos se ven
  los dos agentes y no se habrá probado nada.
- **Correos distintos, y que no coincidan con una cuenta de Drupal existente.**
  Si chocan, el módulo se niega a vincular y pide resolución manual: no une
  cuentas por correo, a propósito (§7.3).
- **La primera consulta arranca su reloj de acceso.** Si se fija una caducidad,
  las cuentas de prueba también caducan.

Se descartó crear cuentas de demostración que se saltaran la comprobación de
WordPress: habría metido un desvío del control de autorización viviendo en
producción, que es algo que se queda encendido por descuido.

## 9. Prueba de humo

Con uno de los usuarios de prueba, o con un alumno real que tenga el curso:

1. Inicia sesión en WordPress y pulsa **Acceder al Diagnostic AI**.
2. Debe aterrizar autenticado en `/sales-diagnostic`, saludado por su nombre.
3. Recarga la URL con el mismo token: debe llevar a la página de rechazo.
4. Con una cuenta **sin** el curso: debe llevar a la página de rechazo con el
   mensaje del curso — que dice que no tiene acceso, no que haya fallado algo.
5. **Con los dos usuarios de prueba**, comprobar el aislamiento: que cada uno
   vea solo su agente, y que ninguno alcance el resultado ni la conversación
   del otro. El cliente marcó este punto como crítico.

Si WordPress no responde durante la prueba, el mensaje debe decir **«No hemos
podido verificar tu acceso»** y no «no tienes acceso»: son cosas distintas y se
distinguen desde el 28-08-2026.

## 10. Acceso por suscripción

Opcional, desde el plugin 1.3.0. Da acceso a **todos** los agentes mientras la
suscripción esté pagada, mensual o anual. El porqué está en
`docs/decisiones/0019-la-suscripcion.md`. Todo se hace en el WordPress del
cliente; Drupal no se toca.

1. **Activar WooCommerce Subscriptions.** Stripe ya admite cobros recurrentes.
2. **Crear el curso** «Suscripción Salesbumm AI» en LearnDash, en modo de
   acceso **Cerrado**. No necesita lecciones. Si se deja en Abierto o Gratis, el
   plugin lo ignora —cualquiera lo tendría sin pagar— y lo avisa en sus ajustes.
3. **Crear los productos de suscripción**, uno por plan y segmento (o uno
   variable con mensual y anual), y asociarles ese curso en su apartado de
   LearnDash. Quién puede comprar el precio de cada segmento se decide en la
   tienda, por ejemplo con el plugin de membresías.
4. **Ajustes → Diagnostic AI → Cursos de suscripción**: poner el ID del curso.
   No ponerlo también en «Cursos que dan acceso».
5. **Probar con una cuenta de prueba y Stripe en modo prueba:**
   - Suscribirse: entra y ve los dos agentes, sin aviso de caducidad.
   - Cancelar la suscripción: pierde el acceso. Drupal recuerda una
     autorización concedida **hasta 15 minutos**, así que el cierre puede
     tardar eso.
   - Simular un pago fallido y comprobar que la integración retira el curso
     cuando la suscripción queda **en espera**: depende de cómo esté configurada
     la tienda.
   - Con un alumno que ya tenía el curso, comprobar que sigue igual.

---

## Despliegues posteriores

```bash
export PATH=/opt/cpanel/ea-php84/root/usr/bin:$PATH   # PHP 8.4 (§2)
cd /home/labai/public_html
drush sql:dump --gzip --result-file=../copia-previa.sql   # SIEMPRE antes
git pull
composer install --no-dev --optimize-autoloader
drush updatedb -y          # aplica los hook_update_N pendientes
drush config:import -y     # aplica la configuración del repositorio
drush cache:rebuild
```

`config:import` **sobrescribe** la configuración del servidor con la del
repositorio. Si alguien cambió algo desde la interfaz y no se exportó, se
pierde. Antes de un despliegue, comprobar `drush config:status` para ver si hay
cambios sin versionar.

La excepción son los números de archivo —documentos e icono de cada agente,
fondo y logotipos de la portada—: los protege `config_ignore`. Los cargadores
(§6) solo se vuelven a pasar cuando cambian esos archivos en el repositorio.

---

## Vuelta atrás

```bash
# Restaurar el código
git checkout <commit-anterior>
composer install --no-dev

# Restaurar la base de datos desde la copia previa al despliegue
drush sql:drop -y && drush sql:query --file=copia-previa.sql
drush cache:rebuild
```

**Hacer siempre una copia de la base de datos antes de desplegar.** Los
`hook_update_N` modifican el esquema y no todos tienen vuelta atrás.

---

## Publicar una versión del plugin de WordPress

El plugin vive en el WordPress del cliente y se instala subiendo un archivo,
sin repositorio ni actualizador automático. Eso hace que el número de versión
sea lo ÚNICO que permite saber qué hay corriendo en su sitio.

**Subir la versión no es opcional.** WordPress no distingue dos archivos con la
misma versión: si no cambia, quien administra el sitio ve el mismo número antes
y después de actualizar, y nadie —tampoco Drupal— puede saber cuál está
instalado.

### Procedimiento

1. **Elegir el número** según lo que cambió:

   | Parte | Cuándo |
   |---|---|
   | PARCHE | corrección que no cambia lo que Drupal recibe |
   | MENOR | dato o capacidad nueva que Drupal puede aprovechar |
   | MAYOR | cambio que rompe lo que Drupal esperaba |

2. **Cambiarlo en los dos sitios** de `salesbumm-sld.php`: la cabecera
   `Version:` y la constante `SLD_VERSION`.

3. **Comprobar que coinciden**:

   ```bash
   php bin/comprobar-version-plugin.php
   ```

   Falla con código 1 si los dos números difieren o si el formato no es
   `MAYOR.MENOR.PARCHE`.

4. **Anotar el cambio** en `wordpress-plugin/salesbumm-sld/CHANGELOG.md`.

5. Si el módulo de Drupal **empieza a depender** de algo que añade esta
   versión, subir también `SalesLeadershipDiagnostic::MINIMUM_PLUGIN_VERSION`.
   Solo en ese caso: exigir la última versión por costumbre obligaría al
   cliente a actualizar por cambios que no le afectan.

6. **Correr las pruebas del plugin**, que deben pasar todas:

   ```bash
   ddev exec vendor/bin/phpunit -c wordpress-plugin/salesbumm-sld/tests/phpunit.xml.dist
   ```

7. **Empaquetar** sin las pruebas ni los archivos ocultos de macOS (comprimir
   desde el Finder mete una carpeta `__MACOSX` en el zip):

   ```bash
   cd wordpress-plugin && rm -f salesbumm-sld.zip && \
     zip -rX salesbumm-sld.zip salesbumm-sld -x 'salesbumm-sld/tests/*' '*.DS_Store'
   ```

   Subirlo en **Plugins → Añadir nuevo → Subir plugin**, marcando la opción de
   reemplazar.

### Después de subirla

Entrar en **Informes → Informe de estado** del Drupal y buscar
«Diagnostic AI: plugin de WordPress». Debe mostrar la versión que se acaba de
subir. Si dice «Anterior a X», el archivo que se subió no es el que se
preparó, o el sitio conserva el anterior en cache.

El dato se actualiza la primera vez que se comprueba una autorización, así que
puede hacer falta que un alumno entre —o forzarlo con:

```bash
drush eval '\Drupal\Core\Cache\Cache::invalidateTags(["sld_authorization"]);'
```

---

## Prueba de humo de la interfaz

Hay una clase de fallos que ni los tests ni las comprobaciones por línea de
órdenes ven, y en este proyecto los encontró siempre el cliente usando el
producto: un error fatal servido con código 200, un formulario que carga pero
no guarda, una subida que falla en su petición AJAX, texto ilegible solo con el
sistema en modo oscuro, o una función a la que se tiene permiso pero a la que
no lleva ningún enlace.

`bin/humo.mjs` los reproduce todos. Hay que pasarla antes de dar por cerrado
cualquier cambio que toque interfaz, formularios, permisos o CSS:

```bash
ULI=$(ddev drush uli --no-browser --uri=https://diagnostic-ai.ddev.site)
SLD_ULI="$ULI" node ~/.claude/skills/browser-automation/browser.mjs \
  https://diagnostic-ai.ddev.site/ --script bin/humo.mjs
```

Solo cuenta como pasar `"resultado": "TODO BIEN"` con la lista de fallos vacía.

Inicia sesión de verdad con cada rol, envía los formularios de administración,
mide el contraste en modo claro y oscuro, y comprueba que el gestor llegue a
sus herramientas **por enlace** y no escribiendo la URL. No envía mensajes al
proveedor de IA, así que no cuesta llamadas.

Desde el 14-09-2026 la completa **`bin/recorrido.mjs`**, que hace las ACCIONES:
guardar cada formulario como administrador y como gestor, anotar una cuenta,
abrir los informes, comprobar que nada se sale de ancho en móvil y, con
`SLD_CHAT=1`, mandar un mensaje real (cuesta unos centavos). Instrucciones en
su cabecera. Las dos corren en local, antes de desplegar, y **nunca a la vez**:
comparten cuentas y una le cierra la sesión a la otra.

---

## Lista de comprobación

### Antes de tocar el servidor

- [ ] **IP del servidor autorizada en el Hostinger del cliente**, verificada
      con `curl https://api.ipify.org` desde el propio servidor. Sin esto se
      quedan fuera TODOS los alumnos a la vez
- [ ] Usuarios de prueba en su WordPress: uno por curso y uno con la membresía (§8)
- [ ] El prompt de cada agente y su curso de LearnDash, confirmados por escrito

### Servidor y secretos

- [ ] HTTPS activo y `trusted_host_patterns` con `^labai\.salesbumm\.com$`
- [ ] Composer 2.10.3 o superior en el servidor
- [ ] Los tres secretos definidos, con 32 caracteres o más
- [ ] Secretos distintos de los de desarrollo
- [ ] `sld_use_mock_engine` **ausente** de `settings.php`
- [ ] Directorio `sites/default/files-private` creado y con permisos de escritura
- [ ] Cron programado y ejecutándose. Sin él la memoria del alumno no se
      escribe NUNCA, y no falla de forma visible
- [ ] Informe de estado: «documentos de conocimiento» dice **Protegidos**. Si
      dice «a la vista», la metodología del cliente se puede descargar sin
      sesión
- [ ] Registro de Drupal a 100 000 filas (`dblog.settings row_limit`). Con
      las 1 000 de fábrica y el cron cada minuto, un error se borra en hora y
      media

### Instalación

- [ ] `drush updatedb:status` sin pendientes
- [ ] `drush config:status` sin diferencias
- [ ] Informe de estado sin errores: «2 agentes disponibles»
- [ ] Cargadores pasados: `cargar-agentes.php` y `cargar-marca.php`. Sin ellos
      no hay logotipos, iconos ni documentos
- [ ] Las tres cuentas: admin, `gestor.sam` (rol `gestor_sam`) y `alumno.demo`
      enlazado a un usuario de prueba de WordPress

### Configuración del producto

- [ ] Modelo de IA elegido del catálogo
- [ ] Los dos agentes activos, cada uno con su curso de LearnDash confirmado
- [ ] Documentos de conocimiento cargados en cada agente (los pone el cargador)
- [ ] Topes: 5 USD por alumno y 30 USD globales al mes (vienen en la
      configuración; subir el global al abrir a alumnos reales)
- [ ] Presupuesto de tokens holgado. Con 2.000 el informe final del cliente NO
      cabe y el alumno pierde el diagnóstico al concluir
- [ ] Decidido el plazo de conservación de las conversaciones (de fábrica: no
      se purga nada)
- [ ] URL de acceso configurada en WordPress

### Verificación

- [ ] Prueba de humo superada con un usuario de prueba
- [ ] **Aislamiento comprobado con los dos usuarios de prueba**: cada uno ve
      solo su agente y no alcanza nada del otro
- [ ] En local, antes de desplegar: `bin/humo.mjs` y `bin/recorrido.mjs` en verde
- [ ] Versión del plugin subida y anotada en su CHANGELOG
- [ ] El informe de estado muestra la versión correcta del plugin
- [ ] Copia de la base de datos guardada
