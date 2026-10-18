<?php
/**
 * Loads the admin panel: the site renderer, the panel code, the content and the login state.
 * Used by admin/index.php (the panel) and admin/hook.php (rebuild after a code update from GitHub).
 */
declare(strict_types=1);
if (!defined('DM_ADMIN')) { http_response_code(404); exit; }

define('DM_ROOT', dirname(__DIR__, 2));
define('DM_APP', DM_ROOT . '/app');
date_default_timezone_set('America/Toronto');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');

require DM_APP . '/pages.php';
foreach (['core', 'store', 'images', 'schema', 'forms', 'views', 'actions', 'seo', 'media', 'pdf', 'invoices', 'recovery'] as $f) require __DIR__ . '/' . $f . '.php';

security_headers();
try {
    $GLOBALS['DM_C'] = content_load();
    $GLOBALS['DM_CONTENT_ERR'] = '';
} catch (\Throwable $e) {
    $GLOBALS['DM_C'] = content_default();
    $GLOBALS['DM_CONTENT_ERR'] = $e->getMessage();
}
$GLOBALS['DM_AUTH'] = auth_load();
$GLOBALS['DM_SESSION'] = session_check($GLOBALS['DM_AUTH']);

function log_error(\Throwable $e): void {
    $line = date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    @file_put_contents(DM_ROOT . '/data/admin-errors.log', $line, FILE_APPEND | LOCK_EX);
}
