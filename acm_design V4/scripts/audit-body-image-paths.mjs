#!/usr/bin/env node
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manifestPath = path.join(repoRoot, 'leadwerk_importer', 'manifest', 'import-manifest.json');
const sourceRoot = path.join(repoRoot, 'leadwerk_importer', 'source_assets');
const outputPath = path.join(repoRoot, 'tmp', 'acm-de-body-image-paths.json');
const imageExtensions = new Set(['.webp', '.jpg', '.jpeg', '.png', '.gif', '.svg', '.ico']);

function decodeHtml(value) {
  return String(value)
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(Number.parseInt(hex, 16)))
    .replace(/&#([0-9]+);/g, (_, num) => String.fromCodePoint(Number.parseInt(num, 10)));
}

function safeDecodeUri(value) {
  try {
    return decodeURI(value);
  } catch {
    return value;
  }
}

function normalizeMediaRef(value) {
  let raw = decodeHtml(value).trim();
  if (!raw || /^(?:data|mailto|tel|javascript):/i.test(raw) || raw.startsWith('#')) {
    return '';
  }

  if (/^(?:https?:)?\/\//i.test(raw)) {
    try {
      const url = new URL(raw.startsWith('//') ? `https:${raw}` : raw);
      raw = url.pathname;
    } catch {
      return '';
    }
  }

  raw = safeDecodeUri(raw).replace(/\\/g, '/').replace(/[?#].*$/, '').replace(/^\.\//, '');
  const markers = [
    ['/wp-content/uploads/', 'Fotos/uploads/'],
    ['/Fotos/', 'Fotos/'],
    ['/fotos/', 'Fotos/'],
    ['/assets/images/', 'assets/images/'],
    ['/leadwerk_importer/source_assets/', ''],
    ['/leadwerk_theme/', ''],
  ];

  const lower = raw.toLowerCase();
  for (const [marker, prefix] of markers) {
    const index = lower.indexOf(marker.toLowerCase());
    if (index !== -1) {
      return `${prefix}${raw.slice(index + marker.length).replace(/^\/+/, '')}`.replace(/^\/+|\/+$/g, '');
    }
  }

  raw = raw.replace(/^\/+/, '');
  if (/^wp-content\/uploads\//i.test(raw)) {
    return `Fotos/uploads/${raw.slice('wp-content/uploads/'.length)}`;
  }
  if (/^uploads\//i.test(raw)) {
    return `Fotos/${raw}`;
  }
  return raw.replace(/^\/+|\/+$/g, '');
}

function hasImageExtension(value) {
  const ext = path.extname(normalizeMediaRef(value)).toLowerCase();
  return imageExtensions.has(ext);
}

function sourceCandidates(normalized) {
  if (!normalized) {
    return [];
  }
  const candidates = [normalized];
  const ext = path.extname(normalized).toLowerCase();
  if (['.webp', '.jpg', '.jpeg', '.png'].includes(ext)) {
    const parsed = path.posix.parse(normalized.replace(/\\/g, '/'));
    for (const siblingExt of ['.webp', '.jpg', '.jpeg', '.png']) {
      candidates.push(path.posix.join(parsed.dir, `${parsed.name}${siblingExt}`));
    }
  }
  return [...new Set(candidates.filter(Boolean))];
}

async function firstExistingCandidate(normalized) {
  for (const candidate of sourceCandidates(normalized)) {
    try {
      const stat = await fs.stat(path.join(sourceRoot, candidate));
      if (stat.isFile()) {
        return candidate;
      }
    } catch {
      // Continue.
    }
  }
  return '';
}

function bodyOf(html) {
  const match = html.match(/<body\b[^>]*>([\s\S]*?)<\/body>/i);
  return match ? match[1] : html;
}

function pushRef(refs, kind, value, context) {
  const normalized = normalizeMediaRef(value);
  if (!normalized || !hasImageExtension(normalized)) {
    return;
  }
  refs.push({ kind, value: decodeHtml(value).trim(), normalized, context });
}

function extractRefs(body) {
  const refs = [];

  for (const match of body.matchAll(/<[^>]+\s(src|poster|data-src|data-bg|data-img)\s*=\s*(["'])(.*?)\2/gi)) {
    pushRef(refs, match[1].toLowerCase(), match[3], match[0].slice(0, 220));
  }

  for (const match of body.matchAll(/<[^>]+\ssrcset\s*=\s*(["'])(.*?)\1/gi)) {
    const srcset = decodeHtml(match[2]);
    for (const part of srcset.split(',')) {
      const url = part.trim().replace(/\s+\d+(?:w|x)$/i, '').trim();
      pushRef(refs, 'srcset', url, match[0].slice(0, 220));
    }
  }

  for (const match of body.matchAll(/url\(\s*(["']?)(.*?)\1\s*\)/gi)) {
    pushRef(refs, 'style-url', match[2], match[0]);
  }

  return refs;
}

async function main() {
  const manifest = JSON.parse(await fs.readFile(manifestPath, 'utf8'));
  const items = Array.isArray(manifest.items) ? manifest.items : [];
  const pages = [];
  const unique = new Map();

  for (const item of items) {
    if (!item.source) {
      continue;
    }
    const sourcePath = path.join(sourceRoot, item.source);
    const html = await fs.readFile(sourcePath, 'utf8');
    const refs = extractRefs(bodyOf(html));
    for (const ref of refs) {
      ref.existing_source_path = await firstExistingCandidate(ref.normalized);
      ref.exists_in_source_assets = ref.existing_source_path !== '';
      unique.set(ref.normalized, ref);
    }
    pages.push({
      type: item.type,
      import_key: item.import_key,
      source_key: item.source_key,
      source: item.source,
      title: item.title,
      body_image_ref_count: refs.length,
      refs,
    });
  }

  const uniqueRefs = [...unique.values()].sort((a, b) => a.normalized.localeCompare(b.normalized));
  const report = {
    generated_at: new Date().toISOString(),
    manifest: path.relative(repoRoot, manifestPath).replace(/\\/g, '/'),
    source_root: path.relative(repoRoot, sourceRoot).replace(/\\/g, '/'),
    item_count: pages.length,
    body_image_ref_count: pages.reduce((sum, page) => sum + page.body_image_ref_count, 0),
    unique_body_image_ref_count: uniqueRefs.length,
    missing_unique_ref_count: uniqueRefs.filter((ref) => !ref.exists_in_source_assets).length,
    missing_unique_refs: uniqueRefs.filter((ref) => !ref.exists_in_source_assets),
    unique_refs: uniqueRefs,
    pages,
  };

  await fs.mkdir(path.dirname(outputPath), { recursive: true });
  await fs.writeFile(outputPath, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  console.log(`Wrote ${path.relative(repoRoot, outputPath).replace(/\\/g, '/')}`);
  console.log(`Pages: ${report.item_count}`);
  console.log(`Body image refs: ${report.body_image_ref_count}`);
  console.log(`Unique refs: ${report.unique_body_image_ref_count}`);
  console.log(`Missing unique refs: ${report.missing_unique_ref_count}`);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
