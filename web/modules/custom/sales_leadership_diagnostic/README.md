# Sales Leadership Diagnostic AI

Módulo custom para Drupal 11 que aloja una experiencia privada de diagnóstico de
liderazgo comercial mediante inteligencia artificial.

La arquitectura completa está documentada en [`docs/ARQUITECTURA.md`](../../../../docs/ARQUITECTURA.md)
en la raíz del proyecto. Este README cubre solo lo necesario para instalar,
configurar y mantener el módulo.

---

## Qué hace

- Recibe alumnos que llegan desde WordPress mediante un token SSO firmado.
- Consulta a WordPress/LearnDash si el alumno tiene el curso que autoriza el
  diagnóstico. **WordPress es la fuente de verdad**; Drupal no gestiona compras,
  inscripciones ni membresías.
- Ejecuta el diagnóstico como una conversación con un agente de IA, usando el
  prompt y la metodología que proporciona el cliente.
- Guarda sesiones, mensajes y resultados, y los expone únicamente a su dueño.

## Qué NO hace

No gestiona ecommerce, no duplica LearnDash, no accede a la base de datos de
WordPress, no almacena contraseñas de WordPress y no crea metodología ni
preguntas propias. La metodología pertenece al cliente.

---

## Principio de diseño central

**Identidad y autorización son cosas distintas.**

El token SSO prueba *quién* es el alumno: dura segundos y se consume una sola
vez. El derecho de acceso (*¿tiene el curso?*) se consulta a WordPress de forma
independiente y se cachea con TTL.

Si el token cargara el permiso, un alumno cuya compra se reembolsara seguiría
autorizado durante toda su sesión. Separándolos, WordPress sigue siendo la
fuente de verdad viva y el comportamiento *fail closed* es real.

---

## Requisitos

| Requisito | Versión |
|---|---|
| Drupal | ^11 |
| PHP | >= 8.3 |
| `drupal/externalauth` | ^2.0 |
| `firebase/php-jwt` | ^7.1 |
| `league/commonmark` | ^2.5 |

> `firebase/php-jwt` debe permanecer en la línea **7.x**: las versiones 6.10.0 a
> 6.11.1 están afectadas por un aviso de seguridad y Composer las bloquea.

## Instalación

```bash
ddev composer install
ddev drush en sales_leadership_diagnostic -y
```

## Configuración

### 1. Secretos (obligatorio)

Los secretos **nunca** se guardan en configuración de Drupal ni en Git. Se leen
de variables de entorno a través de `settings.php`:

| Variable | Uso |
|---|---|
| `SLD_JWT_SHARED_SECRET` | Verificar la firma del token SSO de WordPress |
| `SLD_WP_HMAC_SECRET` | Firmar las llamadas de Drupal hacia WordPress |
| `SLD_OPENAI_API_KEY` | Autenticación con el proveedor de IA |

Ver `.env.example` en la raíz del proyecto. En desarrollo se definen en
`.ddev/config.local.yaml` (ignorado por Git) y se aplican con `ddev restart`.

Comprueba el estado en **Informes → Informe de estado**
(`/admin/reports/status`). El módulo reporta ahí qué falta, sin revelar valores.

### 2. Integración y agente

Pendiente de la Fase 2. La configuración vivirá en
`/admin/config/salesbumm/diagnostic`.

---

## Permisos

| Permiso | Otorga |
|---|---|
| `access sales leadership diagnostic` | Usar el diagnóstico y ver **los resultados propios** |
| `administer sales leadership diagnostic` | Configurar integración, agente y límites |
| `view all diagnostic results` | Ver resultados de **cualquier** alumno |

El primero **no** permite ver datos ajenos: la propiedad se verifica siempre a
nivel de entidad, incluso para quien tenga el permiso de acceso.

`view all diagnostic results` expone información empresarial sensible de todos
los alumnos. Otórgalo solo a personal de soporte autorizado.

El módulo crea el rol **Alumno Diagnostic AI** (`sales_diagnostic_student`) con
el primer permiso. Se asigna automáticamente al provisionar cuentas desde
WordPress.

---

## Mantenimiento

### El rol de alumno se crea por código, no por `config/install`

Es una decisión deliberada, y la razón importa si algún día se refactoriza.

Cuando se desinstala un módulo, Drupal **no borra** los roles que dependen de
él: `Role::onDependencyRemoval()` les retira los permisos pero conserva el rol
para no destruir las asignaciones a usuarios. Un rol declarado en
`config/install` sobrevive por tanto a la desinstalación, y la siguiente
instalación aborta con `PreExistingConfigException` — dejando el módulo
**imposible de reinstalar**, en contra de §3.

Por eso el rol se crea en `hook_install()` de forma idempotente: si ya existe,
se le devuelve su permiso en lugar de fallar.

### Desinstalación

`hook_uninstall()` retira los permisos del módulo del rol de alumno y después:

- si **ningún** usuario tiene el rol asignado, lo elimina;
- si **algún** usuario lo tiene, conserva el rol vacío y lo registra en el log.

Nunca se borra un rol que siga asignado: sería destruir información
irreversible. Las cuentas provisionadas desde WordPress tampoco se eliminan.

Comportamiento verificado en los cinco escenarios: instalación limpia,
desinstalación sin usuarios, reinstalación, desinstalación con usuarios
asignados y reinstalación sobre un rol huérfano.

### Reglas al modificar este módulo

- La lógica de negocio vive en servicios. Controllers, Forms y Hooks son solo
  puntos de entrada.
- Ninguna clase salvo `SecretsProvider` lee `Settings` o `getenv()` para
  obtener secretos.
- Ninguna clase salvo `OpenAIDiagnosticProvider` sabe qué proveedor de IA se
  usa.
- Los mensajes de excepción acaban en logs: nunca deben contener secretos,
  tokens, prompts completos ni respuestas completas del modelo.
- Antes de usar una API de Drupal, verificar que no esté deprecada en 11.x.

---

## Estado del desarrollo

| Fase | Estado |
|---|---|
| 0 — Entorno base | ✅ Completada |
| 1 — Esqueleto del módulo | ✅ Completada |
| 2 — Configuración administrativa | ✅ Completada |
| 6 — Modelo de datos | ✅ Completada |
| 7 — Dashboard | ✅ Completada |
| 8 — Chat UI | Siguiente |
| 3, 4, 5 — WordPress, autorización, SSO | Bloqueadas: requieren acceso al WordPress del cliente |
| 9–16 | Pendientes (ver `docs/ARQUITECTURA.md` §14) |

### El botón «Iniciar diagnóstico» está deshabilitado a propósito

No es un pendiente olvidado. Crear una sesión exige comprobar antes que el
alumno tiene derecho al curso, y esa cadena de autorización llega en las fases
4 y 5. Habilitar el botón ahora significaría abrir una vía para crear sesiones
sin verificar autorización, que es exactamente lo que prohíben §12 y §13.

El panel ya muestra el historial y el estado de disponibilidad; solo falta la
acción, que se conecta junto con la capa de conversación.
