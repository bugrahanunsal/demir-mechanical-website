# Demir Mechanical & Construction — website + admin panel

Website of **Demir Mechanical & Construction Ltd.** (Toronto / GTA plumbing and mechanical contractor):
<https://demirmechanical.com>, hosted on Hostinger (Business Web Hosting, LiteSpeed, PHP 8.x).

The public site is **plain static HTML** (fast, nothing to hack, works with any CDN). A small **PHP admin panel**
at `/admin/` edits one content file (`data/content.json`) and regenerates every page in a fraction of a second.
No database, no framework, no build step on the server, no Composer packages.

```
Visitor  ──►  index.html, about.html, plumber-toronto.html …   (static files, served directly)
Owner    ──►  /admin/  ──►  data/content.json  ──►  app/pages.php renders all pages  ──►  *.html written to the site root
```

## Folders

| Path | What it is | In git? | On the server |
|---|---|---|---|
| `app/pages.php`, `app/lib.php` | Page renderer: builds every page from the content (pure functions, no I/O except reading images) | yes | denied to the web |
| `app/theme/` | CSS (`style.html`), page script (`script.html`), icons, decorative SVG, fixed snippets | yes | denied |
| `app/content.default.json` | Default content = the site as launched. New fields added here reach existing sites automatically (deep merge) | yes | denied |
| `app/admin/` | Admin panel code: `core` (auth, CSRF, rate limit), `store` (save/publish/backups), `images` (uploads), `schema` (what is editable), `forms` (form engine), `views`, `actions`, `seo` (SEO panel), `media` (Galeri), `invoices` (Faturalar), `pdf` (PDF writer), `boot` | yes | denied |
| `admin/` | Panel entry point `index.php`, rebuild hook `hook.php`, `assets/admin.css`, `assets/admin.js` | yes | public (login required) |
| `assets/` | Logos, icons, photos, cursor. `assets/uploads/` = photos uploaded in the panel | yes (uploads: no) | public |
| `send.php`, `lib/phpmailer/` | Contact/quote form mailer (Hostinger SMTP via PHPMailer, `mail()` fallback) | yes | public / denied |
| `form-config.php` | **Secret**: mailbox password for the form mailer. Copy from `form-config.sample.php` | **never** | denied |
| `data/` | **Server only**: `content.json`, `invoices.json` (invoices, customer data), `admin-auth.php` (password hash), `backups/`, `published.json`, `ratelimit/` | **never** | denied |
| `*.html`, `sitemap.xml`, `robots.txt` | Generated pages | no (generated) | public |
| `_dev/` | Local build, test server router, tests | yes | denied |

`.htaccess` blocks `app/ data/ lib/ _dev/ docs/`, dot-files, `form-config*.php` and `*.md`; each private folder also has its own
`Require all denied`. `assets/uploads/.htaccess` refuses to run anything but images.

## Admin panel (Turkish UI)

* **İçerik:** services (each has its own page), service areas (one `plumber-<city>.html` page each), **projects** with photo
  galleries (own page per project, lightbox viewer, home-page section and menu item appear automatically), reviews, FAQ.
* **Sayfalar:** every text of every page (home, about, contact, the shared service/city/project page templates, 404, shared blocks).
* **Site ayarları:** business info (phone/address/hours update everywhere incl. Google structured data), logo / footer logo / favicon
  uploads, header menu (order, labels, visibility, custom links) and footer, WhatsApp number + messages, social links,
  Google Analytics 4 ID, Search Console verification, suppliers.
* **SEO paneli:** one row per page (grouped: pages, services, areas, projects) with a status dot, the Google title and
  "keyphrase · title · description" dots; search and filters (Tümü / İyileştirilecek / Değiştirilen). "Düzenle" opens an editor
  window (AJAX, deep link `?p=seo&edit=page.html`, previous/next page):
  focus keyphrase (only used for the check, never written into the pages; services/areas get an automatic one),
  Google preview for phone and computer (titles are measured in pixels with Arial widths, 600px like Google — the same table
  in PHP and JS), title and description with meters, "Öner" (rule-based suggestions with the keyphrase) and "use the original",
  the page address (read-only, link to where it can be changed), the sharing image (og:image: pick from the site photos, upload
  a new one = cropped to 1200×630 JPG, share without an image, back to the automatic one), every photo on the page with its
  **file name and alternative text** (renaming copies the photo to `assets/uploads/photos/<new-name>` with its phone copy and updates
  every reference; the old file stays for older backups), and an SEO check in Turkish (improvements / good results: keyphrase in
  title, description, address, H1, opening paragraphs, text and alt texts, keyphrase used twice, title px, description 120–156,
  duplicates, H1, word count, alt texts, file names, sharing image). It updates while typing (`a=seo_check`, nothing saved).
  Code: `app/admin/seo.php`; per-page settings `seo_title`, `seo_description`, `seo_keyphrase`, `og_image` ('' = automatic,
  'none' = no image) live at `pages.<page>` or on the service / area / project item (`page_base()` in `app/pages.php`).
* **Galeri:** every image of the site in one grid, wherever it was uploaded (services, areas, projects, home page, founder,
  logos, sharing images, the site's own `assets/photos`, unused uploads), with file name and alternative text on each card;
  search and filters (photos / sharing images / logos / missing alt / unused). A click opens a window (deep link
  `?p=media&open=<file>`, previous/next) with the large photo, size, every place that uses it (links to the editor and the page),
  and the editable **alt text** (written to every use; empty refused unless all uses are project gallery photos, which fall back to
  the project title) and **file name** (same rename as the SEO panel). Nothing is stored for the gallery itself: it is read from
  the content and the photo folders (`app/admin/media.php`).
* **Faturalar (İşletme):** invoices for the business's customers, as PDF. Stored in `data/invoices.json` (mode 0600, never in
  git or the content; a copy goes to `data/backups/invoices-*.json` before every change, the full zip backup includes it).
  List with unpaid / this month / paid this year, search and filters (Ödenmemiş, Gecikmiş = past the due date, Ödendi, Taslak,
  İptal). Editor (form engine, `invoice_fields()`): number (prefix + running number, unique), status, dates, customer (names
  from earlier invoices fill the other details), service address, lines (description / qty / rate), tax (HST 13 %), discount
  before tax, deposit, notes, payment details; live totals in the page (admin.js rounds like PHP `round()`), "PDF önizle"
  (POST, nothing saved), copy, delete. The PDF is English, Letter size, written by `DmPdf` (`app/admin/pdf.php`: Helvetica,
  WinAnsi, the header logo as JPEG, pages with "Page x of y", PAID / VOID stamp). Settings at `content.invoice`
  (Fatura Ayarları: HST number, prefix, first number, tax, due days, payment text with `{email}` `{legal_name}` `{phone}`, note);
  the section is `private` (no site preview; saving still goes through `content_save`, pages are unchanged).
* **Yedekler:** automatic backup before every save (last 60), one-click restore, download `content.json`, download a **full zip**
  of the site (pages + panel + photos + content, without passwords), import a `content.json`, delete uploads no backup uses.
* **Önizle:** every editor can preview the page with unsaved changes in a new tab (nothing is written).
* **Özel Kod (CSS / JS):** custom CSS (after the site styles), custom JavaScript (end of every page) and raw HTML snippets for
  `<head>`, right after `<body>` and before `</body>` (Meta Pixel, Tag Manager, chat widgets). An on/off switch keeps the code but
  removes it from the pages. Every page has `<body data-page="…" data-type="…">` (types: home, services, service, areas, city,
  projects, project, about, contact, faq, 404) so CSS can target one page or one kind of page. The panel sends code fields
  base64-encoded (`b64:` prefix) so hosting firewalls that block posts containing `<script>` don't interfere. HTML snippets are
  left out of the Claude preview build.
  **Sitenin kendi kodu:** the site's own CSS (`app/theme/style.html` without its `<style>` wrapper) and JavaScript
  (`script.html`, must stay `<script>` blocks) are shown in two code fields (`themecode` type). Saving an unchanged copy stores
  nothing (`custom.theme_css` / `theme_js` stay ''), so code updates keep arriving. An edited copy replaces the shipped file in
  `css()` / `scripts()` while custom code is switched on; `theme_*_base` keeps the sha1 of the original it was edited from, and the
  panel warns when a newer original has shipped since. "Orijinal koda dön" puts the original back.
* Photo uploads: phone photos are resized in the browser (≤2400 px) and on the server (WebP, 1600 px + 700 px `-sm` copy for
  phones), turned upright from EXIF, named from the alt text / title, unique names (images are cached for 6 months).

### How saving works (`app/admin/store.php`)
1. The posted form is validated against the schema (`app/admin/schema.php`, rendered/parsed by `forms.php`).
2. All pages are rendered **in memory** from the new content. Any exception → nothing is written.
3. The current `content.json` is copied to `data/backups/`, the new one is written atomically.
4. Pages are written atomically, unchanged files are skipped, pages that no longer exist are deleted
   (only pages listed in `data/published.json`, never other files). Renamed/deleted pages get a small forwarding page
   (`content.redirects`), so old URLs keep working.

### Security
* One admin password (bcrypt via `password_hash`), stored in `data/admin-auth.php` as text behind a PHP exit line
  (read with `file_get_contents`, never `include`, so OPcache cannot serve a stale copy).
* Signed session cookie (HMAC-SHA256, 14 days, `Secure`, `HttpOnly`, `SameSite=Strict`, path `/admin/`).
  Password change or "log out everywhere" rotates the signing key.
* First-run setup must prove ownership: the mailbox password already in `form-config.php`, or — if that is empty —
  a one-time code written to `data/setup-code.txt` (readable only through the hosting file manager).
* CSRF token on every POST + `Origin` check; login and setup rate-limited per IP (8 failures / 15 min).
* Strict CSP on panel pages (`script-src 'self'`, no inline script), `X-Frame-Options: DENY`, `noindex`. Only file responses
  drop them (`header_remove`): downloads drop the CSP, invoice PDFs also `X-Frame-Options` so the browser's PDF viewer can show
  them. Both still need the login.
* Uploads are re-encoded by GD (no original bytes are kept), stored under generated names.
* Forgot the password? Delete `data/admin-auth.php` in the file manager and open `/admin/` again.

## Local development

Requirements: PHP ≥ 8.1 with GD (WebP), EXIF, Zip, mbstring; for tests Python 3 + Playwright (Chromium) + Pillow, and `qpdf` +
`poppler-utils` (pdftotext, pdftoppm) for the invoice PDF checks.

```bash
# build the pages from the default content into a folder
php _dev/build.php --out=/tmp/site                       # --preview: Claude artifact variant, --content=FILE: other content
# run the site + admin locally (mimics the .htaccess rules)
cp form-config.sample.php form-config.php                # put any test password in it (used as the setup proof)
php _dev/build.php --out=.  && php -S 127.0.0.1:8080 -t . _dev/router.php
# then open http://127.0.0.1:8080/admin/
```

Tests:

```bash
python3 _dev/tests/e2e_admin.py      # full admin panel run on a throw-away copy (~220 checks, ~3 min)
python3 _dev/tests/site_features.py  # WhatsApp / Analytics / social links in the generated pages
python3 _dev/tests/parity.py A B     # compare two built sites page by page (normalised)
```

### Changing the site
* **Texts, photos, menu, SEO** → in the admin panel, not in code.
* **New editable field** → add the default value to `app/content.default.json`, use it in `app/pages.php`, add a field to
  `app/admin/schema.php`. Existing sites receive the default automatically (`merge_defaults`).
* **Design / page structure** → `app/theme/style.html`, `app/theme/script.html`, `app/pages.php`.
* **After any change to `app/`**: bump `DM_VERSION` in `app/lib.php`. The server regenerates all pages on the next panel visit
  (or immediately through the hook below) when the published version differs.
* Keep escaping conventions: `t()` for text written into the page, `h()` where the original templates used Python's
  `html.escape`, `pyjson()` for JSON-LD.

## Deployment

**A. File upload (current setup).** Upload the install zip (code + assets, no pages) to `public_html` with the Hostinger File
Manager and extract. Then open `/admin/` → setup → the panel imports the old `site-links.js` settings and regenerates all pages.
Never upload `data/` or `form-config.php` from elsewhere over the live ones.

**B. Hostinger Git deployment (optional, for a developer).** hPanel → Advanced → Git → repository
`bugrahanunsal/demir-mechanical-website`, branch `main`, path `public_html`. Hostinger may require an empty folder for the first
deployment: download a full backup in the panel first, deploy, then put `data/`, `form-config.php` and `assets/uploads/` back and
open `/admin/` once. Turn on auto-deployment to pull on every push. Content edits stay on the server (`data/`), code comes from git.

**Rebuild hook.** `.github/workflows/rebuild-after-deploy.yml` calls `POST /admin/hook.php` with header `X-DM-Key` after a push,
retrying until the server reports the new `DM_VERSION`. Create the key in the panel (Hesap & Güvenlik → Geliştirici) and store it
as the repository secret `DM_HOOK_KEY`. Without the secret the workflow does nothing.

## Handover checklist
* Hostinger access (hPanel), domain DNS, mailbox `info@demirmechanical.com`.
* Panel password (owner), `form-config.php` mailbox password — never in git.
* A full backup zip from the panel = complete copy of the live site at that moment.
