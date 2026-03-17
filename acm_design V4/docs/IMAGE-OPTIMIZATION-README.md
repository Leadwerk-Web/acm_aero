# Bildoptimierung – Pipeline & Nutzung

## Übersicht

- **Originale:** Alle bestehenden Bilder in `Fotos/` bleiben unverändert.
- **Ausgabe:** Optimierte Varianten (WebP + AVIF, mehrere Breiten) werden in `Fotos/optimized/` erzeugt.
- **Tool:** Node.js + [sharp](https://sharp.pixelplumbing.com/).

## Voraussetzungen

- Node.js 18+
- Keine Überschreibung der Originale; nur Lesezugriff auf `Fotos/`, Schreibzugriff auf `Fotos/optimized/`.

## Installation

```bash
npm install
```

## Skript ausführen

```bash
# Optimierte Bilder erzeugen
npm run optimize-images

# Nur anzeigen, was gemacht würde (keine Dateien schreiben)
npm run optimize-images:dry
```

## Ausgabestruktur

Pro Originalbild z. B. `Fotos/Neu/7500_hero.jpg` entstehen in `Fotos/optimized/Neu/`:

- `7500_hero-480w.webp`, `7500_hero-480w.avif`
- `7500_hero-768w.webp`, `7500_hero-768w.avif`
- … für 1024, 1366, 1920 (nur Breiten kleiner als Original)
- `7500_hero-full.webp`, `7500_hero-full.avif` (Vollbreite, komprimiert)

Kleinere Originale werden nicht hochskaliert; es werden nur Breiten ≤ Originalbreite erzeugt.

## Konfiguration

In `scripts/optimize-images.js`:

- **CONFIG.inputDir:** Quellordner (Standard: `Fotos/`)
- **CONFIG.outputDir:** Zielordner (Standard: `Fotos/optimized/`)
- **CONFIG.widths:** Responsive-Breiten (Standard: 480, 768, 1024, 1366, 1920)
- **CONFIG.webp / CONFIG.avif:** Qualität und Effort (siehe sharp-Doku)

## HTML-Anpassung

Nach dem ersten Lauf der Pipeline können die HTML-Seiten schrittweise umgestellt werden:

1. **LCP/Hero-Bilder:** `<picture>` mit `<source type="image/avif">`, `<source type="image/webp">`, Fallback-`<img>`, plus `srcset`/`sizes`, `width`/`height`, `decoding="async"`, `fetchpriority="high"` (nur ein LCP pro Seite).
2. **Content-Bilder:** Gleiche Struktur, aber `loading="lazy"` und kein `fetchpriority="high"`.
3. **Pfade:** Entweder direkt auf `Fotos/optimized/...` zeigen oder über Server/Redirect die optimierten Varianten ausliefern.

Beispiel-Markup für ein Hero-Bild ist in der Analyse (docs) und in den umgestellten Hero-Abschnitten zu finden.
