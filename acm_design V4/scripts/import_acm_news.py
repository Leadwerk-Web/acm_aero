from __future__ import annotations

import json
import posixpath
import re
from dataclasses import dataclass
from datetime import datetime
from html import escape, unescape
from pathlib import Path, PurePosixPath
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup, Comment, Doctype, NavigableString, Tag
from deep_translator import GoogleTranslator


ROOT = Path(__file__).resolve().parents[1]
I18N_DIR = ROOT / "i18n"
NEWS_DIR = ROOT / "news"
EN_DIR = ROOT / "en"
EN_NEWS_DIR = EN_DIR / "news"
LOCAL_IMAGE_DIR = ROOT / "Fotos" / "news"
DE_LISTING_PATH = ROOT / "news.html"
EN_LISTING_PATH = EN_DIR / "news.html"
DATA_OUTPUT_PATH = NEWS_DIR / "acm-news-archive.json"
SITEMAP_PATH = ROOT / "page-sitemap.xml"

BASE_URL = "https://www.acm.aero"
AJAX_URL = f"{BASE_URL}/wp-admin/admin-ajax.php"
AJAX_REFERER = f"{BASE_URL}/unternehmen/news-neuigkeiten/"

DE_LISTING_REL = PurePosixPath("news.html")
EN_LISTING_REL = PurePosixPath("en/news.html")

ARCHIVE_SECTION_START = "<!-- ACM NEWS ARCHIVE START -->"
ARCHIVE_SECTION_END = "<!-- ACM NEWS ARCHIVE END -->"
ARCHIVE_SCRIPT_START = "<!-- ACM NEWS ARCHIVE SCRIPT START -->"
ARCHIVE_SCRIPT_END = "<!-- ACM NEWS ARCHIVE SCRIPT END -->"
GRID_IMPORT_START = "<!-- ACM NEWS GRID IMPORT START -->"
GRID_IMPORT_END = "<!-- ACM NEWS GRID IMPORT END -->"
CONTACT_CTA_MARKER = """<!-- ============================================
         5. CONTACT CTA"""

GLOSSARY_CONFIG = json.loads((I18N_DIR / "glossary.json").read_text(encoding="utf-8"))
EXACT_GLOSSARY = GLOSSARY_CONFIG.get("exact", {})
PROTECTED_TERMS = GLOSSARY_CONFIG.get("protected_terms", [])
PROTECTED_TERMS = sorted(
    {
        *PROTECTED_TERMS,
        "ACM Handling",
        "Authorized Reseller",
        "Business Aviation Handling",
        "IBAC",
        "SMS",
    },
    key=len,
    reverse=True,
)
PAGES_CONFIG = json.loads((I18N_DIR / "pages.json").read_text(encoding="utf-8"))
CACHE_PATH = I18N_DIR / "cache.json"

TRANSLATION_CACHE: dict[str, str] = json.loads(CACHE_PATH.read_text(encoding="utf-8"))
TRANSLATOR = GoogleTranslator(source="de", target="en")
SESSION = requests.Session()
SESSION.headers.update(
    {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
        )
    }
)

FILTER_KEY_BY_LABEL = {
    "Unternehmen": "unternehmen",
    "Company": "unternehmen",
    "Operations": "operations",
    "Flotte": "flotte",
    "Fleet": "flotte",
    "Maintenance": "maintenance",
    "Partner": "partner",
    "Handling": "handling",
    "Sicherheit": "sicherheit",
    "Safety": "sicherheit",
}

MANUAL_LISTING_STORY_SLUGS = (
    "is-bah-stage-2-acm-handling",
)


@dataclass
class NewsItem:
    slug: str
    source_url: str
    published_iso: str
    published_label_de: str
    published_label_en: str
    title_de: str
    title_en: str
    summary_de: str
    summary_en: str
    image_remote_url: str
    image_local_path: str
    body_html_de: str
    body_html_en: str
    lead_html_de: str
    lead_html_en: str
    category_de: str
    category_en: str


def normalize_spaces(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def is_external(value: str) -> bool:
    return value.startswith(("http://", "https://", "//", "mailto:", "tel:", "#", "data:", "javascript:"))


def split_url(value: str) -> tuple[str, str]:
    match = re.match(r"^([^?#]*)(.*)$", value)
    if not match:
        return value, ""
    return match.group(1), match.group(2)


def relative_url_from(source_rel: PurePosixPath | str, target_rel: PurePosixPath | str) -> str:
    source_parent = PurePosixPath(source_rel).parent.as_posix()
    target_path = PurePosixPath(target_rel).as_posix()
    rel = posixpath.relpath(target_path, start=source_parent)
    return rel.replace("\\", "/")


def rewrite_relative_reference(value: str, source_rel: PurePosixPath | str, target_rel: PurePosixPath | str) -> str:
    if not value or is_external(value):
        return value

    path, suffix = split_url(value.replace("\\", "/"))
    if not path or path.startswith("/"):
        return value

    source_dir = PurePosixPath(source_rel).parent.as_posix()
    target_dir = PurePosixPath(target_rel).parent.as_posix()
    resolved = posixpath.normpath(posixpath.join(source_dir, path))
    rewritten = posixpath.relpath(resolved, start=target_dir)
    return rewritten.replace("\\", "/") + suffix


def rewrite_srcset(value: str, source_rel: PurePosixPath | str, target_rel: PurePosixPath | str) -> str:
    parts: list[str] = []
    for item in value.split(","):
        candidate = item.strip()
        if not candidate:
            continue
        tokens = candidate.split()
        tokens[0] = rewrite_relative_reference(tokens[0], source_rel, target_rel)
        parts.append(" ".join(tokens))
    return ", ".join(parts)


def rewrite_style_urls(style: str, source_rel: PurePosixPath | str, target_rel: PurePosixPath | str) -> str:
    def repl(match: re.Match[str]) -> str:
        quote = match.group(1) or ""
        path = match.group(2)
        rewritten = rewrite_relative_reference(path, source_rel, target_rel)
        return f"url({quote}{rewritten}{quote})"

    return re.sub(r"url\((['\"]?)([^)'\"]+)\1\)", repl, style)


def rewrite_relative_paths_in_soup(soup: BeautifulSoup, source_rel: PurePosixPath | str, target_rel: PurePosixPath | str) -> None:
    for tag in soup.find_all(True):
        for attr in ("href", "src", "poster"):
            value = tag.get(attr)
            if isinstance(value, str):
                tag[attr] = rewrite_relative_reference(value, source_rel, target_rel)

        srcset = tag.get("srcset")
        if isinstance(srcset, str):
            tag["srcset"] = rewrite_srcset(srcset, source_rel, target_rel)

        style = tag.get("style")
        if isinstance(style, str):
            tag["style"] = rewrite_style_urls(style, source_rel, target_rel)


def save_translation_cache() -> None:
    CACHE_PATH.write_text(
        json.dumps(dict(sorted(TRANSLATION_CACHE.items())), ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def preserve_whitespace(source: str, translated: str) -> str:
    match = re.match(r"^(\s*)(.*?)(\s*)$", source, re.S)
    if not match:
        return translated
    return f"{match.group(1)}{translated}{match.group(3)}"


def contains_letters(value: str) -> bool:
    return any(char.isalpha() for char in value)


def mask_protected_terms(text: str) -> tuple[str, dict[str, str]]:
    masked = text
    placeholder_map: dict[str, str] = {}
    for index, term in enumerate(PROTECTED_TERMS):
        if term not in masked:
            continue
        token = f"ZXQTERM{index}QXZ"
        masked = masked.replace(term, token)
        placeholder_map[token] = term
    return masked, placeholder_map


def unmask_protected_terms(text: str, placeholder_map: dict[str, str]) -> str:
    restored = text
    for token, term in placeholder_map.items():
        restored = restored.replace(token, term)
    return restored


def translate_text(text: str) -> str:
    stripped = normalize_spaces(text)
    if not stripped:
        return text
    if stripped in EXACT_GLOSSARY:
        return EXACT_GLOSSARY[stripped]
    if stripped in TRANSLATION_CACHE:
        return TRANSLATION_CACHE[stripped]

    masked, placeholder_map = mask_protected_terms(stripped)
    try:
        translated = TRANSLATOR.translate(masked)
    except Exception:
        translated = stripped

    translated = unmask_protected_terms(translated, placeholder_map)
    TRANSLATION_CACHE[stripped] = translated
    return translated


def should_translate_text_node(node: NavigableString) -> bool:
    if isinstance(node, Comment):
        return False

    parent = node.parent
    if parent is None or parent.name in {"script", "style", "svg", "path"}:
        return False

    text = str(node)
    stripped = text.strip()
    if not stripped:
        return False
    if not contains_letters(stripped):
        return False
    if stripped in EXACT_GLOSSARY:
        return True
    if re.fullmatch(r"[\d\s%+./,:;|()\-–—&]+", stripped):
        return False
    if "@" in stripped or stripped.startswith("http"):
        return False
    return True


def translate_fragment_html(fragment_html: str) -> str:
    if not fragment_html.strip():
        return fragment_html

    wrapper = BeautifulSoup(f"<div>{fragment_html}</div>", "html.parser").div
    if wrapper is None:
        return fragment_html

    for node in list(wrapper.find_all(string=True)):
        if not should_translate_text_node(node):
            continue
        original = str(node)
        translated = translate_text(original.strip())
        node.replace_with(preserve_whitespace(original, translated))

    return "".join(str(child) for child in wrapper.contents)


def text_from_html(fragment_html: str) -> str:
    return normalize_spaces(BeautifulSoup(fragment_html, "html.parser").get_text(" ", strip=True))


def truncate_text(text: str, limit: int) -> str:
    if len(text) <= limit:
        return text
    clipped = text[: limit - 1].rsplit(" ", 1)[0].strip()
    return f"{clipped}…"


def request_text(
    url: str,
    *,
    method: str = "GET",
    data: dict[str, str] | None = None,
    referer: str | None = None,
    allow_redirects: bool = False,
) -> tuple[str, requests.Response]:
    headers: dict[str, str] = {}
    if referer:
        headers["Referer"] = referer
    response = SESSION.request(method, url, data=data, headers=headers, allow_redirects=allow_redirects, timeout=30)
    response.encoding = response.apparent_encoding or response.encoding or "utf-8"
    return response.text, response


def parse_card_image(style: str) -> str | None:
    match = re.search(r"url\((['\"]?)(.*?)\1\)", style)
    if not match:
        return None
    return match.group(2)


def parse_de_date(label: str) -> datetime:
    return datetime.strptime(label, "%d.%m.%Y")


def format_en_date(value: datetime) -> str:
    return f"{value.strftime('%B')} {value.day}, {value.year}"


def slug_from_url(url: str) -> str | None:
    path = urlparse(url).path.rstrip("/")
    if not path or path == "":
        return None
    parts = [part for part in path.split("/") if part]
    return parts[-1] if parts else None


def infer_category(title_de: str, summary_de: str) -> tuple[str, str]:
    haystack = f"{title_de} {summary_de}".lower()
    rules = [
        (("handling", "is-bah"), ("Handling", "Handling")),
        (("technik", "maintenance", "wartung", "inspektion", "camo"), ("Maintenance", "Maintenance")),
        (("global", "bombardier", "falcon", "bbj", "phenom", "flotte"), ("Flotte", "Fleet")),
        (("is-bao", "zertifizierung"), ("Sicherheit", "Safety")),
    ]
    for keywords, labels in rules:
        if any(keyword in haystack for keyword in keywords):
            return labels
    return ("Unternehmen", "Company")


def clean_detail_description(detail: Tag) -> tuple[str, str, str]:
    cleaned = BeautifulSoup(str(detail), "html.parser").find(True)
    if cleaned is None:
        return "", "", ""

    for tag in cleaned.find_all(True):
        if tag.name in {"script", "style"}:
            tag.decompose()
            continue

        attrs: dict[str, str] = {}
        if tag.name == "a" and tag.get("href"):
            attrs["href"] = tag["href"]
            if is_external(tag["href"]):
                attrs["target"] = "_blank"
                attrs["rel"] = "noopener noreferrer"
        tag.attrs = attrs

    paragraphs = [paragraph for paragraph in cleaned.find_all("p") if normalize_spaces(paragraph.get_text(" ", strip=True))]
    if paragraphs:
        lead_node = paragraphs[0]
        lead_html = str(lead_node)
        summary = normalize_spaces(lead_node.get_text(" ", strip=True))
        lead_node.extract()
        body_html = "".join(str(child) for child in cleaned.contents if normalize_spaces(str(child)))
        return lead_html, body_html, summary

    inner_html = "".join(str(child) for child in cleaned.contents if normalize_spaces(str(child)))
    summary = text_from_html(inner_html)
    return inner_html, "", summary


def download_image(remote_url: str, slug: str) -> str:
    parsed = urlparse(remote_url)
    suffix = Path(parsed.path).suffix.lower()
    if suffix not in {".jpg", ".jpeg", ".png", ".webp"}:
        suffix = ".jpg"

    LOCAL_IMAGE_DIR.mkdir(parents=True, exist_ok=True)
    filename = f"{slug}{suffix}"
    target_path = LOCAL_IMAGE_DIR / filename
    if target_path.exists():
        return (PurePosixPath("Fotos") / "news" / filename).as_posix()

    response = SESSION.get(remote_url, timeout=30)
    response.raise_for_status()
    target_path.write_bytes(response.content)
    return (PurePosixPath("Fotos") / "news" / filename).as_posix()


def fetch_archive_cards() -> list[dict[str, str]]:
    html, _ = request_text(
        AJAX_URL,
        method="POST",
        data={"action": "loadMore_news", "offset": "3", "ppp": "15"},
        referer=AJAX_REFERER,
    )
    soup = BeautifulSoup(html, "html.parser")
    cards: list[dict[str, str]] = []
    for box in soup.select(".box-col"):
        link = box.select_one("a[href]")
        title_node = box.find("p")
        date_node = box.select_one("span")
        image_wrap = box.select_one(".col-images")
        if not link or not title_node or not date_node or not image_wrap:
            continue
        image_remote = parse_card_image(image_wrap.get("style", ""))
        if not image_remote:
            continue
        cards.append(
            {
                "source_url": unescape(link.get("href", "")).strip(),
                "title_de": normalize_spaces(title_node.get_text(" ", strip=True)),
                "published_label_de": normalize_spaces(date_node.get_text(" ", strip=True)),
                "image_remote_url": image_remote,
            }
        )
    return cards


def parse_news_item(card: dict[str, str]) -> NewsItem | None:
    html, _ = request_text(card["source_url"], allow_redirects=False)
    soup = BeautifulSoup(html, "html.parser")
    detail_header = soup.select_one(".detail-tital")
    detail_body = soup.select_one(".detail-description")
    if detail_header is None or detail_body is None:
        return None

    title_node = detail_header.select_one("h2")
    date_node = detail_header.select_one(".dt-list li")
    image_node = soup.select_one(".detail-img img")
    title_de = normalize_spaces(title_node.get_text(" ", strip=True)) if title_node else card["title_de"]
    published_label_de = normalize_spaces(date_node.get_text(" ", strip=True)) if date_node else card["published_label_de"]
    published_dt = parse_de_date(published_label_de)
    slug = slug_from_url(card["source_url"])
    if not slug:
        return None

    image_remote_url = image_node.get("src") if image_node and image_node.get("src") else card["image_remote_url"]
    lead_html_de, body_html_de, summary_de = clean_detail_description(detail_body)
    lead_html_en = translate_fragment_html(lead_html_de)
    body_html_en = translate_fragment_html(body_html_de)
    title_en = translate_text(title_de)
    summary_en = text_from_html(lead_html_en) or translate_text(summary_de)
    category_de, category_en = infer_category(title_de, summary_de)
    image_local_path = download_image(image_remote_url, slug)

    return NewsItem(
        slug=slug,
        source_url=card["source_url"],
        published_iso=published_dt.date().isoformat(),
        published_label_de=published_label_de,
        published_label_en=format_en_date(published_dt),
        title_de=title_de,
        title_en=title_en,
        summary_de=summary_de,
        summary_en=summary_en,
        image_remote_url=image_remote_url,
        image_local_path=image_local_path,
        body_html_de=body_html_de,
        body_html_en=body_html_en,
        lead_html_de=lead_html_de,
        lead_html_en=lead_html_en,
        category_de=category_de,
        category_en=category_en,
    )


def resolve_local_asset_path(reference: str, source_rel: PurePosixPath) -> str:
    if not reference:
        return reference
    if is_external(reference):
        return reference
    path, _ = split_url(reference.replace("\\", "/"))
    source_dir = source_rel.parent.as_posix()
    return posixpath.normpath(posixpath.join(source_dir, path)).replace("\\", "/")


def parse_manual_listing_story(slug: str) -> NewsItem | None:
    de_rel = PurePosixPath("news") / f"{slug}.html"
    en_rel = PurePosixPath("en/news") / f"{slug}.html"
    de_path = ROOT / de_rel
    en_path = ROOT / en_rel
    if not de_path.exists() or not en_path.exists():
        return None

    de_soup = BeautifulSoup(de_path.read_text(encoding="utf-8"), "html.parser")
    en_soup = BeautifulSoup(en_path.read_text(encoding="utf-8"), "html.parser")

    title_de_node = de_soup.find("h1")
    title_en_node = en_soup.find("h1")
    time_de_node = de_soup.find("time")
    time_en_node = en_soup.find("time")
    lead_de_node = de_soup.select_one("p.article-lead")
    lead_en_node = en_soup.select_one("p.article-lead")
    image_de_node = de_soup.select_one("main > figure img")

    if not title_de_node or not title_en_node or not time_de_node or not time_en_node or not lead_de_node or not lead_en_node or not image_de_node:
        return None

    published_iso = normalize_spaces(time_de_node.get("datetime", ""))
    if not published_iso:
        return None

    return NewsItem(
        slug=slug,
        source_url=f"/news/{slug}.html",
        published_iso=published_iso,
        published_label_de=normalize_spaces(time_de_node.get_text(" ", strip=True)),
        published_label_en=normalize_spaces(time_en_node.get_text(" ", strip=True)),
        title_de=normalize_spaces(title_de_node.get_text(" ", strip=True)),
        title_en=normalize_spaces(title_en_node.get_text(" ", strip=True)),
        summary_de=normalize_spaces(lead_de_node.get_text(" ", strip=True)),
        summary_en=normalize_spaces(lead_en_node.get_text(" ", strip=True)),
        image_remote_url=resolve_local_asset_path(image_de_node.get("src", ""), de_rel),
        image_local_path=resolve_local_asset_path(image_de_node.get("src", ""), de_rel),
        body_html_de="",
        body_html_en="",
        lead_html_de=str(lead_de_node),
        lead_html_en=str(lead_en_node),
        category_de="Unternehmen",
        category_en="Company",
    )


def collect_manual_listing_items() -> list[NewsItem]:
    items: list[NewsItem] = []
    for slug in MANUAL_LISTING_STORY_SLUGS:
        item = parse_manual_listing_story(slug)
        if item is not None:
            items.append(item)
    items.sort(key=lambda item: item.published_iso, reverse=True)
    return items


def merge_listing_items(imported_items: list[NewsItem], manual_items: list[NewsItem]) -> list[NewsItem]:
    merged: dict[str, NewsItem] = {item.slug: item for item in imported_items}
    for item in manual_items:
        merged[item.slug] = item
    return sorted(merged.values(), key=lambda item: item.published_iso, reverse=True)


def build_archive_card(item: NewsItem, *, locale: str, target_rel: PurePosixPath, hidden: bool) -> str:
    title = item.title_de if locale == "de" else item.title_en
    summary = item.summary_de if locale == "de" else item.summary_en
    category = item.category_de if locale == "de" else item.category_en
    date_label = item.published_label_de if locale == "de" else item.published_label_en
    article_rel = PurePosixPath("news") / f"{item.slug}.html" if locale == "de" else PurePosixPath("en/news") / f"{item.slug}.html"
    href = relative_url_from(target_rel, article_rel)
    image_href = relative_url_from(target_rel, item.image_local_path)
    hidden_class = " hidden archive-load-more-item" if hidden else ""
    read_more = "Weiterlesen" if locale == "de" else "Read more"

    return f"""
<article class="news-card scroll-reveal flex flex-col{hidden_class}" data-archive-item="true">
<a class="block" href="{escape(href, quote=True)}">
<div class="aspect-[4/3] overflow-hidden">
<img alt="{escape(title, quote=True)}" class="w-full h-full object-cover" loading="lazy" src="{escape(image_href, quote=True)}"/>
</div>
</a>
<div class="news-card-body">
<div class="flex items-center gap-3 mb-3">
<span class="news-category-pill">{escape(category)}</span>
<span class="news-date">{escape(date_label)}</span>
</div>
<h3 class="font-serif text-stone-900 font-light mb-3" style="font-size: 1.5rem; line-height: 1.25;">
<a class="hover:text-olive transition-colors" href="{escape(href, quote=True)}">{escape(title)}</a>
</h3>
<p class="text-stone-500 text-sm leading-relaxed mb-5 line-clamp-3 flex-1">
              {escape(summary)}
            </p>
<a class="link-arrow mt-auto" href="{escape(href, quote=True)}">{escape(read_more)} <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 8l4 4m0 0l-4 4m4-4H3" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg></a>
</div>
</article>""".strip()


def filter_key_for_category(category: str) -> str:
    return FILTER_KEY_BY_LABEL.get(category, "unternehmen")


def build_main_grid_card(item: NewsItem, *, locale: str, target_rel: PurePosixPath, hidden: bool) -> str:
    title = item.title_de if locale == "de" else item.title_en
    summary = item.summary_de if locale == "de" else item.summary_en
    category = item.category_de if locale == "de" else item.category_en
    date_label = item.published_label_de if locale == "de" else item.published_label_en
    href = relative_url_from(target_rel, news_single_rel(locale, item.slug))
    image_href = relative_url_from(target_rel, item.image_local_path)
    hidden_class = " hidden news-load-more-item" if hidden else ""
    read_more = "Weiterlesen" if locale == "de" else "Read more"
    filter_key = filter_key_for_category(category)

    return f"""
<article class="news-card scroll-reveal flex flex-col{hidden_class}" data-category="{escape(filter_key, quote=True)}" data-imported-news="true">
<a class="block" href="{escape(href, quote=True)}">
<div class="aspect-[4/3] overflow-hidden">
<img alt="{escape(title, quote=True)}" class="w-full h-full object-cover" loading="lazy" src="{escape(image_href, quote=True)}"/>
</div>
</a>
<div class="news-card-body">
<div class="flex items-center gap-3 mb-3">
<span class="news-category-pill">{escape(category)}</span>
<span class="news-date">{escape(date_label)}</span>
</div>
<h3 class="font-serif text-stone-900 font-light mb-3" style="font-size: 1.5rem; line-height: 1.25;">
<a class="hover:text-olive transition-colors" href="{escape(href, quote=True)}">{escape(title)}</a>
</h3>
<p class="text-stone-500 text-sm leading-relaxed mb-5 line-clamp-2 flex-1">
              {escape(summary)}
            </p>
<a class="link-arrow mt-auto" href="{escape(href, quote=True)}">
              {escape(read_more)}
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 8l4 4m0 0l-4 4m4-4H3" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
</a>
</div>
</article>""".strip()


def build_main_grid_import_block(items: list[NewsItem], *, locale: str, target_rel: PurePosixPath) -> str:
    visible_count = 3
    cards_html = "\n".join(
        build_main_grid_card(item, locale=locale, target_rel=target_rel, hidden=index >= visible_count)
        for index, item in enumerate(items)
    )
    return f"""
{GRID_IMPORT_START}
{cards_html}
{GRID_IMPORT_END}""".strip()


def build_archive_section(items: list[NewsItem], *, locale: str, target_rel: PurePosixPath) -> str:
    if locale == "de":
        label = "Archiv"
        title = "ACM News Archiv"
        intro = "Originale ACM Meldungen aus dem bisherigen News-Archiv, lokal in diese Seite integriert."
        button_label = "Weitere Archivbeiträge laden"
    else:
        label = "Archive"
        title = "ACM News Archive"
        intro = "Original ACM stories from the previous news archive, now integrated locally on this site."
        button_label = "Load more archived articles"

    visible_count = 6
    cards_html = "\n".join(
        build_archive_card(item, locale=locale, target_rel=target_rel, hidden=index >= visible_count)
        for index, item in enumerate(items)
    )
    load_more_hidden = " hidden" if len(items) <= visible_count else ""

    return f"""
{ARCHIVE_SECTION_START}
<section class="py-20 sm:py-24 lg:py-28 bg-stone-50 border-y border-stone-200/80" id="news-archive-section">
<div class="max-w-6xl mx-auto px-6 sm:px-8 lg:px-12">
<div class="max-w-3xl mb-12 lg:mb-14">
<p class="section-label mb-4 scroll-reveal">{escape(label)}</p>
<h2 class="font-serif text-stone-900 font-light mb-5 scroll-reveal">{escape(title)}</h2>
<p class="text-stone-500 text-lg leading-relaxed scroll-reveal">{escape(intro)}</p>
</div>
<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8 lg:gap-10" id="news-archive-articles">
{cards_html}
</div>
<div class="text-center mt-16 scroll-reveal{load_more_hidden}" id="news-archive-load-more-wrap">
<button aria-controls="news-archive-articles" aria-expanded="false" class="btn-outline" id="news-archive-load-more-btn" type="button">
            {escape(button_label)}
          </button>
</div>
</div>
</section>
{ARCHIVE_SECTION_END}""".strip()


ARCHIVE_LOAD_MORE_SCRIPT = r"""
<script id="acm-news-archive-script">
  (function initArchiveLoadMore() {
    const archive = document.getElementById('news-archive-section');
    if (!archive) return;

    const loadMoreBtn = document.getElementById('news-archive-load-more-btn');
    const loadMoreWrap = document.getElementById('news-archive-load-more-wrap');
    if (!loadMoreBtn) return;

    const LOAD_BATCH = 3;
    let revealObserver = null;
    if ('IntersectionObserver' in window) {
      revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
            revealObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
    }

    function pendingItems() {
      return Array.from(document.querySelectorAll('#news-archive-articles article.archive-load-more-item.hidden'));
    }

    loadMoreBtn.addEventListener('click', function () {
      const batch = pendingItems().slice(0, LOAD_BATCH);
      batch.forEach((article) => {
        article.classList.remove('hidden');
        if (revealObserver) {
          article.classList.remove('revealed');
          revealObserver.observe(article);
        } else {
          article.classList.add('revealed');
        }
      });

      if (pendingItems().length === 0 && loadMoreWrap) {
        loadMoreWrap.classList.add('hidden');
      } else {
        loadMoreBtn.setAttribute('aria-expanded', 'true');
      }
    });
  })();
</script>
""".strip()


SINGLE_PAGE_STYLES = """
.article-shell h1,
.article-shell h2,
.article-shell h3 {
  letter-spacing: 0.02em;
}
.article-lead p,
.article-copy p {
  color: #57534e;
}
.article-lead p {
  font-size: 1.0625rem;
  line-height: 1.7;
  color: #44403c;
}
.article-copy p {
  font-size: 0.98rem;
  line-height: 1.75;
}
.article-copy p + p {
  margin-top: 1.25rem;
}
.article-copy a,
.article-lead a {
  color: var(--color-olive);
  text-decoration: underline;
  text-underline-offset: 0.18em;
}
.article-copy a:hover,
.article-lead a:hover {
  color: var(--color-olive-dark);
}
.article-figure {
  width: 100%;
  border: 1px solid rgba(0, 20, 65, 0.08);
  background: white;
}
.article-back-link {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.8125rem;
  letter-spacing: 0.05em;
  color: var(--color-olive);
}
.article-back-link:hover {
  color: var(--color-olive-dark);
}
""".strip()


def news_single_rel(locale: str, slug: str) -> PurePosixPath:
    if locale == "de":
        return PurePosixPath("news") / f"{slug}.html"
    return PurePosixPath("en/news") / f"{slug}.html"


def listing_rel(locale: str) -> PurePosixPath:
    return DE_LISTING_REL if locale == "de" else EN_LISTING_REL


def build_related_cards(current: NewsItem, items: list[NewsItem], *, locale: str, target_rel: PurePosixPath) -> str:
    related = [item for item in items if item.slug != current.slug][:3]
    if not related:
        return ""

    cards: list[str] = []
    for item in related:
        title = item.title_de if locale == "de" else item.title_en
        date_label = item.published_label_de if locale == "de" else item.published_label_en
        href = relative_url_from(target_rel, news_single_rel(locale, item.slug))
        image_href = relative_url_from(target_rel, item.image_local_path)
        cards.append(
            f"""
<article class="news-card scroll-reveal flex flex-col overflow-hidden">
<a class="block" href="{escape(href, quote=True)}">
<div class="aspect-[4/3] overflow-hidden">
<img alt="{escape(title, quote=True)}" class="w-full h-full object-cover" loading="lazy" src="{escape(image_href, quote=True)}"/>
</div>
</a>
<div class="news-card-body">
<div class="flex items-center gap-3 mb-3">
<span class="news-category-pill">{escape(item.category_de if locale == 'de' else item.category_en)}</span>
<span class="news-date">{escape(date_label)}</span>
</div>
<h3 class="font-serif text-stone-900 font-light mb-3" style="font-size: 1.45rem; line-height: 1.25;">
<a class="hover:text-olive transition-colors" href="{escape(href, quote=True)}">{escape(title)}</a>
</h3>
<p class="text-stone-500 text-sm leading-relaxed mb-5 line-clamp-3 flex-1">{escape(item.summary_de if locale == 'de' else item.summary_en)}</p>
<a class="link-arrow mt-auto" href="{escape(href, quote=True)}">{escape('Weiterlesen' if locale == 'de' else 'Read more')} <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 8l4 4m0 0l-4 4m4-4H3" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg></a>
</div>
</article>""".strip()
        )
    return "\n".join(cards)


def build_single_main(item: NewsItem, items: list[NewsItem], *, locale: str, target_rel: PurePosixPath) -> str:
    title = item.title_de if locale == "de" else item.title_en
    category = item.category_de if locale == "de" else item.category_en
    date_label = item.published_label_de if locale == "de" else item.published_label_en
    lead_html = item.lead_html_de if locale == "de" else item.lead_html_en
    body_html = item.body_html_de if locale == "de" else item.body_html_en
    image_href = relative_url_from(target_rel, item.image_local_path)
    back_href = relative_url_from(target_rel, listing_rel(locale))
    contact_href = relative_url_from(target_rel, "kontakt.html#zentrale" if locale == "de" else "en/kontakt.html#zentrale")
    charter_href = relative_url_from(target_rel, "charter.html" if locale == "de" else "en/charter.html")
    related_cards = build_related_cards(item, items, locale=locale, target_rel=target_rel)

    if locale == "de":
        back_label = "Zurück zur News-Übersicht"
        archive_label = "Archivbeitrag"
        cta_title = "Kontaktieren Sie unser Team."
        cta_copy = "Für Rückfragen zu Charter, Aircraft Management oder Maintenance steht Ihnen unser Team direkt zur Verfügung."
        cta_primary = "Kontakt aufnehmen"
        cta_secondary = "Charter anfragen"
        related_title = "Weitere Meldungen aus dem Archiv"
    else:
        back_label = "Back to the news overview"
        archive_label = "Archive story"
        cta_title = "Contact our team."
        cta_copy = "Our team is available directly for questions about charter, aircraft management or maintenance."
        cta_primary = "Get in touch"
        cta_secondary = "Request charter"
        related_title = "More from the archive"

    body_block = ""
    if body_html.strip():
        body_block = f"""
      <div class="article-copy mt-10 scroll-reveal">
        {body_html}
      </div>""".rstrip()

    return f"""
<main class="article-shell pt-32 lg:pt-40">
<section class="pb-6 sm:pb-8">
<div class="max-w-6xl mx-auto px-6 sm:px-8 lg:px-12">
<a class="article-back-link scroll-reveal" href="{escape(back_href, quote=True)}">
<span aria-hidden="true">←</span>
<span>{escape(back_label)}</span>
</a>
</div>
</section>
<section class="pb-8 sm:pb-10 lg:pb-12">
<div class="max-w-4xl mx-auto px-6 sm:px-8 lg:px-12 text-center">
<p class="section-label mb-4 scroll-reveal">News · {escape(archive_label)} · {escape(category)}</p>
<h1 class="font-serif text-stone-900 font-light text-3xl sm:text-4xl lg:text-[3.2rem] leading-[1.08] scroll-reveal">{escape(title)}</h1>
<div class="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-stone-500 scroll-reveal">
<time datetime="{escape(item.published_iso, quote=True)}">{escape(date_label)}</time>
</div>
</div>
</section>
<section class="pb-14 lg:pb-16">
<div class="max-w-3xl mx-auto px-6 sm:px-8 lg:px-12">
<figure class="article-figure overflow-hidden scroll-reveal">
<div class="aspect-[21/9] overflow-hidden bg-stone-200">
<img alt="{escape(title, quote=True)}" class="w-full h-full object-cover object-center" fetchpriority="high" src="{escape(image_href, quote=True)}"/>
</div>
</figure>
</div>
</section>
<section class="pb-20 lg:pb-24">
<article class="max-w-3xl mx-auto px-6 sm:px-8 lg:px-12">
<div class="article-lead scroll-reveal">
{lead_html}
</div>{body_block}
</article>
</section>
<section class="py-16 lg:py-20 bg-stone-100/80 border-y border-stone-200/80">
<div class="max-w-4xl mx-auto px-6 sm:px-8 lg:px-12 text-center scroll-reveal">
<h2 class="font-serif text-stone-900 text-3xl lg:text-4xl font-light mb-4">{escape(cta_title)}</h2>
<p class="text-stone-500 mb-10 max-w-2xl mx-auto leading-relaxed">{escape(cta_copy)}</p>
<div class="flex flex-col sm:flex-row flex-wrap justify-center gap-4">
<a class="btn-primary" href="{escape(contact_href, quote=True)}">{escape(cta_primary)}</a>
<a class="btn-outline" href="{escape(charter_href, quote=True)}">{escape(cta_secondary)}</a>
</div>
</div>
</section>
<section class="py-16 lg:py-24 bg-white">
<div class="max-w-6xl mx-auto px-6 sm:px-8 lg:px-12">
<h2 class="font-serif text-stone-900 text-3xl font-light mb-12 text-center scroll-reveal">{escape(related_title)}</h2>
<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-8 lg:gap-10">
{related_cards}
</div>
<p class="text-center mt-12 scroll-reveal">
<a class="article-back-link" href="{escape(back_href, quote=True)}">
<span aria-hidden="true">←</span>
<span>{escape(back_label)}</span>
</a>
</p>
</div>
</section>
</main>""".strip()


def update_head_metadata(soup: BeautifulSoup, *, title: str, description: str, public_de: str, public_en: str, locale: str) -> None:
    if soup.html:
        soup.html["lang"] = locale

    head = soup.head
    if head is None:
        return

    title_tag = head.find("title")
    if title_tag:
        title_tag.string = f"{title} | ACM AIR CHARTER"

    description_tag = head.find("meta", attrs={"name": "description"})
    if description_tag:
        description_tag["content"] = description

    for link in list(head.find_all("link", rel=lambda value: value and ("canonical" in value or "alternate" in value))):
        rel_values = link.get("rel", [])
        if link.get("hreflang") in {"de", "en"} or "canonical" in rel_values:
            link.decompose()

    canonical = soup.new_tag("link", rel="canonical", href=f"/{public_de}" if locale == "de" else f"/{public_en}")
    de_alt = soup.new_tag("link", rel="alternate", hreflang="de", href=f"/{public_de}")
    en_alt = soup.new_tag("link", rel="alternate", hreflang="en", href=f"/{public_en}")

    anchor = description_tag or title_tag
    if anchor is None:
        head.append(canonical)
        head.append(de_alt)
        head.append(en_alt)
    else:
        anchor.insert_after(en_alt)
        en_alt.insert_before(de_alt)
        de_alt.insert_before(canonical)


def configure_language_switchers(soup: BeautifulSoup, *, locale: str, target_rel: PurePosixPath, public_de: str, public_en: str) -> None:
    de_href = relative_url_from(target_rel, public_de)
    en_href = relative_url_from(target_rel, public_en)

    switcher_links = soup.select(".header-lang-switcher a.header-lang-link")
    if len(switcher_links) >= 2:
        de_link, en_link = switcher_links[0], switcher_links[1]
        de_classes = [cls for cls in de_link.get("class", []) if cls != "header-lang-active"]
        en_classes = [cls for cls in en_link.get("class", []) if cls != "header-lang-active"]
        de_link["href"] = de_href
        en_link["href"] = en_href
        de_link["data-lang"] = "de"
        en_link["data-lang"] = "en"
        if locale == "de":
            de_link["class"] = de_classes + ["header-lang-active"]
            en_link["class"] = en_classes
        else:
            de_link["class"] = de_classes
            en_link["class"] = en_classes + ["header-lang-active"]

    footer_de = soup.find(id="lang-de")
    footer_en = soup.find(id="lang-en")
    if footer_de and footer_en:
        footer_de["href"] = de_href
        footer_en["href"] = en_href
        footer_de["data-lang"] = "de"
        footer_en["data-lang"] = "en"
        footer_de["class"] = [cls for cls in footer_de.get("class", []) if cls != "active"] + (["active"] if locale == "de" else [])
        footer_en["class"] = [cls for cls in footer_en.get("class", []) if cls != "active"] + (["active"] if locale == "en" else [])


def inject_single_page_styles(soup: BeautifulSoup) -> None:
    existing = soup.find("style", attrs={"id": "acm-news-single-styles"})
    if existing:
        existing.decompose()

    style_tag = soup.new_tag("style", id="acm-news-single-styles")
    style_tag.string = SINGLE_PAGE_STYLES
    if soup.head:
        soup.head.append(style_tag)


def render_single_page(item: NewsItem, items: list[NewsItem], *, locale: str) -> str:
    base_path = DE_LISTING_PATH if locale == "de" else EN_LISTING_PATH
    base_rel = DE_LISTING_REL if locale == "de" else EN_LISTING_REL
    target_rel = news_single_rel(locale, item.slug)
    target_path = ROOT / target_rel

    soup = BeautifulSoup(base_path.read_text(encoding="utf-8"), "html.parser")
    for child in list(soup.contents):
        if isinstance(child, Doctype):
            child.extract()

    rewrite_relative_paths_in_soup(soup, base_rel, target_rel)
    inject_single_page_styles(soup)

    public_de = (PurePosixPath("news") / f"{item.slug}.html").as_posix()
    public_en = (PurePosixPath("en/news") / f"{item.slug}.html").as_posix()
    summary = item.summary_de if locale == "de" else item.summary_en
    title = item.title_de if locale == "de" else item.title_en
    update_head_metadata(
        soup,
        title=title,
        description=truncate_text(summary, 155),
        public_de=public_de,
        public_en=public_en,
        locale=locale,
    )
    configure_language_switchers(
        soup,
        locale=locale,
        target_rel=target_rel,
        public_de=public_de,
        public_en=public_en,
    )

    new_main = BeautifulSoup(build_single_main(item, items, locale=locale, target_rel=target_rel), "html.parser").find("main")
    old_main = soup.find("main")
    if new_main and old_main:
        old_main.replace_with(new_main)

    if soup.body:
        soup.body.insert(0, Comment(" Generated by scripts/import_acm_news.py "))

    target_path.parent.mkdir(parents=True, exist_ok=True)
    html_output = "<!DOCTYPE html>\n" + soup.decode(formatter="html")
    target_path.write_text(html_output, encoding="utf-8")
    return target_rel.as_posix()


def strip_generated_block(source: str, start_marker: str, end_marker: str) -> str:
    pattern = re.escape(start_marker) + r".*?" + re.escape(end_marker)
    return re.sub(pattern, "", source, flags=re.S)


def upsert_archive_into_listing(page_path: Path, *, section_html: str) -> None:
    html = page_path.read_text(encoding="utf-8")
    html = strip_generated_block(html, ARCHIVE_SECTION_START, ARCHIVE_SECTION_END)
    html = strip_generated_block(html, ARCHIVE_SCRIPT_START, ARCHIVE_SCRIPT_END)

    section_block = f"\n{section_html}\n"
    if CONTACT_CTA_MARKER not in html:
        raise RuntimeError(f"Could not find contact CTA marker in {page_path}")
    html = html.replace(CONTACT_CTA_MARKER, f"{section_block}{CONTACT_CTA_MARKER}", 1)

    script_block = f"\n{ARCHIVE_SCRIPT_START}\n{ARCHIVE_LOAD_MORE_SCRIPT}\n{ARCHIVE_SCRIPT_END}\n"
    html = html.replace("</body>", f"{script_block}</body>", 1)
    page_path.write_text(html, encoding="utf-8")


def upsert_main_grid_into_listing(
    page_path: Path,
    *,
    items: list[NewsItem],
    locale: str,
    target_rel: PurePosixPath,
) -> None:
    html = page_path.read_text(encoding="utf-8")
    html = strip_generated_block(html, GRID_IMPORT_START, GRID_IMPORT_END)

    block = f"\n{build_main_grid_import_block(items, locale=locale, target_rel=target_rel)}\n"
    first_hidden_article = '<article class="news-card scroll-reveal flex flex-col hidden news-load-more-item"'
    load_more_marker = "<!-- Load More -->"

    if first_hidden_article in html:
        html = html.replace(first_hidden_article, f"{block}{first_hidden_article}", 1)
    elif load_more_marker in html:
        html = html.replace(load_more_marker, f"{block}{load_more_marker}", 1)
    else:
        raise RuntimeError(f"Could not find news grid insertion marker in {page_path}")

    page_path.write_text(html, encoding="utf-8")


def write_archive_data(items: list[NewsItem]) -> None:
    payload = [
        {
            "slug": item.slug,
            "source_url": item.source_url,
            "published_iso": item.published_iso,
            "published_label_de": item.published_label_de,
            "published_label_en": item.published_label_en,
            "title_de": item.title_de,
            "title_en": item.title_en,
            "summary_de": item.summary_de,
            "summary_en": item.summary_en,
            "image_remote_url": item.image_remote_url,
            "image_local_path": item.image_local_path,
            "body_html_de": item.body_html_de,
            "body_html_en": item.body_html_en,
        }
        for item in items
    ]
    DATA_OUTPUT_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def public_url(path: str) -> str:
    return f"{PAGES_CONFIG['base_url'].rstrip('/')}/{path.lstrip('/')}"


def write_sitemap(items: list[NewsItem], *, extra_items: list[NewsItem] | None = None) -> None:
    page_entries: list[tuple[str, str]] = []
    lastmod = datetime.now().astimezone().date().isoformat()

    for page in PAGES_CONFIG["pages"]:
        page_entries.append((page["public_de"], lastmod))
        page_entries.append((page["public_en"], lastmod))

    sitemap_items = merge_listing_items(items, extra_items or [])
    for item in sitemap_items:
        page_entries.append((f"/news/{item.slug}.html", item.published_iso))
        page_entries.append((f"/en/news/{item.slug}.html", item.published_iso))

    xml = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ]
    for path, last_modified in page_entries:
        xml.extend(
            [
                "  <url>",
                f"    <loc>{public_url(path)}</loc>",
                f"    <lastmod>{last_modified}</lastmod>",
                "  </url>",
            ]
        )
    xml.append("</urlset>")
    SITEMAP_PATH.write_text("\n".join(xml) + "\n", encoding="utf-8")


def generate_listing_sections(items: list[NewsItem]) -> None:
    upsert_main_grid_into_listing(
        DE_LISTING_PATH,
        items=items,
        locale="de",
        target_rel=DE_LISTING_REL,
    )
    upsert_archive_into_listing(
        DE_LISTING_PATH,
        section_html=build_archive_section(items, locale="de", target_rel=DE_LISTING_REL),
    )
    upsert_main_grid_into_listing(
        EN_LISTING_PATH,
        items=items,
        locale="en",
        target_rel=EN_LISTING_REL,
    )
    upsert_archive_into_listing(
        EN_LISTING_PATH,
        section_html=build_archive_section(items, locale="en", target_rel=EN_LISTING_REL),
    )


def generate_single_pages(items: list[NewsItem]) -> None:
    for item in items:
        render_single_page(item, items, locale="de")
        render_single_page(item, items, locale="en")


def collect_news_items() -> tuple[list[NewsItem], list[str]]:
    cards = fetch_archive_cards()
    items: list[NewsItem] = []
    skipped: list[str] = []
    for card in cards:
        parsed = parse_news_item(card)
        if parsed is None:
            skipped.append(card["source_url"])
            continue
        items.append(parsed)

    items.sort(key=lambda item: item.published_iso, reverse=True)
    return items, skipped


def main() -> None:
    LOCAL_IMAGE_DIR.mkdir(parents=True, exist_ok=True)
    NEWS_DIR.mkdir(parents=True, exist_ok=True)
    EN_NEWS_DIR.mkdir(parents=True, exist_ok=True)

    items, skipped = collect_news_items()
    if len(items) != 13:
        raise RuntimeError(f"Expected 13 importable ACM news items, found {len(items)}")

    manual_items = collect_manual_listing_items()
    listing_items = merge_listing_items(items, manual_items)

    write_archive_data(items)
    generate_listing_sections(listing_items)
    generate_single_pages(items)
    write_sitemap(items, extra_items=manual_items)
    save_translation_cache()

    print(f"Imported {len(items)} ACM news items.")
    if skipped:
        print("Skipped:")
        for url in skipped:
            print(f"  - {url}")


if __name__ == "__main__":
    main()
