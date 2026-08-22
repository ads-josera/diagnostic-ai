# Salesbumm — Diagnostic AI (puente para WordPress)

Conecta el WordPress con LearnDash del cliente con el módulo de diagnóstico
alojado en Drupal.

No añade funcionalidad visible al sitio salvo un botón de acceso. No toca el
contenido, ni los cursos, ni las inscripciones, ni el proceso de compra.

---

## Qué hace, exactamente

**Dos cosas, y nada más:**

1. **Emite el token de acceso.** Cuando un alumno con el curso pulsa
   *«Acceder al Diagnostic AI»*, el plugin firma un token de vida muy corta y
   lo redirige a Drupal.
2. **Responde a una pregunta.** Drupal le consulta *«¿el usuario X tiene el
   curso Y?»* y el plugin responde sí o no.

**WordPress sigue siendo la fuente de verdad.** Drupal no gestiona compras,
inscripciones ni membresías, y no accede a esta base de datos.

## Por qué no usa la REST API de LearnDash

Podría, pero expondría bastante más de lo necesario: el perfil del alumno, su
progreso, la lista de sus cursos. Este endpoint devuelve un booleano.

Además no requiere ninguna credencial de usuario — ni contraseñas de
aplicación, ni cuentas de servicio — porque se autentica con una firma. No hay
ninguna credencial que robar ni cuyo alcance ampliar.

---

## Requisitos

| Requisito | Versión |
|---|---|
| WordPress | 6.0 o superior |
| PHP | 7.4 o superior |
| LearnDash | Activo |

> El módulo de Drupal exige PHP 8.3, pero este plugin se escribe para 7.4
> a propósito: corre en el WordPress del cliente, cuya versión no controlamos.

## Instalación

1. Copia la carpeta `salesbumm-sld` en `wp-content/plugins/`.
2. Actívalo en **Plugins**.
3. Define los secretos en `wp-config.php` (ver abajo).
4. Configúralo en **Ajustes → Diagnostic AI**.
5. Inserta el shortcode `[salesbumm_diagnostic_button]` donde quieras el botón.

Si prefieres que no pueda desactivarse, colócalo en `wp-content/mu-plugins/`
con un archivo cargador de una línea:

```php
<?php
require WPMU_PLUGIN_DIR . '/salesbumm-sld/salesbumm-sld.php';
```

---

## Secretos

Se definen en `wp-config.php`, **antes** de la línea
`/* That's all, stop editing! */`:

```php
define( 'SLD_JWT_SHARED_SECRET', 'pega-aqui-el-secreto' );
define( 'SLD_WP_HMAC_SECRET',    'pega-aqui-el-otro-secreto' );
```

Genera cada uno por separado:

```bash
openssl rand -hex 32
```

### Tres reglas que importan

**1. Cada secreto debe medir al menos 32 caracteres.** No es una preferencia:
la librería que valida el token en Drupal rechaza las claves HMAC de menos de
256 bits, y lo hace lanzando un error genérico en el momento del login. Un
secreto corto se manifiesta como *«no puedo entrar»* sin ninguna pista de la
causa. `openssl rand -hex 32` produce 64 caracteres y cumple de sobra. El
plugin lo comprueba y avisa.

**2. Los dos secretos deben ser distintos entre sí.** Protegen canales
diferentes; si uno se filtra, el otro debe seguir cubriendo el suyo.

**3. Cada uno debe ser idéntico al configurado en Drupal**, y **distinto en
cada entorno**. Los de producción no se comparten con desarrollo ni staging.

### Por qué en `wp-config.php` y no en los ajustes

La base de datos de WordPress se copia con frecuencia: a un entorno de
pruebas, a un volcado para depurar, a una copia de seguridad que acaba en un
disco compartido. Un secreto guardado en la tabla de opciones viaja en todas
esas copias. Una constante de `wp-config.php` se queda en el servidor.

---

## Configuración

**Ajustes → Diagnostic AI**

| Campo | Qué es |
|---|---|
| URL de acceso en Drupal | La ruta `/sales-diagnostic/sso` del sitio Drupal. **Debe usar HTTPS**: el token viaja en esta URL |
| Curso que da acceso | ID del curso de LearnDash cuya compra habilita el diagnóstico. Debe coincidir con el configurado en Drupal |
| Vigencia del token | Segundos. Solo tiene que sobrevivir a una redirección. Recomendado: 90 |

Si el cliente cambia el curso que da acceso, se edita aquí y en Drupal. No hay
que tocar código en ninguno de los dos lados.

---

## Seguridad

### El token no se genera al pintar la página

Es la decisión de diseño más importante del plugin.

Si el botón llevara el token incrustado en su enlace, cualquier capa de caché
de página —y casi todo WordPress en producción tiene una— serviría a todos los
visitantes el token del primer alumno que cargó esa página. Sería una
suplantación de identidad servida por la propia infraestructura, y además
silenciosa: el sitio funcionaría con aparente normalidad.

Por eso el botón apunta a `admin-post.php`, que WordPress nunca cachea, y el
token se emite en esa petición, ya con la sesión del alumno resuelta.

### El token

Firmado con HS256, dura 90 segundos por defecto y lleva un identificador único
(`jti`) que Drupal consume una sola vez: reenviarlo no vuelve a dar acceso.

Transporta el correo y el nombre del alumno **solo para poblar su perfil en
Drupal la primera vez**. La identidad es el ID de usuario de WordPress; Drupal
nunca autoriza por correo electrónico.

### El endpoint de autorización

`POST /wp-json/salesbumm-sld/v1/access`

Cada petición debe traer tres cabeceras:

```
X-SLD-Timestamp: 1755792000
X-SLD-Nonce:     <aleatorio, un solo uso>
X-SLD-Signature: HMAC-SHA256( secreto, timestamp + "." + nonce + "." + cuerpo )
```

- La **firma** demuestra que la petición viene de Drupal.
- La **marca de tiempo** (±5 minutos) y el **nonce** impiden repetirla.
- La comparación de firmas usa `hash_equals`, para que el tiempo de respuesta
  no revele cuántos bytes eran correctos.

Cualquier fallo devuelve el mismo `401` con el mismo mensaje: distinguir
*firma inválida* de *nonce repetido* ayudaría a quien esté sondeando el
endpoint. El motivo real queda en el log del servidor.

**Si LearnDash no está disponible**, el endpoint responde `503` en lugar de
`false`. Drupal debe poder distinguir *«no tiene acceso»* de *«no he podido
comprobarlo»*: ante una avería puede apoyarse en una autorización validada
previamente, ante una denegación real no.

---

## Diagnóstico de problemas

| Síntoma | Causa probable |
|---|---|
| El botón no aparece | El alumno no tiene el curso, no ha iniciado sesión, o falta configurar el curso |
| «El diagnóstico no está disponible» | Falta la URL de Drupal, el curso o el secreto JWT. Revisa **Ajustes → Diagnostic AI** |
| El alumno llega a Drupal y se le deniega | Los secretos de los dos lados no coinciden, o el reloj de un servidor está desajustado |
| Drupal recibe siempre `401` del endpoint | El `SLD_WP_HMAC_SECRET` no coincide, o el reloj difiere más de 5 minutos |
| El login falla con un error genérico | Algún secreto tiene menos de 32 caracteres |

Con `WP_DEBUG` activo, el plugin registra el motivo real de cada rechazo en el
log de PHP. Nunca registra el token ni los secretos.

---

## Mantenimiento

- Toda la lógica de LearnDash está en `class-sld-course-access.php`. Si el
  cliente cambiara de LMS, se reescribe esa clase y Drupal no se entera.
- El plugin no crea tablas, ni opciones fuera de las tres documentadas, ni
  tipos de contenido. Desinstalarlo no deja residuos relevantes.
- Los nonces consumidos se guardan como *transients* con caducidad de 10
  minutos y se limpian solos.
