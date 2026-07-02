<?php
/**
 * One-off: build impressum.html / datenschutz.html from kontakt shell.
 *
 * @package Leadwerk
 */

$root = dirname( __DIR__ );
$base = file_get_contents( $root . '/leadwerk_importer/source_assets/kontakt.html' );
$pos  = strpos( $base, '<main>' );
$end  = strpos( $base, '</main>' );
if ( false === $pos || false === $end ) {
	fwrite( STDERR, "Could not find <main> in kontakt.html\n" );
	exit( 1 );
}
$before = substr( $base, 0, $pos );
$tail   = substr( $base, $end + strlen( '</main>' ) );

$legal_main = static function ( $title ) {
	return '<main>
<section class="content-section content-section--white legal-content pt-32 pb-24">
<div class="max-w-3xl mx-auto px-6">
<h1 class="legal-title font-serif text-4xl text-stone-900 mb-8">' . $title . '</h1>
<div class="legal-body text-stone-600 leading-relaxed prose prose-stone max-w-none">
<p>Platzhalter &ndash; Inhalt bitte im WordPress-Backend unter Leadwerk Fields pflegen.</p>
</div>
</div>
</section>
</main>';
};

$imp_before = preg_replace( '#<title>.*?</title>#', '<title>Impressum | ACM AIR CHARTER</title>', $before, 1 );
$imp_before = preg_replace( '#<meta content="[^"]*" name="description"/>#', '<meta content="Impressum der ACM AIR CHARTER GmbH." name="description"/>', $imp_before, 1 );
$imp_before = str_replace( 'href="/kontakt.html"', 'href="/impressum.html"', $imp_before );
$imp_before = str_replace( 'href="/en/kontakt.html"', 'href="/en/impressum.html"', $imp_before );

$ds_before = preg_replace( '#<title>.*?</title>#', '<title>Datenschutz | ACM AIR CHARTER</title>', $before, 1 );
$ds_before = preg_replace( '#<meta content="[^"]*" name="description"/>#', '<meta content="Datenschutzerklaerung der ACM AIR CHARTER GmbH." name="description"/>', $ds_before, 1 );
$ds_before = str_replace( 'href="/kontakt.html"', 'href="/datenschutz.html"', $ds_before );
$ds_before = str_replace( 'href="/en/kontakt.html"', 'href="/en/datenschutz.html"', $ds_before );

$paths = array(
	$root . '/leadwerk_importer/source_assets/impressum.html',
	$root . '/impressum.html',
);
foreach ( $paths as $p ) {
	file_put_contents( $p, $imp_before . $legal_main( 'Impressum' ) . $tail );
}

$paths_ds = array(
	$root . '/leadwerk_importer/source_assets/datenschutz.html',
	$root . '/datenschutz.html',
);
foreach ( $paths_ds as $p ) {
	file_put_contents( $p, $ds_before . $legal_main( 'Datenschutz' ) . $tail );
}

echo "Generated legal shells.\n";
