# -*- coding: utf-8 -*-
"""Normalize news article HTML to compact shell + fix UTF-8 mojibake (äöü)."""
from __future__ import annotations

import html
import re
from html import unescape
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
NEWS_DE = ROOT / "news"
NEWS_EN = ROOT / "en" / "news"

TAILWIND = """
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          maxWidth: { '6xl': '100rem', 'article': '42rem' },
          colors: {
            olive: { DEFAULT: '#001441', dark: '#000d2b', light: '#0a2a5c', lighter: '#E8EEF4' },
            accent: { DEFAULT: '#001441', hover: '#000d2b', light: '#E8EEF4' },
            sand: { 50: '#FDFCFA', 100: '#FAF8F5', 200: '#F5F2ED' }
          },
          fontFamily: { serif: ['Cormorant Garamond', 'Georgia', 'serif'], sans: ['Inter', 'system-ui', 'sans-serif'] }
        }
      }
    }
  </script>
"""

FOOTER_SCRIPT = """
  <script>
    (function () {
      var header = document.getElementById('header');
      function sc() { if (window.scrollY > 8) header.classList.add('header-scrolled'); else header.classList.remove('header-scrolled'); }
      window.addEventListener('scroll', sc, { passive: true }); sc();
      var dm = document.getElementById('desktop-menu'), db = document.getElementById('desktop-menu-backdrop');
      document.getElementById('desktop-menu-btn')?.addEventListener('click', function () { dm.classList.remove('hidden'); db.classList.remove('hidden'); requestAnimationFrame(function () { dm.classList.add('show'); db.classList.add('show'); }); });
      function closeM() { dm.classList.remove('show'); db.classList.remove('show'); setTimeout(function () { dm.classList.add('hidden'); db.classList.add('hidden'); }, 300); }
      document.getElementById('desktop-menu-close')?.addEventListener('click', closeM);
      db?.addEventListener('click', closeM);
      document.getElementById('mobile-menu-btn')?.addEventListener('click', function () {
        var m = document.getElementById('mobile-menu'); m.classList.toggle('hidden');
      });
      var st = document.getElementById('scroll-to-top');
      window.addEventListener('scroll', function () { if (window.scrollY > 400) st.classList.add('visible'); else st.classList.remove('visible'); }, { passive: true });
      st?.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
      if ('IntersectionObserver' in window) {
        var o = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('revealed'); o.unobserve(e.target); } }); }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
        document.querySelectorAll('.scroll-reveal').forEach(function (el) { o.observe(el); });
      } else {
        document.querySelectorAll('.scroll-reveal').forEach(function (el) { el.classList.add('revealed'); });
      }
    })();
  </script>
  <script src="../nav-active.js?v=20260319" defer></script>
"""


def fix_mojibake(s: str) -> str:
    if "Ã" not in s and "â" not in s and "Â" not in s:
        return s
    try:
        return s.encode("latin-1").decode("utf-8")
    except (UnicodeDecodeError, UnicodeEncodeError):
        return s


def extract_tag(html: str, tag: str) -> str:
    m = re.search(rf"<{tag}[^>]*>([\s\S]*?)</{tag}>", html, re.I)
    return m.group(1).strip() if m else ""


def extract_meta_content(html_text: str, name: str) -> str:
    m = re.search(
        rf'<meta\s+[^>]*name=["\']{re.escape(name)}["\'][^>]*content=["\']([^"\']*)["\']',
        html_text,
        re.I,
    )
    if m:
        return unescape(m.group(1))
    m = re.search(
        rf'<meta\s+[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']{re.escape(name)}["\']',
        html_text,
        re.I,
    )
    return unescape(m.group(1)) if m else ""


def extract_main_full(html_text: str) -> str | None:
    m = re.search(r"(<main[^>]*>[\s\S]*</main>)", html_text, re.I)
    return m.group(1) if m else None


def transform_old_main(full_main: str, lang: str) -> str:
    m = full_main
    m = re.sub(
        r'<main\s+class="article-shell pt-32 lg:pt-40"',
        '<main class="pt-28 lg:pt-36"',
        m,
    )
    m = re.sub(
        r'<main\s+class="([^"]*)\barticle-shell\b([^"]*)"',
        r'<main class="pt-28 lg:pt-36"',
        m,
        count=1,
    )

    back_de = """    <div class="max-w-6xl mx-auto px-6 sm:px-8 lg:px-12 pb-8">
      <a href="../news.html" class="article-back inline-flex items-center gap-2 scroll-reveal">
        <span aria-hidden="true">&larr;</span> Zurück zu News
      </a>
    </div>"""
    back_en = """    <div class="max-w-6xl mx-auto px-6 sm:px-8 lg:px-12 pb-8">
      <a href="../news.html" class="article-back inline-flex items-center gap-2 scroll-reveal">
        <span aria-hidden="true">&larr;</span> Back to news
      </a>
    </div>"""
    back = back_en if lang == "en" else back_de
    m = re.sub(
        r'<section class="pb-6 sm:pb-8">\s*<div class="max-w-6xl mx-auto px-6 sm:px-8 lg:px-12">\s*<a class="article-back-link scroll-reveal" href="\.\./news\.html">[\s\S]*?</a>\s*</div>\s*</section>',
        back,
        m,
        count=1,
    )

    m = m.replace(
        '<div class="max-w-4xl mx-auto px-6 sm:px-8 lg:px-12 text-center">',
        '<div class="max-w-article mx-auto px-6 sm:px-8 lg:px-0 text-center">',
    )
    m = m.replace(
        '<div class="max-w-3xl mx-auto px-6 sm:px-8 lg:px-12">',
        '<div class="max-w-article mx-auto px-6 sm:px-8 lg:px-0">',
    )
    m = re.sub(
        r'<article class="max-w-article mx-auto px-6 sm:px-8 lg:px-0">',
        '<article class="max-w-article mx-auto px-6 sm:px-8 lg:px-0 pb-20 lg:pb-24 article-body">',
        m,
        count=1,
    )
    m = m.replace("article-back-link", "article-back inline-flex items-center gap-2")
    m = re.sub(
        r'<div class="aspect-\[21/9\] overflow-hidden bg-stone-200">',
        r'<div class="aspect-[21/9] max-h-[380px] sm:max-h-[420px] overflow-hidden bg-stone-200">',
        m,
    )
    m = m.replace(
        'class="news-card scroll-reveal flex flex-col overflow-hidden"',
        'class="news-card-related flex flex-col overflow-hidden scroll-reveal"',
    )

    return m


def header_de(slug: str) -> str:
    return f"""  <header id="header" class="fixed top-0 left-0 right-0 z-50 w-full">
    <div class="w-full px-6 sm:px-8 lg:px-10">
      <div class="flex items-center justify-center h-16 lg:h-[4.25rem] relative">
        <button type="button" id="desktop-menu-btn" class="hidden lg:block absolute left-0 p-2 header-desktop-menu-btn" aria-label="Menü öffnen"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="hidden lg:flex absolute right-0 items-center gap-6">
          <div class="flex items-center gap-1 text-sm">
            <a href="{slug}" class="header-lang-link header-lang-active">DE</a>
            <span class="text-stone-300">|</span>
            <a href="../en/news/{slug}" class="header-lang-link">EN</a>
          </div>
          <a href="../kontakt.html#zentrale" class="header-link-button whitespace-nowrap">Kontakt aufnehmen</a>
        </div>
        <a href="../index.html" class="hidden lg:block"><img src="../logo.png" alt="ACM AIR CHARTER" class="acm-logo h-11 w-auto" onerror="this.outerHTML='<span class=\\'font-serif text-xl text-stone-900\\'>ACM</span>'"></a>
        <a href="../index.html" class="lg:hidden"><img src="../logo.png" alt="ACM" class="acm-logo h-9 w-auto" onerror="this.style.display='none'"></a>
        <button type="button" id="mobile-menu-btn" class="lg:hidden absolute right-0 p-2 header-mobile-btn" aria-label="Menü"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
      </div>
      <nav class="hidden lg:flex items-center justify-center gap-16 lg:gap-24 py-3 border-t border-stone-100" aria-label="Hauptnavigation">
        <a href="../thats-acm.html" class="header-nav-link">That's ACM</a>
        <a href="../charter.html" class="header-nav-link">Charter</a>
        <a href="../aircraft-management.html" class="header-nav-link">Aircraft Management</a>
        <a href="../maintenance.html" class="header-nav-link">Maintenance</a>
        <a href="../kontakt.html" class="header-nav-link">Kontakt</a>
        <a href="../karriere.html" class="header-nav-link">Careers</a>
      </nav>
    </div>
    <div id="desktop-menu" class="hidden lg:block fixed top-0 left-0 h-screen w-80 bg-white z-[100] -translate-x-full shadow-xl transition-transform duration-300">
      <div class="p-6 flex justify-between items-center border-b border-stone-100">
        <span class="font-serif text-xl text-stone-900">Menü</span>
        <button type="button" id="desktop-menu-close" class="p-2" aria-label="Schließen"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>
      <nav class="p-6 space-y-3 text-stone-800">
        <a href="../thats-acm.html" class="block py-2 hover:text-olive">That's ACM</a>
        <a href="../charter.html" class="block py-2 hover:text-olive">Charter</a>
        <a href="../aircraft-management.html" class="block py-2 hover:text-olive">Aircraft Management</a>
        <a href="../maintenance.html" class="block py-2 hover:text-olive">Maintenance</a>
        <a href="../karriere.html" class="block py-2 hover:text-olive">Careers</a>
        <a href="../news.html" class="block py-2 hover:text-olive">News</a>
        <a href="../kontakt.html" class="block py-2 hover:text-olive">Kontakt</a>
      </nav>
    </div>
    <div id="desktop-menu-backdrop" class="hidden lg:block fixed inset-0 bg-black/20 z-[99] opacity-0 pointer-events-none transition-opacity"></div>
    <div id="mobile-menu" class="lg:hidden hidden border-t border-stone-100 bg-white px-6 py-4 space-y-2">
      <a href="../news.html" class="block py-2">News</a>
      <a href="../kontakt.html" class="block py-2">Kontakt</a>
    </div>
  </header>"""


def header_en(slug: str) -> str:
    return f"""  <header id="header" class="fixed top-0 left-0 right-0 z-50 w-full">
    <div class="w-full px-6 sm:px-8 lg:px-10">
      <div class="flex items-center justify-center h-16 lg:h-[4.25rem] relative">
        <button type="button" id="desktop-menu-btn" class="hidden lg:block absolute left-0 p-2 header-desktop-menu-btn" aria-label="Open menu"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="hidden lg:flex absolute right-0 items-center gap-6">
          <div class="flex items-center gap-1 text-sm">
            <a href="../../news/{slug}" class="header-lang-link">DE</a>
            <span class="text-stone-300">|</span>
            <a href="{slug}" class="header-lang-link header-lang-active">EN</a>
          </div>
          <a href="../kontakt.html#zentrale" class="header-link-button whitespace-nowrap">Get in touch</a>
        </div>
        <a href="../index.html" class="hidden lg:block"><img src="../../logo.png" alt="ACM AIR CHARTER" class="acm-logo h-11 w-auto" onerror="this.outerHTML='<span class=\\'font-serif text-xl text-stone-900\\'>ACM</span>'"></a>
        <a href="../index.html" class="lg:hidden"><img src="../../logo.png" alt="ACM" class="acm-logo h-9 w-auto" onerror="this.style.display='none'"></a>
        <button type="button" id="mobile-menu-btn" class="lg:hidden absolute right-0 p-2 header-mobile-btn" aria-label="Menu"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
      </div>
      <nav class="hidden lg:flex items-center justify-center gap-16 lg:gap-24 py-3 border-t border-stone-100" aria-label="Main navigation">
        <a href="../thats-acm.html" class="header-nav-link">That's ACM</a>
        <a href="../charter.html" class="header-nav-link">Charter</a>
        <a href="../aircraft-management.html" class="header-nav-link">Aircraft Management</a>
        <a href="../maintenance.html" class="header-nav-link">Maintenance</a>
        <a href="../kontakt.html" class="header-nav-link">Contact</a>
        <a href="../karriere.html" class="header-nav-link">Careers</a>
      </nav>
    </div>
    <div id="desktop-menu" class="hidden lg:block fixed top-0 left-0 h-screen w-80 bg-white z-[100] -translate-x-full shadow-xl transition-transform duration-300">
      <div class="p-6 flex justify-between items-center border-b border-stone-100">
        <span class="font-serif text-xl text-stone-900">Menu</span>
        <button type="button" id="desktop-menu-close" class="p-2" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>
      <nav class="p-6 space-y-3 text-stone-800">
        <a href="../thats-acm.html" class="block py-2 hover:text-olive">That's ACM</a>
        <a href="../charter.html" class="block py-2 hover:text-olive">Charter</a>
        <a href="../aircraft-management.html" class="block py-2 hover:text-olive">Aircraft Management</a>
        <a href="../maintenance.html" class="block py-2 hover:text-olive">Maintenance</a>
        <a href="../karriere.html" class="block py-2 hover:text-olive">Careers</a>
        <a href="../news.html" class="block py-2 hover:text-olive">News</a>
        <a href="../kontakt.html" class="block py-2 hover:text-olive">Contact</a>
      </nav>
    </div>
    <div id="desktop-menu-backdrop" class="hidden lg:block fixed inset-0 bg-black/20 z-[99] opacity-0 pointer-events-none transition-opacity"></div>
    <div id="mobile-menu" class="lg:hidden hidden border-t border-stone-100 bg-white px-6 py-4 space-y-2">
      <a href="../news.html" class="block py-2">News</a>
      <a href="../kontakt.html" class="block py-2">Contact</a>
    </div>
  </header>"""


def footer_block(lang: str) -> str:
    if lang == "en":
        return """  <footer class="bg-white text-stone-600 pt-14 pb-10 px-6 sm:px-8">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between gap-8 text-sm border-t border-stone-200 pt-10">
      <div>
        <p class="text-stone-500 leading-relaxed max-w-sm">ACM AIR CHARTER GmbH · Integrated business aviation at Baden-Airpark.</p>
      </div>
      <div class="text-right">
        <a href="tel:+497229304050" class="text-olive hover:opacity-80">+49 7229 30405-0</a><br>
        <a href="mailto:info@acm.aero" class="text-olive hover:opacity-80">info@acm.aero</a>
      </div>
    </div>
    <p class="text-center text-stone-400 text-xs mt-10">&copy; 2026 ACM AIR CHARTER GmbH</p>
  </footer>"""
    return """  <footer class="bg-white text-stone-600 pt-14 pb-10 px-6 sm:px-8">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between gap-8 text-sm border-t border-stone-200 pt-10">
      <div>
        <p class="text-stone-500 leading-relaxed max-w-sm">ACM AIR CHARTER GmbH · Integrierte Business Aviation am Baden-Airpark.</p>
      </div>
      <div class="text-right">
        <a href="tel:+497229304050" class="text-olive hover:opacity-80">+49 7229 30405-0</a><br>
        <a href="mailto:info@acm.aero" class="text-olive hover:opacity-80">info@acm.aero</a>
      </div>
    </div>
    <p class="text-center text-stone-400 text-xs mt-10">&copy; 2026 ACM AIR CHARTER GmbH</p>
  </footer>"""


def build_head(
    lang: str, slug: str, title: str, description: str, canonical: str, icon_href: str, css_href: str
) -> str:
    desc = html.escape(description, quote=True)
    tit = html.escape(title, quote=True)
    if lang == "en":
        alt_de = f"/news/{slug}"
        alt_en = f"/en/news/{slug}"
    else:
        alt_de = f"/news/{slug}"
        alt_en = f"/en/news/{slug}"
    return f"""<!DOCTYPE html>
<html lang="{lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="{desc}">
  <meta name="robots" content="index, follow">
  <title>{tit}</title>
  <link rel="canonical" href="{html.escape(canonical, quote=True)}">
  <link rel="alternate" hreflang="de" href="{alt_de}">
  <link rel="alternate" hreflang="en" href="{alt_en}">
  <link rel="icon" href="{icon_href}" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{css_href}">
{TAILWIND}
</head>
<body class="font-sans antialiased text-stone-700">
"""


def scroll_btn(lang: str) -> str:
    if lang == "en":
        return """  <button type="button" id="scroll-to-top" aria-label="Back to top"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg></button>
"""
    return """  <button type="button" id="scroll-to-top" aria-label="Nach oben"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg></button>
"""


def process_file(path: Path, lang: str) -> None:
    raw = path.read_text(encoding="utf-8")
    slug = path.name
    main_full = extract_main_full(raw)
    if not main_full:
        print(f"skip (no main): {path}")
        return

    title = extract_tag(raw, "title") or path.stem
    description = extract_meta_content(raw, "description") or title
    can_m = re.search(r'<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']', raw, re.I)
    canonical = can_m.group(1) if can_m else (f"/en/news/{slug}" if lang == "en" else f"/news/{slug}")

    if lang == "en":
        icon_href = "../../Fotos/Favicon_xs.png"
        css_href = "../news/news-article.css"
        nav_script = '\n  <script src="../../nav-active.js?v=20260319" defer></script>'
    else:
        icon_href = "../Fotos/Favicon_xs.png"
        css_href = "news-article.css"
        nav_script = '\n  <script src="../nav-active.js?v=20260319" defer></script>'

    is_old = "article-shell" in raw
    if is_old:
        transformed = transform_old_main(main_full, lang).strip()
        if not transformed.startswith("<main"):
            transformed = f'<main class="pt-28 lg:pt-36">\n{transformed}\n</main>'
        main_out = "\n".join("  " + ln if ln.strip() else ln for ln in transformed.split("\n"))
    else:
        inner_m = re.search(r"<main[^>]*>([\s\S]*)</main>", main_full, re.I)
        inner = inner_m.group(1) if inner_m else ""
        main_out = f"  <main class=\"pt-28 lg:pt-36\">\n{fix_mojibake(inner).strip()}\n  </main>"

    head = build_head(lang, slug, title, description, canonical, icon_href, css_href)
    hdr = header_en(slug) if lang == "en" else header_de(slug)
    foot = footer_block(lang)
    script = FOOTER_SCRIPT.replace(
        '<script src="../nav-active.js?v=20260319" defer></script>', nav_script.strip()
    )

    out = (
        head
        + "\n"
        + hdr
        + "\n"
        + scroll_btn(lang)
        + "\n"
        + main_out
        + "\n\n"
        + foot
        + "\n\n"
        + script
        + "\n</body>\n</html>\n"
    )
    out = fix_mojibake(out)
    path.write_text(out, encoding="utf-8")
    print(f"OK {path.relative_to(ROOT)}")


def main() -> None:
    for p in sorted(NEWS_DE.glob("*.html")):
        if p.name == "news-article.css":
            continue
        if p.suffix.lower() != ".html":
            continue
        process_file(p, "de")
    for p in sorted(NEWS_EN.glob("*.html")):
        process_file(p, "en")


if __name__ == "__main__":
    main()
