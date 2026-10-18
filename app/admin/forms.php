<?php
/**
 * Form fields for the admin panel, generated from the schema (schema.php): rendering and reading back + validation.
 * Input names: f[path] for top-level fields, f[path][i][sub] inside repeaters. data-n holds the name template with
 * {0},{1}… for repeater positions so the page script can renumber items after adding, removing or moving them.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

/* ======================= rendering ======================= */
function fld_name(array $parts): string {
    $o = 'f';
    foreach ($parts as $p) $o .= '[' . $p . ']';
    return $o;
}
/** $parts: name parts with real indices; $tpl: same with {n} placeholders for repeater positions. */
function render_fields(array $fields, array $data, array $parts, array $tpl, array $errors, string $epath): string {
    $o = '';
    foreach ($fields as $fd) $o .= render_field($fd, $data, $parts, $tpl, $errors, $epath);
    return $o;
}
function err_of(array $errors, string $p): string {
    return isset($errors[$p]) ? '<div class="ferr">' . e($errors[$p]) . '</div>' : '';
}
function render_field(array $fd, array $data, array $parts, array $tpl, array $errors, string $epath): string {
    $t = $fd['t'];
    if ($t === 'heading') return '<h3 class="fh">' . e($fd['l']) . '</h3>' . (($fd['h'] ?? '') !== '' ? '<p class="fhelp">' . e($fd['h']) . '</p>' : '');
    $k = $fd['k'];
    $val = path_get($data, $k);
    $np = array_merge($parts, [$k]); $nt = array_merge($tpl, [$k]);
    $name = fld_name($np); $dn = fld_name($nt);
    $ep = ($epath === '' ? '' : $epath . '.') . $k;
    $id = 'f' . substr(md5($name), 0, 10);
    $help = ($fd['h'] ?? '') !== '' ? '<div class="help">' . e($fd['h']) . '</div>' : '';
    $req = !empty($fd['req']) ? ' <span class="req" title="Zorunlu">*</span>' : '';
    $lab = '<label class="fl" for="' . $id . '">' . e($fd['l']) . $req . '</label>';
    $er = err_of($errors, $ep);
    $cls = 'fld' . ($er !== '' ? ' has-err' : '');
    $max = isset($fd['max']) ? ' maxlength="' . (int)$fd['max'] . '"' : '';
    $seo = isset($fd['seo']) ? ' data-seo="' . $fd['seo'] . '"' : '';
    // optional input hints: ph = placeholder, list = <datalist> id, ac = autocomplete, im = inputmode (phone keyboards)
    $more = (isset($fd['ph']) && $t !== 'code' ? ' placeholder="' . e($fd['ph']) . '"' : '') . (isset($fd['list']) ? ' list="' . e($fd['list']) . '"' : '')
        . (isset($fd['ac']) || isset($fd['list']) ? ' autocomplete="' . e($fd['ac'] ?? 'off') . '"' : '') . (isset($fd['im']) ? ' inputmode="' . e($fd['im']) . '"' : '');
    switch ($t) {
        case 'text':
            return '<div class="' . $cls . '">' . $lab . '<input type="text" id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '" value="' . e(is_scalar($val) ? (string)$val : '') . '"' . $max . $seo . $more . '>' . $help . $er . '</div>';
        case 'date':
            $dv = is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : '';
            return '<div class="' . $cls . ' fdate">' . $lab . '<input type="date" id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '" value="' . e($dv) . '"' . (!empty($fd['req']) ? ' required' : '') . '>' . $help . $er . '</div>';
        case 'number':
            return '<div class="' . $cls . ' fnum">' . $lab . '<input type="text" inputmode="decimal" id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '" value="' . e(is_scalar($val) ? (string)$val : '') . '"' . (isset($fd['ph']) ? ' placeholder="' . e($fd['ph']) . '"' : '') . '>' . $help . $er . '</div>';
        case 'textarea':
            return '<div class="' . $cls . '">' . $lab . '<textarea id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '" rows="' . (int)($fd['rows'] ?? 3) . '"' . $max . $seo . $more . '>' . e(is_scalar($val) ? (string)$val : '') . '</textarea>' . $help . $er . '</div>';
        case 'code':
            return '<div class="' . $cls . ' fcode">' . $lab . '<textarea id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '" data-code="' . e($fd['lang'] ?? 'html') . '" rows="' . (int)($fd['rows'] ?? 10) . '" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" wrap="off"'
                . (isset($fd['ph']) ? ' placeholder="' . e($fd['ph']) . '"' : '') . '>' . e(is_scalar($val) ? (string)$val : '') . '</textarea>' . $help . $er . '</div>';
        case 'themecode':
            $orig = theme_original((string)$fd['src']);
            $edited = is_scalar($val) && trim((string)$val) !== '';
            $cur = $edited ? (string)$val : $orig;
            $base = (string)path_get($data, $k . '_base', '');
            $state = $edited ? ic('alert') . '<span data-tc-msg>Düzenlenmiş kopya kullanılıyor.</span>' : ic('check') . '<span data-tc-msg>Sitenin orijinal kodu kullanılıyor.</span>';
            return '<div class="' . $cls . ' fcode ftheme">' . $lab
                . '<div class="tc-state ' . ($edited ? 'ed' : 'orig') . '">' . $state . '<span class="dim">' . (substr_count($cur, "\n") + 1) . ' satır · ' . kb(strlen($cur)) . '</span>'
                . '<button type="button" class="link" data-code-reset>Orijinal koda dön</button></div>'
                . ($edited && $base !== '' && $base !== sha1($orig) ? '<div class="flash warn small">' . ic('alert') . '<div>Sitenin orijinal kodu, siz bu kopyayı düzenledikten sonra güncellendi (yeni sürüm). Güncel orijinali kullanmak için "Orijinal koda dön"e basıp kendi değişikliğinizi yeniden yapın.</div></div>' : '')
                . '<textarea id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '" data-code="' . e($fd['lang'] ?? 'css') . '" rows="' . (int)($fd['rows'] ?? 22) . '" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" wrap="off">' . e($cur) . '</textarea>'
                . '<template class="code-orig">' . e($orig) . '</template>' . $help . $er . '</div>';
        case 'lines':
            $txt = is_array($val) ? implode("\n", array_map('strval', $val)) : (string)$val;
            return '<div class="' . $cls . '">' . $lab . '<textarea id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '" rows="' . (int)($fd['rows'] ?? 4) . '">' . e($txt) . '</textarea>' . $help . $er . '</div>';
        case 'check':
            return '<div class="' . $cls . ' fcheck"><input type="hidden" name="' . e($name) . '" data-n="' . e($dn) . '" value="0"><label><input type="checkbox" name="' . e($name) . '" data-n="' . e($dn) . '" value="1"' . (!empty($val) ? ' checked' : '') . '> ' . e($fd['l']) . '</label>' . $help . $er . '</div>';
        case 'select':
        case 'icon':
            $opts = $t === 'icon' ? icon_options() : ($fd['opts'] ?? []);
            $o = '';
            foreach ($opts as $v => $l) $o .= '<option value="' . e((string)$v) . '"' . ((string)$v === (string)$val ? ' selected' : '') . '>' . e((string)$l) . '</option>';
            if ($t === 'icon' && !isset($opts[(string)$val]) && (string)$val !== '') $o .= '<option value="' . e((string)$val) . '" selected>' . e((string)$val) . '</option>';
            return '<div class="' . $cls . ($t === 'icon' ? ' ficon' : '') . '">' . $lab . '<div class="sel-wrap">' . ($t === 'icon' ? '<span class="ic-prev" aria-hidden="true"></span>' : '') . '<select id="' . $id . '" name="' . e($name) . '" data-n="' . e($dn) . '">' . $o . '</select></div>' . $help . $er . '</div>';
        case 'image':
            return '<div class="' . $cls . '">' . $lab . render_image(is_array($val) ? $val : [], $np, $nt, $fd, $id) . $help . $er . '</div>';
        case 'logofile':
            return '<div class="' . $cls . '">' . $lab . render_logofile((string)$val, $name, $dn, $fd, $id) . $help . $er . '</div>';
        case 'repeater':
            return '<div class="' . $cls . ' frep">' . $lab . $help . render_repeater($fd, is_array($val) ? array_values($val) : [], $np, $nt, $errors, $ep) . $er . '</div>';
        case 'gallery':
            return '<div class="' . $cls . ' fgal">' . render_gallery($fd, is_array($val) ? array_values($val) : [], $np, $nt, $errors, $ep) . $help . $er . '</div>';
        case 'menu':
            return '<div class="' . $cls . ' fmenu">' . $lab . render_menu(is_array($val) ? $val : [], $np, $nt) . $help . $er . '</div>';
        case 'logo': case 'flogo': case 'favicon':
            return '<div class="' . $cls . '">' . $lab . render_brand($t, $data) . $help . $er . '</div>';
    }
    return '';
}
function pos_select(string $name, string $dn, string $cur): string {
    $opts = ['50% 50%' => 'Ortala', '50% 25%' => 'Üst', '50% 75%' => 'Alt', '25% 50%' => 'Sol', '75% 50%' => 'Sağ'];
    if ($cur !== '' && !isset($opts[$cur])) $opts = [$cur => 'Özel (' . $cur . ')'] + $opts;
    $o = '';
    foreach ($opts as $v => $l) $o .= '<option value="' . e($v) . '"' . ($v === ($cur ?: '50% 50%') ? ' selected' : '') . '>' . e($l) . '</option>';
    return '<label class="mini">Kırpma odağı <select name="' . e($name) . '" data-n="' . e($dn) . '" class="posel">' . $o . '</select></label>';
}
function thumb_url(string $file): string { return $file === '' ? '' : '../' . $file . '?v=' . (is_file(DM_ROOT . '/' . $file) ? filemtime(DM_ROOT . '/' . $file) : 0); }
function render_image(array $v, array $np, array $nt, array $fd, string $id, bool $caption = false): string {
    $file = (string)($v['file'] ?? '');
    $n = fn(string $s) => e(fld_name(array_merge($np, [$s])));
    $d = fn(string $s) => e(fld_name(array_merge($nt, [$s])));
    $exists = $file !== '' && is_file(DM_ROOT . '/' . $file);
    [$w, $h] = $exists ? img_size($file) : [0, 0];
    $o = '<div class="imgf" data-kind="' . e($fd['kind'] ?? 'photo') . '">'
        . '<div class="imgf-prev">' . ($exists ? '<img src="' . e(thumb_url($file)) . '" alt="">' : '<span class="noimg">Fotoğraf yok</span>') . '</div>'
        . '<div class="imgf-side">'
        . '<input type="hidden" class="imgf-file" name="' . $n('file') . '" data-n="' . $d('file') . '" value="' . e($file) . '">'
        . (isset($v['key']) ? '<input type="hidden" name="' . $n('key') . '" data-n="' . $d('key') . '" value="' . e((string)$v['key']) . '">' : '')
        . '<div class="imgf-name">' . ($exists ? e(basename($file)) . ' <span class="dim">' . $w . '×' . $h . '</span>' : ($file !== '' ? '<span class="warn">Dosya bulunamadı: ' . e($file) . '</span>' : '')) . '</div>'
        . '<div class="imgf-act"><label class="btn sm">Fotoğraf yükle<input type="file" accept="image/jpeg,image/png,image/webp" class="imgf-up" hidden></label>'
        . (!$caption && in_array($fd['kind'] ?? 'photo', ['photo', 'hero', 'portrait'], true) ? '<button type="button" class="btn sm imgf-pick">Galeriden seç</button>' : '')
        . '<button type="button" class="btn sm ghost imgf-clear"' . ($file === '' ? ' hidden' : '') . '>Kaldır</button><span class="imgf-st" aria-live="polite"></span></div>';
    $altLabel = $caption ? 'Alt metin (SEO, İngilizce)' : 'Alternatif metin (SEO, ne gösterdiğini İngilizce yazın)';
    if (empty($fd['noalt'])) $o .= '<label class="mini">' . $altLabel . '<input type="text" class="imgf-alt" name="' . $n('alt') . '" data-n="' . $d('alt') . '" value="' . e((string)($v['alt'] ?? '')) . '"></label>';
    else $o .= '<input type="hidden" name="' . $n('alt') . '" data-n="' . $d('alt') . '" value="">';
    if ($caption) $o .= '<label class="mini">Açıklama (büyük görünümde altta)<input type="text" name="' . $n('caption') . '" data-n="' . $d('caption') . '" value="' . e((string)($v['caption'] ?? '')) . '"></label>';
    $o .= pos_select(fld_name(array_merge($np, ['pos'])), fld_name(array_merge($nt, ['pos'])), (string)($v['pos'] ?? '50% 50%'));
    return $o . '</div></div>';
}
function render_logofile(string $file, string $name, string $dn, array $fd, string $id): string {
    $exists = $file !== '' && is_file(DM_ROOT . '/' . $file);
    return '<div class="imgf logo" data-kind="' . e($fd['kind'] ?? 'supplier') . '"><div class="imgf-prev">' . ($exists ? '<img src="' . e(thumb_url($file)) . '" alt="">' : '<span class="noimg">Logo yok</span>') . '</div><div class="imgf-side">'
        . '<input type="hidden" class="imgf-file" name="' . e($name) . '" data-n="' . e($dn) . '" value="' . e($file) . '">'
        . '<div class="imgf-name">' . ($exists ? e(basename($file)) : '') . '</div>'
        . '<div class="imgf-act"><label class="btn sm">Logo yükle<input type="file" accept="image/png,image/webp,image/jpeg" class="imgf-up" hidden></label><button type="button" class="btn sm ghost imgf-clear"' . ($file === '' ? ' hidden' : '') . '>Kaldır</button><span class="imgf-st" aria-live="polite"></span></div></div></div>';
}
function render_brand(string $t, array $data): string {
    $b = $data['branding'] ?? [];
    if ($t === 'logo') { $file = (string)($b['header_logo_png'] ?? ''); $bg = ''; $kind = 'logo'; }
    elseif ($t === 'flogo') { $file = (string)($b['footer_logo'] ?? ''); $bg = ' dark'; $kind = 'flogo'; }
    else { $file = (string)($b['apple_touch_icon'] ?? ''); $bg = ''; $kind = 'favicon'; }
    $exists = $file !== '' && is_file(DM_ROOT . '/' . $file);
    $names = ['logo' => 'header_logo_png', 'flogo' => 'footer_logo', 'favicon' => 'apple_touch_icon'];
    $o = '<div class="imgf brand' . $bg . '" data-kind="' . $kind . '"><div class="imgf-prev">' . ($exists ? '<img src="' . e(thumb_url($file)) . '" alt="">' : '<span class="noimg">Yok</span>') . '</div><div class="imgf-side">'
        . '<input type="hidden" class="imgf-file" name="' . e(fld_name(['branding.' . $names[$t]])) . '" value="' . e($file) . '">';
    if ($t === 'logo') $o .= '<input type="hidden" class="imgf-webp" name="' . e(fld_name(['branding.header_logo_webp'])) . '" value="' . e((string)($b['header_logo_webp'] ?? '')) . '"><input type="hidden" class="imgf-ratio" name="' . e(fld_name(['branding.header_logo_ratio'])) . '" value="' . e((string)($b['header_logo_ratio'] ?? '')) . '">';
    if ($t === 'flogo') $o .= '<input type="hidden" class="imgf-w" name="' . e(fld_name(['branding.footer_logo_w'])) . '" value="' . e((string)($b['footer_logo_w'] ?? '')) . '"><input type="hidden" class="imgf-h" name="' . e(fld_name(['branding.footer_logo_h'])) . '" value="' . e((string)($b['footer_logo_h'] ?? '')) . '">';
    if ($t === 'favicon') foreach (['favicon_32', 'favicon_48', 'apple_touch_icon'] as $k) if ($k !== 'apple_touch_icon') $o .= '<input type="hidden" class="imgf-' . $k . '" name="' . e(fld_name(['branding.' . $k])) . '" value="' . e((string)($b[$k] ?? '')) . '">';
    if ($t === 'favicon') $o .= '<input type="hidden" class="imgf-icon_512" name="' . e(fld_name(['site.schema_image'])) . '" value="' . e((string)($data['site']['schema_image'] ?? '')) . '">';
    return $o . '<div class="imgf-name">' . ($exists ? e(basename($file)) : '') . '</div><div class="imgf-act"><label class="btn sm">Yeni yükle<input type="file" accept="image/png,image/webp,image/jpeg" class="imgf-up" hidden></label><span class="imgf-st" aria-live="polite"></span></div></div></div>';
}
function rep_title(array $fd, array $item): string {
    $t = (string)($item[$fd['title'] ?? ''] ?? '');
    return $t !== '' ? $t : '(yeni)';
}
function render_repeater(array $fd, array $items, array $np, array $nt, array $errors, string $ep): string {
    $level = count(array_filter($nt, fn($x) => preg_match('/^\{\d+\}$/', (string)$x)));
    $ph = '{' . $level . '}';
    $o = '<div class="rep" data-min="' . (int)($fd['min'] ?? 0) . '" data-max="' . (int)($fd['max_items'] ?? 0) . '"><div class="rep-items">';
    foreach ($items as $i => $item) $o .= rep_item($fd, is_array($item) ? $item : [], array_merge($np, [$i]), array_merge($nt, [$ph]), $errors, $ep . '.' . $i, false);
    $o .= '</div><template class="rep-tpl">' . rep_item($fd, $fd['new'] ?? [], array_merge($nt, [$ph]), array_merge($nt, [$ph]), [], '', true) . '</template>';
    return $o . '<button type="button" class="btn sm rep-add">+ ' . e($fd['add'] ?? 'Ekle') . '</button></div>';
}
function rep_item(array $fd, array $item, array $np, array $nt, array $errors, string $ep, bool $isNew): string {
    $hasErr = !empty($fd['open']);                       // 'open': items start unfolded (short items such as invoice lines)
    foreach ($errors as $p => $_) if ($ep !== '' && str_starts_with($p, $ep . '.')) { $hasErr = true; break; }
    return '<div class="rep-item' . ($isNew ? ' is-new' : '') . ($hasErr ? ' open' : '') . '" data-tk="' . e((string)($fd['title'] ?? '')) . '"><div class="rep-head"><button type="button" class="rep-tog" aria-expanded="' . ($hasErr || $isNew ? 'true' : 'false') . '"><span class="rep-t">' . e(rep_title($fd, $item)) . '</span></button>'
        . '<span class="rep-tools"><button type="button" class="ib rep-up" title="Yukarı">↑</button><button type="button" class="ib rep-down" title="Aşağı">↓</button><button type="button" class="ib rep-del" title="Sil">✕</button></span></div>'
        . '<div class="rep-body">' . render_fields($fd['fields'], $item, $np, $nt, $errors, $ep) . '</div></div>';
}
function render_gallery(array $fd, array $items, array $np, array $nt, array $errors, string $ep): string {
    $level = count(array_filter($nt, fn($x) => preg_match('/^\{\d+\}$/', (string)$x)));
    $ph = '{' . $level . '}';
    $one = function (array $v, array $p, array $t) use ($fd) {
        return '<div class="gal-item" draggable="false"><div class="gal-tools"><span class="drag" title="Sürükleyin">⋮⋮</span><button type="button" class="ib rep-up" title="Öne al">↑</button><button type="button" class="ib rep-down" title="Geri al">↓</button><button type="button" class="ib rep-del" title="Sil">✕</button></div>'
            . render_image($v, $p, $t, $fd, 'g' . bin2hex(random_bytes(3)), true) . '</div>';
    };
    $o = '<div class="gal" data-kind="' . e($fd['kind'] ?? 'project') . '"><div class="gal-items rep-items">';
    foreach ($items as $i => $v) $o .= $one(is_array($v) ? $v : [], array_merge($np, [$i]), array_merge($nt, [$ph]));
    $o .= '</div><template class="gal-tpl">' . $one(['file' => '', 'alt' => '', 'caption' => '', 'pos' => '50% 50%'], array_merge($nt, [$ph]), array_merge($nt, [$ph])) . '</template>';
    return $o . '<div class="gal-drop"><div class="gal-btns"><label class="btn">+ Fotoğraf yükle (birden fazla seçebilirsiniz)<input type="file" accept="image/jpeg,image/png,image/webp" multiple class="gal-up" hidden></label>'
        . '<button type="button" class="btn gal-pick">' . aic('image') . '<span>Galeriden ekle</span></button></div><span class="gal-st" aria-live="polite"></span><div class="help">Fotoğrafları buraya sürükleyip bırakabilirsiniz. Telefon fotoğrafları otomatik küçültülür. Galeriye daha önce eklediğiniz fotoğrafları "Galeriden ekle" ile seçin.</div></div></div>';
}
function render_menu(array $items, array $np, array $nt): string {
    $types = ['home' => 'Ana sayfa', 'services' => 'Hizmetler (açılır liste)', 'areas' => 'Bölgeler (açılır liste)', 'projects' => 'Projeler (proje varsa görünür)', 'faq' => 'SSS sayfası', 'about' => 'Hakkımızda', 'contact' => 'İletişim', 'link' => 'Özel bağlantı'];
    $row = function (array $m, $i, bool $tpl) use ($np, $nt, $types) {
        $p = $tpl ? array_merge($nt, ['{0}']) : array_merge($np, [$i]);
        $t = array_merge($nt, ['{0}']);
        $n = fn(string $s) => e(fld_name(array_merge($p, [$s])));
        $d = fn(string $s) => e(fld_name(array_merge($t, [$s])));
        $type = (string)($m['type'] ?? 'link');
        $isLink = $type === 'link';
        return '<div class="rep-item menu-row' . ($isLink ? ' is-link' : '') . '"><div class="mr">'
            . '<span class="mr-type">' . e($types[$type] ?? $type) . '</span><input type="hidden" name="' . $n('type') . '" data-n="' . $d('type') . '" value="' . e($type) . '">'
            . '<label class="mini">Menüdeki adı<input type="text" name="' . $n('label') . '" data-n="' . $d('label') . '" value="' . e((string)($m['label'] ?? '')) . '" required></label>'
            . ($isLink ? '<label class="mini">Bağlantı<input type="text" name="' . $n('link') . '" data-n="' . $d('link') . '" value="' . e((string)($m['link'] ?? '')) . '" placeholder="https://... veya sayfa.html"></label>' : '')
            . '<label class="mcheck"><input type="hidden" name="' . $n('show') . '" data-n="' . $d('show') . '" value="0"><input type="checkbox" name="' . $n('show') . '" data-n="' . $d('show') . '" value="1"' . (!isset($m['show']) || !empty($m['show']) ? ' checked' : '') . '> Göster</label>'
            . '<span class="rep-tools"><button type="button" class="ib rep-up" title="Yukarı">↑</button><button type="button" class="ib rep-down" title="Aşağı">↓</button>' . ($isLink ? '<button type="button" class="ib rep-del" title="Sil">✕</button>' : '') . '</span></div></div>';
    };
    $o = '<div class="rep" data-min="1"><div class="rep-items">';
    foreach ($items as $i => $m) $o .= $row(is_array($m) ? $m : [], $i, false);
    $o .= '</div><template class="rep-tpl">' . $row(['type' => 'link', 'label' => '', 'link' => '', 'show' => true], 0, true) . '</template>';
    return $o . '<button type="button" class="btn sm rep-add">+ Özel bağlantı ekle</button></div>';
}

/* ======================= reading + validation ======================= */
function validate_value(array $fd, string $v, string $ep, array &$errors): string {
    if (!empty($fd['req']) && $v === '') { $errors[$ep] = 'Bu alan boş bırakılamaz.'; return $v; }
    if (isset($fd['max']) && mb_strlen($v) > (int)$fd['max']) $errors[$ep] = 'En fazla ' . (int)$fd['max'] . ' karakter.';
    if ($v === '' || !isset($fd['v'])) return $v;
    switch ($fd['v']) {
        case 'email': if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$ep] = 'Geçerli bir e-posta adresi girin.'; break;
        case 'phone': if (strlen(preg_replace('/\D+/', '', $v)) < 7) $errors[$ep] = 'Geçerli bir telefon numarası girin.'; break;
        case 'wa': $d = preg_replace('/\D+/', '', $v); if (strlen($d) < 8 || strlen($d) > 15) $errors[$ep] = 'Ülke koduyla birlikte 8–15 rakam girin (örnek 16479193677).'; else $v = $d; break;
        case 'url': if (!preg_match('#^https?://[^\s<>"]+$#i', $v)) $errors[$ep] = 'https:// ile başlayan tam bir adres girin.'; break;
        case 'link': if (!preg_match('#^(https?://[^\s<>"]+|tel:\+?[0-9]+|mailto:[^\s<>"]+|[a-z0-9][a-z0-9\-]*\.html(\#[a-z0-9\-]+)?|\#[a-z0-9\-]+)$#i', $v)) $errors[$ep] = 'Bir sayfa adı (ornek.html) veya https:// ile başlayan adres girin.'; break;
        case 'slug': $s = slugify($v); if ($s === '') $errors[$ep] = 'Sadece harf, rakam ve tire kullanın.'; else $v = $s; break;
        case 'ga4': $v = strtoupper($v); if (!preg_match('/^G-[A-Z0-9]{4,14}$/', $v)) $errors[$ep] = 'Kimlik G- ile başlamalı (örnek G-ABC123XYZ).'; break;
        case 'gsv': if (preg_match('/content="([^"]+)"/', $v, $m)) $v = $m[1]; if (!preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $v)) $errors[$ep] = 'Sadece doğrulama kodunu yapıştırın (content="…" içindeki kısım).'; break;
        case 'time': if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) $errors[$ep] = 'Saat 24 saat biçiminde olmalı (08:00).'; break;
    }
    return $v;
}
function clean_text($raw): string { return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace(["\r\n", "\r"], "\n", (string)$raw)) ?? ''); }
function clean_file($raw): string {
    $f = trim((string)$raw);
    if ($f === '') return '';
    if (!preg_match('#^assets/[A-Za-z0-9/_\-.]+$#', $f) || str_contains($f, '..')) return '';
    return $f;
}
/** Read the posted values for $fields into $data (a copy of the content or of one item). */
function parse_fields(array $fields, array $post, array $data, array &$errors, string $ep): array {
    foreach ($fields as $fd) {
        if ($fd['t'] === 'heading') continue;
        $k = $fd['k'];
        $p = ($ep === '' ? '' : $ep . '.') . $k;
        $has = array_key_exists($k, $post);
        $raw = $post[$k] ?? null;
        switch ($fd['t']) {
            case 'text':
                if (!$has) break;
                path_set($data, $k, validate_value($fd, str_replace("\n", ' ', clean_text($raw)), $p, $errors)); break;
            case 'textarea':
                if (!$has) break;
                path_set($data, $k, validate_value($fd, clean_text($raw), $p, $errors)); break;
            case 'date':
                if (!$has) break;
                $v = trim((string)$raw);
                if ($v === '') { if (!empty($fd['req'])) $errors[$p] = 'Bir tarih seçin.'; path_set($data, $k, ''); break; }
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) { $errors[$p] = 'Geçerli bir tarih seçin (YYYY-AA-GG).'; break; }
                path_set($data, $k, $v); break;
            case 'code':
                if (!$has) break;
                $code = parse_code($fd, (string)$raw, $p, $errors);
                if ($code !== null) path_set($data, $k, $code);
                break;
            case 'themecode':
                if (!$has) break;
                $code = parse_code(['lang' => ($fd['lang'] ?? 'css') === 'css' ? 'css' : 'html'], (string)$raw, $p, $errors);
                if ($code === null) break;
                $orig = theme_original((string)$fd['src']);
                if ($code === trim(str_replace(["\r\n", "\r"], "\n", $orig)) || $code === '') { path_set($data, $k, ''); path_set($data, $k . '_base', ''); break; }   // unchanged: keep following the code updates
                if (($fd['lang'] ?? '') === 'html' && !preg_match('#^(\s*<script\b[^>]*>.*?</script>)+\s*$#is', $code)) { $errors[$p] = 'JavaScript kodu <script> … </script> blokları içinde kalmalı.'; break; }
                path_set($data, $k, $code); path_set($data, $k . '_base', sha1($orig));
                break;
            case 'lines':
                if (!$has) break;
                $lines = array_values(array_filter(array_map('trim', explode("\n", clean_text($raw))), 'strlen'));
                if (!empty($fd['req']) && !$lines) $errors[$p] = 'En az bir satır girin.';
                path_set($data, $k, $lines); break;
            case 'check':
                path_set($data, $k, !empty($raw)); break;
            case 'number':
                if (!$has) break;
                $s = str_replace(',', '.', trim((string)$raw));
                if ($s === '') { if (!empty($fd['req'])) $errors[$p] = 'Bu alan boş bırakılamaz.'; path_set($data, $k, !empty($fd['int']) ? 0 : 0.0); break; }
                if (!is_numeric($s)) { $errors[$p] = 'Sayı girin.'; break; }
                $n = !empty($fd['int']) ? (int)round((float)$s) : (float)$s;
                if (isset($fd['min']) && $n < $fd['min']) $errors[$p] = 'En az ' . $fd['min'] . '.';
                if (isset($fd['max']) && $n > $fd['max']) $errors[$p] = 'En fazla ' . $fd['max'] . '.';
                path_set($data, $k, $n); break;
            case 'select':
                if (!$has) break;
                $v = (string)$raw;
                if (!array_key_exists($v, $fd['opts'] ?? [])) { $errors[$p] = 'Listeden bir seçim yapın.'; break; }
                path_set($data, $k, $v); break;
            case 'icon':
                if (!$has) break;
                $v = (string)$raw;
                path_set($data, $k, isset(icons()[$v]) ? $v : 'check'); break;
            case 'image':
                if (!$has || !is_array($raw)) break;
                $old = path_get($data, $k, []);
                $img = ['file' => clean_file($raw['file'] ?? ''), 'alt' => str_replace("\n", ' ', clean_text($raw['alt'] ?? '')), 'pos' => preg_match('/^\d{1,3}% \d{1,3}%$/', (string)($raw['pos'] ?? '')) ? (string)$raw['pos'] : '50% 50%'];
                if (isset($raw['key'])) $img['key'] = (string)$raw['key']; elseif (is_array($old) && isset($old['key'])) $img['key'] = $old['key'];
                if ($img['file'] !== '' && empty($fd['noalt']) && $img['alt'] === '') $errors[$p . '.alt'] = 'Fotoğraf için alternatif metin yazın (SEO için gerekli).';
                path_set($data, $k, $img); break;
            case 'logofile':
                if (!$has) break;
                path_set($data, $k, clean_file($raw)); break;
            case 'repeater':
                $items = [];
                foreach (is_array($raw) ? array_values($raw) : [] as $i => $it) {
                    if (!is_array($it)) continue;
                    $items[] = parse_fields($fd['fields'], $it, rep_defaults($fd), $errors, $p . '.' . $i);
                }
                if (isset($fd['min']) && count($items) < (int)$fd['min']) $errors[$p] = 'En az ' . (int)$fd['min'] . ' öğe olmalı.';
                if (!empty($fd['max_items']) && count($items) > (int)$fd['max_items']) $items = array_slice($items, 0, (int)$fd['max_items']);
                path_set($data, $k, $items); break;
            case 'gallery':
                $items = [];
                foreach (is_array($raw) ? array_values($raw) : [] as $i => $it) {
                    if (!is_array($it)) continue;
                    $f = clean_file($it['file'] ?? '');
                    if ($f === '') continue;
                    $alt = str_replace("\n", ' ', clean_text($it['alt'] ?? ''));      // empty: the site uses the project title
                    $items[] = ['file' => $f, 'alt' => $alt, 'caption' => str_replace("\n", ' ', clean_text($it['caption'] ?? '')), 'pos' => preg_match('/^\d{1,3}% \d{1,3}%$/', (string)($it['pos'] ?? '')) ? (string)$it['pos'] : '50% 50%'];
                }
                path_set($data, $k, $items); break;
            case 'menu':
                $items = [];
                foreach (is_array($raw) ? array_values($raw) : [] as $i => $it) {
                    $type = (string)($it['type'] ?? '');
                    if (!in_array($type, ['home', 'services', 'areas', 'projects', 'faq', 'about', 'contact', 'link'], true)) continue;
                    $label = str_replace("\n", ' ', clean_text($it['label'] ?? ''));
                    if ($label === '') $errors[$p . '.' . $i . '.label'] = 'Menü adı boş olamaz.';
                    $row = ['type' => $type, 'label' => $label, 'show' => !empty($it['show'])];
                    if ($type === 'link') {
                        $row['link'] = validate_value(['v' => 'link', 'req' => true], clean_text($it['link'] ?? ''), $p . '.' . $i . '.link', $errors);
                    }
                    $items[] = $row;
                }
                foreach (['home', 'services', 'areas', 'projects', 'faq', 'about', 'contact'] as $need)      // fixed items can be hidden, not lost
                    if (!in_array($need, array_column($items, 'type'), true)) $items[] = ['type' => $need, 'label' => ucfirst($need), 'show' => false];
                path_set($data, $k, $items); break;
            case 'logo': case 'flogo': case 'favicon':
                break;      // handled by the brand hidden inputs below
        }
    }
    return $data;
}
/**
 * Code fields (custom CSS / JS / HTML). The panel's script sends them base64-encoded ("b64:…") so a hosting firewall that
 * blocks form posts containing <script> never gets in the way; plain text is accepted as well.
 */
function parse_code(array $fd, string $v, string $p, array &$errors): ?string {
    if (str_starts_with($v, 'b64:')) {
        $d = base64_decode(substr($v, 4), true);
        if ($d === false || !mb_check_encoding($d, 'UTF-8')) { $errors[$p] = 'Kod okunamadı; sayfayı yenileyip tekrar deneyin.'; return null; }
        $v = $d;
    }
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $v)) ?? '');
    $lang = $fd['lang'] ?? 'html';
    // pasted with its own <style> / <script> wrapper: keep only what is inside
    if ($lang === 'css' && preg_match('#^<style\b[^>]*>(.*)</style>$#is', $v, $m) && stripos($m[1], '<style') === false) $v = trim($m[1]);
    if ($lang === 'js' && preg_match('#^<script\b([^>]*)>(.*)</script>$#is', $v, $m) && stripos($m[2], '<script') === false && stripos($m[1], 'src=') === false) $v = trim($m[2]);
    if ($lang === 'css' && preg_match('#<(style|script|link|meta)\b#i', $v)) $errors[$p] = 'Bu alana sadece CSS yazın. <link> veya <script> içeren kodları aşağıdaki "<head> içine" alanına yapıştırın.';
    if ($lang === 'js' && preg_match('#<script\b#i', $v)) $errors[$p] = 'Bu alana sadece JavaScript yazın. <script> etiketli hazır kodları (Meta Pixel, Tag Manager vb.) aşağıdaki HTML alanlarına yapıştırın.';
    if (mb_strlen($v) > 200000) $errors[$p] = 'Kod çok uzun (en fazla 200.000 karakter).';
    return $v;
}
/** The site's own code as shipped (theme files): CSS without its <style> wrapper, the scripts as they are. */
function theme_original(string $src): string {
    $s = trim(str_replace(["\r\n", "\r"], "\n", theme($src)));
    if ($src === 'style.html' && preg_match('#^<style\b[^>]*>(.*)</style>$#s', $s, $m)) $s = trim($m[1]);
    return $s;
}
function rep_defaults(array $fd): array { return is_array($fd['new'] ?? null) ? $fd['new'] : []; }
/** Branding hidden inputs (logo uploads) are plain top-level paths. */
function parse_brand(array $post, array $data): array {
    $map = ['branding.header_logo_png', 'branding.header_logo_webp', 'branding.footer_logo', 'branding.favicon_32', 'branding.favicon_48', 'branding.apple_touch_icon', 'site.schema_image'];
    foreach ($map as $k) if (array_key_exists($k, $post)) { $f = clean_file($post[$k]); if ($f !== '') path_set($data, $k, $f); }
    if (isset($post['branding.header_logo_ratio']) && is_numeric($post['branding.header_logo_ratio']) && (float)$post['branding.header_logo_ratio'] > 0.3 && (float)$post['branding.header_logo_ratio'] < 8)
        path_set($data, 'branding.header_logo_ratio', round((float)$post['branding.header_logo_ratio'], 3));
    foreach (['branding.footer_logo_w', 'branding.footer_logo_h'] as $k) if (isset($post[$k]) && ctype_digit((string)$post[$k]) && (int)$post[$k] > 0) path_set($data, $k, (int)$post[$k]);
    if (!empty($post['site.schema_image'])) { $f = clean_file($post['site.schema_image']); if ($f !== '') path_set($data, 'site.og_image', $f); }
    return $data;
}
