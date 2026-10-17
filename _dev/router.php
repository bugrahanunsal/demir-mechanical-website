<?php
/**
 * Local test server that behaves like the Hostinger .htaccess rules:
 *   php -S 127.0.0.1:8080 -t . _dev/router.php
 */
$uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$root = dirname(__DIR__);
$deny = '#^/(app|data|lib|_dev|docs|\.git|\.github)(/|$)|(^|/)\.(?!well-known/)|^/(form-config(\.sample)?\.php|README\.md|CLAUDE\.md)$|^/assets/uploads/.*\.(php\d?|phtml|phar|html?|svg)$#i';
if (preg_match($deny, $uri)) { http_response_code(403); header('Content-Type: text/plain'); echo "403 Forbidden\n"; return true; }
$f = $root . $uri;
if (is_file($f)) return false;
if (is_dir($f)) {
    foreach (['index.html', 'index.php'] as $ix) if (is_file(rtrim($f, '/') . '/' . $ix)) {
        if (substr($uri, -1) !== '/') { header('Location: ' . $uri . '/', true, 301); return true; }
        return false;
    }
}
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
if (is_file($root . '/404.html')) readfile($root . '/404.html'); else echo 'Not found';
return true;
