<?php
/**
 * Synchronisiert statisches HTML: Kanonische Quelle = leadwerk_importer/source_assets
 * -> Projekt-Root (inkl. news/).
 *
 * Der WordPress-Importer liest nur source_assets; Theme-Shells werden nicht mehr gespiegelt.
 *
 * Usage (Repo-Root = acm_design V4):
 *   php scripts/sync-html-sources.php
 *   php scripts/sync-html-sources.php --dry-run
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

$dry_run = in_array( '--dry-run', $argv, true );

$root    = dirname( __DIR__ );
$mapping = $root . '/leadwerk_importer/manifest/mapping.json';
$src     = $root . '/leadwerk_importer/source_assets';

if ( ! is_file( $mapping ) ) {
	fwrite( STDERR, "Fehlt mapping.json: {$mapping}\n" );
	exit( 1 );
}

if ( ! is_dir( $src ) ) {
	fwrite( STDERR, "Fehlt source_assets: {$src}\n" );
	exit( 1 );
}

$raw  = file_get_contents( $mapping );
$data = json_decode( (string) $raw, true );
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "Ungültiges JSON in mapping.json\n" );
	exit( 1 );
}

$files = array();

foreach ( (array) ( $data['pages'] ?? array() ) as $page ) {
	$f = (string) ( $page['source_file'] ?? '' );
	if ( $f !== '' ) {
		$files[] = $f;
	}
}

foreach ( (array) ( $data['news_articles'] ?? array() ) as $article ) {
	$f = (string) ( $article['source_file'] ?? '' );
	if ( $f !== '' && preg_match( '/\.html$/i', $f ) ) {
		$files[] = $f;
	}
}

$files = array_values( array_unique( $files ) );
sort( $files );

$copied = 0;
$skipped = 0;

foreach ( $files as $rel ) {
	$rel = str_replace( '\\', '/', $rel );
	$rel = ltrim( $rel, '/' );
	$from = $src . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );

	if ( ! is_file( $from ) ) {
		fwrite( STDERR, "Quelle fehlt (übersprungen): {$rel}\n" );
		++$skipped;
		continue;
	}

	$targets = array(
		$root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel ),
	);

	foreach ( $targets as $dest ) {
		$dest_dir = dirname( $dest );
		if ( ! is_dir( $dest_dir ) ) {
			if ( $dry_run ) {
				echo "[dry-run] mkdir {$dest_dir}\n";
			} else {
				if ( ! mkdir( $dest_dir, 0775, true ) && ! is_dir( $dest_dir ) ) {
					fwrite( STDERR, "Verzeichnis nicht anlegbar: {$dest_dir}\n" );
					exit( 1 );
				}
			}
		}

		if ( $dry_run ) {
			echo "[dry-run] copy {$rel} -> " . str_replace( $root . DIRECTORY_SEPARATOR, '', $dest ) . "\n";
		} else {
			if ( ! copy( $from, $dest ) ) {
				fwrite( STDERR, "Kopieren fehlgeschlagen: {$rel}\n" );
				exit( 1 );
			}
		}
	}
	++$copied;
}

echo $dry_run
	? "Dry-Run: {$copied} Quelldatei(en) würden synchronisiert, {$skipped} ohne Quelle.\n"
	: "Fertig: {$copied} Quelldatei(en) nach Projekt-Root kopiert, {$skipped} ohne Quelle.\n";
exit( 0 );
