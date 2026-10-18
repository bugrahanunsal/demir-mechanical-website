<?php
/**
 * Demir Mechanical website — rendering helpers shared by the page builders (pages.php) and the admin panel.
 * Pages are plain static HTML written to the site root; this code only runs when the admin saves (or from the CLI build).
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const DM_VERSION = '2026.10.18.5';

/** Python html.escape(s, quote=True) — used where the original templates escaped data. */
function h($s): string {
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#x27;'], (string)$s);
}
/** Text written into the page as-is in the original templates: escape markup characters, keep apostrophes. */
function t($s): string {
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], (string)$s);
}
/** Fill {placeholders} in an editable sentence. */
function fill(string $tpl, array $vars): string {
    $r = [];
    foreach ($vars as $k => $v) $r['{' . $k . '}'] = (string)$v;
    return strtr($tpl, $r);
}
function slugify(string $s): string {
    $s = strtr($s, ['ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c', '&' => ' and ', "'" => '', '’' => '', '‘' => '']);
    if (function_exists('iconv')) { $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($x !== false) $s = $x; }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/** JSON exactly like Python json.dumps(obj, ensure_ascii=False) (", " and ": " separators, "/" not escaped). */
function pyjson($v): string {
    if (is_array($v)) {
        if ($v === [] ) return '[]';
        if (array_is_list($v)) return '[' . implode(', ', array_map('pyjson', $v)) . ']';
        $parts = [];
        foreach ($v as $k => $x) $parts[] = pystr((string)$k) . ': ' . pyjson($x);
        return '{' . implode(', ', $parts) . '}';
    }
    if (is_string($v)) return pystr($v);
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null) return 'null';
    if (is_int($v)) return (string)$v;
    if (is_float($v)) {
        $s = json_encode($v);               // shortest round-trip form, like Python repr
        return (strpos($s, '.') === false && strpos($s, 'e') === false) ? $s . '.0' : $s;
    }
    return pystr((string)$v);
}
function pystr(string $s): string {
    $out = '"';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i]; $o = ord($c);
        if ($c === '"') $out .= '\\"';
        elseif ($c === '\\') $out .= '\\\\';
        elseif ($c === "\n") $out .= '\\n';
        elseif ($c === "\r") $out .= '\\r';
        elseif ($c === "\t") $out .= '\\t';
        elseif ($c === "\x08") $out .= '\\b';
        elseif ($c === "\x0c") $out .= '\\f';
        elseif ($o < 0x20) $out .= sprintf('\\u%04x', $o);
        else $out .= $c;
    }
    // "</script" inside JSON-LD would end the script element early
    return str_replace('</', '<\\/', $out) . '"';
}
function ld($o): string { return '<script type="application/ld+json">' . pyjson($o) . '</script>'; }

/** New settings added in later versions come from the defaults; the owner's values and lists always win. */
function merge_defaults(array $def, array $cur): array {
    foreach ($def as $k => $v) {
        if (!array_key_exists($k, $cur)) { $cur[$k] = $v; continue; }
        if (is_array($v) && is_array($cur[$k]) && !array_is_list($v) && !array_is_list($cur[$k]) && $v !== []) $cur[$k] = merge_defaults($v, $cur[$k]);
    }
    return $cur;
}

/* ---------------- theme files ---------------- */
function theme(string $name): string {
    static $cache = [];
    return $cache[$name] ??= (string)file_get_contents(DM_APP . '/theme/' . $name);
}
function icons(): array {
    static $i = null;
    return $i ??= json_decode(theme('icons.json'), true);
}
/** Line icon (24x24, stroke). */
function ic(string $k): string {
    $d = icons()[$k] ?? icons()['check'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}
function fixed(string $k): string {
    static $f = null;
    $f ??= json_decode(theme('fixed.json'), true);
    return $f[$k] ?? '';
}

/* ---------------- content helpers ---------------- */
function C(): array { return $GLOBALS['DM_C']; }
function cget(string $path, $default = '') {
    $v = C();
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}
function phone(): string { return (string)cget('contact.phone'); }
function phone_digits(): string { return preg_replace('/\D+/', '', phone()) ?? ''; }
function tel(): string { return 'tel:+' . phone_digits(); }
/** "+1-647-919-3677" for North-American numbers, "+<digits>" otherwise. */
function phone_schema(): string {
    $d = phone_digits();
    if (strlen($d) === 11 && $d[0] === '1') return '+1-' . substr($d, 1, 3) . '-' . substr($d, 4, 3) . '-' . substr($d, 7);
    return '+' . $d;
}
/** Phone as shown in the phone header button: without the "+1 " country code. */
function phone_local(): string { return str_replace('+1 ', '', phone()); }
function site_url(): string { return rtrim((string)cget('site.url'), '/'); }
function brand(): string { return (string)cget('site.brand', 'Demir Mechanical & Construction'); }
function short_name(): string { return (string)cget('site.short_name', 'Demir Mechanical'); }
function addr_full(): string { $c = C()['contact']; return "{$c['street']}, {$c['city']}, {$c['region']} {$c['postal']}"; }
function maps_link(): string { $c = C()['contact']; return 'https://maps.google.com/?q=' . str_replace(' ', '+', "{$c['street']} {$c['city']} {$c['region_long']}"); }
function maps_link_full(): string { $c = C()['contact']; return 'https://maps.google.com/?q=' . str_replace(' ', '+', "{$c['street']} {$c['city']} {$c['region']} {$c['postal']}"); }
function vars(array $more = []): array {
    $c = C()['contact'];
    return $more + ['phone' => phone(), 'email' => $c['email'], 'street' => $c['street'], 'city' => $c['city'],
                    'region' => $c['region'], 'region_long' => $c['region_long'], 'postal' => $c['postal'],
                    'brand' => brand(), 'name' => short_name(), 'count' => count(C()['services'])];
}
function services(): array { return C()['services']; }
function cities(): array { return C()['cities']; }
function city_slug(string $name): string { return 'plumber-' . str_replace(' ', '-', strtolower($name)); }

/** Email link attributes: the address is never written into the page (the page script assembles it). */
function maila(string $email = ''): string {
    $e = $email !== '' ? $email : (string)cget('contact.email');
    [$u, $d] = array_pad(explode('@', $e, 2), 2, '');
    return 'href="contact.html" data-mu="' . t(strrev($u)) . '" data-md="' . t(strrev($d)) . '"';
}
const MAILT = '<span class="em-t">Email us</span>';

/* ---------------- WhatsApp ---------------- */
function wa_number(): string { return preg_replace('/\D+/', '', (string)cget('links.whatsapp')) ?? ''; }
function wa_on(): bool { return strlen(wa_number()) >= 8; }
function wa_text_for(string $path): string {
    $slug = preg_replace('/\.html$/', '', $path);
    foreach (services() as $s) if ($s['slug'] === $slug) return fill((string)cget('whatsapp.service'), ['service' => $s['name']]);
    foreach (cities() as $c) if ($c['slug'] === $slug) return fill((string)cget('whatsapp.city'), ['city' => $c['name']]);
    return (string)cget('whatsapp.general');
}
/** Like Python urllib.parse.quote(s): everything except letters, digits, "_.-~" and "/" is %-encoded. */
function pyquote(string $s): string { return str_replace('%2F', '/', rawurlencode($s)); }
function wa_href(string $path): string { return 'https://wa.me/' . wa_number() . '?text=' . pyquote(wa_text_for($path)); }
const WAA = 'href="__WAHREF__" target="_blank" rel="noopener" data-wa';

/* ---------------- images ---------------- */
function img_size(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $p = DM_ROOT . '/' . $file;
    $s = ($file !== '' && is_file($p)) ? @getimagesize($p) : false;
    return $cache[$file] = $s ? [(int)$s[0], (int)$s[1]] : [0, 0];
}
function img_exists($img): bool { return is_array($img) && ($img['file'] ?? '') !== '' && is_file(DM_ROOT . '/' . $img['file']); }
/** The 700px-wide copy that sits next to a photo ("name-sm.webp"). */
function img_small(string $file): string { return preg_replace('/\.[a-z0-9]+$/i', '', $file) . '-sm.webp'; }

const SZ_CARD = '(max-width: 600px) 100vw, (max-width: 1160px) 50vw, 300px';
const SZ_WIDE = '(max-width: 1160px) 100vw, 720px';
const SZ_HALF = '(max-width: 1160px) 100vw, 560px';
const SZ_GAL  = '(max-width: 600px) 100vw, (max-width: 1160px) 50vw, 400px';

function img_tag($img, string $sizes = SZ_WIDE, bool $eager = false, string $cls = 'ph', ?string $alt = null): string {
    if (!img_exists($img)) return '';
    $src = $img['file'];
    [$w, $hh] = img_size($src);
    $alt = $alt ?? (string)($img['alt'] ?? '');
    $sm = img_small($src);
    $ss = ($w > 760 && is_file(DM_ROOT . '/' . $sm)) ? ' srcset="' . $sm . ' 700w, ' . $src . ' ' . $w . 'w" sizes="' . $sizes . '"' : '';
    $pos = (string)($img['pos'] ?? '');
    $st = ($pos !== '' && $pos !== '50% 50%') ? ' style="object-position:' . t($pos) . '"' : '';
    $ldg = $eager ? ' fetchpriority="high"' : ' loading="lazy" decoding="async"';
    return '<img class="' . $cls . '" src="' . t($src) . '"' . $ss . ' alt="' . h($alt) . '" width="' . $w . '" height="' . $hh . '"' . $ldg . $st . '>';
}
