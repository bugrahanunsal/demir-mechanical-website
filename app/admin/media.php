<?php
/**
 * Galeri (İçerik → Galeri): every photo of the website in one place, wherever it was uploaded (services, areas,
 * projects, home page, founder, logos, sharing images) plus the site's own photos. Each card shows the file name and the
 * alternative text; a click opens the photo with both fields editable and the list of places that use it.
 * Nothing new is stored: the list is read from the content and the photo folders every time.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const MEDIA_DIRS = ['assets/uploads/photos', 'assets/uploads/projects', 'assets/uploads/og', 'assets/photos', 'assets/uploads/logos', 'assets/uploads/suppliers', 'assets/uploads/icons'];
const MEDIA_RE = '#^assets/[A-Za-z0-9/_\-.]+\.(webp|png|jpe?g|gif|svg)$#i';

/** Every place in the content that uses an image file: file => [content paths]. */
function media_refs(array $c): array {
    $o = [];
    $walk = function ($v, string $p) use (&$walk, &$o) {
        if (!is_array($v)) { if (is_string($v) && preg_match(MEDIA_RE, $v)) $o[$v][] = $p; return; }
        if (isset($v['file']) && is_string($v['file']) && array_key_exists('alt', $v)) { if ($v['file'] !== '') $o[$v['file']][] = $p; return; }
        foreach ($v as $k => $x) $walk($x, $p . '.' . $k);
    };
    foreach ($c as $k => $v) if (!in_array($k, ['redirects', 'custom'], true)) $walk($v, (string)$k);
    return $o;
}
/** Where a content path is on the site, in words: [label, editor (p=…), page]. */
function media_place(array $c, string $p): array {
    $cat = [];
    foreach (seo_catalog($c) as $e) $cat[$e['base']] = $e;
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
function media_usage(array $c, string $p): array {
    [$label, $edit, $page] = media_place($c, $p);
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
/** Every image: the ones the content uses and every file in the photo folders (newest first). */
function media_items(array $c): array {
    $refs = media_refs($c);
    $files = array_fill_keys(array_keys($refs), true);
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
        if (!$ps && isset($refs[$f])) continue;                     // only the header logo's WebP copy
        $uses = array_map(fn($p) => media_usage($c, $p), $ps);
        $ok = is_file(DM_ROOT . '/' . $f);
        [$w, $h] = $ok ? img_size($f) : [0, 0];
        $it = ['file' => $f, 'name' => basename($f), 'cat' => media_cat($f), 'uses' => $uses, 'exists' => $ok, 'w' => $w, 'h' => $h,
               'size' => $ok ? (int)filesize(DM_ROOT . '/' . $f) : 0, 'time' => $ok ? (int)filemtime(DM_ROOT . '/' . $f) : 0];
        $alts = array_values(array_filter($uses, fn($u) => $u['altp'] !== ''));
        $it['alts'] = $alts;
        $it['noalt'] = (bool)array_filter($alts, fn($u) => $u['alt'] === '' && !$u['gallery']);
        $it['unused'] = !$uses;
        $o[$f] = $it;
    }
    $rank = ['photo' => 0, 'og' => 1, 'logo' => 2];        // photos first; in each group the newest uploads first
    $up = fn(array $x) => str_starts_with($x['file'], 'assets/uploads/') ? 0 : 1;
    uasort($o, fn($a, $b) => [$rank[$a['cat']], $up($a), $b['time'] * (1 - $up($b)), $a['name']] <=> [$rank[$b['cat']], $up($b), $a['time'] * (1 - $up($a)), $b['name']]);
    return $o;
}
function media_can_rename(array $it): bool {
    return $it['exists'] && $it['uses'] && (bool)preg_match('#^assets/(photos|uploads/(photos|projects|og))/[A-Za-z0-9_\-.]+\.(webp|png|jpe?g)$#', $it['file']);
}
/** The alternative text shown on the card: the first use that has one (project photos fall back to the title). */
function media_alt_text(array $it): array {
    foreach ($it['alts'] as $u) {
        if ($u['alt'] !== '') return [$u['alt'], ''];
        if ($u['auto'] !== '') return [$u['auto'], 'auto'];
    }
    if ($it['alts']) return ['', 'missing'];
    foreach ($it['uses'] as $u) if ($u['auto'] !== '') return [$u['auto'], 'auto'];
    if ($it['uses'] && $it['uses'][0]['decor']) return ['Dekoratif arka plan: alternatif metin gerekmez', 'none'];
    if ($it['cat'] === 'og') return ['Paylaşım görseli: alternatif metin gerekmez', 'none'];
    return ['', 'none'];
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
    return match ($f) { 'photo', 'og', 'logo' => $it['cat'] === $f, 'noalt' => $it['noalt'], 'unused' => $it['unused'], default => true };
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
function media_card(array $it, string $filter = ''): string {
    [$alt, $how] = media_alt_text($it);
    $altHtml = match ($how) {
        'missing' => '<span class="warn">Alternatif metin yok</span>',
        'auto' => e($alt) . ' <span class="dim">(otomatik)</span>',
        'none' => '<span class="dim">' . e($alt !== '' ? $alt : '—') . '</span>',
        default => e($alt),
    };
    $tags = '';
    if ($it['unused']) $tags .= '<span class="tag">Kullanılmıyor</span>';
    elseif (count($it['uses']) > 1) $tags .= '<span class="tag">' . count($it['uses']) . ' yerde</span>';
    if (!$it['exists']) $tags .= '<span class="tag warn">Dosya yok</span>';
    $q = mb_strtolower($it['name'] . ' ' . $alt . ' ' . implode(' ', array_column($it['uses'], 'label')) . ' ' . implode(' ', array_map(fn($u) => (string)$u['alt'], $it['uses'])));
    $src = media_thumb($it);
    return '<a class="mcard ' . $it['cat'] . (media_dark($it) ? ' dark' : '') . '" href="index.php?p=media&amp;open=' . e(rawurlencode($it['file'])) . '" data-media-open="' . e($it['file']) . '" data-cat="' . $it['cat'] . '" data-un="' . ($it['unused'] ? '1' : '0') . '" data-na="' . ($it['noalt'] ? '1' : '0') . '" data-q="' . e($q) . '"' . (media_match($it, $filter) ? '' : ' hidden') . '>'
        . '<span class="mthumb">' . ($src !== '' ? '<img src="' . e($src) . '" alt="" loading="lazy" decoding="async">' : aic('image')) . '</span>'
        . '<span class="mmeta"><b class="mname">' . e($it['name']) . '</b><span class="malt">' . $altHtml . '</span>' . ($tags !== '' ? '<span class="mtags">' . $tags . '</span>' : '') . '</span></a>';
}
function media_grid_html(array $items, string $filter = ''): string {
    $o = '';
    foreach ($items as $it) $o .= media_card($it, $filter);
    return $o . '<p class="seo-none card muted" hidden>Bu filtreye veya aramaya uyan fotoğraf yok.</p>';
}
function view_media(string $filter, string $open): string {
    $items = media_items(C());
    $filter = array_key_exists($filter, media_filters()) ? $filter : '';
    $o = '<div id="mediaApp" class="media-app" data-filter="' . e($filter) . '">'
        . head_block('Galeri', 'Sitedeki bütün fotoğraflar tek yerde, hangi bölümden yüklenmiş olursa olsun: hizmetler, bölgeler, projeler, ana sayfa, logolar, paylaşım görselleri. Kartlarda dosya adı ve alternatif metin (alt) yazar; fotoğrafa tıklayınca büyük hali açılır, ikisini de buradan değiştirebilirsiniz. Yeni fotoğraf kullanılacağı bölümden yüklenir ve burada kendiliğinden görünür.')
        . '<div class="seo-bar"><label class="seo-search">' . aic('search') . '<span class="sr">Fotoğraf ara</span><input type="search" id="mediaSearch" placeholder="Dosya adı, alt metin veya sayfa ara…" autocomplete="off" spellcheck="false"></label>'
        . '<nav class="chips" id="mediaChips" aria-label="Filtre">' . media_chips_html($items, $filter) . '</nav></div>'
        . '<div class="mgrid" id="mediaGrid">' . media_grid_html($items, $filter) . '</div>';
    $form = ($open !== '' && isset($items[$open])) ? media_modal_html($items[$open]) : '';
    return $o . '<dialog id="mediaModal" class="seo-dlg media-dlg" aria-label="Fotoğraf"' . ($form !== '' ? ' open' : '') . '>' . $form . '</dialog></div>';
}

/* ---------------- one photo ---------------- */
function media_modal_html(array $it): string {
    $f = $it['file'];
    [$alt, $how] = media_alt_text($it);
    $catName = ['photo' => 'Fotoğraf', 'og' => 'Paylaşım görseli', 'logo' => 'Logo / ikon'][$it['cat']];
    $where = str_starts_with($f, 'assets/uploads/') ? 'Panelden yüklendi' : 'Sitenin kendi dosyası';
    $o = '<form method="post" action="index.php" class="seo-ed media-ed" data-file="' . e($f) . '" novalidate>' . csrf_field()
        . '<input type="hidden" name="file" value="' . e($f) . '">'
        . '<div class="sd-head"><span class="mh-ic">' . aic('image') . '</span><div class="sd-ttl" tabindex="-1" autofocus><b>' . e($it['name']) . '</b><small>' . e($catName) . ' · ' . e($where) . '</small></div>'
        . '<div class="sd-nav"><span class="ib off" data-media-nav="prev">‹</span><span class="ib off" data-media-nav="next">›</span><a class="ib" href="index.php?p=media" data-media-close title="Kapat" aria-label="Kapat">' . aic('x') . '</a></div></div>'
        . '<div class="sd-body"><div class="flash bad" data-media-err hidden></div>';
    $o .= '<div class="sd-sec"><a class="mprev' . (media_dark($it) ? ' dark' : '') . '" href="../' . e($f) . '" target="_blank" rel="noopener" title="Tam boyutta aç">'
        . ($it['exists'] ? '<img src="' . e(thumb_url($f)) . '" alt="">' : '<span class="noimg">Dosya bulunamadı</span>') . '</a>'
        . '<div class="mfacts">' . ($it['w'] ? '<span>' . $it['w'] . '×' . $it['h'] . ' px</span>' : '') . ($it['size'] ? '<span>' . kb($it['size']) . '</span>' : '')
        . ($it['time'] ? '<span>' . e(tr_date($it['time'])) . '</span>' : '') . '<span>' . e(dirname($f)) . '/</span>'
        . '<a class="lnk" href="../' . e($f) . '" target="_blank" rel="noopener">' . aic('ext') . 'Tam boyut</a></div></div>';
    // file name
    $o .= '<div class="sd-sec"><label class="fl" for="mdName">Dosya adı</label>';
    if (media_can_rename($it)) {
        $base = pathinfo($f, PATHINFO_FILENAME);
        $o .= '<span class="fname"><input type="text" id="mdName" name="fname" value="' . e($base) . '" spellcheck="false" autocomplete="off" autocapitalize="off"><span>.' . e(pathinfo($f, PATHINFO_EXTENSION)) . '</span></span>'
            . '<input type="hidden" name="fname0" value="' . e($base) . '">'
            . '<div class="help">Google Görseller için fotoğrafı anlatan İngilizce kelimeler kullanın (ör. copper-riser-north-york). Ad değişince fotoğraf yeni adla kopyalanır ve sitedeki her kullanımı güncellenir; eski dosya eski yedekler için kalır.</div>';
    } else {
        $why = !$it['uses'] ? 'Bu fotoğraf sitede kullanılmıyor.' : ($it['cat'] === 'logo' ? 'Logo ve ikon dosyaları Logo & Görseller (veya Tedarikçiler) bölümünden yeni dosya yüklenerek değişir.' : 'Bu dosyanın adı buradan değiştirilemez.');
        $o .= '<div class="mro">' . e($it['name']) . '</div><div class="help">' . e($why) . '</div>';
    }
    $o .= '</div>';
    // alternative text
    $o .= '<div class="sd-sec"><label class="fl" for="mdAlt">Alternatif metin (alt)</label>';
    if ($it['alts']) {
        $vals = array_values(array_unique(array_map(fn($u) => $u['alt'], $it['alts'])));
        $cur = $vals[0];
        $ph = $cur === '' ? (array_values(array_filter(array_column($it['alts'], 'auto')))[0] ?? '') : '';
        $o .= '<input type="text" id="mdAlt" name="alt" value="' . e($cur) . '"' . ($ph !== '' ? ' placeholder="' . e($ph) . '"' : '') . ' maxlength="250">'
            . '<input type="hidden" name="alt0" value="' . e($cur) . '">'
            . '<div class="help">Fotoğrafta ne olduğunu İngilizce anlatın (ör. "Plumber replacing a copper riser in a North York condo"). Görme engelli ziyaretçiler ve Google bu metni okur.'
            . ($ph !== '' ? ' Boş kalırsa proje başlığı kullanılır.' : '') . '</div>';
        if (count($it['alts']) > 1) $o .= '<div class="flash warn small">' . ic('alert') . '<div>Bu fotoğraf ' . count($it['alts']) . ' yerde kullanılıyor; kaydedince hepsinde bu alternatif metin olur.'
            . (count($vals) > 1 ? ' Şu an farklı metinler var: ' . e(implode(' / ', array_map(fn($v) => $v !== '' ? '"' . $v . '"' : '(boş)', $vals))) . '.' : '') . '</div></div>';
    } else {
        $o .= '<div class="mro">' . e($alt !== '' ? $alt : '—') . '</div><div class="help">'
            . e(match (true) { !$it['uses'] => 'Kullanılmayan fotoğrafın alternatif metni olmaz; bir bölümde kullanılınca orada yazılır.', $how === 'auto' => 'Otomatik: tedarikçinin adından oluşur (Tedarikçiler bölümünde değişir).', default => 'Bu kullanımda alternatif metin gerekmez.' }) . '</div>';
    }
    $o .= '</div>';
    // where it is used
    $o .= '<div class="sd-sec"><span class="fl">Kullanıldığı yerler <span class="dim">' . count($it['uses']) . '</span></span>';
    if (!$it['uses']) $o .= '<p class="muted small">Sitede kullanılmıyor (örneğin yüklenip kaydedilmeyen ya da yerine yenisi konan bir fotoğraf). Eski yedeklerde kullanılmıyorsa Yedekler sayfasındaki "Kullanılmayan fotoğraflar" ile silebilirsiniz.</p>';
    else {
        $o .= '<ul class="musage">';
        foreach ($it['uses'] as $u)
            $o .= '<li><span>' . e($u['label']) . ($u['altp'] !== '' && $u['alt'] !== '' && count($it['alts']) > 1 ? '<small>alt: ' . e($u['alt']) . '</small>' : '') . '</span><span class="mu-act">'
                . ($u['edit'] !== '' ? '<a class="btn sm ghost" href="index.php?p=' . e($u['edit']) . '">' . aic('edit') . '<span>Düzenle</span></a>' : '')
                . ($u['page'] !== '' ? site_link($u['page'], 'Sayfada gör') : '') . '</span></li>';
        $o .= '</ul>';
    }
    $o .= '</div></div><div class="sd-foot"><span class="sd-dirty">' . ic('alert') . '<span>Kaydedilmedi</span></span>'
        . '<div class="sd-fa"><a class="btn" href="index.php?p=media" data-media-close>Kapat</a>'
        . (media_can_rename($it) || $it['alts'] ? '<button type="submit" class="btn primary" name="a" value="media_save" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet</span></button>' : '') . '</div></div></form>';
    return $o;
}
/** GET p=media_edit: the window for one photo (loaded into the gallery by admin.js). */
function media_edit_fragment(string $file): never {
    $items = media_items(C());
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($items[$file])) { http_response_code(404); echo '<p>Bu fotoğraf bulunamadı.</p>'; exit; }
    echo media_modal_html($items[$file]);
    exit;
}
function media_fail(string $msg, string $file, int $code = 422): never {
    if (wants_json()) json_out(['ok' => false, 'error' => $msg], $code);
    redirect(['p' => 'media', 'open' => $file], e($msg), 'bad');
}
/** POST a=media_save: new alternative text (for every use) and/or a new file name. */
function act_media_save(): never {
    $file = clean_file((string)($_POST['file'] ?? ''));
    $c = C();
    $it = media_items($c)[$file] ?? null;
    if (!$it) media_fail('Bu fotoğraf bulunamadı. Galeriyi yenileyin.', '', 404);
    $err = [];
    $why = [];
    if ($it['alts'] && isset($_POST['alt'])) {
        $alt = mb_substr(one_line((string)$_POST['alt']), 0, 250);
        if ($alt !== one_line((string)($_POST['alt0'] ?? ''))) {
            if ($alt === '' && array_filter($it['alts'], fn($u) => !$u['gallery'])) $err[] = 'Alternatif metin boş bırakılamaz.';
            else { foreach ($it['alts'] as $u) path_set($c, $u['altp'], $alt); $why[] = 'alt metin'; }
        }
    }
    $rename = '';
    if (media_can_rename($it) && isset($_POST['fname'])) {
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
    if (!$why) {
        if (wants_json()) json_out(['ok' => true, 'note' => 'Değişiklik yok.', 'file' => $file]);
        redirect(['p' => 'media', 'open' => $file], 'Değişiklik yok.');
    }
    $rep = content_save($c, 'Galeri: ' . basename($new) . ' (' . implode(', ', $why) . ')');
    unset($GLOBALS['DM_SEO']);
    $msg = save_msg($rep) . ($new !== $file ? ' Yeni dosya adı: <b>' . e(basename($new)) . '</b>.' : '');
    if (!wants_json()) redirect(['p' => 'media', 'open' => $new], $msg);
    $items = media_items(C());
    json_out(['ok' => true, 'file' => $new, 'note' => trim(html_entity_decode(strip_tags(str_replace('Siteyi görüntüle', '', $msg)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
              'html' => isset($items[$new]) ? media_modal_html($items[$new]) : '', 'grid' => media_grid_html($items), 'chips' => media_chips_html($items, '')]);
}
