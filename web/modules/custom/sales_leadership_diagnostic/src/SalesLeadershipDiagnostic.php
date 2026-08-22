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
   * Lista completa de los permisos que define el módulo.
   *
   * @var string[]
   */
  public const ALL_PERMISSIONS = [
    self::PERMISSION_ACCESS,
    self::PERMISSION_ADMINISTER,
    self::PERMISSION_VIEW_ALL_RESULTS,
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

}
