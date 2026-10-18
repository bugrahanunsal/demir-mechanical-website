# Notes for Claude sessions working on this repo

Website + admin panel of Demir Mechanical & Construction (Toronto plumber), live at https://demirmechanical.com on Hostinger.
Read README.md first. The site owner/agency contact writes in Turkish: answer in Turkish, formal "siz". The website itself is English (en-CA);
the admin panel UI is Turkish.

## Commands
- Build pages: `php _dev/build.php --out=DIR [--preview] [--content=FILE]` (default content: `data/content.json` if present, else `app/content.default.json`)
- Local server: `php -S 127.0.0.1:8080 -t . _dev/router.php` (needs a `form-config.php` copied from the sample; built pages in the root)
- Tests (must pass before committing): `python3 _dev/tests/e2e_admin.py`, `python3 _dev/tests/site_features.py`
- `php -l` every PHP file you touch.

## Rules
- Never commit `data/`, `form-config.php`, `assets/uploads/`, generated `*.html`, `sitemap.xml`, `robots.txt` (see .gitignore). No secrets in git.
- Bump `DM_VERSION` in `app/lib.php` whenever `app/` output changes: the live server rebuilds all pages when the version differs.
- New editable content: default value in `app/content.default.json` → use it in `app/pages.php` → field in `app/admin/schema.php`
  (`F(path, type, label, opts)`, headings `HD()`); repeaters need every item key as a sub-field or the key is dropped on save.
- PHP function names are case-insensitive: `h()` exists (Python-style html.escape), so never define `H()` etc.
- Escaping: `t()` text into HTML (keeps apostrophes), `h()` where the original templates used html.escape, `pyjson()` for JSON-LD,
  `e()` inside the admin panel.
- Admin pages run under a strict CSP: no inline `<script>`/event handlers; behaviour goes in `admin/assets/admin.js`
  (`data-confirm`, `data-guard`, `data-busy`, `data-back` hooks). Data for scripts goes in `<script type="application/json">`.
- Uploaded images are cached by browsers for 6 months: always write new files with unique names (`unique_path()`), never overwrite.
- Read auth/state files with `file_get_contents`, not `include` (OPcache can serve stale copies right after a write).
- Panel saves are all-or-nothing: render everything in memory first (`render_all`), then back up, then write (`content_save`).
- Custom code (Araçlar → Özel Kod, `content.custom`) is injected by `custom_css()`, `custom_js()`, `custom_html()` in `app/pages.php`.
  Pages carry `<body data-page data-type>`; anything that looks for the body tag must allow attributes (`/<body([^>]*)>/`).
- SEO panel (`app/admin/seo.php`) analyses the rendered pages (`render_all`), never the content alone. Title widths: `seo_widths()`
  is the single Arial table, passed to admin.js as `#seo-px` JSON (PHP and JS must agree; the e2e test checks it).
  Per-page `seo_keyphrase` / `og_image` exist on fixed pages (defaults) and items (may be missing on old items: always `?? ''`).
  Photo renames copy into `assets/uploads/…` (never write into `assets/photos`, which comes from git).
- Commit messages end with the attribution lines required by the session.
