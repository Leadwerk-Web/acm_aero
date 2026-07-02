<?php
/**
 * Front page template.
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
		echo leadwerk_theme_render_current_page_content( get_the_ID() );
	}
}

get_footer();
