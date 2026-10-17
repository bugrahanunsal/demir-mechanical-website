<?php
/**
 * Admin panel core: request helpers, login, first-run setup, CSRF protection and rate limiting.
 *
 * Login: one admin password (stored as a hash in data/admin-auth.php) and a signed cookie, valid 14 days.
 * First-run setup is protected: it asks for the info@ mailbox password that is already in form-config.php
 * (or, if that is empty, a one-time setup code written to data/setup-code.txt that only the site owner can read).
 * Forgot the password? Delete data/admin-auth.php in Hostinger's File Manager and run the setup again.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const DM_AUTH_FILE = DM_ROOT . '/data/admin-auth.php';
const DM_COOKIE = 'dm_admin';
const DM_SESSION_DAYS = 14;

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}
function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }
function admin_url(array $q = []): string { return 'index.php' . ($q ? '?' . http_build_query($q) : ''); }
function redirect(array $q = [], ?string $flash = null, string $kind = 'ok'): never {
    if ($flash !== null) flash_set($flash, $kind);
    header('Location: ' . admin_url($q), true, 303);
    exit;
}
function json_out(array $o, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
}

/* ---------- flash messages (short-lived cookie) ---------- */
function flash_set(string $msg, string $kind = 'ok'): void {
    $GLOBALS['DM_FLASH_NOW'] = [$kind, $msg];
    $v = b64u(json_encode([$kind, $msg]));
    setcookie('dm_flash', $v . '.' . flash_sig($v), ['expires' => time() + 60, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
}
/** Flash messages may contain links, so they are signed: a cookie planted by someone else is ignored. */
function flash_sig(string $v): string {
    $a = $GLOBALS['DM_AUTH'] ?? null;
    return b64u(hash_hmac('sha256', 'flash|' . $v, $a['secret'] ?? 'setup', true));
}
function flash_take(): ?array {
    if (!empty($GLOBALS['DM_FLASH_NOW']) && empty($GLOBALS['DM_REDIRECTING'])) { $f = $GLOBALS['DM_FLASH_NOW']; $GLOBALS['DM_FLASH_NOW'] = null; setcookie('dm_flash', '', ['expires' => time() - 3600, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']); return $f; }
    if (empty($_COOKIE['dm_flash'])) return null;
    setcookie('dm_flash', '', ['expires' => time() - 3600, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    $parts = explode('.', (string)$_COOKIE['dm_flash']);
    if (count($parts) !== 2 || !hash_equals(flash_sig($parts[0]), $parts[1])) return null;
    $v = json_decode(b64u_dec($parts[0]), true);
    return is_array($v) && count($v) === 2 && in_array($v[0], ['ok', 'bad', 'warn'], true) ? [(string)$v[0], (string)$v[1]] : null;
}
function cookie_path(): string {
    $p = dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'));
    return rtrim(str_replace('\\', '/', $p), '/') . '/';
}

/* ---------- auth storage ---------- */
/* The file is read as text (never include): PHP's code cache could otherwise serve an old copy for a few seconds
   right after a password change, which would log the owner out. The first line stops it from ever being shown. */
function auth_load(): ?array {
    if (!is_file(DM_AUTH_FILE)) return null;
    $raw = (string)file_get_contents(DM_AUTH_FILE);
    $a = json_decode(substr($raw, (int)strpos($raw, "\n") + 1), true);
    return is_array($a) && !empty($a['hash']) && !empty($a['secret']) ? $a : null;
}
function auth_save(array $a): void {
    $head = "<?php http_response_code(404); exit; /* Demir Mechanical admin login: password hash + signing key. Delete this file to reset the panel password. */ ?>\n";
    write_atomic(DM_AUTH_FILE, $head . json_encode($a, JSON_UNESCAPED_SLASHES) . "\n", 0600);
}
function write_atomic(string $file, string $data, int $mode = 0644): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Klasör oluşturulamadı: ' . $dir);
    $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $data, LOCK_EX) === false) throw new RuntimeException('Dosya yazılamadı: ' . $file);
    @chmod($tmp, $mode);
    if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Dosya kaydedilemedi: ' . $file); }
}

/** Mailbox password from form-config.php (the site owner's existing secret), used once to authorise the setup. */
function setup_mail_secret(): string {
    $f = DM_ROOT . '/form-config.php';
    if (!is_file($f)) return '';
    try { $c = include $f; } catch (\Throwable $e) { return ''; }
    return is_array($c) ? (string)($c['smtp_password'] ?? '') : '';
}
/** Fallback when no mailbox password is configured: a one-time code in data/setup-code.txt (readable only via File Manager). */
function setup_code(): string {
    $f = DM_ROOT . '/data/setup-code.txt';
    if (!is_file($f)) write_atomic($f, strtoupper(bin2hex(random_bytes(5))) . "\n", 0600);
    return trim((string)file_get_contents($f));
}
function password_ok_for_policy(string $p): ?string {
    if (mb_strlen($p) < 10) return 'Şifre en az 10 karakter olmalı.';
    if (mb_strlen($p) > 200) return 'Şifre çok uzun.';
    return null;
}

/* ---------- rate limit (failed logins per IP) ---------- */
function rl_file(string $what): string { return DM_ROOT . '/data/ratelimit/' . $what . '-' . hash('sha256', client_ip()) . '.json'; }
function rl_blocked(string $what, int $max, int $window): int {
    $f = rl_file($what);
    $hits = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > time() - $window));
    return count($hits) >= $max ? max(1, (int)ceil(($hits[0] + $window - time()) / 60)) : 0;
}
function rl_hit(string $what, int $window): void {
    $f = rl_file($what);
    $hits = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > time() - $window));
    $hits[] = time();
    write_atomic($f, json_encode($hits), 0600);
}
function rl_clear(string $what): void { @unlink(rl_file($what)); }

/* ---------- session cookie ---------- */
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/'), true); }
function session_issue(array $auth): void {
    $payload = b64u(json_encode(['exp' => time() + DM_SESSION_DAYS * 86400, 'n' => bin2hex(random_bytes(12))]));
    $tok = $payload . '.' . b64u(hash_hmac('sha256', $payload, $auth['secret'], true));
    setcookie(DM_COOKIE, $tok, ['expires' => time() + DM_SESSION_DAYS * 86400, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    $_COOKIE[DM_COOKIE] = $tok;
}
function session_clear(): void {
    setcookie(DM_COOKIE, '', ['expires' => time() - 3600, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    unset($_COOKIE[DM_COOKIE]);
}
/** Returns the session nonce when the cookie is valid, else null. */
function session_check(?array $auth): ?string {
    if (!$auth || empty($_COOKIE[DM_COOKIE])) return null;
    $parts = explode('.', (string)$_COOKIE[DM_COOKIE]);
    if (count($parts) !== 2) return null;
    [$payload, $sig] = $parts;
    if (!hash_equals(b64u(hash_hmac('sha256', $payload, $auth['secret'], true)), $sig)) return null;
    $d = json_decode(b64u_dec($payload), true);
    if (!is_array($d) || (int)($d['exp'] ?? 0) < time() || empty($d['n'])) return null;
    return (string)$d['n'];
}
function csrf_token(): string {
    $auth = $GLOBALS['DM_AUTH'] ?? null; $n = $GLOBALS['DM_SESSION'] ?? null;
    return ($auth && $n) ? b64u(hash_hmac('sha256', 'csrf|' . $n, $auth['secret'], true)) : '';
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function wants_json(): bool { return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'); }
/** Browsers send the page's origin with every form post; a post from another website is refused. */
function origin_ok(): bool {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') return $origin === '';
    return strtolower((string)parse_url($origin, PHP_URL_HOST)) === strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
}
/** Every POST must carry the session's CSRF token and come from this site. */
function csrf_require(): void {
    $sent = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    $ok = $sent !== '' && hash_equals(csrf_token(), $sent) && origin_ok();
    if (!$ok) {
        if (wants_json()) json_out(['ok' => false, 'error' => 'Oturum süresi doldu, sayfayı yenileyin.'], 403);
        http_response_code(403);
        exit('Güvenlik kontrolü başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
    }
}
