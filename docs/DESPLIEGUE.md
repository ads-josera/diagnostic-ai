# Manual de despliegue

## Sales Leadership Diagnostic AI

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
| PHP | 8.3 o superior |
| Base de datos | MariaDB 10.6+ / MySQL 8.0+ / PostgreSQL 12+ |
| Composer | 2.x |
| HTTPS | **Obligatorio.** El token de acceso viaja en la URL |

## 2. Código y dependencias

```bash
git clone git@github.com:ads-josera/diagnostic-ai.git
cd diagnostic-ai

# --no-dev excluye Drush y las herramientas de desarrollo.
composer install --no-dev --optimize-autoloader
```

> Si necesitas Drush en el servidor para el despliegue, instala sin `--no-dev`
> o añade `drush/drush` como dependencia de producción. El resto de comandos de
> este manual lo requieren.

## 3. Variables de entorno

**Antes** de instalar. Ver `.env.example` para la descripción de cada una.

```bash
export DRUPAL_HASH_SALT="$(openssl rand -hex 32)"
export DRUPAL_TRUSTED_HOST='^diagnostico\.salesbumm\.com$'

export SLD_JWT_SHARED_SECRET="..."   # idéntico al de wp-config.php
export SLD_WP_HMAC_SECRET="..."      # idéntico al de wp-config.php
export SLD_OPENAI_API_KEY="..."
```

### Reglas que no son opcionales

1. **Cada secreto, mínimo 32 caracteres.** `firebase/php-jwt` rechaza claves
   HMAC más cortas y lo hace con un error genérico durante el login, no con un
   mensaje útil. Genera con `openssl rand -hex 32`.
2. **Los dos secretos del puente, distintos entre sí.**
3. **Secretos nuevos para cada entorno** (§49). Los de producción no se
   comparten con desarrollo ni con staging, y los que se usaron durante el
   desarrollo deben rotarse antes de producción.
4. **`sld_use_mock_engine` NO debe existir** en `settings.php`. Si está, los
   diagnósticos se generan con respuestas de prueba. El informe de estado lo
   marca como error, pero conviene comprobarlo antes.

## 4. Base de datos y instalación

```bash
# Crea la base de datos vacía y configura $databases en settings.php.

drush site:install --existing-config \
  --account-name=DevAdmin \
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

En el ensayo, un despliegue correcto deja el informe así:

```
[OK]      Diagnostic AI: WordPress / LearnDash => Configurado
[OK]      Diagnostic AI: secretos              => Configurados
[Warning] Diagnostic AI: agente                => Sin prompt cargado
```

El aviso del agente es normal hasta que el cliente cargue su prompt. **No debe
aparecer ninguna línea sobre el motor simulado**: si aparece, retira
`sld_use_mock_engine` de `settings.php` y limpia caché.

## 6. Configuración desde la interfaz

En **Configuración → Salesbumm → Sales Leadership Diagnostic AI**:

| Sección | Qué revisar |
|---|---|
| WordPress | URL base y curso vienen del repositorio; confirma que son los del entorno |
| Proveedor de IA | Cargar el catálogo de modelos y elegir uno |
| Seguridad | Revisar límites y periodo de gracia |
| Agente | Cargar prompt, instrucciones y versión del cliente |

## 7. Conectar WordPress

En **salesbumm.com → Ajustes → Diagnostic AI**, rellenar la
**URL de acceso en Drupal** con la ruta de este entorno:

```
https://diagnostico.salesbumm.com/sales-diagnostic/sso
```

> ⚠ Ese campo apunta a los alumnos reales. Nunca debe contener una URL de
> desarrollo: un alumno que pulse el botón acabaría en un sitio inexistente.

## 8. Prueba de humo

Con un alumno real que tenga el curso:

1. Inicia sesión en WordPress y pulsa **Acceder al Diagnostic AI**.
2. Debe aterrizar autenticado en `/sales-diagnostic`, saludado por su nombre.
3. Recarga la URL con el mismo token: debe llevar a la página de rechazo.
4. Con una cuenta **sin** el curso: debe llevar a la página de rechazo con el
   mensaje del curso.

---

## Despliegues posteriores

```bash
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

6. Comprimir la carpeta `salesbumm-sld` y subirla en **Plugins → Añadir nuevo →
   Subir plugin**, marcando la opción de reemplazar.

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

## Lista de comprobación

- [ ] HTTPS activo y `DRUPAL_TRUSTED_HOST` configurado
- [ ] Los tres secretos definidos, con 32 caracteres o más
- [ ] Secretos distintos de los de desarrollo
- [ ] `sld_use_mock_engine` **ausente** de `settings.php`
- [ ] `drush updatedb:status` sin pendientes
- [ ] `drush config:status` sin diferencias
- [ ] Informe de estado sin errores, salvo el aviso del agente
- [ ] Modelo de IA elegido del catálogo
- [ ] Prompt del cliente cargado, con su versión
- [ ] URL de acceso configurada en WordPress
- [ ] Prueba de humo superada con un alumno real
- [ ] Copia de la base de datos guardada
- [ ] Versión del plugin subida y anotada en su CHANGELOG
- [ ] El informe de estado muestra la versión correcta del plugin
