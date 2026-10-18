<?php
/**
 * SEO panel (Araçlar → SEO Paneli). One row per page with its Google title, description, focus keyphrase and sharing
 * image, and an editor window with a Google preview (phone / computer), length meters, suggestions, the page's photos
 * (file name + alternative text) and an SEO check in plain Turkish.
 * Pages are analysed as they are rendered (render_all), so the panel shows exactly what Google gets. The keyphrase is
 * only used for the check; it is never written into the pages.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const SEO_TITLE_PX = 600;          // Google shows about 600px of a title on computers (Arial 20px), then "..."
const SEO_TITLE_MIN = 30;
const SEO_DESC_MIN = 120;
const SEO_DESC_MAX = 156;
const SEO_WORDS_MIN = 300;
const SEO_STOP = ['a', 'an', 'the', 'and', 'or', 'of', 'in', 'on', 'at', 'for', 'to', 'with', 'by', 'near', 'me', 'my', 'your', 'our', 'from', 'is', 'are'];
const SEO_PLACE = ['toronto', 'gta', 'ontario', 'canada', 'on', 'ca'];     // not expected in a page address

/* ======================= measuring text ======================= */
/** Arial character widths (Google's result font) in 1/1000 em; other characters count as 556. admin.js uses the same table. */
function seo_widths(): array {
    static $w = null;
    if ($w !== null) return $w;
    $w = [32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584,
          44 => 278, 45 => 333, 46 => 278, 47 => 278, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015,
          91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
          160 => 278, 169 => 737, 174 => 737, 176 => 400, 183 => 278, 215 => 584, 8211 => 556, 8212 => 1000, 8216 => 222, 8217 => 222,
          8220 => 333, 8221 => 333, 8226 => 350, 8230 => 1000, 8482 => 1000];
    for ($i = 48; $i <= 57; $i++) $w[$i] = 556;
    foreach (explode(' ', 'A667 B667 C722 D722 E667 F611 G778 H722 I278 J500 K667 L556 M833 N722 O778 P667 Q778 R722 S667 T611 U722 V667 W944 X667 Y667 Z611 '
        . 'a556 b556 c500 d556 e556 f278 g556 h556 i222 j222 k500 l222 m833 n556 o556 p556 q556 r333 s500 t278 u556 v500 w722 x500 y500 z500') as $p)
        $w[ord($p[0])] = (int)substr($p, 1);
    ksort($w);
    return $w;
}
/** Width of a Google title in pixels (about). */
function seo_px(string $s): int {
    $w = seo_widths(); $t = 0;
    foreach (mb_str_split($s) as $ch) $t += $w[mb_ord($ch)] ?? 556;
    return (int)round($t * 20 / 1000);
}

/* ======================= comparing words ======================= */
function seo_tokens(string $s): array {
    $s = mb_strtolower(str_replace(['’', '‘', "'", '&'], ['', '', '', ' and '], $s));
    return preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
/** The words of a keyphrase that matter ("drain cleaning in toronto" -> drain, cleaning, toronto). */
function seo_core(string $kp): array {
    $o = [];
    foreach (seo_tokens($kp) as $t) if (!in_array($t, SEO_STOP, true) && !in_array($t, $o, true)) $o[] = $t;
    return $o;
}
function seo_set(string $text): array { return array_fill_keys(seo_tokens($text), true); }
/** Singular and plural forms count as the same word (heater / heaters, city / cities). */
function seo_forms(string $w): array {
    $o = [$w, $w . 's', $w . 'es'];
    if (str_ends_with($w, 'y')) $o[] = substr($w, 0, -1) . 'ies';
    if (str_ends_with($w, 'ies')) $o[] = substr($w, 0, -3) . 'y';
    if (str_ends_with($w, 'es')) $o[] = substr($w, 0, -2);
    if (str_ends_with($w, 's')) $o[] = substr($w, 0, -1);
    return $o;
}
/** Words of $core missing from $set. */
function seo_missing(array $core, array $set): array {
    $o = [];
    foreach ($core as $w) {
        $hit = false;
        foreach (seo_forms($w) as $v) if (isset($set[$v])) { $hit = true; break; }
        if (!$hit) $o[] = $w;
    }
    return $o;
}
function seo_has(array $core, array $set): bool { return $core !== [] && !seo_missing($core, $set); }
/** Same search, written differently ("Toronto plumbers" = "plumber toronto"). */
function seo_kp_key(string $kp): string {
    $w = [];
    foreach (seo_core($kp) as $x) {
        if (strlen($x) > 3 && str_ends_with($x, 'ies')) $x = substr($x, 0, -3) . 'y';
        elseif (strlen($x) > 3 && str_ends_with($x, 's') && !str_ends_with($x, 'ss')) $x = substr($x, 0, -1);
        $w[$x] = true;
    }
    $w = array_keys($w); sort($w);
    return implode(' ', $w);
}
function seo_bad_name(string $name): bool {
    return (bool)preg_match('/^(img|image|photo|picture|pic|dsc|dscn|pxl|mvimg|screenshot|screen-shot|whatsapp|untitled|unnamed|download|file|[0-9])[\w\-]*$/i', pathinfo($name, PATHINFO_FILENAME));
}

/* ======================= what is on every page ======================= */
/** Every page the panel can edit, in list order: path => where its settings live and which editor has its texts. */
function seo_catalog(array $c): array {
    $o = [];
    $fixed = [['index.html', 'home', 'Ana sayfa', 'home'], ['about.html', 'about', 'Hakkımızda', 'about'], ['services.html', 'services', 'Hizmetler', 'servicepage'],
              ['service-areas.html', 'areas', 'Hizmet bölgeleri', 'areapage'], ['projects.html', 'projects', 'Projeler', 'projectpage'],
              ['faq.html', 'faq', 'Sık sorulan sorular', 'faq'], ['contact.html', 'contact', 'İletişim', 'contactpage'], ['404.html', 'notfound', 'Sayfa bulunamadı (404)', 'notfound']];
    foreach ($fixed as [$path, $key, $name, $sec])
        $o[$path] = ['path' => $path, 'name' => $name, 'group' => 'Sayfalar', 'base' => 'pages.' . $key, 'kind' => 'page', 'i' => null, 'edit' => 'sec&s=' . $sec];
    foreach ($c['services'] ?? [] as $i => $s)
        $o[$s['slug'] . '.html'] = ['path' => $s['slug'] . '.html', 'name' => (string)$s['name'], 'group' => 'Hizmetler', 'base' => 'services.' . $i, 'kind' => 'services', 'i' => $i, 'edit' => 'item&k=services&i=' . $i];
    foreach ($c['cities'] ?? [] as $i => $x)
        $o[$x['slug'] . '.html'] = ['path' => $x['slug'] . '.html', 'name' => (string)$x['name'], 'group' => 'Bölgeler', 'base' => 'cities.' . $i, 'kind' => 'cities', 'i' => $i, 'edit' => 'item&k=cities&i=' . $i];
    foreach ($c['projects'] ?? [] as $i => $p)
        if (($p['published'] ?? true) && ($p['slug'] ?? '') !== '' && ($p['title'] ?? '') !== '')
            $o['project-' . $p['slug'] . '.html'] = ['path' => 'project-' . $p['slug'] . '.html', 'name' => (string)$p['title'], 'group' => 'Projeler', 'base' => 'projects.' . $i, 'kind' => 'projects', 'i' => $i, 'edit' => 'item&k=projects&i=' . $i];
    return $o;
}
function seo_default(): array { static $d = null; return $d ??= content_default(); }
function seo_attr(string $tag, string $attr): ?string {
    return preg_match('/\s' . $attr . '="([^"]*)"/', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
}
function seo_text(string $html): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}
/** Read a rendered page like a search engine: head tags, headings, opening paragraphs, sentences, photos. */
function seo_snap(string $html, array $c): array {
    $hp = strpos($html, '</head>');
    $head = $hp === false ? $html : substr($html, 0, $hp);
    $get = fn(string $re) => preg_match($re, $head, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    $a = strpos($html, '</header>');
    $start = $a === false ? (int)strpos($html, '<body') : $a + 9;
    $b = strpos($html, '<footer', $start);
    $main = substr($html, $start, ($b === false ? strlen($html) : $b) - $start);
    $main = preg_replace('#<(script|style|svg|form|template|noscript|iframe)\b.*?</\1>#is', ' ', $main) ?? $main;
    $main = preg_replace('#<div class="qsent"[^>]*>.*?</div>#s', ' ', $main) ?? $main;            // "message sent" boxes of the forms
    preg_match_all('#<h1\b[^>]*>(.*?)</h1>#is', $main, $m);
    $h1 = array_values(array_filter(array_map('seo_text', $m[1]), 'strlen'));
    $hp = stripos($main, '</h1>');
    preg_match_all('#<p(?:\s[^>]*)?>(.*?)</p>#is', $hp === false ? $main : substr($main, $hp), $m);
    $intro = [];
    foreach ($m[1] as $x) {
        $t = seo_text($x);
        if (mb_strlen($t) >= 40) $intro[] = $t;
        if (count($intro) >= 2) break;
    }
    $blocks = preg_replace('#<br\s*/?>|</(p|h[1-6]|li|div|summary|dt|dd|td|th|blockquote|section|article|aside|details|figcaption|label|button|ul|ol)>#i', "$0\n", $main) ?? $main;
    $segs = []; $words = 0;
    foreach (preg_split('/\n+|(?<=[.!?])\s+/u', html_entity_decode(strip_tags($blocks), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [] as $s) {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if ($s === '') continue;
        $segs[] = $s;
        $words += count(preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
    $br = $c['branding'] ?? [];
    $logos = array_filter([(string)($br['header_logo_png'] ?? ''), (string)($br['header_logo_webp'] ?? ''), (string)($br['footer_logo'] ?? '')]);
    $imgs = [];
    preg_match_all('/<img\s[^>]*>/i', $main, $m);
    foreach ($m[0] as $tag) {
        $src = (string)seo_attr($tag, 'src'); $alt = trim((string)seo_attr($tag, 'alt')); $cls = ' ' . (string)seo_attr($tag, 'class') . ' ';
        if ($src === '' || str_starts_with($src, 'data:') || str_contains($cls, ' wm ') || isset($imgs[$src]) || in_array($src, $logos, true)) continue;
        $imgs[$src] = ['file' => $src, 'alt' => $alt, 'decor' => $alt === '' && str_contains($cls, ' hero-bg '), 'name' => basename($src), 'badname' => false];
    }
    return ['title' => $get('#<title>(.*?)</title>#s'), 'desc' => $get('/<meta name="description" content="([^"]*)"/'), 'og_url' => $get('/<meta property="og:image" content="([^"]*)"/'),
            'noindex' => (bool)preg_match('/<meta name="robots" content="[^"]*noindex/i', $head), 'h1' => $h1, 'intro' => implode(' ', $intro),
            'segs' => $segs, 'words' => $words, 'imgs' => array_values($imgs)];
}
/** Suggested keyphrase when none is set: what people type into Google for this kind of page. */
function seo_auto_kp(array $e, array $node): string {
    $name = trim(preg_replace('/\s+/u', ' ', str_replace('&', 'and', (string)($node['name'] ?? ''))) ?? '');
    return match ($e['kind']) { 'services' => mb_strtolower($name) . ' toronto', 'cities' => 'plumber ' . mb_strtolower($name), default => '' };
}
/** Title and description the site writes by itself when the fields are left empty (services, areas, projects). */
function seo_auto_text(array $e, array $node): array {
    $x = ['seo_title' => '', 'seo_description' => ''] + $node;
    return match ($e['kind']) {
        'services' => [service_title($x), service_desc($x)],
        'cities' => [city_title($x), city_desc($x)],
        'projects' => [project_title($x), project_desc($x)],
        default => ['', ''],
    };
}
function seo_row_data(array $c, array $e, array $s, array $meta): array {
    $node = path_get($c, $e['base'], []);
    $node = is_array($node) ? $node : [];
    $fixed = $e['kind'] === 'page';
    $def = $fixed ? path_get(seo_default(), $e['base'], []) : [];
    $def = is_array($def) ? $def : [];
    $orig = ['kp' => trim((string)($def['seo_keyphrase'] ?? '')), 'title' => trim((string)($def['seo_title'] ?? '')), 'desc' => trim((string)($def['seo_description'] ?? '')), 'og' => ''];
    $set = ['kp' => trim((string)($node['seo_keyphrase'] ?? '')), 'title' => trim((string)($node['seo_title'] ?? '')), 'desc' => trim((string)($node['seo_description'] ?? '')), 'og' => trim((string)($node['og_image'] ?? ''))];
    [$at, $ad] = $fixed ? ['', ''] : seo_auto_text($e, $node);
    $auto = ['kp' => $fixed ? '' : seo_auto_kp($e, $node), 'title' => $at, 'desc' => $ad];
    $kp = $set['kp'] !== '' ? $set['kp'] : $auto['kp'];
    $changed = false;
    foreach ($set as $k => $v) if ($v !== $orig[$k]) $changed = true;
    $site = site_url() . '/';
    $og = str_starts_with($s['og_url'], $site) ? substr($s['og_url'], strlen($site)) : '';
    return $e + $s + ['set' => $set, 'orig' => $orig, 'auto' => $auto, 'kp' => $kp, 'kp_auto' => $set['kp'] === '' && $kp !== '', 'changed' => $changed,
                      'og' => $og, 'og_auto' => (string)($meta['og_auto'] ?? ''), 'url' => $site . ($e['path'] === 'index.html' ? '' : $e['path'])];
}
/**
 * Analyse every page for content $c (default: the saved content; cached for the request).
 * $pending: photo renames typed in the editor but not saved yet (file => new name), for the file-name check.
 */
function seo_report(?array $c = null, array $pending = []): array {
    if ($c === null && isset($GLOBALS['DM_SEO'])) return $GLOBALS['DM_SEO'];
    $own = $c === null;
    $c ??= C();
    $keep = $GLOBALS['DM_C'];
    try {
        $r = render_all($c);                       // also makes $c the current content for the helpers below
        $rows = [];
        foreach (seo_catalog($c) as $path => $e) {
            if (!isset($r['files'][$path])) continue;
            $rows[$path] = seo_row_data($c, $e, seo_snap($r['files'][$path], $c), $r['meta'][$path] ?? []);
        }
        seo_score($rows, $pending);
    } finally {
        $GLOBALS['DM_C'] = $keep;
    }
    $rep = ['rows' => $rows];
    if ($own) $GLOBALS['DM_SEO'] = $rep;
    return $rep;
}
function seo_summary(): array {
    try { $rows = seo_report()['rows']; }
    catch (\Throwable $e) { return ['pages' => 0, 'fix' => 0, 'bad' => 0]; }
    $fix = count(array_filter($rows, fn($r) => in_array($r['status'], ['ok', 'bad'], true)));
    return ['pages' => count($rows), 'fix' => $fix, 'bad' => count(array_filter($rows, fn($r) => $r['status'] === 'bad'))];
}

/* ======================= the SEO check ======================= */
function seo_is_icon(string $f): bool {
    if ($f === '') return false;
    $b = C()['branding'] ?? [];
    return (bool)preg_match('/(favicon|apple-touch|icon|logo)/i', basename($f)) || in_array($f, [(string)cget('site.schema_image'), (string)($b['header_logo_png'] ?? ''), (string)($b['footer_logo'] ?? '')], true);
}
function seo_score(array &$rows, array $pending = []): void {
    $names = []; $byT = []; $byD = []; $byK = [];
    foreach ($rows as $p => $r) {
        $names[$p] = $r['name'];
        if ($r['noindex']) continue;
        $byT[mb_strtolower($r['title'])][] = $p;
        $byD[mb_strtolower($r['desc'])][] = $p;
        if (!$r['kp_auto'] && ($k = seo_kp_key($r['kp'])) !== '') $byK[$k][] = $p;
    }
    foreach ($rows as $p => &$r) {
        foreach ($r['imgs'] as &$im) {
            if (($pending[$im['file']] ?? '') !== '') $im['name'] = $pending[$im['file']] . '.' . pathinfo($im['file'], PATHINFO_EXTENSION);
            $im['badname'] = seo_bad_name($im['name']);
        }
        unset($im);
        $others = fn(array $l) => array_map(fn($x) => $names[$x], array_values(array_diff($l, [$p])));
        $k = (!$r['kp_auto'] && !$r['noindex']) ? seo_kp_key($r['kp']) : '';
        $dup = ['title' => $r['noindex'] ? [] : $others($byT[mb_strtolower($r['title'])] ?? []),
                'desc' => $r['noindex'] ? [] : $others($byD[mb_strtolower($r['desc'])] ?? []),
                'kp' => $k !== '' ? $others($byK[$k] ?? []) : []];
        $r['checks'] = seo_checks($r, $dup);
        [$r['status'], $r['dots']] = seo_status($r);
    }
    unset($r);
}
/** Each check: id, level (good / ok / bad) and a sentence for the owner. */
function seo_checks(array $r, array $dup): array {
    $o = [];
    $add = function (string $id, string $lv, string $msg) use (&$o) { $o[] = ['id' => $id, 'lv' => $lv, 'msg' => $msg]; };
    $list = fn(array $n) => implode(', ', array_slice($n, 0, 3)) . (count($n) > 3 ? ' …' : '');
    $miss = fn(array $w) => ' (eksik: ' . implode(', ', $w) . ')';
    $title = $r['title']; $desc = $r['desc'];
    $core = seo_core($r['kp']);
    if (!$core) {
        $add('kp', 'ok', 'Odak anahtar kelime yok. Bu sayfanın Google\'da hangi aramayla bulunmasını istiyorsanız yazın (İngilizce, ör. "water heater installation toronto").');
    } else {
        $n = count($core);
        $add('kp-len', $n <= 4 ? 'good' : 'ok', $n <= 4 ? 'Anahtar kelimenin uzunluğu iyi.' : 'Anahtar kelime uzun (' . $n . ' kelime). 1–4 kelimelik bir arama ifadesi daha etkilidir.');
        $m = seo_missing($core, seo_set($title));
        $add('kp-title', $m ? 'bad' : 'good', $m ? 'Anahtar kelime Google başlığında yok' . $miss($m) . '. Başlığa ekleyin, mümkünse başa.' : 'Anahtar kelime Google başlığında geçiyor.');
        $m = seo_missing($core, seo_set($desc));
        $add('kp-desc', $m ? 'ok' : 'good', $m ? 'Anahtar kelime Google açıklamasında yok' . $miss($m) . '.' : 'Anahtar kelime Google açıklamasında geçiyor.');
        if ($r['kind'] !== 'page') {
            $uc = array_values(array_diff($core, SEO_PLACE));
            if ($uc) {
                $m = seo_missing($uc, seo_set(str_replace('-', ' ', preg_replace('/\.html$/', '', $r['path']) ?? '')));
                $add('kp-url', $m ? 'ok' : 'good', $m ? 'Sayfa adresinde anahtar kelime yok (/' . $r['path'] . ').' : 'Sayfa adresi anahtar kelimeyi içeriyor.');
            }
        }
        if ($r['h1']) {
            $m = seo_missing($core, seo_set(implode(' ', $r['h1'])));
            $add('kp-h1', $m ? 'ok' : 'good', $m ? 'Anahtar kelime sayfanın ana başlığında (H1) yok' . $miss($m) . '.' : 'Anahtar kelime sayfanın ana başlığında (H1) geçiyor.');
        }
        $m = seo_missing($core, seo_set($r['intro']));
        $add('kp-intro', $m ? 'ok' : 'good', $m ? 'Anahtar kelime sayfanın giriş paragrafında yok' . $miss($m) . '.' : 'Anahtar kelime giriş paragrafında geçiyor.');
        $hits = 0;
        foreach ($r['segs'] as $s) if (seo_has($core, seo_set($s))) $hits++;
        $min = max(2, (int)round($r['words'] * 0.005)); $max = max($min + 2, (int)round($r['words'] * 0.03));
        if ($hits < $min) $add('kp-count', 'ok', ($hits ? 'Anahtar kelime sayfa yazısında sadece ' . $hits . ' kez geçiyor' : 'Anahtar kelime sayfa yazısında hiç geçmiyor') . '; en az ' . $min . ' kez önerilir.');
        elseif ($hits > $max) $add('kp-count', 'ok', 'Anahtar kelime çok sık geçiyor (' . $hits . ' kez). Google bunu aşırı tekrar sayabilir; en fazla ' . $max . ' kez önerilir.');
        else $add('kp-count', 'good', 'Anahtar kelime sayfa yazısında ' . $hits . ' kez geçiyor; iyi.');
        $real = array_filter($r['imgs'], fn($im) => !$im['decor']);
        if ($real) {
            $hit = false;
            foreach ($real as $im) if (seo_has($core, seo_set($im['alt']))) { $hit = true; break; }
            $add('kp-alt', $hit ? 'good' : 'ok', $hit ? 'Anahtar kelime bir fotoğrafın alternatif metninde geçiyor.' : 'Anahtar kelime hiçbir fotoğrafın alternatif metninde yok.');
        }
        if ($dup['kp']) $add('kp-dup', 'ok', 'Bu anahtar kelime başka sayfada da kullanılıyor: ' . $list($dup['kp']) . '. Her sayfa farklı bir aramayı hedeflemeli.');
    }
    $tl = mb_strlen($title); $tp = seo_px($title);
    if ($tl === 0) $add('title-len', 'bad', 'Google başlığı boş.');
    elseif ($tp > SEO_TITLE_PX) $add('title-len', 'ok', 'Google başlığı uzun (yaklaşık ' . $tp . ' px). Google 600 px\'ten sonrasını "..." ile keser.');
    elseif ($tl < SEO_TITLE_MIN) $add('title-len', 'ok', 'Google başlığı kısa (' . $tl . ' karakter). Biraz daha açıklayıcı yazın.');
    else $add('title-len', 'good', 'Google başlığının uzunluğu iyi.');
    if ($dup['title']) $add('title-dup', 'bad', 'Aynı Google başlığı başka sayfada da var: ' . $list($dup['title']) . '. Her sayfanın başlığı farklı olmalı.');
    $dl = mb_strlen($desc);
    if ($dl === 0) $add('desc-len', 'bad', 'Google açıklaması boş.');
    elseif ($dl < SEO_DESC_MIN) $add('desc-len', 'ok', 'Google açıklaması kısa (' . $dl . ' karakter); 120–156 karakter idealdir.');
    elseif ($dl > SEO_DESC_MAX) $add('desc-len', 'ok', 'Google açıklaması uzun (' . $dl . ' karakter); Google 156 karakter civarından sonrasını keser.');
    else $add('desc-len', 'good', 'Google açıklamasının uzunluğu iyi (' . $dl . ' karakter).');
    if ($dup['desc']) $add('desc-dup', 'ok', 'Aynı açıklama başka sayfada da var: ' . $list($dup['desc']) . '.');
    $nh = count($r['h1']);
    $add('h1', $nh === 0 ? 'bad' : ($nh > 1 ? 'ok' : 'good'), $nh === 0 ? 'Sayfada ana başlık (H1) yok.' : ($nh > 1 ? 'Sayfada birden fazla ana başlık (H1) var.' : 'Sayfada bir ana başlık (H1) var.'));
    $add('words', $r['words'] < SEO_WORDS_MIN ? 'ok' : 'good', $r['words'] < SEO_WORDS_MIN ? 'Sayfada az yazı var (' . $r['words'] . ' kelime). Google için en az 300 kelime önerilir.' : 'Sayfa yazısının uzunluğu iyi (' . $r['words'] . ' kelime).');
    $real = array_values(array_filter($r['imgs'], fn($im) => !$im['decor']));
    if ($real) {
        $noalt = array_filter($real, fn($im) => $im['alt'] === '');
        $add('img-alt', $noalt ? 'bad' : 'good', $noalt ? count($noalt) . ' fotoğrafın alternatif metni boş. Fotoğrafta ne olduğunu İngilizce yazın.' : 'Tüm fotoğrafların alternatif metni var.');
        $bad = array_values(array_filter($real, fn($im) => $im['badname']));
        $add('img-name', $bad ? 'ok' : 'good', $bad ? 'Dosya adı açıklayıcı değil: ' . implode(', ', array_slice(array_column($bad, 'name'), 0, 3)) . '. Fotoğrafı anlatan bir ad verin (ör. copper-riser-north-york).' : 'Fotoğraf dosya adları açıklayıcı.');
    }
    $og = $r['og'];
    [$w, $h] = $og !== '' ? img_size($og) : [0, 0];
    if ($og === '') $add('og', 'ok', 'Sosyal medyada (Facebook, WhatsApp, LinkedIn) görselsiz paylaşılır.');
    elseif (seo_is_icon($og)) $add('og', 'ok', 'Paylaşımlarda sadece site ikonu görünüyor. Sayfaya uygun bir fotoğraf seçin (en iyisi 1200×630).');
    elseif ($w > 0 && ($w < 600 || $h < 315)) $add('og', 'ok', 'Paylaşım görseli küçük (' . $w . '×' . $h . '). En az 600×315, en iyisi 1200×630.');
    else $add('og', 'good', 'Paylaşım görseli var' . ($w ? ' (' . $w . '×' . $h . ')' : '') . '.');
    return $o;
}
/** Page status (good / ok / bad / off) and the three dots in the list (keyphrase, title, description). */
function seo_status(array $r): array {
    $lv = [];
    foreach ($r['checks'] as $ch) $lv[$ch['id']] = $ch['lv'];
    $rank = ['good' => 0, 'ok' => 1, 'bad' => 2];
    $worst = function (string ...$ids) use ($lv, $rank) { $w = 'good'; foreach ($ids as $i) if (isset($lv[$i]) && $rank[$lv[$i]] > $rank[$w]) $w = $lv[$i]; return $w; };
    $dots = ['kp' => $lv['kp-title'] ?? ($lv['kp'] ?? 'ok'), 'title' => $worst('title-len', 'title-dup'), 'desc' => $worst('desc-len', 'desc-dup')];
    if ($r['noindex']) return ['off', $dots];
    $n = array_count_values(array_column($r['checks'], 'lv'));
    if (!empty($n['bad'])) return ['bad', $dots];
    $good = isset($lv['kp-title']) && $lv['title-len'] === 'good' && $lv['desc-len'] === 'good' && ($n['ok'] ?? 0) <= 3;
    return [$good ? 'good' : 'ok', $dots];
}
function seo_label(string $st): string {
    return ['good' => 'İyi', 'ok' => 'İyileştirilebilir', 'bad' => 'Sorunlu', 'off' => 'Google\'da gizli'][$st] ?? $st;
}

/* ======================= suggestions ("Öner") ======================= */
/** Place names and the brand written properly (keyphrases are usually typed in lower case). */
function seo_proper(string $s): string {
    static $names = null;
    if ($names === null) {
        $names = ['Toronto', 'GTA', 'Ontario', 'Canada', 'York Region', 'Peel Region', 'Durham Region', 'Halton Region'];
        foreach (cities() as $x) $names[] = (string)$x['name'];
        $names[] = short_name(); $names[] = brand();
        $names = array_values(array_unique(array_filter($names, fn($x) => trim($x) !== '')));
        usort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    }
    foreach ($names as $n) $s = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/iu', $n, $s) ?? $s;
    return $s;
}
function seo_title_case(string $s): string {
    $small = ['a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'for', 'to', 'the', 'by', 'with', 'near'];
    $up = ['gta' => 'GTA', 'hvac' => 'HVAC', 'pvc' => 'PVC', 'abs' => 'ABS', 'faq' => 'FAQ', 'faqs' => 'FAQs'];
    $w = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $last = count($w) - 1;
    foreach ($w as $i => &$x) {
        $lx = mb_strtolower($x);
        if (isset($up[$lx])) $x = $up[$lx];
        elseif ($lx === 'on' && $i === $last && $i > 0) $x = 'ON';
        elseif (preg_match('/\p{Lu}/u', $x)) continue;
        elseif ($i > 0 && in_array($lx, $small, true)) $x = $lx;
        else $x = mb_strtoupper(mb_substr($x, 0, 1)) . mb_substr($x, 1);
    }
    unset($x);
    return seo_proper(implode(' ', $w));
}
function seo_sentence_case(string $s): string {
    $s = seo_proper(trim($s));
    foreach (['gta' => 'GTA', 'faq' => 'FAQ', 'hvac' => 'HVAC'] as $k => $v) $s = preg_replace('/(?<![\p{L}])' . $k . '(?![\p{L}])/u', $v, $s) ?? $s;
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}
function seo_node(array $r): array { $n = path_get(C(), $r['base'], []); return is_array($n) ? $n : []; }
function seo_is_auto_kp(array $r, string $kp): bool { return $kp === '' || ($r['auto']['kp'] !== '' && seo_kp_key($kp) === seo_kp_key($r['auto']['kp'])); }
function seo_cut_px(string $s, int $max): string {
    if (seo_px($s) <= $max) return $s;
    $w = preg_split('/\s+/u', $s) ?: [];
    while (count($w) > 1 && seo_px(implode(' ', $w)) > $max) array_pop($w);
    return rtrim(implode(' ', $w), ' |,;:–—-');
}
function seo_cut_chars(string $s, int $max): string {
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 1);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, ' ,;:–—-') . '…';
}
/** Google title: keyphrase first, then a short promise and the brand, as long as it fits into 600px. */
function seo_suggest_title(array $r, string $kp): string {
    $n = seo_node($r);
    if (seo_is_auto_kp($r, $kp) && $r['kind'] !== 'page') {
        $lead = match ($r['kind']) { 'services' => $n['name'] . ' in Toronto', 'cities' => 'Plumber in ' . $n['name'] . ', ON', default => (string)($n['title'] ?? $r['name']) };
    } else {
        $lead = $kp !== '' ? seo_title_case($kp) : ($r['h1'][0] ?? $r['name']);
    }
    $brand = short_name(); $tiny = explode(' ', $brand)[0];
    $hasBrand = seo_has(seo_core($tiny), seo_set($lead));
    $pre = ['about.html' => 'About', 'contact.html' => 'Contact'][$r['path']] ?? '';
    if ($hasBrand && $pre !== '' && !seo_has([mb_strtolower($pre)], seo_set($lead))) $lead = $pre . ' ' . $lead;
    $tags = [];
    if ($r['kind'] === 'services') $tags[] = !empty($n['emergency']) ? '24/7 Service' : 'Free Estimates';
    elseif ($r['kind'] === 'cities' || $r['path'] === 'index.html') $tags[] = '24/7 Service';
    $tags = array_values(array_filter($tags, fn($t) => !str_contains(mb_strtolower($lead), mb_strtolower(explode(' ', $t)[0]))));
    $cands = [];
    foreach ($tags as $t) $cands[] = $lead . ' | ' . $t . ($hasBrand ? '' : ' | ' . $brand);
    if ($hasBrand) foreach (seo_has(['toronto'], seo_set($lead)) ? ['Plumbing & Mechanical Contractor', 'Licensed Plumbers'] : ['Toronto Plumbing & Mechanical', 'Toronto & GTA'] as $t) $cands[] = $lead . ' | ' . $t;
    if (!$hasBrand) $cands[] = $lead . ' | ' . $brand;
    foreach ($tags as $t) if (!$hasBrand) $cands[] = $lead . ' | ' . $t . ' | ' . $tiny;
    if (!$hasBrand) $cands[] = $lead . ' | ' . $tiny;
    $cands[] = $lead;
    foreach ($cands as $x) if (seo_px($x) <= SEO_TITLE_PX) return $x;
    return seo_cut_px($lead, SEO_TITLE_PX);
}
function seo_sentences(string $text): array {
    $o = [];
    foreach (preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [] as $s) if (trim($s) !== '') $o[] = trim($s);
    return $o;
}
/** The text already says it (licensed / free estimate / 24/7 / GTA). */
function seo_says(string $text, string $fact): bool {
    $t = mb_strtolower($text); $f = mb_strtolower($fact);
    foreach (['licensed', 'free estimate', '24/7', 'gta'] as $k) if (str_contains($f, $k) && str_contains($t, $k)) return true;
    return false;
}
/** Shorten at a natural break (dash, semicolon, comma) so the sentence still reads well. */
function seo_clause_cut(string $s, int $max): string {
    if (mb_strlen($s) <= $max) return $s;
    foreach ([' — ', ' – ', '; ', ', '] as $sep) {
        $p = mb_strrpos(mb_substr($s, 0, $max), $sep);
        if ($p !== false && $p >= 40) return rtrim(mb_substr($s, 0, $p), ' ,;:') . '.';
    }
    return seo_cut_chars($s, $max);
}
/** "Plumbing FAQ Toronto: straight answers to …" — the keyphrase as a label in front of the page's own first sentence. */
function seo_label_lead(string $label, string $s, int $max): string {
    $w = preg_replace('/[^\p{L}]/u', '', preg_split('/\s+/u', $s, 2)[0] ?? '') ?? '';
    $acronym = mb_strlen($w) > 1 && $w === mb_strtoupper($w);
    if ($w !== '' && !$acronym && seo_proper(mb_strtolower($w)) === mb_strtolower($w)) $s = mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    return seo_clause_cut($label . ': ' . $s, $max);
}
/** "service & repairs, drain cleaning and water heaters" from the first services. */
function seo_services_phrase(int $n): string {
    $l = array_map(fn($s) => mb_strtolower((string)$s['name']), array_slice(services(), 0, $n));
    if (count($l) < 2) return $l[0] ?? 'plumbing';
    $last = array_pop($l);
    return implode(', ', $l) . ' and ' . $last;
}
/** Google description: a sentence with the keyphrase, more from the page, short facts, then "Call …" — 120 to 156 characters. */
function seo_suggest_desc(array $r, string $kp): string {
    $n = seo_node($r);
    $core = seo_core($kp);
    $brand = short_name();
    $cta = 'Call ' . phone() . '.';
    $auto = seo_is_auto_kp($r, $kp);
    $intro = array_values(array_filter(seo_sentences($r['intro']), fn($s) => !preg_match('/^(call|thanks|thank you)\b/i', $s)));
    $lead = ''; $more = $intro;
    if ($auto && $r['kind'] === 'services') $lead = seo_sentence_case(mb_strtolower((string)$n['name'])) . ' in Toronto & the GTA by ' . $brand . '.';
    elseif ($auto && $r['kind'] === 'cities') { $lead = 'Licensed plumber in ' . $n['name'] . ', Ontario.'; $more = ['24/7 ' . seo_services_phrase(3) . '.']; }
    elseif ($auto && $r['kind'] === 'projects') $lead = trim((string)($n['summary'] ?? '')) !== '' ? seo_clause_cut(trim((string)$n['summary']), 130) : $n['title'] . ' by ' . $brand . '.';
    if ($lead === '' && $core) {
        foreach (array_merge($intro, $r['segs']) as $s)
            if (preg_match('/[.!?]$/u', $s) && mb_strlen($s) >= 50 && mb_strlen($s) <= 130 && seo_has($core, seo_set($s))) { $lead = $s; break; }
        if ($lead === '' && $intro) $lead = seo_label_lead(seo_sentence_case($kp), $intro[0], SEO_DESC_MAX - mb_strlen($cta) - 1);
    }
    if ($lead === '') {
        if ($core) $lead = seo_sentence_case($kp) . (seo_has(seo_core($brand), seo_set($kp)) ? '.' : ' by ' . $brand . '.');
        else $lead = $intro[0] ?? ($r['name'] . ' by ' . $brand . '.');
    }
    if (!preg_match('/[.!?…]$/u', $lead)) $lead .= '.';
    $more = array_values(array_filter($more, fn($s) => mb_strlen($s) <= 120 && !str_contains($lead, rtrim($s, '.!?')) && !str_contains($s, rtrim($lead, '.!?'))));
    $facts = ['Licensed & insured.', 'Free estimates.'];
    if ($r['kind'] === 'cities' || $r['path'] === 'index.html' || !empty($n['emergency'])) $facts[] = '24/7 emergency service.';
    $facts[] = 'Serving Toronto & the GTA.';
    return seo_fit($lead, $more, $facts, $cta);
}
function seo_fit(string $lead, array $more, array $facts, string $cta): string {
    if (mb_strlen($lead) > SEO_DESC_MAX) return seo_cut_chars($lead, SEO_DESC_MAX);
    $out = $lead;
    $room = fn(string $o, string $add) => mb_strlen($o . ' ' . $add . ' ' . $cta) <= SEO_DESC_MAX;
    foreach ($more as $s) if ($room($out, $s)) $out .= ' ' . $s;
    foreach ($facts as $s) {
        if (mb_strlen($out . ' ' . $cta) >= SEO_DESC_MIN) break;
        if (!seo_says($out, $s) && $room($out, $s)) $out .= ' ' . $s;
    }
    if (mb_strlen($out . ' ' . $cta) <= SEO_DESC_MAX) return $out . ' ' . $cta;
    foreach ($facts as $s) if (mb_strlen($out) < SEO_DESC_MIN && !seo_says($out, $s) && mb_strlen($out . ' ' . $s) <= SEO_DESC_MAX) $out .= ' ' . $s;
    return $out;
}

/* ======================= list page ======================= */
function seo_shown(array $r, string $filter): bool {
    return $filter === '' || ($filter === 'fix' && in_array($r['status'], ['ok', 'bad'], true)) || ($filter === 'changed' && $r['changed']);
}
function view_seo(string $filter, string $edit): string {
    $rows = seo_report()['rows'];
    $filter = in_array($filter, ['fix', 'changed'], true) ? $filter : '';
    $o = '<div id="seoApp" class="seo-app" data-filter="' . e($filter) . '">'
        . head_block('SEO Paneli', 'Her sayfanın Google\'da nasıl göründüğü: başlık, açıklama, odak anahtar kelime, paylaşım görseli ve fotoğraflar. "Düzenle" ile Google önizlemesi, öneriler ve SEO kontrolü açılır. Site İngilizce olduğu için bu metinler de İngilizce yazılır.')
        . '<div class="seo-bar"><label class="seo-search">' . aic('search') . '<span class="sr">Sayfa ara</span><input type="search" id="seoSearch" placeholder="Sayfa, adres veya başlık ara…" autocomplete="off" spellcheck="false"></label>'
        . '<nav class="chips" id="seoChips" aria-label="Filtre">' . seo_chips_html($rows, $filter) . '</nav></div>'
        . '<div class="seo-legend"><span><i class="sdot good"></i>iyi</span><span><i class="sdot ok"></i>iyileştirilebilir</span><span><i class="sdot bad"></i>sorunlu</span><span><i class="sdot off"></i>Google\'da gizli</span></div>'
        . '<div id="seoList">' . seo_list_html($rows, $filter) . '</div>'
        . seo_logos_card();
    $form = '';
    if ($edit !== '' && isset($rows[$edit])) $form = seo_modal_html($rows[$edit], $rows);
    $o .= '<dialog id="seoModal" class="seo-dlg" aria-label="SEO düzenle"' . ($form !== '' ? ' open' : '') . '>' . $form . '</dialog>';
    return $o . '</div>';
}
function seo_chips_html(array $rows, string $filter): string {
    $fix = count(array_filter($rows, fn($r) => in_array($r['status'], ['ok', 'bad'], true)));
    $ch = count(array_filter($rows, fn($r) => $r['changed']));
    $o = '';
    foreach ([['', 'Tümü', count($rows)], ['fix', 'İyileştirilecek', $fix], ['changed', 'Değiştirilen', $ch]] as [$f, $l, $n])
        $o .= '<a href="index.php?p=seo' . ($f !== '' ? '&amp;f=' . $f : '') . '" data-f="' . $f . '"' . ($f === $filter ? ' class="on" aria-current="true"' : '') . '>' . e($l) . ' <b>' . $n . '</b></a>';
    return $o;
}
function seo_list_html(array $rows, string $filter = ''): string {
    $groups = [];
    foreach ($rows as $r) $groups[$r['group']][] = $r;
    $o = '';
    foreach ($groups as $g => $list) {
        $o .= '<section class="sgroup"><h2 class="sg-t">' . e($g) . ' <span>' . count($list) . '</span></h2><div class="list card">';
        foreach ($list as $r) $o .= seo_row_html($r, $filter);
        $o .= '</div></section>';
    }
    return $o . '<p class="seo-none card muted" hidden>Bu filtreye veya aramaya uyan sayfa yok.</p>';
}
function seo_thumb(string $f, string $cls = 'thumb'): string {
    if ($f === '' || !is_file(DM_ROOT . '/' . $f)) return '<span class="' . $cls . ' none" title="Paylaşım görseli yok">' . aic('image') . '</span>';
    $sm = img_small($f);
    return '<span class="' . $cls . (seo_is_icon($f) ? ' icon' : '') . '"><img src="' . e(thumb_url(is_file(DM_ROOT . '/' . $sm) ? $sm : $f)) . '" alt="" loading="lazy"></span>';
}
function seo_row_html(array $r, string $filter = ''): string {
    $q = mb_strtolower($r['name'] . ' ' . $r['path'] . ' ' . $r['title'] . ' ' . $r['kp'] . ' ' . $r['group']);
    $dot = fn(string $lv) => '<i class="sdot ' . $lv . '" aria-hidden="true"></i>';
    $meta = '<span>' . ($r['kp'] !== '' ? 'Anahtar kelime' : 'Anahtar kelime yok') . $dot($r['dots']['kp']) . '</span>'
        . '<span>Başlık ' . mb_strlen($r['title']) . $dot($r['dots']['title']) . '</span>'
        . '<span>Açıklama ' . mb_strlen($r['desc']) . $dot($r['dots']['desc']) . '</span>';
    return '<div class="srow" data-path="' . e($r['path']) . '" data-st="' . $r['status'] . '" data-ch="' . ($r['changed'] ? '1' : '0') . '" data-q="' . e($q) . '" data-px="' . seo_px($r['title']) . '"' . (seo_shown($r, $filter) ? '' : ' hidden') . '>'
        . '<a class="srow-a" href="index.php?p=seo&amp;edit=' . e(rawurlencode($r['path'])) . '" data-seo-open="' . e($r['path']) . '">'
        . '<span class="sst ' . $r['status'] . '" title="' . e(seo_label($r['status'])) . '"><span class="sr">' . e(seo_label($r['status'])) . '</span></span>'
        . '<span class="srow-t"><span class="srow-n"><b>' . e($r['name']) . '</b><small>/' . e($r['path'] === 'index.html' ? '' : $r['path']) . '</small>' . ($r['changed'] ? '<span class="tag">değiştirildi</span>' : '') . '</span>'
        . '<span class="srow-g">' . e($r['title']) . '</span><span class="srow-m">' . $meta . '</span></span>'
        . seo_thumb($r['og']) . '<span class="btn sm srow-e">' . aic('edit') . '<span>Düzenle</span></span></a></div>';
}
/** Content paths that hold the alternative text of a logo. */
function alt_paths(array $c, string $file): array {
    $p = image_refs($c, $file);
    $b = $c['branding'];
    if ($file === ($b['header_logo_png'] ?? '')) $p[] = 'branding.header_logo_alt';
    if ($file === ($b['footer_logo'] ?? '')) $p[] = 'branding.footer_logo_alt';
    return $p;
}
function seo_logos_card(): string {
    $b = C()['branding'];
    $o = '<section class="card seo-logos"><h2>' . aic('image') . ' Tüm sayfalarda: logolar</h2><p class="muted small">Header ve footer logosunun alternatif metni her sayfada kullanılır.</p>'
        . '<form method="post" class="edit" data-guard>' . csrf_field() . '<input type="hidden" name="path" value="_site"><div class="seo-imgs">';
    foreach ([['logo_alt', $b['header_logo_png'], $b['header_logo_alt'], 'Header logosu', ''], ['flogo_alt', $b['footer_logo'], $b['footer_logo_alt'], 'Footer logosu', ' dark']] as [$n, $f, $alt, $l, $cls])
        $o .= '<div class="si"><span class="si-img logo' . $cls . '"><img src="' . e(thumb_url((string)$f)) . '" alt=""></span><div class="si-b"><div class="si-n">' . e($l) . ' · ' . e(basename((string)$f)) . '</div>'
            . '<label class="mini">Alternatif metin<input type="text" name="' . $n . '" value="' . e((string)$alt) . '" required></label></div></div>';
    return $o . '</div><div class="seo-act"><a class="btn sm ghost" href="index.php?p=sec&amp;s=branding">' . aic('image') . '<span>Logoyu değiştir</span></a>'
        . '<button type="submit" class="btn primary sm" name="a" value="seo_save" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet</span></button></div></form></section>';
}

/* ======================= editor window ======================= */
function seo_meter_text(string $what, string $v, bool $auto): string {
    $n = mb_strlen($v);
    if ($what === 'title') return ($auto ? 'Otomatik · ' : '') . $n . ' karakter · yaklaşık ' . seo_px($v) . ' / ' . SEO_TITLE_PX . ' px';
    return ($auto ? 'Otomatik · ' : '') . $n . ' karakter · ideal ' . SEO_DESC_MIN . '–' . SEO_DESC_MAX;
}
function seo_og_info(array $r): string {
    $f = $r['og'];
    if ($r['set']['og'] === 'none') return 'Görselsiz paylaşılır.';
    if ($f === '') return 'Görsel yok.';
    [$w, $h] = img_size($f);
    $how = $r['set']['og'] !== '' && $r['set']['og'] === $f ? 'Seçilen' : 'Otomatik (sayfanın fotoğrafı)';
    if ($r['set']['og'] === '' && seo_is_icon($f)) $how = 'Otomatik (site ikonu)';
    return $how . ': ' . basename($f) . ($w ? ' · ' . $w . '×' . $h : '');
}
function seo_og_html(array $r): string {
    $auto = $r['og_auto'];
    $autoOk = $auto !== '' && is_file(DM_ROOT . '/' . $auto);
    [$aw, $ah] = $autoOk ? img_size($auto) : [0, 0];
    $cur = $r['og'];
    $src = fn(string $f) => thumb_url(is_file(DM_ROOT . '/' . img_small($f)) ? img_small($f) : $f);
    return '<div class="sd-sec sd-og" data-og data-auto="' . e($autoOk ? $auto : '') . '" data-auto-url="' . e($autoOk ? $src($auto) : '') . '" data-auto-wh="' . ($aw ? $aw . '×' . $ah : '') . '" data-auto-icon="' . ($autoOk && seo_is_icon($auto) ? '1' : '0') . '">'
        . '<span class="fl">Paylaşım görseli <span class="dim">Facebook, WhatsApp, LinkedIn…</span></span>'
        . '<input type="hidden" name="og" value="' . e($r['set']['og']) . '" data-og-val>'
        . '<div class="ogc"><div class="ogc-img" data-og-img>' . ($cur !== '' && is_file(DM_ROOT . '/' . $cur) ? '<img src="' . e($src($cur)) . '" alt="">' : '<span class="noimg">Görsel yok</span>') . '</div>'
        . '<div class="ogc-b"><small>' . e(strtoupper((string)preg_replace('#^https?://#', '', site_url()))) . '</small><b data-og-t>' . e($r['title']) . '</b><span data-og-d>' . e($r['desc']) . '</span></div></div>'
        . '<div class="ogc-info" data-og-info>' . e(seo_og_info($r)) . '</div>'
        . '<div class="ogc-act"><button type="button" class="btn sm" data-og-act="pick" aria-expanded="false">' . aic('image') . '<span>Siteden seç</span></button>'
        . '<label class="btn sm">' . aic('upload') . '<span>Yeni yükle</span><input type="file" accept="image/jpeg,image/png,image/webp" data-og-up hidden></label>'
        . '<button type="button" class="btn sm ghost" data-og-act="none">Görselsiz paylaş</button>'
        . '<button type="button" class="btn sm ghost" data-og-act="auto">Orijinale dön</button><span class="imgf-st" data-og-st aria-live="polite"></span></div>'
        . '<div class="ogpick" data-og-grid hidden></div>'
        . '<div class="help">En iyi boyut 1200×630 (yatay). Yeni yüklenen görsel bu boyuta kırpılır ve JPG olarak kaydedilir.</div></div>';
}
function seo_imgs_html(array $r): string {
    $c = C();
    $imgs = $r['imgs'];
    $o = '<div class="sd-sec"><span class="fl">Sayfadaki fotoğraflar <span class="dim">' . count($imgs) . '</span></span>';
    if (!$imgs) return $o . '<p class="muted small">Bu sayfada fotoğraf yok.</p></div>';
    $o .= '<p class="help">Dosya adı ve alternatif metin (alt) Google Görseller için önemlidir: fotoğrafta ne olduğunu İngilizce yazın. Dosya adı değişince fotoğraf yeni adla kopyalanır ve sitedeki her kullanımı güncellenir; eski dosya eski yedekler için kalır.</p><div class="seo-imgs">';
    foreach ($imgs as $j => $im) {
        $f = $im['file'];
        $refs = image_refs($c, $f);
        $ok = is_file(DM_ROOT . '/' . $f);
        [$w, $h] = $ok ? img_size($f) : [0, 0];
        $sm = img_small($f);
        $stored = $im['alt'];
        if ($refs) {
            $vals = array_map(fn($p) => (string)path_get($c, $p . '.alt', ''), $refs);
            $stored = in_array($im['alt'], $vals, true) ? $im['alt'] : $vals[0];
        }
        $o .= '<div class="si"><a class="si-img" href="../' . e($f) . '" target="_blank" rel="noopener" title="Fotoğrafı aç">' . ($ok ? '<img src="' . e(thumb_url(is_file(DM_ROOT . '/' . $sm) ? $sm : $f)) . '" alt="" loading="lazy">' : '') . '</a><div class="si-b">'
            . '<input type="hidden" name="altf[' . $j . ']" value="' . e($f) . '">';
        if (seo_can_rename($c, $f)) {
            $base = pathinfo($f, PATHINFO_FILENAME);
            $o .= '<label class="mini">Dosya adı<span class="fname"><input type="text" name="fname[' . $j . ']" value="' . e($base) . '" spellcheck="false" autocomplete="off" autocapitalize="off"><span>.' . e(pathinfo($f, PATHINFO_EXTENSION)) . '</span></span></label>'
                . '<input type="hidden" name="fname0[' . $j . ']" value="' . e($base) . '">';
        } else {
            $o .= '<div class="si-n">' . e(basename($f)) . '</div>';
        }
        if ($im['decor']) $o .= '<div class="dim small">Dekoratif arka plan: alternatif metin gerekmez.</div>';
        elseif ($refs) $o .= '<label class="mini">Alternatif metin<input type="text" name="alt[' . $j . ']" value="' . e($stored) . '"' . ($stored === '' && $im['alt'] !== '' ? ' placeholder="' . e($im['alt']) . '"' : '') . ' maxlength="250"></label>'
            . '<input type="hidden" name="alt0[' . $j . ']" value="' . e($stored) . '">';
        else $o .= '<div class="small"><span class="dim">Alternatif metin:</span> ' . e($im['alt'] !== '' ? $im['alt'] : '—') . ' <span class="dim">(otomatik)</span></div>';
        $info = [];
        if ($w) $info[] = $w . '×' . $h;
        if ($ok) $info[] = kb((int)filesize(DM_ROOT . '/' . $f));
        else $info[] = '<span class="warn">dosya bulunamadı</span>';
        if (count($refs) > 1) $info[] = count($refs) . ' yerde kullanılıyor, hepsi güncellenir';
        if ($im['badname']) $info[] = '<span class="warn">dosya adı açıklayıcı değil</span>';
        $o .= '<div class="dim small">' . implode(' · ', $info) . '</div></div></div>';
    }
    return $o . '</div></div>';
}
function seo_checks_html(array $r): string {
    $todo = array_values(array_filter($r['checks'], fn($x) => $x['lv'] !== 'good'));
    usort($todo, fn($a, $b) => ($a['lv'] === 'bad' ? 0 : 1) <=> ($b['lv'] === 'bad' ? 0 : 1));
    $good = array_values(array_filter($r['checks'], fn($x) => $x['lv'] === 'good'));
    $li = fn(array $x) => '<li><i class="sdot ' . $x['lv'] . '" aria-hidden="true"></i><span>' . e($x['msg']) . '</span></li>';
    $o = '<div class="sd-row"><span class="fl">SEO kontrolü</span><span class="sbadge ' . $r['status'] . '" data-seo-badge>' . e(seo_label($r['status'])) . '</span></div>';
    if ($r['noindex']) return $o . '<p class="muted small">Bu sayfa Google\'da listelenmediği için kontrol edilmez.</p>';
    return $o . '<details class="sc" data-sc="todo"' . ($todo ? ' open' : '') . '><summary>İyileştirmeler <b>' . count($todo) . '</b></summary>'
        . ($todo ? '<ul>' . implode('', array_map($li, $todo)) . '</ul>' : '<p class="muted small">İyileştirilecek bir şey yok.</p>') . '</details>'
        . '<details class="sc" data-sc="good"><summary>İyi sonuçlar <b>' . count($good) . '</b></summary><ul>' . implode('', array_map($li, $good)) . '</ul></details>';
}
function seo_modal_html(array $r, array $rows): string {
    $c = C();
    $paths = array_keys($rows);
    $i = (int)array_search($r['path'], $paths, true);
    $nav = function (string $to, string $dir) {
        $lbl = $dir === 'prev' ? 'Önceki sayfa' : 'Sonraki sayfa'; $ic = $dir === 'prev' ? '‹' : '›';
        return $to !== '' ? '<a class="ib" href="index.php?p=seo&amp;edit=' . e(rawurlencode($to)) . '" data-seo-open="' . e($to) . '" data-seo-nav="' . $dir . '" title="' . $lbl . '" aria-label="' . $lbl . '">' . $ic . '</a>'
                          : '<span class="ib off" data-seo-nav="' . $dir . '">' . $ic . '</span>';
    };
    $fixed = $r['kind'] === 'page';
    $fav = (string)($c['branding']['favicon_48'] ?? '');
    $crumb = $r['path'] === 'index.html' ? '' : ' › ' . $r['path'];
    $tShow = $r['set']['title'] !== '' ? $r['set']['title'] : $r['title'];
    $dShow = $r['set']['desc'] !== '' ? $r['set']['desc'] : $r['desc'];
    $how = match ($r['kind']) {
        'services' => ['Hizmetlerde değiştir', 'item&k=services&i=' . $r['i'], 'Adresi hizmetin sayfasından değiştirebilirsiniz; eski adres yeni sayfaya yönlenir.'],
        'projects' => ['Projelerde değiştir', 'item&k=projects&i=' . $r['i'], 'Adresi projenin sayfasından değiştirebilirsiniz; eski adres yeni sayfaya yönlenir.'],
        'cities' => ['Bölgelerde değiştir', 'item&k=cities&i=' . $r['i'], 'Adres şehir adından otomatik oluşur (plumber-şehir.html).'],
        default => ['', '', 'Bu sayfanın adresi sabittir.'],
    };
    $kpEx = $r['auto']['kp'] !== '' ? $r['auto']['kp'] : 'water heater installation toronto';
    $o = '<form method="post" action="index.php" class="seo-ed" data-path="' . e($r['path']) . '" novalidate>' . csrf_field()
        . '<input type="hidden" name="path" value="' . e($r['path']) . '">'
        . '<div class="sd-head"><span class="sst ' . $r['status'] . '" data-seo-st title="' . e(seo_label($r['status'])) . '"></span>'
        . '<div class="sd-ttl"><b>' . e($r['name']) . '</b><small>' . e($r['group']) . ' · ' . site_link($r['path'], 'Sayfayı aç', 'lnk') . ' · <a class="lnk" href="index.php?p=' . e($r['edit']) . '">Tüm yazıları</a></small></div>'
        . '<div class="sd-nav">' . $nav($paths[$i - 1] ?? '', 'prev') . $nav($paths[$i + 1] ?? '', 'next') . '<a class="ib" href="index.php?p=seo" data-seo-close title="Kapat" aria-label="Kapat">' . aic('x') . '</a></div></div>'
        . '<div class="sd-body"><div class="flash bad" data-seo-err hidden></div>'
        . ($r['noindex'] ? '<div class="flash warn">' . ic('alert') . '<div>Bu sayfa Google\'da listelenmez (noindex). Başlığı sadece tarayıcı sekmesinde görünür.</div></div>' : '')
        // keyphrase
        . '<div class="sd-sec"><label class="fl" for="sdKp">Odak anahtar kelime</label>'
        . '<input type="text" id="sdKp" name="kp" value="' . e($r['set']['kp']) . '" placeholder="' . e($r['auto']['kp'] !== '' ? $r['auto']['kp'] : 'ör. ' . $kpEx) . '" maxlength="100" autocomplete="off" spellcheck="false" data-orig="' . e($r['orig']['kp']) . '">'
        . '<div class="help">Bu sayfanın Google\'da bulunmasını istediğiniz arama, İngilizce.' . ($r['auto']['kp'] !== '' ? ' Boş bırakılırsa otomatik: <b>' . e($r['auto']['kp']) . '</b>.' : '') . ' Sadece bu kontrol ve öneriler için kullanılır; sitede görünmez.</div></div>'
        // Google preview
        . '<div class="sd-sec"><div class="sd-row"><span class="fl">Google önizlemesi</span><div class="seg sm" role="group" aria-label="Görünüm"><button type="button" class="on" data-seo-view="m" aria-pressed="true">Telefon</button><button type="button" data-seo-view="d" aria-pressed="false">Bilgisayar</button></div></div>'
        . '<div class="gp m" data-gp><div class="gp-site"><span class="gp-fav">' . ($fav !== '' && is_file(DM_ROOT . '/' . $fav) ? '<img src="' . e(thumb_url($fav)) . '" alt="">' : '') . '</span><span class="gp-s"><b>' . e(short_name()) . '</b><small>' . e(site_url() . $crumb) . '</small></span></div>'
        . '<div class="gp-main"><div class="gp-tx"><div class="gp-t" data-gp-t>' . e($tShow) . '</div><div class="gp-d" data-gp-d>' . e($dShow) . '</div></div>'
        . '<div class="gp-img" data-gp-img' . ($r['og'] === '' ? ' hidden' : '') . '>' . ($r['og'] !== '' ? '<img src="' . e(thumb_url(is_file(DM_ROOT . '/' . img_small($r['og'])) ? img_small($r['og']) : $r['og'])) . '" alt="">' : '') . '</div></div></div></div>'
        // title
        . '<div class="sd-sec"><div class="sd-row"><label class="fl" for="sdTitle">Google başlığı</label><button type="button" class="btn sm ghost" data-seo-suggest="title">' . aic('star') . '<span>Öner</span></button></div>'
        . '<input type="text" id="sdTitle" name="title" value="' . e($r['set']['title']) . '"' . (!$fixed ? ' placeholder="' . e($r['auto']['title']) . '"' : '') . ' maxlength="200" data-orig="' . e($r['orig']['title']) . '" data-meter-in="title">'
        . '<div class="meter ' . seo_meter_level('title', $tShow) . '" data-meter="title"><i style="width:' . min(100, (int)round(seo_px($tShow) / SEO_TITLE_PX * 100)) . '%"></i></div>'
        . '<div class="sd-meta"><span data-meter-txt="title">' . e(seo_meter_text('title', $tShow, $r['set']['title'] === '' && !$fixed)) . '</span><button type="button" class="link" data-seo-orig="title">' . ($fixed ? 'Orijinal başlığı kullan' : 'Otomatik başlığı kullan') . '</button></div>'
        . (!$fixed ? '<div class="help">Boş bırakılırsa otomatik başlık kullanılır (gri yazı).</div>' : '') . '</div>'
        // address
        . '<div class="sd-sec"><label class="fl" for="sdUrl">Sayfa adresi</label><div class="sd-url"><input type="text" id="sdUrl" value="' . e($r['url']) . '" readonly>'
        . ($how[0] !== '' ? '<a class="btn sm ghost" href="index.php?p=' . e($how[1]) . '">' . e($how[0]) . '</a>' : '') . '</div><div class="help">' . e($how[2]) . '</div></div>'
        // description
        . '<div class="sd-sec"><div class="sd-row"><label class="fl" for="sdDesc">Google açıklaması</label><button type="button" class="btn sm ghost" data-seo-suggest="desc">' . aic('star') . '<span>Öner</span></button></div>'
        . '<textarea id="sdDesc" name="desc" rows="3" maxlength="400"' . (!$fixed ? ' placeholder="' . e($r['auto']['desc']) . '"' : '') . ' data-orig="' . e($r['orig']['desc']) . '" data-meter-in="desc">' . e($r['set']['desc']) . '</textarea>'
        . '<div class="meter ' . seo_meter_level('desc', $dShow) . '" data-meter="desc"><i style="width:' . min(100, (int)round(mb_strlen($dShow) / SEO_DESC_MAX * 100)) . '%"></i></div>'
        . '<div class="sd-meta"><span data-meter-txt="desc">' . e(seo_meter_text('desc', $dShow, $r['set']['desc'] === '' && !$fixed)) . '</span><button type="button" class="link" data-seo-orig="desc">' . ($fixed ? 'Orijinal açıklamayı kullan' : 'Otomatik açıklamayı kullan') . '</button></div></div>'
        . seo_og_html($r)
        . seo_imgs_html($r)
        . '<div class="sd-sec sd-check" data-seo-check aria-live="polite">' . seo_checks_html($r) . '</div>'
        . '</div><div class="sd-foot"><button type="button" class="btn ghost" data-seo-reset title="Anahtar kelime, başlık, açıklama ve paylaşım görseli ilk hallerine döner (kaydedince geçerli olur)">' . aic('undo') . '<span>Orijinallere dön</span></button>'
        . '<span class="sd-dirty">' . ic('alert') . '<span>Kaydedilmedi</span></span>'
        . '<div class="sd-fa"><a class="btn" href="index.php?p=seo" data-seo-close>Kapat</a><button type="submit" class="btn primary" name="a" value="seo_save" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet</span></button></div></div></form>';
    return $o;
}
function seo_meter_level(string $what, string $v): string {
    $n = mb_strlen($v);
    if ($n === 0) return 'bad';
    if ($what === 'title') return seo_px($v) > SEO_TITLE_PX || $n < SEO_TITLE_MIN ? 'ok' : 'good';
    return $n < SEO_DESC_MIN || $n > SEO_DESC_MAX ? 'ok' : 'good';
}
/** GET p=seo_edit: the editor window for one page (loaded into the list page by admin.js). */
function seo_edit_fragment(string $path): never {
    $rows = seo_report()['rows'];
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($rows[$path])) { http_response_code(404); echo '<p class="seo-gone">Bu sayfa bulunamadı (silinmiş veya adresi değişmiş olabilir).</p>'; exit; }
    echo seo_modal_html($rows[$path], $rows);
    exit;
}
/** GET p=seo_media: photos that can be used as a sharing image (this page's first). */
function seo_media(string $path): never {
    $files = [];
    $rows = seo_report()['rows'];
    $ok = fn(string $f) => (bool)preg_match('#^assets/(photos|uploads/(photos|projects|og))/[^/]+\.(webp|jpe?g|png)$#i', $f) && !str_ends_with($f, '-sm.webp');
    foreach ($rows[$path]['imgs'] ?? [] as $im) if ($ok($im['file'])) $files[$im['file']] = true;
    foreach (['assets/uploads/og', 'assets/uploads/projects', 'assets/uploads/photos', 'assets/photos'] as $dir) {
        $list = [];
        if (!is_dir(DM_ROOT . '/' . $dir)) continue;
        foreach (scandir(DM_ROOT . '/' . $dir) ?: [] as $n) if ($n[0] !== '.' && $ok($dir . '/' . $n) && is_file(DM_ROOT . '/' . $dir . '/' . $n)) $list[$dir . '/' . $n] = (int)filemtime(DM_ROOT . '/' . $dir . '/' . $n);
        arsort($list);
        foreach ($list as $f => $_) $files[$f] = true;
    }
    $o = [];
    foreach (array_keys($files) as $f) {
        if (!is_file(DM_ROOT . '/' . $f)) continue;
        [$w, $h] = img_size($f);
        if ($w < 200) continue;
        $sm = img_small($f);
        $o[] = ['file' => $f, 'url' => thumb_url(is_file(DM_ROOT . '/' . $sm) ? $sm : $f), 'name' => basename($f), 'w' => $w, 'h' => $h];
        if (count($o) >= 400) break;
    }
    json_out(['ok' => true, 'items' => $o]);
}

/* ======================= saving ======================= */
function seo_can_rename(array $c, string $f): bool {
    return (bool)preg_match('#^assets/(photos|uploads/[a-z]+)/[A-Za-z0-9_\-.]+\.(webp|png|jpe?g)$#', $f) && image_refs($c, $f) !== [];
}
function seo_og_value(string $v, array &$err): string {
    $v = trim($v);
    if ($v === '' || $v === 'none') return $v;
    $f = clean_file($v);
    if ($f === '' || !og_file_ok($f)) { $err[] = 'Seçilen paylaşım görseli bulunamadı; başka bir görsel seçin.'; return ''; }
    return $f;
}
/** Apply the editor's fields to a copy of the content. $strict: saving (empty fixed titles and alt texts are refused). */
function seo_apply(array $c, array $e, array $in, array &$err, bool $strict): array {
    $b = $e['base'];
    $kp = mb_substr(one_line((string)($in['kp'] ?? '')), 0, 100);
    $t = one_line((string)($in['title'] ?? '')); $d = one_line((string)($in['desc'] ?? ''));
    if ($strict && $e['kind'] === 'page' && $t === '') $err[] = 'Bu sayfanın Google başlığı boş bırakılamaz.';
    if ($strict && $e['kind'] === 'page' && $d === '') $err[] = 'Bu sayfanın Google açıklaması boş bırakılamaz.';
    if (mb_strlen($t) > 200) $err[] = 'Google başlığı çok uzun (en fazla 200 karakter).';
    if (mb_strlen($d) > 400) $err[] = 'Google açıklaması çok uzun (en fazla 400 karakter).';
    path_set($c, $b . '.seo_keyphrase', $kp);
    path_set($c, $b . '.seo_title', $t);
    path_set($c, $b . '.seo_description', $d);
    path_set($c, $b . '.og_image', seo_og_value((string)($in['og'] ?? ''), $err));
    $files = is_array($in['altf'] ?? null) ? $in['altf'] : [];
    $was = is_array($in['alt0'] ?? null) ? $in['alt0'] : [];
    foreach (is_array($in['alt'] ?? null) ? $in['alt'] : [] as $j => $alt) {
        $f = clean_file($files[$j] ?? '');
        $alt = mb_substr(one_line((string)$alt), 0, 250);
        if ($f === '' || $alt === one_line((string)($was[$j] ?? ''))) continue;        // only what was changed
        $refs = image_refs($c, $f);
        if ($alt === '' && $strict) foreach ($refs as $p) if (!preg_match('/^projects\.\d+\.photos\.\d+$/', $p)) { $err[] = basename($f) . ': alternatif metin boş bırakılamaz.'; continue 2; }
        foreach ($refs as $p) path_set($c, $p . '.alt', $alt);
    }
    return $c;
}
/** Photo renames asked for in the editor: file => new name ('' = not a usable name). */
function seo_renames(array $c, array $in): array {
    $o = [];
    $files = is_array($in['altf'] ?? null) ? $in['altf'] : [];
    $was = is_array($in['fname0'] ?? null) ? $in['fname0'] : [];
    foreach (is_array($in['fname'] ?? null) ? $in['fname'] : [] as $j => $name) {
        $f = clean_file($files[$j] ?? '');
        if ($f === '' || !seo_can_rename($c, $f)) continue;
        $slug = substr(slugify((string)preg_replace('/\.(webp|png|jpe?g)$/i', '', trim((string)$name))), 0, 80);
        if ($slug !== '' && $slug === slugify((string)($was[$j] ?? ''))) continue;
        if ($slug === '' && trim((string)$name) === trim((string)($was[$j] ?? ''))) continue;
        $o[$f] = $slug;
    }
    return $o;
}
function seo_entry(string $path): ?array { return seo_catalog(C())[$path] ?? null; }
function seo_fail(string $msg, string $path, int $code = 422): never {
    if (wants_json()) json_out(['ok' => false, 'error' => $msg], $code);
    redirect($path !== '' ? ['p' => 'seo', 'edit' => $path] : ['p' => 'seo'], e($msg), 'bad');
}
/** POST a=seo_save: the editor window (JSON for admin.js, redirect without JavaScript) and the logos card. */
function act_seo_save(): never {
    $path = (string)($_POST['path'] ?? '');
    if ($path === '_site') {
        $c = C();
        foreach (['logo_alt' => 'branding.header_logo_alt', 'flogo_alt' => 'branding.footer_logo_alt'] as $in => $p)
            if (isset($_POST[$in]) && one_line((string)$_POST[$in]) !== '') path_set($c, $p, one_line((string)$_POST[$in]));
        $rep = content_save($c, 'SEO: logolar');
        redirect(['p' => 'seo'], save_msg($rep));
    }
    $e = seo_entry($path);
    if (!$e) seo_fail('Bu sayfa bulunamadı (silinmiş veya adresi değişmiş olabilir). Listeyi yenileyin.', '', 404);
    $err = [];
    $c = seo_apply(C(), $e, $_POST, $err, true);
    $ren = seo_renames($c, $_POST);
    foreach ($ren as $f => $slug) if ($slug === '') $err[] = basename($f) . ': yeni dosya adı geçersiz. Harf, rakam ve tire kullanın.';
    if ($err) seo_fail(implode(' ', array_unique($err)), $path);
    $names = [];
    if ($ren) with_lock(function () use (&$c, $ren, &$names) {
        foreach ($ren as $f => $slug) {
            $new = image_rename($f, $slug);
            if ($new !== $f) { $c = replace_file_refs($c, $f, $new); $names[] = basename($new); }
        }
    });
    $rep = content_save($c, 'SEO: ' . $e['name']);
    unset($GLOBALS['DM_SEO']);
    $msg = save_msg($rep) . ($names ? ' Yeni dosya adı: <b>' . e(implode(', ', $names)) . '</b>.' : '');
    if (!wants_json()) redirect(['p' => 'seo', 'edit' => $path], $msg);
    $rows = seo_report()['rows'];
    json_out(['ok' => true, 'msg' => $msg, 'note' => trim(html_entity_decode(strip_tags(str_replace('Siteyi görüntüle', '', $msg)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
              'html' => isset($rows[$path]) ? seo_modal_html($rows[$path], $rows) : '', 'list' => seo_list_html($rows), 'chips' => seo_chips_html($rows, '')]);
}
/** POST a=seo_check: the SEO check for the editor's unsaved values (nothing is saved). */
function act_seo_check(): never {
    $path = (string)($_POST['path'] ?? '');
    $e = seo_entry($path);
    if (!$e) json_out(['ok' => false, 'error' => 'Sayfa bulunamadı.'], 404);
    $err = [];
    $c = seo_apply(C(), $e, $_POST, $err, false);
    $pending = array_filter(seo_renames($c, $_POST), 'strlen');
    $r = seo_report($c, $pending)['rows'][$path] ?? null;
    if (!$r) json_out(['ok' => false, 'error' => 'Sayfa bulunamadı.'], 404);
    json_out(['ok' => true, 'status' => $r['status'], 'label' => seo_label($r['status']), 'html' => seo_checks_html($r), 'title' => $r['title'], 'desc' => $r['desc']]);
}
/** POST a=seo_suggest: a title or description for the keyphrase that is in the editor right now. */
function act_seo_suggest(): never {
    $path = (string)($_POST['path'] ?? '');
    $r = seo_report()['rows'][$path] ?? null;
    if (!$r) json_out(['ok' => false, 'error' => 'Sayfa bulunamadı.'], 404);
    $kp = one_line((string)($_POST['kp'] ?? ''));
    if ($kp === '') $kp = $r['auto']['kp'];
    $text = ($_POST['what'] ?? '') === 'desc' ? seo_suggest_desc($r, $kp) : seo_suggest_title($r, $kp);
    json_out(['ok' => true, 'text' => $text, 'px' => seo_px($text), 'len' => mb_strlen($text)]);
}
