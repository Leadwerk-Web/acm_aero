<?php
/**
 * 404 template.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
$leadwerk_404_image_url = function_exists( 'leadwerk_theme_get_uploaded_media_url_for_template_ref' )
	? leadwerk_theme_get_uploaded_media_url_for_template_ref( 'Fotos/Neu/AOG_Support.webp' )
	: '';
?>
<main class="leadwerk-simple-page">
	<section class="content-section content-section--white legal-content pt-32 pb-24" style="position:relative;overflow:hidden;background:linear-gradient(135deg,#fdfcfa 0%,#f3f0ea 56%,#e8edf4 100%);">
		<div class="max-w-6xl mx-auto px-6" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:clamp(2rem,5vw,4rem);align-items:center;">
			<div>
				<p class="section-label mb-4"><?php esc_html_e( '404 / Route nicht verfuegbar', 'leadwerk-theme' ); ?></p>
				<h1 class="legal-title font-serif text-4xl text-stone-900 mb-8" style="font-size:clamp(3.5rem,9vw,8rem);line-height:.86;margin-bottom:1.5rem;"><?php esc_html_e( 'Dieser Flugplan endet hier.', 'leadwerk-theme' ); ?></h1>
				<div class="legal-body text-stone-600 leading-relaxed prose prose-stone max-w-none">
					<p style="font-size:1.05rem!important;max-width:34rem;"><?php esc_html_e( 'Die angeforderte Seite ist nicht mehr verfuegbar. Unser Team bringt Sie direkt zurueck zu den wichtigsten ACM Bereichen.', 'leadwerk-theme' ); ?></p>
					<div style="display:flex;flex-wrap:wrap;gap:.75rem;margin:2rem 0 2.25rem;">
						<a class="btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>" style="display:inline-flex;align-items:center;justify-content:center;padding:.9rem 1.35rem;background:#001441;color:#fff;text-decoration:none;"><?php esc_html_e( 'Zur Startseite', 'leadwerk-theme' ); ?></a>
						<a class="btn-primary" href="<?php echo esc_url( home_url( '/kontakt/' ) ); ?>" style="display:inline-flex;align-items:center;justify-content:center;padding:.9rem 1.35rem;background:#fff;color:#001441;text-decoration:none;box-shadow:0 12px 30px rgba(0,20,65,.10);"><?php esc_html_e( 'Kontakt aufnehmen', 'leadwerk-theme' ); ?></a>
					</div>
					<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;max-width:42rem;">
						<a href="<?php echo esc_url( home_url( '/charter/' ) ); ?>" style="padding:1rem;background:rgba(255,255,255,.72);color:#1c1917;text-decoration:none;box-shadow:0 10px 26px rgba(0,20,65,.08);"><span style="display:block;font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;color:#001441;margin-bottom:.45rem;">Charter</span><?php esc_html_e( 'Aircraft Charter', 'leadwerk-theme' ); ?></a>
						<a href="<?php echo esc_url( home_url( '/aircraft-management/' ) ); ?>" style="padding:1rem;background:rgba(255,255,255,.72);color:#1c1917;text-decoration:none;box-shadow:0 10px 26px rgba(0,20,65,.08);"><span style="display:block;font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;color:#001441;margin-bottom:.45rem;">Management</span><?php esc_html_e( 'Aircraft Management', 'leadwerk-theme' ); ?></a>
						<a href="<?php echo esc_url( home_url( '/news/' ) ); ?>" style="padding:1rem;background:rgba(255,255,255,.72);color:#1c1917;text-decoration:none;box-shadow:0 10px 26px rgba(0,20,65,.08);"><span style="display:block;font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;color:#001441;margin-bottom:.45rem;">Aktuell</span><?php esc_html_e( 'News & Updates', 'leadwerk-theme' ); ?></a>
					</div>
				</div>
			</div>
			<figure style="margin:0;position:relative;min-height:26rem;box-shadow:0 28px 80px rgba(0,20,65,.16);background:#001441;overflow:hidden;">
				<?php if ( '' !== $leadwerk_404_image_url ) : ?>
					<img src="<?php echo esc_url( $leadwerk_404_image_url ); ?>" alt="<?php esc_attr_e( 'ACM Aircraft Support', 'leadwerk-theme' ); ?>" style="width:100%;height:100%;min-height:26rem;object-fit:cover;display:block;filter:saturate(.85) contrast(.96);">
				<?php endif; ?>
				<figcaption style="position:absolute;left:1.25rem;right:1.25rem;bottom:1.25rem;padding:1rem 1.1rem;background:rgba(255,255,255,.88);color:#001441;backdrop-filter:blur(14px);font-size:.9rem;letter-spacing:.08em;text-transform:uppercase;"><?php esc_html_e( 'Operations ready', 'leadwerk-theme' ); ?></figcaption>
				<div aria-hidden="true" style="position:absolute;right:-.4rem;top:-1.8rem;font-family:Cormorant Garamond,serif;font-size:clamp(7rem,18vw,13rem);line-height:.8;color:rgba(255,255,255,.34);">404</div>
			</figure>
		</div>
	</section>
</main>
<?php
get_footer();
