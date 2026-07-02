<?php
/**
 * Classic fallback template.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();

		if ( is_page() && function_exists( 'leadwerk_theme_render_current_page_content' ) ) {
			echo leadwerk_theme_render_current_page_content( get_the_ID() );
			continue;
		}

		the_content();
	}
}

get_footer();
