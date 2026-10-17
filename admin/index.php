<?php
/**
 * Demir Mechanical — admin panel (https://demirmechanical.com/admin/).
 * Edits data/content.json and regenerates the static pages of the website. See README.md.
 */
declare(strict_types=1);
if (PHP_VERSION_ID < 80100) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); exit("Bu panel PHP 8.1 veya daha yeni bir sürüm gerektirir.\nHostinger hPanel → Gelişmiş → PHP Yapılandırması → PHP 8.3 seçin."); }
const DM_ADMIN = true;
require dirname(__DIR__) . '/app/admin/boot.php';

/** PHP drops the whole request when it is bigger than post_max_size; say so instead of a confusing error. */
function post_too_big(): bool {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = trim((string)ini_get('post_max_size'));
    $n = (int)$max; $u = strtolower(substr($max, -1));
    $bytes = $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
    return $bytes > 0 && $len > $bytes && !$_POST;
}
/** After the code was updated (for example from GitHub), rebuild the pages once with the new code. */
function auto_rebuild(): void {
    if (!is_file(DM_CONTENT) || $GLOBALS['DM_CONTENT_ERR'] !== '') return;
    if ((published_info()['version'] ?? '') === DM_VERSION) return;
    $rep = site_rebuild();
    flash_set(save_msg($rep, 'Site yeni sürüme güncellendi (' . DM_VERSION . ')'));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$p = (string)($_GET['p'] ?? 'dash');
$a = $method === 'POST' ? (string)($_POST['a'] ?? '') : '';
$authed = false;
try {
    if (!$GLOBALS['DM_AUTH']) {                                   // first run: create the password
        if ($method === 'POST' && $a === 'setup' && origin_ok()) act_setup();
        out('Kurulum', '', view_setup('', setup_mail_secret() !== ''), false);
    }
    if (!$GLOBALS['DM_SESSION']) {
        if ($method === 'POST' && $a === 'login' && origin_ok()) act_login();
        if ($method === 'POST' && wants_json()) json_out(['ok' => false, 'error' => 'Oturum kapanmış. Sayfayı yenileyip tekrar giriş yapın.'], 401);
        out('Giriş', '', view_login('', rl_blocked('login', 8, 900)), false);
    }
    $authed = true;
    if ($method === 'POST') {
        if (post_too_big()) {
            $m = 'Gönderilen dosya sunucu sınırını (' . ini_get('post_max_size') . ') aşıyor. Daha küçük bir fotoğraf seçin.';
            if (wants_json()) json_out(['ok' => false, 'error' => $m], 413);
            redirect([], $m, 'bad');
        }
        csrf_require();
        $acts = ['logout' => 'act_logout', 'logout_all' => 'act_logout_all', 'password' => 'act_password', 'hook_key' => 'act_hook_key',
                 'save_sec' => 'act_save_sec', 'save_item' => 'act_save_item', 'delete' => 'act_delete', 'move' => 'act_move', 'preview' => 'act_preview',
                 'upload' => 'act_upload', 'seo_save' => 'act_seo_save', 'seo_rename' => 'act_seo_rename',
                 'restore' => 'act_restore', 'import' => 'act_import', 'rebuild' => 'act_rebuild', 'cleanup' => 'act_cleanup'];
        if (!isset($acts[$a])) redirect([], 'Bilinmeyen işlem.', 'bad');
        $acts[$a]();
    }
    auto_rebuild();
    switch ($p) {
        case 'dash': out('Panel', 'dash', view_dash());
        case 'sec':
            $key = (string)($_GET['s'] ?? '');
            $S = section_or_404($key);
            out($S['title'], 'sec&s=' . $key, view_section($key, C(), []));
        case 'list':
            $kind = (string)($_GET['k'] ?? '');
            $K = kind_or_404($kind);
            out($K['many'], 'list&k=' . $kind, view_list($kind));
        case 'item':
            $kind = (string)($_GET['k'] ?? '');
            $K = kind_or_404($kind);
            $idx = (string)($_GET['i'] ?? 'new');
            if ($idx === 'new') out('Yeni ' . mb_strtolower($K['one']), 'list&k=' . $kind, view_item($kind, 'new', $K['new'], []));
            $item = ctype_digit($idx) ? (C()[$kind][(int)$idx] ?? null) : null;
            if (!$item) redirect(['p' => 'list', 'k' => $kind], 'Bu öğe bulunamadı (silinmiş olabilir).', 'bad');
            out((string)$item[$K['title']], 'list&k=' . $kind, view_item($kind, $idx, $item, []));
        case 'seo': out('SEO Paneli', 'seo', view_seo((string)($_GET['only'] ?? '')));
        case 'backups': out('Yedekler', 'backups', view_backups());
        case 'account': out('Hesap & Güvenlik', 'account', view_account(''));
        case 'dl': do_download((string)($_GET['what'] ?? ''));
        case 'login': redirect([]);
        default: out('Bulunamadı', '', '<div class="card"><h1>Sayfa bulunamadı</h1><p><a href="index.php">Panele dön</a></p></div>', true, 404);
    }
} catch (\Throwable $e) {
    log_error($e);
    $msg = $e instanceof \RuntimeException || $e instanceof \JsonException ? $e->getMessage() : 'Beklenmeyen bir hata oluştu (' . get_class($e) . ').';
    if (wants_json()) json_out(['ok' => false, 'error' => $msg], 500);
    out('Hata', '', '<div class="card err-card"><h1>Bir sorun oluştu</h1><p>' . e($msg) . '</p><p class="muted">Bu işlem kaydedilmedi; site önceki haliyle yayında. Tekrar denemek için geri dönün.</p><p><a class="btn" href="index.php" data-back>← Geri dön</a></p></div>', $authed, 500);
}
