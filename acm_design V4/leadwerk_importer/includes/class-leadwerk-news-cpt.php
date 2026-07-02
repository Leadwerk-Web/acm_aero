<?php
/**
 * Registers acm_news when the theme is inactive so news import can run safely.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register ACM News CPT (same rules as leadwerk_theme) if not already registered.
 *
 * @return void
 */
function leadwerk_importer_register_acm_news_cpt() {
	if ( post_type_exists( 'acm_news' ) ) {
		return;
	}

	$labels = array(
		'name'               => 'ACM News',
		'singular_name'      => 'News-Beitrag',
		'menu_name'          => 'ACM News',
		'add_new'            => 'Neuen Beitrag',
		'add_new_item'       => 'Neuen News-Beitrag erstellen',
		'edit_item'          => 'News-Beitrag bearbeiten',
		'new_item'           => 'Neuer News-Beitrag',
		'view_item'          => 'News-Beitrag ansehen',
		'search_items'       => 'News durchsuchen',
		'not_found'          => 'Keine News gefunden',
		'not_found_in_trash' => 'Keine News im Papierkorb',
		'all_items'          => 'Alle News',
	);

	// has_archive false: keine CPT-Archiv-URL auf /news/ — die Uebersicht ist die importierte Seite "news".
	register_post_type(
		'acm_news',
		array(
			'labels'        => $labels,
			'public'        => true,
			'has_archive'   => false,
			'menu_icon'     => 'dashicons-megaphone',
			'menu_position' => 5,
			'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			'rewrite'       => array( 'slug' => 'news', 'with_front' => false ),
			'show_in_rest'  => true,
		)
	);
}
add_action( 'init', 'leadwerk_importer_register_acm_news_cpt', 5 );
