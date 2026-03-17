#!/usr/bin/env node
/**
 * ACM AIR CHARTER – Bildoptimierungs-Pipeline
 *
 * Liest Rasterbilder aus Fotos/, erzeugt WebP + AVIF in mehreren Breiten
 * und schreibt sie nach Fotos/optimized/. Originale werden nie verändert.
 *
 * Nutzung:
 *   npm run optimize-images
 *   npm run optimize-images:dry   (nur anzeigen, nichts schreiben)
 *
 * Konfiguration: siehe CONFIG unten.
 */

const fs = require("fs");
const path = require("path");
const sharp = require("sharp");

const DRY_RUN = process.argv.includes("--dry-run");

const CONFIG = {
  inputDir: path.join(__dirname, "..", "Fotos"),
  outputDir: path.join(__dirname, "..", "Fotos", "optimized"),
  widths: [480, 768, 1024, 1366, 1920, 2560],
  webp: { quality: 88, effort: 6 },
  avif: { quality: 65, effort: 6 },
  skipDirs: ["optimized"],
  extensions: [".jpg", ".jpeg", ".png"],
  sharpen: { sigma: 0.6 },
};

function getAllImagePaths(dir, base = "") {
  const results = [];
  if (!fs.existsSync(dir)) return results;
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const e of entries) {
    const rel = base ? `${base}/${e.name}` : e.name;
    if (e.isDirectory()) {
      if (CONFIG.skipDirs.includes(e.name)) continue;
      results.push(...getAllImagePaths(path.join(dir, e.name), rel));
    } else if (e.isFile()) {
      const ext = path.extname(e.name).toLowerCase();
      if (CONFIG.extensions.includes(ext)) results.push(rel);
    }
  }
  return results;
}

function ensureDir(filePath) {
  const dir = path.dirname(filePath);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

async function optimizeFile(relPath) {
  const inputPath = path.join(CONFIG.inputDir, relPath);
  if (!fs.existsSync(inputPath)) return { skipped: true, reason: "missing" };

  const ext = path.extname(relPath).toLowerCase();
  const baseName = path.basename(relPath, ext);
  const outSubDir = path.dirname(relPath);
  const outBase = path.join(CONFIG.outputDir, outSubDir);

  let meta;
  try {
    meta = await sharp(inputPath).metadata();
  } catch (err) {
    return { skipped: true, reason: "read_error", error: err.message };
  }

  const origW = meta.width || 0;
  const origH = meta.height || 0;
  const widths = CONFIG.widths.filter((w) => w < origW);
  if (widths.length === 0 && origW > 0) widths.push(origW);
  if (widths.length === 0) widths.push(Math.min(1920, origW || 1920));

  const created = [];

  for (const w of widths) {
    const webpPath = path.join(outBase, `${baseName}-${w}w.webp`);
    const avifPath = path.join(outBase, `${baseName}-${w}w.avif`);

    if (!DRY_RUN) {
      ensureDir(webpPath);
      ensureDir(avifPath);
      const ro = { withoutEnlargement: true };
      const step = (s) => (CONFIG.sharpen ? s.sharpen(CONFIG.sharpen) : s);
      await step(sharp(inputPath).resize(w, null, ro)).webp(CONFIG.webp).toFile(webpPath);
      created.push(webpPath);
      await step(sharp(inputPath).resize(w, null, ro)).avif(CONFIG.avif).toFile(avifPath);
      created.push(avifPath);
    } else {
      created.push(webpPath, avifPath);
    }
  }

  return {
    skipped: false,
    original: relPath,
    width: origW,
    height: origH,
    variants: widths,
    created,
  };
}

async function main() {
  console.log("Input:  ", CONFIG.inputDir);
  console.log("Output: ", CONFIG.outputDir);
  console.log("Widths: ", CONFIG.widths.join(", "));
  if (DRY_RUN) console.log("[DRY RUN – keine Dateien geschrieben]\n");

  if (!fs.existsSync(CONFIG.inputDir)) {
    console.error("Eingabeverzeichnis existiert nicht:", CONFIG.inputDir);
    process.exit(1);
  }

  const files = getAllImagePaths(CONFIG.inputDir);
  console.log("Gefundene Rasterbilder:", files.length, "\n");

  const results = { ok: 0, skipped: 0, errors: [] };
  for (const rel of files) {
    const r = await optimizeFile(rel);
    if (r.skipped) {
      results.skipped++;
      if (r.reason !== "missing") console.log("Skip:", rel, r.reason);
    } else {
      results.ok++;
      if (!DRY_RUN) console.log("OK:", rel, "->", r.created.length, "Dateien");
    }
  }

  console.log("\nFertig:", results.ok, "optimiert,", results.skipped, "übersprungen.");
  if (results.errors.length) console.log("Fehler:", results.errors.length);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
