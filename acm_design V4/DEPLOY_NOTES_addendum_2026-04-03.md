Addendum — 2026-04-03

Actions performed (automated):

1) Backed up and removed FINORA assets (moved to `backup_finora_2026-04-03`):
- leadwerk_theme/css/{base.css,components.css,pages.css,styles.css}
- leadwerk_theme/js/main.js
- leadwerk_theme/source_shells/{altersvorsorge.html,datenschutz.html,erbanlage-beratung.html,finora-philosophie.html,immobilien-beratung.html,impressum.html,index.html,investment-beratung.html,kontakt.html,ueber-finora.html}
- leadwerk_theme/assets/images/{Finora-Beratung.jpg,Finora-Kevin-freigestellt-scaled.png,header-ueber.jpg}
- leadwerk_importer/source_assets/* (same HTML files + assets/images)

2) Copied ACM repo root static files into the theme:
- Copied root HTML -> `leadwerk_theme/source_shells/` (examples: index.html, kontakt.html, news.html, global-6000.html, global-7500.html, global-xrs.html, aircraft-management.html, maintenance.html, charter.html, thats-acm.html, etc.)
- Copied root CSS -> `leadwerk_theme/css/` (mobile-qa.css)
- Copied root JS -> `leadwerk_theme/js/` (nav-active.js)

Notes & next steps:
- All moved Finora files are preserved under `backup_finora_2026-04-03/`.
- Theme now contains ACM root pages under `leadwerk_theme/source_shells/`. Verify that file names match what the theme expects (exact-finora-render.php maps some source keys to specific filenames).
- You may need to update theme includes or page-key mappings to align ACM filenames with theme logic.
- If you want me to: (a) remove the backup, (b) run a search-and-replace to remove `finora-` tokens from PHP/CSS/JS, or (c) update mapping keys in `inc/exact-finora-render.php`, tell me which.

Files written/modified:
- DEPLOY_NOTES_addendum_2026-04-03.md
- backup_finora_2026-04-03/ (moved files)
- leadwerk_theme/source_shells/ (copied ACM HTML files)
- leadwerk_theme/css/mobile-qa.css
- leadwerk_theme/js/nav-active.js

End of addendum.

-- Automated rename & mapping update (2026-04-03)

3) Created full pre-replace backup of leadwerk_* directories:
- `backup_pre_replace_2026-04-03/leadwerk_fields`
- `backup_pre_replace_2026-04-03/leadwerk_importer`
- `backup_pre_replace_2026-04-03/leadwerk_theme`
- `backup_pre_replace_2026-04-03/leadwerk_wpml_clone`

4) Replaced token prefix `finora-` -> `acm-` across plugin/theme files (.php, .js, .css, .html, .json) under the copied leadwerk_* folders. Files changed include (non-exhaustive):
- `leadwerk_theme/inc/exact-finora-render.php` (mapping updated)
- `leadwerk_theme/inc/structured-finora-render.php`
- `leadwerk_theme/parts/footer.html`
- `leadwerk_theme/parts/header.html`
- `leadwerk_theme/functions.php`
- `leadwerk_importer/*`
- `leadwerk_wpml_clone/*`
- `leadwerk_fields/*`

5) Updated `leadwerk_theme/inc/exact-finora-render.php`:
- Added `acm-*` source-key mappings that point to ACM shell filenames (e.g. `acm-home-v1` => `index.html`, `acm-contact-v1` => `kontakt.html`, etc.).

6) Verification:
- Performed a post-change search; no remaining `finora-` tokens were found in the edited file types under leadwerk_*.

If you want me to also:
- Replace human-readable occurrences of “Finora” in content with ACM wording, I can (this will alter visible text and should be reviewed), or
- Update theme mapping to remap specific source keys to other filenames, provide the desired source_key → filename mapping.

End of automated rename & mapping update.