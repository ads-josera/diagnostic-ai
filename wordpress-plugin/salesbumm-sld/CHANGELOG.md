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

## 1.3.0 — 13 de septiembre de 2026

**Novedades**

- **Acceso por suscripción.** Campo nuevo en los ajustes, «Cursos de
  suscripción». Quien tenga uno de esos cursos entra a **todos** los agentes y
  su acceso no caduca por su cuenta: dura lo que dure la suscripción. La cobra
  WooCommerce Subscriptions, mensual o anual, y la integración de LearnDash con
  WooCommerce concede el curso al pagar y lo retira al cancelarse.
- Quien compró el curso y además se suscribe no gana ni pierde nada: su
  periodo de doce meses sigue contando desde la compra.
- Un curso que figure en las dos listas se trata como suscripción, y la
  pantalla de ajustes lo señala.
- Un curso de suscripción en modo **Abierto** o **Gratis** se ignora y la
  pantalla lo avisa: cualquiera lo tendría y se llevaría los agentes sin pagar.

**Compatibilidad**

- Con el campo vacío, el plugin se comporta exactamente como la 1.2.0.
- Drupal no necesita ningún cambio: al suscriptor le llegan todos los cursos
  en `owned_courses` y `expires_at` vacío, que ya sabía interpretar. La versión
  mínima que exige Drupal no sube.

**Mantenimiento**

- El plugin tiene pruebas automáticas por primera vez, en `tests/`. Las del
  acceso por curso se escribieron contra la 1.2.0 antes de tocarla y siguen
  pasando. No van en el paquete que se sube a WordPress.

---

## 1.2.0 — 25 de agosto de 2026

**Novedades**

- El endpoint de autorización devuelve `owned_courses`: **todos** los cursos
  autorizadores que tiene el alumno, no solo el primero. Es lo que permite a
  Drupal concederle cada agente por el curso que lo da. `course_id` se conserva
  con el primero para no romper a un Drupal anterior.

*Esta entrada se añadió con la 1.3.0: faltaba.*

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
