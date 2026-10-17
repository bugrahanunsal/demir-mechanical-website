<?php
/**
 * Demir Mechanical & Construction — website form handler (Hostinger).
 * Receives the "Request Service" (home page) and "Request a Free Quote" (contact page) forms
 * and emails them to the business.
 *
 * Settings live in form-config.php (not part of the website package, so uploads never overwrite it).
 * Copy form-config.sample.php to form-config.php and add the mailbox password there.
 * With a password the email goes out through Hostinger's mail server (SMTP, best delivery);
 * without one the server's basic mail() function is used as a fallback.
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

date_default_timezone_set('America/Toronto');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$cfg = [
    'to'            => 'info@demirmechanical.com',
    'from'          => 'info@demirmechanical.com',
    'smtp_password' => '',
    'smtp_host'     => 'smtp.hostinger.com',
    'smtp_port'     => 465,
    'smtp_secure'   => 'ssl',          // 'ssl' (port 465) or 'tls' (port 587)
];
$cfgFile = __DIR__ . '/form-config.php';
if (is_file($cfgFile)) {
    // A typo in form-config.php (missing quote/comma) must never stop the forms: fall back to defaults + mail().
    try {
        $user = include $cfgFile;
        if (is_array($user)) {
            $cfg = array_merge($cfg, $user);
        }
    } catch (\Throwable $e) {
        error_log('Demir form: form-config.php could not be read, using mail() fallback: ' . $e->getMessage());
    }
}

$wantsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
const PHONE = '+1 (647) 919-3677';

function respond(bool $ok, string $msg, int $code, bool $json): void
{
    http_response_code($code);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $msg]);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $title = $ok ? 'Request received' : 'Request not sent';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . $title . ' | Demir Mechanical</title><style>'
        . 'body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f3f6f9;color:#1a1f26;'
        . 'display:grid;place-items:center;min-height:100vh;padding:24px;box-sizing:border-box}'
        . 'main{max-width:30rem;background:#fff;border:1px solid #e2e7ec;border-radius:11px;padding:32px;text-align:center}'
        . 'h1{font-size:1.5rem;margin:0 0 8px}a{color:#2f6aa8;font-weight:700}</style></head><body><main>'
        . '<h1>' . $title . '</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="/">&larr; Back to the website</a></p></main></body></html>';
    exit;
}

function cut(string $s, int $max): string
{
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

function field(string $key, int $max): string
{
    return cut((string)($_POST[$key] ?? ''), $max);
}

/** Single line: removes line breaks so nothing can be injected into email headers. */
function oneLine(string $s): string
{
    return trim((string)preg_replace('/[\r\n\t\x00-\x1F\x7F]+/u', ' ', $s));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(false, 'Please use the form on our website.', 405, $wantsJson);
}

// Spam traps: a hidden field people never see, and a minimum time between page load and submit.
// Bots get a normal "thank you" so they learn nothing, and no email is sent.
$honeypot = (string)($_POST['website'] ?? '');
$t0 = (int)($_POST['t'] ?? 0);
$t1 = (int)($_POST['e'] ?? 0);
if ($honeypot !== '' || ($t0 > 0 && $t1 > 0 && ($t1 - $t0) < 2500)) {
    respond(true, 'Thanks! We will be in touch shortly.', 200, $wantsJson);
}
// The website's own script stamps every form with the time the page opened (field "t").
// A post without it was not sent from our page (spam script / direct POST): send nothing.
if ($t0 <= 0) {
    respond(false, 'Please send your request using the form on our website, or call us at ' . PHONE . '.', 400, $wantsJson);
}

// Simple flood limit: at most 5 requests per 10 minutes from one address.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rlFile = rtrim(sys_get_temp_dir(), '/\\') . '/dm_form_' . md5($ip);
$now = time();
$hits = [];
if (is_readable($rlFile)) {
    foreach (explode(',', (string)@file_get_contents($rlFile)) as $h) {
        if ((int)$h > $now - 600) {
            $hits[] = (int)$h;
        }
    }
}
if (count($hits) >= 5) {
    respond(false, 'Too many requests in a short time. Please call us at ' . PHONE . '.', 429, $wantsJson);
}

$form    = field('form', 20) === 'contact' ? 'contact' : 'quote';
$name    = oneLine(field('name', 100));
$phone   = oneLine(field('phone', 40));
$email   = oneLine(field('email', 150));
$city    = oneLine(field('city', 80));
$service = oneLine(field('service', 80));
$message = field('message', 4000);
$page    = oneLine(field('page', 200));

$digits = (string)preg_replace('/\D+/', '', $phone);
$errors = [];
if ($name === '') {
    $errors[] = 'name';
}
if (strlen($digits) < 7 || strlen($digits) > 15) {
    $errors[] = 'phone';
}
if ($service === '') {
    $errors[] = 'service';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if ($errors) {
    respond(false, 'Please enter your name, a valid phone number and the service you need.', 422, $wantsJson);
}

$hits[] = $now;
@file_put_contents($rlFile, implode(',', $hits), LOCK_EX);

$kind = $form === 'contact' ? 'Quote request (contact page)' : 'Service request (home page)';
$subject = 'Website ' . ($form === 'contact' ? 'quote request' : 'service request') . ': ' . $service . ' – ' . $name;
$lines = [
    $kind . ' from demirmechanical.com',
    '',
    'Name:     ' . $name,
    'Phone:    ' . $phone,
    'Email:    ' . ($email !== '' ? $email : '—'),
    'City:     ' . ($city !== '' ? $city : '—'),
    'Service:  ' . $service,
];
if ($message !== '') {
    $lines[] = '';
    $lines[] = 'Message:';
    $lines[] = $message;
}
$lines[] = '';
$lines[] = '—';
$lines[] = 'Page: ' . ($page !== '' ? $page : (string)($_SERVER['HTTP_REFERER'] ?? 'website'));
$lines[] = 'Received: ' . date('D, M j, Y g:i A T');
$body = implode("\n", $lines);

$replyTo = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
$sent = false;

// 1) Hostinger SMTP (when a mailbox password is set in form-config.php)
if ((string)$cfg['smtp_password'] !== '' && is_file(__DIR__ . '/lib/phpmailer/PHPMailer.php')) {
    require_once __DIR__ . '/lib/phpmailer/Exception.php';
    require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/lib/phpmailer/SMTP.php';
    try {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string)$cfg['smtp_host'];
        $m->Port = (int)$cfg['smtp_port'];
        $m->SMTPAuth = true;
        $m->Username = (string)$cfg['from'];
        $m->Password = (string)$cfg['smtp_password'];
        $secure = strtolower((string)$cfg['smtp_secure']);
        if ($secure === 'ssl') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $m->SMTPSecure = '';
            $m->SMTPAutoTLS = false;
        }
        $m->Timeout = 15;
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->setFrom((string)$cfg['from'], 'Demir Mechanical Website');
        $m->addAddress((string)$cfg['to']);
        if ($replyTo !== '') {
            $m->addReplyTo($replyTo, $name);
        }
        $m->Subject = $subject;
        $m->isHTML(false);
        $m->Body = $body;
        $m->send();
        $sent = true;
    } catch (\Throwable $e) {
        error_log('Demir form: SMTP failed, trying mail(): ' . $e->getMessage());
    }
}

// 2) Fallback: the server's mail() function
if (!$sent && function_exists('mail')) {
    $headers = [
        'From: Demir Mechanical Website <' . $cfg['from'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent = @mail((string)$cfg['to'], $encodedSubject, $body, implode("\r\n", $headers), '-f' . $cfg['from']);
    if (!$sent) {
        error_log('Demir form: mail() failed');
    }
}

if (!$sent) {
    respond(false, 'Sorry, your request could not be sent. Please call us at ' . PHONE . '.', 500, $wantsJson);
}
respond(true, 'Thanks! We will call you back shortly at the number you provided.', 200, $wantsJson);
