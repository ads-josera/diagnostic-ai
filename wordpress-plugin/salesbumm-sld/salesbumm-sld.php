<?php
/**
 * Plugin Name:       Salesbumm — Sales Leadership Diagnostic AI (puente)
 * Description:       Puente entre WordPress/LearnDash y el módulo de diagnóstico en Drupal. Emite el token de acceso del alumno y responde a la consulta de autorización de Drupal. No añade funcionalidad visible fuera del botón de acceso.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Salesbumm
 * License:           GPL-2.0-or-later
 * Text Domain:       salesbumm-sld
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

// Acceso directo al archivo: nada que hacer aquí.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nota sobre la versión de PHP.
 *
 * El módulo de Drupal exige PHP 8.3. Este plugin, en cambio, se escribe para
 * PHP 7.4: corre en el WordPress del cliente, cuya versión no controlamos y
 * que suele ir por detrás. Por eso aquí no hay enums, ni propiedades
 * promovidas, ni readonly, aunque el proyecto los use del otro lado.
 */

const SLD_VERSION      = '1.0.0';
const SLD_PLUGIN_FILE  = __FILE__;
const SLD_TEXT_DOMAIN  = 'salesbumm-sld';

/**
 * Espacio de nombres del endpoint REST.
 */
const SLD_REST_NAMESPACE = 'salesbumm-sld/v1';

require_once __DIR__ . '/includes/class-sld-secrets.php';
require_once __DIR__ . '/includes/class-sld-settings.php';
require_once __DIR__ . '/includes/class-sld-jwt-signer.php';
require_once __DIR__ . '/includes/class-sld-request-verifier.php';
require_once __DIR__ . '/includes/class-sld-course-access.php';
require_once __DIR__ . '/includes/class-sld-access-endpoint.php';
require_once __DIR__ . '/includes/class-sld-sso-handler.php';
require_once __DIR__ . '/includes/class-sld-plugin.php';

/**
 * Arranca el plugin.
 *
 * Se engancha a plugins_loaded y no antes: la comprobación de acceso depende
 * de LearnDash, que debe haber tenido ocasión de cargarse.
 */
function sld_bootstrap(): void {
	Plugin::instance()->register();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\sld_bootstrap' );
