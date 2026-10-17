<?php
/**
 * Demir Mechanical website — page builders. Every public page is built from the content file (data/content.json)
 * with the theme in app/theme/. build_site() returns path => HTML for the live site or for the Claude preview.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

const PGIN = '<script>document.documentElement.classList.add("pg-in")</script>';
const CARET = '<svg class="car" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';

function icons_head(): string {
    $b = C()['branding'];
    return '<link rel="icon" type="image/png" sizes="32x32" href="' . t($b['favicon_32']) . '"><link rel="icon" type="image/png" sizes="48x48" href="' . t($b['favicon_48']) . '"><link rel="apple-touch-icon" href="' . t($b['apple_touch_icon']) . '"><meta name="theme-color" content="' . t(cget('site.theme_color', '#ffffff')) . '">';
}
function css(): string { return theme('style.html'); }
function scripts(): string {
    return strtr(theme('script.html'), ['__SITE__' => site_url(), '__GA4__' => preg_match('/^G-[A-Z0-9]{4,}$/i', (string)cget('links.ga4')) ? (string)cget('links.ga4') : '']);
}

/* ---------------- shared pieces ---------------- */
function mark(int $hgt, string $cls = ''): string {
    $b = C()['branding'];
    $w = (int)round($hgt * (float)$b['header_logo_ratio']);
    $src = ($b['header_logo_webp'] ?? '') !== '' ? '<source srcset="' . t($b['header_logo_webp']) . '" type="image/webp">' : '';
    return '<picture>' . $src . '<img' . ($cls !== '' ? ' class="' . $cls . '"' : '') . ' src="' . t($b['header_logo_png']) . '" alt="' . t($b['header_logo_alt']) . '" width="' . $w . '" height="' . $hgt . '" decoding="async"></picture>';
}

function nav(string $active, string $path): string {
    $H = C()['header'];
    $on = fn(string $u) => $u === $path ? ' class="on" aria-current="page"' : '';
    $home = $active === 'home' ? '#home' : 'index.html';
    $c = fn(string $n) => $active === $n ? ' active' : '';
    $mega = '';
    foreach (services() as $s) $mega .= '<a href="' . $s['slug'] . '.html"' . $on($s['slug'] . '.html') . '>' . h($s['name']) . '</a>';
    $cols = '';
    foreach (cities() as $x) $cols .= '<a href="' . $x['slug'] . '.html"' . $on($x['slug'] . '.html') . '>' . t($x['name']) . '</a>';
    $items = '';
    foreach ($H['menu'] as $m) {
        if (empty($m['show'])) continue;
        $label = t($m['label']);
        switch ($m['type']) {
            case 'home': $items .= '<div class="item"><a class="lk' . $c('home') . '" href="' . $home . '">' . $label . "</a></div>\n"; break;
            case 'services':
                $items .= '<div class="item services"><button class="lk' . $c('services') . '" aria-expanded="false" aria-haspopup="true">' . $label . ' ' . CARET . "</button>\n"
                    . '<div class="drop mega">' . $mega . '<div class="allrow"><a href="services.html"' . $on('services.html') . '>' . t($H['services_all']) . " <span>→</span></a></div></div></div>\n";
                break;
            case 'areas':
                $items .= '<div class="item areas"><button class="lk' . $c('areas') . '" aria-expanded="false" aria-haspopup="true">' . $label . ' ' . CARET . "</button>\n"
                    . '<div class="drop areacols">' . $cols . '<div class="allrow"><a href="service-areas.html"' . $on('service-areas.html') . '>' . t($H['areas_all']) . " <span>→</span></a></div></div></div>\n";
                break;
            case 'projects':
                if (projects()) $items .= '<div class="item"><a class="lk' . $c('projects') . '" href="projects.html">' . $label . "</a></div>\n";
                break;
            case 'faq': case 'about': case 'contact':
                $items .= '<div class="item"><a class="lk' . $c($m['type']) . '" href="' . $m['type'] . '.html">' . $label . "</a></div>\n"; break;
            case 'link':
                $u = (string)($m['link'] ?? '');
                $ext = preg_match('#^https?://#i', $u) && stripos($u, site_url()) !== 0;
                $items .= '<div class="item"><a class="lk' . ($u === $path ? ' active' : '') . '" href="' . t($u) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . $label . "</a></div>\n";
                break;
        }
    }
    $ct = C()['contact'];
    $waRow = wa_on() ? '<a class="d-r" ' . WAA . '>' . ic('chat') . '<span><small>' . t(cget('whatsapp.menu_label')) . '</small><span class="one">' . t(cget('whatsapp.menu_text')) . '</span></span></a>' : '';
    return '<div class="util"><div class="wrap"><span class="u-item">' . ic('shield') . t($H['util_left']) . "</span>\n"
        . '<div class="u-right"><span class="u-item">' . ic('clock') . t($ct['hours_short']) . '</span><a class="u-item" ' . maila() . '>' . ic('mail') . MAILT . "</a></div></div></div>\n"
        . "<header class=\"mainbar\"><div class=\"wrap\">\n"
        . '<a href="' . $home . '" class="brand">' . mark(50) . "</a>\n"
        . "<nav class=\"navlinks\" id=\"navlinks\" aria-label=\"Primary\">\n"
        . '<div class="drawer-head"><a href="' . $home . '" class="d-brand" aria-label="' . t(brand()) . ' – Home">' . mark(40) . '</a><button class="d-close" id="dClose" aria-label="Close menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>' . "</div>\n"
        . $items
        . '<div class="drawer-foot"><div class="d-info"><a class="d-r" href="' . tel() . '">' . ic('phone') . '<span><small>' . t($H['emergency_label']) . '</small>' . t(phone()) . '</span></a>' . $waRow
        . '<a class="d-r" ' . maila() . '>' . ic('mail') . '<span><small>' . t($H['drawer_email_label']) . '</small><span class="one em-t">Email us</span></span></a>'
        . '<a class="d-r" href="' . t(maps_link_full()) . '" target="_blank" rel="noopener">' . ic('pin') . '<span><small>' . t($H['drawer_address_label']) . '</small><span class="one">' . t($ct['address_short']) . '</span></span></a></div>'
        . '<a href="' . t($H['cta_link']) . '" class="btn btn-white">' . t($H['cta_label']) . "</a></div>\n"
        . "</nav>\n"
        . "<div class=\"nav-backdrop\" id=\"navBackdrop\"></div>\n"
        . '<div class="h-right"><a class="h-call" href="' . tel() . '"><span class="ic">' . ic('phone') . '</span><span><span class="lbl">' . t($H['emergency_label']) . '</span><span class="num">' . t(phone()) . "</span></span></a>\n"
        . '<a href="' . t($H['cta_link']) . '" class="btn btn-primary btn-sm">' . t($H['cta_label']) . "</a></div>\n"
        . '<a class="m-call" href="' . tel() . '" aria-label="Call ' . t(phone()) . '"><span class="mi">' . ic('phone') . '</span><span class="mt"><small>' . t($H['emergency_label']) . '</small><b>' . t(phone_local()) . "</b></span></a>\n"
        . '<button class="burger" id="burger" aria-label="Open menu" aria-expanded="false" aria-controls="navlinks"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>' . "\n"
        . '</div></header>';
}

/** Map + info bar on the live site; the Claude preview (which cannot show other sites' iframes) gets a placeholder. */
function gmap(string $title, string $sub, int $z, string $style = ''): string {
    $q = str_replace([' ', ','], ['+', '%2C'], addr_full());
    $src = 'https://maps.google.com/maps?q=' . $q . '&amp;z=' . $z . '&amp;output=embed';
    $ct = C()['contact'];
    if (!empty($GLOBALS['DM_PREVIEW'])) {
        $inner = $style !== '' ? '<b>' . t("{$ct['street']}, {$ct['city']}, {$ct['region']}") . '</b>' . t(cget('pages.areas.map_text'))
                               : '<b>' . t(cget('pages.contact.map_title')) . '</b>' . t("Based in {$ct['city']}, {$ct['region_long']}");
        return '<div class="mapwrap"' . $style . '><div class="mapph"><div class="in">' . ic('pin') . $inner . ' · <a href="' . t(maps_link()) . '">Open in Google Maps →</a></div></div></div>';
    }
    return '<div class="mapwrap"' . $style . '><iframe class="gmap" src="' . $src . '" title="Google map: ' . t(brand()) . ', ' . t($ct['street']) . ', ' . t($ct['city']) . ', ' . t($ct['region_long']) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>'
        . '<div class="mapbar"><span class="mb-ic">' . ic('pin') . '</span><span class="mb-t"><b>' . $title . '</b><small>' . $sub . '</small></span>'
        . '<a class="mb-a" href="' . t(maps_link()) . '" target="_blank" rel="noopener">Open in Google Maps ' . ic('arrow') . '</a></div></div>';
}

function emer(): string {
    $e = C()['blocks']['emergency'];
    return '<div class="emer"><div class="wrap"><div class="e-ic">' . ic('alert') . '</div><div><h3>' . t($e['title']) . '</h3><p>' . t($e['text']) . '</p></div><a href="' . tel() . '" class="btn btn-white">' . ic('phone') . t(fill($e['button'], vars())) . '</a></div></div>';
}
function process_steps(): string {
    $cards = '';
    foreach (C()['blocks']['process'] as $i => $s)
        $cards .= '<div class="step"><div class="n">STEP ' . sprintf('%02d', $i + 1) . '</div><div class="si">' . ic($s['icon']) . '</div><h3>' . t($s['title']) . '</h3><p>' . t($s['text']) . '</p></div>';
    return '<div class="process">' . $cards . '</div>';
}
function cta(): string {
    $c = C()['blocks']['cta'];
    return '<section class="cta"><div class="wrap"><div><span class="eyebrow on">' . t($c['eyebrow']) . '</span><h2 style="margin-top:14px">' . t($c['title']) . '</h2><p>' . t($c['text']) . "</p></div>\n"
        . '<div class="cta-act"><a class="cta-phone" href="' . tel() . '">' . ic('phone') . '<span><small>' . t($c['phone_label']) . '</small><b>' . t(phone()) . '</b></span></a><a href="contact.html" class="btn btn-primary">' . t($c['button']) . '</a></div></div></section>';
}
function trust(): string {
    $o = "<div class=\"trust\"><div class=\"wrap\">\n";
    $n = count(C()['blocks']['trust']);
    foreach (C()['blocks']['trust'] as $i => $x)
        $o .= '<div class="t"><span class="ti">' . ic($x['icon']) . '</span><span><b>' . t($x['title']) . '</b><span>' . t($x['text']) . '</span></span></div>' . ($i < $n - 1 ? "\n" : '');
    return $o . '</div></div>';
}
function problems(): string {
    $o = '';
    foreach (C()['blocks']['problems'] as $p) $o .= '<span class="prob">' . ic($p['icon']) . t($p['text']) . '</span>';
    return $o;
}
function social_links(): array {
    $o = [];
    foreach (['facebook', 'instagram', 'google'] as $k) {
        $u = trim((string)cget('links.' . $k));
        if (preg_match('#^https?://\S+$#i', $u)) $o[$k] = $u;
    }
    return $o;
}

function footer(string $home): string {
    $F = C()['footer']; $ct = C()['contact']; $b = C()['branding'];
    $sl = '';
    foreach (services() as $s) $sl .= '<li><a href="' . $s['slug'] . '.html">' . h($s['name']) . '</a></li>';
    $am = '';
    foreach (array_slice(cities(), 0, (int)$F['areas_count']) as $x) $am .= '<a href="' . $x['slug'] . '.html">' . t($x['name']) . '</a>';
    $am .= '<a href="service-areas.html" class="all">' . t($F['areas_all']) . '</a>';
    $soc = social_links();
    $icons = ['facebook' => ['Facebook', fixed('FB')], 'instagram' => ['Instagram', fixed('IG')], 'google' => ['Google Business Profile', fixed('GG')]];
    $sa = '';
    foreach ($icons as $k => [$lbl, $svg])
        $sa .= isset($soc[$k]) ? '<a href="' . t($soc[$k]) . '" target="_blank" rel="noopener" aria-label="' . $lbl . '">' . $svg . '</a>'
                               : '<a data-social="' . $k . '" target="_blank" rel="noopener" aria-label="' . $lbl . '" hidden>' . $svg . '</a>';
    $waRow = wa_on() ? '<div class="r" data-wa-row>' . ic('chat') . '<a ' . WAA . '>' . t(cget('whatsapp.footer_text')) . '</a></div>' : '';
    $fab = wa_on() ? '<a class="wa-fab" ' . WAA . ' aria-label="Chat with ' . t(short_name()) . ' on WhatsApp">' . ic('chat') . '<span>' . t(cget('whatsapp.button')) . '</span></a>' : '';
    $fnav = '';
    $nav = $F['nav'];
    if (projects() && !in_array('projects.html', array_column($nav, 'link'), true)) {
        $at = array_search('service-areas.html', array_column($nav, 'link'), true);
        array_splice($nav, $at === false ? count($nav) : $at + 1, 0, [['label' => C()['header']['menu'][array_search('projects', array_column(C()['header']['menu'], 'type'), true)]['label'] ?? 'Projects', 'link' => 'projects.html']]);
    }
    foreach ($nav as $l) $fnav .= '<a href="' . t($l['link']) . '">' . t($l['label']) . '</a>';
    return "<footer class=\"footer\"><div class=\"wrap\"><div class=\"cols\">\n"
        . '<div><a class="flogo-a" href="' . $home . '" aria-label="' . t(brand()) . ' – Home"><img class="flogo" src="' . t($b['footer_logo']) . '" alt="' . t($b['footer_logo_alt']) . '" width="' . (int)$b['footer_logo_w'] . '" height="' . (int)$b['footer_logo_h'] . '" loading="lazy"></a><p>' . t($F['about']) . "</p>\n"
        . '<a class="fem" href="' . tel() . '">' . ic('phone') . '<span><small>' . t($F['emergency_label']) . '</small><b>' . t(phone()) . "</b></span></a>\n"
        . '<div class="fsocial"' . ($soc ? '' : ' hidden') . '>' . $sa . "</div></div>\n"
        . '<div><h4>' . t($F['services_title']) . '</h4><ul>' . $sl . "</ul></div>\n"
        . '<div><h4>' . t($F['areas_title']) . '</h4><div class="areas-mini">' . $am . "</div></div>\n"
        . '<div><h4>' . t($F['contact_title']) . "</h4><div class=\"fcontact\">\n"
        . '<div class="r">' . ic('pin') . '<span>' . t($ct['street']) . '<br>' . t($ct['city']) . ', ' . t($ct['region_long']) . ' ' . t($ct['postal']) . "</span></div>\n"
        . '<div class="r">' . ic('phone') . '<a href="' . tel() . '">' . t(phone()) . "</a></div>\n"
        . $waRow . "\n"
        . '<div class="r">' . ic('mail') . '<a ' . maila() . '>' . MAILT . "</a></div>\n"
        . '<div class="r">' . ic('clock') . '<span>' . t($ct['hours_line1']) . '<br>' . t($ct['hours_line2']) . "</span></div></div></div>\n"
        . "</div></div>\n"
        . '<div class="fnav"><div class="wrap">' . $fnav . "</div></div>\n"
        . '<div class="bottom"><div class="wrap"><span>© <span id="yr">' . date('Y') . '</span> ' . t($F['copyright']) . '</span><span>' . t($F['bottom_right']) . "</span></div></div></footer>\n"
        . $fab . "\n"
        . '<div class="mbar"><a href="' . tel() . '" class="btn btn-ghost">' . ic('phone') . t($F['mbar_call']) . '</a><a href="contact.html" class="btn btn-primary">' . t($F['mbar_quote']) . '</a></div>';
}

function biz(): array {
    $ct = C()['contact']; $site = site_url();
    $o = ['@context' => 'https://schema.org', '@type' => cget('site.schema_type', 'Plumber'), 'name' => cget('site.legal_name'),
          'image' => $site . '/' . cget('site.schema_image'), 'logo' => $site . '/' . cget('site.schema_image'),
          '@id' => $site . '/#business', 'url' => $site, 'telephone' => phone_schema(), 'priceRange' => cget('site.price_range'),
          'address' => ['@type' => 'PostalAddress', 'streetAddress' => $ct['street'], 'addressLocality' => $ct['city'], 'addressRegion' => $ct['region'], 'postalCode' => $ct['postal'], 'addressCountry' => $ct['country']],
          'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float)$ct['lat'], 'longitude' => (float)$ct['lng']],
          'areaServed' => array_map(fn($x) => ['@type' => 'City', 'name' => $x['name']], cities()),
          'openingHoursSpecification' => [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array_values($ct['open_days']), 'opens' => $ct['opens'], 'closes' => $ct['closes']]],
          'slogan' => cget('site.slogan'),
          'hasOfferCatalog' => ['@type' => 'OfferCatalog', 'name' => cget('site.catalog_name', 'Plumbing & Mechanical Services'),
              'itemListElement' => array_map(fn($s) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $s['name'], 'url' => $site . '/' . $s['slug'] . '.html']], services())]];
    if ($soc = social_links()) $o['sameAs'] = array_values($soc);
    return $o;
}
function crumbs(array $items): string {
    $l = [];
    foreach ($items as $i => [$n, $u]) $l[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $n, 'item' => site_url() . '/' . $u];
    return ld(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $l]);
}
function faq_ld(array $items): string {
    return ld(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]], $items)]);
}
function faq_block(array $items, bool $openFirst = false): string {
    $o = '<div class="faq">';
    foreach ($items as $i => $x)
        $o .= '<details' . ($openFirst && $i === 0 ? ' open' : '') . '><summary>' . h($x['q']) . '<span class="pm">' . ic('plus') . '</span></summary><div class="ans">' . h($x['a']) . '</div></details>';
    return $o . '</div>';
}

function svc_card(array $s): string {
    $img = $s['image'];
    $pill = !empty($s['emergency']) ? '<span class="pill">24/7 Emergency</span>' : '';
    $media = img_tag($img, SZ_CARD, false, 'ph', (string)($img['alt'] ?? '') !== '' ? (string)$img['alt'] : $s['name'] . ' in Toronto');
    if ($media === '') $media = '<img class="wm" src="' . t(cget('branding.watermark')) . '" alt=""><span class="lbl">' . ic('cam') . ' Photo</span>';
    return '<article class="svc"><div class="svc-media">' . $media . $pill . '</div><div class="svc-body"><div class="ic">' . ic($s['icon']) . '</div><h3>' . h($s['name']) . '</h3><p>' . h($s['short']) . '</p><span class="more">Learn more ' . ic('arrow') . '</span></div><a class="svc-link" href="' . $s['slug'] . '.html" aria-label="' . h($s['name']) . '"></a></article>';
}
/** Photo frame; $tag and $label are HTML already. Without a photo it shows the logo watermark and a hint. */
function frame(string $label, string $cls, string $tag, $img, bool $eager = false): string {
    $tg = $tag !== '' ? '<span class="tag">' . $tag . '</span>' : '';
    $sizes = str_contains($cls, 'wide') ? SZ_WIDE : (str_contains($cls, 'sm') ? SZ_GAL : SZ_HALF);
    $im = img_tag($img, $sizes, $eager);
    if ($im !== '') return '<div class="imgframe has-img ' . $cls . '">' . $tg . $im . '</div>';
    return '<div class="imgframe ' . $cls . '">' . $tg . '<img class="wm" src="' . t(cget('branding.watermark')) . '" alt="" loading="lazy"><div class="lbl">' . ic('cam') . ' ' . $label . '</div></div>';
}
function side_card(string $title, string $text): string {
    return '<div class="card ccard"><h4>' . $title . '</h4><p>' . $text . '</p><a class="ph" href="' . tel() . '">' . ic('phone') . t(phone()) . '</a><a href="contact.html" class="btn btn-white" style="width:100%">' . t(C()['blocks']['side_button']) . '</a></div>';
}
function page_bg(): array {
    $img = C()['page_header_image'] ?? [];
    if (img_exists($img)) return [' has-bg', '<img class="hero-bg" src="' . t($img['file']) . '" alt="" decoding="async"><span class="hero-ov" aria-hidden="true"></span>'];
    return ['', ''];
}
/** Inner-page title band. $trail = [[label HTML, url or null], ...]; $h1 and $lead are HTML. */
function ph_head(array $trail, string $h1, string $lead, bool $act = true): string {
    [$cls, $bg] = page_bg();
    $bc = '<a href="index.html">Home</a>';
    foreach ($trail as [$n, $u]) $bc .= '<span class="sep">/</span>' . ($u ? '<a href="' . $u . '">' . $n . '</a>' : '<span>' . $n . '</span>');
    $a = $act ? '<div class="ph-act"><a href="contact.html" class="btn btn-primary">' . t(C()['blocks']['quote_button']) . '</a><a href="' . tel() . '" class="btn btn-ghost">' . ic('phone') . t(phone()) . '</a></div>' : '';
    return '<section class="pagehead' . $cls . '">' . $bg . theme('pipes.svg') . '<div class="wrap"><div class="breadcrumb">' . $bc . '</div><h1>' . $h1 . '</h1><p class="ph-lead">' . $lead . '</p>' . $a . '</div></section>';
}
function service_options(): string {
    $o = '';
    foreach (services() as $s) $o .= '<option>' . h($s['name']) . '</option>';
    return $o . '<option>' . t(C()['blocks']['form_service_other']) . '</option>';
}
function areas_section(): string {
    $P = C()['pages']['home'];
    $chips = '';
    foreach (cities() as $x) $chips .= '<a class="citychip" href="' . $x['slug'] . '.html">' . ic('pin') . t($x['name']) . '</a>';
    return '<section class="areas-band" id="areas"><div class="wrap"><div class="areas-grid"><div><span class="eyebrow on">' . t($P['areas_eyebrow']) . '</span><h2>' . t($P['areas_title']) . "</h2>\n"
        . '<p>' . t($P['areas_text']) . "</p>\n"
        . '<div class="f-act"><a href="' . tel() . '" class="btn btn-primary">' . ic('phone') . 'Call ' . t(phone()) . '</a><a href="service-areas.html" class="btn btn-ghost">' . t($P['areas_button']) . ' ' . ic('arrow') . '</a></div></div><div class="cities">' . $chips . '</div></div></div></section>';
}
function portrait(): string {
    $f = C()['founder'];
    $img = $f['photo'];
    if (img_exists($img)) {
        [$w, $hh] = img_size($img['file']);
        $pos = (string)($img['pos'] ?? '');
        return '<img class="ph" src="' . t($img['file']) . '" alt="' . h($img['alt']) . '" width="' . $w . '" height="' . $hh . '" loading="lazy"' . ($pos !== '' && $pos !== '50% 50%' ? ' style="object-position:' . t($pos) . '"' : '') . '>';
    }
    return '<span class="ini">' . t($f['initials']) . '</span><span class="lbl">' . ic('cam') . ' Add photo</span>';
}
function reviews_section(): string {
    $R = C()['reviews'] ?? [];
    if (!$R) return '';
    $P = C()['pages']['home'];
    $cards = '';
    foreach ($R as $r) $cards .= '<div class="review"><div class="stars" role="img" aria-label="5 out of 5 stars">★★★★★</div><p>“' . h($r['quote']) . '”</p><div class="who">' . h($r['name']) . '</div><div class="where">' . h($r['where']) . '</div></div>';
    return '<section class="sec bg-soft" id="reviews"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['reviews_eyebrow']) . '</span><h2>' . t($P['reviews_title']) . '</h2><p>' . t($P['reviews_text']) . "</p></div>\n"
        . '<div class="rev-grid">' . $cards . '</div></div></section>';
}
function team_section(bool $soft = false): string {
    $f = C()['founder'];
    $chk = '';
    foreach ($f['points'] as $p) $chk .= '<li>' . ic('check') . '<span>' . t($p) . '</span></li>';
    $btn = $soft ? '<a href="contact.html" class="btn btn-outline">' . t($f['button_about']) . ' ' . ic('arrow') . '</a>'
                 : '<a href="about.html" class="btn btn-outline">' . t($f['button_home']) . ' ' . ic('arrow') . '</a>';
    return '<section class="sec' . ($soft ? ' bg-soft' : '') . "\" id=\"team\"><div class=\"wrap\"><div class=\"founder\">\n"
        . '<div class="f-photo">' . portrait() . '<div class="f-badge"><span class="si">' . ic('shield') . '</span><span><b>' . t($f['badge_title']) . '</b><span>' . t($f['badge_text']) . "</span></span></div></div>\n"
        . '<div class="f-copy"><span class="eyebrow">' . t($f['eyebrow']) . '</span><h2>' . t($f['name']) . '</h2><div class="f-role">' . t($f['role']) . "</div>\n"
        . '<blockquote>“' . t($f['quote']) . "”</blockquote>\n"
        . '<p>' . t($f['bio']) . "</p>\n"
        . '<ul class="f-list">' . $chk . "</ul>\n"
        . '<div class="f-act"><a href="' . tel() . '" class="btn btn-primary">' . ic('phone') . t(phone()) . '</a>' . $btn . "</div></div>\n"
        . '</div></div></section>';
}
function suppliers_html(): string {
    $S = C()['suppliers'] ?? [];
    $n = count($S);
    if ($n === 0) return '';
    $cols = $n <= 6 ? $n : (int)ceil($n / ceil($n / 6));
    $mcols = $n <= 2 ? $n : ($n === 4 ? 2 : 3);
    $items = '';
    foreach ($S as $s) {
        $logo = (string)($s['logo'] ?? '');
        if ($logo !== '' && is_file(DM_ROOT . '/' . $logo)) {
            $wh = '';
            if (!str_ends_with(strtolower($logo), '.svg')) { [$w, $hh] = img_size($logo); $wh = ' width="' . $w . '" height="' . $hh . '"'; }
            $inner = '<img src="' . t($logo) . '" alt="' . h($s['name']) . ' logo"' . $wh . ' loading="lazy" decoding="async">';
        } else {
            $inner = '<span class="sup-name">' . h(mb_strtoupper($s['name'])) . '</span>';
        }
        $u = trim((string)($s['url'] ?? ''));
        $items .= $u !== '' ? '<a class="sup-item" href="' . h($u) . '" target="_blank" rel="noopener" title="' . h($s['name']) . '">' . $inner . '</a>'
                            : '<div class="sup-item" title="' . h($s['name']) . '">' . $inner . '</div>';
    }
    return '<div class="supp" style="--cols:' . $cols . ';--mcols:' . $mcols . '">' . $items . '</div>';
}
function work_band(array $work): string {
    $o = '';
    foreach ($work as $w) $o .= frame(t($w['label']), 'sm', t($w['tag']), $w['image']);
    return $o;
}

/* ---------------- pages ---------------- */
function page_home(): array {
    $P = C()['pages']['home'];
    $hero = $P['hero_image'];
    if (img_exists($hero)) {
        $hb = [' has-bg', '<img class="hero-bg" src="' . t($hero['file']) . '" alt="' . t($hero['alt']) . '" fetchpriority="high" style="object-position:' . t($hero['pos'] ?: '50% 50%') . '"><span class="hero-ov" aria-hidden="true"></span>'];
    } else {
        $hb = ['', ''];
    }
    $tiles = '';
    foreach ($P['tiles'] as $x) $tiles .= '<a class="h-tile" href="' . t($x['link']) . '"><span class="ti">' . ic($x['icon']) . '</span><span>' . t($x['label']) . "</span></a>\n";
    $proof = '';
    foreach ($P['proof'] as $x) $proof .= '<div>' . ic($x['icon']) . '<span><b>' . t($x['title']) . '</b><span>' . t($x['text']) . '</span></span></div>';
    $cards = implode('', array_map('svc_card', services()));
    $values = [];
    foreach ($P['values'] as $v) $values[] = '<div class="value"><div class="vi">' . ic($v['icon']) . '</div><div><h3>' . t($v['title']) . '</h3><p>' . t($v['text']) . '</p></div></div>';
    $who = [];
    foreach ($P['who'] as $a) {
        $li = '';
        foreach ($a['points'] as $p) $li .= '<li>' . ic('check') . t($p) . '</li>';
        $who[] = '<div class="a"><div class="ai">' . ic($a['icon']) . '</div><h3>' . t($a['title']) . '</h3><p>' . t($a['text']) . '</p><ul>' . $li . '</ul></div>';
    }
    $V = vars();
    $hasRev = !empty(C()['reviews']);
    $hasProj = (bool)projects();
    $body = '<section class="hero' . $hb[0] . '" id="home">' . $hb[1] . theme('pipes.svg') . "<div class=\"wrap\"><div>\n"
        . '<span class="badge-em"><span class="dot"></span><span>' . t($P['badge']) . '<span class="xs-hide">' . t($P['badge_more']) . "</span></span></span>\n"
        . '<h1>' . t($P['title_before']) . '<span class="hl">' . t($P['title_highlight']) . '</span>' . t($P['title_after']) . "</h1>\n"
        . '<p class="lead lead-d">' . t($P['lead']) . "</p>\n"
        . '<p class="lead lead-m">' . t($P['lead_mobile']) . "</p>\n"
        . '<div class="h-tiles"><div class="ht-h">' . t($P['tiles_title']) . "</div><div class=\"ht-g\">\n"
        . $tiles
        . '</div><a class="ht-all" href="services.html">' . t(fill($P['tiles_all'], $V)) . ' ' . ic('arrow') . "</a></div>\n"
        . '<div class="h-actions"><a href="contact.html" class="btn btn-primary">' . t(C()['blocks']['quote_button']) . '</a><a href="' . tel() . '" class="btn btn-ghost">' . ic('phone') . t(phone()) . "</a></div>\n"
        . '<div class="h-proof">' . $proof . "</div></div>\n"
        . '<div class="quote-card"><div class="qh"><div class="k">' . t($P['quote_title']) . '</div><div class="s">' . t($P['quote_text']) . "</div></div>\n"
        . "<form id=\"quoteForm\" action=\"send.php\" method=\"post\" novalidate>\n"
        . "<input type=\"hidden\" name=\"form\" value=\"quote\"><input type=\"hidden\" name=\"t\" value=\"\">\n"
        . "<div class=\"hp\" aria-hidden=\"true\"><label for=\"qw\">Leave this field empty</label><input id=\"qw\" name=\"website\" type=\"text\" tabindex=\"-1\" autocomplete=\"off\"></div>\n"
        . "<div class=\"fld\"><label for=\"qn\">Full name</label><input id=\"qn\" name=\"name\" type=\"text\" autocomplete=\"name\" placeholder=\"Your name\" required></div>\n"
        . "<div class=\"grid2\"><div class=\"fld\"><label for=\"qp\">Phone</label><input id=\"qp\" name=\"phone\" type=\"tel\" autocomplete=\"tel\" placeholder=\"(647) 000-0000\" required></div>\n"
        . "<div class=\"fld\"><label for=\"qc\">City</label><input id=\"qc\" name=\"city\" type=\"text\" placeholder=\"e.g. North York\" autocomplete=\"address-level2\"></div></div>\n"
        . '<div class="fld"><label for="qs">Service needed</label><select id="qs" name="service" required><option value="" selected disabled>Select a service</option>' . service_options() . "</select></div>\n"
        . '<button type="submit" class="btn btn-primary" style="width:100%">' . t($P['quote_button']) . "</button>\n"
        . '<p class="f-err" role="alert" hidden>' . t(fill(C()['blocks']['form_error'], $V)) . "</p>\n"
        . '<p class="q-note">' . t(fill($P['quote_note'], $V)) . "</p></form>\n"
        . '<div class="qsent" id="qsent" role="status">' . ic('shield') . '<h3>' . t($P['quote_sent_title']) . '</h3><p>' . t($P['quote_sent_text']) . "</p></div></div></div></section>\n"
        . trust() . "\n"
        . '<section class="sec bg-soft" id="services"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['services_eyebrow']) . '</span><h2>' . t($P['services_title']) . '</h2><p>' . t($P['services_text']) . "</p></div>\n"
        . '<div class="svc-grid">' . $cards . "</div>\n"
        . '<p class="prob-h">' . t(C()['blocks']['problems_title']) . '</p><div class="problems">' . problems() . "</div></div></section>\n"
        . emer() . "\n"
        . "<section class=\"sec\" id=\"about\"><div class=\"wrap\"><div class=\"split\">\n"
        . '<div class="about-media">' . frame('Team or job-site photo', '', t($P['about_image_tag']), $P['about_image']) . '<div class="stat"><span class="si">' . ic('shield') . '</span><span><b>' . t($P['about_stat_title']) . '</b><span>' . t($P['about_stat_text']) . "</span></span></div></div>\n"
        . '<div><span class="eyebrow">' . t($P['about_eyebrow']) . '</span><h2>' . t($P['about_title']) . "</h2>\n"
        . '<p class="body">' . t($P['about_text']) . "</p>\n"
        . '<div class="values">' . implode("\n", $values) . "</div>\n"
        . '<div style="margin-top:30px"><a href="about.html" class="btn btn-outline">' . t($P['about_button']) . ' ' . ic('arrow') . "</a></div></div></div></div></section>\n"
        . '<section class="sec bg-soft" id="process"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t(C()['blocks']['process_eyebrow']) . '</span><h2>' . t(C()['blocks']['process_title']) . '</h2><p>' . t($P['process_text']) . '</p></div>' . process_steps() . "</div></section>\n"
        . '<section class="sec" id="who"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['who_eyebrow']) . '</span><h2>' . t($P['who_title']) . '</h2><p>' . t($P['who_text']) . "</p></div>\n"
        . "<div class=\"aud\">\n" . implode("\n", $who) . "\n</div></div></section>\n"
        . areas_section() . "\n"
        . '<section class="sec' . ((($hasRev ? 1 : 0) + ($hasProj ? 1 : 0)) % 2 ? '' : ' bg-soft') . '" id="work"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t(C()['blocks']['work_eyebrow']) . '</span><h2>' . t(C()['blocks']['work_title']) . '</h2><p>' . t($P['work_text']) . "</p></div>\n"
        . '<div class="imgband">' . work_band($P['work']) . "</div></div></section>\n"
        . ($hasProj ? home_projects(!$hasRev) . "\n" : '')
        . reviews_section() . "\n"
        . team_section() . "\n"
        . '<section class="sec bg-soft" id="faq"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['faq_eyebrow']) . '</span><h2>' . t($P['faq_title']) . '</h2><p>' . t($P['faq_text']) . '</p></div>' . faq_block(C()['faq']['home'], true) . '<div style="text-align:center;margin-top:30px"><a href="faq.html" class="btn btn-outline">' . t($P['faq_button']) . ' ' . ic('arrow') . "</a></div></div></section>\n"
        . (C()['suppliers'] ? '<section class="sec tight"><div class="wrap"><div class="sec-head" style="margin-bottom:26px"><span class="eyebrow">' . t($P['suppliers_eyebrow']) . '</span><h2 style="font-size:1.6rem">' . t($P['suppliers_title']) . "</h2></div>\n" . suppliers_html() . "</div></section>\n" : '')
        . cta();
    $og = img_exists($hero) ? $hero['file'] : null;
    return ['path' => 'index.html', 'active' => 'home', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'og' => $og, 'extra' => faq_ld(C()['faq']['home']), 'skeleton' => true, 'h1' => strip_tags($P['title_before'] . $P['title_highlight'] . $P['title_after'])];
}

function page_about(): array {
    $P = C()['pages']['about'];
    [$cls, $bg] = page_bg();
    $story = '';
    foreach ($P['story'] as $p) $story .= '<p class="body">' . t($p) . "</p>\n";
    $prom = [];
    foreach ($P['promise'] as $v) $prom[] = '<div class="a"><div class="ai">' . ic($v['icon']) . '</div><h3>' . t($v['title']) . '</h3><p>' . t($v['text']) . '</p></div>';
    $body = '<section class="pagehead' . $cls . '">' . $bg . theme('pipes.svg') . '<div class="wrap"><div class="breadcrumb"><a href="index.html">Home</a><span class="sep">/</span><span>About</span></div><h1>' . t($P['title']) . '</h1><p class="ph-lead">' . t($P['lead']) . "</p></div></section>\n"
        . trust() . "\n"
        . "<section class=\"sec\"><div class=\"wrap\"><div class=\"split\">\n"
        . '<div><span class="eyebrow">' . t($P['story_eyebrow']) . '</span><h2>' . t($P['story_title']) . "</h2>\n"
        . $story
        . '<div style="margin-top:22px;display:flex;gap:12px;flex-wrap:wrap"><a href="contact.html" class="btn btn-primary">' . t($P['button_1']) . '</a><a href="services.html" class="btn btn-outline">' . t($P['button_2']) . "</a></div></div>\n"
        . '<div class="about-media">' . frame('Team or office photo', '', t($P['image_tag']), $P['image']) . '<div class="stat"><span class="si">' . ic('pin') . '</span><span><b>' . t($P['stat_title']) . '</b><span>' . t($P['stat_text']) . "</span></span></div></div></div></div></section>\n"
        . '<section class="sec bg-soft"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['promise_eyebrow']) . '</span><h2>' . t($P['promise_title']) . '</h2><p>' . t($P['promise_text']) . "</p></div>\n"
        . "<div class=\"aud\">\n" . implode("\n", $prom) . "</div></div></section>\n"
        . '<section class="sec"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['process_eyebrow']) . '</span><h2>' . t($P['process_title']) . '</h2></div>' . process_steps() . "</div></section>\n"
        . team_section(true) . "\n"
        . cta();
    return ['path' => 'about.html', 'active' => 'about', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'extra' => crumbs([['Home', ''], ['About', 'about.html']]), 'h1' => $P['title']];
}

function page_services(): array {
    $P = C()['pages']['services'];
    [$cls, $bg] = page_bg();
    $cards = implode('', array_map('svc_card', services()));
    $body = '<section class="pagehead' . $cls . '">' . $bg . theme('pipes.svg') . '<div class="wrap"><div class="breadcrumb"><a href="index.html">Home</a><span class="sep">/</span><span>Services</span></div><h1>' . t($P['title']) . '</h1><p class="ph-lead">' . t($P['lead']) . "</p>\n"
        . '<div class="ph-act"><a href="contact.html" class="btn btn-primary">' . t(C()['blocks']['quote_button']) . '</a><a href="' . tel() . '" class="btn btn-ghost">' . ic('phone') . t(phone()) . "</a></div></div></section>\n"
        . '<section class="sec bg-soft"><div class="wrap"><div class="svc-grid">' . $cards . '</div><p class="prob-h">' . t(C()['blocks']['problems_title']) . '</p><div class="problems">' . problems() . "</div></div></section>\n"
        . emer() . "\n"
        . '<section class="sec"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t(C()['blocks']['process_eyebrow']) . '</span><h2>' . t(C()['blocks']['process_title']) . '</h2></div>' . process_steps() . "</div></section>\n"
        . '<section class="sec bg-soft" id="work"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t(C()['blocks']['work_eyebrow']) . '</span><h2>' . t(C()['blocks']['work_title']) . "</h2></div>\n"
        . '<div class="imgband">' . work_band($P['work']) . "</div></div></section>\n"
        . cta();
    return ['path' => 'services.html', 'active' => 'services', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'extra' => crumbs([['Home', ''], ['Services', 'services.html']]), 'h1' => $P['title']];
}

function service_vars(array $s): array { return vars(['service' => $s['name'], 'service_lower' => mb_strtolower($s['name'])]); }
function service_faq(array $s): array {
    $T = C()['pages']['service_template']; $V = service_vars($s); $o = [];
    foreach ($T['faq'] as $q) {
        $a = (empty($s['emergency']) && !empty($q['a_scheduled'])) ? $q['a_scheduled'] : $q['a'];
        $o[] = ['q' => fill($q['q'], $V), 'a' => fill($a, $V)];
    }
    return $o;
}
function service_title(array $s): string { return trim((string)($s['seo_title'] ?? '')) !== '' ? $s['seo_title'] : fill(C()['pages']['service_template']['seo_title'], service_vars($s)); }
function service_desc(array $s): string { return trim((string)($s['seo_description'] ?? '')) !== '' ? $s['seo_description'] : fill(C()['pages']['service_template']['seo_description'], service_vars($s)); }

function page_service(array $s): array {
    $T = C()['pages']['service_template']; $V = service_vars($s);
    $slug = $s['slug']; $name = $s['name']; $em = !empty($s['emergency']);
    $inc = ''; foreach ($s['included'] as $x) $inc .= '<div class="i">' . ic('check') . '<span>' . h($x) . '</span></div>';
    $sg = ''; foreach ($s['signs'] as $x) $sg .= '<div class="s">' . ic('alert') . '<span>' . h($x) . '</span></div>';
    $sl = ''; foreach (services() as $o) $sl .= '<a href="' . $o['slug'] . '.html" class="' . ($o['slug'] === $slug ? 'on' : '') . '">' . h($o['name']) . ic('arrow') . '</a>';
    $sfaq = service_faq($s);
    $fq = '<div class="faq">';
    foreach ($sfaq as $x) $fq .= '<details><summary>' . h($x['q']) . '<span class="pm">' . ic('plus') . '</span></summary><div class="ans">' . h($x['a']) . '</div></details>';
    $fq .= '</div>';
    [$cls, $bg] = page_bg();
    $lead = h($s['short']) . ' ' . t($em ? $T['lead_end_emergency'] : $T['lead_end_scheduled']);
    $body = '<section class="pagehead' . $cls . '">' . $bg . theme('pipes.svg') . '<div class="wrap"><div class="breadcrumb"><a href="index.html">Home</a><span class="sep">/</span><a href="services.html">' . t($T['breadcrumb']) . '</a><span class="sep">/</span><span>' . h($name) . "</span></div>\n"
        . '<h1>' . t(fill($T['title'], $V)) . '</h1><p class="ph-lead">' . $lead . "</p>\n"
        . '<div class="ph-act"><a href="contact.html" class="btn btn-primary">' . t(C()['blocks']['quote_button']) . '</a><a href="' . tel() . '" class="btn btn-ghost">' . ic('phone') . t(phone()) . "</a></div></div></section>\n"
        . "<section class=\"sec\"><div class=\"wrap\"><div class=\"detail\"><div>\n"
        . frame(t(fill($T['photo_label'], $V)), 'wide', h($name), $s['image'], true) . "\n"
        . '<p class="lead">' . h($s['intro']) . "</p>\n"
        . '<h2>' . t($T['included_title']) . '</h2><div class="incl">' . $inc . "</div>\n"
        . '<h2>' . t(fill($T['signs_title'], $V)) . '</h2><div class="signs">' . $sg . "</div>\n"
        . '<h2>' . t($T['area_title']) . '</h2><p>' . t($T['area_text']) . ' ' . h($s['closing']) . "</p>\n"
        . '<h2>' . t($T['faq_title']) . '</h2>' . $fq . "</div>\n"
        . '<aside class="side"><div class="card ccard"><h4>' . t(fill($T['side_title'], $V)) . '</h4><p>' . t($em ? $T['side_text_emergency'] : $T['side_text_scheduled']) . '</p><a class="ph" href="' . tel() . '">' . ic('phone') . t(phone()) . '</a><a href="contact.html" class="btn btn-white" style="width:100%">' . t(C()['blocks']['side_button']) . "</a></div>\n"
        . '<div class="card"><h4>' . t($T['all_title']) . '</h4><div class="slist">' . $sl . "</div></div></aside></div></div></section>\n"
        . '<section class="sec bg-soft"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t(C()['blocks']['process_eyebrow']) . '</span><h2>' . t(C()['blocks']['process_title']) . '</h2></div>' . process_steps() . "</div></section>\n"
        . cta();
    $img = img_exists($s['image']) ? $s['image']['file'] : null;
    $sld = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $name . ' in Toronto', 'serviceType' => $name];
    if ($img) $sld['image'] = site_url() . '/' . $img;
    $sld += ['description' => $s['short'], 'provider' => ['@id' => site_url() . '/#business'],
             'areaServed' => array_map(fn($x) => ['@type' => 'City', 'name' => $x['name']], array_slice(cities(), 0, (int)$T['schema_areas'])),
             'url' => site_url() . '/' . $slug . '.html'];
    $extra = crumbs([['Home', ''], ['Services', 'services.html'], [$name, $slug . '.html']]) . ld($sld) . faq_ld($sfaq);
    return ['path' => $slug . '.html', 'active' => 'services', 'title' => service_title($s), 'desc' => service_desc($s), 'body' => $body,
            'og' => $img, 'extra' => $extra, 'h1' => fill($T['title'], $V)];
}

function page_contact(): array {
    $P = C()['pages']['contact']; $ct = C()['contact'];
    [$cls, $bg] = page_bg();
    $waRow = wa_on() ? '<div class="row" data-wa-row><div class="ci">' . ic('chat') . '</div><div><small>' . t(cget('whatsapp.contact_label')) . '</small><a class="v" ' . WAA . '>' . t(cget('whatsapp.contact_text')) . '</a></div></div>' : '';
    $V = vars();
    $body = '<section class="pagehead' . $cls . '">' . $bg . theme('pipes.svg') . '<div class="wrap"><div class="breadcrumb"><a href="index.html">Home</a><span class="sep">/</span><span>Contact</span></div><h1>' . t($P['title']) . '</h1><p class="ph-lead">' . t($P['lead']) . "</p></div></section>\n"
        . "<section class=\"sec\"><div class=\"wrap\"><div class=\"contact-grid\">\n"
        . '<div class="cinfo"><span class="eyebrow">' . t($P['eyebrow']) . '</span><h2>' . t($P['info_title']) . "</h2>\n"
        . "<div class=\"rows\">\n"
        . '<div class="row"><div class="ci">' . ic('phone') . '</div><div><small>' . t($P['phone_label']) . '</small><a class="v" href="' . tel() . '">' . t(phone()) . "</a></div></div>\n"
        . $waRow . "\n"
        . '<div class="row"><div class="ci">' . ic('mail') . '</div><div><small>' . t($P['email_label']) . '</small><a class="v" ' . maila() . '>' . MAILT . "</a></div></div>\n"
        . '<div class="row"><div class="ci">' . ic('pin') . '</div><div><small>' . t($P['address_label']) . '</small><b>' . t(addr_full()) . "</b></div></div>\n"
        . '<div class="row"><div class="ci">' . ic('clock') . '</div><div><small>' . t($P['hours_label']) . '</small><b>' . t($ct['hours_short']) . "</b></div></div></div></div>\n"
        . '<div class="cform"><h3>' . t($P['form_title']) . '</h3><div class="sub">' . t($P['form_text']) . "</div>\n"
        . "<form id=\"contactForm\" action=\"send.php\" method=\"post\" novalidate>\n"
        . "<input type=\"hidden\" name=\"form\" value=\"contact\"><input type=\"hidden\" name=\"t\" value=\"\">\n"
        . "<div class=\"hp\" aria-hidden=\"true\"><label for=\"cw\">Leave this field empty</label><input id=\"cw\" name=\"website\" type=\"text\" tabindex=\"-1\" autocomplete=\"off\"></div>\n"
        . "<div class=\"grid2\"><div class=\"fld\"><label for=\"cn\">Name</label><input id=\"cn\" name=\"name\" type=\"text\" autocomplete=\"name\" placeholder=\"Your name\" required></div><div class=\"fld\"><label for=\"cph\">Phone</label><input id=\"cph\" name=\"phone\" type=\"tel\" autocomplete=\"tel\" placeholder=\"(647) 000-0000\" required></div></div>\n"
        . "<div class=\"grid2\"><div class=\"fld\"><label for=\"ce\">Email</label><input id=\"ce\" name=\"email\" type=\"email\" autocomplete=\"email\" placeholder=\"you@email.com\"></div><div class=\"fld\"><label for=\"cc\">City</label><input id=\"cc\" name=\"city\" type=\"text\" placeholder=\"e.g. Markham\"></div></div>\n"
        . '<div class="fld"><label for="cs">Service needed</label><select id="cs" name="service" required><option value="" selected disabled>Select a service</option>' . service_options() . "</select></div>\n"
        . "<div class=\"fld\"><label for=\"cm\">Message</label><textarea id=\"cm\" name=\"message\" placeholder=\"Tell us about the issue or project\"></textarea></div>\n"
        . '<button type="submit" class="btn btn-primary" style="width:100%">' . t($P['form_button']) . "</button>\n"
        . '<p class="f-err" role="alert" hidden>' . t(fill(C()['blocks']['form_error'], $V)) . "</p></form>\n"
        . '<div class="qsent" id="csent" role="status">' . ic('shield') . '<h3>' . t($P['sent_title']) . '</h3><p>' . t($P['sent_text']) . "</p></div></div></div>\n"
        . gmap(t($P['map_title']), t($P['map_text']), 12) . "</div></section>\n"
        . emer() . "\n"
        . '<section class="sec bg-soft" id="faq"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['faq_eyebrow']) . '</span><h2>' . t($P['faq_title']) . '</h2></div>' . faq_block(C()['faq']['home'], true) . '</div></section>';
    return ['path' => 'contact.html', 'active' => 'contact', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'extra' => crumbs([['Home', ''], ['Contact', 'contact.html']]) . faq_ld(C()['faq']['home']), 'h1' => $P['title']];
}

function page_faq(): array {
    $P = C()['pages']['faq'];
    $cats = C()['faq']['categories'];
    $chips = ''; $groups = ''; $all = [];
    foreach ($cats as $i => $c) {
        $chips .= '<a class="fq-chip" href="#' . t($c['key']) . '">' . t($c['name']) . '</a>';
        $groups .= '<div class="fq-group" id="' . t($c['key']) . '"><h2>' . t($c['name']) . '</h2>' . faq_block($c['items'], $i === 0) . '</div>';
        foreach ($c['items'] as $x) $all[] = $x;
    }
    $pop = '';
    foreach (array_slice(services(), 0, (int)$P['popular_count']) as $s) $pop .= '<a href="' . $s['slug'] . '.html">' . h($s['name']) . ic('arrow') . '</a>';
    $body = ph_head([['FAQ', null]], t($P['title']), t($P['lead']), false) . "\n"
        . '<section class="sec"><div class="wrap"><div class="fq-chips">' . $chips . '</div><div class="detail"><div>' . $groups . "</div>\n"
        . '<aside class="side">' . side_card(t($P['side_title']), t($P['side_text'])) . '<div class="card"><h4>' . t($P['popular_title']) . '</h4><div class="slist">' . $pop . "</div></div></aside></div></div></section>\n"
        . emer() . "\n"
        . cta();
    return ['path' => 'faq.html', 'active' => 'faq', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'extra' => crumbs([['Home', ''], ['FAQ', 'faq.html']]) . faq_ld($all), 'h1' => $P['title']];
}

/* ---------------- service areas ---------------- */
function nearest(array $c, int $n): array {
    $la = (float)$c['lat']; $lo = (float)$c['lng'];
    $o = [];
    foreach (cities() as $i => $x) {
        if ($x['name'] === $c['name']) continue;
        $o[] = [hypot((float)$x['lat'] - $la, ((float)$x['lng'] - $lo) * cos(deg2rad($la))), $i, $x];
    }
    usort($o, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    return array_map(fn($r) => $r[2], array_slice($o, 0, $n));
}
function city_vars(array $c): array {
    $T = C()['pages']['city_template'];
    $hoods = array_values($c['neighbourhoods']);
    $list = count($hoods) > 1 ? implode(', ', array_slice($hoods, 0, -1)) . ' and ' . end($hoods) : ($hoods[0] ?? $c['name']);
    $V = vars(['city' => $c['name'], 'region' => $c['region'], 'neighbourhoods' => $list]);
    $where = $c['name'] === 'Toronto' ? $T['where_toronto'] : ($c['region'] === 'City of Toronto' ? $T['where_city_of_toronto'] : $T['where_other']);
    $V['where'] = fill($where, $V);
    return $V;
}
function city_faq(array $c): array {
    $V = city_vars($c); $o = [];
    foreach (C()['pages']['city_template']['faq'] as $q) $o[] = ['q' => fill($q['q'], $V), 'a' => fill($q['a'], $V)];
    return $o;
}
function city_title(array $c): string {
    if (trim((string)($c['seo_title'] ?? '')) !== '') return $c['seo_title'];
    $T = C()['pages']['city_template']; $V = city_vars($c);
    $t = fill($T['seo_title'], $V);
    return mb_strlen($t) > 62 ? fill($T['seo_title_short'], $V) : $t;
}
function city_desc(array $c): string { return trim((string)($c['seo_description'] ?? '')) !== '' ? $c['seo_description'] : fill(C()['pages']['city_template']['seo_description'], city_vars($c)); }

function page_city(array $c): array {
    $T = C()['pages']['city_template']; $V = city_vars($c);
    $slug = $c['slug']; $name = $c['name'];
    $hc = ''; foreach ($c['neighbourhoods'] as $x) $hc .= '<span class="citychip lt">' . ic('pin') . t($x) . '</span>';
    $nl = ''; foreach (nearest($c, (int)$T['nearby_count']) as $n) $nl .= '<a href="' . $n['slug'] . '.html">' . t(fill($T['nearby_link'], ['city' => $n['name']])) . ic('arrow') . '</a>';
    $inc = ''; foreach (services() as $s) $inc .= '<a class="i" href="' . $s['slug'] . '.html">' . ic($s['icon']) . '<span>' . h($s['name']) . '</span></a>';
    $why = ''; foreach ($T['why'] as $w) $why .= '<li>' . ic('check') . '<span>' . t(fill($w, $V)) . '</span></li>';
    $fq = city_faq($c);
    $body = ph_head([['Service Areas', 'service-areas.html'], [t($name), null]], t(fill($T['title'], $V)), t(fill($T['lead'], $V))) . "\n"
        . trust() . "\n"
        . "<section class=\"sec\"><div class=\"wrap\"><div class=\"detail\"><div>\n"
        . frame(t(fill($T['photo_label'], $V)), 'wide', t($name), $c['image'], true) . "\n"
        . '<p class="lead">' . t(fill($T['intro'], $V)) . ' ' . t($c['blurb']) . "</p>\n"
        . '<h2>' . t(fill($T['services_title'], $V)) . '</h2><div class="incl svc-links">' . $inc . "</div>\n"
        . '<h2>' . t(fill($T['hoods_title'], $V)) . '</h2><div class="hoods">' . $hc . "</div>\n"
        . '<h2>' . t(fill($T['why_title'], $V)) . '</h2><ul class="f-list">' . $why . "</ul>\n"
        . '<h2>' . t($T['faq_title']) . '</h2>' . faq_block($fq) . "</div>\n"
        . '<aside class="side">' . side_card(t(fill($T['side_title'], $V)), t($T['side_text'])) . '<div class="card"><h4>' . t($T['nearby_title']) . '</h4><div class="slist">' . $nl . '</div></div><div class="card"><h4>' . t($T['more_title']) . '</h4><div class="slist"><a href="service-areas.html">' . t($T['more_all']) . ic('arrow') . '</a><a href="contact.html">' . t($T['more_contact']) . ic('arrow') . "</a></div></div></aside></div></div></section>\n"
        . emer() . "\n"
        . '<section class="sec bg-soft"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t(C()['blocks']['process_eyebrow']) . '</span><h2>' . t(C()['blocks']['process_title']) . '</h2></div>' . process_steps() . "</div></section>\n"
        . cta();
    $img = img_exists($c['image']) ? $c['image']['file'] : null;
    $sld = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => fill($T['title'], $V), 'serviceType' => 'Plumbing', 'provider' => ['@id' => site_url() . '/#business']];
    if ($img) $sld['image'] = site_url() . '/' . $img;
    $sld += ['areaServed' => ['@type' => 'City', 'name' => $name, 'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float)$c['lat'], 'longitude' => (float)$c['lng']],
                              'containedInPlace' => ['@type' => 'AdministrativeArea', 'name' => $c['region'] . ', Ontario']],
             'url' => site_url() . '/' . $slug . '.html'];
    $extra = crumbs([['Home', ''], ['Service Areas', 'service-areas.html'], [$name, $slug . '.html']]) . ld($sld) . faq_ld($fq);
    return ['path' => $slug . '.html', 'active' => 'areas', 'title' => city_title($c), 'desc' => city_desc($c), 'body' => $body,
            'og' => $img, 'extra' => $extra, 'h1' => fill($T['title'], $V)];
}

function page_areas(): array {
    $P = C()['pages']['areas']; $ct = C()['contact'];
    $order = array_values($P['regions']);
    $list = cities();
    $idx = array_flip(array_map(fn($x) => $x['name'], $list));
    usort($list, function ($a, $b) use ($order, $idx) {
        $ra = array_search($a['region'], $order, true); $rb = array_search($b['region'], $order, true);
        $ra = $ra === false ? 99 : $ra; $rb = $rb === false ? 99 : $rb;
        return $ra <=> $rb ?: $idx[$a['name']] <=> $idx[$b['name']];
    });
    $rg = '<div class="rg-list">';
    foreach ($list as $c) $rg .= '<a class="area-card" href="' . $c['slug'] . '.html" title="Plumber in ' . t($c['name']) . '"><span class="ai2">' . ic('pin') . '</span><span><b>' . t($c['name']) . '</b><small>' . t($c['region']) . '</small></span>' . ic('arrow') . '</a>';
    $rg .= '</div>';
    $mt = t(fill($P['map_title'], vars()));
    $body = ph_head([['Service Areas', null]], t($P['title']), t($P['lead'])) . "\n"
        . '<section class="sec"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['eyebrow']) . '</span><h2>' . t($P['list_title']) . '</h2><p>' . t($P['list_text']) . '</p></div>' . $rg . "</div></section>\n"
        . '<section class="sec bg-soft"><div class="wrap">' . gmap($mt, t($P['map_text']), 9, ' style="margin-top:0"') . "</div></section>\n"
        . emer() . "\n"
        . cta();
    return ['path' => 'service-areas.html', 'active' => 'areas', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'extra' => crumbs([['Home', ''], ['Service Areas', 'service-areas.html']]), 'h1' => $P['title']];
}

function page_404(): array {
    $P = C()['pages']['notfound'];
    $cards = implode('', array_map('svc_card', services()));
    $body = ph_head([['Page not found', null]], t($P['title']), t($P['lead'])) . "\n"
        . '<section class="sec"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['eyebrow']) . '</span><h2>' . t($P['list_title']) . "</h2></div>\n"
        . '<div class="svc-grid">' . $cards . "</div>\n"
        . '<div style="text-align:center;margin-top:34px" class="f-act" ><a href="services.html" class="btn btn-outline">' . t($P['button_services']) . ' ' . ic('arrow') . '</a><a href="service-areas.html" class="btn btn-outline">' . t($P['button_areas']) . ' ' . ic('arrow') . '</a><a href="contact.html" class="btn btn-primary">' . t($P['button_contact']) . "</a></div></div></section>\n"
        . emer();
    return ['path' => '404.html', 'active' => '', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'head_extra' => '<base href="/"><meta name="robots" content="noindex">', 'nositemap' => true, 'h1' => $P['title']];
}

/* ---------------- projects ---------------- */
/** Published projects in the order set in the admin panel (first = newest / most important). */
function projects(): array {
    return array_values(array_filter(C()['projects'] ?? [], fn($p) => ($p['published'] ?? true) && ($p['slug'] ?? '') !== '' && ($p['title'] ?? '') !== ''));
}
function project_url(array $p): string { return 'project-' . $p['slug'] . '.html'; }
function project_photos(array $p): array { return array_values(array_filter($p['photos'] ?? [], 'img_exists')); }
function service_by_slug(string $slug): ?array { foreach (services() as $s) if ($s['slug'] === $slug) return $s; return null; }
/** "2026-09" -> "September 2026"; anything else is shown as typed. */
function project_date(string $d): string {
    if (preg_match('/^(\d{4})-(\d{2})$/', trim($d), $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12)
        return date('F', mktime(0, 0, 0, (int)$m[2], 1, 2000)) . ' ' . $m[1];
    return trim($d);
}
function project_meta(array $p): string {
    $bits = [];
    if (trim((string)($p['location'] ?? '')) !== '') $bits[] = t($p['location']);
    if (($s = service_by_slug((string)($p['service'] ?? ''))) !== null) $bits[] = h($s['name']);
    return implode(' · ', $bits);
}
function project_card(array $p): string {
    $P = C()['pages']['projects']; $T = C()['pages']['project_template'];
    $ph = project_photos($p);
    $media = $ph ? img_tag($ph[0], SZ_CARD) : '<img class="wm" src="' . t(cget('branding.watermark')) . '" alt="" loading="lazy"><span class="lbl">' . ic('cam') . ' Photo</span>';
    $n = count($ph);
    $cnt = $n > 1 ? '<span class="proj-count">' . ic('cam') . t(fill($T['photos'], ['count' => $n])) . '</span>' : '';
    $meta = project_meta($p);
    return '<article class="proj"><a class="proj-a" href="' . project_url($p) . '"><div class="proj-media">' . $media . $cnt . '</div><div class="proj-body">'
        . ($meta !== '' ? '<div class="proj-meta">' . $meta . '</div>' : '') . '<h3>' . t($p['title']) . '</h3>'
        . (trim((string)($p['summary'] ?? '')) !== '' ? '<p>' . t($p['summary']) . '</p>' : '')
        . '<span class="more">' . t($P['card_button']) . ' ' . ic('arrow') . '</span></div></a></article>';
}
function home_projects(bool $soft): string {
    $P = C()['pages']['projects'];
    $list = array_slice(projects(), 0, max(1, (int)$P['home_count']));
    return '<section class="sec' . ($soft ? ' bg-soft' : '') . '" id="projects"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['home_eyebrow']) . '</span><h2>' . t($P['home_title']) . '</h2><p>' . t($P['home_text']) . "</p></div>\n"
        . '<div class="proj-grid">' . implode('', array_map('project_card', $list)) . '</div><div style="text-align:center;margin-top:30px"><a href="projects.html" class="btn btn-outline">' . t($P['home_button']) . ' ' . ic('arrow') . '</a></div></div></section>';
}
/** Photo gallery that opens in the site's photo viewer. Spans keep every row full (no lone photo at the end). */
function gallery(array $photos): string {
    $n = count($photos);
    if ($n === 0) return '';
    $o = '<div class="gallery g' . ($n <= 3 ? $n : 'x') . '">';
    $restD = ($n - 3) % 3; $restM = ($n - 1) % 2;            // desktop: big 2x2 + right column of 2, rows of 3; phones: big + pairs
    foreach ($photos as $i => $ph) {
        $cls = 'g-item' . ($i === 0 ? ' g-big' : '');
        if ($i > 0 && $n > 3 && $i === $n - 1 && $restD === 1) $cls .= ' g-w3';
        if ($i > 0 && $n > 3 && $i === $n - 1 && $restD === 2) $cls .= ' g-w2';
        if ($i > 0 && $i === $n - 1 && $restM === 1) $cls .= ' m-w2';
        $cap = trim((string)($ph['caption'] ?? ''));
        $o .= '<a class="' . $cls . '" href="' . t($ph['file']) . '" data-lb' . ($cap !== '' ? ' data-cap="' . h($cap) . '"' : '') . '>' . img_tag($ph, $i === 0 ? SZ_WIDE : SZ_GAL, $i === 0) . '</a>';
    }
    return $o . '</div>';
}
function project_title(array $p): string { return trim((string)($p['seo_title'] ?? '')) !== '' ? $p['seo_title'] : fill(C()['pages']['project_template']['seo_title'], vars(['project' => $p['title']])); }
function project_desc(array $p): string {
    if (trim((string)($p['seo_description'] ?? '')) !== '') return $p['seo_description'];
    $d = fill(C()['pages']['project_template']['seo_description'], vars(['project' => $p['title'], 'summary' => (string)($p['summary'] ?? '')]));
    return trim($d) !== '' ? $d : (string)$p['title'];
}

function page_projects(): array {
    $P = C()['pages']['projects'];
    $cards = implode('', array_map('project_card', projects()));
    $body = ph_head([[t(C()['pages']['project_template']['breadcrumb']), null]], t($P['title']), t($P['lead'])) . "\n"
        . '<section class="sec"><div class="wrap"><div class="sec-head"><span class="eyebrow">' . t($P['eyebrow']) . '</span><h2>' . t($P['list_title']) . '</h2><p>' . t($P['list_text']) . '</p></div><div class="proj-grid">' . $cards . "</div></div></section>\n"
        . emer() . "\n"
        . cta();
    return ['path' => 'projects.html', 'active' => 'projects', 'title' => $P['seo_title'], 'desc' => $P['seo_description'], 'body' => $body,
            'extra' => crumbs([['Home', ''], [C()['pages']['project_template']['breadcrumb'], 'projects.html']]), 'h1' => $P['title']];
}

function page_project(array $p): array {
    $T = C()['pages']['project_template'];
    $ph = project_photos($p);
    $svc = service_by_slug((string)($p['service'] ?? ''));
    $paras = '';
    foreach (preg_split('/\R\s*\R/', trim((string)($p['description'] ?? ''))) ?: [] as $para)
        if (trim($para) !== '') $paras .= '<p class="body">' . nl2br(t(trim($para)), false) . "</p>\n";
    $hl = '';
    foreach (array_filter(array_map('trim', $p['highlights'] ?? []), 'strlen') as $x) $hl .= '<li>' . ic('check') . '<span>' . t($x) . '</span></li>';
    $facts = '';
    if (trim((string)($p['location'] ?? '')) !== '') $facts .= '<div class="pf"><small>' . t($T['location_label']) . '</small><b>' . t($p['location']) . '</b></div>';
    if ($svc) $facts .= '<div class="pf"><small>' . t($T['service_label']) . '</small><b><a href="' . $svc['slug'] . '.html">' . h($svc['name']) . '</a></b></div>';
    if (trim((string)($p['date'] ?? '')) !== '') $facts .= '<div class="pf"><small>' . t($T['date_label']) . '</small><b>' . t(project_date((string)$p['date'])) . '</b></div>';
    $more = '';
    foreach (projects() as $o) if ($o['slug'] !== $p['slug']) $more .= '<a href="' . project_url($o) . '">' . t($o['title']) . ic('arrow') . '</a>';
    $more .= '<a href="projects.html">' . t($T['all_link']) . ic('arrow') . '</a>';
    $body = ph_head([[t($T['breadcrumb']), 'projects.html'], [t($p['title']), null]], t($p['title']), t((string)($p['summary'] ?? ''))) . "\n"
        . "<section class=\"sec\"><div class=\"wrap\"><div class=\"detail\"><div>\n"
        . ($ph ? '<h2 class="g-h">' . t($T['gallery_title']) . '</h2>' . gallery($ph) . "\n" : '')
        . ($paras !== '' ? '<h2>' . t($T['about_title']) . "</h2>\n" . $paras : '')
        . ($hl !== '' ? '<h2>' . t($T['highlights_title']) . '</h2><ul class="f-list">' . $hl . "</ul>\n" : '')
        . ($svc ? '<p style="margin-top:22px"><a class="btn btn-outline" href="' . $svc['slug'] . '.html">' . t(fill($T['service_link'], ['service' => $svc['name']])) . ' ' . ic('arrow') . "</a></p>\n" : '')
        . "</div>\n"
        . '<aside class="side">' . ($facts !== '' ? '<div class="card pfacts"><h4>' . t($T['facts_title']) . '</h4>' . $facts . '</div>' : '')
        . side_card(t($T['side_title']), t($T['side_text'])) . '<div class="card"><h4>' . t($T['more_title']) . '</h4><div class="slist">' . $more . "</div></div></aside></div></div></section>\n"
        . emer() . "\n"
        . cta();
    $img = $ph ? $ph[0]['file'] : null;
    return ['path' => project_url($p), 'active' => 'projects', 'title' => project_title($p), 'desc' => project_desc($p), 'body' => $body, 'og' => $img,
            'extra' => crumbs([['Home', ''], [$T['breadcrumb'], 'projects.html'], [$p['title'], project_url($p)]]), 'h1' => $p['title']];
}

/* ---------------- whole document ---------------- */
function head_full(array $pg): string {
    $site = site_url(); $path = $pg['path'];
    $url = $site . '/' . ($path === 'index.html' ? '' : $path);
    $og = $pg['og'] ?? null; $og = $og ?: cget('site.og_image', 'assets/favicon-512.png');
    $ver = trim((string)cget('links.google_site_verification'));
    $verTag = preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $ver) ? '<meta name="google-site-verification" content="' . $ver . '">' : '';
    return '<!doctype html><html lang="' . t(cget('site.lang', 'en-CA')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . PGIN
        . '<title>' . h($pg['title']) . '</title><meta name="description" content="' . h($pg['desc']) . '"><link rel="canonical" href="' . $url . '">'
        . '<meta property="og:type" content="website"><meta property="og:title" content="' . h($pg['title']) . '"><meta property="og:description" content="' . h($pg['desc']) . '"><meta property="og:url" content="' . $url . '"><meta property="og:image" content="' . $site . '/' . t($og) . '"><meta property="og:locale" content="' . t(cget('site.og_locale', 'en_CA')) . '">'
        . '<meta name="geo.region" content="' . t(cget('site.geo_region')) . '"><meta name="geo.placename" content="' . t(cget('site.geo_placename')) . '">' . $verTag . ($pg['head_extra'] ?? '') . icons_head() . fixed('FONTS') . css() . '</head><body>';
}

/** All pages: path => ['live' => html, 'preview' => html, 'meta' => [...]] */
function build_pages(): array {
    $pages = [page_home(), page_about(), page_services()];
    foreach (services() as $s) $pages[] = page_service($s);
    $pages[] = page_contact();
    $pages[] = page_faq();
    foreach (cities() as $c) $pages[] = page_city($c);
    $pages[] = page_areas();
    if (projects()) {
        $pages[] = page_projects();
        foreach (projects() as $p) $pages[] = page_project($p);
    }
    $pages[] = page_404();
    return $pages;
}

function render_page(array $pg, bool $preview): string {
    $GLOBALS['DM_PREVIEW'] = $preview;
    $home = $pg['active'] === 'home' ? '#home' : 'index.html';
    $tail = footer($home) . ld(biz()) . ($pg['extra'] ?? '') . '<script src="site-links.js"></script>' . scripts();
    $wh = wa_href($pg['path']);
    if ($preview && !empty($pg['skeleton'])) {
        $out = "<title>Demir Mechanical</title>\n<meta name=\"claude:viewport-fit-sides\" content=\"cover\">\n<meta name=\"description\" content=\"" . h($pg['desc']) . "\">\n" . PGIN . "\n"
            . icons_head() . "\n" . fixed('FONTS') . "\n" . css() . "\n"
            . "<style>/* artifact wrapper pads :root by the safe-area insets — do not add them again */:root{--sat:0px;--sab:0px}</style>\n"
            . "<script>window.DM_PREVIEW=1</script>\n"
            . nav($pg['active'], $pg['path']) . $pg['body'] . $tail;
    } else {
        $full = head_full($pg) . nav($pg['active'], $pg['path']) . $pg['body'] . $tail . '</body></html>';
        $out = $preview ? preg_replace('/<body>/', '<body><script>window.DM_PREVIEW=1</script>', $full, 1) : str_replace(PGIN, '', $full);
    }
    return str_replace('__WAHREF__', $wh, $out);
}

/** Renders the whole site. $pagesIn are the page arrays (built once in live mode, once in preview mode for the map). */
function build_site(bool $preview = false): array {
    $GLOBALS['DM_PREVIEW'] = $preview;      // page bodies containing the map differ in the preview
    $files = []; $meta = [];
    foreach (build_pages() as $pg) {
        $files[$pg['path']] = render_page($pg, $preview);
        $meta[$pg['path']] = ['title' => $pg['title'], 'desc' => $pg['desc'], 'h1' => $pg['h1'] ?? '', 'sitemap' => empty($pg['nositemap'])];
    }
    $GLOBALS['DM_PREVIEW'] = false;
    $files['sitemap.xml'] = sitemap($meta);
    $files['robots.txt'] = "User-agent: *\nAllow: /\n\nSitemap: " . site_url() . "/sitemap.xml\n";
    return ['files' => $files, 'meta' => $meta];
}
function sitemap(array $meta): string {
    $today = date('Y-m-d');
    $svc = array_map(fn($s) => $s['slug'] . '.html', services());
    $o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($meta as $p => $m) {
        if (!$m['sitemap']) continue;
        $prio = $p === 'index.html' ? '1.0' : (in_array($p, ['services.html', 'service-areas.html', 'contact.html'], true) ? '0.9' : ((str_starts_with($p, 'plumber-') || in_array($p, $svc, true) || $p === 'projects.html') ? '0.8' : (str_starts_with($p, 'project-') ? '0.6' : '0.7')));
        $o .= '  <url><loc>' . site_url() . '/' . ($p === 'index.html' ? '' : $p) . '</loc><lastmod>' . $today . '</lastmod><priority>' . $prio . "</priority></url>\n";
    }
    return $o . "</urlset>\n";
}
