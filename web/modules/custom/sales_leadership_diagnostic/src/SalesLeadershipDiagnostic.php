<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

/**
 * Identificadores compartidos del módulo.
 *
 * Centraliza las cadenas que se referencian desde varios sitios a la vez
 * (instalación, rutas, access checks, provisión de usuarios). Una constante
 * mal escrita falla al compilar; un literal mal escrito falla en silencio
 * concediendo o denegando accesos de forma incorrecta.
 */
final class SalesLeadershipDiagnostic {

  /**
   * Rol que agrupa a los alumnos autorizados.
   *
   * Se asigna automáticamente al provisionar una cuenta desde WordPress.
   */
  public const STUDENT_ROLE_ID = 'sales_diagnostic_student';

  /**
   * Etiqueta del rol de alumno.
   */
  public const STUDENT_ROLE_LABEL = 'Alumno Diagnostic AI';

  /**
   * Rol para quien atiende soporte.
   *
   * Se crea vacío de usuarios: el módulo no asigna a nadie a este rol, porque
   * quién atiende soporte es una decisión del cliente. Existe para que el
   * permiso de leer diagnósticos ajenos pueda concederse sin tener que
   * inventar un rol ni, mucho peor, repartir el de administrador.
   */
  public const SUPPORT_ROLE_ID = 'sales_diagnostic_support';

  /**
   * Etiqueta del rol de soporte.
   */
  public const SUPPORT_ROLE_LABEL = 'Soporte Diagnostic AI';

  /**
   * Rol de gestión del cliente.
   *
   * Es un rol DISTINTO del de soporte, no un renombrado. Hoy ambos llevan el
   * mismo permiso, pero responden a puestos distintos dentro de la
   * organización del cliente y sus permisos pueden separarse más adelante sin
   * tener que reasignar usuarios.
   *
   * No se toca el rol de administrador de Drupal: el gestor recibe lo justo
   * para consultar diagnósticos, y nada del prompt ni de las credenciales de
   * integración.
   */
  public const MANAGER_ROLE_ID = 'gestor_sam';

  /**
   * Etiqueta del rol de gestión.
   */
  public const MANAGER_ROLE_LABEL = 'Gestor SAM';

  /**
   * Permiso de core que hace usable el listado a quien no es administrador.
   *
   * Sin el tema de administración, el listado de resultados se pinta con el
   * tema del alumno y desentona. NO se concede «access administration pages»:
   * el gestor no necesita el resto del panel y dárselo ampliaría su alcance
   * sin motivo.
   */
  public const ADMIN_THEME_PERMISSION =
    'view the administration theme';

  /**
   * Versión mínima del plugin de WordPress que el módulo necesita.
   *
   * Se sube cuando el módulo empieza a depender de algo que el plugin añadió,
   * NO cada vez que el plugin publica una versión: exigir la última obligaría
   * al cliente a actualizar por cambios que no le afectan.
   *
   * 1.1.0 es la primera que envía `started_at`, sin el cual no puede aplicarse
   * la política de un diagnóstico por periodo, y la primera que informa de su
   * propia versión.
   */
  public const MINIMUM_PLUGIN_VERSION = '1.1.0';

  /**
   * Permite usar el diagnóstico y consultar los resultados propios.
   */
  public const PERMISSION_ACCESS = 'access sales leadership diagnostic';

  /**
   * Permite configurar el módulo.
   */
  public const PERMISSION_ADMINISTER = 'administer sales leadership diagnostic';

  /**
   * Permite consultar los resultados de cualquier alumno.
   */
  public const PERMISSION_VIEW_ALL_RESULTS = 'view all diagnostic results';

  /**
   * Permite editar y publicar el prompt del agente.
   *
   * Separado de PERMISSION_ADMINISTER a propósito. Quien define cómo conversa
   * el agente no tiene por qué ver los secretos, la integración con WordPress
   * ni los límites de seguridad; y al revés, quien administra la instalación
   * no es necesariamente quien sabe de metodología comercial.
   *
   * Antes de existir este permiso, dar acceso al prompt obligaba a entregar
   * también todo lo demás.
   */
  public const PERMISSION_EDIT_PROMPT = 'edit diagnostic prompt';

  /**
   * Lista completa de los permisos que define el módulo.
   *
   * @var string[]
   */
  public const ALL_PERMISSIONS = [
    self::PERMISSION_ACCESS,
    self::PERMISSION_ADMINISTER,
    self::PERMISSION_VIEW_ALL_RESULTS,
    self::PERMISSION_EDIT_PROMPT,
  ];

  /**
   * Proveedor de authmap que vincula un usuario de Drupal con uno de WordPress.
   *
   * Lo consume externalauth. Una cuenta creada a mano en Drupal no tiene
   * entrada en el authmap y por tanto no puede acceder al diagnóstico.
   */
  public const AUTHMAP_PROVIDER = 'sld_wp';

  /**
   * Canal de log del módulo.
   */
  public const LOGGER_CHANNEL = 'sales_leadership_diagnostic';

  /**
   * Identificador del primer agente, el que existía antes de haber varios.
   *
   * Vive como constante porque lo usan la migración y el código que necesita
   * un valor por defecto para las sesiones anteriores a los agentes. Nada más
   * debería depender de él: a partir de aquí los agentes son datos, no código.
   */
  public const FIRST_AGENT_ID = 'sales_leadership_diagnostic';

}
