# ACM AIR CHARTER — static source contract

Canonical HTML lives in `acm_design V4/`. WordPress theme PHP, plugins and
`source_assets` are **not** the source. Global Theme Distribution compiles a
signed theme ZIP; WordPress never reads this GitHub repository at request time.

Follow this file when adding or changing ACM pages, CSS or JavaScript. The GTD
HTML annotation rules also apply (`data-lw-*`, one `<main>`, one `<h1>`). Full
platform contract: `Netzwerft/Global Theme Distribution/AGENTS.md`.

## Canonical vs ignore

| Use | Do not use |
| --- | --- |
| `acm_design V4/*.html` | `leadwerk_importer/source_assets/**` |
| `acm_design V4/news/*.html` | `leadwerk_theme/source_shells/**` |
| `acm_design V4/Fotos/**` (referenced files only) | `leadwerk-fields/**`, `leadwerk_importer/**`, `leadwerk-wpml-clone/**`, `leadwerk_theme/**` |
| GTD overlay CSS/JS for production | Tailwind CDN / Google Fonts in the WordPress package |

## New public page (not news)

1. Add `acm_design V4/<slug>.html`. `lang="de"`, one `<main>`, one `<h1>`, title
   and meta description. Unique section `id`s. No `href="#"`, no `javascript:`.
2. Register it in GTD `projects/acm/project.json`:
   - `source.pagePatterns` (filename list; `**/*.html` is **not** used here)
   - `source.pageOverrides` with a new stable `sourceKey` (`acm-<slug>-v1`),
     `route`, `template`, `status: "publish"`
3. If the page has layout sections, add a `pageSchemas` entry in
   `projects/acm/schema-overrides.json`. Every `selector` must match **exactly
   one** node. The compiler requires all schema sections (currently 108); adding
   sections raises that count. Missing or duplicate selectors fail the build.
4. Production CSS/JS is the GTD overlay, not the CDN in the HTML preview:
   - `projects/acm/overlay/assets/css/page-<name>.css`
   - `projects/acm/overlay/assets/js/page-<name>.js` (only if needed)
   - enqueue both lists in `projects/acm/overlay/inc/project-runtime.php.tpl`
   Preview HTML may keep Tailwind/Google Fonts; GTD strips them before validate.
   Local fonts and `acm-tailwind.css` ship from the overlay.
5. Link the page from nav/footer with a project path (`/slug.html` or `/slug/`).
6. From GTD:

   ```bash
   node tooling/bin/gtd.mjs validate --project projects/acm/project.json
   node tooling/bin/gtd.mjs build --project projects/acm/project.json --dry-run
   ```

7. Push the HTML repo **and** the GTD profile/overlay changes. Theme Center
   installs the next published ZIP. A wp-admin popup applies only the
   HTML/field/section delta and then offers the next server version. CSS/JS
   come with the theme ZIP. Do not skip intermediate versions on the live site.

Do not change a `sourceKey` after first release.

## New news article

Put the file in `acm_design V4/news/<slug>.html`. `newsPatterns` already picks
`news/*.html` as `acm_news` posts (not WordPress pages). Do not add news files to
`pagePatterns` (that would collide with `/news/`). Keep a stable `sourceKey`.
Article CSS lives in overlay `page-news-article.css`.

## CSS and JavaScript

- **Preview:** optional local files next to the HTML (`mobile-qa.css`,
  `page-aircraft.js`). No localhost, no debug POST, no inline event handlers.
- **WordPress:** overlay only. A new stylesheet that is not enqueued in
  `project-runtime.php.tpl` will not load on the site.
- Images: local `src`, lowercase/stable names, real files. Unreferenced `Fotos/`
  files are not packed (that is why the theme ZIP is ~38 MB, not ~588 MB).

## Forbidden

- Copying HTML into `source_assets` or `source_shells`
- Putting Fields/Importer/Translation PHP into this GitHub repo for GTD
- Weakening the GTD validator so a broken page “passes”
- Enabling ACM `releaseEnabled` without documented staging gates
- Skipping published theme versions on the live WordPress site
