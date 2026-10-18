<?php
/**
 * Admin panel screens (Turkish UI). Each view returns the HTML of the main area; out() wraps it in the page layout.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

/* ---------------- small helpers ---------------- */
function aic(string $k): string {
    static $extra = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'archive' => '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'ext' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'star' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
        'layers' => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        'edit' => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'undo' => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/>',
        'code' => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    ];
    if (isset($extra[$k])) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $extra[$k] . '</svg>';
    return str_replace('<svg ', '<svg aria-hidden="true" ', ic($k));
}
function tr_date(int $ts, bool $time = true): string {
    static $m = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    return (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) . ($time ? ', ' . date('H:i', $ts) : '');
}
function ago(int $ts): string {
    $d = time() - $ts;
    if ($d < 60) return 'az önce';
    if ($d < 3600) return (int)floor($d / 60) . ' dk önce';
    if ($d < 86400) return (int)floor($d / 3600) . ' saat önce';
    if ($d < 86400 * 7) return (int)floor($d / 86400) . ' gün önce';
    return tr_date($ts, false);
}
function kb(int $bytes): string { return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB'; }
function post_btn(array $hidden, string $label, string $cls = 'btn sm', string $confirm = '', string $title = ''): string {
    $o = '<form method="post" class="inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($hidden as $k => $v) $o .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    return $o . '<button type="submit" class="' . e($cls) . '"' . ($title !== '' ? ' title="' . e($title) . '" aria-label="' . e($title) . '"' : '') . '>' . $label . '</button></form>';
}
function site_link(string $path, string $label = 'Sitede gör', string $cls = 'btn sm ghost'): string {
    return '<a class="' . e($cls) . '" href="../' . e($path === 'index.html' ? '' : $path) . '" target="_blank" rel="noopener">' . aic('ext') . '<span>' . e($label) . '</span></a>';
}

/* ---------------- what can be edited ---------------- */
/** Lists edited one item at a time. */
function kinds(): array {
    return [
        'services' => ['one' => 'Hizmet', 'many' => 'Hizmetler', 'icon' => 'wrench', 'title' => 'name', 'schema' => 'schema_service', 'gone' => 'services.html',
            'intro' => 'Her hizmetin kendi sayfası vardır; menüdeki Hizmetler listesi, ana sayfa kartları, footer ve formlardaki seçenekler bu listeden otomatik oluşur. Sıra her yerde aynıdır.',
            'new' => ['slug' => '', 'name' => '', 'icon' => 'wrench', 'emergency' => false, 'short' => '', 'intro' => '', 'included' => [], 'signs' => [], 'closing' => '', 'image' => ['file' => '', 'alt' => '', 'pos' => '50% 50%'], 'seo_title' => '', 'seo_description' => '']],
        'cities' => ['one' => 'Bölge', 'many' => 'Hizmet Bölgeleri', 'icon' => 'pin', 'title' => 'name', 'schema' => 'schema_city', 'gone' => 'service-areas.html',
            'intro' => 'Her şehir veya semt için "plumber-şehir.html" sayfası oluşur (Google\'da "plumber + şehir" aramaları için). Bölgeler menüsü, ana sayfa ve footer bu listeyi kullanır.',
            'new' => ['name' => '', 'slug' => '', 'region' => 'York Region', 'blurb' => '', 'neighbourhoods' => [], 'lat' => 43.7, 'lng' => -79.4, 'image' => ['file' => '', 'alt' => '', 'pos' => '50% 50%'], 'seo_title' => '', 'seo_description' => '']],
        'projects' => ['one' => 'Proje', 'many' => 'Projeler', 'icon' => 'folder', 'title' => 'title', 'schema' => 'schema_project', 'gone' => 'projects.html',
            'intro' => 'Yayındaki her proje kendi sayfasında fotoğraf galerisiyle görünür, ana sayfada "Recent Projects" bölümü ve menüde Projeler bağlantısı otomatik çıkar. Listede en üstteki proje ilk sırada gösterilir.',
            'new' => ['slug' => '', 'title' => '', 'published' => true, 'location' => '', 'service' => '', 'date' => '', 'summary' => '', 'photos' => [], 'description' => '', 'highlights' => [], 'seo_title' => '', 'seo_description' => '']],
    ];
}
function item_url(string $kind, array $x): string {
    return match ($kind) { 'projects' => 'project-' . $x['slug'] . '.html', default => $x['slug'] . '.html' };
}
function nav_groups(): array {
    $S = schema_sections();
    $sec = fn(string $k, string $icon = '') => ['sec&s=' . $k, $S[$k]['title'], $icon !== '' ? $icon : $S[$k]['icon']];
    return [
        ['', [['dash', 'Panel', 'grid']]],
        ['İçerik', [['list&k=services', 'Hizmetler', 'wrench'], ['list&k=cities', 'Bölgeler', 'pin'], ['list&k=projects', 'Projeler', 'folder'], $sec('reviews', 'star'), $sec('faq')]],
        ['Sayfalar', [$sec('home'), $sec('about'), $sec('contactpage'), $sec('founder'), $sec('servicepage'), $sec('areapage'), $sec('projectpage'), $sec('blocks', 'layers'), $sec('notfound')]],
        ['Site Ayarları', [$sec('business'), $sec('branding', 'image'), $sec('menu', 'menu'), $sec('links'), $sec('suppliers')]],
        ['Araçlar', [['seo', 'SEO Paneli', 'search'], $sec('custom', 'code'), ['backups', 'Yedekler', 'archive'], ['account', 'Hesap & Güvenlik', 'key']]],
    ];
}

/* ---------------- layout ---------------- */
function out(string $title, string $active, string $main, bool $authed = true, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $flash = flash_take();
    $fl = $flash ? '<div class="flash ' . e($flash[0]) . '" role="status">' . ($flash[0] === 'ok' ? ic('check') : ic('alert')) . '<div>' . $flash[1] . '</div><button type="button" class="flash-x" aria-label="Kapat">' . aic('x') . '</button></div>' : '';
    $brand = e(short_name());
    $fav = e('../' . (string)cget('branding.favicon_32', 'assets/favicon-32.png'));
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($title) . ' · ' . $brand . ' Panel</title>'
        . '<link rel="icon" href="' . $fav . '"><link rel="stylesheet" href="assets/admin.css?v=' . DM_VERSION . '">'
        . '<script src="assets/admin.js?v=' . DM_VERSION . '" defer></script></head>';
    if (!$authed) {
        echo '<body class="auth"><main class="auth-box"><div class="auth-brand">' . admin_logo() . '<span>Yönetim Paneli</span></div>' . $fl . $main . '</main></body></html>';
        exit;
    }
    $nav = '';
    foreach (nav_groups() as [$g, $items]) {
        $nav .= '<div class="ng">' . ($g !== '' ? '<div class="ng-t">' . e($g) . '</div>' : '');
        foreach ($items as [$p, $label, $icon]) $nav .= '<a href="index.php?p=' . e($p) . '"' . ($p === $active ? ' class="on" aria-current="page"' : '') . '>' . aic($icon) . '<span>' . e($label) . '</span></a>';
        $nav .= '</div>';
    }
    echo '<body data-csrf="' . e(csrf_token()) . '"><div class="shell">'
        . '<aside class="side" id="side"><div class="side-top"><a class="side-brand" href="index.php">' . admin_logo() . '</a><button type="button" class="side-x" data-side-close aria-label="Menüyü kapat">' . aic('x') . '</button></div>'
        . '<nav class="snav">' . $nav . '</nav>'
        . '<div class="side-foot"><a href="../" target="_blank" rel="noopener" class="view-site">' . aic('ext') . '<span>Siteyi görüntüle</span></a>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="logout"><button type="submit" class="logout">' . aic('logout') . '<span>Çıkış yap</span></button></form></div></aside>'
        . '<div class="side-dim" data-side-close></div>'
        . '<div class="main"><header class="top"><button type="button" class="burger" data-side-open aria-label="Menüyü aç" aria-controls="side">' . aic('menu') . '</button><div class="top-t">' . e($title) . '</div><a class="top-site" href="../" target="_blank" rel="noopener" title="Siteyi görüntüle">' . aic('ext') . '</a></header>'
        . '<main class="content">' . $fl . $main . '</main></div></div>'
        . '<script type="application/json" id="dm-icons">' . json_encode(icons(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script></body></html>';
    exit;
}
function admin_logo(): string {
    $b = C()['branding'];
    $f = (string)($b['header_logo_png'] ?? '');
    return $f !== '' && is_file(DM_ROOT . '/' . $f) ? '<img src="../' . e($f) . '" alt="' . e(short_name()) . '" class="alogo">' : '<b>' . e(short_name()) . '</b>';
}
function head_block(string $title, string $intro = '', string $actions = ''): string {
    return '<div class="phead"><div><h1>' . e($title) . '</h1>' . ($intro !== '' ? '<p class="intro">' . e($intro) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="phead-act">' . $actions . '</div>' : '') . '</div>';
}

/* ---------------- setup & login ---------------- */
function view_setup(string $err, bool $mailMode): string {
    $o = '<h1>Paneli kurun</h1><p class="muted">İlk kurulum: panelin şifresini belirleyin. Bu işlem bir kez yapılır.</p>';
    if ($err !== '') $o .= '<div class="flash bad">' . ic('alert') . '<div>' . e($err) . '</div></div>';
    $o .= '<form method="post" class="authf" autocomplete="off">' . '<input type="hidden" name="a" value="setup">';
    if ($mailMode) $o .= '<label>info@ e-posta hesabının şifresi<input type="password" name="proof" required autocomplete="off"></label><p class="help">Güvenlik için: sunucudaki <b>form-config.php</b> dosyasına yazdığınız e-posta şifresi. Bu sayede paneli sadece site sahibi kurabilir.</p>';
    else $o .= '<label>Kurulum kodu<input type="text" name="proof" required autocomplete="off" spellcheck="false"></label><p class="help">Hostinger → Dosya Yöneticisi → public_html → <b>data/setup-code.txt</b> dosyasını açın ve içindeki kodu buraya yazın.</p>';
    $o .= '<label>Yeni panel şifresi<input type="password" name="pw" required minlength="10" autocomplete="new-password"></label>'
        . '<label>Yeni şifre (tekrar)<input type="password" name="pw2" required minlength="10" autocomplete="new-password"></label>'
        . '<p class="help">En az 10 karakter. E-posta şifresinden farklı bir şifre seçin.</p>'
        . '<button type="submit" class="btn primary wide">Kurulumu tamamla</button></form>';
    return $o;
}
function view_login(string $err, int $blocked): string {
    $o = '<h1>Giriş</h1>';
    if ($blocked > 0) $o .= '<div class="flash bad">' . ic('alert') . '<div>Çok fazla hatalı deneme. ' . $blocked . ' dakika sonra tekrar deneyin.</div></div>';
    elseif ($err !== '') $o .= '<div class="flash bad">' . ic('alert') . '<div>' . e($err) . '</div></div>';
    return $o . '<form method="post" class="authf"><input type="hidden" name="a" value="login">'
        . '<label>Şifre<input type="password" name="pw" required autofocus autocomplete="current-password"></label>'
        . '<button type="submit" class="btn primary wide">Giriş yap</button></form>'
        . '<p class="help center">Şifrenizi unuttuysanız: Hostinger Dosya Yöneticisi\'nde <b>data/admin-auth.php</b> dosyasını silin ve bu sayfayı yenileyin; kurulum yeniden açılır.</p>';
}

/* ---------------- dashboard ---------------- */
function dash_todos(array $c, array $seo): array {
    $t = [];
    $mail = setup_mail_secret();
    if (!is_file(DM_ROOT . '/form-config.php')) $t[] = ['bad', 'Form e-postaları gönderilemiyor: sunucuda <b>form-config.php</b> yok. form-config.sample.php dosyasını kopyalayıp e-posta şifresini yazın.', ''];
    elseif ($mail === '') $t[] = ['bad', 'Form e-postaları gönderilemiyor: <b>form-config.php</b> içinde e-posta şifresi boş.', ''];
    if (trim((string)$c['links']['ga4']) === '') $t[] = ['warn', 'Google Analytics bağlı değil. Ölçüm kimliğini (G-…) girin; ziyaretler ve arama/WhatsApp tıklamaları ölçülsün.', 'sec&s=links'];
    if (trim((string)$c['links']['google']) === '') $t[] = ['warn', 'Google İşletme Profili bağlantısı yok. Ekleyince footer\'da Google ikonu çıkar ve Google bilgileriniz güçlenir.', 'sec&s=links'];
    if (trim((string)$c['links']['google_site_verification']) === '') $t[] = ['info', 'Google Search Console doğrulaması girilmemiş (Google\'da hangi aramalarla bulunduğunuzu görmek için).', 'sec&s=links'];
    if (!$c['reviews']) $t[] = ['warn', 'Henüz müşteri yorumu yok. Google yorumlarınızdan 3–6 tanesini ekleyin; ana sayfada yorum bölümü otomatik çıkar.', 'sec&s=reviews'];
    if (!projects()) $t[] = ['info', 'Henüz yayında proje yok. İlk projeyi fotoğraflarıyla ekleyin; menüde "Projects" bağlantısı otomatik çıkar.', 'list&k=projects'];
    if (!img_exists($c['founder']['photo'] ?? [])) $t[] = ['info', 'Kurucu fotoğrafı yok (şimdilik baş harfler görünüyor).', 'sec&s=founder'];
    if (trim((string)$c['links']['facebook']) === '' && trim((string)$c['links']['instagram']) === '') $t[] = ['info', 'Facebook / Instagram bağlantıları boş.', 'sec&s=links'];
    if ($seo['warn'] > 0) $t[] = ['warn', $seo['warn'] . ' SEO uyarısı var (başlık/açıklama uzunluğu, eksik alternatif metin…).', 'seo'];
    return $t;
}
function view_dash(): string {
    $c = C(); $pub = published_info(); $seo = seo_summary();
    $when = isset($pub['at']) ? strtotime((string)$pub['at']) : 0;
    $o = '<div class="hello"><div><h1>Hoş geldiniz</h1><p class="intro">' . e(brand()) . ' web sitesi yönetim paneli. Yaptığınız her kayıt siteye hemen yansır; önceki hali otomatik yedeklenir.</p></div>'
        . '<div class="status"><span class="dot"></span><div><b>Site yayında</b><small>' . ($when ? 'Son güncelleme ' . e(ago($when)) . ' · ' . count($pub['files'] ?? []) . ' dosya' : 'Henüz panelden yayın yapılmadı') . '</small></div></div></div>';
    $tiles = [
        ['list&k=services', 'wrench', 'Hizmetler', count($c['services']) . ' hizmet'],
        ['list&k=cities', 'pin', 'Bölgeler', count($c['cities']) . ' şehir/semt'],
        ['list&k=projects', 'folder', 'Projeler', count(projects()) . ' yayında' . (count($c['projects'] ?? []) > count(projects()) ? ' · ' . (count($c['projects']) - count(projects())) . ' taslak' : '')],
        ['sec&s=reviews', 'star', 'Yorumlar', count($c['reviews']) . ' yorum'],
        ['sec&s=business', 'building', 'Firma Bilgileri', e(phone())],
        ['seo', 'search', 'SEO Paneli', $seo['pages'] . ' sayfa · ' . ($seo['warn'] ? $seo['warn'] . ' uyarı' : 'sorun yok')],
    ];
    $o .= '<div class="tiles">';
    foreach ($tiles as [$p, $icon, $t, $s]) $o .= '<a class="tile" href="index.php?p=' . e($p) . '"><span class="ti">' . aic($icon) . '</span><span><b>' . e($t) . '</b><small>' . $s . '</small></span></a>';
    $o .= '</div>';
    $todo = dash_todos($c, $seo);
    if ($todo) {
        $o .= '<section class="card"><h2>Yapılacaklar</h2><ul class="todo">';
        foreach ($todo as [$k, $msg, $link]) $o .= '<li class="t-' . $k . '">' . ($k === 'info' ? aic('help') : ic('alert')) . '<span>' . $msg . '</span>' . ($link !== '' ? '<a class="btn sm" href="index.php?p=' . e($link) . '">Düzenle</a>' : '') . '</li>';
        $o .= '</ul></section>';
    }
    $bk = array_slice(backups_list(), 0, 6);
    $o .= '<div class="cols2"><section class="card"><h2>Son değişiklikler</h2>';
    if ($bk) {
        $o .= '<ul class="hist">';
        foreach ($bk as $b) $o .= '<li><span>' . e($b['why'] !== '' ? $b['why'] : 'Kayıt') . '</span><small>' . e(ago($b['time'])) . '</small></li>';
        $o .= '</ul><p class="muted small">Her kayıttan önceki hali saklanır. Geri almak için <a href="index.php?p=backups">Yedekler</a>.</p>';
    } else $o .= '<p class="muted">Henüz kayıt yok.</p>';
    $o .= '</section><section class="card"><h2>Hızlı işlemler</h2><div class="qa">'
        . '<a class="btn" href="index.php?p=item&k=projects&i=new">' . aic('plus') . '<span>Yeni proje ekle</span></a>'
        . '<a class="btn" href="index.php?p=item&k=services&i=new">' . aic('plus') . '<span>Yeni hizmet ekle</span></a>'
        . '<a class="btn" href="index.php?p=sec&s=reviews">' . aic('star') . '<span>Yorum ekle</span></a>'
        . '<a class="btn" href="index.php?p=sec&s=branding">' . aic('image') . '<span>Logoyu değiştir</span></a>'
        . post_btn(['a' => 'rebuild'], aic('refresh') . '<span>Tüm sayfaları yeniden oluştur</span>', 'btn')
        . '</div><p class="muted small">Sürüm ' . e(DM_VERSION) . '</p></section></div>';
    return $o;
}

/* ---------------- section editor ---------------- */
function section_page(string $key): string {
    return match ($key) {
        'about', 'founder' => 'about.html', 'contactpage', 'links' => 'contact.html', 'faq' => 'faq.html',
        'servicepage' => 'services.html', 'areapage' => 'service-areas.html', 'projectpage' => projects() ? 'projects.html' : 'index.html', 'notfound' => '404.html',
        default => 'index.html',
    };
}
function preview_btn(): string {
    return '<button type="submit" class="btn ghost" name="a" value="preview" formtarget="_blank" formnovalidate>' . aic('eye') . '<span>Önizle</span></button>';
}
function view_section(string $key, array $data, array $errors): string {
    $S = schema_sections()[$key];
    $o = head_block($S['title'], (string)($S['intro'] ?? ''), site_link(section_page($key)));
    if ($errors) $o .= '<div class="flash bad">' . ic('alert') . '<div>Kaydedilmedi: ' . count($errors) . ' alanda düzeltme gerekiyor (kırmızı ile işaretli).</div></div>';
    $o .= '<form method="post" class="edit" data-guard>' . csrf_field() . '<input type="hidden" name="s" value="' . e($key) . '">'
        . '<div class="fields">' . render_fields($S['fields'], $data, [], [], $errors, '') . '</div>'
        . savebar('save_sec', $key === 'custom' ? preview_page_select() : '') . '</form>';
    return $o;
}
/** Which page "Önizle" opens (custom code can target any page). */
function preview_page_select(): string {
    $o = '<label class="pv-page"><span class="sr">Önizlenecek sayfa</span><select name="preview_page" title="Önizlenecek sayfa">';
    foreach (build_pages() as $pg) {
        $p = $pg['path'];
        $o .= '<option value="' . e($p) . '">' . e($p === 'index.html' ? 'Ana sayfa' : $p) . '</option>';
    }
    return $o . '</select></label>';
}
function savebar(string $action, string $extra = ''): string {
    // the save button comes first in the page so the Enter key never opens the preview
    return '<div class="savebar"><span class="dirty-note">' . ic('alert') . 'Kaydedilmemiş değişiklikler var</span><div class="sb-act">'
        . '<button type="submit" class="btn primary" name="a" value="' . e($action) . '" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet ve yayınla</span></button>' . preview_btn() . $extra . '</div></div>';
}

/* ---------------- lists (services / cities / projects) ---------------- */
function thumb(?array $img = null, string $icon = 'cam'): string {
    if ($img && img_exists($img)) {
        $f = $img['file'];
        $sm = img_small($f);
        $src = is_file(DM_ROOT . '/' . $sm) ? $sm : $f;
        return '<span class="thumb"><img src="../' . e($src) . '" alt="" loading="lazy"></span>';
    }
    return '<span class="thumb none">' . aic($icon) . '</span>';
}
function view_list(string $kind): string {
    $K = kinds()[$kind]; $items = C()[$kind] ?? [];
    $o = head_block($K['many'], $K['intro'], '<a class="btn primary" href="index.php?p=item&k=' . $kind . '&i=new">' . aic('plus') . '<span>Yeni ' . e(mb_strtolower($K['one'])) . ' ekle</span></a>');
    if (!$items) return $o . '<div class="empty card">' . aic($K['icon']) . '<p>Henüz ' . e(mb_strtolower($K['one'])) . ' yok.</p><a class="btn primary" href="index.php?p=item&k=' . $kind . '&i=new">' . aic('plus') . '<span>İlk ' . e(mb_strtolower($K['one'])) . 'yi ekleyin</span></a></div>';
    $n = count($items);
    $o .= '<div class="list card">';
    foreach ($items as $i => $x) {
        $title = (string)($x[$K['title']] ?? '');
        $img = $kind === 'projects' ? (project_photos($x)[0] ?? null) : ($x['image'] ?? null);
        $sub = [];
        $live = true;
        if ($kind === 'services') { $sub[] = e(item_url($kind, $x)); if (!empty($x['emergency'])) $sub[] = '<span class="tag">7/24</span>'; }
        if ($kind === 'cities') { $sub[] = e(item_url($kind, $x)); $sub[] = e((string)$x['region']); }
        if ($kind === 'projects') {
            $live = !empty($x['published']);
            $sub[] = $live ? '<span class="tag ok">Yayında</span>' : '<span class="tag">Taslak</span>';
            $sub[] = count(project_photos($x)) . ' fotoğraf';
            if (trim((string)($x['location'] ?? '')) !== '') $sub[] = e((string)$x['location']);
        }
        $edit = 'index.php?p=item&k=' . $kind . '&i=' . $i;
        $o .= '<div class="row">' . '<a class="row-main" href="' . e($edit) . '">' . thumb($img, $K['icon']) . '<span class="row-t"><b>' . e($title !== '' ? $title : '(adsız)') . '</b><small>' . implode(' · ', $sub) . '</small></span></a>'
            . '<div class="row-act">'
            . ($i > 0 ? post_btn(['a' => 'move', 'k' => $kind, 'i' => $i, 'dir' => -1, 'slug' => $x['slug']], '↑', 'ib', '', 'Yukarı taşı') : '<span class="ib off">↑</span>')
            . ($i < $n - 1 ? post_btn(['a' => 'move', 'k' => $kind, 'i' => $i, 'dir' => 1, 'slug' => $x['slug']], '↓', 'ib', '', 'Aşağı taşı') : '<span class="ib off">↓</span>')
            . ($live ? site_link(item_url($kind, $x), 'Gör', 'btn sm ghost hide-s') : '')
            . '<a class="btn sm" href="' . e($edit) . '">' . aic('edit') . '<span>Düzenle</span></a>'
            . '</div></div>';
    }
    return $o . '</div>';
}

/* ---------------- one item ---------------- */
function view_item(string $kind, string $idx, array $item, array $errors): string {
    $K = kinds()[$kind];
    $isNew = $idx === 'new';
    $title = $isNew ? 'Yeni ' . mb_strtolower($K['one']) : (string)($item[$K['title']] ?? $K['one']);
    $live = !$isNew && ($kind !== 'projects' || !empty($item['published']));
    $acts = '<a class="btn sm ghost" href="index.php?p=list&k=' . $kind . '">← ' . e($K['many']) . '</a>' . ($live ? site_link(item_url($kind, $item)) : '');
    $o = head_block($title, '', $acts);
    if ($errors) $o .= '<div class="flash bad">' . ic('alert') . '<div>Kaydedilmedi: ' . count($errors) . ' alanda düzeltme gerekiyor (kırmızı ile işaretli).</div></div>';
    $o .= '<form method="post" class="edit" data-guard data-kind="' . e($kind) . '"' . ($isNew ? ' data-new' : '') . '>' . csrf_field()
        . '<input type="hidden" name="k" value="' . e($kind) . '"><input type="hidden" name="i" value="' . e($idx) . '">'
        . '<input type="hidden" name="was" value="' . e((string)($item['slug'] ?? '')) . '">'
        . '<div class="fields">' . render_fields(($K['schema'])(), $item, [], [], $errors, '') . '</div>'
        . savebar('save_item') . '</form>';
    if (!$isNew) {
        $o .= '<section class="card danger"><h2>' . e($K['one']) . ' silinsin mi?</h2><p class="muted">Sayfa siteden kaldırılır; eski adres ' . e($K['gone']) . ' sayfasına yönlendirilir. Silmeden önceki hali Yedekler\'de durur.</p>'
            . post_btn(['a' => 'delete', 'k' => $kind, 'i' => $idx, 'slug' => $item['slug'] ?? ''], aic('trash') . '<span>Sil</span>', 'btn danger', '"' . $title . '" silinsin mi?') . '</section>';
    }
    return $o;
}

/* ---------------- SEO panel ---------------- */
/** Where each page's title/description lives and which editor changes it. */
function page_source(string $path, array $c): ?array {
    $fixed = ['index.html' => ['home', 'home'], 'about.html' => ['about', 'about'], 'services.html' => ['services', 'servicepage'], 'contact.html' => ['contact', 'contactpage'],
              'faq.html' => ['faq', 'faq'], 'service-areas.html' => ['areas', 'areapage'], 'projects.html' => ['projects', 'projectpage'], '404.html' => ['notfound', 'notfound']];
    if (isset($fixed[$path])) return ['label' => 'Sabit sayfa', 'edit' => 'sec&s=' . $fixed[$path][1], 'tpath' => 'pages.' . $fixed[$path][0] . '.seo_title', 'dpath' => 'pages.' . $fixed[$path][0] . '.seo_description', 'auto' => false];
    foreach (['services' => 'Hizmet', 'cities' => 'Bölge', 'projects' => 'Proje'] as $k => $lbl)
        foreach ($c[$k] ?? [] as $i => $x)
            if (item_url($k, $x) === $path) return ['label' => $lbl, 'edit' => 'item&k=' . $k . '&i=' . $i, 'tpath' => "$k.$i.seo_title", 'dpath' => "$k.$i.seo_description", 'auto' => true];
    return null;
}
function html_attr(string $tag, string $attr): ?string {
    return preg_match('/\s' . $attr . '="([^"]*)"/', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
}
/** Analyse every page as it is rendered from the current content. Cached per request. */
function seo_report(): array {
    static $rep = null;
    if ($rep !== null) return $rep;
    $c = C();
    $files = render_all($c)['files'];
    $GLOBALS['DM_C'] = $c;
    $rows = []; $titles = []; $descs = [];
    foreach ($files as $path => $html) {
        if (!str_ends_with($path, '.html') || str_contains($html, '<meta http-equiv="refresh"')) continue;
        preg_match('/<title>(.*?)<\/title>/s', $html, $m); $title = html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('/<meta name="description" content="([^"]*)"/', $html, $m); $desc = html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m); $h1 = trim(html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $body = preg_replace('/<header.*?<\/header>|<footer.*?<\/footer>/s', '', substr($html, (int)strpos($html, '<body'))) ?? '';
        $imgs = [];
        preg_match_all('/<img\s[^>]*>/', $body, $mm);
        foreach ($mm[0] as $tag) {
            $src = (string)html_attr($tag, 'src'); $alt = html_attr($tag, 'alt') ?? '';
            $cls = (string)html_attr($tag, 'class');
            if ($src === '' || $cls === 'wm' || isset($imgs[$src]) || str_starts_with($src, 'data:') || in_array($src, [$c['branding']['header_logo_png'], $c['branding']['footer_logo']], true)) continue;
            $imgs[$src] = ['file' => $src, 'alt' => $alt, 'decor' => $alt === '' && $cls === 'hero-bg'];
        }
        $titles[$title][] = $path; $descs[$desc][] = $path;
        $rows[$path] = ['path' => $path, 'title' => $title, 'desc' => $desc, 'h1' => $h1, 'imgs' => array_values($imgs), 'noindex' => $path === '404.html'];
    }
    $warnTotal = 0;
    foreach ($rows as $p => &$r) {
        $w = [];
        $tl = mb_strlen($r['title']); $dl = mb_strlen($r['desc']);
        if ($tl > 60) $w[] = 'SEO başlığı uzun (' . $tl . ' karakter); Google ~60 karakterden sonrasını kesebilir.';
        elseif ($tl < 25) $w[] = 'SEO başlığı kısa (' . $tl . ' karakter).';
        if ($dl > 160) $w[] = 'Meta açıklama uzun (' . $dl . ' karakter); ~155–160 karakter idealdir.';
        elseif ($dl < 70) $w[] = 'Meta açıklama kısa (' . $dl . ' karakter); 120–160 karakter önerilir.';
        if (!$r['noindex'] && count($titles[$r['title']]) > 1) $w[] = 'Aynı SEO başlığı başka sayfada da var: ' . implode(', ', array_diff($titles[$r['title']], [$p])) . '.';
        if (!$r['noindex'] && count($descs[$r['desc']]) > 1) $w[] = 'Aynı meta açıklama başka sayfada da var.';
        if ($r['h1'] === '') $w[] = 'Sayfada H1 başlığı yok.';
        foreach ($r['imgs'] as &$im) {
            $im['size'] = is_file(DM_ROOT . '/' . $im['file']) ? (int)filesize(DM_ROOT . '/' . $im['file']) : 0;
            $im['badname'] = (bool)preg_match('/^(img|image|photo|dsc|pxl|screenshot|whatsapp|untitled|\d)[\w\-]*$/i', pathinfo($im['file'], PATHINFO_FILENAME));
            if (!$im['decor'] && trim($im['alt']) === '') $w[] = 'Alternatif metni boş fotoğraf: ' . basename($im['file']);
            if ($im['badname']) $w[] = 'Dosya adı açıklayıcı değil: ' . basename($im['file']);
        }
        unset($im);
        $r['warn'] = $w; $warnTotal += count($w);
    }
    unset($r);
    return $rep = ['rows' => $rows, 'warn' => $warnTotal];
}
function seo_summary(): array {
    try { $r = seo_report(); return ['pages' => count($r['rows']), 'warn' => $r['warn']]; }
    catch (\Throwable $e) { return ['pages' => 0, 'warn' => 0]; }
}
/** Content paths that hold the alt text of an image file (photos) or of a logo. */
function alt_paths(array $c, string $file): array {
    $p = image_refs($c, $file);
    $b = $c['branding'];
    if ($file === ($b['header_logo_png'] ?? '')) $p[] = 'branding.header_logo_alt';
    if ($file === ($b['footer_logo'] ?? '')) $p[] = 'branding.footer_logo_alt';
    return $p;
}
function view_seo(string $only): string {
    $c = C(); $rep = seo_report();
    $o = head_block('SEO Paneli', 'Her sayfanın Google\'da görünen başlığı ve açıklaması, sayfadaki fotoğrafların dosya adları ve alternatif metinleri (alt). Buradan doğrudan düzenleyebilirsiniz; aynı fotoğraf başka sayfalarda da kullanılıyorsa alternatif metni her yerde güncellenir.');
    $nw = count(array_filter($rep['rows'], fn($r) => $r['warn']));
    $o .= '<div class="seo-top"><div class="seg"><a href="index.php?p=seo"' . ($only === '' ? ' class="on"' : '') . '>Tüm sayfalar (' . count($rep['rows']) . ')</a><a href="index.php?p=seo&only=warn"' . ($only === 'warn' ? ' class="on"' : '') . '>Uyarılı (' . $nw . ')</a></div>'
        . '<div class="seo-legend"><span><i class="bar ok"></i>iyi</span><span><i class="bar warn"></i>dikkat</span></div></div>'
        . '<p class="muted small">Öneriler: SEO başlığı 30–60 karakter, meta açıklama 120–160 karakter. Alternatif metin fotoğrafta ne olduğunu İngilizce anlatmalı (örnek: "Plumber replacing a copper riser in a North York condo"). Site İngilizce olduğu için tüm bu metinler İngilizce yazılır.</p>';
    $cur = (string)($_GET['open'] ?? '');
    $b = $c['branding'];
    if ($only === '') {
        $o .= '<details class="seo card" id="_site"' . ($cur === '_site' ? ' open' : '') . '><summary><span class="seo-p"><b>Tüm sayfalarda: logolar</b><small>Header ve footer logosunun alternatif metni</small></span></summary>'
            . '<form method="post" class="edit seo-f" data-guard>' . csrf_field() . '<input type="hidden" name="path" value="_site"><div class="seo-imgs">';
        foreach ([['logo_alt', $b['header_logo_png'], $b['header_logo_alt'], 'Header logosu', ''], ['flogo_alt', $b['footer_logo'], $b['footer_logo_alt'], 'Footer logosu', ' dark']] as [$n, $f, $alt, $l, $cls])
            $o .= '<div class="si"><span class="si-img' . $cls . '"><img src="' . e(thumb_url((string)$f)) . '" alt=""></span><div class="si-b"><div class="si-n">' . e($l) . ' · ' . e(basename((string)$f)) . '</div><label class="mini">Alternatif metin<input type="text" name="' . $n . '" value="' . e((string)$alt) . '" required></label></div></div>';
        $o .= '</div><div class="seo-act"><a class="btn sm ghost" href="index.php?p=sec&s=branding">' . aic('image') . '<span>Logoyu değiştir</span></a><button type="submit" class="btn primary sm" name="a" value="seo_save" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet</span></button></div></form></details>';
    }
    foreach ($rep['rows'] as $path => $r) {
        if ($only === 'warn' && !$r['warn']) continue;
        $src = page_source($path, $c);
        $url = site_url() . '/' . ($path === 'index.html' ? '' : $path);
        $badge = $r['warn'] ? '<span class="tag warn">' . count($r['warn']) . ' uyarı</span>' : '<span class="tag ok">' . ic('check') . 'iyi</span>';
        $o .= '<details class="seo card" id="' . e(preg_replace('/\W/', '-', $path)) . '"' . ($cur === $path ? ' open' : '') . '><summary><span class="seo-p"><b>' . e($r['title']) . '</b><small>/' . e($path === 'index.html' ? '' : $path) . ($src ? ' · ' . e($src['label']) : '') . '</small></span>' . $badge . '</summary>';
        $o .= '<div class="serp" aria-hidden="true"><div class="serp-u">' . e(preg_replace('#^https?://#', '', $url)) . '</div><div class="serp-t" data-serp-t>' . e($r['title']) . '</div><div class="serp-d" data-serp-d>' . e($r['desc']) . '</div></div>';
        if ($r['warn']) { $o .= '<ul class="warns">'; foreach ($r['warn'] as $w) $o .= '<li>' . ic('alert') . e($w) . '</li>'; $o .= '</ul>'; }
        $o .= '<form method="post" class="edit seo-f" data-guard>' . csrf_field() . '<input type="hidden" name="path" value="' . e($path) . '">';
        if ($src) {
            $tv = (string)path_get($c, $src['tpath'], ''); $dv = (string)path_get($c, $src['dpath'], '');
            $ph = $src['auto'] ? ' placeholder="' . e($r['title']) . '"' : '';
            $phd = $src['auto'] ? ' placeholder="' . e($r['desc']) . '"' : '';
            $o .= '<div class="fld"><label class="fl">SEO başlığı' . ($src['auto'] ? ' <span class="dim">(boş = otomatik)</span>' : '') . '</label><input type="text" name="title" value="' . e($tv) . '"' . $ph . ' data-seo="title" data-serp="t"></div>'
                . '<div class="fld"><label class="fl">Meta açıklama' . ($src['auto'] ? ' <span class="dim">(boş = otomatik)</span>' : '') . '</label><textarea name="desc" rows="3" data-seo="desc" data-serp="d"' . $phd . '>' . e($dv) . '</textarea></div>';
        }
        $o .= '<div class="kv"><span>H1 başlığı</span><b>' . e($r['h1'] !== '' ? $r['h1'] : '—') . '</b></div>';
        if ($r['imgs']) {
            $o .= '<h3 class="fh">Fotoğraflar (' . count($r['imgs']) . ')</h3><div class="seo-imgs">';
            foreach ($r['imgs'] as $j => $im) {
                $paths = alt_paths($c, $im['file']);
                $o .= '<div class="si"><a class="si-img" href="../' . e($im['file']) . '" target="_blank" rel="noopener"><img src="' . e(thumb_url(is_file(DM_ROOT . '/' . img_small($im['file'])) ? img_small($im['file']) : $im['file'])) . '" alt="" loading="lazy"></a><div class="si-b">'
                    . '<div class="si-n' . ($im['badname'] ? ' warn' : '') . '">' . e(basename($im['file'])) . ' <span class="dim">' . ($im['size'] ? kb($im['size']) : 'dosya yok') . '</span>'
                    . ($paths && preg_match('#^assets/(photos|uploads)/#', $im['file']) ? ' <button type="button" class="link" data-rename="' . e($im['file']) . '">Dosya adını değiştir</button>' : '') . '</div>';
                if ($im['decor']) $o .= '<div class="dim small">Dekoratif arka plan (alternatif metin gerekmez)</div>';
                elseif ($paths) $o .= '<label class="mini">Alternatif metin<input type="text" name="alt[' . $j . ']" value="' . e($im['alt']) . '"><input type="hidden" name="altf[' . $j . ']" value="' . e($im['file']) . '"></label>'
                    . (count(image_refs($c, $im['file'])) > 1 ? '<div class="dim small">Bu fotoğraf ' . count(image_refs($c, $im['file'])) . ' yerde kullanılıyor; hepsi güncellenir.</div>' : '');
                else $o .= '<div class="small"><span class="dim">Alternatif metin:</span> ' . e($im['alt'] !== '' ? $im['alt'] : '—') . ' <span class="dim">(otomatik)</span></div>';
                $o .= '</div></div>';
            }
            $o .= '</div>';
        }
        $o .= '<div class="seo-act">' . ($src ? '<a class="btn sm ghost" href="index.php?p=' . e($src['edit']) . '">' . aic('edit') . '<span>Sayfanın tüm yazıları</span></a>' : '') . site_link($path, 'Sayfayı aç')
            . '<button type="submit" class="btn primary sm" name="a" value="seo_save" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet</span></button></div></form></details>';
    }
    $o .= '<form method="post" id="renameForm" hidden>' . csrf_field() . '<input type="hidden" name="a" value="seo_rename"><input type="hidden" name="file"><input type="hidden" name="name"><input type="hidden" name="back"></form>';
    return $o;
}

/* ---------------- backups ---------------- */
function view_backups(): string {
    $o = head_block('Yedekler', 'Her kayıttan önce sitenin önceki hali otomatik saklanır (son ' . DM_KEEP_BACKUPS . ' kayıt). Bir yedeği geri yüklerseniz site o haline döner; geri yüklemeden önce de mevcut hal yedeklenir.');
    $o .= '<div class="cols2"><section class="card"><h2>' . aic('download') . ' İndir</h2><p class="muted">Bilgisayarınıza veya geliştiricinize vermek için.</p><div class="qa">'
        . '<a class="btn" href="index.php?p=dl&what=content">' . aic('file') . '<span>İçerik dosyası (content.json)</span></a>'
        . (class_exists('ZipArchive') ? '<a class="btn" href="index.php?p=dl&what=full">' . aic('archive') . '<span>Tam yedek (.zip): site + fotoğraflar + içerik</span></a>' : '<span class="muted small">Tam yedek için sunucuda ZipArchive gerekli.</span>')
        . '</div><p class="muted small">Tam yedekte şifreler (panel şifresi, form-config.php) yer almaz.</p></section>'
        . '<section class="card"><h2>' . aic('upload') . ' Yedekten yükle</h2><p class="muted">Daha önce indirdiğiniz bir content.json dosyasını yükleyip siteyi o hale getirin.</p>'
        . '<form method="post" enctype="multipart/form-data" data-confirm="Site yüklenen dosyadaki haline dönecek. Devam edilsin mi?">' . csrf_field() . '<input type="hidden" name="a" value="import"><input type="file" name="json" accept="application/json,.json" required><button type="submit" class="btn">Yükle ve uygula</button></form></section></div>';
    $un = unused_uploads();
    if ($un) $o .= '<section class="card"><h2>' . aic('trash') . ' Kullanılmayan fotoğraflar</h2><p class="muted">' . count($un) . ' dosya (' . kb(array_sum($un)) . ') ne sitede ne de yedeklerde kullanılıyor (örneğin yüklenip kaydedilmeyen fotoğraflar).</p>'
        . post_btn(['a' => 'cleanup'], aic('trash') . '<span>Temizle</span>', 'btn sm', count($un) . ' dosya kalıcı olarak silinsin mi?') . '</section>';
    $list = backups_list();
    $o .= '<section class="card"><h2>' . aic('undo') . ' Önceki haller</h2>';
    if (!$list) return $o . '<p class="muted">Henüz yedek yok. İlk kayıttan sonra burada görünür.</p></section>';
    $o .= '<div class="list">';
    foreach ($list as $i => $b) {
        $o .= '<div class="row"><div class="row-main"><span class="row-t"><b>' . e($b['why'] !== '' ? $b['why'] : 'Kayıt') . '</b><small>Bu kayıttan önceki hal · ' . e(tr_date($b['time'])) . ' · ' . e(kb($b['size'])) . '</small></span></div><div class="row-act">'
            . '<a class="btn sm ghost" href="index.php?p=dl&what=backup&f=' . e(rawurlencode($b['file'])) . '" title="İndir">' . aic('download') . '<span class="hide-s">İndir</span></a>'
            . post_btn(['a' => 'restore', 'f' => $b['file']], aic('undo') . '<span>Geri yükle</span>', 'btn sm', 'Site ' . tr_date($b['time']) . ' tarihindeki haline dönecek. Devam edilsin mi?')
            . '</div></div>';
    }
    return $o . '</div></section>';
}

/* ---------------- account ---------------- */
function view_account(string $err): string {
    $a = $GLOBALS['DM_AUTH'];
    $o = head_block('Hesap & Güvenlik');
    if ($err !== '') $o .= '<div class="flash bad">' . ic('alert') . '<div>' . e($err) . '</div></div>';
    $o .= '<div class="cols2"><section class="card"><h2>' . aic('key') . ' Şifreyi değiştir</h2><form method="post" class="authf">' . csrf_field() . '<input type="hidden" name="a" value="password">'
        . '<label>Mevcut şifre<input type="password" name="cur" required autocomplete="current-password"></label>'
        . '<label>Yeni şifre<input type="password" name="pw" required minlength="10" autocomplete="new-password"></label>'
        . '<label>Yeni şifre (tekrar)<input type="password" name="pw2" required minlength="10" autocomplete="new-password"></label>'
        . '<button type="submit" class="btn primary">Şifreyi değiştir</button></form><p class="muted small">Şifre değişince diğer tüm cihazlardaki oturumlar kapanır.</p></section>'
        . '<section class="card"><h2>' . aic('logout') . ' Oturumlar</h2><p class="muted">Panel bu cihazda ' . DM_SESSION_DAYS . ' gün açık kalır. Telefonunuzu kaybettiyseniz veya başka bir bilgisayarda giriş yaptıysanız tüm oturumları kapatın.</p>'
        . post_btn(['a' => 'logout_all'], aic('logout') . '<span>Tüm cihazlardan çıkış yap</span>', 'btn', 'Tüm cihazlardaki oturumlar kapanacak (bu cihaz dahil). Devam edilsin mi?') . '</section></div>';
    $checks = [
        ['PHP sürümü', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')],
        ['Fotoğraf işleme (GD + WebP)', function_exists('imagewebp') ? 'var' : 'yok', function_exists('imagewebp')],
        ['Telefon fotoğrafı döndürme (EXIF)', function_exists('exif_read_data') ? 'var' : 'yok', function_exists('exif_read_data')],
        ['Tam yedek (ZipArchive)', class_exists('ZipArchive') ? 'var' : 'yok', class_exists('ZipArchive')],
        ['Site klasörüne yazma', is_writable(DM_ROOT) ? 'var' : 'yok', is_writable(DM_ROOT)],
        ['Yükleme sınırı', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'), true],
        ['Form e-posta ayarı (form-config.php)', is_file(DM_ROOT . '/form-config.php') ? (setup_mail_secret() !== '' ? 'tamam' : 'şifre boş') : 'dosya yok', setup_mail_secret() !== ''],
    ];
    $o .= '<section class="card"><h2>' . aic('sliders') . ' Sunucu kontrolü</h2><div class="checks">';
    foreach ($checks as [$l, $v, $ok]) $o .= '<div class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? ic('check') : ic('alert')) . '<span>' . e($l) . '</span><b>' . e($v) . '</b></div>';
    $o .= '</div></section>';
    $hook = (string)($a['hook'] ?? '');
    $o .= '<details class="card dev"><summary><h2>Geliştirici: GitHub otomatik yayın</h2></summary><p class="muted">Kod GitHub\'dan Hostinger\'a otomatik çekiliyorsa, sayfaların yeni kodla yeniden oluşturulması için GitHub Actions bu adresi çağırabilir. Panelin herhangi bir sayfasını açmak da aynı işi yapar.</p>'
        . ($hook !== '' ? '<div class="kv"><span>Adres</span><code>' . e(site_url()) . '/admin/hook.php</code></div><div class="kv"><span>Anahtar (DM_HOOK_KEY)</span><code class="sel">' . e($hook) . '</code></div>' : '<p class="muted">Henüz anahtar oluşturulmadı.</p>')
        . post_btn(['a' => 'hook_key'], aic('refresh') . '<span>' . ($hook !== '' ? 'Yeni anahtar oluştur' : 'Anahtar oluştur') . '</span>', 'btn sm', $hook !== '' ? 'Eski anahtar çalışmayı bırakacak. Devam edilsin mi?' : '')
        . '</details>';
    return $o;
}
