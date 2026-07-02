#!/usr/bin/env node
import fs from 'node:fs/promises';
import fssync from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '..');
const baseUrl = 'https://www.acm.aero';
const sourceRoot = path.join(root, 'leadwerk_importer', 'source_assets');
const mappingPath = path.join(root, 'leadwerk_importer', 'manifest', 'mapping.json');

const pages = [
  { source_key: 'acm-index-v1', field_name: 'acm_index_sections', source_file: 'index.html', title: 'ACM AIR CHARTER', slug: 'home', live_path: '/', is_front_page: true },
  { source_key: 'acm-thats-acm-v1', field_name: 'acm_thats_acm_sections', source_file: 'thats-acm.html', title: "That's ACM", slug: 'thats-acm', live_path: '/thats-acm/' },
  { source_key: 'acm-charter-v1', field_name: 'acm_charter_sections', source_file: 'charter.html', title: 'Charter', slug: 'charter', live_path: '/charter/' },
  { source_key: 'acm-global-7500-v1', field_name: 'acm_global7500_sections', source_file: 'global-7500.html', title: 'Bombardier Global 7500', slug: 'global-7500', live_path: '/global-7500/' },
  { source_key: 'acm-global-6000-v1', field_name: 'acm_global6000_sections', source_file: 'global-6000.html', title: 'Bombardier Global 6000', slug: 'global-6000', live_path: '/global-6000/' },
  { source_key: 'acm-global-xrs-v1', field_name: 'acm_globalxrs_sections', source_file: 'global-xrs.html', title: 'Bombardier Global XRS', slug: 'global-xrs', live_path: '/global-xrs/' },
  { source_key: 'acm-aircraft-v1', field_name: 'acm_aircraft_sections', source_file: 'aircraft-management.html', title: 'Aircraft Management', slug: 'aircraft-management', live_path: '/aircraft-management/' },
  { source_key: 'acm-maintenance-v1', field_name: 'acm_maintenance_sections', source_file: 'maintenance.html', title: 'Maintenance', slug: 'maintenance', live_path: '/maintenance/' },
  { source_key: 'acm-careers-v1', field_name: 'acm_careers_sections', source_file: 'karriere.html', title: 'Karriere', slug: 'karriere', live_path: '/karriere/' },
  { source_key: 'acm-contact-v1', field_name: 'acm_contact_sections', source_file: 'kontakt.html', title: 'Kontakt', slug: 'kontakt', live_path: '/kontakt/' },
  { source_key: 'acm-news-v1', field_name: 'acm_news_sections', source_file: 'news.html', title: 'News', slug: 'news', live_path: '/news/' },
  { source_key: 'acm-impressum-v1', field_name: 'impressum_page', source_file: 'impressum.html', title: 'Impressum', slug: 'impressum', live_path: '/impressum/' },
  { source_key: 'acm-datenschutz-v1', field_name: 'datenschutz_page', source_file: 'datenschutz.html', title: 'Datenschutz', slug: 'datenschutz', live_path: '/datenschutz/' },
  { source_key: 'acm-agb-v1', field_name: 'agb_page', source_file: 'agb.html', title: 'AGB', slug: 'agb', live_path: '/agb/' },
];

const knownPageByPath = new Map();
for (const page of pages) {
  knownPageByPath.set(page.live_path.replace(/^\/|\/$/g, ''), page.source_file);
}
knownPageByPath.set('', 'index.html');

const downloads = new Map();

function toPosix(value) {
  return value.replace(/\\/g, '/');
}

function encodeRelForHtml(rel) {
  return rel.split('/').map((part) => encodeURIComponent(decodeURIComponent(part))).join('/');
}

function decodeRelForFs(rel) {
  return rel.split('/').map((part) => decodeURIComponent(part)).join(path.sep);
}

function depthPrefix(relPath) {
  const depth = toPosix(relPath).split('/').length - 1;
  return depth > 0 ? '../'.repeat(depth) : '';
}

function htmlRel(relPath, targetRel) {
  return depthPrefix(relPath) + encodeRelForHtml(targetRel);
}

function cleanRemoteUrl(raw) {
  const normalized = raw
    .replace(/^\/\//, 'https://')
    .replace(/&amp;/g, '&')
    .replace(/\\\//g, '/');
  const url = new URL(normalized, baseUrl);
  url.search = '';
  url.hash = '';
  return url.toString();
}

function registerDownload(remoteUrl, localRel) {
  const clean = cleanRemoteUrl(remoteUrl);
  downloads.set(`${clean} => ${localRel}`, { url: clean, rel: localRel });
  return localRel;
}

function isDownloadableMediaPath(pathname) {
  if (/[{}]/.test(pathname)) {
    return false;
  }
  return /\.(?:jpe?g|png|webp|gif|svg|mp4|webm|mov|pdf|ico)$/i.test(pathname);
}

function mapUpload(remoteUrl) {
  const clean = cleanRemoteUrl(remoteUrl);
  const url = new URL(clean);
  if (!isDownloadableMediaPath(url.pathname)) {
    return '';
  }
  const rel = decodeURIComponent(url.pathname.replace(/^\/wp-content\/uploads\//, ''));
  return registerDownload(clean, `Fotos/uploads/${rel}`);
}

function mapThemeFotos(remoteUrl) {
  const clean = cleanRemoteUrl(remoteUrl);
  const url = new URL(clean);
  const rel = decodeURIComponent(url.pathname.replace(/^\/wp-content\/themes\/leadwerk_theme\/Fotos\//, ''));
  return registerDownload(clean, `Fotos/${rel}`);
}

function mapThemeImage(remoteUrl) {
  const clean = cleanRemoteUrl(remoteUrl);
  const url = new URL(clean);
  const filename = decodeURIComponent(path.posix.basename(url.pathname));
  return registerDownload(clean, `Fotos/${filename}`);
}

function mapSiteUrl(raw, relPath) {
  let parsed;
  try {
    parsed = new URL(raw, baseUrl);
  } catch {
    return raw;
  }

  if (!['www.acm.aero', 'acm.aero'].includes(parsed.hostname)) {
    return raw;
  }

  const posixPath = parsed.pathname.replace(/^\/+|\/+$/g, '');
  if (posixPath.startsWith('wp-content/') || posixPath.startsWith('wp-json') || posixPath.startsWith('feed')) {
    return raw;
  }
  if (posixPath.startsWith('en/')) {
    return raw;
  }

  let targetRel = '';
  if (posixPath.startsWith('news/')) {
    const parts = posixPath.split('/').filter(Boolean);
    const slug = parts[1] || '';
    if (!slug) {
      targetRel = 'news.html';
    } else {
      const currentIsNewsSingle = toPosix(relPath).startsWith('news/');
      return `${currentIsNewsSingle ? `${slug}.html` : htmlRel(relPath, `news/${slug}.html`)}${parsed.hash || ''}`;
    }
  } else if (knownPageByPath.has(posixPath)) {
    targetRel = knownPageByPath.get(posixPath);
  }

  if (!targetRel) {
    return raw;
  }

  const hash = parsed.hash || '';
  return htmlRel(relPath, targetRel) + hash;
}

function mapResourceUrl(raw, relPath) {
  const value = raw.trim();
  if (!value || value.startsWith('#') || /^(mailto|tel):/i.test(value)) {
    return raw;
  }

  const normalized = value.replace(/\\\//g, '/');
  if (/^(https?:)?\/\/www\.acm\.aero\/wp-content\/uploads\//i.test(normalized) || /^\/wp-content\/uploads\//i.test(normalized)) {
    const absolute = normalized.startsWith('/') ? `${baseUrl}${normalized}` : normalized;
    const mapped = mapUpload(absolute);
    return mapped ? htmlRel(relPath, mapped) : raw;
  }
  if (/^(https?:)?\/\/www\.acm\.aero\/wp-content\/themes\/leadwerk_theme\/Fotos\//i.test(normalized) || /^\/wp-content\/themes\/leadwerk_theme\/Fotos\//i.test(normalized)) {
    const absolute = normalized.startsWith('/') ? `${baseUrl}${normalized}` : normalized;
    return htmlRel(relPath, mapThemeFotos(absolute));
  }
  if (/^(https?:)?\/\/www\.acm\.aero\/wp-content\/themes\/leadwerk_theme\/assets\/images\//i.test(normalized) || /^\/wp-content\/themes\/leadwerk_theme\/assets\/images\//i.test(normalized)) {
    const absolute = normalized.startsWith('/') ? `${baseUrl}${normalized}` : normalized;
    return htmlRel(relPath, mapThemeImage(absolute));
  }
  if (/^https?:\/\/(www\.)?acm\.aero\//i.test(normalized) || /^\/(?!\/)/.test(normalized)) {
    return mapSiteUrl(normalized, relPath);
  }

  return raw;
}

function rewriteAttributes(html, relPath) {
  return html.replace(/\b(href|src|poster|action|content)=("([^"]*)"|'([^']*)')/gi, (match, attr, quoted, doubleValue, singleValue) => {
    const quote = quoted[0];
    const value = doubleValue ?? singleValue ?? '';
    const next = mapResourceUrl(value, relPath);
    return `${attr}=${quote}${next}${quote}`;
  });
}

function rewriteSrcsets(html, relPath) {
  return html.replace(/\bsrcset=("([^"]*)"|'([^']*)')/gi, (match, quoted, doubleValue, singleValue) => {
    const quote = quoted[0];
    const value = doubleValue ?? singleValue ?? '';
    const next = value.split(',').map((candidate) => {
      const trimmed = candidate.trim();
      const parts = trimmed.split(/\s+/);
      if (!parts[0]) {
        return trimmed;
      }
      parts[0] = mapResourceUrl(parts[0], relPath);
      return parts.join(' ');
    }).join(', ');
    return `srcset=${quote}${next}${quote}`;
  });
}

function rewriteInlineRemoteAssets(html, relPath) {
  let next = html.replace(/https?:\/\/www\.acm\.aero\/wp-content\/uploads\/[^\s"'()<>,]+\.(?:jpe?g|png|webp|gif|svg|mp4|webm|mov|pdf|ico)(?:\?[^\s"'()<>,]+)?/gi, (url) => {
    const mapped = mapUpload(url);
    return mapped ? htmlRel(relPath, mapped) : url;
  });
  next = next.replace(/https?:\/\/www\.acm\.aero\/wp-content\/themes\/leadwerk_theme\/Fotos\/[^\s"'()<>,]+/gi, (url) => htmlRel(relPath, mapThemeFotos(url)));
  next = next.replace(/https?:\/\/www\.acm\.aero\/wp-content\/themes\/leadwerk_theme\/assets\/images\/[^\s"'()<>,]+/gi, (url) => htmlRel(relPath, mapThemeImage(url)));
  return next;
}

function rewriteNewsHero(html, relPath, slug, rawHtml) {
  const figureMatch = rawHtml.match(/<figure[^>]*\barticle-figure\b[^>]*>[\s\S]*?<\/figure>/i);
  if (!figureMatch) {
    return html;
  }
  const srcMatch = figureMatch[0].match(/<img[^>]+\bsrc=(?:"([^"]+)"|'([^']+)')/i);
  if (!srcMatch) {
    return html;
  }

  const sourceUrl = srcMatch[1] || srcMatch[2];
  let ext = path.posix.extname(new URL(sourceUrl, baseUrl).pathname).toLowerCase();
  if (!ext || ext.length > 6) {
    ext = '.jpg';
  }
  const heroRel = registerDownload(sourceUrl, `Fotos/news/${slug}${ext}`);
  const heroHtmlPath = htmlRel(relPath, heroRel);

  let replaced = false;
  return html.replace(/<figure[^>]*\barticle-figure\b[^>]*>[\s\S]*?<\/figure>/i, (figure) => {
    if (replaced) {
      return figure;
    }
    replaced = true;
    return figure
      .replace(/\s+srcset=(?:"[^"]*"|'[^']*')/i, '')
      .replace(/\s+sizes=(?:"[^"]*"|'[^']*')/i, '')
      .replace(/(<img[^>]+\bsrc=)(?:"[^"]*"|'[^']*')/i, `$1"${heroHtmlPath}"`);
  });
}

function normalizeHtml(rawHtml, relPath, slug = '') {
  let html = rawHtml;
  html = rewriteInlineRemoteAssets(html, relPath);
  html = rewriteSrcsets(html, relPath);
  html = rewriteAttributes(html, relPath);
  if (slug) {
    html = rewriteNewsHero(html, relPath, slug, rawHtml);
  }
  return html.replace(/\r\n/g, '\n');
}

async function fetchText(url) {
  const response = await fetch(url, {
    headers: {
      'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'user-agent': 'ACM-Leadwerk-LiveSync/1.0',
    },
  });
  if (!response.ok) {
    throw new Error(`Fetch failed ${response.status}: ${url}`);
  }
  return await response.text();
}

async function fetchBuffer(url) {
  const response = await fetch(url, {
    headers: {
      'accept': 'image/avif,image/webp,image/apng,image/svg+xml,image/*,video/*,*/*;q=0.8',
      'user-agent': 'ACM-Leadwerk-LiveSync/1.0',
    },
  });
  if (!response.ok) {
    throw new Error(`Asset fetch failed ${response.status}: ${url}`);
  }
  return Buffer.from(await response.arrayBuffer());
}

async function writeFile(relPath, contents) {
  const dest = path.join(sourceRoot, ...toPosix(relPath).split('/'));
  await fs.mkdir(path.dirname(dest), { recursive: true });
  await fs.writeFile(dest, contents, 'utf8');
}

async function writeSupportFile(relPath, contents) {
  const targets = [
    path.join(sourceRoot, ...toPosix(relPath).split('/')),
    path.join(root, ...toPosix(relPath).split('/')),
  ];
  for (const dest of targets) {
    await fs.mkdir(path.dirname(dest), { recursive: true });
    await fs.writeFile(dest, contents, 'utf8');
  }
}

async function downloadAssets() {
  for (const { url, rel } of downloads.values()) {
    const dest = path.join(sourceRoot, decodeRelForFs(rel));
    await fs.mkdir(path.dirname(dest), { recursive: true });
    try {
      const bytes = await fetchBuffer(url);
      await fs.writeFile(dest, bytes);
      console.log(`asset ${rel} <= ${url}`);
    } catch (error) {
      if (fssync.existsSync(dest)) {
        console.log(`asset ${rel} kept existing after fetch warning`);
        continue;
      }
      const fallback = path.join(sourceRoot, path.basename(decodeRelForFs(rel)));
      if (fssync.existsSync(fallback)) {
        await fs.copyFile(fallback, dest);
        console.log(`asset ${rel} <= local ${path.relative(root, fallback)}`);
        continue;
      }
      console.warn(`asset skipped ${rel}: ${error instanceof Error ? error.message : error}`);
    }
  }
}

async function copyDir(src, dest) {
  if (!fssync.existsSync(src)) {
    return;
  }
  await fs.mkdir(dest, { recursive: true });
  const entries = await fs.readdir(src, { withFileTypes: true });
  for (const entry of entries) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      await copyDir(from, to);
    } else if (entry.isFile()) {
      await fs.mkdir(path.dirname(to), { recursive: true });
      await fs.copyFile(from, to);
    }
  }
}

async function removeObsoleteNews(newsSlugs) {
  const allowed = new Set(newsSlugs.map((slug) => `news/${slug}.html`));
  const roots = [sourceRoot, root];
  for (const base of roots) {
    const newsDir = path.join(base, 'news');
    if (!fssync.existsSync(newsDir)) {
      continue;
    }
    const entries = await fs.readdir(newsDir, { withFileTypes: true });
    for (const entry of entries) {
      if (!entry.isFile() || !entry.name.endsWith('.html')) {
        continue;
      }
      const rel = `news/${entry.name}`;
      if (!allowed.has(rel)) {
        await fs.rm(path.join(newsDir, entry.name), { force: true });
        console.log(`removed obsolete ${path.relative(root, path.join(newsDir, entry.name))}`);
      }
    }
  }
}

async function readNewsSlugs() {
  const sitemap = await fetchText(`${baseUrl}/acm_news-sitemap.xml`);
  const slugs = [];
  for (const match of sitemap.matchAll(/<loc>\s*https:\/\/www\.acm\.aero\/news\/([^/<]+)\/\s*<\/loc>/gi)) {
    const slug = match[1];
    if (!slugs.includes(slug)) {
      slugs.push(slug);
    }
  }
  if (slugs.length > 0) {
    return slugs;
  }

  const newsHtml = await fetchText(`${baseUrl}/news/`);
  for (const match of newsHtml.matchAll(/https:\/\/www\.acm\.aero\/news\/([^/"'<]+)\//gi)) {
    const slug = match[1];
    if (!slugs.includes(slug)) {
      slugs.push(slug);
    }
  }
  return slugs;
}

async function updateMapping(newsSlugs) {
  const mapping = JSON.parse(await fs.readFile(mappingPath, 'utf8'));
  const existingPages = new Map((mapping.pages || []).map((page) => [page.source_key, page]));
  const nextPages = pages.map((page) => ({
    ...(existingPages.get(page.source_key) || {}),
    source_key: page.source_key,
    field_name: page.field_name,
    source_file: page.source_file,
    title: page.title,
    slug: page.slug,
    target_type: 'page',
    post_status: 'publish',
    ...(page.is_front_page ? { is_front_page: true } : {}),
  }));

  const existing404 = existingPages.get('acm-404-v1');
  if (existing404) {
    nextPages.push(existing404);
  }

  mapping.pages = nextPages;
  mapping.news_articles = newsSlugs.map((slug) => ({
    source_file: `news/${slug}.html`,
    target_type: 'acm_news',
  }));

  await fs.writeFile(mappingPath, `${JSON.stringify(mapping, null, 2)}\n`, 'utf8');
}

async function main() {
  await fs.mkdir(sourceRoot, { recursive: true });
  const newsSlugs = await readNewsSlugs();
  if (newsSlugs.length < 1) {
    throw new Error('No live news slugs found.');
  }
  console.log(`news slugs: ${newsSlugs.length}`);

  for (const page of pages) {
    const url = `${baseUrl}${page.live_path}`;
    const rawHtml = await fetchText(url);
    await writeFile(page.source_file, normalizeHtml(rawHtml, page.source_file));
    console.log(`page ${page.source_file} <= ${url}`);
  }

  for (const slug of newsSlugs) {
    const rel = `news/${slug}.html`;
    const url = `${baseUrl}/news/${slug}/`;
    const rawHtml = await fetchText(url);
    await writeFile(rel, normalizeHtml(rawHtml, rel, slug));
    console.log(`news ${rel} <= ${url}`);
  }

  await updateMapping(newsSlugs);
  await removeObsoleteNews(newsSlugs);

  for (const rel of ['robots.txt', 'sitemap_index.xml', 'page-sitemap.xml', 'acm_news-sitemap.xml']) {
    const livePath = rel === 'sitemap_index.xml' ? '/sitemap_index.xml' : `/${rel}`;
    const contents = await fetchText(`${baseUrl}${livePath}`);
    await writeSupportFile(rel, contents.replace(/\r\n/g, '\n'));
    console.log(`support ${rel} <= ${baseUrl}${livePath}`);
  }

  await downloadAssets();
  await copyDir(path.join(sourceRoot, 'Fotos'), path.join(root, 'Fotos'));

  console.log(`done: ${pages.length} pages, ${newsSlugs.length} news articles, ${downloads.size} assets`);
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : error);
  process.exitCode = 1;
});
