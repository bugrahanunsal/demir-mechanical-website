<?php
/**
 * Galeri (İçerik → Galeri): every photo of the website in one place, wherever it was uploaded (services, areas,
 * projects, home page, founder, logos, sharing images) plus the site's own photos and the photos added to the gallery
 * itself ("Fotoğraf ekle"). The page is a grid of photos; a click shows the photo's details in a panel on the right
 * (on phones: a sheet over the page): file name and alternative text (both editable), size, where it is used, delete.
 * Photos added in the gallery are kept in content.library ({file, alt, added}) until a section uses them
 * ("Galeriden seç" in every photo field). Everything else is read from the content and the photo folders each time.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const MEDIA_DIRS = ['assets/uploads/photos', 'assets/uploads/projects', 'assets/uploads/og', 'assets/photos', 'assets/uploads/logos', 'assets/uploads/suppliers', 'assets/uploads/icons'];
const MEDIA_RE = '#^assets/[A-Za-z0-9/_\-.]+\.(webp|png|jpe?g|gif|svg)$#i';

/** Every place in the content that uses an image file: file => [content paths]. (The gallery's own list is not a use.) */
function media_refs(array $c): array {
    $o = [];
    $walk = function ($v, string $p) use (&$walk, &$o) {
        if (!is_array($v)) { if (is_string($v) && preg_match(MEDIA_RE, $v)) $o[$v][] = $p; return; }
        if (isset($v['file']) && is_string($v['file']) && array_key_exists('alt', $v)) { if ($v['file'] !== '') $o[$v['file']][] = $p; return; }
        foreach ($v as $k => $x) $walk($x, $p . '.' . $k);
    };
    foreach ($c as $k => $v) if (!in_array($k, ['redirects', 'custom', 'library'], true)) $walk($v, (string)$k);
    return $o;
}
/** Photos added in the gallery: file => index in content.library. */
function media_lib(array $c): array {
    $o = [];
    foreach (($c['library'] ?? []) as $i => $x) if (is_array($x) && is_string($x['file'] ?? null) && $x['file'] !== '') $o[$x['file']] = $i;
    return $o;
}
/** Where a content path is on the site, in words: [label, editor (p=…), page]. $cat: seo_catalog() by base path. */
function media_place(array $c, string $p, array $cat): array {
    $m = [];
    if (preg_match('/^(services|cities)\.(\d+)\.image$/', $p, $m)) {
        $x = $c[$m[1]][(int)$m[2]] ?? [];
        return [($m[1] === 'services' ? 'Hizmet: ' : 'Bölge: ') . ($x['name'] ?? ''), 'item&k=' . $m[1] . '&i=' . $m[2], ($x['slug'] ?? '') . '.html'];
    }
    if (preg_match('/^projects\.(\d+)\.photos\.(\d+)$/', $p, $m)) {
        $x = $c['projects'][(int)$m[1]] ?? [];
        $n = (int)$m[2];
        return ['Proje: ' . ($x['title'] ?? '') . ' · ' . ($n + 1) . '. fotoğraf' . ($n === 0 ? ' (kapak)' : ''), 'item&k=projects&i=' . $m[1], !empty($x['published']) && ($x['slug'] ?? '') !== '' ? 'project-' . $x['slug'] . '.html' : ''];
    }
    if (preg_match('/^(.+)\.og_image$/', $p, $m) && isset($cat[$m[1]])) return ['Paylaşım görseli: ' . $cat[$m[1]]['name'], 'seo&edit=' . $cat[$m[1]]['path'], $cat[$m[1]]['path']];
    if (preg_match('/^pages\.home\.work\.(\d+)\.image$/', $p, $m)) return ['Ana sayfa: iş fotoğrafları bandı (' . ((int)$m[1] + 1) . ')', 'sec&s=home', 'index.html'];
    if (preg_match('/^pages\.services\.work\.(\d+)\.image$/', $p, $m)) return ['Hizmetler sayfası: iş fotoğrafları bandı (' . ((int)$m[1] + 1) . ')', 'sec&s=servicepage', 'services.html'];
    if (preg_match('/^suppliers\.(\d+)\.logo$/', $p, $m)) return ['Tedarikçi logosu: ' . ($c['suppliers'][(int)$m[1]]['name'] ?? ''), 'sec&s=suppliers', 'index.html'];
    $fixed = [
        'pages.home.hero_image' => ['Ana sayfa: giriş bölümünün arka planı', 'sec&s=home', 'index.html'],
        'pages.home.about_image' => ['Ana sayfa: "Biz kimiz" fotoğrafı', 'sec&s=home', 'index.html'],
        'pages.about.image' => ['Hakkımızda: fotoğraf', 'sec&s=about', 'about.html'],
        'founder.photo' => ['Kurucu portresi', 'sec&s=founder', 'about.html'],
        'page_header_image' => ['İç sayfa başlıklarının arka planı', 'sec&s=branding', 'services.html'],
        'branding.header_logo_png' => ['Header logosu', 'sec&s=branding', 'index.html'],
        'branding.footer_logo' => ['Footer logosu', 'sec&s=branding', 'index.html'],
        'branding.favicon_32' => ['Site ikonu (32 px)', 'sec&s=branding', ''],
        'branding.favicon_48' => ['Site ikonu (48 px)', 'sec&s=branding', ''],
        'branding.apple_touch_icon' => ['Site ikonu (telefon ana ekranı)', 'sec&s=branding', ''],
        'branding.watermark' => ['Fotoğraf yokken görünen logo izi', '', ''],
        'site.og_image' => ['Varsayılan paylaşım görseli', 'sec&s=branding', ''],
        'site.schema_image' => ['Google işletme bilgisi görseli', 'sec&s=branding', ''],
    ];
    return $fixed[$p] ?? [$p, '', ''];
}
/** One use of a photo: where, and the alternative text that belongs to it (null = this use has none). */
function media_usage(array $c, string $p, array $cat): array {
    [$label, $edit, $page] = media_place($c, $p, $cat);
    $v = path_get($c, $p);
    $alt = null; $altp = ''; $auto = '';
    $gallery = (bool)preg_match('/^projects\.(\d+)\.photos\.(\d+)$/', $p, $m);
    if (is_array($v) && array_key_exists('alt', $v) && $p !== 'page_header_image') {
        $alt = trim((string)$v['alt']); $altp = $p . '.alt';
        if ($gallery && $alt === '') $auto = (string)($c['projects'][(int)$m[1]]['title'] ?? '') . ((int)$m[2] > 0 ? ' – photo ' . ((int)$m[2] + 1) : '');
    } elseif ($p === 'branding.header_logo_png') { $alt = (string)($c['branding']['header_logo_alt'] ?? ''); $altp = 'branding.header_logo_alt'; }
    elseif ($p === 'branding.footer_logo') { $alt = (string)($c['branding']['footer_logo_alt'] ?? ''); $altp = 'branding.footer_logo_alt'; }
    elseif (preg_match('/^suppliers\.(\d+)\.logo$/', $p, $s)) $auto = (string)($c['suppliers'][(int)$s[1]]['name'] ?? '') . ' logo';
    return ['path' => $p, 'label' => $label, 'edit' => $edit, 'page' => $page, 'alt' => $alt, 'altp' => $altp, 'auto' => $auto, 'gallery' => $gallery, 'decor' => $p === 'page_header_image'];
}
function media_cat(string $f): string {
    if (str_starts_with($f, 'assets/uploads/og/')) return 'og';
    return preg_match('#^assets/(photos|uploads/(photos|projects))/#', $f) ? 'photo' : 'logo';
}
/**
 * Every image: the ones the content uses, the gallery's own photos and every file in the photo folders (newest first).
 * shown = listed under "Tümü" (used somewhere, added to the gallery, or a logo); old copies that nothing uses any more
 * (replaced or renamed photos) appear only under "Kullanılmayan".
 */
function media_items(array $c): array {
    $refs = media_refs($c);
    $lib = media_lib($c);
    $cat = [];                                              // page names for the "used on" list
    foreach (seo_catalog($c) as $e) $cat[$e['base']] = $e;
    $files = array_fill_keys(array_keys($refs), true) + array_fill_keys(array_keys($lib), true);
    foreach (MEDIA_DIRS as $dir) {
        if (!is_dir(DM_ROOT . '/' . $dir)) continue;
        foreach (scandir(DM_ROOT . '/' . $dir) ?: [] as $n) {
            if ($n[0] === '.' || str_ends_with($n, '-sm.webp') || !preg_match('/\.(webp|png|jpe?g|gif|svg)$/i', $n) || !is_file(DM_ROOT . '/' . $dir . '/' . $n)) continue;
            if (str_ends_with($n, '.webp') && is_file(DM_ROOT . '/' . $dir . '/' . substr($n, 0, -5) . '.png') && !str_starts_with($dir, 'assets/photos')) continue;   // logo's WebP twin
            $files[$dir . '/' . $n] = true;
        }
    }
    $o = [];
    foreach (array_keys($files) as $f) {
        $ps = array_values(array_filter($refs[$f] ?? [], fn($p) => $p !== 'branding.header_logo_webp'));
        if (!$ps && isset($refs[$f]) && !isset($lib[$f])) continue;                     // only the header logo's WebP copy
        $uses = array_map(fn($p) => media_usage($c, $p, $cat), $ps);
        $ok = is_file(DM_ROOT . '/' . $f);
        [$w, $h] = $ok ? img_size($f) : [0, 0];
        $inLib = isset($lib[$f]);
        $it = ['file' => $f, 'name' => basename($f), 'cat' => media_cat($f), 'uses' => $uses, 'exists' => $ok, 'w' => $w, 'h' => $h,
               'size' => $ok ? (int)filesize(DM_ROOT . '/' . $f) : 0, 'time' => $ok ? (int)filemtime(DM_ROOT . '/' . $f) : 0,
               'lib' => $inLib, 'lib_alt' => $inLib ? trim((string)($c['library'][$lib[$f]]['alt'] ?? '')) : ''];
        $alts = array_values(array_filter($uses, fn($u) => $u['altp'] !== ''));
        $it['alts'] = $alts;
        $it['unused'] = !$uses;
        $it['shown'] = $uses || $inLib || $it['cat'] === 'logo';
        $it['noalt'] = $alts ? (bool)array_filter($alts, fn($u) => $u['alt'] === '' && !$u['gallery']) : ($inLib && $it['cat'] === 'photo' && $it['lib_alt'] === '');
        [$it['alt'], $it['alt_how']] = media_alt_text($it);
        $o[$f] = $it;
    }
    $rank = ['photo' => 0, 'og' => 1, 'logo' => 2];        // photos first; in each group the newest uploads first
    $up = fn(array $x) => str_starts_with($x['file'], 'assets/uploads/') ? 0 : 1;
    uasort($o, fn($a, $b) => [$rank[$a['cat']], $up($a), $b['time'] * (1 - $up($b)), $a['name']] <=> [$rank[$b['cat']], $up($b), $a['time'] * (1 - $up($a)), $b['name']]);
    return $o;
}
/** The best alternative text known for a photo: [text, how] (how: '' real, auto, missing, none). */
function media_alt_text(array $it): array {
    foreach ($it['alts'] as $u) {
        if ($u['alt'] !== '') return [$u['alt'], ''];
        if ($u['auto'] !== '') return [$u['auto'], 'auto'];
    }
    if ($it['lib_alt'] !== '') return [$it['lib_alt'], ''];
    if ($it['alts'] || ($it['lib'] && $it['cat'] === 'photo')) return ['', 'missing'];
    foreach ($it['uses'] as $u) if ($u['auto'] !== '') return [$u['auto'], 'auto'];
    if ($it['uses'] && $it['uses'][0]['decor']) return ['Dekoratif arka plan: alternatif metin gerekmez', 'none'];
    if ($it['cat'] === 'og') return ['Paylaşım görseli: alternatif metin gerekmez', 'none'];
    return ['', 'none'];
}
/** The alternative text can be written here: for every use that has one, or kept in the gallery until the photo is used. */
function media_alt_editable(array $it): bool { return (bool)$it['alts'] || ($it['unused'] && $it['cat'] === 'photo' && $it['exists']); }
function media_can_rename(array $it): bool {
    return $it['exists'] && ($it['uses'] || $it['lib']) && (bool)preg_match('#^assets/(photos|uploads/(photos|projects|og))/[A-Za-z0-9_\-.]+\.(webp|png|jpe?g)$#', $it['file']);
}
/** Only uploads that the site does not use can be deleted (the site's own photos come with the code). */
function media_can_delete(array $it): bool {
    return $it['unused'] && str_starts_with($it['file'], DM_UPLOADS . '/') && (bool)preg_match('#^assets/uploads/(photos|projects|og)/[A-Za-z0-9_\-.]+\.(webp|png|jpe?g)$#', $it['file']);
}
/** Does an older backup still point to this file? (Restoring it would then miss the photo.) */
function media_in_backups(string $file): bool {
    static $all = null;
    if ($all === null) {
        $all = '';
        foreach (glob(DM_BACKUPS . '/content-*.json') ?: [] as $b) $all .= str_replace('\\/', '/', (string)file_get_contents($b)) . "\n";
    }
    return str_contains($all, '"' . $file . '"');
}
function media_thumb(array $it): string {
    if (!$it['exists']) return '';
    $sm = img_small($it['file']);
    return thumb_url(is_file(DM_ROOT . '/' . $sm) ? $sm : $it['file']);
}
function media_dark(array $it): bool { return $it['cat'] === 'logo' && (bool)preg_match('/(light|white)/i', $it['name']); }

/* ---------------- page ---------------- */
function media_filters(): array {
    return ['' => 'Tümü', 'photo' => 'Fotoğraflar', 'og' => 'Paylaşım görselleri', 'logo' => 'Logolar & ikonlar', 'noalt' => 'Alt metni eksik', 'unused' => 'Kullanılmayan'];
}
function media_match(array $it, string $f): bool {
    return match ($f) { 'photo', 'og', 'logo' => $it['shown'] && $it['cat'] === $f, 'noalt' => $it['shown'] && $it['noalt'], 'unused' => $it['unused'], default => $it['shown'] };
}
function media_chips_html(array $items, string $filter): string {
    $o = '';
    foreach (media_filters() as $f => $l) {
        $n = count(array_filter($items, fn($it) => media_match($it, (string)$f)));
        if ($f !== '' && $n === 0 && $f !== $filter) continue;
        $o .= '<a href="index.php?p=media' . ($f !== '' ? '&amp;f=' . $f : '') . '" data-f="' . $f . '"' . ((string)$f === $filter ? ' class="on" aria-current="true"' : '') . '>' . e($l) . ' <b>' . $n . '</b></a>';
    }
    return $o;
}
/** One photo in the grid: the picture only (name on hover), small marks for a missing alt text or an unused photo. */
function media_tile(array $it, string $filter = '', string $sel = ''): string {
    $q = mb_strtolower($it['name'] . ' ' . $it['alt'] . ' ' . implode(' ', array_column($it['uses'], 'label')) . ' ' . implode(' ', array_map(fn($u) => (string)$u['alt'], $it['uses'])));
    $src = media_thumb($it);
    $marks = ($it['noalt'] ? '<span class="mk warn" title="Alternatif metin yok">Alt yok</span>' : '')
        . ($it['unused'] ? '<span class="mk" title="Sitede kullanılmıyor">Kullanılmıyor</span>' : '')
        . (!$it['exists'] ? '<span class="mk warn">Dosya yok</span>' : '');
    $tip = $it['name'] . ($it['alt'] !== '' && $it['alt_how'] !== 'none' ? ' — ' . $it['alt'] : '');
    return '<a class="mtile ' . $it['cat'] . (media_dark($it) ? ' dark' : '') . ($it['file'] === $sel ? ' on' : '') . '" href="index.php?p=media&amp;open=' . e(rawurlencode($it['file'])) . '"'
        . ' data-media-open="' . e($it['file']) . '" data-cat="' . $it['cat'] . '" data-sh="' . ($it['shown'] ? '1' : '0') . '" data-un="' . ($it['unused'] ? '1' : '0') . '" data-na="' . ($it['noalt'] && $it['shown'] ? '1' : '0') . '"'
        . ' data-q="' . e($q) . '" title="' . e($tip) . '"' . ($it['file'] === $sel ? ' aria-current="true"' : '') . (media_match($it, $filter) ? '' : ' hidden') . '>'
        . '<span class="mt-img">' . ($src !== '' ? '<img src="' . e($src) . '" alt="" loading="lazy" decoding="async">' : aic('image')) . '</span>'
        . ($marks !== '' ? '<span class="mt-mk">' . $marks . '</span>' : '')
        . '<span class="mt-name">' . e($it['name']) . '</span><span class="mt-on" aria-hidden="true">' . ic('check') . '</span></a>';
}
function media_grid_html(array $items, string $filter = '', string $sel = ''): string {
    $o = '';
    foreach ($items as $it) $o .= media_tile($it, $filter, $sel);
    return $o . '<p class="seo-none card muted" hidden>Bu filtreye veya aramaya uyan fotoğraf yok.</p>';
}
function view_media(string $filter, string $open): string {
    $items = media_items(C());
    $filter = array_key_exists($filter, media_filters()) ? $filter : '';
    $sel = ($open !== '' && isset($items[$open])) ? $open : '';
    $add = '<label class="btn primary madd">' . aic('upload') . '<span>Fotoğraf ekle</span><input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden data-media-add></label>';
    return '<div id="mediaApp" class="media-app" data-filter="' . e($filter) . '">'
        . head_block('Galeri', 'Sitedeki bütün fotoğraflar. Bir fotoğrafa tıklayınca dosya adı ve alternatif metni (alt) sağda açılır; oradan değiştirebilirsiniz. Yeni fotoğrafları "Fotoğraf ekle" ile yükleyin (ya da bu sayfaya sürükleyip bırakın), sonra bölümlerdeki "Galeriden seç" ile kullanın.', $add)
        . '<div class="seo-bar"><label class="seo-search">' . aic('search') . '<span class="sr">Fotoğraf ara</span><input type="search" id="mediaSearch" placeholder="Dosya adı, alt metin veya sayfa ara…" autocomplete="off" spellcheck="false"></label>'
        . '<nav class="chips" id="mediaChips" aria-label="Filtre">' . media_chips_html($items, $filter) . '</nav></div>'
        . '<div class="mlay"><div class="mmain"><div class="mup" id="mediaUp" hidden aria-live="polite"></div>'
        . '<div class="mgrid" id="mediaGrid">' . media_grid_html($items, $filter, $sel) . '</div></div>'
        . '<aside class="mpane' . ($sel !== '' ? ' open' : '') . '" id="mediaPane" aria-label="Fotoğraf bilgileri">' . ($sel !== '' ? media_pane_html($items[$sel]) : media_pane_empty($items)) . '</aside></div>'
        . '<template id="mediaEmpty">' . media_pane_empty($items) . '</template>'
        . '<div class="mdim" data-media-close></div>'
        . '<div class="mdrop" aria-hidden="true"><div>' . aic('upload') . '<b>Fotoğrafları bırakın</b><span>Galeriye eklenir</span></div></div></div>';
}
/** The right-hand panel when no photo is selected. */
function media_pane_empty(array $items): string {
    $shown = array_filter($items, fn($it) => $it['shown']);
    $photos = count(array_filter($shown, fn($it) => $it['cat'] === 'photo'));
    $na = count(array_filter($shown, fn($it) => $it['noalt']));
    return '<div class="mp-empty">' . aic('image') . '<b>Bir fotoğraf seçin</b><p>Dosya adını ve alternatif metnini görmek ve değiştirmek için soldaki fotoğraflardan birine tıklayın.</p>'
        . '<ul><li><span>Fotoğraf</span><b>' . $photos . '</b></li><li><span>Logo ve ikon</span><b>' . count(array_filter($shown, fn($it) => $it['cat'] === 'logo')) . '</b></li>'
        . '<li><span>Alt metni eksik</span>' . ($na ? '<a href="index.php?p=media&amp;f=noalt" data-f-go="noalt"><b class="warn">' . $na . '</b></a>' : '<b>0</b>') . '</li></ul>'
        . '<p class="muted small">Yeni fotoğraf eklemek için üstteki "Fotoğraf ekle" düğmesini kullanın ya da fotoğrafları bu sayfaya sürükleyip bırakın.</p></div>';
}

/* ---------------- one photo (the panel) ---------------- */
function media_pane_html(array $it): string {
    $f = $it['file'];
    $catName = ['photo' => 'Fotoğraf', 'og' => 'Paylaşım görseli', 'logo' => 'Logo / ikon'][$it['cat']];
    $where = str_starts_with($f, 'assets/uploads/') ? ($it['lib'] && $it['unused'] ? 'Galeriye eklendi' : 'Panelden yüklendi') : 'Sitenin kendi dosyası';
    $o = '<form method="post" action="index.php" class="seo-ed media-ed" data-file="' . e($f) . '" novalidate>' . csrf_field()
        . '<input type="hidden" name="file" value="' . e($f) . '">'
        . '<div class="sd-head"><div class="sd-ttl" tabindex="-1"><b>' . e($it['name']) . '</b><small>' . e($catName) . ' · ' . e($where) . '</small></div>'
        . '<div class="sd-nav"><span class="ib off" data-media-nav="prev">‹</span><span class="ib off" data-media-nav="next">›</span><a class="ib" href="index.php?p=media" data-media-close title="Kapat" aria-label="Kapat">' . aic('x') . '</a></div></div>'
        . '<div class="sd-body"><div class="flash bad" data-media-err hidden></div>';
    $o .= '<div class="sd-sec"><a class="mprev' . (media_dark($it) ? ' dark' : '') . '" href="../' . e($f) . '" target="_blank" rel="noopener" title="Tam boyutta aç">'
        . ($it['exists'] ? '<img src="' . e(thumb_url($f)) . '" alt="">' : '<span class="noimg">Dosya bulunamadı</span>') . '</a>'
        . '<div class="mfacts">' . ($it['w'] ? '<span>' . $it['w'] . '×' . $it['h'] . ' px</span>' : '') . ($it['size'] ? '<span>' . kb($it['size']) . '</span>' : '')
        . ($it['time'] ? '<span>' . e(tr_date($it['time'])) . '</span>' : '') . '<a class="lnk" href="../' . e($f) . '" target="_blank" rel="noopener">' . aic('ext') . 'Tam boyut</a></div></div>';
    // file name
    $o .= '<div class="sd-sec"><label class="fl" for="mdName">Dosya adı</label>';
    if (media_can_rename($it)) {
        $base = pathinfo($f, PATHINFO_FILENAME);
        $o .= '<span class="fname"><input type="text" id="mdName" name="fname" value="' . e($base) . '" spellcheck="false" autocomplete="off" autocapitalize="off"><span>.' . e(pathinfo($f, PATHINFO_EXTENSION)) . '</span></span>'
            . '<input type="hidden" name="fname0" value="' . e($base) . '">'
            . '<div class="help">Fotoğrafı anlatan İngilizce kelimeler (ör. copper-riser-north-york): Google Görseller bu adı okur. Ad değişince sitedeki her kullanımı güncellenir.</div>';
    } else {
        $why = $it['unused'] ? 'Adını değiştirmek için önce alternatif metnini yazıp kaydedin (fotoğraf galeriye alınır).' : ($it['cat'] === 'logo' ? 'Logo ve ikon dosyaları Logo & Görseller (veya Tedarikçiler) bölümünden yeni dosya yüklenerek değişir.' : 'Bu dosyanın adı buradan değiştirilemez.');
        if (!$it['exists']) $why = 'Dosya sunucuda bulunamadı.';
        $o .= '<div class="mro">' . e($it['name']) . '</div><div class="help">' . e($why) . '</div>';
    }
    $o .= '</div>';
    // alternative text
    $o .= '<div class="sd-sec"><label class="fl" for="mdAlt">Alternatif metin (alt)</label>';
    if (media_alt_editable($it)) {
        $vals = $it['alts'] ? array_values(array_unique(array_map(fn($u) => $u['alt'], $it['alts']))) : [$it['lib_alt']];
        $cur = $vals[0];
        $ph = $cur === '' ? (array_values(array_filter(array_column($it['alts'], 'auto')))[0] ?? '') : '';
        $o .= '<textarea id="mdAlt" name="alt" rows="3" maxlength="250"' . ($ph !== '' ? ' placeholder="' . e($ph) . '"' : ' placeholder="Örn. Plumber replacing a copper riser in a North York condo"') . '>' . e($cur) . '</textarea>'
            . '<input type="hidden" name="alt0" value="' . e($cur) . '">'
            . '<div class="help">Fotoğrafta ne olduğunu İngilizce anlatın. Görme engelli ziyaretçiler ve Google bu metni okur.'
            . ($ph !== '' ? ' Boş kalırsa proje başlığı kullanılır.' : '') . ($it['unused'] ? ' Fotoğrafı bir bölümde "Galeriden seç" ile kullandığınızda bu metin kendiliğinden gelir.' : '') . '</div>';
        if (count($it['alts']) > 1) $o .= '<div class="flash warn small">' . ic('alert') . '<div>Bu fotoğraf ' . count($it['alts']) . ' yerde kullanılıyor; kaydedince hepsinde bu alternatif metin olur.'
            . (count($vals) > 1 ? ' Şu an farklı metinler var: ' . e(implode(' / ', array_map(fn($v) => $v !== '' ? '"' . $v . '"' : '(boş)', $vals))) . '.' : '') . '</div></div>';
    } else {
        $o .= '<div class="mro">' . e($it['alt'] !== '' ? $it['alt'] : '—') . '</div><div class="help">'
            . e(match (true) { $it['alt_how'] === 'auto' => 'Otomatik: tedarikçinin adından oluşur (Tedarikçiler bölümünde değişir).', default => 'Bu kullanımda alternatif metin gerekmez.' }) . '</div>';
    }
    $o .= '</div>';
    // where it is used
    $o .= '<div class="sd-sec"><span class="fl">Kullanıldığı yerler <span class="dim">' . count($it['uses']) . '</span></span>';
    if (!$it['uses']) {
        $o .= '<p class="muted small">' . ($it['lib'] ? 'Henüz sitede kullanılmıyor. Bir hizmet, bölge, proje veya sayfa düzenlerken fotoğraf alanındaki <b>Galeriden seç</b> ile kullanabilirsiniz.'
            : 'Sitede kullanılmıyor: yerine yenisi konan, adı değişen ya da yüklenip kaydedilmeyen bir fotoğrafın kopyası olabilir.') . '</p>';
    } else {
        $o .= '<ul class="musage">';
        foreach ($it['uses'] as $u)
            $o .= '<li><span>' . e($u['label']) . ($u['altp'] !== '' && $u['alt'] !== '' && count($it['alts']) > 1 ? '<small>alt: ' . e($u['alt']) . '</small>' : '') . '</span><span class="mu-act">'
                . ($u['edit'] !== '' ? '<a class="btn sm ghost" href="index.php?p=' . e($u['edit']) . '">' . aic('edit') . '<span>Düzenle</span></a>' : '')
                . ($u['page'] !== '' ? site_link($u['page'], 'Sayfada gör') : '') . '</span></li>';
        $o .= '</ul>';
    }
    $o .= '</div></div><div class="sd-foot"><span class="sd-dirty">' . ic('alert') . '<span>Kaydedilmedi</span></span>';
    if (media_can_delete($it)) {
        $msg = $it['name'] . ' kalıcı olarak silinsin mi?' . (media_in_backups($f) ? ' Eski bir yedekte kullanılıyor: o yedeğe geri dönerseniz bu fotoğraf görünmez.' : '');
        $o .= '<button type="submit" class="btn ghost danger" name="a" value="media_delete" formnovalidate data-del-msg="' . e($msg) . '" title="Fotoğrafı sil">' . aic('trash') . '<span>Sil</span></button>';
    }
    $o .= '<div class="sd-fa">' . (media_can_rename($it) || media_alt_editable($it) ? '<button type="submit" class="btn primary" name="a" value="media_save" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet</span></button>' : '') . '</div></div></form>';
    return $o;
}
/** GET p=media_edit: the panel for one photo (loaded into the gallery by admin.js). */
function media_edit_fragment(string $file): never {
    $items = media_items(C());
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($items[$file])) { http_response_code(404); echo '<p>Bu fotoğraf bulunamadı.</p>'; exit; }
    echo media_pane_html($items[$file]);
    exit;
}
function media_fail(string $msg, string $file, int $code = 422): never {
    if (wants_json()) json_out(['ok' => false, 'error' => $msg], $code);
    redirect(['p' => 'media'] + ($file !== '' ? ['open' => $file] : []), e($msg), 'bad');
}
/** The answer after a change: the new grid and chips, and the panel for $file ('' = empty panel). */
function media_done(string $msg, string $file, string $flashFile = ''): never {
    if (!wants_json()) redirect(['p' => 'media'] + ($flashFile !== '' ? ['open' => $flashFile] : []), $msg);
    $items = media_items(C());
    json_out(['ok' => true, 'file' => $file, 'note' => trim(html_entity_decode(strip_tags(str_replace('Siteyi görüntüle', '', $msg)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
              'html' => $file !== '' && isset($items[$file]) ? media_pane_html($items[$file]) : media_pane_empty($items), 'empty' => media_pane_empty($items),
              'grid' => media_grid_html($items, '', $file), 'chips' => media_chips_html($items, '')]);
}
/** POST a=media_save: new alternative text (for every use, or kept in the gallery) and/or a new file name. */
function act_media_save(): never {
    $file = clean_file((string)($_POST['file'] ?? ''));
    $c = C();
    $it = media_items($c)[$file] ?? null;
    if (!$it) media_fail('Bu fotoğraf bulunamadı. Galeriyi yenileyin.', '', 404);
    $err = [];
    $why = [];
    if (media_alt_editable($it) && isset($_POST['alt'])) {
        $alt = mb_substr(one_line((string)$_POST['alt']), 0, 250);
        if ($alt !== one_line((string)($_POST['alt0'] ?? ''))) {
            if ($it['alts']) {
                if ($alt === '' && array_filter($it['alts'], fn($u) => !$u['gallery'])) $err[] = 'Alternatif metin boş bırakılamaz.';
                else { foreach ($it['alts'] as $u) path_set($c, $u['altp'], $alt); $why[] = 'alt metin'; }
            } else $why[] = 'alt metin';
            if (!$err) $c = media_lib_set($c, $file, $alt, $it['lib'] || !$it['alts']);
        }
    }
    $rename = '';
    if (isset($_POST['fname']) && media_can_rename($it)) {
        $slug = substr(slugify((string)preg_replace('/\.(webp|png|jpe?g)$/i', '', trim((string)$_POST['fname']))), 0, 80);
        if ($slug === '') $err[] = 'Yeni dosya adı geçersiz. Harf, rakam ve tire kullanın.';
        elseif ($slug !== slugify((string)($_POST['fname0'] ?? ''))) $rename = $slug;
    }
    if ($err) media_fail(implode(' ', $err), $file);
    $new = $file;
    if ($rename !== '') {
        $new = with_lock(fn() => image_rename($file, $rename));
        if ($new !== $file) { $c = replace_file_refs($c, $file, $new); $why[] = 'dosya adı'; }
    }
    if (!$why) media_done('Değişiklik yok.', $file, $file);
    $rep = content_save($c, 'Galeri: ' . basename($new) . ' (' . implode(', ', $why) . ')');
    unset($GLOBALS['DM_SEO']);
    $msg = ($it['uses'] ? save_msg($rep) : '<b>Kaydedildi.</b>') . ($new !== $file ? ' Yeni dosya adı: <b>' . e(basename($new)) . '</b>.' : '');
    media_done($msg, $new, $new);
}
/** Keep (or add) a photo in the gallery's own list with its alternative text. */
function media_lib_set(array $c, string $file, string $alt, bool $add): array {
    $lib = is_array($c['library'] ?? null) ? array_values($c['library']) : [];
    foreach ($lib as &$x) if (($x['file'] ?? '') === $file) { $x['alt'] = $alt; $c['library'] = $lib; return $c; }
    unset($x);
    if ($add) $lib[] = ['file' => $file, 'alt' => $alt, 'added' => date('c')];
    $c['library'] = $lib;
    return $c;
}
/** POST a=media_add: photos just uploaded with "Fotoğraf ekle" (a=upload, kind photo) join the gallery. */
function act_media_add(): never {
    $c = C();
    $have = media_lib($c);
    $added = [];
    foreach ((array)($_POST['files'] ?? []) as $raw) {
        $f = clean_file(is_string($raw) ? $raw : '');
        if ($f === '' || !preg_match('#^assets/uploads/photos/[A-Za-z0-9_\-.]+\.(webp|jpe?g|png)$#', $f) || str_ends_with($f, '-sm.webp') || !is_file(DM_ROOT . '/' . $f)) continue;
        if (isset($have[$f]) || in_array($f, $added, true)) continue;
        $added[] = $f;
        $c = media_lib_set($c, $f, '', true);
    }
    if (!$added) media_fail('Eklenecek fotoğraf bulunamadı. Tekrar yükleyin.', '');
    content_save($c, 'Galeri: ' . count($added) . ' fotoğraf eklendi');
    media_done('<b>' . count($added) . ' fotoğraf galeriye eklendi.</b> Alternatif metinlerini yazmayı unutmayın.', $added[0], $added[0]);
}
/** POST a=media_delete: remove an upload that the site does not use (and its phone copy). */
function act_media_delete(): never {
    $file = clean_file((string)($_POST['file'] ?? ''));
    $c = C();
    $it = media_items($c)[$file] ?? null;
    if (!$it) media_fail('Bu fotoğraf bulunamadı. Galeriyi yenileyin.', '', 404);
    if (!media_can_delete($it)) media_fail($it['unused'] ? 'Sitenin kendi fotoğrafları silinemez.' : 'Bu fotoğraf sitede kullanılıyor; önce kullanıldığı yerlerden kaldırın.', $file);
    with_lock(function () use ($file) {
        @unlink(DM_ROOT . '/' . $file);
        $sm = img_small($file);
        if ($sm !== $file && is_file(DM_ROOT . '/' . $sm)) @unlink(DM_ROOT . '/' . $sm);
    });
    if ($it['lib']) {
        $c['library'] = array_values(array_filter((array)($c['library'] ?? []), fn($x) => !is_array($x) || ($x['file'] ?? '') !== $file));
        content_save($c, 'Galeri: ' . basename($file) . ' silindi');
    }
    media_done('<b>' . e(basename($file)) . '</b> silindi.', '');
}
/** GET p=media_pick: photos for "Galeriden seç" in the editors (used ones and the gallery's own, newest first). */
function media_pick(): never {
    $o = [];
    foreach (media_items(C()) as $it) {
        if ($it['cat'] !== 'photo' || !$it['exists'] || !$it['shown']) continue;
        $o[] = ['file' => $it['file'], 'url' => thumb_url($it['file']), 'sm' => media_thumb($it), 'name' => $it['name'], 'w' => $it['w'], 'h' => $it['h'],
                'alt' => $it['alt_how'] === '' ? $it['alt'] : '', 'used' => count($it['uses']), 'new' => $it['lib'] && $it['unused']];
    }
    json_out(['ok' => true, 'items' => $o]);
}
