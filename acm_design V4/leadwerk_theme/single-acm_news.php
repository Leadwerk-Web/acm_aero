<?php
/**
 * Single template for ACM news (CPT acm_news).
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$lang     = function_exists( 'leadwerk_theme_get_current_lang' ) ? leadwerk_theme_get_current_lang() : 'de';
$news_url = function_exists( 'leadwerk_theme_get_page_url' )
	? leadwerk_theme_get_page_url( 'acm-news-v1', $lang, home_url( '/news/' ) )
	: home_url( '/news/' );

if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();

		$dt_raw = '';
		if ( defined( 'LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META' ) ) {
			$dt_raw = get_post_meta( get_the_ID(), LEADWERK_THEME_ACM_NEWS_PUBLICATION_DATE_META, true );
		}
		if ( ! is_string( $dt_raw ) || '' === trim( $dt_raw ) ) {
			$dt_raw = get_post_meta( get_the_ID(), '_leadwerk_news_datetime', true );
		}
		$date_html = '';
		if ( is_string( $dt_raw ) && '' !== $dt_raw ) {
			$ts = strtotime( $dt_raw );
			if ( $ts ) {
				$date_html = sprintf(
					'<time class="acm-news-single__date" datetime="%1$s">%2$s</time>',
					esc_attr( $dt_raw ),
					esc_html( date_i18n( get_option( 'date_format' ), $ts ) )
				);
			}
		}

		$back_label = 'en' === $lang
			? esc_html__( 'Back to news overview', 'leadwerk-theme' )
			: esc_html__( 'Zurück zur News-Übersicht', 'leadwerk-theme' );
		$section_label = esc_html__( 'News', 'leadwerk-theme' );
		?>
<main class="article-shell acm-news-single" role="main">
	<section class="acm-news-single__toolbar" aria-label="<?php echo esc_attr__( 'Navigation', 'leadwerk-theme' ); ?>">
		<div class="acm-news-single__inner">
			<a class="article-back-link" href="<?php echo esc_url( $news_url ); ?>">
				<span aria-hidden="true">&larr;</span>
				<span><?php echo $back_label; ?></span>
			</a>
		</div>
	</section>
	<section class="acm-news-single__intro">
		<div class="acm-news-single__inner acm-news-single__intro-inner">
			<p class="section-label acm-news-single__label"><?php echo $section_label; ?></p>
			<h1 class="acm-news-single__title"><?php the_title(); ?></h1>
			<?php if ( '' !== $date_html ) { ?>
			<div class="acm-news-single__meta"><?php echo $date_html; ?></div>
			<?php } ?>
		</div>
	</section>
	<?php if ( has_post_thumbnail() ) { ?>
	<section class="acm-news-single__hero">
		<div class="acm-news-single__inner acm-news-single__hero-inner">
			<figure class="article-figure acm-news-single__figure">
				<div class="acm-news-single__figure-aspect">
					<?php
					the_post_thumbnail(
						'full',
						array(
							'class'         => 'acm-news-single__hero-img',
							'loading'       => 'eager',
							'fetchpriority' => 'high',
							'alt'           => wp_strip_all_tags( get_the_title() ),
						)
					);
					?>
				</div>
			</figure>
		</div>
	</section>
	<?php } ?>
	<div class="acm-news-single__content">
		<?php the_content(); ?>
	</div>
</main>
		<?php
	}
}

get_footer();
