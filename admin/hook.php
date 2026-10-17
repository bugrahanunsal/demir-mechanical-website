<?php
/**
 * Rebuild hook for automatic deployment: after new code arrives from GitHub, a GitHub Action calls
 *   POST https://demirmechanical.com/admin/hook.php   (header  X-DM-Key: <key from Panel → Hesap & Güvenlik>)
 * and the pages are regenerated with the new code. Without a key set in the panel this endpoint does nothing.
 */
declare(strict_types=1);
const DM_ADMIN = true;
require dirname(__DIR__) . '/app/admin/boot.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
if (rl_blocked('hook', 30, 3600)) json_out(['ok' => false, 'error' => 'too many requests'], 429);
$auth = $GLOBALS['DM_AUTH'];
$key = (string)($_SERVER['HTTP_X_DM_KEY'] ?? '');
if (!$auth || empty($auth['hook']) || $key === '' || !hash_equals((string)$auth['hook'], $key)) { rl_hit('hook', 3600); json_out(['ok' => false, 'error' => 'forbidden'], 403); }
rl_hit('hook', 3600);
try {
    $before = (string)(published_info()['version'] ?? '');
    $rep = ($before !== DM_VERSION || isset($_GET['force'])) && is_file(DM_CONTENT) ? site_rebuild() : null;
    json_out(['ok' => true, 'version' => DM_VERSION, 'previous' => $before, 'rebuilt' => $rep !== null, 'written' => $rep['written'] ?? 0]);
} catch (\Throwable $e) {
    log_error($e);
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
