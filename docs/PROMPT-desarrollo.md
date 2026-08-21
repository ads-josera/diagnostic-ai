PROMPT MAESTRO
SALES LEADERSHIP DIAGNOSTIC AI
MÓDULO CUSTOM PARA DRUPAL 11.x

============================================================
1. ROL
============================================================

Usa la Skill Jarvis que actúa como Senior Drupal 11 Architect + PHP 8.3/8.4 Developer
+ Symfony 7 Developer + API Integration Architect
+ AI Integration Engineer + Security Engineer
+ UX/UI Engineer especializado en interfaces conversacionales.

Tu responsabilidad es diseñar y desarrollar un módulo custom,
profesional, seguro, desacoplado, mantenible y escalable para
Drupal 11.x denominado:

sales_leadership_diagnostic

El módulo implementará la funcionalidad de:

Sales Leadership Diagnostic AI


============================================================
2. OBJETIVO PRINCIPAL
============================================================

Desarrollar un módulo custom para Drupal 11 que permita integrar
dentro de un sitio Drupal existente una experiencia privada de
diagnóstico basada en inteligencia artificial.

El módulo deberá:

- Identificar al alumno.
- Validar su autorización contra un WordPress externo con LearnDash.
- Determinar si tiene acceso al curso/producto específico que
  habilita el diagnóstico.
- Permitir el acceso únicamente a usuarios autorizados.
- Ejecutar el diagnóstico mediante OpenAI API.
- Integrar el agente, prompt, instrucciones y metodología
  proporcionados por el cliente.
- Proporcionar una interfaz conversacional tipo asistente de IA.
- Gestionar sesiones de diagnóstico.
- Guardar las respuestas.
- Procesar los resultados.
- Mostrar los resultados al alumno.
- Mantener un historial básico.
- Proporcionar configuración administrativa.
- Mantener una arquitectura preparada para futuras ampliaciones.


============================================================
3. REGLA ARQUITECTÓNICA FUNDAMENTAL
============================================================

TODO lo relacionado con este proyecto deberá implementarse dentro
del módulo custom:

sales_leadership_diagnostic

NO desarrollar esta funcionalidad directamente dentro de:

- Drupal Core.
- El theme.
- Otros módulos existentes.
- Código PHP suelto.
- Templates globales.
- Modificaciones directas de módulos contrib.

El módulo debe ser independiente y poder:

- Instalarse.
- Activarse.
- Desactivarse.
- Actualizarse.
- Eliminarse.

sin afectar el funcionamiento general de Drupal.


============================================================
4. REGLA DE NO AFECTAR EL SITIO EXISTENTE
============================================================

Antes de modificar cualquier cosa:

1. Analizar la instalación Drupal existente.
2. Identificar módulos instalados.
3. Identificar tema activo.
4. Identificar configuración relevante.
5. Identificar integraciones existentes.
6. Identificar posibles conflictos.
7. Identificar servicios reutilizables.

El módulo deberá evitar modificar funcionalidades existentes.

NO modificar Drupal Core.

NO modificar módulos existentes salvo que sea estrictamente
necesario y exista autorización explícita.

NO modificar el theme para implementar lógica de negocio.

Cuando sea necesario agregar CSS/JS, utilizar las libraries
propias del módulo.


============================================================
5. ARQUITECTURA GENERAL
============================================================

La solución tendrá tres sistemas principales:

WORDPRESS + LEARNDASH
        |
        | API / autenticación
        v
DRUPAL 11
        |
        | OpenAI API
        v
OPENAI

Drupal será la plataforma donde vivirá la experiencia
Sales Leadership Diagnostic AI.

El módulo:

sales_leadership_diagnostic

será responsable de toda la lógica específica.


============================================================
6. RESPONSABILIDAD DE WORDPRESS
============================================================

WordPress + LearnDash será la fuente de verdad para determinar
el derecho de acceso del alumno.

Drupal NO administrará:

- Compras.
- Pagos.
- Productos.
- Inscripciones.
- Checkout.
- Membresías.

Drupal únicamente consultará WordPress para determinar:

¿Este usuario tiene acceso al curso/producto específico que
habilita Sales Leadership Diagnostic AI?


============================================================
7. CURSO/PRODUCTO AUTORIZADOR
============================================================

El identificador del curso/producto que concede acceso deberá
ser configurable.

NO hardcodear el ID dentro del código.

Crear una configuración administrativa para:

- WordPress API Base URL.
- Course/Product ID.
- Credenciales necesarias.
- Timeout.
- Cache TTL.

El administrador deberá poder modificar el Course/Product ID
sin modificar código.


============================================================
8. INTEGRACIÓN WORDPRESS / LEARNDASH
============================================================

Crear una capa desacoplada para la integración.

Utilizar una interfaz similar a:

interface CourseAccessProviderInterface
{
    public function userHasAccess(
        string $externalUserId,
        string $courseId
    ): bool;
}

Crear una implementación:

LearnDashAccessProvider

Responsabilidades:

- Comunicarse con WordPress.
- Consultar LearnDash.
- Validar inscripción.
- Validar el curso.
- Devolver una respuesta normalizada.

NO colocar llamadas HTTP directamente dentro de Controllers.

Utilizar:

- Dependency Injection.
- Services.
- Interfaces.
- HTTP Client.
- Configuración.
- Excepciones controladas.


============================================================
9. WORDPRESS COMO SERVICIO EXTERNO
============================================================

Drupal debe tratar WordPress como un sistema externo.

NO:

- Acceder directamente a su base de datos.
- Compartir credenciales de administrador.
- Utilizar scraping.
- Copiar usuarios masivamente.
- Almacenar contraseñas de WordPress.

La comunicación será mediante API segura sobre HTTPS.


============================================================
10. AUTENTICACIÓN / SSO
============================================================

La arquitectura deberá permitir que el alumno llegue desde
WordPress hacia Drupal sin tener que mantener una segunda
contraseña.

Flujo preferido:

WordPress
   |
Usuario autenticado
   |
"Acceder al Diagnostic AI"
   |
Token temporal seguro
   |
Drupal
   |
Validación
   |
Consulta WordPress
   |
Autorización
   |
Sesión Drupal

Puede utilizarse JWT u otro mecanismo seguro equivalente.

El token deberá ser:

- Firmado.
- Temporal.
- Con expiración.
- Con issuer.
- Con audience.
- Con protección contra replay cuando corresponda.


============================================================
11. ACCESO DIRECTO A DRUPAL
============================================================

El módulo deberá impedir que una persona pueda obtener acceso
únicamente entrando directamente a una URL del Drupal.

No confiar en:

?email=usuario@example.com

No confiar en:

?user_id=123

No confiar únicamente en información enviada desde el navegador.

El acceso deberá depender de una autenticación y autorización
válidas.


============================================================
12. AUTORIZACIÓN
============================================================

La autorización seguirá este flujo:

Usuario
   |
Identidad válida
   |
Token válido
   |
Consulta WordPress
   |
LearnDash
   |
¿Tiene curso autorizado?

SÍ
 -> permitir acceso.

NO
 -> denegar acceso.


============================================================
13. FAIL CLOSED
============================================================

Si WordPress no responde:

- No asumir que el usuario está autorizado.
- No conceder nuevo acceso automáticamente.

Por defecto:

FAIL CLOSED

Es decir:

No se puede validar
       |
       v
No conceder nuevo acceso.

Podrá utilizarse una autorización previamente validada mediante
cache durante un periodo configurable, siempre que esto sea
seguro y esté explícitamente diseñado.


============================================================
14. CACHE DE AUTORIZACIÓN
============================================================

No realizar consultas innecesarias a WordPress en cada request.

Utilizar cache de Drupal.

Ejemplo:

authorization:{wordpress_user_id}:{course_id}

TTL configurable.

Valor inicial sugerido:

5-15 minutos.

WordPress continuará siendo la fuente de verdad.


============================================================
15. AGENTE DE IA — RESPONSABILIDAD DEL CLIENTE
============================================================

MUY IMPORTANTE.

El cliente ya cuenta con el agente desarrollado y probado.

El cliente proporcionará:

- Agente de IA.
- Prompt principal.
- Instrucciones.
- Metodología.
- Preguntas.
- Secuencia del diagnóstico.
- Criterios de evaluación.
- Reglas de análisis.
- Criterios para generar resultados.
- Recomendaciones.
- Materiales de conocimiento.

NO desarrollar un agente nuevo.

NO crear una metodología nueva.

NO inventar preguntas.

NO modificar la lógica de negocio del cliente.

El trabajo consiste en integrar técnicamente estos elementos
dentro del módulo Drupal.


============================================================
16. INTEGRACIÓN DEL AGENTE
============================================================

El trabajo contempla:

1. Incorporar el prompt proporcionado.
2. Incorporar las instrucciones proporcionadas.
3. Incorporar la metodología proporcionada.
4. Configurar OpenAI API.
5. Enviar las respuestas del usuario.
6. Mantener el contexto de la conversación.
7. Procesar la respuesta.
8. Validar la respuesta.
9. Generar el resultado.
10. Guardar el resultado.

La metodología y lógica de negocio pertenecen al cliente.


============================================================
17. ADAPTACIÓN DE UN GPT EXISTENTE
============================================================

Si el agente actual del cliente está construido como un GPT
personalizado dentro de ChatGPT, NO asumir que puede incrustarse
directamente dentro de Drupal.

Deberá realizarse la adaptación técnica necesaria para utilizar
su:

- Prompt.
- Instrucciones.
- Metodología.
- Contexto.
- Knowledge/materiales.
- Formato de salida.

mediante OpenAI API.

La adaptación es técnica.

NO implica rediseñar la metodología del cliente.


============================================================
18. GESTIÓN DEL PROMPT
============================================================

El prompt no deberá quedar mezclado arbitrariamente dentro de
Controllers o lógica de negocio.

Crear una capa similar a:

DiagnosticPromptManager

Responsable de gestionar:

- Prompt.
- Instrucciones.
- Versión.
- Variables dinámicas.
- Contexto.
- Configuración.

Cada diagnóstico deberá registrar la versión utilizada.

Ejemplo:

Diagnostic Version:

1.0
1.1
2.0


============================================================
19. INTERFAZ CONVERSACIONAL
============================================================

MUY IMPORTANTE.

La interacción del alumno con el Sales Leadership Diagnostic AI
deberá realizarse mediante una interfaz conversacional moderna,
inspirada en la experiencia de uso de aplicaciones de asistentes
de IA como ChatGPT.

NO implementar el diagnóstico como un formulario tradicional
de preguntas/respuestas salvo que la metodología proporcionada
por el cliente requiera explícitamente algún componente específico.

La experiencia principal deberá ser un CHAT.


============================================================
20. EXPERIENCIA DEL CHAT
============================================================

El usuario deberá entrar a una vista de conversación donde pueda:

1. Ver el mensaje inicial del agente.
2. Leer las preguntas o mensajes del agente.
3. Escribir su respuesta.
4. Enviar la respuesta.
5. Ver el procesamiento de la IA.
6. Recibir la siguiente respuesta.
7. Continuar la conversación.
8. Completar el diagnóstico.
9. Recibir el resultado final.


============================================================
21. DISEÑO DE LA INTERFAZ CHAT
============================================================

La interfaz deberá contemplar:

- Header del Diagnostic AI.
- Área principal de conversación.
- Mensajes del agente.
- Mensajes del alumno.
- Diferenciación visual clara entre ambos.
- Campo de entrada de texto.
- Botón Enviar.
- Indicador de procesamiento.
- Scroll automático.
- Persistencia de conversación.
- Estado de sesión.
- Diseño responsive.
- Desktop.
- Tablet.
- Mobile.

La interfaz deberá sentirse moderna y limpia.

Referencia conceptual:

ChatGPT / asistentes conversacionales modernos.

NO copiar visualmente la interfaz de ChatGPT.

La implementación deberá utilizar la identidad visual de Salesbumm
cuando ésta sea proporcionada.


============================================================
22. EJEMPLO VISUAL CONCEPTUAL
============================================================

La experiencia podrá seguir conceptualmente:

------------------------------------------------------

Sales Leadership Diagnostic AI

🤖 Diagnostic AI

Hola José.

Comenzaremos tu diagnóstico de liderazgo comercial.

Para comenzar, cuéntame cómo describirías actualmente
la estructura de tu equipo comercial.

                         ┌────────────────────────────┐
                         │ Actualmente contamos con... │
                         └────────────────────────────┘

🤖 Gracias.

Ahora me gustaría conocer...

------------------------------------------------------

Es decir:

AGENTE
  |
MENSAJE
  |
USUARIO
  |
RESPUESTA
  |
AGENTE
  |
MENSAJE
  |
USUARIO
  |
RESPUESTA


============================================================
23. COMPORTAMIENTO DEL CHAT
============================================================

El envío de mensajes deberá realizarse sin recargar toda la
página cuando sea técnicamente apropiado.

Preferir:

JavaScript / Fetch / AJAX
        |
        v
Drupal
        |
        v
Diagnostic Service
        |
        v
OpenAI

No realizar llamadas directas desde JavaScript hacia OpenAI.


============================================================
24. INDICADOR DE PROCESAMIENTO
============================================================

Mientras OpenAI procesa una respuesta deberá mostrarse un
estado visual apropiado.

Ejemplo:

"Analizando..."

o un indicador animado.

El usuario no deberá poder enviar múltiples mensajes simultáneos
que puedan romper la sesión.

El botón de envío deberá bloquearse mientras corresponda.


============================================================
25. MARKDOWN Y RESPUESTAS
============================================================

Si el agente devuelve Markdown:

- Renderizarlo de forma segura.
- Sanitizar HTML.
- Evitar XSS.
- No ejecutar HTML arbitrario generado por la IA.

Nunca confiar directamente en HTML generado por OpenAI.


============================================================
26. CONTINUIDAD DE CONVERSACIÓN
============================================================

La conversación deberá mantener contexto durante la sesión.

Conceptualmente:

DiagnosticSession
      |
      +-- Message 1
      +-- Message 2
      +-- Message 3
      +-- Message 4
      +-- Message N

El backend será responsable de administrar el contexto.

No depender únicamente del navegador para mantener la
información crítica de la conversación.


============================================================
27. ARQUITECTURA DEL CHAT
============================================================

Separar:

CHAT UI
   |
Conversation Controller
   |
Conversation Service
   |
Diagnostic Session
   |
Diagnostic Context Builder
   |
Diagnostic Prompt Manager
   |
OpenAI Provider
   |
OpenAI API
   |
Structured Response
   |
Diagnostic Session
   |
CHAT UI


============================================================
28. OPENAI PROVIDER
============================================================

Crear una interfaz:

DiagnosticEngineInterface

Ejemplo:

interface DiagnosticEngineInterface
{
    public function process(
        DiagnosticContext $context
    ): DiagnosticResult;
}

Implementación inicial:

OpenAIDiagnosticProvider

La arquitectura deberá permitir posteriormente otros
proveedores de IA.

Ejemplo:

OpenAIDiagnosticProvider
AnthropicDiagnosticProvider
OtherAIProvider


============================================================
29. OPENAI API
============================================================

La API Key jamás deberá exponerse al frontend.

NO:

- JavaScript.
- HTML.
- URL.
- Cookies accesibles al frontend.
- Código cliente.

La comunicación con OpenAI será exclusivamente backend.

Las credenciales deberán almacenarse de forma segura mediante:

- Variables de entorno.
- Secrets management.
- Configuración segura del servidor.

Nunca hardcodear API Keys.


============================================================
30. MODELO DE IA
============================================================

No asumir un modelo específico.

El modelo deberá poder configurarse.

Ejemplo:

OpenAI Model
[ configurable ]

No hardcodear el modelo si puede evitarse.


============================================================
31. CONTEXTO DEL DIAGNÓSTICO
============================================================

Crear un servicio similar a:

DiagnosticContextBuilder

Responsable de preparar:

- Identidad del diagnóstico.
- Respuestas.
- Historial necesario.
- Prompt.
- Instrucciones.
- Metodología.
- Versión.
- Variables dinámicas.

No enviar información innecesaria a OpenAI.


============================================================
32. RESPUESTAS ESTRUCTURADAS
============================================================

Siempre que sea posible, utilizar respuestas estructuradas.

Ejemplo conceptual:

{
  "type": "diagnostic_response",
  "message": "...",
  "status": "in_progress",
  "next_step": "...",
  "result": null
}

Y al finalizar:

{
  "type": "diagnostic_result",
  "status": "completed",
  "summary": "...",
  "score": 0,
  "strengths": [],
  "opportunities": [],
  "recommendations": [],
  "priority_actions": []
}

La estructura definitiva deberá adaptarse a la metodología
proporcionada por el cliente.

Validar siempre las respuestas antes de almacenarlas.


============================================================
33. SESIONES DE DIAGNÓSTICO
============================================================

Crear una entidad o sistema de almacenamiento apropiado para:

DiagnosticSession

Campos sugeridos:

- ID.
- Drupal User ID.
- WordPress User ID.
- Course ID.
- Status.
- Started At.
- Completed At.
- Diagnostic Version.
- Created.
- Changed.

Estados:

draft
in_progress
processing
completed
failed


============================================================
34. MENSAJES
============================================================

La conversación podrá modelarse mediante mensajes asociados
a una sesión.

Ejemplo:

DiagnosticSession
   |
   +-- user message
   +-- assistant message
   +-- user message
   +-- assistant message
   +-- user message

Los mensajes deberán estar protegidos mediante ownership.


============================================================
35. RESULTADOS
============================================================

Los resultados deberán estar asociados:

Usuario
   |
Diagnostic Session
   |
Diagnostic Result

El usuario únicamente podrá consultar sus propios resultados.

Implementar protección contra:

IDOR
Insecure Direct Object Reference.


============================================================
36. HISTORIAL
============================================================

El alumno deberá contar con una sección:

"Mis diagnósticos"

Mostrando:

- Fecha.
- Estado.
- Versión.
- Resultado.
- Acción para consultar.

Ejemplo:

------------------------------------------------------
Mis diagnósticos

21/08/2026     Completado       [Ver resultado]
------------------------------------------------------


============================================================
37. DASHBOARD
============================================================

Crear un dashboard propio del módulo.

Ejemplo:

Sales Leadership Diagnostic AI

Bienvenido, {nombre}

[ Iniciar diagnóstico ]

Mis diagnósticos

-----------------------------------------
Fecha        Estado        Acción
-----------------------------------------
21/08/2026   Completado    Ver resultado


============================================================
38. RUTAS
============================================================

Crear rutas específicas del módulo.

Ejemplo:

/sales-diagnostic
/sales-diagnostic/start
/sales-diagnostic/session/{id}
/sales-diagnostic/session/{id}/message
/sales-diagnostic/session/{id}/complete
/sales-diagnostic/results
/sales-diagnostic/results/{id}

Todas deberán implementar:

- Access checks.
- Permissions.
- Ownership.
- Authentication.
- Authorization.


============================================================
39. ADMINISTRACIÓN
============================================================

Crear:

Configuration
   |
Sales Leadership Diagnostic AI

Secciones:

WORDPRESS

- API Base URL.
- Course/Product ID.
- Authentication configuration.
- Timeout.
- Authorization cache TTL.

OPENAI

- Model.
- Timeout.
- Retry limit.

DIAGNOSTIC

- Diagnostic version.
- Prompt.
- Instructions.
- Configuración necesaria.

SECURITY

- Rate limits.
- Maximum attempts.
- Session timeout.


============================================================
40. PERMISOS
============================================================

Crear permisos específicos.

Ejemplo:

access sales leadership diagnostic

administer sales leadership diagnostic

view all diagnostic results

El alumno normal solamente deberá tener:

access sales leadership diagnostic

No deberá tener acceso a resultados de otros usuarios.


============================================================
41. STORAGE
============================================================

Elegir la estrategia de almacenamiento apropiada para Drupal 11.

Puede utilizarse:

- Content Entities.
- Custom tables.
- Combinación.

La decisión deberá justificarse considerando:

- Seguridad.
- Privacidad.
- Escalabilidad.
- Rendimiento.
- Integridad.
- Consultas.


============================================================
42. SEGURIDAD
============================================================

Implementar como mínimo:

- Access control.
- Ownership.
- CSRF protection.
- XSS protection.
- Input validation.
- Output sanitization.
- API Key protection.
- Token validation.
- Rate limiting.
- Secure sessions.
- HTTPS.
- IDOR protection.
- Permission checks.

Un alumno jamás deberá poder consultar datos de otro alumno.


============================================================
43. PRIVACIDAD
============================================================

Los diagnósticos pueden contener información empresarial sensible.

Por lo tanto:

- No almacenar información innecesaria.
- No exponer resultados.
- No mostrar datos de otros usuarios.
- No incluir secretos en logs.
- No registrar API Keys.
- No registrar tokens completos.
- Evitar registrar prompts completos.
- Evitar registrar respuestas completas si no es necesario.


============================================================
44. RATE LIMITING
============================================================

Implementar protección contra abuso.

Configurable:

- Maximum diagnostic attempts.
- Maximum requests.
- Cooldown.
- Rate limit por usuario.

También controlar llamadas simultáneas hacia OpenAI.


============================================================
45. LOGGING
============================================================

Utilizar Drupal Logger.

Registrar eventos como:

- Authorization success.
- Authorization denied.
- WordPress API error.
- Token validation failure.
- OpenAI API error.
- Diagnostic started.
- Diagnostic completed.
- Diagnostic failed.

Nunca registrar secretos.


============================================================
46. ESTRUCTURA DEL MÓDULO
============================================================

Estructura inicial sugerida:

sales_leadership_diagnostic/

├── sales_leadership_diagnostic.info.yml
├── sales_leadership_diagnostic.module
├── sales_leadership_diagnostic.services.yml
├── sales_leadership_diagnostic.routing.yml
├── sales_leadership_diagnostic.permissions.yml
├── sales_leadership_diagnostic.links.menu.yml
├── sales_leadership_diagnostic.links.task.yml
│
├── config/
│   ├── install/
│   └── schema/
│
├── src/
│   ├── Controller/
│   ├── Form/
│   ├── Service/
│   │   ├── Authorization/
│   │   ├── WordPress/
│   │   ├── OpenAI/
│   │   ├── Diagnostic/
│   │   ├── Conversation/
│   │   └── Security/
│   │
│   ├── Entity/
│   ├── Plugin/
│   ├── EventSubscriber/
│   ├── Repository/
│   ├── DTO/
│   ├── Exception/
│   └── Security/
│
├── templates/
│   ├── dashboard/
│   ├── chat/
│   └── results/
│
├── css/
│   └── sales_leadership_diagnostic.css
│
├── js/
│   └── sales_leadership_diagnostic.js
│
└── tests/
    ├── Unit/
    └── Kernel/


La estructura podrá modificarse si existe una mejor solución
compatible con Drupal 11, pero deberá mantenerse una clara
separación de responsabilidades.


============================================================
47. PROVIDERS
============================================================

Utilizar arquitectura desacoplada.

CourseAccessProviderInterface
        |
        +-- LearnDashAccessProvider

DiagnosticEngineInterface
        |
        +-- OpenAIDiagnosticProvider

El resto del módulo deberá trabajar contra interfaces cuando
sea razonable.


============================================================
48. TESTING
============================================================

Crear tests para:

WORDPRESS

- Usuario autorizado.
- Usuario no autorizado.
- Curso incorrecto.
- Usuario inexistente.
- API caída.
- Timeout.
- Respuesta inválida.

TOKEN

- Token válido.
- Token expirado.
- Token inválido.
- Firma inválida.
- Issuer incorrecto.
- Audience incorrecta.

CHAT

- Crear sesión.
- Enviar mensaje.
- Recibir respuesta.
- Mantener contexto.
- Continuar conversación.
- Completar diagnóstico.

DIAGNÓSTICO

- Guardar respuestas.
- Procesar resultado.
- Guardar resultado.
- Consultar historial.

SEGURIDAD

- Usuario A no puede consultar usuario B.
- Usuario sin autorización no puede iniciar.
- Usuario no autenticado no puede acceder.
- IDOR.
- CSRF.
- XSS.

OPENAI

Utilizar mocks.

Los tests NO deben depender de llamadas reales a OpenAI.


============================================================
49. CONFIGURACIÓN POR ENTORNO
============================================================

Soportar:

development
staging
production

No mezclar:

- API Keys.
- URLs.
- Course IDs.
- Secrets.

entre ambientes.


============================================================
50. COMPATIBILIDAD
============================================================

Objetivo:

Drupal 11.x
PHP 8.3+
Symfony 7

Seguir APIs oficiales de Drupal 11.

No utilizar APIs deprecated.

Antes de utilizar una API, verificar compatibilidad con Drupal 11.


============================================================
51. DEPENDENCIAS
============================================================

Minimizar dependencias externas.

Antes de agregar un módulo contrib:

1. Justificar necesidad.
2. Revisar compatibilidad con Drupal 11.
3. Evaluar mantenimiento.
4. Evaluar seguridad.
5. Evaluar si Drupal Core puede resolverlo.

No agregar dependencias innecesarias.


============================================================
52. NO HACER
============================================================

NO:

- Modificar Drupal Core.
- Modificar el theme para lógica de negocio.
- Modificar módulos existentes sin autorización.
- Crear ecommerce.
- Duplicar LearnDash.
- Consultar directamente MySQL de WordPress.
- Almacenar contraseñas de WordPress.
- Crear login independiente innecesario.
- Exponer OpenAI API Key.
- Hardcodear Course ID.
- Hardcodear secrets.
- Hardcodear el prompt dentro de Controllers.
- Confiar únicamente en email.
- Confiar en parámetros GET para autorización.
- Permitir acceso cruzado entre usuarios.
- Crear un agente nuevo.
- Inventar metodología.
- Inventar preguntas.
- Modificar la lógica de negocio del cliente.
- Hacer llamadas directas desde frontend hacia OpenAI.


============================================================
53. RESPONSABILIDADES DEL CLIENTE
============================================================

El cliente deberá proporcionar:

- Agente existente.
- Prompt.
- Instrucciones.
- Metodología.
- Preguntas o flujo conversacional.
- Criterios de evaluación.
- Reglas de análisis.
- Estructura de resultados.
- Recomendaciones.
- Materiales de conocimiento.
- Identificador del curso/producto.
- Acceso/documentación necesaria de WordPress/LearnDash.
- Credenciales/API necesarias para integración.


============================================================
54. ALCANCE DEL MVP
============================================================

INCLUIDO:

- Módulo custom Drupal 11.
- Integración WordPress.
- Integración LearnDash.
- Validación del curso/producto.
- SSO/token.
- Autorización.
- Dashboard.
- Interfaz conversacional.
- Chat UI tipo asistente de IA.
- Sesiones.
- Mensajes.
- Contexto conversacional.
- Integración OpenAI API.
- Integración del prompt proporcionado.
- Integración de instrucciones.
- Integración de metodología.
- Resultados.
- Historial.
- Configuración administrativa.
- Seguridad.
- Logging.
- Tests básicos.


============================================================
55. FUERA DEL ALCANCE
============================================================

NO INCLUIDO:

- Creación de nueva metodología.
- Creación de nuevo agente.
- Diseño estratégico del agente.
- Consultoría de IA.
- Creación del prompt desde cero.
- Optimización estratégica del prompt.
- Desarrollo de nuevos diagnósticos.
- Ecommerce.
- CRM.
- Aplicación móvil.
- Gamificación.
- Certificados.
- Analytics avanzado.
- Integraciones adicionales no especificadas.
- Múltiples LMS.
- Otros proveedores de IA no contemplados.


============================================================
56. ESCALABILIDAD
============================================================

El MVP inicialmente contemplará:

1 curso
1 diagnóstico
1 agente

La arquitectura deberá permitir posteriormente:

N cursos
N diagnósticos
N agentes

Ejemplo:

Curso A
   |
Diagnostic A
   |
Agent A


Curso B
   |
Diagnostic B
   |
Agent B


No implementar complejidad innecesaria en el MVP.

Pero tampoco construir una arquitectura que impida esta evolución.


============================================================
57. VERSIONADO
============================================================

Cada sesión deberá registrar:

diagnostic_version

La versión deberá identificar qué:

- Prompt.
- Instrucciones.
- Metodología.

se utilizó.

Esto permitirá mantener trazabilidad de los resultados históricos.


============================================================
58. MANEJO DE ERRORES
============================================================

Los errores técnicos nunca deberán mostrarse directamente
al alumno.

Ejemplo técnico:

cURL error 28

NO mostrar al usuario.

Mostrar:

"No hemos podido procesar tu solicitud en este momento.
Por favor intenta nuevamente."

El detalle técnico debe quedar en logs.


============================================================
59. FLUJO COMPLETO
============================================================

WORDPRESS + LEARNDASH
        |
        v
Usuario compra curso
        |
        v
Usuario inicia sesión
        |
        v
"Acceder al Diagnostic AI"
        |
        v
Token seguro
        |
        v
DRUPAL 11
        |
        v
sales_leadership_diagnostic
        |
        v
Validar identidad
        |
        v
Consultar WordPress
        |
        v
Consultar LearnDash
        |
        v
¿Tiene acceso?
       / \
     SÍ   NO
     |     |
     v     v
Dashboard Denegado
     |
     v
Abrir Chat
     |
     v
Mensaje inicial del agente
     |
     v
Usuario responde
     |
     v
Drupal
     |
     v
Diagnostic Session
     |
     v
Diagnostic Context
     |
     v
Prompt + Instructions
     |
     v
OpenAI Provider
     |
     v
OpenAI API
     |
     v
Respuesta del agente
     |
     v
Chat UI
     |
     v
Usuario continúa
     |
     v
Diagnóstico completado
     |
     v
Resultado
     |
     v
Drupal
     |
     v
Historial


============================================================
60. METODOLOGÍA DE DESARROLLO
============================================================

ANTES DE ESCRIBIR CÓDIGO:

1. Analizar Drupal.
2. Analizar módulos existentes.
3. Analizar tema.
4. Analizar versión PHP.
5. Analizar configuración.
6. Analizar arquitectura.
7. Identificar conflictos.
8. Definir estructura definitiva.
9. Definir integración WordPress.
10. Definir autenticación.
11. Definir autorización.
12. Definir integración OpenAI.
13. Definir arquitectura del chat.
14. Definir modelo de datos.
15. Definir seguridad.

NO comenzar directamente a modificar archivos.

Primero presentar:

1. Architecture Proposal.
2. Module Structure.
3. Dependency Analysis.
4. Security Model.
5. WordPress Integration Flow.
6. Authentication Flow.
7. Authorization Flow.
8. Chat Architecture.
9. OpenAI Integration Flow.
10. Data Model.
11. Implementation Plan.

Esperar aprobación antes de realizar cambios estructurales importantes.


============================================================
61. DESARROLLO POR FASES
============================================================

FASE 1
Arquitectura del módulo.

FASE 2
Configuración Drupal.

FASE 3
WordPress API.

FASE 4
LearnDash Authorization.

FASE 5
SSO / Token.

FASE 6
Dashboard.

FASE 7
Chat UI.

FASE 8
Diagnostic Session.

FASE 9
Conversation Service.

FASE 10
OpenAI Provider.

FASE 11
Prompt + instrucciones + metodología.

FASE 12
Resultados e historial.

FASE 13
Seguridad y rate limiting.

FASE 14
Testing.

FASE 15
Staging.

FASE 16
Producción.


============================================================
62. REGLA DE IMPLEMENTACIÓN
============================================================

Cada cambio deberá indicar:

- Qué se modifica.
- Por qué.
- Archivos afectados.
- Dependencias.
- Riesgos.
- Cómo probarlo.
- Cómo revertirlo.

No realizar cambios no relacionados con:

sales_leadership_diagnostic


============================================================
63. CRITERIO DE ÉXITO
============================================================

El módulo estará correctamente implementado cuando:

Alumno compra curso
        |
LearnDash registra acceso
        |
Alumno inicia sesión en WordPress
        |
Pulsa "Diagnostic AI"
        |
Drupal recibe token seguro
        |
Drupal valida identidad
        |
Drupal consulta WordPress
        |
LearnDash confirma acceso
        |
Módulo autoriza
        |
Alumno entra al Chat
        |
Agente inicia conversación
        |
Alumno responde
        |
Drupal mantiene contexto
        |
Prompt + instrucciones son aplicados
        |
OpenAI procesa
        |
Agente responde
        |
Conversación continúa
        |
Diagnóstico finaliza
        |
Resultado generado
        |
Resultado almacenado
        |
Alumno consulta resultado
        |
Resultado queda en historial.


============================================================
64. PRINCIPIOS FINALES
============================================================

DRUPAL
=
Plataforma y aplicación.

sales_leadership_diagnostic
=
Toda la lógica específica del Diagnostic AI.

WORDPRESS + LEARNDASH
=
Fuente de verdad de autorización.

OPENAI API
=
Motor de inteligencia artificial.

SALESBUMM
=
Propietario y proveedor de:

- Agente.
- Prompt.
- Instrucciones.
- Metodología.
- Criterios de evaluación.
- Contenido del diagnóstico.

DESARROLLO
=
Integración técnica + arquitectura + seguridad +
UX/UI + experiencia conversacional + funcionamiento
del sistema.


============================================================
65. PRINCIPIO FINAL DE PRODUCTO
============================================================

El resultado NO debe sentirse como un formulario conectado
a una API.

Debe sentirse como una experiencia profesional de
diagnóstico conversacional mediante IA.

El alumno deberá percibir:

"Estoy conversando con un asesor de inteligencia artificial
que está realizando mi diagnóstico."

La interfaz deberá ser moderna, limpia, rápida, responsive
y profesional, tomando como referencia conceptual la
experiencia conversacional de ChatGPT, pero utilizando la
identidad visual de Salesbumm.

La metodología, preguntas, instrucciones y lógica del
diagnóstico serán las proporcionadas por el cliente.

El módulo será responsable de proporcionar la infraestructura
tecnológica necesaria para convertir esa metodología en una
experiencia conversacional segura y funcional dentro de
Drupal 11.