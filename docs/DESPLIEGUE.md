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
| Cron | **Debe ejecutarse.** Ver más abajo |

### La IP del servidor tiene que estar autorizada en el WordPress del cliente

**Requisito previo al despliegue.** El módulo consulta la autorización de cada
alumno contra `salesbumm.com`. Si el alojamiento del cliente marca al servidor
como abusivo y le corta, **se quedan fuera todos los alumnos a la vez**.

No es hipotético: ocurrió el 28-08-2026 con la IP de la oficina. Hostinger la
bloqueó por el volumen de consultas durante el desarrollo, y el diagnóstico
dejó de conceder acceso.

Lo que hay que hacer, en el hPanel de Hostinger del cliente:

1. Autorizar la IP del servidor de producción. A 28-08-2026 es
   **`72.167.47.47`**.
2. **Comprobar que esa es la IP de SALIDA**, no solo la de entrada. Con NAT o
   balanceadores pueden ser distintas, y entonces se estaría autorizando una
   que nunca aparece en las peticiones. Desde el servidor:

       curl https://api.ipify.org

   Debe devolver exactamente la IP autorizada. Verificado el 28-08-2026:
   devuelve `72.167.47.47`.

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

    */15 * * * * cd /ruta/al/sitio && vendor/bin/drush cron

También hay directorio privado que crear, para los documentos de conocimiento:

    mkdir -p web/sites/default/files-private
    chmod 775 web/sites/default/files-private

Si falta, subir un documento falla con un error poco explícito.

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

Un despliegue recién hecho deja el informe así:

```
[OK]      Diagnostic AI: WordPress / LearnDash => Configurado
[OK]      Diagnostic AI: secretos              => Configurados
[Warning] Diagnostic AI: agentes               => Ninguno disponible
[Warning] Diagnostic AI: plugin de WordPress   => Sin comprobar todavía
```

El aviso de los agentes es normal hasta crear el primero (§6). Un agente cuenta
como disponible cuando está **activo, tiene curso y tiene prompt**: si falta
alguna de las tres, no aparece y los alumnos no pueden empezar.

El del plugin desaparece en cuanto se consulta la autorización de alguien por
primera vez.

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

### Crear el agente

Desde el 26-08-2026 el diagnóstico lo conducen **agentes**, no un prompt único.
La pestaña «Agente» que hubo aquí se retiró: escribía en un sitio que ya no
gobernaba nada.

En **Configuración → Salesbumm → Sales Leadership Diagnostic AI → Agentes**,
«Añadir agente». Un agente necesita tres cosas para estar disponible:

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

### La cuenta del gestor

Los roles llegan con la configuración exportada, pero **las cuentas no**. Quien
vaya a dar soporte necesita una:

```bash
drush user:create gestor --mail="gestor@salesbumm.com" \
  --password="$(openssl rand -base64 18)"
drush user:role:add gestor_sam gestor
```

Con ese rol entra a **Contenido → Resultados de diagnóstico**, que es su sitio:
el listado de diagnósticos, los agentes, el estudio del prompt y los
documentos. NO necesita el rol de administrador, y no conviene dárselo: el
permiso de gestor no incluye ver los secretos ni la integración.

## 7. Conectar WordPress

En **salesbumm.com → Ajustes → Diagnostic AI**, rellenar la
**URL de acceso en Drupal** con la ruta de este entorno:

```
https://diagnostico.salesbumm.com/sales-diagnostic/sso
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

---

## Lista de comprobación

### Antes de tocar el servidor

- [ ] **IP del servidor autorizada en el Hostinger del cliente**, verificada
      con `curl https://api.ipify.org` desde el propio servidor. Sin esto se
      quedan fuera TODOS los alumnos a la vez
- [ ] Los dos usuarios de prueba creados en su WordPress, un curso cada uno
- [ ] El prompt de cada agente y su curso de LearnDash, confirmados por escrito

### Servidor y secretos

- [ ] HTTPS activo y `DRUPAL_TRUSTED_HOST` configurado
- [ ] Los tres secretos definidos, con 32 caracteres o más
- [ ] Secretos distintos de los de desarrollo
- [ ] `sld_use_mock_engine` **ausente** de `settings.php`
- [ ] Directorio `sites/default/files-private` creado y con permisos de escritura
- [ ] Cron programado y ejecutándose. Sin él la memoria del alumno no se
      escribe NUNCA, y no falla de forma visible

### Instalación

- [ ] `drush updatedb:status` sin pendientes
- [ ] `drush config:status` sin diferencias
- [ ] Informe de estado sin errores. El aviso de «ningún agente disponible» es
      normal hasta crear el primero
- [ ] Cuenta del gestor creada y con el rol `gestor_sam`

### Configuración del producto

- [ ] Modelo de IA elegido del catálogo
- [ ] **Un agente por curso**, cada uno activo, con su curso y con su prompt
- [ ] Documentos de conocimiento cargados en cada agente, si los hay
- [ ] Presupuesto de tokens holgado. Con 2.000 el informe final del cliente NO
      cabe y el alumno pierde el diagnóstico al concluir
- [ ] Decidido el plazo de conservación de las conversaciones (de fábrica: no
      se purga nada)
- [ ] URL de acceso configurada en WordPress

### Verificación

- [ ] Prueba de humo superada con un usuario de prueba
- [ ] **Aislamiento comprobado con los dos usuarios de prueba**: cada uno ve
      solo su agente y no alcanza nada del otro
- [ ] `bin/humo.mjs` en verde
- [ ] Versión del plugin subida y anotada en su CHANGELOG
- [ ] El informe de estado muestra la versión correcta del plugin
- [ ] Copia de la base de datos guardada
