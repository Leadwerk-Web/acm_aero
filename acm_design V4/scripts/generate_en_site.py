from __future__ import annotations

import json
import re
from datetime import datetime
from pathlib import Path

from bs4 import BeautifulSoup, Comment, Doctype, NavigableString
from deep_translator import GoogleTranslator


ROOT = Path(__file__).resolve().parents[1]
I18N_DIR = ROOT / "i18n"

PAGES_CONFIG = json.loads((I18N_DIR / "pages.json").read_text(encoding="utf-8"))
GLOSSARY_CONFIG = json.loads((I18N_DIR / "glossary.json").read_text(encoding="utf-8"))
OVERRIDES_CONFIG = json.loads((I18N_DIR / "overrides.json").read_text(encoding="utf-8"))
CACHE_PATH = I18N_DIR / "cache.json"

OUTPUT_DIR = ROOT / PAGES_CONFIG["output_dir"]
PAGES = PAGES_CONFIG["pages"]
PAGE_LOOKUP = {page["filename"]: page for page in PAGES}

SKIP_TAGS = {
    "script",
    "style",
    "svg",
    "path",
    "circle",
    "source",
    "noscript",
}

SKIP_CLASSES = {
    "header-lang-sep",
    "lw-arrow",
    "badge-dot",
}

ATTRS_TO_TRANSLATE = {
    "alt",
    "aria-label",
    "title",
    "placeholder",
    "data-title",
    "data-body",
    "data-heading",
    "data-subtitle",
    "data-description",
}

EXACT_GLOSSARY = GLOSSARY_CONFIG.get("exact", {})
PROTECTED_TERMS = sorted(GLOSSARY_CONFIG.get("protected_terms", []), key=len, reverse=True)
GLOBAL_TEXT_OVERRIDES = OVERRIDES_CONFIG.get("text_exact", {})
GLOBAL_HTML_REPLACEMENTS = OVERRIDES_CONFIG.get("html_replacements", [])
PAGE_OVERRIDES = OVERRIDES_CONFIG.get("pages", {})

LANG_ACTIVE_SNIPPET = """
    // Language switcher state follows the rendered locale.
    (function setActiveLang() {
      const lang = (document.documentElement.lang || 'de').toLowerCase().startsWith('en') ? 'en' : 'de';
      document.querySelectorAll('.header-lang-link').forEach(function (a) {
        if ((a.getAttribute('data-lang') || '').toLowerCase() === lang) {
          a.classList.add('header-lang-active');
        } else {
          a.classList.remove('header-lang-active');
        }
      });
    })();
""".strip("\n")

INDEX_LABEL_FIXES_EN = {
    "flight-to": "To *",
    "flight-phone": "Phone",
    "flight-message": "Message",
    "maint-reg": "Registration",
    "mgmt-phone": "Phone *",
    "career-phone": "Phone",
}

FORM_STUB_REPLACEMENTS_EN = {
    "Form-Stub: Hier würde die Anfrage gesendet werden.": "Form stub: your request would be sent here.",
    "Form-Stub: Hier würde die Bewerbung gesendet werden.": "Form stub: your application would be sent here.",
}

translator = GoogleTranslator(source="de", target="en")
translation_cache: dict[str, str] = json.loads(CACHE_PATH.read_text(encoding="utf-8"))


def save_cache() -> None:
    CACHE_PATH.write_text(
        json.dumps(dict(sorted(translation_cache.items())), ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def is_external(url: str) -> bool:
    return url.startswith(("http://", "https://", "//", "mailto:", "tel:", "#", "data:", "javascript:"))


def split_url(value: str) -> tuple[str, str]:
    match = re.match(r"^([^?#]*)(.*)$", value)
    if not match:
        return value, ""
    return match.group(1), match.group(2)


def add_doctype(html: str) -> str:
    if html.lstrip().lower().startswith("<!doctype"):
        return html
    return "<!DOCTYPE html>\n" + html


def preserve_whitespace(source: str, translated: str) -> str:
    match = re.match(r"^(\s*)(.*?)(\s*)$", source, re.S)
    if not match:
        return translated
    return f"{match.group(1)}{translated}{match.group(3)}"


def page_text_overrides(filename: str) -> dict[str, str]:
    return PAGE_OVERRIDES.get(filename, {}).get("text_exact", {})


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


def translate_string(text: str, filename: str) -> str:
    if text in page_text_overrides(filename):
        return page_text_overrides(filename)[text]
    if text in GLOBAL_TEXT_OVERRIDES:
        return GLOBAL_TEXT_OVERRIDES[text]
    if text in EXACT_GLOSSARY:
        return EXACT_GLOSSARY[text]
    if text in translation_cache:
        return translation_cache[text]

    masked, placeholder_map = mask_protected_terms(text)
    try:
        translated = translator.translate(masked)
    except Exception:
        translated = text

    translated = unmask_protected_terms(translated, placeholder_map)
    translation_cache[text] = translated
    return translated


def should_skip_text(node: NavigableString) -> bool:
    if isinstance(node, Comment):
        return True

    parent = node.parent
    if parent is None or parent.name in SKIP_TAGS:
        return True

    for ancestor in [parent, *parent.parents]:
        attrs = getattr(ancestor, "attrs", None)
        if not attrs:
            continue
        classes = set(ancestor.get("class", []))
        if classes & SKIP_CLASSES:
            return True
        if ancestor.get("aria-hidden") == "true":
            return True

    text = str(node)
    stripped = text.strip()
    if not stripped:
        return True
    if not contains_letters(stripped):
        return True
    if re.fullmatch(r"[\d\s%+./,:;|()\-–—&]+", stripped):
        return True
    if stripped in {"DE", "EN"}:
        return True
    if stripped in GLOBAL_TEXT_OVERRIDES or stripped in EXACT_GLOSSARY:
        return False
    if len(stripped) <= 5 and stripped.upper() == stripped and contains_letters(stripped):
        return True
    if "@" in stripped or stripped.startswith("http"):
        return True
    return False


def should_translate_attr(attr: str, value: str) -> bool:
    if not value or not contains_letters(value):
        return False
    if attr in {"href", "src", "poster", "srcset"}:
        return False
    if value in {"DE", "EN"}:
        return False
    return True


def translate_text_nodes(soup: BeautifulSoup, filename: str) -> None:
    for node in list(soup.find_all(string=True)):
        if should_skip_text(node):
            continue
        source = str(node)
        translated = translate_string(source.strip(), filename)
        node.replace_with(preserve_whitespace(source, translated))


def translate_attributes(soup: BeautifulSoup, filename: str) -> None:
    for tag in soup.find_all(True):
        for attr in ATTRS_TO_TRANSLATE:
            value = tag.get(attr)
            if not isinstance(value, str) or not should_translate_attr(attr, value):
                continue
            tag[attr] = translate_string(value.strip(), filename)

    for meta in soup.find_all("meta"):
        if meta.get("name") == "description" and meta.get("content"):
            meta["content"] = translate_string(meta["content"].strip(), filename)


def rewrite_srcset(value: str, locale: str) -> str:
    parts: list[str] = []
    for item in value.split(","):
        candidate = item.strip()
        if not candidate:
            continue
        tokens = candidate.split()
        tokens[0] = rewrite_relative_url(tokens[0], locale)
        parts.append(" ".join(tokens))
    return ", ".join(parts)


def rewrite_relative_url(value: str, locale: str) -> str:
    if not value or is_external(value):
        return value

    path, suffix = split_url(value.replace("\\", "/"))
    if not path:
        return value

    if path.startswith(("/", "../", "./")):
        return value

    basename = path.split("/")[-1]
    if basename in PAGE_LOOKUP and path.endswith(".html"):
        return basename + suffix

    if locale == "en":
        return f"../{path}{suffix}"

    return path + suffix


def update_resource_paths(soup: BeautifulSoup, locale: str) -> None:
    for tag in soup.find_all(True):
        for attr in ("href", "src", "poster"):
            value = tag.get(attr)
            if isinstance(value, str):
                tag[attr] = rewrite_relative_url(value, locale)

        srcset = tag.get("srcset")
        if isinstance(srcset, str):
            tag["srcset"] = rewrite_srcset(srcset, locale)

        style = tag.get("style")
        if isinstance(style, str) and locale == "en":
            tag["style"] = re.sub(
                r"url\((['\"]?)(?!https?:|data:|/|#|\.\./)([^)'\"]+)\1\)",
                r"url(\1../\2\1)",
                style,
            )


def ensure_head_locale(soup: BeautifulSoup, page: dict[str, str], locale: str) -> None:
    if soup.html:
        soup.html["lang"] = locale

    head = soup.head
    if head is None:
        return

    for link in list(head.find_all("link", rel=lambda value: value and "canonical" in value)):
        link.decompose()
    for link in list(head.find_all("link", rel=lambda value: value and "alternate" in value)):
        if link.get("hreflang") in {"de", "en"}:
            link.decompose()

    canonical_href = page["public_en"] if locale == "en" else page["public_de"]
    description = head.find("meta", attrs={"name": "description"})
    title = head.find("title")

    canonical = soup.new_tag("link", rel="canonical", href=canonical_href)
    de_alt = soup.new_tag("link", rel="alternate", hreflang="de", href=page["public_de"])
    en_alt = soup.new_tag("link", rel="alternate", hreflang="en", href=page["public_en"])

    anchor = description or title
    if anchor is None:
        head.append(canonical)
        head.append(de_alt)
        head.append(en_alt)
    else:
        anchor.insert_after(en_alt)
        en_alt.insert_before(de_alt)
        de_alt.insert_before(canonical)


def configure_header_language_switcher(soup: BeautifulSoup, filename: str, locale: str) -> None:
    switcher = soup.select_one(".header-lang-switcher")
    if not switcher:
        return

    links = switcher.select("a.header-lang-link")
    if len(links) < 2:
        return

    de_link, en_link = links[0], links[1]
    de_link["href"] = filename if locale == "de" else f"../{filename}"
    en_link["href"] = f"en/{filename}" if locale == "de" else filename
    de_link["data-lang"] = "de"
    en_link["data-lang"] = "en"
    de_link.string = "DE"
    en_link.string = "EN"
    de_classes = [cls for cls in de_link.get("class", []) if cls != "header-lang-active"]
    en_classes = [cls for cls in en_link.get("class", []) if cls != "header-lang-active"]
    if locale == "de":
        de_link["class"] = de_classes + ["header-lang-active"]
        en_link["class"] = en_classes
    else:
        de_link["class"] = de_classes
        en_link["class"] = en_classes + ["header-lang-active"]


def configure_footer_language_switcher(soup: BeautifulSoup, filename: str, locale: str) -> None:
    de_control = soup.find(id="lang-de")
    en_control = soup.find(id="lang-en")
    if not de_control or not en_control:
        return

    def build_link(source, lang_code: str) -> None:
        source.name = "a"
        source.attrs.pop("onclick", None)
        source.attrs.pop("type", None)
        classes = [cls for cls in source.get("class", []) if cls != "active"]
        if lang_code == locale:
            classes.append("active")
        source["class"] = classes
        if lang_code == "de":
            source["href"] = filename if locale == "de" else f"../{filename}"
        else:
            source["href"] = f"en/{filename}" if locale == "de" else filename
        source["data-lang"] = lang_code
        if locale == "en":
            source["aria-label"] = "German language" if lang_code == "de" else "English language"
        else:
            source["aria-label"] = "Deutsche Sprache" if lang_code == "de" else "Englische Sprache"

    build_link(de_control, "de")
    build_link(en_control, "en")


def configure_home_links(soup: BeautifulSoup, locale: str) -> None:
    home_href = "index.html"
    for link in soup.select("a.acm-logo-link"):
        link["href"] = home_href


def normalize_english_ui(soup: BeautifulSoup, filename: str) -> None:
    if filename != "index.html":
        return

    for field_id, label_text in INDEX_LABEL_FIXES_EN.items():
        label = soup.find("label", attrs={"for": field_id})
        if not label:
            continue
        label.clear()
        label.append(label_text)

    for label in soup.select('label[for$="privacy"]'):
        existing_link = label.find("a")
        href = existing_link.get("href", "#") if existing_link else "#"
        classes = existing_link.get("class", []) if existing_link else []

        label.clear()
        label.append("I have read the ")
        policy_link = soup.new_tag("a", href=href)
        if classes:
            policy_link["class"] = classes
        policy_link.string = "Privacy Policy"
        label.append(policy_link)
        label.append(" and agree to the processing of my data. *")

    for form in soup.find_all("form"):
        onsubmit = form.get("onsubmit")
        if not isinstance(onsubmit, str):
            continue
        updated = onsubmit
        for source, target in FORM_STUB_REPLACEMENTS_EN.items():
            updated = updated.replace(source, target)
        form["onsubmit"] = updated


def normalize_inline_scripts(soup: BeautifulSoup) -> None:
    for script in soup.find_all("script"):
        if script.get("src"):
            continue
        content = script.string if script.string is not None else script.get_text()
        if not content:
            continue

        updated = content
        updated = re.sub(
            r"(?:\s*//[^\n]*Sprachauswahl[^\n]*\n)?\s*\(function\s+setActiveLang\(\)\s*\{.*?\}\)\(\);\s*",
            "\n" + LANG_ACTIVE_SNIPPET + "\n",
            updated,
            flags=re.S,
        )
        updated = re.sub(
            r"\s*function\s+switchLanguage\(lang\)\s*\{[\s\S]*?localStorage\.setItem\('preferred-language',\s*lang\);\s*\}\s*",
            "\n",
            updated,
            flags=re.S,
        )
        updated = re.sub(
            r"\s*else\s+if\s*\(lang\s*===\s*['\"]en['\"]\)\s*\{[\s\S]*?localStorage\.setItem\('preferred-language',\s*lang\);\s*\}\s*",
            "\n",
            updated,
            flags=re.S,
        )
        updated = re.sub(
            r"\s*const\s+savedLang\s*=\s*localStorage\.getItem\('preferred-language'\);\s*if\s*\(savedLang\)\s*\{\s*switchLanguage\(savedLang\);\s*\}\s*",
            "\n",
            updated,
            flags=re.S,
        )
        updated = re.sub(
            r"\s*const\s+savedLang\s*=\s*localStorage\.getItem\('preferred-language'\);\s*if\s*\(savedLang\)\s*switchLanguage\(savedLang\);\s*",
            "\n",
            updated,
            flags=re.S,
        )
        updated = updated.replace("// Load saved language preference on page load", "")

        if updated != content:
            script.clear()
            script.append(updated.strip("\n") + "\n")


def apply_html_replacements(html: str, filename: str) -> str:
    result = html
    for source, target in GLOBAL_HTML_REPLACEMENTS:
        result = result.replace(source, target)

    for source, target in PAGE_OVERRIDES.get(filename, {}).get("html_replacements", []):
        result = result.replace(source, target)

    return result


def translate_page(page: dict[str, str], locale: str) -> str:
    source_path = ROOT / page["filename"]
    soup = BeautifulSoup(source_path.read_text(encoding="utf-8"), "html.parser")

    for child in list(soup.contents):
        if isinstance(child, Doctype):
            child.extract()

    if locale == "en":
        translate_text_nodes(soup, page["filename"])
        translate_attributes(soup, page["filename"])
        normalize_english_ui(soup, page["filename"])

    update_resource_paths(soup, locale)
    ensure_head_locale(soup, page, locale)
    configure_header_language_switcher(soup, page["filename"], locale)
    configure_footer_language_switcher(soup, page["filename"], locale)
    configure_home_links(soup, locale)
    normalize_inline_scripts(soup)

    html = add_doctype(soup.decode(formatter="html"))
    return apply_html_replacements(html, page["filename"])


def write_output(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def public_url(path: str) -> str:
    base_url = PAGES_CONFIG["base_url"].rstrip("/")
    normalized_path = path if path.startswith("/") else f"/{path}"
    return f"{base_url}{normalized_path}"


def write_page_sitemap() -> None:
    lastmod = datetime.now().astimezone().date().isoformat()
    entries: list[str] = []
    for page in PAGES:
        for public_path in (page["public_de"], page["public_en"]):
            entries.append(
                "  <url>\n"
                f"    <loc>{public_url(public_path)}</loc>\n"
                f"    <lastmod>{lastmod}</lastmod>\n"
                "  </url>"
            )

    sitemap = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
        + "\n".join(entries)
        + "\n</urlset>\n"
    )
    write_output(ROOT / "page-sitemap.xml", sitemap)


def main() -> None:
    OUTPUT_DIR.mkdir(exist_ok=True)
    for page in PAGES:
        de_content = translate_page(page, "de")
        en_content = translate_page(page, "en")
        write_output(ROOT / page["filename"], de_content)
        write_output(OUTPUT_DIR / page["filename"], en_content)
        print(f"Generated {page['filename']} and {PAGES_CONFIG['output_dir']}/{page['filename']}")
    write_page_sitemap()
    save_cache()


if __name__ == "__main__":
    main()
