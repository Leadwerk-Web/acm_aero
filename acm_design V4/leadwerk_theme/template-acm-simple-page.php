<?php
/**
 * Template Name: ACM Simple Page
 * Template Post Type: page
 *
 * Editable WordPress page content in the same shell as Impressum/Datenschutz.
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

		if ( function_exists( 'leadwerk_theme_render_simple_content_page' ) ) {
			echo leadwerk_theme_render_simple_content_page( get_the_ID() );
		} else {
			the_content();
		}
	}
}

get_footer();
