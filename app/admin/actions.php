<?php
/**
 * What happens when a form in the admin panel is sent. Every handler ends with a redirect, a JSON answer or a page.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

function save_msg(array $rep, string $what = 'Kaydedildi'): string {
    $n = (int)$rep['written'];
    $msg = '<b>' . e($what) . '.</b> ' . ($n > 0 ? $n . ' sayfa güncellendi' : 'Sitede değişen sayfa yok');
    if (!empty($rep['removed'])) $msg .= ', ' . count($rep['removed']) . ' sayfa kaldırıldı';
    return $msg . '. <a href="../" target="_blank" rel="noopener">Siteyi görüntüle</a>';
}
function one_line(string $s): string { return trim(preg_replace('/\s+/u', ' ', clean_text($s)) ?? ''); }

/* ---------------- setup / login / logout ---------------- */
function act_setup(): never {
    if (auth_load()) redirect([]);
    $mail = setup_mail_secret();
    $fail = fn(string $m) => out('Kurulum', '', view_setup($m, $mail !== ''), false, 400);
    if ($b = rl_blocked('setup', 8, 900)) $fail('Çok fazla hatalı deneme. ' . $b . ' dakika sonra tekrar deneyin.');
    $proof = (string)($_POST['proof'] ?? '');
    $ok = $mail !== '' ? hash_equals(hash('sha256', $mail), hash('sha256', $proof)) : hash_equals(hash('sha256', setup_code()), hash('sha256', strtoupper(trim($proof))));
    if (!$ok) { rl_hit('setup', 900); $fail($mail !== '' ? 'E-posta şifresi doğru değil.' : 'Kurulum kodu doğru değil.'); }
    $pw = (string)($_POST['pw'] ?? '');
    if ($pw !== (string)($_POST['pw2'] ?? '')) $fail('İki şifre aynı değil.');
    if ($m = password_ok_for_policy($pw)) $fail($m);
    if ($mail !== '' && hash_equals($mail, $pw)) $fail('Panel şifresi e-posta şifresinden farklı olmalı.');
    $auth = ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'secret' => bin2hex(random_bytes(32)), 'created' => date('c')];
    auth_save($auth);
    @unlink(DM_ROOT . '/data/setup-code.txt');
    rl_clear('setup');
    $GLOBALS['DM_AUTH'] = $auth;
    session_issue($auth);
    if (!is_file(DM_CONTENT)) content_save(import_site_links(C()), 'Panel kuruldu');
    redirect([], '<b>Panel kuruldu.</b> Hoş geldiniz! Soldaki menüden düzenlemek istediğiniz bölümü seçin.');
}
/** The old site kept social/WhatsApp/Analytics settings in site-links.js; carry them over once. */
function import_site_links(array $c): array {
    $f = DM_ROOT . '/site-links.js';
    if (!is_file($f)) return $c;
    $js = (string)file_get_contents($f);
    foreach (['facebook', 'instagram', 'google'] as $k)
        if (preg_match('/\b' . $k . '\s*:\s*"([^"]*)"/', $js, $m) && preg_match('#^https?://\S+$#i', trim($m[1]))) $c['links'][$k] = trim($m[1]);
    if (preg_match('/\bwhatsapp\s*:\s*"([^"]*)"/', $js, $m)) $c['links']['whatsapp'] = preg_replace('/\D+/', '', $m[1]);
    if (preg_match('/\bga4\s*:\s*"(G-[A-Z0-9]{4,14})"/i', $js, $m)) $c['links']['ga4'] = strtoupper($m[1]);
    return $c;
}
function act_login(): never {
    $auth = $GLOBALS['DM_AUTH'];
    if (!$auth) redirect([]);
    if ($b = rl_blocked('login', 8, 900)) out('Giriş', '', view_login('', $b), false, 429);
    if (!password_verify((string)($_POST['pw'] ?? ''), $auth['hash'])) {
        rl_hit('login', 900);
        usleep(400000);
        out('Giriş', '', view_login('Şifre yanlış.', rl_blocked('login', 8, 900)), false, 401);
    }
    rl_clear('login');
    if (password_needs_rehash($auth['hash'], PASSWORD_DEFAULT)) { $auth['hash'] = password_hash((string)$_POST['pw'], PASSWORD_DEFAULT); auth_save($auth); }
    session_issue($auth);
    redirect([]);
}
function act_logout(): never { session_clear(); redirect(['p' => 'login'], 'Çıkış yapıldı.'); }
function act_logout_all(): never {
    $a = $GLOBALS['DM_AUTH']; $a['secret'] = bin2hex(random_bytes(32)); auth_save($a);
    $GLOBALS['DM_AUTH'] = $a;
    session_clear();
    redirect(['p' => 'login'], 'Tüm cihazlardaki oturumlar kapatıldı. Tekrar giriş yapın.');
}
function act_password(): never {
    $a = $GLOBALS['DM_AUTH'];
    $fail = fn(string $m) => out('Hesap & Güvenlik', 'account', view_account($m), true, 400);
    if ($b = rl_blocked('login', 8, 900)) $fail('Çok fazla hatalı deneme. ' . $b . ' dakika sonra tekrar deneyin.');
    if (!password_verify((string)($_POST['cur'] ?? ''), $a['hash'])) { rl_hit('login', 900); $fail('Mevcut şifre yanlış.'); }
    $pw = (string)($_POST['pw'] ?? '');
    if ($pw !== (string)($_POST['pw2'] ?? '')) $fail('İki yeni şifre aynı değil.');
    if ($m = password_ok_for_policy($pw)) $fail($m);
    $a['hash'] = password_hash($pw, PASSWORD_DEFAULT);
    $a['secret'] = bin2hex(random_bytes(32));
    auth_save($a);
    $GLOBALS['DM_AUTH'] = $a;
    session_issue($a);
    redirect(['p' => 'account'], '<b>Şifre değiştirildi.</b> Diğer cihazlarda yeniden giriş yapılması gerekecek.');
}
function act_hook_key(): never {
    $a = $GLOBALS['DM_AUTH']; $a['hook'] = bin2hex(random_bytes(20)); auth_save($a);
    $GLOBALS['DM_AUTH'] = $a;
    redirect(['p' => 'account'], 'Yeni anahtar oluşturuldu.');
}

/* ---------------- sections ---------------- */
function section_or_404(string $key): array {
    $S = schema_sections()[$key] ?? null;
    if (!$S) out('Bulunamadı', '', '<div class="card"><p>Bu bölüm bulunamadı. <a href="index.php">Panele dön</a></p></div>', true, 404);
    return $S;
}
/** Read a section form into a copy of the content. */
function parse_section(string $key, array &$errors): array {
    $S = section_or_404($key);
    $post = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $new = parse_fields($S['fields'], $post, C(), $errors, '');
    if ($key === 'branding') $new = parse_brand($post, $new);
    if ($key === 'faq') {
        $seen = [];
        foreach ($new['faq']['categories'] as $i => &$cat) {
            $k = slugify((string)($cat['key'] !== '' ? $cat['key'] : $cat['name'])) ?: 'faq-' . ($i + 1);
            $base = $k; $n = 2;
            while (isset($seen[$k])) $k = $base . '-' . $n++;
            $seen[$k] = true; $cat['key'] = $k;
        }
        unset($cat);
    }
    return $new;
}
function act_save_sec(): never {
    $key = (string)($_POST['s'] ?? '');
    $errors = [];
    $new = parse_section($key, $errors);
    $S = schema_sections()[$key];
    if ($errors) out($S['title'], 'sec&s=' . $key, view_section($key, $new, $errors), true, 422);
    $rep = content_save($new, $S['title'] . ' kaydedildi');
    redirect(['p' => 'sec', 's' => $key], save_msg($rep));
}

/* ---------------- list items ---------------- */
function kind_or_404(string $kind): array {
    $K = kinds()[$kind] ?? null;
    if (!$K) out('Bulunamadı', '', '<div class="card"><p>Bu liste bulunamadı. <a href="index.php">Panele dön</a></p></div>', true, 404);
    return $K;
}
/** Index of the item a form was opened for; stops if the list changed in another window meanwhile. */
function item_index(string $kind, string $idx, string $was): ?int {
    if ($idx === 'new') return null;
    $list = C()[$kind] ?? [];
    $i = ctype_digit($idx) ? (int)$idx : -1;
    if (!isset($list[$i]) || (string)$list[$i]['slug'] !== $was)
        redirect(['p' => 'list', 'k' => $kind], 'Bu liste başka bir pencerede değişmiş. Değişiklikler kaydedilmedi; lütfen tekrar açıp deneyin.', 'bad');
    return $i;
}
/** Read one item from the form, make its page address unique, fill automatic values. */
function parse_item(string $kind, ?int $i, array &$errors): array {
    $K = kind_or_404($kind);
    $c = C();
    $old = $i === null ? $K['new'] : $c[$kind][$i];
    $item = parse_fields(($K['schema'])(), is_array($_POST['f'] ?? null) ? $_POST['f'] : [], $old, $errors, '');
    $taken = reserved_slugs($c, $i === null ? '' : "$kind.$i");
    if ($kind === 'cities') {
        $item['slug'] = ($i !== null && $item['name'] === $old['name']) ? $old['slug'] : 'plumber-' . slugify((string)$item['name']);
        foreach ($c['cities'] as $j => $x) if ($j !== $i && mb_strtolower($x['name']) === mb_strtolower((string)$item['name'])) $errors['name'] = 'Bu isimde bir bölge zaten var.';
        if (!isset($errors['name']) && isset($taken[$item['slug']])) $errors['name'] = 'Bu isim başka bir sayfanın adresiyle çakışıyor.';
        $item['neighbourhoods'] = array_values($item['neighbourhoods']);
    } else {
        $from = $kind === 'services' ? (string)$item['name'] : (string)$item['title'];
        $slug = slugify((string)($item['slug'] !== '' ? $item['slug'] : $from));
        if ($slug === '' && $kind === 'projects') $slug = 'project-' . date('Ymd-His');
        $item['slug'] = $slug;
        $key = $kind === 'projects' ? 'project-' . $slug : $slug;
        if ($slug === '') $errors['slug'] = 'Sayfa adresi oluşturulamadı; adres alanına harf ve rakam yazın.';
        elseif (isset($taken[$key])) $errors['slug'] = 'Bu adres (' . $key . '.html) zaten kullanılıyor. Başka bir adres yazın.';
        if ($kind === 'services') foreach ($c['services'] as $j => $x) if ($j !== $i && mb_strtolower($x['name']) === mb_strtolower((string)$item['name'])) $errors['name'] = 'Bu isimde bir hizmet zaten var.';
    }
    return $item;
}
/** Forward old addresses: when a page moves, the old address points to the new one (also earlier forwards). */
function add_redirect(array &$c, string $from, string $to): void {
    if ($from === $to) return;
    $c['redirects'] = $c['redirects'] ?? [];
    foreach ($c['redirects'] as $f => $t) if ($t === $from) $c['redirects'][$f] = $to;
    $c['redirects'][$from] = $to;
    unset($c['redirects'][$to]);
}
function act_save_item(): never {
    $kind = (string)($_POST['k'] ?? ''); $K = kind_or_404($kind);
    $idx = (string)($_POST['i'] ?? 'new');
    $i = item_index($kind, $idx, (string)($_POST['was'] ?? ''));
    $errors = [];
    $item = parse_item($kind, $i, $errors);
    if ($errors) out($i === null ? 'Yeni ' . mb_strtolower($K['one']) : $K['one'], 'list&k=' . $kind, view_item($kind, $idx, $item, $errors), true, 422);
    $c = C();
    if ($i === null) {
        $c[$kind][] = $item; $i = count($c[$kind]) - 1;
        unset($c['redirects'][item_url($kind, $item)]);
        $why = $K['one'] . ' eklendi: ' . $item[$K['title']];
    } else {
        $old = $c[$kind][$i];
        $c[$kind][$i] = $item;
        if ($old['slug'] !== $item['slug']) {
            add_redirect($c, item_url($kind, $old), item_url($kind, $item));
            if ($kind === 'services') foreach ($c['projects'] as &$p) if (($p['service'] ?? '') === $old['slug']) $p['service'] = $item['slug'];
            unset($p);
        }
        $why = $K['one'] . ' güncellendi: ' . $item[$K['title']];
    }
    $rep = content_save($c, $why);
    redirect(['p' => 'item', 'k' => $kind, 'i' => $i], save_msg($rep));
}
function act_delete(): never {
    $kind = (string)($_POST['k'] ?? ''); $K = kind_or_404($kind);
    $i = item_index($kind, (string)($_POST['i'] ?? ''), (string)($_POST['slug'] ?? ''));
    $c = C();
    if ($i === null) redirect(['p' => 'list', 'k' => $kind]);
    if (in_array($kind, ['services', 'cities'], true) && count($c[$kind]) <= 1) redirect(['p' => 'item', 'k' => $kind, 'i' => $i], 'En az bir ' . mb_strtolower($K['one']) . ' kalmalı.', 'bad');
    $old = $c[$kind][$i];
    array_splice($c[$kind], $i, 1);
    add_redirect($c, item_url($kind, $old), $K['gone']);
    if ($kind === 'services') foreach ($c['projects'] as &$p) if (($p['service'] ?? '') === $old['slug']) $p['service'] = '';
    unset($p);
    $rep = content_save($c, $K['one'] . ' silindi: ' . $old[$K['title']]);
    redirect(['p' => 'list', 'k' => $kind], save_msg($rep, '"' . $old[$K['title']] . '" silindi'));
}
function act_move(): never {
    $kind = (string)($_POST['k'] ?? ''); $K = kind_or_404($kind);
    $i = item_index($kind, (string)($_POST['i'] ?? ''), (string)($_POST['slug'] ?? ''));
    $j = $i + ((int)($_POST['dir'] ?? 0) < 0 ? -1 : 1);
    $c = C();
    if ($i === null || !isset($c[$kind][$j])) redirect(['p' => 'list', 'k' => $kind]);
    [$c[$kind][$i], $c[$kind][$j]] = [$c[$kind][$j], $c[$kind][$i]];
    $rep = content_save($c, $K['many'] . ': sıra değişti');
    redirect(['p' => 'list', 'k' => $kind], save_msg($rep, 'Sıra değişti'));
}

/* ---------------- preview (nothing is saved) ---------------- */
function act_preview(): never {
    $errors = [];
    $c = C();
    if (isset($_POST['s'])) {
        $new = parse_section((string)$_POST['s'], $errors);
        $page = section_page((string)$_POST['s']);
    } else {
        $kind = (string)($_POST['k'] ?? ''); kind_or_404($kind);
        $idx = (string)($_POST['i'] ?? 'new');
        $i = $idx === 'new' ? null : item_index($kind, $idx, (string)($_POST['was'] ?? ''));
        $item = parse_item($kind, $i, $errors);
        if ($kind === 'projects') $item['published'] = true;
        $new = $c;
        if ($i === null) $new[$kind][] = $item; else $new[$kind][$i] = $item;
        $page = item_url($kind, $item);
    }
    $GLOBALS['DM_C'] = $new;
    $pg = null;
    foreach (build_pages() as $p) { if ($p['path'] === $page) { $pg = $p; break; } $pg ??= $p; }
    $html = render_page($pg, false);
    $note = $errors ? ' · ' . count($errors) . ' alanda hata var, kaydetmeden önce düzeltin' : '';
    $banner = '<script>window.DM_PREVIEW=1</script><div style="position:fixed;z-index:2147483647;left:50%;transform:translateX(-50%);top:calc(env(safe-area-inset-top,0px) + 6px);background:#b45309;color:#fff;font:600 13px/1.3 system-ui,sans-serif;padding:7px 14px;border-radius:999px;box-shadow:0 4px 14px rgba(0,0,0,.25);pointer-events:none;max-width:92vw;text-align:center">ÖNİZLEME — henüz kaydedilmedi' . e($note) . '</div>';
    $html = preg_replace('/<head>/', '<head><base href="../">', $html, 1);
    $html = preg_replace('/<body>/', '<body>' . $banner, $html, 1);
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: frame-ancestors 'none'; form-action 'none'");
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/* ---------------- photo upload (AJAX) ---------------- */
function act_upload(): never {
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(image_kinds()[$kind])) json_out(['ok' => false, 'error' => 'Bilinmeyen görsel türü.'], 400);
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || is_array($f['name'] ?? null)) json_out(['ok' => false, 'error' => 'Dosya seçilmedi.'], 400);
    try {
        $r = with_lock(fn() => image_upload($f, $kind, one_line((string)($_POST['name'] ?? ''))));
        [$w, $h] = [$r['w'], $r['h']];
        json_out(['ok' => true, 'file' => $r['file'], 'url' => thumb_url($r['file']), 'name' => basename($r['file']), 'w' => $w, 'h' => $h,
                  'webp' => $r['webp'] ?? '', 'icons' => $r['icons'] ?? (object)[]]);
    } catch (\RuntimeException $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

/* ---------------- SEO panel ---------------- */
function back_to_seo(string $path, string $msg, string $kind = 'ok'): never {
    flash_set($msg, $kind);
    header('Location: index.php?p=seo&open=' . rawurlencode($path) . '#' . preg_replace('/\W/', '-', $path), true, 303);
    exit;
}
function act_seo_save(): never {
    $c = C();
    $path = (string)($_POST['path'] ?? '');
    if ($path === '_site') {
        foreach (['logo_alt' => 'branding.header_logo_alt', 'flogo_alt' => 'branding.footer_logo_alt'] as $in => $p)
            if (isset($_POST[$in]) && one_line((string)$_POST[$in]) !== '') path_set($c, $p, one_line((string)$_POST[$in]));
    } else {
        $src = page_source($path, $c);
        if ($src && isset($_POST['title'], $_POST['desc'])) {
            $t = one_line((string)$_POST['title']); $d = one_line((string)$_POST['desc']);
            if (!$src['auto'] && ($t === '' || $d === '')) back_to_seo($path, 'Bu sayfanın SEO başlığı ve açıklaması boş bırakılamaz.', 'bad');
            path_set($c, $src['tpath'], $t); path_set($c, $src['dpath'], $d);
        }
        $files = is_array($_POST['altf'] ?? null) ? $_POST['altf'] : [];
        foreach (is_array($_POST['alt'] ?? null) ? $_POST['alt'] : [] as $j => $alt) {
            $file = clean_file($files[$j] ?? '');
            if ($file === '') continue;
            foreach (alt_paths($c, $file) as $p) {
                if (is_array(path_get($c, $p))) path_set($c, $p . '.alt', one_line((string)$alt));
                elseif (one_line((string)$alt) !== '') path_set($c, $p, one_line((string)$alt));
            }
        }
    }
    $rep = content_save($c, 'SEO: /' . ($path === 'index.html' ? '' : $path));
    back_to_seo($path, save_msg($rep));
}
function act_seo_rename(): never {
    $c = C();
    $file = clean_file((string)($_POST['file'] ?? ''));
    $back = (string)($_POST['back'] ?? '');
    if ($file === '' || !preg_match('#^assets/(photos|uploads)/#', $file) || !image_refs($c, $file)) back_to_seo($back, 'Bu dosyanın adı buradan değiştirilemez.', 'bad');
    try { $new = image_rename($file, one_line((string)($_POST['name'] ?? ''))); }
    catch (\RuntimeException $e) { back_to_seo($back, e($e->getMessage()), 'bad'); }
    if ($new === $file) back_to_seo($back, 'Dosya adı zaten bu.');
    $c = replace_file_refs($c, $file, $new);
    $rep = content_save($c, 'Dosya adı: ' . basename($new));
    back_to_seo($back, save_msg($rep, 'Dosya adı değişti: ' . basename($new)));
}

/* ---------------- backups ---------------- */
function backup_file(string $name): string {
    if (!preg_match('/^content-\d{8}-\d{6}-[0-9a-f]{4,6}(-[0-9a-f]{4})?\.json$/', $name) || !is_file(DM_BACKUPS . '/' . $name)) redirect(['p' => 'backups'], 'Yedek bulunamadı.', 'bad');
    return DM_BACKUPS . '/' . $name;
}
function content_from_json(string $json): array {
    $c = json_decode($json, true);
    if (!is_array($c) || !isset($c['services'], $c['cities'], $c['pages'], $c['contact']) || !is_array($c['services'])) throw new RuntimeException('Bu dosya bir Demir Mechanical içerik dosyası değil.');
    return merge_defaults(content_default(), $c);
}
function act_restore(): never {
    $f = backup_file((string)($_POST['f'] ?? ''));
    $c = content_from_json((string)file_get_contents($f));
    $rep = content_save($c, 'Geri yüklendi: ' . tr_date((int)filemtime($f)));
    redirect(['p' => 'backups'], save_msg($rep, 'Site ' . tr_date((int)filemtime($f)) . ' tarihindeki haline döndü'));
}
function act_import(): never {
    $f = $_FILES['json'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || (int)$f['size'] > 5 * 1024 * 1024) redirect(['p' => 'backups'], 'Dosya yüklenemedi.', 'bad');
    try { $c = content_from_json((string)file_get_contents((string)$f['tmp_name'])); }
    catch (\RuntimeException $e) { redirect(['p' => 'backups'], e($e->getMessage()), 'bad'); }
    $rep = content_save($c, 'Dosyadan yüklendi: ' . mb_substr(basename((string)$f['name']), 0, 40));
    redirect(['p' => 'backups'], save_msg($rep, 'İçerik dosyası uygulandı'));
}
function act_cleanup(): never {
    $n = 0; $bytes = 0;
    with_lock(function () use (&$n, &$bytes) {
        foreach (unused_uploads() as $rel => $size) if (@unlink(DM_ROOT . '/' . $rel)) { $n++; $bytes += $size; }
    });
    redirect(['p' => 'backups'], $n ? '<b>' . $n . ' kullanılmayan dosya silindi</b> (' . kb($bytes) . ' yer açıldı).' : 'Silinecek dosya yok.');
}
function act_rebuild(): never {
    $rep = site_rebuild();
    redirect([], save_msg($rep, 'Tüm sayfalar yeniden oluşturuldu'));
}

/* ---------------- downloads ---------------- */
function send_download(string $file, string $name, string $type): never {
    header_remove('Content-Security-Policy');
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
function do_download(string $what): never {
    $stamp = date('Ymd-His');
    if ($what === 'content') {
        if (!is_file(DM_CONTENT)) { header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="demir-content-' . $stamp . '.json"'); echo content_json(C()); exit; }
        send_download(DM_CONTENT, 'demir-content-' . $stamp . '.json', 'application/json');
    }
    if ($what === 'backup') {
        $f = backup_file((string)($_GET['f'] ?? ''));
        send_download($f, 'demir-' . basename($f), 'application/json');
    }
    if ($what === 'full' && class_exists('ZipArchive')) {
        @set_time_limit(300);
        $zip = full_backup_zip();
        register_shutdown_function(fn() => @unlink($zip));
        send_download($zip, 'demir-mechanical-site-' . $stamp . '.zip', 'application/zip');
    }
    redirect(['p' => 'backups'], 'İndirme yapılamadı.', 'bad');
}
/** The whole website folder without passwords, logs and old backups — ready to hand over or move to another server. */
function full_backup_zip(): string {
    $tmp = DM_ROOT . '/data/tmp';
    ensure_dir('data/tmp');
    foreach (glob($tmp . '/*.zip') ?: [] as $old) if (filemtime($old) < time() - 3600) @unlink($old);
    $path = $tmp . '/site-' . bin2hex(random_bytes(6)) . '.zip';
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Zip dosyası oluşturulamadı.');
    $skipDir = '#^(\.git|\.github/\.cache|data/(backups|ratelimit|tmp)|node_modules)(/|$)#';
    $skipFile = '#^(form-config\.php|data/(admin-auth\.php|setup-code\.txt|\.lock|admin-errors\.log)|.*\.zip|.*error_log|.*\.tmp)$#';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DM_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(DM_ROOT) + 1));
        if (preg_match($skipDir, $rel) || preg_match($skipFile, $rel)) continue;
        $z->addFile($f->getPathname(), $rel);
    }
    $z->addFromString('YEDEK-BILGI.txt', "Demir Mechanical web sitesi tam yedeği — " . date('Y-m-d H:i') . "\n\n"
        . "İçerik: data/content.json (tüm yazılar ve ayarlar), assets/ (fotoğraflar ve logolar), sitenin sayfaları, admin paneli ve kodu.\n"
        . "Şifreler bu yedekte yoktur: form-config.php (e-posta şifresi) ve data/admin-auth.php (panel şifresi).\n"
        . "Başka bir sunucuya taşımak için: dosyaları public_html klasörüne çıkarın, form-config.sample.php'yi form-config.php olarak kopyalayıp e-posta şifresini yazın,\n"
        . "sonra /admin/ adresini açıp paneli yeniden kurun.\n");
    $z->close();
    return $path;
}
