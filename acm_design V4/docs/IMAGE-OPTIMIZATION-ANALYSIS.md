# Bildoptimierung – Analysebericht

**Projekt:** ACM AIR CHARTER Website (acm_design V4)  
**Datum:** 2025  
**Zweck:** Vollständige Erfassung aller Bildassets und Einsatzorte für Performance-Optimierung.

---

## 1. Erfasste Bilddateien und Einsatzorte

### 1.1 Unique Bildpfade (aus HTML durchsucht)

| Pfad | Formate | Einsatz | Typ |
|------|---------|---------|-----|
| `logo.png` | PNG | Header/Footer (alle Seiten) | Logo |
| `Fotos/Emblem.svg` | SVG | Section-Emblem (alle Seiten) | Icon |
| `Fotos/Neu/7500_hero.jpg` | JPG | global-7500 Hero | **LCP** |
| `Fotos/Neu/7500_Flugzeug.jpg` | JPG | global-7500 Flugzeug | Content |
| `Fotos/Neu/7500_Kabine.jpg` | JPG | global-7500 Kabine | Content |
| `Fotos/Neu/7500_banner.jpg` | JPG | global-7500 Banner | Banner |
| `Fotos/Neu/Karriere_hero.jpg` | JPG | karriere Hero | **LCP** |
| `Fotos/Neu/Arbeitgeber.jpg` | JPG | karriere | Content |
| `Fotos/Neu/banner_career.jpg` | JPG | karriere Banner | Banner |
| `Fotos/Neu/Maintenance_CAMO_hero.jpg` | JPG | maintenance Hero | **LCP** |
| `Fotos/Neu/Maintenance _CAMO.jpg` | JPG | maintenance | Content |
| `Fotos/Neu/Technik_Vertrauen.jpg` | JPG | maintenance | Content |
| `Fotos/Neu/aog_support.jpg` | JPG | maintenance | Content |
| `Fotos/Neu/Thats ACM.jpg` | JPG | thats-acm Hero | **LCP** |
| `Fotos/Neu/Detail_Flugzeug.jpg` | JPG | thats-acm | Content |
| `Fotos/Neu/Wartungsbasis.jpg` | JPG | thats-acm | Content |
| `Fotos/Neu/Line_Maintenance.jpg` | JPG | thats-acm | Content |
| `Fotos/Neu/Camo_Aufsicht.jpg` | JPG | thats-acm | Content |
| `Fotos/Neu/Operations_Control.jpg` | JPG | thats-acm | Content |
| `Fotos/Neu/Heimatbasis.jpg` | JPG | thats-acm | Content |
| `Fotos/ACM - Allgemein/MKA1770.jpg` | JPG | karriere Banner | Banner |
| `Fotos/ACM - Allgemein/MKA1481.jpg` | JPG | thats-acm Banner | Banner |
| `Fotos/ACM - Allgemein/MKA1988.jpg` | JPG | index, aircraft-management | Content/Banner |
| `Fotos/ACM - Allgemein/MKA1488.jpg` | JPG | index | Content |
| `Fotos/ACM - Allgemein/MKA1223.jpg` | JPG | index | Content |
| `Fotos/ACM - Allgemein/MKA1216.png` | PNG | index | Content |
| `Fotos/ACM - Allgemein/ACM hanger at Baden Airport with two Globals.jpg` | JPG | index, charter, karriere | Content/Banner |
| `Fotos/ACM - Allgemein/Cockpit.jpg` | JPG | charter | Content |
| `Fotos/D-APLC/*.jpg` | JPG | global-7500, charter, global-6000/xrs | Hero/Content/Galerie |
| `Fotos/D-ABAY/*.jpg|.png` | JPG/PNG | global-6000, index, charter, karriere | Hero/Content/Flotte |
| `Fotos/D-AGJP (alt)/*.jpg` | JPG | global-xrs, global-7500/6000 Flotte | Hero/Content/Galerie |
| `Fotos/D-AMPG/*.jpg|.png` | JPG/PNG | index, charter | Content |
| `Fotos/Hangar/*.jpg|.png` | JPG/PNG | index, charter, maintenance, aircraft-management, kontakt | Content/Banner |
| `Fotos/Just/*.png` | PNG | aircraft-management | Content |
| `Fotos/Kontakt/*.jpg|.png` | JPG/PNG | kontakt (Profilbilder) | Content |
| `Fotos/bombardier7500-sitzplan.png` | PNG | global-7500 | Sitzplan |
| `Fotos/bombardier6000-sitzplan-tag.png` | PNG | global-6000 | Sitzplan |
| `Fotos/bombardier6000-sitzplan-nacht.png` | PNG | global-6000 | Sitzplan |
| `Fotos/bombardierxrs-sitzplan-tag.png` | PNG | global-xrs | Sitzplan |
| `Fotos/bombardierxrs-sitzplan-nacht.png` | PNG | global-xrs | Sitzplan |
| `Fotos/rampmanager.jpg` | JPG | index | Content |
| `Fotos/starlink-popup.png` | PNG | index | Modal |
| `Fotos/is-bao-registered-company-stages3-01.png` | PNG | mehrere Seiten (Footer) | Badge |
| `is-bao-registered-company-stages3-01.png` | PNG | karriere, maintenance, thats-acm, etc. | Badge |

### 1.2 Klassifizierung nach Typ

- **Hero / LCP:** 7500_hero, Karriere_hero, Maintenance_CAMO_hero, Thats ACM (thats-acm), Charter-Hero (charter), Global-6000 Hero (DSC_0533), Global-XRS Hero (SL7_7893), Index (Video-Poster/Banner).
- **Content:** Flugzeug-/Kabinen-Bilder, Sektionen „Flugzeug“, „Kabine“, „Arbeitgeber“, „Haltung“, etc.
- **Banner:** Fullwidth-Banner (45vh/55vh), z. B. „Ohne Grenzen“, „Your career…“, „Sicherheit, Präzision“.
- **Galerie/Slider:** Carousel-Bilder (D-APLC, D-ABAY, D-AGJP).
- **Karten/Grid:** Flotten-Karten (250px/300px Höhe), Kontakt-Profilbilder.
- **Logos/Icons:** logo.png, Fotos/Emblem.svg (SVG bereits vektoriell).
- **Sitzpläne:** PNG mit Transparenz/Text – behalten als PNG oder optimiertes PNG/WebP.
- **Dekorative/UI:** IS-BAO-Badge, Starlink-Popup.

---

## 2. Gefundene Probleme

### 2.1 Format und Größe

- **Keine WebP/AVIF:** Alle Fotos werden als JPG/PNG ausgeliefert → hohes Einsparpotenzial.
- **Vermutlich überdimensioniert:** Hero- und Banner-Bilder werden oft in voller Auflösung geladen; responsive Varianten fehlen.
- **PNG wo kein Transparenzbedarf:** Einige Inhalte (z. B. MKA1216.png, DSC_0511.png) könnten als WebP/AVIF dienen; Sitzpläne/Badges ggf. PNG beibehalten.

### 2.2 HTML/Attribute

- **width/height fehlen** bei den meisten `<img>` (CLS-Risiko), außer z. B. starlink-popup.png.
- **loading="lazy"** teils gesetzt (Galerie, Content), bei Hero/Banner teils fehlend oder bewusst weggelassen (korrekt für LCP).
- **decoding="async"** nirgends gesetzt.
- **fetchpriority="high"** bei keinem LCP-Bild gesetzt.
- **Kein `<picture>`:** Keine responsive Auslieferung (srcset/sizes) und keine modernen Formate (AVIF/WebP) im Markup.

### 2.3 Responsive Auslieferung

- Keine unterschiedlichen Größen für Viewport (z. B. 480/768/1024/1920).
- Mobile lädt dieselben großen Dateien wie Desktop.

### 2.4 Doppelte / mehrfache Nutzung

- Gleiche Datei an mehreren Stellen (z. B. `70046_04_9905.jpg` auf charter, global-7500, global-6000/xrs).
- `Emblem.svg` und `logo.png` auf jeder Seite mehrfach – sinnvoll gecacht, aber Größe der Assets prüfen.

### 2.5 LCP-relevante Fälle

- **global-7500:** `Fotos/Neu/7500_hero.jpg` – Hero, kein preload/fetchpriority, keine responsive Varianten.
- **karriere:** `Fotos/Neu/Karriere_hero.jpg` – Hero.
- **maintenance:** `Fotos/Neu/Maintenance_CAMO_hero.jpg` – Hero.
- **thats-acm:** `Fotos/Neu/Thats ACM.jpg` – Hero.
- **charter:** `Fotos/D-APLC/70046_04_9905.jpg` – Hero.
- **global-6000:** `Fotos/D-ABAY/DSC_0533.jpg` – Hero.
- **global-xrs:** `Fotos/D-AGJP (alt)/SL7_7893-Bearbeitet.jpg` – Hero.
- **index:** Video + Poster – LCP vom Video oder erstem sichtbarem Bild.

### 2.6 CSS background-image

- Nur lineare Verläufe (z. B. `linear-gradient`) gefunden, keine Raster-Bilder als background-image in den durchsuchten Stellen.

### 2.7 Ungenutzte / doppelte Assets

- Keine eindeutig ungenutzten Dateien aus der HTML-Suche; doppelte Nutzung siehe oben.
- Verschiedene Schreibweisen (z. B. `Maintenance _CAMO.jpg` mit Leerzeichen) können zu Fehlern führen.

---

## 3. Empfohlene Maßnahmen (Überblick)

1. **Pipeline:** Node.js + sharp – Originale in `Fotos/` belassen, Ausgabe in `Fotos/optimized/` (WebP + AVIF, mehrere Breiten).
2. **HTML:** Für Hero/LCP und große Content-Bilder `<picture>` mit `source type="image/avif"`, `source type="image/webp"`, `img`-Fallback; `srcset`/`sizes`; `width`/`height`; `decoding="async"`; `loading="lazy"` nur unterhalb des Folds; `fetchpriority="high"` nur für ein LCP-Bild pro Seite.
3. **Nicht überschreiben:** Alle Originale in `Fotos/` unverändert lassen; nur in `Fotos/optimized/` schreiben.
4. **SVG:** Emblem.svg und Logo (falls SVG-Version genutzt wird) nicht durch die Raster-Pipeline laufen lassen.
5. **Sitzpläne/Badges:** PNG optimieren (Kompression), ggf. WebP mit Transparenz; AVIF nur bei Browser-Support prüfen.

---

## 4. Nächste Schritte

- Bildoptimierungs-Skript ausführen (siehe `scripts/optimize-images.js` und README).
- HTML schrittweise auf `picture`/`srcset`/`sizes` und Attribute umstellen, beginnend mit LCP-Bildern.
- Nach Generierung der optimierten Dateien: Pfade in HTML auf `Fotos/optimized/...` zeigen lassen (oder Server/Redirect-Regeln für optimierte Auslieferung nutzen).
