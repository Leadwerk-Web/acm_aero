<?php
/**
 * Plugin Name: Leadwerk WPML Clone
 * Description: Lightweight DE/EN translation layer with section-based classic translation editor for ACM sites.
 * Version: 2.0.0
 * Author: Leadwerk
 * Text Domain: leadwerk-wpml-clone
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_WPML_CLONE_VERSION', '2.0.0' );
define( 'LEADWERK_WPML_CLONE_PATH', plugin_dir_path( __FILE__ ) );
define( 'LEADWERK_WPML_CLONE_BOOT_ERROR_OPTION', 'leadwerk_wpml_clone_boot_error' );

require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-api.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-migrator.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-router.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-html-segments.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-sync.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-shared-translation-packages.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-wpml-clone.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-language-switcher.php';
require_once LEADWERK_WPML_CLONE_PATH . 'includes/class-leadwerk-translation-sitemap.php';

/**
 * Persist one boot-time plugin error for later admin display.
 *
 * @param Throwable $error Caught error.
 * @return void
 */
function leadwerk_wpml_clone_store_boot_error( $error ) {
	if ( ! $error instanceof Throwable ) {
		return;
	}

	update_option(
		LEADWERK_WPML_CLONE_BOOT_ERROR_OPTION,
		array(
			'message' => $error->getMessage(),
			'file'    => $error->getFile(),
			'line'    => $error->getLine(),
			'time'    => current_time( 'mysql', true ),
		),
		false
	);

	if ( function_exists( 'error_log' ) ) {
		error_log(
			sprintf(
				'Leadwerk WPML Clone boot error: %s in %s:%d',
				$error->getMessage(),
				$error->getFile(),
				(int) $error->getLine()
			)
		);
	}
}

/**
 * Activation wrapper that avoids hard site crashes.
 *
 * @return void
 */
function leadwerk_wpml_clone_activate() {
	try {
		delete_option( LEADWERK_WPML_CLONE_BOOT_ERROR_OPTION );
		Leadwerk_Translation_Migrator::install();
	} catch ( Throwable $error ) {
		leadwerk_wpml_clone_store_boot_error( $error );

		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	}
}

/**
 * Safe runtime bootstrap.
 *
 * @return void
 */
function leadwerk_wpml_clone_boot() {
	try {
		delete_option( LEADWERK_WPML_CLONE_BOOT_ERROR_OPTION );
		Leadwerk_Translation_Migrator::maybe_upgrade();
		Leadwerk_Translation_Router::init();
		Leadwerk_WPML_Clone::init();
		Leadwerk_Language_Switcher::init();
		Leadwerk_Translation_Sitemap::init();
	} catch ( Throwable $error ) {
		leadwerk_wpml_clone_store_boot_error( $error );
	}
}

/**
 * Show stored plugin boot errors in wp-admin.
 *
 * @return void
 */
function leadwerk_wpml_clone_admin_notice() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$error = get_option( LEADWERK_WPML_CLONE_BOOT_ERROR_OPTION, array() );
	if ( empty( $error['message'] ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>Leadwerk WPML Clone:</strong> ' . esc_html( (string) $error['message'] ) . '</p>';
	if ( ! empty( $error['file'] ) ) {
		echo '<p><code>' . esc_html( (string) $error['file'] ) . ':' . esc_html( (string) ( $error['line'] ?? '' ) ) . '</code></p>';
	}
	echo '</div>';
}

register_activation_hook( __FILE__, 'leadwerk_wpml_clone_activate' );
add_action( 'plugins_loaded', 'leadwerk_wpml_clone_boot', 20 );
add_action( 'admin_notices', 'leadwerk_wpml_clone_admin_notice' );
