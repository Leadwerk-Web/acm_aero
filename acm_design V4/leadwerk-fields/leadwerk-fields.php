<?php
/**
 * Plugin Name: Leadwerk Fields
 * Description: Lightweight ACF Pro replacement – provides get_field/update_field API using native post_meta. No external dependencies.
 * Version: 1.0.0
 * Author: Leadwerk
 * Text Domain: leadwerk-fields
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Leadwerk_Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADWERK_FIELDS_VERSION', '1.0.0' );
define( 'LEADWERK_FIELDS_PATH', plugin_dir_path( __FILE__ ) );

require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-content-schema.php';
require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-fields-api.php';
require_once LEADWERK_FIELDS_PATH . 'includes/class-leadwerk-fields-metabox.php';

// Initialise the API (registers global functions if ACF is not present).
Leadwerk_Fields_API::init();

// Initialise admin metaboxes and options page.
if ( is_admin() ) {
	Leadwerk_Fields_Metabox::init();
}
