<?php
/**
 * Content storage and publishing.
 * data/content.json holds everything the panel edits (texts, lists, photo references, settings).
 * Saving = validate -> render every page in memory -> back up the old content -> write content -> write pages.
 * If rendering fails nothing is saved, so a mistake can never take the website down.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const DM_CONTENT = DM_ROOT . '/data/content.json';
const DM_BACKUPS = DM_ROOT . '/data/backups';
const DM_PUBLISHED = DM_ROOT . '/data/published.json';
const DM_KEEP_BACKUPS = 60;
/** Pages the site writes; anything else in the site folder (send.php, assets, admin…) is never touched. */
const DM_PAGE_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.html$/';

function content_default(): array { return json_decode((string)file_get_contents(DM_APP . '/content.default.json'), true, 512, JSON_THROW_ON_ERROR); }
function content_load(): array {
    $def = content_default();
    if (!is_file(DM_CONTENT)) return $def;
    $cur = json_decode((string)file_get_contents(DM_CONTENT), true);
    if (!is_array($cur)) throw new RuntimeException('data/content.json okunamadı (bozuk). Yedekler sayfasından eski bir sürümü geri yükleyin.');
    return merge_defaults($def, $cur);
}
function content_json(array $c): string { return json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }

function with_lock(callable $fn) {
    if (!is_dir(DM_ROOT . '/data')) mkdir(DM_ROOT . '/data', 0755, true);
    $h = fopen(DM_ROOT . '/data/.lock', 'c');
    if (!$h || !flock($h, LOCK_EX)) throw new RuntimeException('Kilit alınamadı, birazdan tekrar deneyin.');
    try { return $fn(); } finally { flock($h, LOCK_UN); fclose($h); }
}

function backup_current(string $why): ?string {
    if (!is_file(DM_CONTENT)) return null;
    if (!is_dir(DM_BACKUPS)) mkdir(DM_BACKUPS, 0755, true);
    $name = 'content-' . date('Ymd-His') . '-' . sprintf('%06d', (int)(fmod(microtime(true), 1) * 1e6)) . '.json';   // sorts by time
    if (is_file(DM_BACKUPS . '/' . $name)) $name = str_replace('.json', '-' . bin2hex(random_bytes(2)) . '.json', $name);
    $meta = json_encode(['why' => mb_substr($why, 0, 80), 'at' => date('c')], JSON_UNESCAPED_UNICODE);
    copy(DM_CONTENT, DM_BACKUPS . '/' . $name);
    file_put_contents(DM_BACKUPS . '/' . $name . '.meta', $meta);
    $all = backups_list();
    foreach (array_slice($all, DM_KEEP_BACKUPS) as $b) { @unlink(DM_BACKUPS . '/' . $b['file']); @unlink(DM_BACKUPS . '/' . $b['file'] . '.meta'); }
    return $name;
}
function backups_list(): array {
    $o = [];
    foreach (glob(DM_BACKUPS . '/content-*.json') ?: [] as $f) {
        $m = is_file($f . '.meta') ? (json_decode((string)file_get_contents($f . '.meta'), true) ?: []) : [];
        $o[] = ['file' => basename($f), 'time' => filemtime($f), 'why' => (string)($m['why'] ?? ''), 'size' => filesize($f)];
    }
    usort($o, fn($a, $b) => strcmp($b['file'], $a['file']));                   // newest first (names carry the time)
    return $o;
}

/** Old addresses keep working: a tiny page that forwards to the new one (search engines treat it as a redirect). */
function redirect_stub(string $to): string {
    $u = site_url() . '/' . $to;
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Moved</title><meta name="robots" content="noindex">'
        . '<link rel="canonical" href="' . t($u) . '"><meta http-equiv="refresh" content="0;url=' . t($to) . '"></head>'
        . '<body><p>This page has moved: <a href="' . t($to) . '">' . t($u) . '</a></p></body></html>';
}

/** Render everything for $c without writing anything (throws on problems). */
function render_all(array $c): array {
    $GLOBALS['DM_C'] = $c;
    $r = build_site(false);
    // forwarders for renamed pages (only where no real page lives now)
    foreach (($c['redirects'] ?? []) as $from => $to) {
        if (!preg_match(DM_PAGE_RE, (string)$from) || !preg_match(DM_PAGE_RE, (string)$to) || isset($r['files'][$from])) continue;
        $r['files'][$from] = redirect_stub(isset($r['files'][$to]) ? (string)$to : 'index.html');      // target gone too: home page
    }
    return $r;
}

/** Write rendered files into the site folder and remove pages this panel generated earlier that no longer exist. */
function publish_files(array $files): array {
    $prev = is_file(DM_PUBLISHED) ? (json_decode((string)file_get_contents(DM_PUBLISHED), true)['files'] ?? []) : [];
    $written = 0;
    foreach ($files as $path => $html) {
        if (!preg_match(DM_PAGE_RE, $path) && !in_array($path, ['sitemap.xml', 'robots.txt'], true)) continue;
        $dest = DM_ROOT . '/' . $path;
        if (is_file($dest) && hash_file('sha256', $dest) === hash('sha256', $html)) continue;
        write_atomic($dest, $html);
        $written++;
    }
    $removed = [];
    foreach ($prev as $old) {
        if (!isset($files[$old]) && preg_match(DM_PAGE_RE, $old) && !in_array($old, ['index.html', '404.html'], true) && is_file(DM_ROOT . '/' . $old)) {
            @unlink(DM_ROOT . '/' . $old); $removed[] = $old;
        }
    }
    write_atomic(DM_PUBLISHED, json_encode(['files' => array_keys($files), 'at' => date('c'), 'version' => DM_VERSION], JSON_PRETTY_PRINT));
    return ['written' => $written, 'removed' => $removed, 'total' => count($files)];
}

/** Save new content and republish the site. Returns a short report; throws with a readable message on failure. */
function content_save(array $new, string $why): array {
    return with_lock(function () use ($new, $why) {
        $new['version'] = 1;
        $new['updated_at'] = date('c');
        $r = render_all($new);                         // fails before anything is written
        backup_current($why);
        write_atomic(DM_CONTENT, content_json($new));
        $rep = publish_files($r['files']);
        $GLOBALS['DM_C'] = $new;
        return $rep;
    });
}
function site_rebuild(): array {
    return with_lock(function () {
        $c = content_load();
        $r = render_all($c);
        return publish_files($r['files']);
    });
}
function published_info(): array { return is_file(DM_PUBLISHED) ? (json_decode((string)file_get_contents(DM_PUBLISHED), true) ?: []) : []; }

/* ---------- path helpers for nested content ---------- */
function path_get(array $a, string $path, $default = null) {
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) return $default;
        $a = $a[$k];
    }
    return $a;
}
function path_set(array &$a, string $path, $value): void {
    $ref = &$a;
    foreach (explode('.', $path) as $k) {
        if (!is_array($ref)) $ref = [];
        if (!array_key_exists($k, $ref)) $ref[$k] = [];
        $ref = &$ref[$k];
    }
    $ref = $value;
}
/** All places in the content that use a given photo file (for alt-text edits and renames). */
function image_refs(array $c, string $file, string $prefix = ''): array {
    $o = [];
    foreach ($c as $k => $v) {
        $p = $prefix === '' ? (string)$k : $prefix . '.' . $k;
        if (is_array($v)) {
            if (($v['file'] ?? null) === $file && array_key_exists('alt', $v)) $o[] = $p;
            else $o = array_merge($o, image_refs($v, $file, $p));
        }
    }
    return $o;
}
/** Replace a file path everywhere (string values equal to $old). */
function replace_file_refs(array $c, string $old, string $new): array {
    foreach ($c as $k => $v) {
        if (is_array($v)) $c[$k] = replace_file_refs($v, $old, $new);
        elseif ($v === $old) $c[$k] = $new;
    }
    return $c;
}

/** Page addresses in use (to keep new service/project/city addresses unique). */
function reserved_slugs(array $c, string $exceptPath = ''): array {
    $fixed = ['index', 'about', 'services', 'contact', 'faq', 'service-areas', 'projects', '404', 'send', 'admin', 'app', 'data', 'assets', 'lib', 'sitemap', 'robots', 'site-links', 'form-config'];
    $o = array_fill_keys($fixed, 'site');
    foreach ($c['services'] ?? [] as $i => $s) if ("services.$i" !== $exceptPath) $o[$s['slug']] = "services.$i";
    foreach ($c['cities'] ?? [] as $i => $x) if ("cities.$i" !== $exceptPath) $o[$x['slug']] = "cities.$i";
    foreach ($c['projects'] ?? [] as $i => $p) if ("projects.$i" !== $exceptPath) $o['project-' . $p['slug']] = "projects.$i";
    return $o;
}

/** Uploaded photos that neither the site nor any backup uses any more (safe to delete). */
function unused_uploads(): array {
    $dir = DM_ROOT . '/' . DM_UPLOADS;
    if (!is_dir($dir)) return [];
    $texts = [is_file(DM_CONTENT) ? (string)file_get_contents(DM_CONTENT) : content_json(C())];
    foreach (glob(DM_BACKUPS . '/content-*.json') ?: [] as $b) $texts[] = (string)file_get_contents($b);
    $all = str_replace('\\/', '/', implode("\n", $texts));
    $o = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || str_starts_with($f->getFilename(), '.')) continue;
        $rel = DM_UPLOADS . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        $main = preg_replace('/-sm\.webp$/', '.webp', $rel);                    // phone copies belong to their photo
        if (str_contains($all, '"' . $rel . '"') || str_contains($all, '"' . $main . '"')) continue;
        $o[$rel] = $f->getSize();
    }
    ksort($o);
    return $o;
}
