# Arranque en producción — labai.salesbumm.com

Documento para **Jarvis (Claude) en el servidor cPanel** y para José Raúl.
Se escribió el 14-09-2026, justo antes del primer despliegue.

**Ensayado de principio a fin el 14-09-2026** en local: instalación limpia
desde el repositorio, cargadores, cuentas y comprobación en el navegador
(logotipos, iconos, los dos agentes, pantallas del gestor), sin un solo error.

**Léelo entero antes de empezar.** Quien ejecuta esto en el servidor no tiene
el contexto del desarrollo: solo este repositorio. Todo lo que hace falta saber
está aquí o en `docs/DESPLIEGUE.md`, que es el manual detallado; este documento
dice **en qué orden** y **con qué datos**, y corrige lo que allí quedó viejo.

---

## Reglas que no se negocian

1. **Los secretos NUNCA pasan por el chat**, ni por el repositorio, ni por la
   configuración de Drupal. Las llaves (OpenAI, Tavily, los dos secretos de
   WordPress) las escribe **José Raúl** directamente en el archivo
   `settings.local.php` del servidor. Jarvis prepara el archivo con huecos y le
   dice dónde está; no le pide que las pegue.
2. **La base de datos NO viaja.** El sitio se instala desde cero con la
   configuración exportada (`config/sync`). No se copia la base de datos local.
3. **Preguntar antes de cada paso que no se pueda deshacer**: instalar sobre una
   base de datos, borrar, sobrescribir archivos, tocar cron.
4. **Los prompts del cliente no se tocan** (§15). Se cargan tal cual con el
   cargador del repositorio.
5. **El WordPress del cliente (salesbumm.com) no lo toca Jarvis.** Lo que haya
   que cambiar allí lo hace José Raúl, y aquí se le indica qué.
6. Antes de cualquier cambio en producción ya desplegada: **copia de la base de
   datos**.

---

## Datos del entorno

| Qué | Valor |
|---|---|
| Dominio | **https://labai.salesbumm.com** (ya apunta al servidor) |
| Raíz del documento | **`/home/labai/public_html/web`**: el repositorio vive en `/home/labai/public_html` (cuenta cPanel `labai`, sin otros dominios; comprobado el 14-09-2026) |
| Servidor | cPanel con acceso SSH |
| PHP | **8.4** (el que se usa en desarrollo); mínimo 8.3. El PHP de la **línea de órdenes** debe ser la misma versión que el de la web: el cron corre por línea de órdenes |
| Repositorio | `git@github.com:ads-josera/diagnostic-ai.git` (**privado**) |
| WordPress del cliente | https://salesbumm.com, con el plugin puente **1.3.0** ya instalado y sus dos secretos ya definidos en su `wp-config.php` |

> La IP de salida del servidor, comprobada desde él el 14-09-2026, es
> `72.167.47.47`: la misma que se autorizó en el Hostinger del cliente el
> 28-08-2026. Basta con confirmar que sigue en su lista blanca.

---

## Paso 0 — Acceso al repositorio

El repositorio es privado. Se da acceso al servidor con una **clave de
despliegue de solo lectura**:

```bash
ssh-keygen -t ed25519 -C "labai.salesbumm.com" -f ~/.ssh/id_ed25519_diagnostic -N ""
cat ~/.ssh/id_ed25519_diagnostic.pub
```

José Raúl copia esa línea en **GitHub → ads-josera/diagnostic-ai → Settings →
Deploy keys → Add deploy key** (sin marcar «Allow write access»). Después:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/id_ed25519_diagnostic
  IdentitiesOnly yes
EOF
ssh -T git@github.com   # debe saludar con el nombre del repositorio
```

## Paso 1 — Requisitos del servidor

Seguir `docs/DESPLIEGUE.md` §1. Lo crítico, en orden:

1. **Tiempos de espera de PHP a 300 s**, empezando por
   `request_terminate_timeout` de PHP-FPM (en cPanel viene en 75 y mata los
   turnos que investigan).
2. **La IP de salida del servidor**, autorizada en el WordPress del cliente:

   ```bash
   curl https://api.ipify.org
   ```

   José Raúl la autoriza en el panel de Hostinger del cliente (lista blanca).
   Sin esto, si Hostinger marca al servidor, **se quedan fuera todos los
   alumnos a la vez** (pasó el 28-08-2026).
3. **HTTPS** activo en el dominio (AutoSSL de cPanel).

## Paso 2 — Código

En este servidor el repositorio va **dentro de `/home/labai/public_html`**, de
modo que su carpeta `web` es la raíz del dominio. La carpeta no está vacía
(cPanel deja `cgi-bin`, `php.ini`, `.user.ini`, `.well-known`), así que no se
usa `git clone` sino `git init` + `remote add` + `fetch` + `checkout`, y esos
archivos de cPanel se conservan y se excluyen en `.git/info/exclude`.

**El PHP de la línea de órdenes del servidor es 8.1** y el del dominio 8.4.
Composer y drush tienen que correr con el de 8.4. **Al empezar cada sesión de
SSH** (esta y cualquier despliegue posterior), poner el PHP 8.4 por delante;
así todas las órdenes `composer` y `vendor/bin/drush` de esta guía lo usan tal
cual están escritas:

```bash
export PATH=/opt/cpanel/ea-php84/root/usr/bin:$PATH
cd /home/labai/public_html
php -v | head -1              # debe decir PHP 8.4
composer --version            # 2.10.3 o superior (CVE-2026-84361), y «PHP version 8.4»
                              # si dice 8.1, el composer de cPanel fija su PHP:
                              # usar  php $(command -v composer) ...  en su lugar
composer install --no-dev --optimize-autoloader
vendor/bin/drush --version    # drush es dependencia de producción
```

Si Composer es anterior a 2.10.3, actualizarlo antes: la vulnerabilidad permite
ejecutar órdenes al instalar un paquete malicioso. **En este servidor
`composer self-update` no sirve**: `/usr/local/bin/composer` es un binario
compartido que pertenece a otra cuenta del sistema y no se toca. La cuenta
`labai` tiene su propio Composer en `~/.local/bin/composer` (instalado el
14-09-2026 con el instalador oficial, verificando su firma), que va antes en
el `PATH`. Comprobar con `command -v composer` que es ese el que responde; para
actualizarlo más adelante, `composer self-update` sí funciona sobre esa copia.

**PHP 8.4 lo decide PHP-FPM, no el `.htaccess`.** El dominio corre con PHP-FPM
(MultiPHP Manager → PHP-FPM encendido, versión 8.4): la versión la fija la
configuración del servidor, y `web/.htaccess` es el de Drupal sin añadidos.
Con FPM encendido, cPanel **quita** su bloque `AddHandler` del `.htaccess`, y
el repositorio no lo vuelve a poner, para no pelear con el panel.

Lección del 14-09-2026: **después de cambiar la raíz del documento en cPanel,
volver a aplicar PHP-FPM** (MultiPHP Manager: apagar y encender PHP-FPM del
dominio, dejando 8.4). Si no, el pool de FPM sigue sirviendo desde la raíz
vieja y cualquier `.php` responde 404 «No input file specified» mientras los
archivos estáticos funcionan. La prueba que lo delata:

```bash
echo '<?php echo "WEB ", PHP_VERSION;' > web/sonda-fpm.php
curl -s https://labai.salesbumm.com/sonda-fpm.php   # esperado: WEB 8.4.x
rm web/sonda-fpm.php
```

## Paso 3 — Base de datos y `settings.local.php`

1. Crear en cPanel una base de datos MariaDB/MySQL vacía y su usuario.
2. Crear **`web/sites/default/settings.local.php`** (git lo ignora; ya lo carga
   `settings.php` al final). Jarvis lo crea con huecos y **José Raúl rellena los
   valores**:

```php
<?php

// Base de datos creada en cPanel.
$databases['default']['default'] = [
  'database' => 'RELLENAR',
  'username' => 'RELLENAR',
  'password' => 'RELLENAR',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
  // El nivel que recomienda Drupal: con REPEATABLE READ (el de este MariaDB)
  // pueden aparecer bloqueos cuando varios alumnos escriben a la vez, y el
  // informe de estado lo avisa.
  'init_commands' => [
    'isolation_level' => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
  ],
];

// Generar con: openssl rand -hex 32
$settings['hash_salt'] = 'RELLENAR';

$settings['trusted_host_patterns'] = ['^labai\.salesbumm\.com$'];

// Los dos secretos del puente: IDÉNTICOS a los del wp-config.php del
// WordPress del cliente (ya están allí definidos).
$settings['sld_jwt_shared_secret'] = 'RELLENAR';
$settings['sld_wp_hmac_secret'] = 'RELLENAR';

// Llaves de José Raúl mientras el cliente prueba. Se cambian por las del
// cliente cuando él lo indique (ver «Cambiar a las llaves del cliente»).
$settings['sld_openai_api_key'] = 'RELLENAR';
$settings['sld_search_api_key'] = 'RELLENAR';
```

> Por qué un archivo y no variables de entorno: en
> cPanel las variables del servidor web no llegan a la línea de órdenes, y el
> cron (que genera los turnos que investigan y escribe la memoria) corre por
> línea de órdenes. El archivo lo leen los dos.

```bash
chmod 440 web/sites/default/settings.local.php
mkdir -p web/sites/default/files-private && chmod 775 web/sites/default/files-private
```

## Paso 4 — Instalar

```bash
vendor/bin/drush site:install --existing-config \
  --account-name=admin \
  --account-pass="$(openssl rand -base64 18)" -y
vendor/bin/drush updatedb -y
vendor/bin/drush cache:rebuild
vendor/bin/drush config:status        # esperado: sin diferencias
vendor/bin/drush core:requirements | grep -i diagnostic
```

**Anota la contraseña del administrador** que imprime el comando y dásela a José
Raúl; puede cambiarla al entrar.

La configuración exportada ya trae, entre otras cosas:

- **Topes de gasto**: 5 USD por alumno al mes (100 pesos con el dólar a 20) y
  30 USD al mes para toda la instalación, con aviso al 80 %. Tipo de cambio de
  la pantalla de consumo: 20.
- Registro de Drupal a 100 000 entradas, favicon, marca y los dos agentes.

## Paso 5 — Agentes, documentos, iconos y logotipos

```bash
vendor/bin/drush php:script bin/cargar-agentes.php
vendor/bin/drush php:script bin/cargar-marca.php
```

El primero pone el prompt, el contrato de salida, los documentos (en la
carpeta **privada**) y el **icono** de cada agente. El segundo, el fondo y los
**dos logotipos** de la portada. Todo sale del repositorio (`docs/marca/`,
`docs/knowledge-cliente/`, `docs/Knowledge documents/`).

Por qué hacen falta: la configuración guarda el NÚMERO de cada archivo, y los
números que vienen en `config/sync` son los del entorno de desarrollo. Sin
estos dos pasos, los agentes quedan sin documentos ni icono y **ninguna
pantalla del alumno tiene logotipo**. En la primera carga el cargador dice
«carga inicial en este entorno» y **no sube la versión** de los agentes: es la
misma metodología, solo que aún no estaba aquí.

Comprobar en **Informes → Informe de estado** que «Diagnostic AI: documentos de
conocimiento» dice **Protegidos**. Detalle en DESPLIEGUE §6.

## Paso 6 — Las tres cuentas que se conservan siempre

José Raúl las quiere siempre en producción, para probar.

| Cuenta | Para qué | Cómo |
|---|---|---|
| **admin** | revisar todo Drupal | la crea el paso 4 |
| **gestor.sam** | soporte y gestión de agentes | abajo |
| **alumno.demo** | probar como alumno | abajo, enlazada a WordPress |

```bash
vendor/bin/drush user:create gestor.sam --mail="RELLENAR" --password="$(openssl rand -base64 18)"
vendor/bin/drush user:role:add gestor_sam gestor.sam

vendor/bin/drush user:create alumno.demo --mail="RELLENAR" --password="$(openssl rand -base64 18)"
vendor/bin/drush user:role:add sales_diagnostic_student alumno.demo
```

**alumno.demo tiene que estar enlazado a un usuario de WordPress** con acceso a
los cursos, porque el acceso de un alumno siempre se comprueba contra
WordPress. En desarrollo estaba enlazado al usuario 1 de WordPress (el
administrador de salesbumm.com); en producción debe ser **un usuario de PRUEBA
de WordPress** que José Raúl indique (su número de usuario de WordPress):

```bash
vendor/bin/drush php:eval '
  $u = user_load_by_name("alumno.demo");
  \Drupal::service("externalauth.authmap")->save($u, "sld_wp", "NUMERO_DE_USUARIO_WP");
'
```

Pasa las contraseñas generadas a José Raúl por un canal seguro, no por el chat.

## Paso 7 — Cron cada minuto

En **cPanel → Cron Jobs**, con la ruta real del proyecto y el PHP de la versión
correcta:

```
* * * * * cd /home/labai/public_html && /opt/cpanel/ea-php84/root/usr/bin/php -d session.gc_divisor=100 -d error_log=/home/labai/logs/php-cli-error.log vendor/drush/drush/drush.php cron >/dev/null 2>&1
```

Los dos `-d` son porque el PHP de línea de órdenes no lee lo que se pone en el
MultiPHP INI Editor (eso es solo para la web): sin ellos, cada minuto dejaba
el aviso «session.gc_divisor must be greater than 0» en un `error_log` dentro
de `public_html` (visto el 16-09-2026).

Es imprescindible: genera los turnos que investigan, escribe la memoria del
alumno y desatasca conversaciones. Detalle en DESPLIEGUE §1.

## Paso 8 — Conectar WordPress (lo hace José Raúl)

En **salesbumm.com → Ajustes → Diagnostic AI**:

- **URL de acceso en Drupal**: `https://labai.salesbumm.com/sales-diagnostic/sso`
- **Cursos que dan acceso**: los mismos que la ficha de cada agente en Drupal.
- **Cursos de suscripción**: vacío hasta que exista la membresía (plugin 1.3.0).

Y colocar el shortcode `[salesbumm_diagnostic_button]` donde vaya el botón,
**solo cuando todo lo anterior funcione**.

## Paso 9 — Verificación

1. `vendor/bin/drush core:requirements | grep -i diagnostic`: WordPress, secretos
   y documentos en OK; ninguna línea de «motor simulado».
2. Abrir https://labai.salesbumm.com/bienvenida y el inicio de sesión: estilo
   oscuro, favicon de Salesbumm.
3. Entrar como **admin** y como **gestor.sam**; guardar la ficha de un agente.
4. Entrar como **alumno.demo**: ve sus agentes, empieza una conversación y el
   agente responde firmando con su nombre.
5. **Pruebas acordadas con José Raúl**: un usuario de WordPress con acceso **por
   curso** y otro **por membresía** entran con el botón de WordPress y ven lo
   que les corresponde.
6. `vendor/bin/drush watchdog:show --severity=Error` sin errores.

---

## Más adelante

### Cambiar a las llaves del cliente

Cuando José Raúl lo indique: editar en `web/sites/default/settings.local.php`
las dos líneas `sld_openai_api_key` y `sld_search_api_key`, y después:

```bash
vendor/bin/drush cache:rebuild
```

No hay que tocar código ni configuración.

### Limpiar los datos de prueba

Cuando el cliente dé el visto bueno, para no arrastrar conversaciones, consumos
ni cuentas de las pruebas:

```bash
vendor/bin/drush sql:dump --gzip --result-file=../copia-antes-de-limpiar.sql   # copia primero
vendor/bin/drush sld:limpiar-pruebas                      # SIMULA: dice qué borraría
vendor/bin/drush sld:limpiar-pruebas --con-alumnos        # simula incluyendo alumnos de prueba
vendor/bin/drush sld:limpiar-pruebas --ejecutar --con-alumnos   # borra, pidiendo confirmación
```

Conserva siempre la configuración, los agentes y sus documentos, el
administrador, el gestor y **alumno.demo**.

### Topes de gasto

30 USD globales al mes alcanzan para unos **8 alumnos muy activos**: sirve para
las pruebas, hay que subirlo cuando entren alumnos reales.

### Despliegues posteriores

```bash
export PATH=/opt/cpanel/ea-php84/root/usr/bin:$PATH   # PHP 8.4 (paso 2)
cd /home/labai/public_html
vendor/bin/drush sql:dump --gzip --result-file=../copia-previa.sql
git pull
composer install --no-dev --optimize-autoloader
vendor/bin/drush updatedb -y
vendor/bin/drush config:import -y
vendor/bin/drush cache:rebuild
```

Los números de archivo (documentos e icono de cada agente, fondo y logotipos
de la portada) **no** los toca `config:import`: los protege el módulo
`config_ignore` (`config/sync/config_ignore.settings.yml`). Los cargadores del
paso 5 solo se vuelven a pasar cuando cambian los documentos, los iconos o los
logotipos del repositorio.

⚠ Todo lo demás sí: `config:import` **sobrescribe** la configuración de
producción con la del repositorio. Si alguien cambia en la interfaz de producción algo que es
configuración (topes, marca, ajustes de un agente), ese cambio **se pierde en el
siguiente despliegue** salvo que se lleve también al repositorio. Antes de
desplegar, `vendor/bin/drush config:status` dice qué se perdería.

---

## La instrucción para arrancar

José Raúl, en el servidor, le dice a Jarvis algo como:

> Jarvis, vamos a desplegar Sales Leadership Diagnostic AI en este servidor
> cPanel. Clona el repositorio `ads-josera/diagnostic-ai` y sigue
> `docs/ARRANQUE-PRODUCCION.md` paso a paso. El dominio es
> https://labai.salesbumm.com y su raíz apunta a la carpeta `web`. Pregúntame
> antes de cada paso que no se pueda deshacer, y nunca me pidas que pegue
> llaves en el chat.

Como el repositorio aún no está clonado la primera vez, el paso 0 (clave de
despliegue) va antes de que Jarvis pueda leer este archivo: José Raúl le puede
pegar ese paso o pedirle directamente que genere la clave de despliegue.
