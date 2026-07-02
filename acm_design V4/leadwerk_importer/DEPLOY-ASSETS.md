# Statische Assets (Importer + Theme)

## Ein Quellbaum

- **Kanon:** `leadwerk_importer/source_assets/` (HTML, `Fotos/`, `assets/`, …) — wird vom Importer (`leadwerk_import_source_root`) gelesen.
- **Theme:** `leadwerk_theme_get_static_source_base()` löst `assets/…`- und `Fotos/…`-URLs gegen dieselbe `source_assets`-Basis auf (Plugin neben dem Theme unter `wp-content/plugins/`), sofern der Ordner existiert. Filter: `leadwerk_theme_static_source_base`.
- **Fonts:** klein unter `leadwerk_theme/assets/fonts/` (Theme), unabhängig von den großen Bilddaten.

## Deployment

1. Repo ohne große Blobs klonen (siehe Root-`.gitignore`).
2. `source_assets` auf dem Server füllen, z. B. aus eurem Artefakt-Store oder internem Export:
   - `rsync -a ./export/source_assets/ wp-content/plugins/leadwerk_importer/source_assets/`
3. Theme-Ordner `leadwerk_theme/assets/images/` bleibt leer bis auf `.gitkeep` — keine zweite Bildkopie nötig.

## Produktion / Sicherheit

Öffentliche URLs unter `…/plugins/leadwerk_importer/source_assets/` können die komplette statische Site erreichbar machen. Optionen:

- **Staging:** unkritisch.
- **Production:** Medien nach Import nur noch aus `wp-content/uploads/`; Shell-Links ggf. weiter auf WP-Medien umbiegen **oder** `leadwerk_theme_static_source_base` auf einen internen/ geschützten Pfad setzen **oder** Webserver: kein Directory-Listing, nur benötigte Pfade.

## Optional: statische JPEG/PNG → WebP (vor oder nach erstem Import)

Skript: `scripts/convert-static-images-to-webp.py` (benötigt `pip install -r scripts/requirements-webp.txt`).

```bash
cd /pfad/zum/ACM-Repo
python3 scripts/convert-static-images-to-webp.py --dry-run
python3 scripts/convert-static-images-to-webp.py --keep-originals   # erst konvertieren + Referenzen, Originale behalten
python3 scripts/convert-static-images-to-webp.py                    # konvertieren, Referenzen patchen, Originale löschen
```

Standard-`--path` ist `leadwerk_importer/source_assets` (nicht das ganze Repo). Dateien, die der Importer per **fester** PHP-Zeile erwartet (`logo.png`, Favicons, `assets/images/Logo-final-weiss-rz.png`, `Fotos/starlink-popup.png` sowie ggf. `assets/images/starlink-popup.png`, …), werden **nicht** konvertiert, damit `fill_options` / Preflight / Starlink-Seed nicht brechen.

## Exact-Shells (Theme)

`leadwerk_theme` laedt HTML-Schalen zuerst aus `leadwerk_theme/source_shells/`, fehlt die Datei dort, aus `leadwerk_importer/source_assets/` (gleicher Dateiname, z. B. `index.html`). Filter: `leadwerk_theme_exact_shell_file`.

## Verifikation

- Eine Seite mit Shell-`assets/…`- und `Fotos/…`-Links öffnen (Bilder laden).
- Importer-Dry-Run: Medienliste findet Dateien unter `source_assets`.
