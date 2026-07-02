<?php
/**
 * Druckbare Checkliste nach erfolgreichem Leadwerk-Live-Import (WordPress-Zielsystem).
 *
 * Usage: php scripts/post-import-checklist.php
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

$lines = array(
	'Leadwerk — Checkliste nach Live-Import',
	'=====================================',
	'',
	'[ ] Einstellungen → Permalinks: einmal speichern (ohne Änderung), damit Rewrite-Regeln für Seiten und acm_news greifen.',
	'[ ] Einstellungen → Lesen: statische Startseite = importierte „ACM AIR CHARTER“-Seite prüfen.',
	'[ ] Front: Startseite / (DE) und eine Unterseite öffnen; leere Bereiche = Parser/Schema prüfen (Finalize setzt betroffene Seiten ggf. auf Entwurf).',
	'[ ] News: /news/ = Übersichtsseite; /news/{artikel-slug}/ = Einzelbeitrag (CPT acm_news).',
	'[ ] Englisch: /en/ und mindestens eine Unterseite; EN-Felder ggf. in Leadwerk WPML Clone pflegen (translation-seeds.json kann leer sein).',
	'[ ] Englisch Shared Content: /en/ pruefen; globale Form-Modaltexte, Starlink-Block, Footer-Tagline und Homepage-News-Teaser muessen aus EN-Segmenten kommen.',
	'[ ] Englisch News: /en/news/ pruefen; Filter zeigt "All", Karten-Meta nutzt EN-Kategorien und EN-Monatsnamen, CTA ist "Read more", und Excerpts enthalten keine kyrillischen Fragmente (z. B. "благодаря").',
	'[ ] Tools → Leadwerk Import: Live-Log auf Fehler/Warnungen und „Finalize → Entwurf“-Hinweise prüfen.',
	'[ ] Advanced Custom Fields bleibt deaktiviert, solange Leadwerk Fields genutzt wird.',
	'[ ] Kein echtes WPML parallel zu Leadwerk WPML Clone (URL/Locale-Konflikte).',
	'',
	'Optional: php scripts/verify-leadwerk-deployment.php --strict-drift (im Repo vor erneutem Deploy).',
	'',
);

fwrite( STDOUT, implode( "\n", $lines ) );
