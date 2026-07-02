<?php
/**
 * Theme footer.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
if ( function_exists( 'leadwerk_theme_render_footer_block' ) ) {
	echo leadwerk_theme_render_footer_block();
}

if ( function_exists( 'leadwerk_theme_render_scroll_to_top_button' ) ) {
	leadwerk_theme_render_scroll_to_top_button();
}

if ( function_exists( 'leadwerk_theme_render_footer_acm_chrome_markup' ) ) {
	leadwerk_theme_render_footer_acm_chrome_markup();
}

wp_footer();
?>
</body>
</html>
