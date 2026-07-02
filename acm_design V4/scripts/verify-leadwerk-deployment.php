<?php
/**
 * Prüft die erwartete Leadwerk-Bundle-Struktur (Repo-Root oder wp-content/plugins).
 *
 * Usage:
 *   php scripts/verify-leadwerk-deployment.php
 *   php scripts/verify-leadwerk-deployment.php --base="C:/pfad/zum/projekt"
 *   php scripts/verify-leadwerk-deployment.php --strict-drift   # Exit 1 bei HTML-Drift source_assets vs Root-Mirror
 *
 * Exit 0 = OK (Warnungen nur auf STDERR), 1 = Fehler oder --strict-drift mit Abweichung.
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

$opts = getopt( '', array( 'base::', 'strict-drift' ) );
$base = isset( $opts['base'] ) ? rtrim( (string) $opts['base'], "/\\ \t\n\r\0\x0B" ) : dirname( __DIR__ );
$strict_drift = isset( $opts['strict-drift'] );

$errors   = array();
$warnings = array();

/**
 * @param string $base Base path.
 * @param string $rel  Relative path with /.
 */
$path_join = static function ( $base, $rel ) {
	return $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
};

if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	$errors[] = 'PHP 7.4+ erforderlich, gefunden: ' . PHP_VERSION;
}

foreach ( array( 'json', 'dom' ) as $ext ) {
	if ( ! extension_loaded( $ext ) ) {
		$errors[] = "PHP-Erweiterung fehlt: {$ext} (für Manifest/JSON bzw. Importer-Preflight und DOM-Parsing erforderlich)";
	}
}

$required = array(
	'leadwerk_importer/leadwerk-importer.php',
	'leadwerk_importer/manifest/mapping.json',
	'leadwerk_importer/manifest/translation-seeds.json',
	'leadwerk_importer/source_assets',
	'leadwerk_theme/style.css',
);

foreach ( $required as $rel ) {
	$p = $path_join( $base, $rel );
	if ( ! file_exists( $p ) ) {
		$errors[] = "Fehlt: {$rel}";
	}
}

$fields_main_new = $path_join( $base, 'leadwerk-fields/leadwerk-fields.php' );
$fields_main_old = $path_join( $base, 'leadwerk_fields/leadwerk-fields.php' );
if ( ! is_file( $fields_main_new ) && ! is_file( $fields_main_old ) ) {
	$errors[] = 'Fehlt Leadwerk Fields: leadwerk-fields/leadwerk-fields.php (empfohlen) oder leadwerk_fields/leadwerk-fields.php (Legacy).';
} elseif ( is_file( $fields_main_old ) && ! is_file( $fields_main_new ) ) {
	$warnings[] = 'Nur Legacy-Ordner leadwerk_fields gefunden — für WordPress-6.5-Plugin-Abhängigkeiten bitte nach leadwerk-fields umbenennen.';
}

$schema_new = $path_join( $base, 'leadwerk-fields/includes/class-leadwerk-content-schema.php' );
$schema_old = $path_join( $base, 'leadwerk_fields/includes/class-leadwerk-content-schema.php' );
if ( ! is_file( $schema_new ) && ! is_file( $schema_old ) ) {
	$errors[] = 'Fehlt class-leadwerk-content-schema.php unter leadwerk-fields/includes/ oder leadwerk_fields/includes/.';
}

$wpml_new = $path_join( $base, 'leadwerk-wpml-clone/leadwerk-wpml-clone.php' );
$wpml_old = $path_join( $base, 'leadwerk_wpml_clone/leadwerk-wpml-clone.php' );
if ( ! is_file( $wpml_new ) && ! is_file( $wpml_old ) ) {
	$errors[] = 'Fehlt Leadwerk WPML Clone: leadwerk-wpml-clone/leadwerk-wpml-clone.php (empfohlen) oder leadwerk_wpml_clone/leadwerk-wpml-clone.php (Legacy).';
} elseif ( is_file( $wpml_old ) && ! is_file( $wpml_new ) ) {
	$warnings[] = 'Nur Legacy-Ordner leadwerk_wpml_clone gefunden — für WordPress-6.5-Plugin-Abhängigkeiten bitte nach leadwerk-wpml-clone umbenennen.';
}

$mapping_path = $path_join( $base, 'leadwerk_importer/manifest/mapping.json' );
if ( is_file( $mapping_path ) ) {
	$raw  = file_get_contents( $mapping_path );
	$data = json_decode( (string) $raw, true );
	if ( ! is_array( $data ) ) {
		$errors[] = 'leadwerk_importer/manifest/mapping.json ist kein gültiges JSON.';
	} else {
		$mapped_files = array();
		foreach ( (array) ( $data['pages'] ?? array() ) as $page ) {
			$f = (string) ( $page['source_file'] ?? '' );
			if ( $f !== '' ) {
				$mapped_files[] = $f;
			}
		}
		foreach ( (array) ( $data['news_articles'] ?? array() ) as $article ) {
			$f = (string) ( $article['source_file'] ?? '' );
			if ( $f !== '' && preg_match( '/\.html$/i', $f ) ) {
				$mapped_files[] = $f;
			}
		}
		$mapped_files = array_values( array_unique( $mapped_files ) );

		$src = $path_join( $base, 'leadwerk_importer/source_assets' );

		foreach ( $mapped_files as $rel ) {
			$rel = str_replace( '\\', '/', ltrim( $rel, '/' ) );
			$in_src = $path_join( $base, 'leadwerk_importer/source_assets/' . $rel );
			if ( ! is_file( $in_src ) ) {
				$errors[] = "mapping.json verweist auf fehlende Datei in source_assets: {$rel}";
			}

			$in_root = $path_join( $base, $rel );
			if ( is_file( $in_src ) && is_file( $in_root ) ) {
				$h_src   = hash_file( 'sha256', $in_src );
				$h_root  = hash_file( 'sha256', $in_root );
				if ( $h_src !== $h_root ) {
					$warnings[] = "HTML-Drift (source_assets != Root-Mirror): {$rel} - php scripts/sync-html-sources.php ausfuehren.";
					if ( $strict_drift ) {
						$errors[] = "Strict: Drift bei {$rel}";
					}
				}
			} elseif ( is_file( $in_src ) && ! is_file( $in_root ) ) {
				$warnings[] = "Fehlt im Root-Mirror (Sync empfohlen): {$rel}";
				if ( $strict_drift ) {
					$errors[] = "Strict: Root-Mirror fehlt {$rel}";
				}
			}
		}

		// Entspricht Importer-Preflight: jede field_name-Zeile braucht eine Gruppe in Leadwerk_Content_Schema.
		$schema_path = is_file( $path_join( $base, 'leadwerk-fields/includes/class-leadwerk-content-schema.php' ) )
			? $path_join( $base, 'leadwerk-fields/includes/class-leadwerk-content-schema.php' )
			: $path_join( $base, 'leadwerk_fields/includes/class-leadwerk-content-schema.php' );
		if ( is_file( $schema_path ) ) {
			$schema_src = (string) file_get_contents( $schema_path );
			foreach ( (array) ( $data['pages'] ?? array() ) as $page ) {
				$fn = (string) ( $page['field_name'] ?? '' );
				$sk = (string) ( $page['source_key'] ?? '?' );
				if ( '' === $fn ) {
					$errors[] = "mapping.json: Seite source_key={$sk} ohne field_name.";
					continue;
				}
				$pattern = "/'" . preg_quote( $fn, '/' ) . "'\\s*=>/u";
				if ( ! preg_match( $pattern, $schema_src ) ) {
					$errors[] = "Schema-Gruppe fehlt in class-leadwerk-content-schema.php für field_name \"{$fn}\" (source_key {$sk}).";
				}
			}
		} else {
			$errors[] = 'leadwerk-fields/includes/class-leadwerk-content-schema.php (oder Legacy leadwerk_fields/…) fehlt — Preflight-Schema-Prüfung nicht möglich.';
		}

		$src_root = $path_join( $base, 'leadwerk_importer/source_assets' );
		if ( is_dir( $src_root ) ) {
			$fotos = $src_root . DIRECTORY_SEPARATOR . 'Fotos';
			if ( ! is_dir( $fotos ) ) {
				$warnings[] = 'Ordner leadwerk_importer/source_assets/Fotos fehlt — Medienimport wird dünner (wie Importer-Preflight-Warnung).';
			}
			$logo = $src_root . DIRECTORY_SEPARATOR . 'logo.webp';
			if ( ! is_file( $logo ) ) {
				$warnings[] = 'leadwerk_importer/source_assets/logo.webp fehlt — Header/Footer-Logos ggf. nicht importierbar.';
			}
			foreach ( array( 'assets/images/Logo-final-weiss-rz_svg.svg', 'assets/images/Logo-final-weiss-rz.webp', 'assets/images/Schriftzug.svg' ) as $rel_asset ) {
				$full = $path_join( $src_root, $rel_asset );
				if ( ! is_file( $full ) ) {
					$warnings[] = 'Theme-Option-Medium fehlt unter source_assets: ' . $rel_asset;
				}
			}
		}
	}
}

$seeds_path = $path_join( $base, 'leadwerk_importer/manifest/translation-seeds.json' );
if ( is_file( $seeds_path ) ) {
	$seed_raw = file_get_contents( $seeds_path );
	$seed     = json_decode( (string) $seed_raw, true );
	if ( ! is_array( $seed ) ) {
		$errors[] = 'leadwerk_importer/manifest/translation-seeds.json ist kein gültiges JSON.';
	}
}

$manifest_derived = $path_join( $base, 'leadwerk_importer/manifest/import-manifest.json' );
if ( ! is_file( $manifest_derived ) ) {
	$warnings[] = 'leadwerk_importer/manifest/import-manifest.json fehlt — optional: php scripts/build-import-manifest.php (Importer nutzt nur mapping.json).';
}

if ( $errors ) {
	fwrite( STDERR, "Leadwerk Deployment-Check (base: {$base})\n" );
	foreach ( $errors as $line ) {
		fwrite( STDERR, "  [FEHLER] {$line}\n" );
	}
	fwrite( STDERR, "\nHinweis: Unter WordPress müssen leadwerk-fields, leadwerk-wpml-clone und leadwerk_importer Geschwister unter wp-content/plugins/ sein (Legacy-Ordnernamen mit Unterstrich werden vom Importer beim Laden noch toleriert).\n" );
	exit( 1 );
}

foreach ( $warnings as $line ) {
	fwrite( STDERR, "[WARN] {$line}\n" );
}

fwrite(
	STDERR,
	"[INFO] WordPress-Deploy: Drei Ordner exakt benennen und unter wp-content/plugins/ als Geschwister ablegen: leadwerk-fields, leadwerk-wpml-clone, leadwerk_importer (Bindestrich-Namen = gültige WP-6.5-Requires-Plugins-Slugs). Theme leadwerk_theme unter wp-content/themes/ aktivieren.\n"
	. "[INFO] Advanced Custom Fields (Free/Pro) deaktivieren — kollidiert mit Leadwerk Fields / Import-Preflight.\n"
	. "[INFO] Datenbank: Benutzer mit CREATE TABLE (Leadwerk WPML Clone legt Tabellen bei Aktivierung an). Bei roter Admin-Notice Plugin-Boot prüfen.\n"
	. "[INFO] Aktivierung empfohlen: leadwerk-wpml-clone → leadwerk-fields → leadwerk_importer.\n"
	. "[INFO] Optional (Windows): Bundle nach WordPress kopieren mit scripts/copy-leadwerk-bundle-to-wp.ps1 -WordPressRoot \"<WP-Root>\".\n"
	. "[INFO] Vor Live-Import: WP-Admin → Tools → Leadwerk Import → Dry-Run; ACF und fremde get_field-Polyfills deaktivieren.\n"
	. "[INFO] Nach Live-Import: Einstellungen → Permalinks speichern; Lesen → Startseite prüfen; Smoke-Test /, /news/, /news/{artikel-slug}/, /en/; Import-Log auf Entwürfe/Finalize-Warnungen prüfen.\n"
	. "[INFO] Ausführliche Nach-Import-Liste: php scripts/post-import-checklist.php\n"
);

echo "OK: Leadwerk-Bundle unter {$base} vollständig.";
if ( $warnings ) {
	echo ' (' . count( $warnings ) . ' Warnung(en), siehe STDERR)';
}
echo "\n";

exit( 0 );
