<?php
/**
 * "Şifremi unuttum": the owner gets a one-time link by email and sets a new panel password.
 * The link goes to the recovery address (Hesap & Güvenlik; by default the "to" address in form-config.php,
 * info@demirmechanical.com). It is valid for 30 minutes and works once; only a hash of it is stored
 * (in data/admin-auth.php). Mail goes out like the website forms: Hostinger SMTP with the mailbox in
 * form-config.php, or the server's mail() function. The link always points to the site's own address
 * (content site.url), never to the host name of the request, so a forged request cannot redirect it.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const DM_RESET_MIN = 30;

/** form-config.php merged over the same defaults as send.php. */
function mail_config(): array {
    $cfg = ['to' => 'info@demirmechanical.com', 'from' => 'info@demirmechanical.com', 'smtp_password' => '', 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl'];
    $f = DM_ROOT . '/form-config.php';
    if (is_file($f)) {
        if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);      // the owner may just have edited it
        try { $u = include $f; if (is_array($u)) $cfg = array_merge($cfg, $u); } catch (\Throwable $e) {}
    }
    return $cfg;
}
function recovery_email(?array $auth = null): string {
    $auth ??= $GLOBALS['DM_AUTH'] ?? null;
    $e = trim((string)($auth['email'] ?? ''));
    if ($e === '') $e = trim((string)mail_config()['to']);
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}
/** i••••@demirmechanical.com */
function mask_email(string $e): string {
    [$u, $d] = array_pad(explode('@', $e, 2), 2, '');
    $n = mb_strlen($u);
    return mb_substr($u, 0, $n > 2 ? 2 : 1) . str_repeat('•', max(3, min(6, $n - 1))) . '@' . $d;
}
/** Send one email (plain text + HTML). Returns '' when sent, otherwise the reason. */
function admin_mail(string $to, string $subject, string $text, string $html): string {
    $cfg = mail_config();
    $from = (string)$cfg['from'];
    if ((string)$cfg['smtp_password'] !== '' && is_file(DM_ROOT . '/lib/phpmailer/PHPMailer.php')) {
        require_once DM_ROOT . '/lib/phpmailer/Exception.php';
        require_once DM_ROOT . '/lib/phpmailer/PHPMailer.php';
        require_once DM_ROOT . '/lib/phpmailer/SMTP.php';
        try {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host = (string)$cfg['smtp_host']; $m->Port = (int)$cfg['smtp_port'];
            $m->SMTPAuth = true; $m->Username = $from; $m->Password = (string)$cfg['smtp_password'];
            $sec = strtolower((string)$cfg['smtp_secure']);
            if ($sec === 'ssl') $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            elseif ($sec === 'tls') $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            else { $m->SMTPSecure = ''; $m->SMTPAutoTLS = false; }
            $m->Timeout = 15;
            $m->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $m->setFrom($from, short_name() . ' Panel');
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->isHTML(true); $m->Body = $html; $m->AltBody = $text;
            $m->send();
            return '';
        } catch (\Throwable $e) {
            error_log('Demir panel: reset mail over SMTP failed: ' . $e->getMessage());
        }
    }
    if (function_exists('mail')) {
        $headers = ['From: ' . short_name() . ' Panel <' . $from . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'];
        if (@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, implode("\r\n", $headers), '-f' . $from)) return '';
    }
    return 'E-posta gönderilemedi.';
}
function reset_link(string $token): string { return site_url() . '/admin/index.php?p=reset&t=' . rawurlencode($token); }
/** The stored reset request when $token is the right, unexpired one. */
function reset_valid(string $token): bool {
    $r = $GLOBALS['DM_AUTH']['reset'] ?? null;
    return is_array($r) && $token !== '' && preg_match('/^[A-Za-z0-9_\-]{30,80}$/', $token)
        && (int)($r['exp'] ?? 0) >= time() && hash_equals((string)($r['h'] ?? ''), hash('sha256', $token));
}

/* ---------------- screens (sign-in layout) ---------------- */
function auth_badge(string $icon, string $cls = ''): string { return '<span class="auth-badge' . ($cls !== '' ? ' ' . $cls : '') . '">' . aic($icon) . '</span>'; }
function auth_back(): string { return '<a class="auth-back" href="index.php">' . '← Girişe dön</a>'; }
function view_forgot(string $err = ''): string {
    $to = recovery_email();
    $o = auth_badge('key') . '<span class="auth-step">Şifre sıfırlama</span><h1 class="auth-h">Şifrenizi mi unuttunuz?</h1>';
    if ($to === '') {
        return $o . '<p class="auth-sub">Kayıtlı bir kurtarma e-postası bulunamadı, bu yüzden bağlantı gönderilemiyor.</p>' . reset_manual(true) . auth_back();
    }
    $o .= '<p class="auth-sub">Yeni şifre belirlemeniz için tek kullanımlık bir bağlantıyı kayıtlı e-posta adresine göndereceğiz.</p>';
    if ($err !== '') $o .= '<div class="flash bad">' . ic('alert') . '<div>' . $err . '</div></div>';
    return $o . '<div class="auth-mail">' . aic('mail') . '<div><small>Gönderilecek adres</small><b>' . e(mask_email($to)) . '</b></div></div>'
        . '<form method="post" class="authf"><input type="hidden" name="a" value="forgot">'
        . '<button type="submit" class="btn primary wide auth-btn" data-busy="Gönderiliyor…"><span>Sıfırlama bağlantısı gönder</span>' . ic('arrow') . '</button></form>'
        . reset_manual(false) . auth_back();
}
function view_forgot_sent(): string {
    $to = recovery_email();
    return auth_badge('mail', 'ok') . '<span class="auth-step">Şifre sıfırlama</span><h1 class="auth-h">E-postanızı kontrol edin</h1>'
        . '<p class="auth-sub"><b>' . e(mask_email($to)) . '</b> adresine bir bağlantı gönderdik. Bağlantıya tıklayıp yeni şifrenizi belirleyin.</p>'
        . '<ul class="auth-list"><li>' . ic('clock') . '<span>Bağlantı <b>' . DM_RESET_MIN . ' dakika</b> geçerli ve bir kez kullanılabilir.</span></li>'
        . '<li>' . ic('alert') . '<span>Birkaç dakikada gelmezse <b>Spam / Gereksiz</b> klasörüne bakın.</span></li></ul>'
        . '<form method="post" class="authf"><input type="hidden" name="a" value="forgot">'
        . '<button type="submit" class="btn wide auth-btn ghost-btn" data-countdown="60" data-busy="Gönderiliyor…"><span>Bağlantıyı tekrar gönder</span></button></form>'
        . reset_manual(false) . auth_back();
}
function view_reset(string $token, string $err = ''): string {
    $o = auth_badge('lock') . '<span class="auth-step">Şifre sıfırlama</span><h1 class="auth-h">Yeni şifre belirleyin</h1>'
        . '<p class="auth-sub">En az 10 karakter. Harf, rakam ve işaret karıştırırsanız daha güçlü olur.</p>';
    if ($err !== '') $o .= '<div class="flash bad">' . ic('alert') . '<div>' . e($err) . '</div></div>';
    return $o . '<form method="post" class="authf" action="index.php?p=reset"><input type="hidden" name="a" value="reset"><input type="hidden" name="t" value="' . e($token) . '">'
        . pw_field('Yeni şifre', 'pw', ' required minlength="10" autocomplete="new-password" autofocus data-pw-meter')
        . '<div class="pw-meter" aria-hidden="true"><i></i><i></i><i></i><i></i></div><p class="pw-meter-t" aria-live="polite"></p>'
        . pw_field('Yeni şifre (tekrar)', 'pw2', ' required minlength="10" autocomplete="new-password"')
        . '<button type="submit" class="btn primary wide auth-btn" data-busy="Kaydediliyor…"><span>Şifreyi kaydet ve giriş yap</span>' . ic('arrow') . '</button></form>'
        . '<p class="auth-note">' . aic('lock') . '<span>Kaydedince diğer bütün cihazlardaki oturumlar kapanır.</span></p>';
}
function view_reset_bad(): string {
    return auth_badge('alert', 'bad') . '<span class="auth-step">Şifre sıfırlama</span><h1 class="auth-h">Bağlantı geçersiz</h1>'
        . '<p class="auth-sub">Bu bağlantının süresi dolmuş, daha önce kullanılmış ya da yerine yenisi gönderilmiş. Yeni bir bağlantı isteyin.</p>'
        . '<a class="btn primary wide auth-btn" href="index.php?p=forgot"><span>Yeni bağlantı iste</span>' . ic('arrow') . '</a>' . auth_back();
}
/** The way that needs no email: delete the login file in Hostinger's File Manager. */
function reset_manual(bool $open): string {
    return '<details class="auth-forgot"' . ($open ? ' open' : '') . '><summary>E-postaya ulaşamıyor musunuz?</summary>'
        . '<p>Hostinger → <b>Dosya Yöneticisi</b> → public_html → <b>data</b> klasöründeki <b>admin-auth.php</b> dosyasını silin, sonra bu sayfayı yenileyin. Kurulum ekranı açılır ve yeni şifre belirlersiniz. Site içeriği silinmez.</p></details>';
}

/* ---------------- actions ---------------- */
function act_forgot(): never {
    $a = $GLOBALS['DM_AUTH'];
    $to = recovery_email($a);
    if ($to === '') out('Şifremi unuttum', '', view_forgot(), false);
    if ($b = rl_blocked('forgot', 5, 3600)) out('Şifremi unuttum', '', view_forgot('Çok fazla istek. ' . $b . ' dakika sonra tekrar deneyin.'), false, 429);
    if ((int)($a['reset']['at'] ?? 0) > time() - 60) out('E-postanızı kontrol edin', '', view_forgot_sent(), false);     // just sent: do not send twice
    rl_hit('forgot', 3600);
    $token = b64u(random_bytes(32));
    $link = reset_link($token);
    $brand = short_name();
    $text = "Merhaba,\n\n$brand yönetim paneli için şifre sıfırlama isteği aldık. Yeni şifre belirlemek için bu bağlantıyı açın:\n\n$link\n\n"
        . 'Bağlantı ' . DM_RESET_MIN . " dakika geçerlidir ve bir kez kullanılabilir.\nBu isteği siz yapmadıysanız bu e-postayı yok sayın; şifreniz değişmez.\n\n"
        . 'İstek: ' . date('j.m.Y H:i') . ' (Toronto saati)';
    $html = '<div style="background:#f3f5f8;padding:32px 16px;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1a1f26">'
        . '<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #e2e7ec;border-radius:14px;overflow:hidden">'
        . '<div style="background:#0d1b2e;color:#fff;padding:18px 26px;font-weight:700;font-size:16px;letter-spacing:.02em">' . e($brand) . ' · Yönetim Paneli</div>'
        . '<div style="padding:28px 26px 26px"><h1 style="font-size:21px;margin:0 0 10px">Şifre sıfırlama</h1>'
        . '<p style="margin:0 0 22px;line-height:1.6;color:#5b6673">Panel için şifre sıfırlama isteği aldık. Yeni şifrenizi belirlemek için düğmeye tıklayın.</p>'
        . '<a href="' . e($link) . '" style="display:inline-block;background:#2f6aa8;color:#fff;text-decoration:none;font-weight:700;padding:13px 24px;border-radius:10px">Yeni şifre belirle</a>'
        . '<p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#5b6673">Bağlantı ' . DM_RESET_MIN . ' dakika geçerlidir ve bir kez kullanılabilir. Bu isteği siz yapmadıysanız bu e-postayı yok sayın; şifreniz değişmez.</p>'
        . '<p style="margin:16px 0 0;font-size:12px;color:#8b95a1;word-break:break-all">Düğme çalışmazsa: ' . e($link) . '</p></div></div></div>';
    $err = admin_mail($to, $brand . ' paneli: şifre sıfırlama', $text, $html);
    if ($err !== '') out('Şifremi unuttum', '', view_forgot('E-posta gönderilemedi. Sunucunun e-posta ayarını (form-config.php) kontrol edin ya da aşağıdaki "E-postaya ulaşamıyor musunuz?" yolunu kullanın.'), false, 500);
    $a['reset'] = ['h' => hash('sha256', $token), 'exp' => time() + DM_RESET_MIN * 60, 'at' => time()];
    auth_save($a);
    $GLOBALS['DM_AUTH'] = $a;
    out('E-postanızı kontrol edin', '', view_forgot_sent(), false);
}
function act_reset(): never {
    $t = (string)($_POST['t'] ?? '');
    if ($b = rl_blocked('reset', 10, 900)) out('Şifre sıfırlama', '', view_reset_bad(), false, 429);
    if (!reset_valid($t)) { rl_hit('reset', 900); out('Şifre sıfırlama', '', view_reset_bad(), false, 400); }
    $pw = (string)($_POST['pw'] ?? '');
    if ($pw !== (string)($_POST['pw2'] ?? '')) out('Yeni şifre', '', view_reset($t, 'İki şifre aynı değil.'), false, 400);
    if ($m = password_ok_for_policy($pw)) out('Yeni şifre', '', view_reset($t, $m), false, 400);
    $a = $GLOBALS['DM_AUTH'];
    $a['hash'] = password_hash($pw, PASSWORD_DEFAULT);
    $a['secret'] = bin2hex(random_bytes(32));          // every old session ends
    unset($a['reset']);
    auth_save($a);
    $GLOBALS['DM_AUTH'] = $a;
    rl_clear('login'); rl_clear('reset');
    session_issue($a);
    redirect([], '<b>Şifreniz yenilendi.</b> Hoş geldiniz! Diğer cihazlarda yeni şifreyle giriş yapmanız gerekecek.');
}
/** Hesap & Güvenlik: where the reset link goes. */
function act_recovery_email(): never {
    $a = $GLOBALS['DM_AUTH'];
    $fail = fn(string $m) => out('Hesap & Güvenlik', 'account', view_account($m), true, 400);
    if ($b = rl_blocked('login', 8, 900)) $fail('Çok fazla hatalı deneme. ' . $b . ' dakika sonra tekrar deneyin.');
    if (!password_verify((string)($_POST['rcur'] ?? ''), $a['hash'])) { rl_hit('login', 900); $fail('Mevcut şifre yanlış.'); }
    $em = trim((string)($_POST['email'] ?? ''));
    if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) $fail('Geçerli bir e-posta adresi girin.');
    $a['email'] = $em;
    unset($a['reset']);
    auth_save($a);
    $GLOBALS['DM_AUTH'] = $a;
    redirect(['p' => 'account'], '<b>Kurtarma e-postası kaydedildi:</b> ' . e(recovery_email($a) ?: 'yok'));
}
