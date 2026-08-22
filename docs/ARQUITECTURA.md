# PROPUESTA ARQUITECTÓNICA

## Sales Leadership Diagnostic AI — `sales_leadership_diagnostic`

**Drupal 11.4.5 · PHP 8.4 · Symfony 7.4**
**Estado:** Propuesta — pendiente de aprobación (§60)
**Fecha:** 2026-08-21
**Autor:** JARVIS — Senior Drupal Architect

---

## 0. Decisiones cerradas antes de este documento

Cuatro decisiones fueron aprobadas y condicionan todo el diseño:

| # | Decisión | Impacto |
|---|---|---|
| 1 | **Usuario Drupal real auto-provisionado** desde el token de WordPress | Ownership, permisos y protección IDOR nativos de Drupal |
| 2 | **Plugin propio en WordPress** con endpoint firmado | Superficie mínima, sin credenciales de admin, SSO controlado |
| 3 | **Agente = prompt + instrucciones**, sin archivos de knowledge | Sin capa RAG/vector store; provider limpio |
| 4 | **Diseño antes que código** | Este documento precede a cualquier archivo del módulo |

---

## 1. ANÁLISIS DEL ENTORNO EXISTENTE (§4, §60.1–7)

### 1.1 Estado real verificado

| Elemento | Valor | Fuente |
|---|---|---|
| Drupal core | 11.4.5 | `composer.lock` |
| PHP | 8.4 | `.ddev/config.yaml` |
| Symfony | 7.4.15 | `composer.lock` |
| Guzzle | 7.15.3 | `composer.lock` (vía `http_client` de core) |
| Base de datos | MariaDB 11.8 | `.ddev/config.yaml` |
| Servidor web | nginx-fpm | `.ddev/config.yaml` |
| Módulos contrib | Ninguno | `web/modules/` vacío |
| Módulos custom | Ninguno | `web/modules/custom/` no existe aún |
| Themes custom | Ninguno | `web/themes/` vacío |
| Recipes | Ninguna | `recipes/` vacío |

### 1.2 Conflictos identificados (§60.7)

**Ninguno.** El proyecto es *greenfield*: un codebase de `drupal/recommended-project`
sin instalar. §4 (no afectar el sitio existente) se cumple trivialmente hoy, pero la
disciplina de módulo autocontenido se mantiene íntegra porque el sitio sí crecerá.

### 1.3 Bloqueadores de entorno a resolver antes de la Fase 2

1. **Sitio no instalado.** `$databases = []` y `$settings['hash_salt'] = ''` en
   `web/sites/default/settings.php`.
2. **Drush ausente.** `vendor/bin/` no lo contiene. Necesario para `en`, `cex/cim`, `updb`.
3. **No es repositorio Git.** §62 exige poder revertir cada cambio. `git init` es
   prerrequisito, no opcional, para un proyecto de 16 fases con código de seguridad.

### 1.4 Servicios de core reutilizables identificados (§60.6)

El módulo se apoya en core y evita reimplementar:

| Necesidad | Servicio de core | Reemplaza a |
|---|---|---|
| Rate limiting (§44) | `flood` (`FloodInterface`) | Contador propio en BD |
| Concurrencia (§24) | `lock` (`LockBackendInterface`) | Flag manual de estado |
| Cache autorización (§14) | `cache.default` / bin propio | Cache propia |
| Anti-replay de token (§10) | `keyvalue.expirable` | Tabla de nonces propia |
| HTTP saliente | `http_client` (Guzzle) | SDK de terceros |
| Logging (§45) | `logger.factory` | `error_log()` |
| Secretos (§29) | `Settings::get()` + env vars | Config exportable |
| Escaping (§25) | `Xss::filter()` | Sanitizador propio |

---

## 2. ARQUITECTURA GENERAL (§60.1)

### 2.1 Vista de sistemas

```
┌──────────────────────────┐
│  WORDPRESS + LEARNDASH   │  Fuente de verdad de autorización (§6)
│  ┌────────────────────┐  │
│  │ mu-plugin          │  │  ① Emite JWT de identidad (corto, un solo uso)
│  │ salesbumm-sld      │  │  ② Responde ¿tiene acceso al curso? (HMAC)
│  └────────────────────┘  │
└───────────┬──────────────┘
            │ HTTPS
            ▼
┌──────────────────────────────────────────────────────┐
│  DRUPAL 11 — sales_leadership_diagnostic             │
│                                                      │
│  Identidad ──► Autorización ──► Sesión ──► Chat      │
│      │              │              │          │      │
│  externalauth   LearnDash      Entities   Conversation│
│                  Provider                   Service   │
└───────────────────────────┬──────────────────────────┘
                            │ HTTPS (solo backend)
                            ▼
                    ┌───────────────┐
                    │  OPENAI API   │  Motor de IA (§29)
                    └───────────────┘
```

### 2.2 Principio rector: identidad ≠ autorización

Esta es la decisión arquitectónica más importante del diseño.

- El **token JWT** prueba *quién es* el alumno. Se consume una sola vez, dura segundos.
- La **autorización** (¿tiene el curso?) se consulta a WordPress de forma **independiente**,
  se cachea con TTL, y se revalida.

**Por qué separarlas:** si el token cargara `has_access: true`, un alumno que compra,
entra, y cuya compra se reembolsa 10 minutos después seguiría autorizado toda su sesión.
Separándolas, WordPress permanece como fuente de verdad viva (§6) y el fail-closed (§13)
es implementable de verdad.

### 2.3 Capas

```
  PRESENTACIÓN     Controllers · Forms · Twig · JS · CSS
        │           (sin lógica de negocio — §Jarvis)
        ▼
  APLICACIÓN       ConversationService · DiagnosticSessionManager
        │           AuthorizationService · SsoService
        ▼
  DOMINIO          DTOs · Interfaces · Excepciones · Value Objects
        │           (sin dependencias de Drupal donde sea posible)
        ▼
  INFRAESTRUCTURA  LearnDashAccessProvider · OpenAIDiagnosticProvider
                   Repositories · Entities · Config
```

**Regla:** un Controller nunca hace HTTP, nunca construye un prompt, nunca toca la BD
directamente. Solo traduce petición → servicio → respuesta.

---

## 3. ESTRUCTURA DEL MÓDULO (§60.2, §46)

```
web/modules/custom/sales_leadership_diagnostic/
│
├── sales_leadership_diagnostic.info.yml
├── sales_leadership_diagnostic.module          # hooks mínimos (theme, cron)
├── sales_leadership_diagnostic.install         # schema tabla mensajes + requirements()
├── sales_leadership_diagnostic.services.yml
├── sales_leadership_diagnostic.routing.yml
├── sales_leadership_diagnostic.permissions.yml
├── sales_leadership_diagnostic.links.menu.yml
├── sales_leadership_diagnostic.links.task.yml
├── sales_leadership_diagnostic.libraries.yml
├── composer.json                               # deps propias del módulo (§51)
│
├── config/
│   ├── install/
│   │   ├── sales_leadership_diagnostic.settings.yml      # NO contiene secretos
│   │   └── sales_leadership_diagnostic.diagnostic.yml    # prompt + versión
│   └── schema/
│       └── sales_leadership_diagnostic.schema.yml
│
├── src/
│   ├── Controller/
│   │   ├── DashboardController.php          # /sales-diagnostic
│   │   ├── SsoController.php                # /sales-diagnostic/sso
│   │   ├── ChatController.php               # render del chat
│   │   ├── ConversationApiController.php    # POST mensaje (JSON)
│   │   └── ResultsController.php            # historial + detalle
│   │
│   ├── Form/
│   │   ├── SettingsForm.php                 # WordPress · OpenAI · Security
│   │   └── DiagnosticConfigForm.php         # prompt · instrucciones · versión
│   │
│   ├── Entity/
│   │   ├── DiagnosticSession.php            # entity id: sld_diagnostic_session
│   │   ├── DiagnosticResult.php             # entity id: sld_diagnostic_result
│   │   └── Handler/
│   │       ├── DiagnosticSessionAccessControlHandler.php
│   │       └── DiagnosticResultAccessControlHandler.php
│   │
│   ├── Repository/
│   │   └── DiagnosticMessageRepository.php  # tabla custom (§10 de este doc)
│   │
│   ├── Service/
│   │   ├── Authorization/
│   │   │   ├── CourseAccessProviderInterface.php
│   │   │   ├── LearnDashAccessProvider.php
│   │   │   ├── CachedCourseAccessProvider.php   # decorador de cache
│   │   │   └── DiagnosticAccessChecker.php      # orquesta la decisión
│   │   ├── WordPress/
│   │   │   ├── WordPressApiClient.php           # HTTP + firma HMAC
│   │   │   └── HmacSigner.php
│   │   ├── Security/
│   │   │   ├── SsoTokenValidator.php            # JWT: firma, exp, iss, aud
│   │   │   ├── ReplayGuard.php                  # jti, un solo uso
│   │   │   ├── UserProvisioner.php              # externalauth
│   │   │   ├── SecretsProvider.php              # Settings::get()
│   │   │   └── RateLimiter.php                  # wrapper de flood
│   │   ├── Diagnostic/
│   │   │   ├── DiagnosticSessionManager.php
│   │   │   ├── DiagnosticPromptManager.php      # §18
│   │   │   ├── DiagnosticContextBuilder.php     # §31
│   │   │   └── DiagnosticResponseValidator.php  # §32
│   │   ├── Conversation/
│   │   │   ├── ConversationService.php          # orquestador del turno
│   │   │   └── MarkdownRenderer.php             # commonmark + Xss (§25)
│   │   └── Engine/
│   │       ├── DiagnosticEngineInterface.php    # §28
│   │       └── OpenAIDiagnosticProvider.php
│   │
│   ├── DTO/
│   │   ├── DiagnosticContext.php
│   │   ├── DiagnosticTurn.php
│   │   ├── DiagnosticResultData.php
│   │   ├── ConversationMessage.php
│   │   └── AccessDecision.php
│   │
│   ├── Exception/
│   │   ├── DiagnosticException.php              # base
│   │   ├── AuthorizationException.php
│   │   ├── InvalidTokenException.php
│   │   ├── WordPressUnavailableException.php
│   │   ├── EngineException.php
│   │   └── InvalidEngineResponseException.php
│   │
│   ├── Access/
│   │   └── DiagnosticAccessCheck.php            # _custom_access de rutas
│   │
│   └── EventSubscriber/
│       └── DiagnosticExceptionSubscriber.php    # §58 — errores amables
│
├── templates/
│   ├── sld-dashboard.html.twig
│   ├── sld-chat.html.twig
│   └── sld-result.html.twig
│
├── css/
│   └── sld-chat.css
├── js/
│   └── sld-chat.js
│
└── tests/
    ├── src/Unit/
    └── src/Kernel/
```

### 3.1 Desviaciones respecto a §46 (justificadas)

| Cambio | Razón |
|---|---|
| `Service/OpenAI/` → `Service/Engine/` | El directorio no debe llevar el nombre del proveedor; §28 exige poder añadir `AnthropicDiagnosticProvider` sin renombrar la carpeta |
| Añadido `src/Access/` | Los access checks de ruta son un concern propio, no un "Security service" |
| Añadido `.install` y `.libraries.yml` | Necesarios: tabla custom de mensajes y assets del chat (§4 del prompt: usar libraries propias) |
| Añadido `composer.json` del módulo | §51: las dependencias deben declararse en el módulo, no en el root del proyecto |
| `Plugin/` omitido en MVP | No hay ningún punto de extensión que hoy justifique un plugin manager. Se añadirá cuando lleguen N agentes (§56) |

### 3.2 Restricción técnica: longitud de IDs de entidad

Drupal limita los *entity type ID* a **32 caracteres**.
`sales_leadership_diagnostic_session` = **34 caracteres → inválido.**

Por eso los IDs de entidad usan el prefijo corto `sld_`:

- `sld_diagnostic_session` (22) → tabla `sld_diagnostic_session`
- `sld_diagnostic_result` (21) → tabla `sld_diagnostic_result`
- tabla custom: `sld_diagnostic_message`

El *nombre del módulo* sigue siendo `sales_leadership_diagnostic` (sin límite).

---

## 4. ANÁLISIS DE DEPENDENCIAS (§60.3, §51)

### 4.1 Dependencias propuestas

| Paquete | Tipo | Justificación | Alternativa descartada |
|---|---|---|---|
| `drupal/externalauth` | Contrib | Mapeo WP user ↔ Drupal user. Provee tabla `authmap`, `loginRegister()`, y resuelve colisiones y condiciones de carrera en el primer login. Es la base de `cas` y `simplesamlphp_auth`; mantenido y compatible con D11. | Tabla propia: ~150 líneas frágiles, con race conditions reales en logins concurrentes |
| `firebase/php-jwt` **^7.1** | Composer | JWT firmado con `exp`, `iss`, `aud`, `jti` y *leeway* de reloj. Librería mínima y auditada. Su API recibe un objeto `Key` que fija el algoritmo, lo que previene ataques de confusión de algoritmo. | `drupal/jwt`: acopla al sistema de auth providers de Drupal, innecesario. HMAC casero: reinventar JWT mal |

> ⚠ **Restricción de versión — `firebase/php-jwt`.** Las versiones **6.10.0 a
> 6.11.1** están afectadas por el aviso de seguridad `PKSA-y2cr-5h3j-g3ys` y
> Composer bloquea su instalación. El módulo fija la línea **7.x**, verificada
> sin avisos. No degradar a 6.x ni añadir el aviso a la lista de excepciones
> de `audit`.
| `league/commonmark` | Composer | Renderizado seguro de Markdown del agente (§25). Estándar de facto en el ecosistema PHP. | Regex propias: vector de XSS garantizado |

### 4.2 Dependencias explícitamente NO añadidas

| Descartada | Motivo |
|---|---|
| **SDK oficial de OpenAI** | Solo usamos 1 endpoint. `http_client` de core (Guzzle 7.15) basta. Un SDK añade churn de versiones y acopla el módulo al proveedor, en contra de §28 |
| `drupal/key` | Aporta UI de gestión de claves, pero `Settings::get()` + variables de entorno cubre §29 y §49 sin contrib. Se puede añadir después sin refactor si el cliente lo pide |
| Cualquier módulo de chat/IA contrib | Ninguno encaja con la metodología cerrada del cliente (§15); adoptarlo obligaría a adaptar la lógica del cliente al módulo en vez de al revés |

**Total: 1 contrib + 2 librerías PHP.** Todo lo demás sale de core.

### 4.3 Dependencias de desarrollo

`drush/drush` (require-dev del proyecto, no del módulo).

---

## 5. MODELO DE SEGURIDAD (§60.4, §42)

### 5.1 Gestión de secretos (§29, §49)

**Tres secretos** en el sistema:

| Secreto | Uso | Ubicación |
|---|---|---|
| `sld_jwt_shared_secret` | Firma HS256 del token SSO | Env var → `Settings::get()` |
| `sld_wp_hmac_secret` | Firma de llamadas Drupal → WP | Env var → `Settings::get()` |
| `sld_openai_api_key` | Autenticación con OpenAI | Env var → `Settings::get()` |

**Reglas absolutas:**

- Nunca en `config/install/*.yml` (acabaría en Git).
- Nunca en un campo de formulario que los muestre. `SettingsForm` mostrará solo
  `✔ Configurado` / `✘ No configurado`, nunca el valor.
- Nunca en logs, ni truncados (§43).
- `hook_requirements()` avisará en `/admin/reports/status` si falta alguno.

**Por entorno (§49):** DDEV inyecta las de desarrollo vía `web_environment`;
staging y producción usan variables de entorno reales del servidor. Cada entorno
tiene su propio `Course ID`, sus propias claves y su propia base URL. Nunca se comparten.

### 5.2 Modelo de amenazas y mitigaciones

| Amenaza | Mitigación |
|---|---|
| **Acceso directo por URL** (§11) | Todas las rutas exigen `_custom_access` + permiso + usuario autenticado. Ningún parámetro `?email=` o `?user_id=` se lee jamás para autorizar |
| **IDOR** (§35, §42) | `AccessControlHandler` por entidad: `$entity->getOwnerId() === $account->id()`. El route parameter se resuelve a entidad y el handler decide. Un ID ajeno devuelve 403, no 404 con datos |
| **Replay del token SSO** | Claim `jti` consumido atómicamente vía `keyvalue.expirable` (`setWithExpireIfNotExists`). Segundo uso → rechazo |
| **Token robado / de larga vida** | TTL de 60–120 s. Suficiente para un redirect, inútil para un atacante posterior |
| **Compromiso de WordPress** | El secreto compartido permite forjar *identidad*, no *autorización*: Drupal revalida el acceso al curso contra WP de forma independiente. Además, TTL corto y `jti` limitan la ventana |
| **XSS vía respuesta de IA** (§25) | Markdown → `league/commonmark` → `Xss::filter()` con allowlist explícita. El JS nunca hace `innerHTML` con texto crudo del modelo |
| **CSRF** (§42) | La ruta JSON de mensajes usa `_csrf_token: 'TRUE'`; el token viaja por `drupalSettings` y se envía en cada POST |
| **Abuso / coste de OpenAI** (§44) | `FloodInterface`: N mensajes por usuario/ventana y N diagnósticos por usuario/día, ambos configurables |
| **Corrupción del contexto por envíos simultáneos** (§24) | `LockBackendInterface` con clave `sld_session:{id}`. Sin lock → HTTP 409. El bloqueo del botón en el frontend es UX, no control de seguridad |
| **Fuga de datos empresariales** (§43) | Los mensajes viven en tabla custom fuera de Entity API/Views/search. Nada de contenido de diagnóstico se indexa ni se navega desde el admin |
| **Filtración por logs** (§43, §45) | El logger recibe IDs y códigos de error, nunca prompts, nunca respuestas completas, nunca tokens ni claves |

### 5.3 Permisos (§40)

| Permiso | Destinatario | Otorga |
|---|---|---|
| `access sales leadership diagnostic` | Rol `sales_diagnostic_student` (creado por el módulo) | Dashboard, iniciar diagnóstico, chatear, ver **sus** resultados |
| `administer sales leadership diagnostic` | Administrador | Configuración, prompt, versión, límites |
| `view all diagnostic results` | Rol de soporte / staff | Ver resultados de cualquier alumno (marcado como *restrict access*) |

El permiso de alumno **no** implica ver resultados ajenos: la propiedad se verifica
siempre en el access handler, incluso para quien tenga el permiso de acceso.

### 5.4 Manejo de errores hacia el usuario (§58)

`DiagnosticExceptionSubscriber` intercepta toda `DiagnosticException`:

- **Al alumno:** un mensaje neutro — *"No hemos podido procesar tu solicitud en este
  momento. Por favor intenta nuevamente."*
- **Al log:** tipo de excepción, ID de sesión, código HTTP del upstream, duración.
  Nunca el payload, nunca el prompt, nunca la clave.

`cURL error 28` jamás llega al navegador.

---

## 6. FLUJO DE INTEGRACIÓN CON WORDPRESS (§60.5, §8, §9)

### 6.1 El mu-plugin de WordPress (decisión 2)

Un único plugin `salesbumm-sld` con **dos responsabilidades y nada más**:

> **Corrección aplicada durante la implementación — plugin normal, no mu-plugin.**
> Se entrega como plugin estándar en `wordpress-plugin/salesbumm-sld/`, porque es
> más fácil de instalar, actualizar y soportar, y aparece en la lista de plugins
> del cliente. Si se prefiere que no pueda desactivarse, se coloca en
> `mu-plugins/` con un cargador de una línea; el README lo documenta.

> **Trampa de caché evitada por diseño.** El token **no** se genera al pintar el
> botón sino cuando el alumno lo pulsa. Si el enlace lo llevara incrustado,
> cualquier caché de página —que casi todo WordPress en producción tiene— serviría
> a todos los visitantes el token del primer alumno que cargó la página: una
> suplantación de identidad servida por la propia infraestructura, y silenciosa. El
> botón apunta a `admin-post.php`, que WordPress nunca cachea.

**① Botón "Acceder al Diagnostic AI"** — genera el JWT y redirige:

```
https://drupal.salesbumm.com/sales-diagnostic/sso?token=<JWT>
```

**② Endpoint de autorización** — consumido solo por Drupal:

```
POST /wp-json/salesbumm-sld/v1/access
Headers:
  X-SLD-Timestamp: 1755792000
  X-SLD-Nonce:     <aleatorio>
  X-SLD-Signature: HMAC-SHA256(secret, timestamp + nonce + body)
Body:
  { "wp_user_id": 4821, "course_id": "1734" }

Respuesta 200:
  { "has_access": true, "wp_user_id": 4821, "course_id": "1734",
    "checked_at": "2026-08-21T18:03:11Z" }
```

**Por qué un endpoint propio y no la REST API de LearnDash** (§9):

- Devuelve un booleano, no el objeto completo del usuario ni su progreso → menos datos sensibles en tránsito.
- No requiere Application Passwords ni credenciales de administrador.
- Aísla a Drupal de la estructura interna de LearnDash: si el cliente migra de LMS,
  se reescribe el plugin de WP, no el módulo de Drupal.
- La ventana de timestamp (±300 s) más el nonce impiden replay de la propia llamada.

### 6.2 Contrato en Drupal

```php
interface CourseAccessProviderInterface {
  /**
   * @throws \Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException
   */
  public function checkAccess(string $externalUserId, string $courseId): AccessDecision;
}
```

`AccessDecision` es un DTO inmutable: `granted`, `checkedAt`, `source`
(`live` | `cache`), `courseId`.

> **Nota sobre §8:** el prompt sugiere `userHasAccess(): bool`. Devolvemos un DTO en
> lugar de un `bool` porque un booleano no distingue *"denegado"* de *"no se pudo
> comprobar"*, y esa distinción es exactamente lo que §13 (fail closed) necesita para
> ser implementable. El nombre de la interfaz y su rol se mantienen.

### 6.3 Estrategia de cache (§14) y fail-closed (§13)

```
                    ¿Hay cache?
                    ╱          ╲
                  SÍ            NO
                   │             │
              usar cache    consultar WP
                                 │
                        ┌────────┴────────┐
                     responde         no responde
                        │                 │
                   cachear +      ¿cache positiva válida?
                    decidir          ╱         ╲
                                   SÍ           NO
                                    │            │
                              PERMITIR      DENEGAR
                              (§13 lo         (fail
                              permite)        closed)
```

**Política de TTL diferenciada** — una decisión deliberada:

| Resultado | TTL | Razón |
|---|---|---|
| Autorizado | 15 min (configurable) | Reduce carga sobre WP sin alejarse de la fuente de verdad |
| Denegado | 60 s | Un alumno que acaba de comprar no debe esperar 15 minutos |

Clave de cache: `sld:authorization:{wp_user_id}:{course_id}` (§14).

**Cuándo se comprueba la autorización:**

| Momento | Comportamiento |
|---|---|
| Login SSO | Comprobación obligatoria. Sin acceso → 403, no se crea sesión |
| Iniciar diagnóstico | Comprobación obligatoria |
| Enviar mensaje en sesión activa | Cache o *grace period* de la sesión |
| Ver resultado histórico | Comprobación obligatoria |

> **Decisión explícita:** un corte de red en WordPress a mitad de un diagnóstico **no**
> interrumpe la conversación en curso. El alumno ya fue autorizado legítimamente al
> iniciarla; abortarla no aporta seguridad y destruye trabajo del usuario. Esto es
> precisamente la excepción que §13 contempla como *"autorización previamente validada,
> explícitamente diseñada"*.

---

## 7. FLUJO DE AUTENTICACIÓN — SSO (§60.6, §10)

### 7.1 Estructura del token

```json
{
  "iss": "https://salesbumm.com",
  "aud": "https://drupal.salesbumm.com",
  "sub": "4821",
  "jti": "01J8XW...",
  "iat": 1755792000,
  "exp": 1755792090,
  "email": "alumno@example.com",
  "name": "José Ramírez"
}
```

- **Algoritmo:** HS256 con secreto compartido. Simétrico porque ambos sistemas son del
  mismo propietario; no hay problema de distribución de claves públicas.
- **Longitud mínima del secreto: 32 caracteres.** `firebase/php-jwt` valida la
  longitud de la clave HMAC (`strlen($key) * 8 >= 256` para HS256) y lanza
  `DomainException` si es menor. Un secreto corto no produce un error de
  autenticación legible sino una excepción genérica en pleno login, muy difícil de
  diagnosticar. El plugin de WordPress lo comprueba y avisa antes de que ocurra.
- **TTL:** 90 s por defecto (configurable, tope 300 s). Solo tiene que sobrevivir a un redirect.
- **Leeway de reloj:** 30 s, porque WP y Drupal son máquinas distintas.

`email` y `name` se usan **solo para poblar el perfil**, jamás para autorizar (§11).
La identidad es `sub` (el WP user ID).

### 7.2 Secuencia de validación

```
GET /sales-diagnostic/sso?token=<JWT>
   │
   ├─► 1. Firma HS256 válida ................. no → 403 + log
   ├─► 2. exp / iat / leeway ................. no → 403 + log
   ├─► 3. iss e aud coinciden ................ no → 403 + log
   ├─► 4. jti no consumido (atómico) ......... no → 403 + log (posible ataque)
   ├─► 5. Rate limit por IP (flood) .......... no → 429
   │
   ├─► 6. AUTORIZACIÓN: ¿tiene el curso?
   │        └─ WordPress / LearnDash ......... no → 403 "sin acceso"
   │
   ├─► 7. Provisión de usuario (externalauth)
   │        authmap: provider='sld_wp', authname=sub
   │        ├─ existe  → cargar usuario
   │        └─ no existe → crear + asignar rol de alumno
   │
   ├─► 8. user_login_finalize() → sesión Drupal nativa
   │
   └─► 9. Redirect 302 → /sales-diagnostic
```

**Orden deliberado:** la autorización (6) ocurre **antes** de crear el usuario (7).
Nunca se provisiona una cuenta de Drupal para alguien que no tiene derecho de acceso.

### 7.3 Colisión de correo electrónico — decisión de seguridad

Drupal exige `mail` único. Si llega un token cuyo `email` ya pertenece a una cuenta
de Drupal **no mapeada** a ese `wp_user_id`:

> **No se vincula automáticamente.** Se deniega el acceso, se registra un warning y se
> notifica al administrador para resolución manual.

Vincular por email sería un vector de apropiación de cuentas: quien controle un buzón en
WordPress heredaría la cuenta de Drupal correspondiente — posiblemente la de un
administrador. §11 lo dice explícitamente: *no confiar únicamente en email*.

### 7.4 Ciclo de vida de la sesión

Sesión Drupal estándar, con `session_timeout` propio del módulo aplicado por el
access check. Cerrar sesión en Drupal no cierra la de WordPress y viceversa: son
sistemas independientes y el reingreso siempre pasa por un token nuevo.

---

## 8. FLUJO DE AUTORIZACIÓN (§60.7, §12)

```
Petición a una ruta protegida
        │
        ▼
DiagnosticAccessCheck (_custom_access)
        │
        ├─ ¿Usuario autenticado? ............... no → 403
        ├─ ¿Permiso 'access sales leadership
        │   diagnostic'? ....................... no → 403
        ├─ ¿Tiene authmap 'sld_wp'? ............ no → 403
        │      (una cuenta de Drupal creada a mano no entra por aquí)
        ├─ ¿DiagnosticAccessChecker concede? ... no → 403
        │      (cache → WordPress → fail closed)
        └─ ¿Es dueño de la entidad del path? ... no → 403
               (AccessControlHandler de la entidad)
                        │
                        ▼
                    PERMITIDO
```

Las cinco comprobaciones son acumulativas: fallar cualquiera deniega. No hay ruta que
salte la cadena.

---

## 9. ARQUITECTURA DEL CHAT (§60.8, §19–§27)

### 9.1 Anatomía de un turno

```
[Navegador] sld-chat.js
     │ POST /sales-diagnostic/session/{id}/message
     │ { message: "...", csrf_token: "..." }
     ▼
ConversationApiController          ── valida request, no más
     ▼
ConversationService                ── ORQUESTADOR DEL TURNO
     │
     ├─ 1. Adquirir lock sld_session:{id} ...... ocupado → 409
     ├─ 2. Rate limit (flood) .................. excedido → 429
     ├─ 3. Validar sesión: propiedad + estado
     ├─ 4. Persistir mensaje del usuario
     │
     ├─ 5. DiagnosticContextBuilder
     │        ├─ DiagnosticPromptManager (prompt + instrucciones + versión)
     │        └─ Historial de la sesión (acotado)
     │              → DiagnosticContext (DTO)
     │
     ├─ 6. DiagnosticEngineInterface::process($context)
     │        └─ OpenAIDiagnosticProvider → OpenAI API
     │
     ├─ 7. DiagnosticResponseValidator ......... inválido → 1 reintento → error amable
     ├─ 8. Persistir mensaje del asistente
     ├─ 9. Si status = completed → crear DiagnosticResult
     ├─ 10. MarkdownRenderer → HTML sanitizado
     └─ 11. Liberar lock
     ▼
JSON  { message_html, status, session_status, result_url? }
     ▼
[Navegador] pinta el mensaje + scroll + rehabilita el input
```

### 9.2 Decisiones de la capa de presentación

| Decisión | Elección | Razón |
|---|---|---|
| **Transporte** | `fetch()` a endpoint JSON de Drupal | §23. Ninguna llamada del navegador a OpenAI |
| **Streaming** | **No en el MVP** | SSE exige persistencia parcial, buffering de nginx y manejo de errores a mitad de stream. §24 solo pide un indicador de proceso. `DiagnosticEngineInterface` se diseña para admitir streaming después sin romper contratos |
| **Framework JS** | Vanilla JS sobre `Drupal.behaviors` | El chat son ~200 líneas. React/Vue añadiría build step, dependencias y peso sin beneficio |
| **Estado del chat** | El servidor es la fuente de verdad | §26. Recargar la página reconstruye la conversación desde la BD; el navegador nunca custodia el contexto |
| **Markdown** | Servidor: commonmark → `Xss::filter()` | §25. El cliente recibe HTML ya seguro; nunca interpreta Markdown del modelo |
| **Estilos** | CSS propio en libraries del módulo | §4. Ni una línea en el theme. Variables CSS para la identidad de Salesbumm cuando llegue |
| **Responsive** | CSS Grid + `dvh` | §21. Desktop, tablet, móvil, con el input fijo abajo |
| **Accesibilidad** | `aria-live="polite"` en el área de conversación | Los mensajes entrantes se anuncian a lectores de pantalla |

### 9.3 Doble envío (§24)

Dos capas, y solo una es control real:

- **Frontend:** botón deshabilitado + indicador *"Analizando…"*. Es **UX**.
- **Backend:** `LockBackendInterface`. Es el **control**. Sin él, dos pestañas abiertas
  corromperían el contexto y pagarían dos llamadas a OpenAI.

---

## 10. FLUJO DE INTEGRACIÓN CON OPENAI (§60.9, §28–§32)

### 10.1 Contrato

```php
interface DiagnosticEngineInterface {
  /**
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  public function process(DiagnosticContext $context): DiagnosticTurn;
}
```

`OpenAIDiagnosticProvider` es la única clase de todo el módulo que sabe que OpenAI existe.
Añadir `AnthropicDiagnosticProvider` (§28) será una clase nueva y una línea de
`services.yml` — sin tocar `ConversationService`.

### 10.2 Adaptación del GPT del cliente (§17, decisión 3)

El agente es prompt + instrucciones, sin archivos de knowledge. La adaptación es
puramente técnica (§17) y consiste en tres capas de mensaje:

```
┌─ system ─────────────────────────────────────────┐
│ [PROMPT DEL CLIENTE — literal, sin modificar]    │  ← §15: intocable
│ [INSTRUCCIONES DEL CLIENTE — literal]            │  ← §15: intocable
├──────────────────────────────────────────────────┤
│ [CONTRATO DE SALIDA — añadido técnico]           │  ← nuestro
│   Responde siempre con el JSON del esquema.      │
│   La prosa conversacional va en "message".       │
└──────────────────────────────────────────────────┘
┌─ historial de la conversación ───────────────────┐
│ user / assistant / user / assistant / …          │
└──────────────────────────────────────────────────┘
```

**La metodología, las preguntas y los criterios del cliente no se tocan** (§15, §55).
Lo único que añadimos es la envoltura que convierte su salida en algo que Drupal
puede validar y almacenar.

> **Riesgo declarado:** un prompt escrito para la interfaz de ChatGPT puede comportarse
> distinto vía API con un contrato JSON impuesto. Debe validarse con el prompt real en la
> Fase 11, antes de dar por buena la integración. Si el contrato JSON degrada la calidad de
> las respuestas, la alternativa es texto libre + un turno separado de extracción estructurada.

### 10.3 Respuestas estructuradas (§32)

Turno intermedio:

```json
{ "type": "diagnostic_response", "message": "…",
  "status": "in_progress", "next_step": "…", "result": null }
```

Turno final:

```json
{ "type": "diagnostic_result", "status": "completed",
  "summary": "…", "score": 0, "strengths": [],
  "opportunities": [], "recommendations": [], "priority_actions": [] }
```

Se usa *structured outputs* con JSON Schema estricto. **Aun así**, `DiagnosticResponseValidator`
revalida en servidor antes de persistir (§32): la garantía del proveedor no sustituye a
la validación propia. Estructura definitiva sujeta a la metodología real del cliente.

### 10.4 Configuración y resiliencia

| Parámetro | Origen | Nota |
|---|---|---|
| Modelo | Configuración admin | §30 — nunca hardcodeado |
| Timeout | Configuración admin | Por defecto 60 s; el turno final puede ser más lento |
| Reintentos | Configuración admin | Solo en errores transitorios (5xx, timeout) con backoff. **Nunca** en 4xx |
| API key | `Settings::get()` | §29 — jamás en config, jamás en el frontend |

### 10.5 Control del contexto (§31)

- Se envía únicamente lo necesario: prompt, instrucciones, historial de la sesión. Ningún
  dato de perfil, ningún ID interno, ningún metadato de Drupal.
- **Tope de turnos configurable.** Un diagnóstico tiene un final; una conversación que
  supera el tope se cierra con `failed` en lugar de crecer indefinidamente en coste y latencia.
- El coste crece cuadráticamente con la longitud de la conversación (cada turno reenvía todo
  el historial). Es esperado y acotado por el tope de turnos.

---

## 11. MODELO DE DATOS (§60.10, §33, §34, §41)

### 11.1 Decisión de almacenamiento y su justificación (§41 exige justificarla)

**Estrategia híbrida** — permitida explícitamente por §41 ("Combinación"):

| Objeto | Almacenamiento | Justificación |
|---|---|---|
| **DiagnosticSession** | Content Entity | Necesita ownership nativo (`EntityOwnerInterface`), access handler, exposición en Views para el admin, y ciclo de vida con estados. Volumen bajo (1–5 por alumno) |
| **DiagnosticResult** | Content Entity | Es un objeto enrutable (`/sales-diagnostic/results/{id}`) con control de acceso propio (§35) e historial (§36). Volumen bajo (1 por sesión) |
| **DiagnosticMessage** | **Tabla custom + Repository** | Ver abajo |

**Por qué los mensajes NO son entidades** — cuatro razones, en orden de peso:

1. **Privacidad (§43).** Como entidad, el contenido del diagnóstico quedaría expuesto a
   Views, al índice de búsqueda, a `entity.query` genérica y a cualquier módulo que
   itere entidades. Estos mensajes contienen información empresarial sensible. Fuera de
   Entity API, la única forma de leerlos es el repositorio del módulo.
2. **Seguridad.** Sin entidad no hay ruta canónica, no hay ID enumerable, no hay
   superficie IDOR propia. El acceso pasa siempre por la sesión, que sí tiene handler.
3. **Rendimiento.** Cada turno del chat lee el historial completo. 40 `entity_load` con
   sus cache tags e invocaciones de hook, contra un `SELECT` indexado, en la ruta caliente.
4. **No necesitan nada de Entity API.** Son *append-only*, se leen siempre como conjunto
   ordenado por sesión, y nunca se editan, traducen, versionan ni referencian.

**Contrapartida aceptada:** escribimos el schema y los `hook_update_N` a mano, y no hay
Views sobre mensajes. Lo segundo es una característica, no una carencia (razón 1).

### 11.2 `sld_diagnostic_session` (Content Entity)

| Campo | Tipo | Notas |
|---|---|---|
| `id` | serial | PK |
| `uuid` | uuid | |
| `uid` | entity_reference → user | **Dueño** (§35) |
| `wp_user_id` | string | Trazabilidad hacia WordPress (§33) |
| `course_id` | string | Curso que autorizó (§33) |
| `status` | list_string | `draft` · `in_progress` · `processing` · `completed` · `failed` (§33) |
| `diagnostic_version` | string | §57 |
| `prompt_snapshot` | text_long | Prompt exacto usado — ver 11.5 |
| `prompt_hash` | string | SHA-256 del snapshot, detecta deriva |
| `turn_count` | integer | Control del tope de turnos |
| `started_at` | timestamp | |
| `completed_at` | timestamp | |
| `created` / `changed` | timestamp | |

### 11.3 `sld_diagnostic_message` (tabla custom)

```
sld_diagnostic_message
├── id            SERIAL      PK
├── session_id    INT         NOT NULL, FK lógica → sld_diagnostic_session.id
├── role          VARCHAR(16) 'user' | 'assistant'
├── content       LONGTEXT    contenido crudo (Markdown del agente / texto del alumno)
├── payload       LONGTEXT    JSON completo del turno estructurado (nullable)
├── sequence      INT         orden dentro de la sesión
└── created       INT         timestamp

ÍNDICES
  idx_session_sequence (session_id, sequence)   ← lectura del historial
  idx_session_created  (session_id, created)
```

Borrado en cascada gestionado explícitamente en `hook_ENTITY_TYPE_delete()` de la
sesión: al borrar una sesión desaparecen sus mensajes (§43, minimización de datos).

### 11.4 `sld_diagnostic_result` (Content Entity)

| Campo | Tipo | Notas |
|---|---|---|
| `id` | serial | PK |
| `uid` | entity_reference → user | **Dueño** (§35) |
| `session_id` | entity_reference → sld_diagnostic_session | 1:1 en el MVP |
| `diagnostic_version` | string | §57 |
| `summary` | text_long | |
| `score` | integer | nullable — depende de la metodología del cliente |
| `payload` | text_long (JSON) | Estructura completa validada (§32) |
| `created` | timestamp | |

`payload` guarda la estructura íntegra en JSON en vez de desplegar `strengths`,
`opportunities`, etc. como campos multivalor: la estructura definitiva depende de la
metodología del cliente (§32) y aún no está entregada. Cuando se cierre, los campos
que necesiten consulta o filtrado se promueven a campos reales mediante
`hook_update_N` — sin perder datos.

### 11.5 Versionado y trazabilidad (§57)

El problema: si un administrador edita el prompt mientras hay diagnósticos en curso,
¿con qué prompt se generó cada resultado?

**Solución: snapshot inmutable.** Al crear la sesión se congela el prompt resuelto en
`prompt_snapshot` junto con `diagnostic_version` y `prompt_hash`.

- Una sesión en curso jamás cambia de prompt a mitad de camino.
- Un resultado de hace seis meses es siempre reproducible con exactitud.
- Coste: unos pocos KB por sesión. Irrelevante frente a la trazabilidad que garantiza.

### 11.6 Relaciones

```
user (Drupal)
  │  authmap['sld_wp'] ←→ wp_user_id (WordPress)
  │
  └──1:N── sld_diagnostic_session
              ├──1:N── sld_diagnostic_message   (tabla custom)
              └──1:1── sld_diagnostic_result
```

---

## 12. RUTAS (§38)

| Ruta | Método | Controller | Protección |
|---|---|---|---|
| `/sales-diagnostic/sso` | GET | `SsoController::login` | Pública por diseño; el JWT **es** la autenticación. Rate-limited por IP |
| `/sales-diagnostic` | GET | `DashboardController::view` | permiso + access check |
| `/sales-diagnostic/start` | POST | `DashboardController::start` | permiso + access check + CSRF + flood |
| `/sales-diagnostic/session/{sld_diagnostic_session}` | GET | `ChatController::view` | + ownership |
| `/sales-diagnostic/session/{sld_diagnostic_session}/message` | POST | `ConversationApiController::send` | + ownership + CSRF + flood + lock |
| `/sales-diagnostic/results` | GET | `ResultsController::history` | permiso + access check |
| `/sales-diagnostic/results/{sld_diagnostic_result}` | GET | `ResultsController::view` | + ownership |
| `/admin/config/salesbumm/diagnostic` | GET/POST | `SettingsForm` | `administer sales leadership diagnostic` |
| `/admin/config/salesbumm/diagnostic/agent` | GET/POST | `DiagnosticConfigForm` | `administer sales leadership diagnostic` |

`{sld_diagnostic_session}` se declara como *entity parameter*: Drupal resuelve el ID a
entidad y aplica su `AccessControlHandler` **antes** de entrar al controller. La
protección IDOR ocurre en el routing, no en el código del controller.

> **`/sales-diagnostic/session/{id}/complete` (§38)** no se implementa como ruta. El
> cierre del diagnóstico lo determina el agente devolviendo `status: "completed"`, no una
> acción del usuario. Exponer un endpoint que marque una sesión como completada permitiría
> a un alumno cerrar su diagnóstico sin haberlo hecho, y produciría resultados vacíos.
> El estado lo controla el motor, no el navegador.

---

## 13. CONFIGURACIÓN ADMINISTRATIVA (§39)

**`SettingsForm`** — `/admin/config/salesbumm/diagnostic`

| Sección | Campos |
|---|---|
| **WordPress** | API Base URL · Course/Product ID · Timeout · TTL cache autorizado · TTL cache denegado · *estado del secreto HMAC* (solo lectura) |
| **OpenAI** | Modelo · Timeout · Nº de reintentos · *estado de la API key* (solo lectura) |
| **Seguridad** | Mensajes por ventana · Ventana (s) · Diagnósticos máx./día · Tope de turnos · Timeout de sesión · TTL del token SSO · *estado del secreto JWT* |

**`DiagnosticConfigForm`** — `/admin/config/salesbumm/diagnostic/agent`

| Campo | Notas |
|---|---|
| Versión del diagnóstico | §57. Editarla es un acto consciente |
| Prompt principal | Textarea. Contenido del cliente (§15) |
| Instrucciones | Textarea. Contenido del cliente (§15) |
| Contrato de salida | Textarea. Nuestro añadido técnico, editable por si hay que ajustarlo |

Al guardar cambios en el prompt, el formulario advertirá: *"Las sesiones en curso
conservan la versión con la que empezaron."*

**Ningún campo de secretos es editable ni visible.** Se configuran por variable de
entorno (§29, §5.1 de este documento).

---

## 14. PLAN DE IMPLEMENTACIÓN (§60.11, §61)

### Fase 0 — Preparación del entorno *(nueva, prerrequisito)*

`git init` + `.gitignore` de Drupal · instalar Drush · instalar el sitio en DDEV ·
`hash_salt` · variables de entorno de desarrollo.
**Entregable:** entorno funcional y versionado.

### Fase 1 — Esqueleto del módulo ✅

`.info.yml`, `.services.yml`, permisos, rol de alumno, config schema, `composer.json`,
`hook_runtime_requirements()`. Instalable y desinstalable limpiamente.
**Entregable:** el módulo se activa sin errores y no hace nada todavía.

> **Corrección aplicada durante la implementación — el rol de alumno.** Declararlo
> en `config/install/` deja el módulo **imposible de reinstalar**: al desinstalar,
> `Role::onDependencyRemoval()` conserva el rol sin permisos para no destruir las
> asignaciones a usuarios, y la instalación siguiente aborta con
> `PreExistingConfigException`, en contra de §3. El rol se crea en `hook_install()`
> de forma idempotente (repara un rol huérfano en lugar de fallar) y
> `hook_uninstall()` solo lo elimina si ningún usuario lo tiene asignado.

> **Nota de API.** Drupal 11.1+ sustituye `hook_requirements()` por
> `hook_runtime_requirements()` y las constantes `REQUIREMENT_*` por el enum
> `RequirementSeverity`. Los hooks del módulo usan la forma OOP con el atributo
> `#[Hook]` en `src/Hook/`. Los hooks de instalación (`hook_install`,
> `hook_uninstall`, `hook_schema`, `hook_update_N`) siguen siendo procedurales
> en el archivo `.install`: el sistema OOP no los cubre.

### Fase 2 — Configuración

`SettingsForm`, `DiagnosticConfigForm`, `SecretsProvider`, config de instalación.
**Entregable:** administrable de punta a punta.

### Fase 3 — WordPress API

`WordPressApiClient`, `HmacSigner`, `CourseAccessProviderInterface`,
`LearnDashAccessProvider`, excepciones. **Incluye el mu-plugin de WordPress.**
**Entregable:** Drupal pregunta a WP y obtiene respuesta firmada.

### Fase 4 — Autorización

`CachedCourseAccessProvider`, `DiagnosticAccessChecker`, fail-closed, TTL diferenciado.
**Entregable:** decisión de acceso correcta, cacheada y resistente a caídas de WP.

### Fase 5 — SSO

`SsoTokenValidator`, `ReplayGuard`, `UserProvisioner`, `SsoController`, `DiagnosticAccessCheck`.
**Entregable:** un alumno entra desde WordPress y aterriza autenticado en Drupal.

### Fase 6 — Modelo de datos

Entidades `sld_diagnostic_session` y `sld_diagnostic_result`, access handlers,
tabla de mensajes, `DiagnosticMessageRepository`, `hook_schema()`.
**Entregable:** persistencia completa con ownership.

### Fase 7 — Dashboard

`DashboardController`, plantilla, historial vacío, botón de inicio.
**Entregable:** el alumno ve su panel (§37).

### Fase 8 — Chat UI

Plantilla, CSS responsive, JS, libraries, estados visuales, accesibilidad.
**Entregable:** la interfaz conversacional, todavía sin IA detrás.

### Fase 9 — Conversation Service

`ConversationService`, `ConversationApiController`, lock, flood, CSRF, persistencia de turnos.
**Entregable:** el chat funciona con un motor simulado (mock).

### Fase 10 — OpenAI Provider

`DiagnosticEngineInterface`, `OpenAIDiagnosticProvider`, structured outputs, reintentos,
`DiagnosticResponseValidator`.
**Entregable:** IA real conectada.

### Fase 11 — Prompt, instrucciones y metodología ⚠️

`DiagnosticPromptManager`, `DiagnosticContextBuilder`, snapshot y versionado, y
**validación del prompt real del cliente contra el contrato JSON**.
**Entregable:** el agente de Salesbumm operando dentro de Drupal.
**Bloqueada** hasta que el cliente entregue los materiales (§53).

### Fase 12 — Resultados e historial

`DiagnosticResult`, `ResultsController`, plantilla de resultado, historial (§36).
**Entregable:** el ciclo completo cierra.

### Fase 13 — Seguridad y rate limiting

Auditoría de la cadena de acceso, `RateLimiter`, `DiagnosticExceptionSubscriber`,
revisión de logs, `MarkdownRenderer` endurecido.
**Entregable:** módulo apto para revisión de seguridad.

### Fase 14 — Testing (§48)

Unit y Kernel: autorización, token, chat, diagnóstico, seguridad. OpenAI **siempre mockeado**.
**Entregable:** suite verde.

### Fase 15 — Staging · Fase 16 — Producción

Config por entorno (§49), secretos separados, verificación del flujo real de compra,
smoke test del criterio de éxito (§63).

### 14.1 Camino crítico

```
Fase 0 → 1 → 2 → 3 → 4 → 5 ──┐
                             ├─► 9 → 10 → 11 → 12 → 13 → 14 → 15 → 16
         Fase 6 → 7 → 8 ─────┘
```

Las fases 6–8 (datos, dashboard, UI) no dependen de WordPress y pueden avanzar en
paralelo a 3–5. **La fase 11 es la única bloqueada por el cliente.**

---

## 15. RIESGOS

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | El prompt del cliente se comporta distinto vía API con contrato JSON | **Alta** | Alto | Validar en Fase 11 con el prompt real. Plan B: texto libre + turno de extracción estructurada |
| R2 | El cliente entrega los materiales tarde (§53) | Media | Alto | Fases 1–10 y 12–13 no dependen de ellos. La 11 se aísla al final del camino crítico |
| R3 | Compromiso de WordPress → identidad forjada | Baja | Alto | TTL de 90 s, `jti` de un solo uso, autorización revalidada de forma independiente |
| R4 | Coste de OpenAI mayor del previsto | Media | Medio | Tope de turnos, flood por usuario/día, modelo configurable. Medir en staging |
| R5 | Latencia del turno final degrada la UX | Media | Medio | Estado `processing` + indicador. Si supera lo aceptable, mover a Queue Worker en Fase 12 |
| R6 | La estructura de resultados del cliente no encaja con §32 | Media | Bajo | `payload` JSON absorbe cualquier forma; los campos consultables se promueven después |
| R7 | Colisión de correos WP ↔ Drupal | Baja | Medio | Fail-closed con aviso al administrador (§7.3). Nunca vinculación automática |
| R8 | LearnDash cambia su API | Baja | Bajo | El contrato vive en el mu-plugin; Drupal no conoce LearnDash |

---

## 16. LO QUE ESTE MÓDULO NO HARÁ (§52, §55)

Sin ecommerce. Sin duplicar LearnDash. Sin tocar la BD de WordPress. Sin almacenar
contraseñas de WordPress. Sin exponer la API key. Sin hardcodear Course ID, secretos
ni prompts. Sin confiar en parámetros GET para autorizar. Sin llamadas del frontend a
OpenAI. Sin crear un agente nuevo, una metodología nueva ni preguntas nuevas. Sin
modificar core, el theme ni módulos de terceros.

---

## 17. CRITERIOS DE ACEPTACIÓN (§63)

- [ ] Un alumno con el curso entra desde WordPress y llega autenticado a Drupal.
- [ ] Un alumno **sin** el curso recibe 403.
- [ ] Un token reutilizado es rechazado.
- [ ] Un token expirado es rechazado.
- [ ] Con WordPress caído y sin cache, no se concede acceso nuevo.
- [ ] El alumno completa una conversación y obtiene un resultado.
- [ ] El resultado queda en su historial con su versión de diagnóstico.
- [ ] El alumno A no puede ver la sesión ni el resultado del alumno B (403).
- [ ] Entrar directo a `/sales-diagnostic` sin token válido no concede acceso.
- [ ] Ningún error técnico se muestra al alumno.
- [ ] Ningún secreto aparece en logs, config exportada ni en el frontend.
- [ ] El módulo se desinstala sin dejar residuos ni romper el sitio.

---

## 18. PENDIENTE DEL CLIENTE (§53)

| Elemento | Bloquea |
|---|---|
| URL base de WordPress + acceso para instalar el mu-plugin | Fase 3 |
| Course/Product ID de LearnDash | Fase 3 |
| Prompt, instrucciones y metodología del agente | **Fase 11** |
| Estructura esperada de resultados y criterios de evaluación | Fase 11 |
| API key de OpenAI y modelo deseado | Fase 10 |
| Identidad visual de Salesbumm (colores, tipografía, logo) | Fase 8 (refinamiento) |

---

## 19. APROBACIÓN

Conforme a §60, **no se creará ningún archivo del módulo** hasta que esta propuesta
sea aprobada.

Puntos que requieren confirmación explícita:

1. **Mensajes en tabla custom** en lugar de Content Entity (§11.1).
2. **Sin streaming** en el MVP (§9.2).
3. **`/complete` no se implementa como ruta** (§12).
4. **Colisión de email → fail closed** con resolución manual (§7.3).
5. **Tres dependencias**: `externalauth`, `firebase/php-jwt`, `league/commonmark` (§4.1).
6. **Fase 0** (git, Drush, instalación del sitio) como prerrequisito.
