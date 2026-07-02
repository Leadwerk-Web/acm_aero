DEPLOY NOTES — leadwerk copy
Date: 2026-04-03

Source: C:\Users\mars\OneDrive\Documents\GitHub\FINORA\Version 2
Target: repository root (acm_design V4)

Actions performed
- Copied: `leadwerk_fields`, `leadwerk_importer`, `leadwerk_theme`, `leadwerk_wpml_clone` into repo root (overwrite enabled).
- Verified presence of main entry files (all found):
  - leadwerk_fields/leadwerk-fields.php
  - leadwerk_importer/leadwerk-importer.php
  - leadwerk_theme/style.css
  - leadwerk_theme/functions.php
  - leadwerk_wpml_clone/leadwerk-wpml-clone.php
- Scanned copied directories for 'b4451i.myrdbx.io' and 'FINORA' references.

Key findings
- All main entry files are present in the target.
- The theme contains FINORA-specific content and keys (many occurrences of the token `finora-` across:
  - leadwerk_theme/style.css and CSS files under leadwerk_theme/css/
  - leadwerk_theme/inc/*.php (including exact-finora-render.php and structured-finora-render.php)
  - leadwerk_theme/source_shells/*.html (static shells with Finora content)
  - leadwerk_theme/js/main.js
- The importer and wpml-clone reference `finora-` source keys and expect the sibling plugins to be present; those sibling plugins were copied.
- I did not alter remote URLs; note that other repo files (e.g. `live_dom.html`) still reference remote leadwerk theme assets on `b4451i.myrdbx.io`.

Recommendations / next steps
1. If you plan to use these in a local WordPress instance, move the plugins into `wp-content/plugins/` and the theme into `wp-content/themes/`, then activate the plugins (ensure dependencies: importer → fields + wpml_clone).
2. If you want the FINORA branding and keys removed or renamed (e.g., change `finora-` tokens or theme/plugin slugs), I can run a coordinated search-and-replace and update text domains/headers.
3. If you want to serve theme assets locally instead of remote URLs, I can update `live_dom.html` and theme enqueue calls to local paths.

Files written
- DEPLOY_NOTES.md (this file)

If you want, I can now:
- Move these into a local WP `wp-content/` layout and prepare activation steps, or
- Run a rename/text-domain migration to `acm_` prefixed slugs, or
- Update remote leadwerk asset URLs to local paths.

Tell me which of the above you'd like next.

---

## ACM (Stand 2026): Ordnernamen und Deploy-Paket

- **Plugins unter `wp-content/plugins/`** (Geschwister, exakt diese Slugs für WordPress 6.5+ „Requires Plugins“):
  - `leadwerk-fields/leadwerk-fields.php`
  - `leadwerk-wpml-clone/leadwerk-wpml-clone.php`
  - `leadwerk_importer/leadwerk-importer.php` (Unterstrich im Ordnernamen ist beabsichtigt)
- **Theme:** `leadwerk_theme/` → `wp-content/themes/leadwerk_theme/`
- **Nicht deployen:** `node_modules/`, lokale ZIPs des Projekts — siehe Root-`.gitignore`.

## Staging-Runbook (Import)

1. Datenbank-Benutzer mit Recht zum Anlegen von Tabellen (WPML Clone bei Aktivierung).
2. **Advanced Custom Fields (Free/Pro) deaktivieren** (Konflikt mit Leadwerk Fields / Preflight).
3. Plugins aktivieren in dieser Reihenfolge: **leadwerk-wpml-clone → leadwerk-fields → leadwerk_importer**.
4. Theme **leadwerk_theme** aktivieren.
5. **Tools → Leadwerk Import:** zuerst **Dry-Run**, Log prüfen; danach Live-Import.
6. Nach Live-Import: **Einstellungen → Permalinks** einmal speichern; **Einstellungen → Lesen** Startseite prüfen (importierte Seite „home“).
7. Smoke-Test: Startseite `/`, News-Übersicht `/news/`, ein Artikel `/news/{slug}/`, Englisch `/en/` und eine Unterseite.
8. Optional: `php scripts/post-import-checklist.php` im Projekt-Root ausführen.

## Englisch (EN) und `translation-seeds.json`

- Wenn `pages` in `leadwerk_importer/manifest/translation-seeds.json` leer bleibt, werden **keine** EN-Feldinhalte aus der Datei vorbefüllt (Importer warnt im Preflight-Log).
- EN-Inhalte dann über **Leadwerk WPML Clone** im Backend pflegen oder die Datei später mit Einträgen pro `source_key` unter `pages` befüllen (siehe Feld `documentation` in der JSON-Datei).