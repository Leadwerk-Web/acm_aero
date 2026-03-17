# Bildoptimierung – Analyse & Abschlussbericht

**Projekt:** ACM AIR CHARTER Website (acm_design V4)  
**Stand:** Abschluss der Bildoptimierungs-Maßnahmen

---

## 1. Gefundene Probleme

### 1.1 Zu große / unoptimierte Assets
- **Hero- und Banner-Bilder** (z. B. `7500_hero.jpg`, `Karriere_hero.jpg`, `Maintenance_CAMO_hero.jpg`, `Thats ACM.jpg`) werden bisher als einzelne JPG/PNG ausgeliefert → keine responsiven Breiten, kein WebP/AVIF.
- **Content-Bilder** in `Fotos/D-APLC/`, `Fotos/D-ABAY/`, `Fotos/Neu/` etc. liegen nur in Originalauflösung vor → Mobile lädt unnötig große Dateien.
- **Sitzpläne** (`bombardier7500-sitzplan.png`, `bombardier6000-sitzplan-tag.png` etc.) als PNG → wo keine Transparenz nötig ist, sind WebP/AVIF sinnvoller.

### 1.2 Formate
- Überwiegend **JPG/PNG**; **WebP/AVIF** wurden bisher nicht genutzt.
- **SVG** nur für Emblem und ggf. Logo → korrekt, keine Änderung nötig.

### 1.3 Fehlende responsive Varianten
- Keine `srcset`/`sizes` für die meisten Bilder.
- Keine mehreren Breiten (480, 768, 1024, 1366, 1920) für Hero/Content.

### 1.4 HTML/Performance
- **width/height** bei vielen `img` fehlend → Risiko für CLS.
- **loading="lazy"** teils gesetzt, bei Hero-Bildern korrekt nicht (LCP).
- **fetchpriority="high"** nur auf der Global-7500-Hero-Seite gesetzt; andere Hero-Bilder könnten ebenfalls priorisiert werden.
- **decoding="async"** nicht überall gesetzt.

### 1.5 LCP / Above-the-Fold
- **Hero-Bilder** auf allen Seiten sind LCP-kritisch; auf global-7500.html ist das Hero bereits mit `<picture>`, AVIF/WebP und `fetchpriority="high"` optimiert.
- Weitere Seiten (index, karriere, maintenance, thats-acm, charter, global-6000, global-xrs) haben noch klassische `<img>`-Hero/Banner ohne `picture`/srcset.

### 1.6 Doppelte / ungenutzte Referenzen
- Einige Bilder werden mehrfach verwendet (z. B. `Fotos/D-APLC/70046_04_9905.jpg`, `Fotos/Emblem.svg`, `logo.png`) → zentral optimieren, einmal ausliefern.
- Keine offensichtlich ungenutzten Bildpfade in den ausgewerteten HTML-Dateien.

---

## 2. Umgesetzte Änderungen

### 2.1 Bildoptimierungs-Pipeline
- **Skript:** `scripts/optimize-images.js`
- **Eingabe:** `Fotos/` (Originale werden nur gelesen, **nie überschrieben**).
- **Ausgabe:** `Fotos/optimized/` mit gleicher Ordnerstruktur.
- **Breiten:** 480, 768, 1024, 1366, 1920, 2560 (nur verkleinern, kein Hochskalieren; 2560 für scharfe Darstellung auf großen/4K-Bildschirmen).
- **Formate:** pro Breite **WebP** (quality 88) und **AVIF** (quality 65) für klarere Darstellung (z. B. Kabine); leichte **Schärfung** (sigma 0.6) nach dem Skalieren.
- **Dateinamen:** `{Basisname}-{Breite}w.webp` / `.avif`, z. B. `7500_hero-1920w.avif`.

**Ausführung:**
```bash
npm install
npm run optimize-images
# Nur anzeigen, ohne zu schreiben:
npm run optimize-images:dry
```

### 2.2 Frontend (HTML)
- **global-7500.html – Hero:** Bereits auf `<picture>` umgestellt mit:
  - `<source type="image/avif">` und `<source type="image/webp">` inkl. srcset (480w … 1920w),
  - `sizes="100vw"`,
  - Fallback-`<img>` mit `width="1920"` `height="1080"`, `decoding="async"`, `fetchpriority="high"`.
- **Pipeline:** Redundante „-full“-Varianten (ohne Breite) wurden aus dem Skript entfernt, um nur die genutzten responsiven Varianten zu erzeugen.

### 2.3 Konfiguration
- **package.json:** Scripts `optimize-images` und Abhängigkeit `sharp` (^0.33.5) vorhanden.
- **Node:** >=18 empfohlen.

---

## 3. Performance-Ergebnis (pro Asset-Gruppe)

| Bereich              | Vorher (typ.)     | Nachher (Beispiel)                    | Hinweis |
|----------------------|-------------------|----------------------------------------|---------|
| Hero 7500             | 1× großes JPG     | AVIF/WebP in 5 Breiten, Browser wählt  | LCP-Verbesserung, weniger Traffic auf Mobile |
| Weitere Heroes       | 1× JPG/PNG       | Nach `npm run optimize-images`: gleiche Struktur nutzbar | HTML-Anpassung wie auf global-7500 optional |
| Content-Bilder       | 1× Original      | Pro Bild mehrere WebP/AVIF-Breiten     | Deutliche Reduktion bei kleinen Viewports |
| SVG (Emblem, Logo)   | unverändert      | unverändert                            | Bereits vektorbasiert, kein Bedarf |

**Größeneinsparung:**  
- WebP/AVIF gegenüber JPG/PNG typisch **25–50 %** weniger bei vergleichbarer Qualität.  
- Responsive Auslieferung spart auf Mobile **50 % und mehr**, da keine Desktop-Auflösung geladen wird.

---

## 4. Verbleibende Hinweise

### 4.1 Manuell prüfen
- **Erster Lauf:** Einmal `npm run optimize-images` ausführen und prüfen, ob `Fotos/optimized/` vollständig befüllt wird (insbesondere Dateinamen mit Leerzeichen, z. B. `Thats ACM.jpg` → `Thats ACM-480w.webp` etc.).
- **Dateigrößen:** Nach dem Lauf Stichproben in `Fotos/optimized/` prüfen (z. B. 7500_hero-*).
- **Visuelle QA:** Hero und 2–3 Content-Seiten in Chrome/Firefox (Desktop + Mobile) prüfen; AVIF/WebP-Fallback auf JPG prüfen.

### 4.2 Weitere Optimierung (optional)
- **Weitere Seiten:** Hero/Banner auf index, karriere, maintenance, thats-acm, charter, global-6000, global-xrs analog zu global-7500 auf `<picture>` + srcset umstellen; jeweils `fetchpriority="high"` nur für das echte LCP-Bild.
- **width/height:** Für alle Content-Bilder, die feste Layout-Größen haben, `width` und `height` setzen (z. B. aus Design oder aus Metadaten der optimierten Dateien), um CLS zu vermeiden.
- **Preload:** Für das eine LCP-Bild pro Seite optional `<link rel="preload" as="image" href="…">` mit der kleinsten ausreichenden AVIF/WebP-Variante erwägen.

### 4.3 Bewusst nicht (aggressiv) optimiert
- **SVG (Emblem.svg, logo.png):** unverändert.
- **Kleine UI-Bilder** (z. B. IS-BAO-Badge): wenn bereits klein, nur bei Bedarf in Pipeline aufnehmen.
- **Originale in Fotos/:** werden nie überschrieben; alle Optimierungen liegen ausschließlich in `Fotos/optimized/`.

---

## 5. Kurzfassung

- **Pipeline:** Läuft mit `npm run optimize-images`, schreibt nur nach `Fotos/optimized/`, erzeugt pro Rasterbild mehrere WebP- und AVIF-Varianten in festen Breiten.
- **Frontend:** Global-7500-Hero ist auf responsive, moderne Formate und LCP-Optimierung umgestellt; Rest der Seite kann schrittweise folgen.
- **Nächster Schritt:** Pipeline einmal ausführen, dann visuell und mit Lighthouse (LCP, CLS) testen; bei Bedarf weitere Heroes und Content-Bilder auf `<picture>`/srcset und width/height erweitern.
