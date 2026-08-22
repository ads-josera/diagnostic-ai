# Cambios del plugin

Registro de las versiones del plugin puente de WordPress.

Sirve a dos personas concretas: a quien administra el WordPress del cliente,
para saber si le conviene actualizar; y a quien atiende una incidencia, para
saber qué hace la versión que hay instalada sin tener que leer el código.

Se sigue [versionado semántico](https://semver.org/lang/es/):

| Parte | Cuándo se sube |
|---|---|
| **PARCHE** | corrección que no cambia lo que Drupal recibe |
| **MENOR** | dato o capacidad nueva que Drupal puede aprovechar si existe |
| **MAYOR** | cambio que rompe lo que Drupal esperaba |

---

## 1.1.0 — 22 de agosto de 2026

**Novedades**

- El endpoint de autorización informa de `started_at`: el momento en que
  empezó el periodo de acceso del alumno. Drupal lo necesita para poder
  limitar el diagnóstico a uno por periodo; sin él no hay forma de saber qué
  diagnósticos pertenecen al periodo vigente y cuáles son de una compra
  anterior.
- El endpoint informa también de `plugin_version`. Drupal la guarda y avisa en
  su informe de estado si el plugin instalado es anterior al que necesita.
  Hasta ahora un plugin desactualizado no se notaba hasta que faltaba algo, y
  para entonces ya era un fallo.

**Seguridad**

- `wp_redirect()` pasa a `wp_safe_redirect()`, con el host de destino añadido
  a la lista blanca de WordPress. La URL la fija un administrador, así que el
  riesgo era bajo, pero así un ajuste mal validado o una opción alterada en la
  base de datos no se convierte en una redirección abierta.

**Mantenimiento**

- El plugin cumple WordPress Coding Standards, con su propio `phpcs.xml` que
  documenta la única regla que excluye y por qué.
- `bin/comprobar-version-plugin.php` verifica que la cabecera `Version:` y la
  constante `SLD_VERSION` no se separen.

---

## 1.0.0 — agosto de 2026

Primera versión.

- Inicio de sesión único hacia Drupal mediante un JWT firmado, de un solo uso.
- Endpoint de autorización autenticado con firma HMAC, marca de tiempo y
  nonce; sin cuentas de WordPress ni contraseñas de aplicación.
- Varios cursos autorizadores configurables.
- Reloj propio de vigencia del acceso al diagnóstico, independiente del acceso
  al curso, con duración configurable en meses.

---

## Nota sobre el número que se ve en WordPress

Si el panel de WordPress muestra una versión que no aparece en este archivo,
significa que se instaló un paquete que no salió de este repositorio. Antes de
actualizar conviene averiguar de dónde vino: el código del sitio y el del
repositorio habrían dejado de ser el mismo.
