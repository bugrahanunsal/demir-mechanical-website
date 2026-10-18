#!/usr/bin/env python3
"""
End-to-end test of the admin panel against a throw-away copy of the site.

    python3 _dev/tests/e2e_admin.py [--keep] [--only NAME[,NAME]]

Creates a fresh site in a temp folder (pages built like the live server has them, a form-config.php with a test
mailbox password and an old site-links.js), starts `php -S` with _dev/router.php and drives the panel with Playwright:
setup, login + rate limit, CSRF, every editor, uploads (logo, photos, project gallery), projects + photo viewer,
SEO panel, backups/restore/import/downloads, preview, password change, version rebuild, deploy hook, access rules.
"""
import datetime, hashlib, io, json, os, re, shutil, signal, socket, subprocess, sys, tempfile, time, zipfile, urllib.request, urllib.error, urllib.parse
from PIL import Image, ImageDraw
from playwright.sync_api import sync_playwright
from zoneinfo import ZoneInfo

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
MAILPW = 'Mailbox-Test-Pass-91'
PW1, PW2 = 'Panel-Pass-2026x', 'Second-Pass-2026y'
args = sys.argv[1:]
KEEP = '--keep' in args
ONLY = set(args[args.index('--only') + 1].split(',')) if '--only' in args else None

def free_port():
    s = socket.socket(); s.bind(('127.0.0.1', 0)); p = s.getsockname()[1]; s.close(); return p

TMP = tempfile.mkdtemp(prefix='dm-e2e-')
SITE = os.path.join(TMP, 'site'); IMG = os.path.join(TMP, 'img')
PORT = free_port(); BASE = f'http://127.0.0.1:{PORT}'; ADMIN = BASE + '/admin/'
results = []; server = None

# ---------------------------------------------------------------- helpers
def check(cond, msg):
    results.append((bool(cond), msg))
    print(('  ok  ' if cond else '  FAIL ') + msg, flush=True)
    return bool(cond)

def rd(rel):
    with open(os.path.join(SITE, rel), encoding='utf-8') as f: return f.read()

def content():
    return json.loads(rd('data/content.json'))

def exists(rel): return os.path.exists(os.path.join(SITE, rel))

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
OPENER = urllib.request.build_opener(NoRedirect)

def http(path, method='GET', data=None, headers=None):
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=headers or {})
    try:
        with OPENER.open(req) as r: return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e: return e.code, e.read().decode('utf-8', 'replace')

def make_site():
    os.makedirs(SITE)
    for name in os.listdir(REPO):
        if name in ('.git', 'data') or name.endswith('.html'): continue
        src = os.path.join(REPO, name)
        (shutil.copytree if os.path.isdir(src) else shutil.copy2)(src, os.path.join(SITE, name))
    os.makedirs(os.path.join(SITE, 'data'), exist_ok=True)
    shutil.copy2(os.path.join(REPO, 'data/.htaccess'), os.path.join(SITE, 'data/.htaccess'))
    up = os.path.join(SITE, 'assets/uploads')
    for n in os.listdir(up):
        if n != '.htaccess': shutil.rmtree(os.path.join(up, n), ignore_errors=True)
    subprocess.run(['php', os.path.join(SITE, '_dev/build.php'), '--out=' + SITE, '--content=' + os.path.join(SITE, 'app/content.default.json')], check=True, capture_output=True)
    with open(os.path.join(SITE, 'form-config.php'), 'w') as f:
        f.write("<?php\nreturn ['to' => 'info@demirmechanical.com', 'from' => 'info@demirmechanical.com', 'smtp_password' => '%s'];\n" % MAILPW)
    with open(os.path.join(SITE, 'site-links.js'), 'w') as f:          # same layout as the file on the live server
        f.write('/* Demir Mechanical - social media links (footer icons on every page)\n'
                '   HOW TO EDIT: Hostinger > File Manager > public_html > site-links.js > Edit.\n'
                '   Example:   facebook:  "https://www.facebook.com/yourpage",\n'
                '   Keep the quotes and the commas exactly as they are. */\n'
                'window.DM_LINKS = {\n  facebook:  "https://www.facebook.com/demirmechanical",\n  instagram: "",\n  google:    ""   /* Google Business Profile (Google Maps) link */\n};\n')

def start_server():
    global server
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{PORT}', '-t', SITE, os.path.join(SITE, '_dev/router.php')],
                              cwd=SITE, stdout=subprocess.DEVNULL, stderr=open(os.path.join(TMP, 'server.log'), 'w'))
    for _ in range(50):
        try: socket.create_connection(('127.0.0.1', PORT), timeout=0.2).close(); return
        except OSError: time.sleep(0.1)
    raise SystemExit('server did not start')

def make_images():
    os.makedirs(IMG)
    def photo(name, w, h, color, exif_orient=None, fmt='JPEG'):
        im = Image.new('RGB', (w, h), color); d = ImageDraw.Draw(im)
        for i in range(0, w, 40): d.line([(i, 0), (i + h, h)], fill=(color[0] // 2, color[1] // 2, color[2] // 2), width=6)
        d.rectangle([w * 0.1, h * 0.1, w * 0.4, h * 0.3], fill=(255, 255, 255))   # white box top-left = "up"
        p = os.path.join(IMG, name)
        kw = {'quality': 95}
        if exif_orient:
            ex = Image.Exif(); ex[0x0112] = exif_orient; kw['exif'] = ex.tobytes()
        im.save(p, fmt, **kw); return p
    P = {}
    P['big'] = photo('IMG_4031.jpg', 4000, 3000, (40, 110, 170))        # phone-size photo, bad file name
    P['rot'] = photo('rotated.jpg', 1200, 900, (170, 90, 40), exif_orient=6)
    for i in range(5): P[f'g{i}'] = photo(f'site-photo-{i}.jpg', 1600 + i * 10, 1200, (60 + i * 30, 140, 90 + i * 20))
    logo = Image.new('RGBA', (900, 400), (0, 0, 0, 0)); d = ImageDraw.Draw(logo)
    d.rectangle([40, 60, 860, 340], fill=(20, 60, 120, 255)); P['logo'] = os.path.join(IMG, 'new-logo.png'); logo.save(P['logo'])
    ico = Image.new('RGBA', (600, 600), (30, 90, 160, 255)); P['ico'] = os.path.join(IMG, 'icon.png'); ico.save(P['ico'])
    P['txt'] = os.path.join(IMG, 'notes.jpg'); open(P['txt'], 'w').write('this is not an image')
    return P

# ---------------------------------------------------------------- browser helpers
class Panel:
    def __init__(self, page): self.p = page
    def go(self, q=''):
        self.p.goto(ADMIN + ('index.php?p=' + q if q else '')); self.p.wait_for_load_state()
    def flash(self):
        el = self.p.locator('.flash').first
        return el.inner_text() if el.count() else ''
    def save(self, value='save_sec'):
        with self.p.expect_navigation():
            self.p.click(f'button[name=a][value={value}]')
        self.p.wait_for_load_state()
    def field(self, path): return self.p.locator(f'[name="f[{path}]"]').last

# ---------------------------------------------------------------- tests
def t_setup(pn, P):
    p = pn.p
    pn.go()
    check(p.locator('h1').inner_text() == 'Paneli kurun', 'first visit shows the setup page')
    p.fill('[name=proof]', 'wrong'); p.fill('[name=pw]', PW1); p.fill('[name=pw2]', PW1); p.click('button[type=submit]'); p.wait_for_load_state()
    check('doğru değil' in pn.flash(), 'setup refuses a wrong mailbox password')
    p.fill('[name=proof]', MAILPW); p.fill('[name=pw]', PW1); p.fill('[name=pw2]', PW1 + 'x'); p.click('button[type=submit]'); p.wait_for_load_state()
    check('aynı değil' in pn.flash(), 'setup refuses mismatched passwords')
    p.fill('[name=proof]', MAILPW); p.fill('[name=pw]', 'short1'); p.fill('[name=pw2]', 'short1')
    p.evaluate("document.querySelectorAll('[minlength]').forEach(e=>e.removeAttribute('minlength'))")
    p.click('button[type=submit]'); p.wait_for_load_state()
    check('10 karakter' in pn.flash(), 'setup refuses a short password')
    p.fill('[name=proof]', MAILPW); p.fill('[name=pw]', MAILPW); p.fill('[name=pw2]', MAILPW); p.click('button[type=submit]'); p.wait_for_load_state()
    check('farklı' in pn.flash(), 'panel password must differ from the mailbox password')
    p.fill('[name=proof]', MAILPW); p.fill('[name=pw]', PW1); p.fill('[name=pw2]', PW1); p.click('button[type=submit]'); p.wait_for_load_state()
    check('Panel kuruldu' in pn.flash(), 'setup succeeds and shows the welcome message')
    check(p.locator('h1').inner_text() == 'Hoş geldiniz', 'dashboard opens after setup')
    c = content()
    check(c['links']['facebook'] == 'https://www.facebook.com/demirmechanical', 'Facebook link imported from the old site-links.js')
    idx = rd('index.html')
    check('site-links.js' not in idx, 'pages no longer load site-links.js')
    check('href="https://www.facebook.com/demirmechanical"' in idx and 'class="fsocial"' in idx, 'Facebook icon rendered in the footer')
    check('"sameAs": ["https://www.facebook.com/demirmechanical"]' in idx, 'Facebook link in Google business data (sameAs)')
    pub = json.loads(rd('data/published.json'))
    check(len(pub['files']) == 35 and pub['version'], 'published.json lists 35 files with the code version')
    a = rd('data/admin-auth.php')
    check(PW1 not in a and '"hash":"$2y$' in a and a.startswith('<?php http_response_code(404); exit;'), 'password stored only as a hash, file cannot be opened from the web')
    pn.go()
    check(p.locator('h1').inner_text() == 'Hoş geldiniz', 'setup page is gone once the panel is set up')

def t_login(pn, P):
    p = pn.p
    p.locator('.logout').click(); p.wait_for_load_state()
    check(p.locator('h1').inner_text() == 'Tekrar hoş geldiniz', 'logout shows the login page')
    pn.go('sec&s=business')
    check(p.locator('h1').inner_text() == 'Tekrar hoş geldiniz', 'editor pages need login')
    for i in range(7):
        p.fill('[name=pw]', 'wrong-password-%d' % i); p.click('button[type=submit]'); p.wait_for_load_state()
    check('yanlış' in pn.flash(), 'wrong password message')
    p.fill('[name=pw]', 'wrong-password-8'); p.click('button[type=submit]'); p.wait_for_load_state()
    check('Çok fazla' in pn.flash(), 'login is blocked after 8 wrong passwords')
    p.fill('[name=pw]', PW1); p.click('button[type=submit]'); p.wait_for_load_state()
    check('Çok fazla' in pn.flash(), 'blocked even with the right password during the lock')
    shutil.rmtree(os.path.join(SITE, 'data/ratelimit'))
    p.fill('[name=pw]', PW1); p.click('button[type=submit]'); p.wait_for_load_state()
    check(p.locator('h1').inner_text() == 'Hoş geldiniz', 'login works with the right password')

def t_login_look(pn, P):
    # the sign-in page: photo side, password with show/hide, help for a lost password
    ctx = pn.p.context.browser.new_context(viewport={'width': 1366, 'height': 900})
    g = ctx.new_page()
    g.goto(ADMIN); g.wait_for_load_state()
    check(g.locator('.auth-art').is_visible() and g.locator('.auth-art img.auth-bg').count() == 1, 'sign-in page: photo panel on computers')
    pw = g.locator('input[name=pw]')
    check(pw.get_attribute('type') == 'password' and pw.evaluate('e => e === document.activeElement'), 'sign-in page: password field focused')
    g.click('[data-pw-toggle]')
    check(pw.get_attribute('type') == 'text' and g.locator('[data-pw-toggle]').get_attribute('aria-label') == 'Şifreyi gizle', 'sign-in page: the eye button shows the password')
    g.click('[data-pw-toggle]')
    check(pw.get_attribute('type') == 'password', 'sign-in page: and hides it again')
    g.click('.auth-forgot summary')
    check('admin-auth.php' in g.locator('.auth-forgot').inner_text(), 'sign-in page: "Şifremi unuttum" explains the reset')
    ctx.close()
    m = pn.p.context.browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
    g = m.new_page(); g.goto(ADMIN); g.wait_for_load_state()
    sw = g.evaluate('document.documentElement.scrollWidth')
    btn = g.locator('.auth-btn').bounding_box()
    check(sw <= 390 and btn and btn['y'] + btn['height'] <= 844, f'sign-in page on a phone: fits the screen, button visible ({sw}px)')
    m.close()

def t_security(pn, P):
    for path, code in [('/data/content.json', 403), ('/data/invoices.json', 403), ('/data/admin-auth.php', 403), ('/app/lib.php', 403), ('/app/content.default.json', 403),
                       ('/form-config.php', 403), ('/lib/phpmailer/PHPMailer.php', 403), ('/_dev/build.php', 403), ('/.htaccess', 403),
                       ('/admin/', 200), ('/', 200), ('/missing-page.html', 404)]:
        st, _ = http(path)
        check(st == code, f'GET {path} -> {code} (got {st})')
    # posts without the CSRF token, or from another website, are refused
    cookies = {c['name']: c['value'] for c in pn.p.context.cookies()}
    ck = 'dm_admin=' + cookies['dm_admin']
    st, body = http('/admin/index.php', 'POST', b'a=rebuild', {'Cookie': ck, 'Content-Type': 'application/x-www-form-urlencoded'})
    check(st == 403, 'POST without CSRF token is refused')
    tok = pn.p.locator('body').get_attribute('data-csrf')
    st, _ = http('/admin/index.php', 'POST', ('a=rebuild&_csrf=' + tok).encode(), {'Cookie': ck, 'Content-Type': 'application/x-www-form-urlencoded', 'Origin': 'https://evil.example'})
    check(st == 403, 'POST from another website is refused even with a token')
    st, _ = http('/admin/index.php', 'POST', ('a=rebuild&_csrf=' + tok).encode(), {'Cookie': ck, 'Content-Type': 'application/x-www-form-urlencoded', 'Origin': BASE})
    check(st == 303, 'same-site POST with token works')
    st, _ = http('/admin/index.php', 'POST', ('a=rebuild&_csrf=' + tok).encode(), {'Cookie': 'dm_admin=forged.' + 'x' * 40, 'Content-Type': 'application/x-www-form-urlencoded'})
    check(st == 200, 'forged session cookie gets the login page')
    # flash cookie planted by someone else is ignored
    pn.p.context.add_cookies([{'name': 'dm_flash', 'value': 'eyJ4Ijoi.PHNjcmlwdD4', 'url': ADMIN}])
    pn.go()
    check(pn.p.locator('.flash').count() == 0, 'unsigned flash cookie is ignored')
    # uploaded files cannot be executed
    os.makedirs(os.path.join(SITE, 'assets/uploads/photos'), exist_ok=True)
    open(os.path.join(SITE, 'assets/uploads/photos/x.php'), 'w').write('<?php echo "RUN";')
    st, body = http('/assets/uploads/photos/x.php')
    check(st == 403 and 'RUN' not in body, 'PHP file in uploads is not run')
    os.remove(os.path.join(SITE, 'assets/uploads/photos/x.php'))

def t_business(pn, P):
    p = pn.p
    pn.go('sec&s=business')
    pn.field('contact.phone').fill('+1 (647) 555-0199')
    pn.field('contact.email').fill('not-an-email')
    pn.save()
    check('düzeltme' in pn.flash() and p.locator('.ferr').count() >= 1, 'invalid email: not saved, field marked')
    check(pn.field('contact.phone').input_value() == '+1 (647) 555-0199', 'typed values are kept after a validation error')
    check(content()['contact']['phone'] == '+1 (647) 919-3677', 'content unchanged after a validation error')
    pn.field('contact.email').fill('info@demirmechanical.com')
    pn.field('contact.hours_short').fill('Mon–Sat 7am–7pm · 24/7 emergencies')
    pn.save()
    check('Kaydedildi' in pn.flash(), 'business info saved')
    idx = rd('index.html'); about = rd('about.html')
    check('tel:+16475550199' in idx and '+1 (647) 555-0199' in about, 'new phone on every page')
    check('Mon–Sat 7am–7pm · 24/7 emergencies' in rd('contact.html'), 'new hours on the contact page')
    check('"telephone": "+1-647-555-0199"' in idx, 'new phone in Google business data')
    check(len([f for f in os.listdir(os.path.join(SITE, 'data/backups')) if f.endswith('.json')]) >= 1, 'a backup was made before saving')
    # put the real phone back
    pn.go('sec&s=business'); pn.field('contact.phone').fill('+1 (647) 919-3677'); pn.save()
    check('tel:+16479193677' in rd('index.html'), 'phone restored')

def t_menu(pn, P):
    p = pn.p
    pn.go('sec&s=menu')
    rows = p.locator('.fmenu .rep-items > .menu-row')
    n = rows.count()
    check(n == 7, 'menu editor lists the 7 menu items')
    about = p.locator('.fmenu .menu-row', has=p.locator('input[value=about][type=hidden]'))
    about.locator('input[type=text]').fill('About Us')
    faq = p.locator('.fmenu .menu-row', has=p.locator('input[value=faq][type=hidden]'))
    faq.locator('input[type=checkbox]').uncheck()
    contact = p.locator('.fmenu .menu-row', has=p.locator('input[value=contact][type=hidden]'))
    contact.locator('.rep-up').click()                                  # Contact before About
    p.click('.fmenu .rep-add')
    new = p.locator('.fmenu .rep-items > .menu-row').last
    new.locator('input[type=text]').nth(0).fill('Careers')
    new.locator('input[type=text]').nth(1).fill('contact.html#form')
    pn.save()
    check('Kaydedildi' in pn.flash(), 'menu saved')
    idx = rd('index.html')
    nav = idx[idx.index('id="navlinks"'):idx.index('</nav>')]
    check('>About Us</a>' in nav, 'renamed menu item')
    check('href="faq.html"' not in nav, 'hidden menu item removed from the menu')
    check(nav.index('href="contact.html"') < nav.index('href="about.html"'), 'menu order changed')
    check('>Careers</a>' in nav and 'href="contact.html#form"' in nav, 'custom link added')
    check(os.path.exists(os.path.join(SITE, 'faq.html')), 'the FAQ page itself still exists')
    # restore the default menu
    m = content()['header']['menu']
    check([x['type'] for x in m] == ['home', 'services', 'areas', 'projects', 'faq', 'contact', 'about', 'link'], 'menu stored in the new order')
    pn.go('sec&s=menu')
    p.once('dialog', lambda d: d.accept())
    p.locator('.fmenu .menu-row', has=p.locator('input[value=link][type=hidden]')).locator('.rep-del').click()
    p.locator('.fmenu .menu-row', has=p.locator('input[value=about][type=hidden]')).locator('.rep-up').click()
    p.locator('.fmenu .menu-row', has=p.locator('input[value=about][type=hidden]')).locator('input[type=text]').fill('About')
    p.locator('.fmenu .menu-row', has=p.locator('input[value=faq][type=hidden]')).locator('input[type=checkbox]').check()
    pn.save()
    check([x['type'] for x in content()['header']['menu']] == ['home', 'services', 'areas', 'projects', 'faq', 'about', 'contact'], 'menu back to the original')

def t_branding(pn, P):
    p = pn.p
    pn.go('sec&s=branding')
    box = p.locator('.imgf[data-kind=logo]')
    box.locator('.imgf-up').set_input_files(P['logo'])
    p.wait_for_selector('.imgf[data-kind=logo]:not(.busy)', timeout=30000)
    newfile = box.locator('.imgf-file').input_value()
    check(newfile.startswith('assets/uploads/logos/') and newfile.endswith('.png'), f'logo uploaded ({newfile})')
    check(box.locator('.imgf-webp').input_value().endswith('.webp'), 'logo also saved as WebP')
    pn.save()
    idx = rd('index.html')
    check(f'src="{newfile}"' in idx and newfile.replace('.png', '.webp') in idx, 'header uses the new logo (PNG + WebP)')
    ratio = content()['branding']['header_logo_ratio']
    check(abs(ratio - 900 / 400) < 0.01, f'logo proportions stored ({ratio})')
    check('width="112" height="50"' in idx, 'logo size attributes follow the new proportions')
    # favicon
    pn.go('sec&s=branding')
    p.locator('.imgf[data-kind=favicon] .imgf-up').set_input_files(P['ico'])
    p.wait_for_selector('.imgf[data-kind=favicon]:not(.busy)', timeout=30000)
    pn.save()
    b = content()['branding']
    check(b['favicon_32'].endswith('-32.png') and b['apple_touch_icon'].endswith('-180.png') and content()['site']['schema_image'].endswith('-512.png'), 'favicon saved in all sizes')
    check(f'href="{b["favicon_32"]}"' in rd('about.html'), 'pages use the new favicon')
    # undo via backups (restores logo + favicon), checked later in t_backups

def upload_and_wait(p, box_sel, path):
    p.locator(box_sel + ' .imgf-up').first.set_input_files(path)
    p.wait_for_selector(box_sel + '.busy', state='detached', timeout=30000)

def t_services(pn, P):
    p = pn.p
    pn.go('item&k=services&i=new')
    pn.field('name').fill('Gas Line Installation')
    check(pn.field('slug').input_value() == 'gas-line-installation', 'address filled in from the name')
    pn.field('short').fill('Safe, code-compliant gas lines for ranges, BBQs and heaters.')
    pn.field('intro').fill('We install and repair natural gas lines for homes and businesses across Toronto.')
    pn.field('included').fill('New gas lines\nBBQ and range hookups\nPressure testing')
    p.select_option('[name="f[icon]"]', 'bolt')
    upload_and_wait(p, '.imgf', P['big'])
    f = p.locator('.imgf .imgf-file').first.input_value()
    check(f.startswith('assets/uploads/photos/gas-line-installation') and f.endswith('.webp'), f'photo named from the service ({f})')
    p.locator('.imgf-alt').first.fill('Plumber connecting a yellow gas line to a kitchen range in Toronto')
    pn.save('save_item')
    check('Kaydedildi' in pn.flash(), 'new service saved')
    page = rd('gas-line-installation.html')
    check('<h1' in page and 'Gas Line Installation' in page, 'new service page exists')
    idx = rd('index.html')
    check('href="gas-line-installation.html"' in idx, 'new service in the menu and on the home page')
    check('gas-line-installation.html' in rd('sitemap.xml'), 'new service in the sitemap')
    check('<option>Gas Line Installation</option>' in rd('contact.html'), 'new service in the contact form')
    im = Image.open(os.path.join(SITE, f))
    check(im.size == (1600, 1200), f'big phone photo resized to 1600px ({im.size})')
    check(exists(f.replace('.webp', '-sm.webp')), 'phone-size copy (-sm.webp) made')
    check(os.path.getsize(os.path.join(SITE, f)) < 400_000, 'resized photo is small')
    # change the address -> old address forwards to the new one
    i = len(content()['services']) - 1
    pn.go(f'item&k=services&i={i}')
    pn.field('slug').fill('gas-lines')
    pn.save('save_item')
    check(exists('gas-lines.html') and '0;url=gas-lines.html' in rd('gas-line-installation.html'), 'renamed page: old address forwards to the new one')
    check('noindex' in rd('gas-line-installation.html'), 'forwarding page is not indexed')
    # address taken
    pn.go(f'item&k=services&i={i}')
    pn.field('slug').fill('drain-cleaning')
    pn.save('save_item')
    check('zaten kullanılıyor' in p.locator('.ferr').first.inner_text(), 'address already used by another page is refused')
    # move up
    pn.go('list&k=services')
    p.locator('.row').nth(i).locator('button[title="Yukarı taşı"]').click(); p.wait_for_load_state()
    names = [s['name'] for s in content()['services']]
    check(names.index('Gas Line Installation') == i - 1, 'service moved up in the list')
    idx = rd('index.html'); nav = idx[idx.index('class="drop mega"'):]
    check(nav.index('riser-renovation.html') < nav.index('gas-lines.html') < nav.index('toilet-installation.html'), 'menu order follows the list')
    # delete
    pn.go(f'item&k=services&i={i-1}')
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('form[data-confirm] button.danger')
    check('silindi' in pn.flash(), 'service deleted')
    check('0;url=services.html' in rd('gas-lines.html'), 'deleted service page forwards to the services page')
    check('0;url=services.html' in rd('gas-line-installation.html'), 'older forward updated too (no chains)')
    check('gas-lines.html' not in rd('index.html'), 'deleted service gone from the menu')
    check(len(content()['services']) == 8, 'back to 8 services')

def t_cities(pn, P):
    p = pn.p
    pn.go('item&k=cities&i=new')
    pn.field('name').fill('Woodbridge')
    pn.field('region').fill('York Region')
    pn.field('blurb').fill('Woodbridge homes range from 1980s detached houses to new townhomes.')
    pn.field('neighbourhoods').fill('Sonoma Heights\nVellore Village\nEast Woodbridge')
    pn.field('lat').fill('43.7834'); pn.field('lng').fill('-79.5960')
    pn.save('save_item')
    check(exists('plumber-woodbridge.html'), 'new city page plumber-woodbridge.html')
    check('href="plumber-woodbridge.html"' in rd('service-areas.html'), 'new city on the service areas page')
    check('Woodbridge' in rd('plumber-vaughan.html'), 'new city shown as a nearby area for Vaughan')
    i = len(content()['cities']) - 1
    pn.go(f'item&k=cities&i={i}')
    pn.field('lat').fill('abc'); pn.save('save_item')
    check(p.locator('.ferr').count() >= 1, 'non-number coordinate refused')
    pn.go(f'item&k=cities&i={i}')
    pn.field('name').fill('Toronto'); pn.save('save_item')
    check('zaten var' in p.locator('.ferr').first.inner_text(), 'duplicate city name refused')
    pn.go(f'item&k=cities&i={i}')
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('form[data-confirm] button.danger')
    check('0;url=service-areas.html' in rd('plumber-woodbridge.html'), 'deleted city forwards to service areas')

def t_projects(pn, P):
    p = pn.p
    check('href="projects.html"' not in rd('index.html'), 'no Projects menu item while there are no projects')
    pn.go('item&k=projects&i=new')
    pn.field('title').fill('Riser Replacement — North York Condo')
    pn.field('location').fill('North York, ON')
    p.select_option('[name="f[service]"]', 'riser-renovation')
    pn.field('date').fill('2026-09')
    pn.field('summary').fill('Full copper riser replacement in a 12-storey condo, done floor by floor with water back on every evening.')
    pn.field('description').fill('The building had 40-year-old copper risers.\n\nWe replaced them floor by floor.')
    pn.field('highlights').fill('Floor-by-floor shutdowns\nNew isolation valves')
    p.locator('.gal-up').set_input_files([P[f'g{i}'] for i in range(5)])
    p.wait_for_selector('.gal-st:has-text("eklendi")', timeout=60000)
    check(p.locator('.gal-items .gal-item').count() == 5, 'five photos added to the gallery')
    alts = [a.input_value() for a in p.locator('.gal-items .imgf-alt').all()]
    check(alts[0] == 'Riser Replacement — North York Condo' and alts[2].endswith('photo 3'), f'photo descriptions pre-filled ({alts[2]})')
    files = [a.input_value() for a in p.locator('.gal-items .imgf-file').all()]
    check(all(f.startswith('assets/uploads/projects/riser-replacement-north-york-condo') for f in files) and len(set(files)) == 5, 'gallery photos named from the project, all unique')
    # reorder: last photo first (arrow buttons)
    last = p.locator('.gal-items .gal-item').nth(4).element_handle()
    for _ in range(4):
        last.query_selector('.rep-up').click()
    order = [a.input_value() for a in p.locator('.gal-items .imgf-file').all()]
    check(order[0] == files[4], 'photo moved to the front (cover)')
    names = [a.get_attribute('name') for a in p.locator('.gal-items .imgf-file').all()]
    check(names == [f'f[photos][{i}][file]' for i in range(5)], 'gallery fields renumbered after reordering')
    p.locator('.gal-items .gal-item').nth(2).locator('[name$="[caption]"]').fill('New valves on floor 7')
    pn.save('save_item')
    check('Kaydedildi' in pn.flash(), 'project saved')
    slug = content()['projects'][0]['slug']
    check(slug == 'riser-replacement-north-york-condo', f'project address from the title ({slug})')
    page = rd(f'project-{slug}.html')
    check('class="gallery gx"' in page and len(re.findall(r'<a class="g-item[^"]*" href="[^"]+" data-lb', page)) == 5, 'project page with 5-photo gallery')
    check(page.index(files[4].split('/')[-1]) < page.index(files[0].split('/')[-1]), 'cover photo first on the page')
    check('data-cap="New valves on floor 7"' in page, 'photo caption on the page')
    check('September 2026' in page and 'href="riser-renovation.html"' in page, 'date and service shown')
    idx = rd('index.html')
    check('href="projects.html"' in idx and 'id="projects"' in idx, 'Projects menu item and home section appear')
    check(exists('projects.html') and f'project-{slug}.html' in rd('sitemap.xml'), 'projects page + sitemap entry')
    # the photo viewer on the live page
    v = p.context.new_page()
    v.goto(f'{BASE}/project-{slug}.html'); v.wait_for_load_state()
    v.locator('.gallery a[data-lb]').nth(1).click()
    v.wait_for_selector('.lb.on', timeout=5000)
    cnt = v.locator('.lb-c').inner_text()
    v.keyboard.press('ArrowRight'); v.wait_for_timeout(200)
    cnt2 = v.locator('.lb-c').inner_text()
    check(cnt.startswith('2') and cnt2.startswith('3'), f'photo viewer opens and moves ({cnt} -> {cnt2})')
    v.keyboard.press('Escape'); v.wait_for_timeout(400)
    check(v.locator('.lb.on').count() == 0, 'photo viewer closes with Esc')
    v.close()
    # unpublish -> page removed, menu item hidden
    pn.go('item&k=projects&i=0')
    p.locator('[name="f[published]"][type=checkbox]').uncheck()
    pn.save('save_item')
    check(not exists(f'project-{slug}.html') and not exists('projects.html'), 'draft project: pages removed')
    check('href="projects.html"' not in rd('index.html'), 'draft project: Projects menu item hidden')
    pn.go('item&k=projects&i=0')
    p.locator('[name="f[published]"][type=checkbox]').check()
    pn.save('save_item')
    check(exists(f'project-{slug}.html'), 'published again')

def t_reviews_faq(pn, P):
    p = pn.p
    pn.go('sec&s=reviews')
    for q, n, w in [('Fixed our leak the same day.', 'Sarah M.', 'North York · Service & Repairs'), ('Clean, fast, fair price.', 'Daniel K.', 'Vaughan · Drain Cleaning')]:
        p.click('.frep .rep-add')
        it = p.locator('.frep .rep-items > .rep-item').last
        it.locator('textarea').fill(q); it.locator('input[type=text]').nth(0).fill(n); it.locator('input[type=text]').nth(1).fill(w)
    check(p.locator('.frep .rep-items > .rep-item .rep-t').last.inner_text() == 'Daniel K.', 'item title follows the name field')
    pn.save()
    idx = rd('index.html')
    check('id="reviews"' in idx and 'Fixed our leak the same day.' in idx and 'Daniel K.' in idx, 'reviews section on the home page')
    # FAQ: add a question in a nested category
    pn.go('sec&s=faq')
    cat = p.locator('[class~=frep]').nth(1).locator('.rep-items').first.locator(':scope > .rep-item').first
    cat.locator('.rep-tog').first.click()
    cat.locator('.rep-add').first.click()
    q = cat.locator('.rep-items').first.locator(':scope > .rep-item').last
    q.locator('input[type=text]').fill('Do you offer free estimates?')
    q.locator('textarea').fill('Yes — estimates for planned work are free.')
    pn.save()
    check('Do you offer free estimates?' in rd('faq.html'), 'new FAQ question on the FAQ page')
    check('"name": "Do you offer free estimates?"' in rd('faq.html'), 'new FAQ question in Google FAQ data')

def wait_until(p, fn, timeout=8.0):
    end = time.time() + timeout
    while time.time() < end:
        if fn(): return True
        p.wait_for_timeout(150)
    return False

def seo_open(p, path):
    p.locator(f'.srow[data-path="{path}"] a').click()
    p.wait_for_selector(f'#seoModal form.seo-ed[data-path="{path}"]')
    return p.locator('#seoModal form.seo-ed')

def seo_save(p):
    p.locator('#seoModal button[value=seo_save]').click()
    p.wait_for_selector('.toast', timeout=15000)
    txt = p.locator('.toast').last.inner_text()
    p.evaluate("document.querySelectorAll('.toast').forEach(t => t.remove())")
    return txt

def og_meta(html):
    return re.findall(r'<meta property="og:image" content="([^"]*)"', html)

def t_seo(pn, P):
    p = pn.p
    c = content()
    pn.go('seo')
    projects = [x for x in c['projects'] if x.get('published') and x.get('slug')]
    expect = 7 + (1 if projects else 0) + len(c['services']) + len(c['cities']) + len(projects)
    rows = p.locator('.srow')
    check(rows.count() == expect, f'SEO list shows every page ({rows.count()} / {expect})')
    groups = [g.text_content().split(' ')[0] for g in p.locator('.sg-t').all()]
    check(groups == ['Sayfalar', 'Hizmetler', 'Bölgeler'] + (['Projeler'] if projects else []), f'pages grouped: pages, services, areas, projects ({groups})')
    check(p.locator('.srow[data-path="404.html"]').get_attribute('data-st') == 'off', '404 page shown as hidden from Google (grey)')
    check(p.locator('.srow[data-path="index.html"] .srow-g').inner_text() == c['pages']['home']['seo_title'], 'row shows the Google title')
    p.fill('#seoSearch', 'north york')
    vis = [r.get_attribute('data-path') for r in p.locator('.srow:not([hidden])').all()]
    check('plumber-north-york.html' in vis and len(vis) < expect and all('north' in (p.locator(f'.srow[data-path="{v}"]').get_attribute('data-q')) for v in vis), f'search filters the list ({len(vis)} rows)')
    p.fill('#seoSearch', '')
    p.click('#seoChips a[data-f=fix]')
    sts = [r.get_attribute('data-st') for r in p.locator('.srow:not([hidden])').all()]
    check(sts and all(x in ('ok', 'bad') for x in sts) and 'f=fix' in p.url, f'"İyileştirilecek" shows only pages to improve ({len(sts)})')
    p.click('#seoChips a[data-f=""]')
    # the editor window opens without leaving the page
    f = seo_open(p, 'about.html')
    check(p.evaluate("document.querySelector('#seoModal').matches(':modal')") and 'edit=about.html' in p.url, 'editor opens as a window, address remembers the page')
    php_px = int(p.locator('.srow[data-path="about.html"]').get_attribute('data-px'))
    js_px = int(re.search(r'yaklaşık (\d+)', f.locator('[data-meter-txt=title]').inner_text()).group(1))
    check(php_px == js_px, f'title width the same in PHP and JavaScript ({php_px} / {js_px} px)')
    f.locator('[name=title]').fill('About Demir Mechanical & Construction | Licensed Toronto Plumbers Since 2010 and More')
    check('ok' in f.locator('[data-meter=title]').get_attribute('class') and int(re.search(r'yaklaşık (\d+)', f.locator('[data-meter-txt=title]').inner_text()).group(1)) > 600, 'title meter warns over 600 px')
    p.click('#seoModal [data-seo-view=d]')
    check(f.locator('[data-gp-t]').inner_text().endswith(' ...'), 'computer preview cuts a long title like Google')
    p.click('#seoModal [data-seo-view=m]')
    f.locator('[name=kp]').fill('emergency plumber toronto')
    p.wait_for_selector('#seoModal details.sc[data-sc=todo] li:has-text("başlığında yok")', timeout=8000)
    check(True, 'SEO check updates while typing (keyphrase missing from the title)')
    before = f.locator('[name=title]').input_value()
    f.locator('[data-seo-suggest=title]').click()
    wait_until(p, lambda: f.locator('[name=title]').input_value() != before)
    t = f.locator('[name=title]').input_value()
    tpx = int(re.search(r'yaklaşık (\d+)', f.locator('[data-meter-txt=title]').inner_text()).group(1))
    check(t.lower().startswith('emergency plumber toronto') and tpx <= 600, f'"Öner" writes a title with the keyphrase that fits ({t} · {tpx}px)')
    before = f.locator('[name=desc]').input_value()
    f.locator('[data-seo-suggest=desc]').click()
    wait_until(p, lambda: f.locator('[name=desc]').input_value() != before)
    d = f.locator('[name=desc]').input_value()
    check(120 <= len(d) <= 156 and 'emergency plumber' in d.lower(), f'"Öner" writes a 120–156 character description ({len(d)})')
    check(wait_until(p, lambda: p.locator('#seoModal details.sc li', has_text='başlığında yok').count() == 0), 'check clears once the title has the keyphrase')
    f.locator('[data-og-act=pick]').click()
    p.wait_for_selector('#seoModal .ogp')
    pick = f.locator('.ogp').first
    chosen = pick.get_attribute('data-og-pick')
    pick.click()
    check(f.locator('[data-og-val]').input_value() == chosen, f'sharing image picked from the site photos ({chosen})')
    msg = seo_save(p)
    a = rd('about.html')
    check('Kaydedildi' in msg and f'<title>{t.replace("&", "&amp;")}</title>' in a, 'saved from the window: new title on the page')
    check(og_meta(a) == [f'https://demirmechanical.com/{chosen}'] and 'og:image:width' in a, 'chosen sharing image on the page (with its size)')
    cc = content()['pages']['about']
    check(cc['seo_keyphrase'] == 'emergency plumber toronto' and cc['og_image'] == chosen, 'keyphrase and sharing image stored')
    row = p.locator('.srow[data-path="about.html"]')
    check(row.get_attribute('data-ch') == '1' and t in row.inner_text(), 'list row updated after saving')
    f = p.locator('#seoModal form.seo-ed')
    f.locator('[data-og-act=none]').click(); seo_save(p)
    check(og_meta(rd('about.html')) == [], '"Görselsiz paylaş" removes og:image')
    f = p.locator('#seoModal form.seo-ed')
    f.locator('[data-seo-reset]').click()
    check(f.locator('[name=title]').input_value() == c['pages']['about']['seo_title'], '"Orijinallere dön" brings back the original title')
    seo_save(p)
    a = rd('about.html')
    site_og = content()['site']['og_image']
    check(content()['pages']['about']['seo_title'] == c['pages']['about']['seo_title'] and og_meta(a) == ['https://demirmechanical.com/' + site_og], f'originals saved: title and automatic sharing image back ({site_og})')
    # previous / next
    paths = [r.get_attribute('data-path') for r in p.locator('.srow').all()]
    p.locator('#seoModal [data-seo-nav=next]').click()
    p.wait_for_selector(f'#seoModal form.seo-ed[data-path="{paths[paths.index("about.html") + 1]}"]')
    check(True, 'next page button opens the next page of the list')
    # unsaved changes are not lost by accident
    f = p.locator('#seoModal form.seo-ed')
    f.locator('[name=kp]').fill('unsaved keyphrase')
    msgs = []
    p.once('dialog', lambda dl: (msgs.append(dl.message), dl.dismiss()))
    p.locator('#seoModal .sd-fa [data-seo-close]').click(); p.wait_for_timeout(300)
    check(msgs and p.locator('#seoModal[open]').count() == 1, 'closing with unsaved changes asks first')
    p.once('dialog', lambda dl: dl.accept())
    p.keyboard.press('Escape'); p.wait_for_timeout(300)
    check(p.locator('#seoModal[open]').count() == 0 and 'edit=' not in p.url, 'Esc closes the window')
    # photos: file name + alternative text from the window (service page; the photo is used on other pages too)
    pn.go('seo&edit=drain-cleaning.html')
    check(p.evaluate("document.querySelector('#seoModal').matches(':modal')"), 'link with &edit= opens the window directly')
    f = p.locator('#seoModal form.seo-ed')
    si = f.locator('.si').first
    old = si.locator('input[name^="altf["]').input_value()
    si.locator('input[name^="fname["]').fill('Drain Snake Kitchen Sink Toronto')
    si.locator('input[name^="alt["]').fill('Technician snaking a clogged kitchen drain in Toronto')
    tricky = 'Drain Cleaning — Toronto’s “Same-Day” Service | 24/7 & More…'
    f.locator('[name=title]').fill(tricky)
    js_px = int(re.search(r'yaklaşık (\d+)', f.locator('[data-meter-txt=title]').inner_text()).group(1))
    f.locator('[data-og-up]').set_input_files(P['big'])
    p.wait_for_selector('#seoModal [data-og-st]:has-text("Yüklendi")', timeout=30000)
    og = f.locator('[data-og-val]').input_value()
    check(og.startswith('assets/uploads/og/') and og.endswith('.jpg') and Image.open(os.path.join(SITE, og)).size == (1200, 630), f'new sharing image cropped to 1200×630 JPG ({og})')
    msg = seo_save(p)
    new = 'assets/uploads/photos/drain-snake-kitchen-sink-toronto.webp'
    page = rd('drain-cleaning.html')
    check('drain-snake-kitchen-sink-toronto.webp' in msg and new in page and old not in page, f'photo renamed from the SEO window ({old} -> {new})')
    check(exists(old) and exists(new) and exists(new.replace('.webp', '-sm.webp')), 'old file kept for older backups, phone copy renamed too')
    check('alt="Technician snaking a clogged kitchen drain in Toronto"' in page, 'alternative text changed from the SEO window')
    check(new in rd('index.html') and old not in rd('index.html'), 'every page using the photo gets the new name (home page card)')
    php_px = int(p.locator('.srow[data-path="drain-cleaning.html"]').get_attribute('data-px'))
    check(php_px == js_px, f'width of a title with dashes, quotes and … the same in PHP and JavaScript ({php_px} / {js_px} px)')
    check(og_meta(page) == ['https://demirmechanical.com/' + og], 'uploaded sharing image on the page')
    f = p.locator('#seoModal form.seo-ed')
    check(f.locator('.si input[name^="altf["]').first.input_value() == new, 'window shows the new file name after saving')
    f.locator('.si input[name^="alt["]').first.fill('')
    p.locator('#seoModal button[value=seo_save]').click()
    p.wait_for_selector('#seoModal [data-seo-err]:not([hidden])')
    check('boş bırakılamaz' in f.locator('[data-seo-err]').inner_text(), 'empty alternative text refused')
    f.locator('.si input[name^="alt["]').first.fill('Technician snaking a clogged kitchen drain in Toronto')
    f.locator('.si input[name^="fname["]').first.fill('???')
    p.locator('#seoModal button[value=seo_save]').click(); p.wait_for_timeout(1200)
    check('geçersiz' in p.locator('#seoModal [data-seo-err]').inner_text(), 'unusable file name refused')
    # fixed pages keep a title
    p.once('dialog', lambda dl: dl.accept())
    pn.go('seo&edit=contact.html')
    f = p.locator('#seoModal form.seo-ed')
    f.locator('[name=title]').fill('')
    p.locator('#seoModal button[value=seo_save]').click()
    p.wait_for_selector('#seoModal [data-seo-err]:not([hidden])')
    check('boş bırakılamaz' in f.locator('[data-seo-err]').inner_text() and '<title>Contact |' in rd('contact.html'), 'fixed page title cannot be emptied')
    # project without a keyphrase gets the hint
    if projects:
        p.once('dialog', lambda dl: dl.accept())
        pn.go('seo&edit=project-' + projects[0]['slug'] + '.html')
        check(p.locator('#seoModal details.sc li:has-text("Odak anahtar kelime yok")').count() == 1, 'project page: asks for a keyphrase')
    # works without JavaScript too (plain form post)
    cookies = {ck['name']: ck['value'] for ck in p.context.cookies()}
    tok = p.locator('body').get_attribute('data-csrf')
    faq = content()['pages']['faq']
    data = urllib.parse.urlencode({'a': 'seo_save', '_csrf': tok, 'path': 'faq.html', 'kp': 'plumbing questions toronto', 'title': faq['seo_title'], 'desc': faq['seo_description'], 'og': ''}).encode()
    st, _ = http('/admin/index.php', 'POST', data, {'Cookie': 'dm_admin=' + cookies['dm_admin'], 'Content-Type': 'application/x-www-form-urlencoded', 'Origin': BASE})
    check(st == 303 and content()['pages']['faq']['seo_keyphrase'] == 'plumbing questions toronto', 'plain form post saves and returns to the window')
    pn.go('seo&f=changed')
    ch = [r.get_attribute('data-path') for r in p.locator('.srow:not([hidden])').all()]
    check('drain-cleaning.html' in ch and 'faq.html' in ch and 'index.html' not in ch, f'"Değiştirilen" lists edited pages ({len(ch)})')
    # logos (all pages)
    p.fill('[name=logo_alt]', 'Demir Mechanical logo')
    with p.expect_navigation(): p.click('.seo-logos button[value=seo_save]')
    check('alt="Demir Mechanical logo"' in rd('index.html'), 'logo alternative text saved from the SEO page')
    pn.go('dash')
    check('iyileştirilebilir' in p.locator('.tile', has_text='SEO').inner_text() or 'hepsi iyi' in p.locator('.tile', has_text='SEO').inner_text(), 'dashboard shows the SEO summary')

def t_preview(pn, P):
    p = pn.p
    pn.go('sec&s=home')
    pn.field('pages.home.title_highlight').fill('PREVIEW-ONLY-TEXT')
    with p.context.expect_page() as newp:
        p.click('button[name=a][value=preview]')
    v = newp.value; v.wait_for_load_state()
    html = v.content()
    check('PREVIEW-ONLY-TEXT' in html and 'ÖNİZLEME' in html, 'preview shows the unsaved text with a banner')
    check(v.locator('link[rel=stylesheet], style').count() >= 1 and v.evaluate("getComputedStyle(document.querySelector('.mainbar')).position") in ('sticky', 'fixed', 'relative', 'static'), 'preview page is styled')
    v.close()
    check('PREVIEW-ONLY-TEXT' not in rd('index.html') and 'PREVIEW-ONLY-TEXT' not in rd('data/content.json'), 'preview saved nothing')
    check('dirty' in (p.locator('form.edit').get_attribute('class') or ''), 'editor still shows unsaved changes after preview')
    # project preview of an unsaved new project
    pn.go('item&k=projects&i=new')
    pn.field('title').fill('Preview Project Test')
    pn.field('summary').fill('Only a preview.')
    with p.context.expect_page() as newp:
        p.click('button[name=a][value=preview]')
    v = newp.value; v.wait_for_load_state()
    check('Preview Project Test' in v.content(), 'preview of a new project page')
    v.close()
    check(len(content()['projects']) == 1, 'project preview saved nothing')

def t_upload_errors(pn, P):
    p = pn.p
    pn.go('sec&s=founder')
    msgs = []
    def on_dialog(d): msgs.append(d.message); d.accept()
    p.on('dialog', on_dialog)
    p.locator('.imgf .imgf-up').first.set_input_files(P['txt'])
    p.wait_for_timeout(1500)
    p.remove_listener('dialog', on_dialog)
    check(any('görsel değil' in m for m in msgs), 'a non-image file is refused with a clear message')
    upload_and_wait(p, '.imgf', P['rot'])
    f = p.locator('.imgf .imgf-file').first.input_value()
    im = Image.open(os.path.join(SITE, f)).convert('RGB')
    check(im.size == (900, 1200), f'phone photo turned upright from its EXIF data ({im.size})')
    px = im.getpixel((im.size[0] - 120, 200))        # the white box (top-left before rotation) is now top-right
    check(min(px) > 200, f'rotation direction correct (pixel {px})')
    p.locator('.imgf-alt').first.fill('Barış Demir, founder of Demir Mechanical & Construction')
    pn.save()
    check('Barış Demir, founder' in rd('about.html') and f in rd('about.html'), 'founder photo on the About page')

def t_backups(pn, P):
    p = pn.p
    pn.go('backups')
    rows = p.locator('.list .row')
    check(rows.count() >= 10, f'backups listed ({rows.count()})')
    # restore the backup made right before the logo change -> the old logo returns
    target = None
    for r in rows.all():
        if 'Logo & Görseller kaydedildi' in r.inner_text(): target = r
    restore_ok = False
    if target is not None:
        # the oldest "Logo" backup = state before the first logo upload
        p.once('dialog', lambda d: d.accept())
        with p.expect_navigation(): target.locator('button', has_text='Geri yükle').click()
        restore_ok = 'haline döndü' in pn.flash()
    check(restore_ok, 'backup restored')
    check('assets/demir-mechanical-construction-logo.png' in rd('index.html'), 'original logo back after restore')
    # downloads
    pn.go('backups')
    with p.expect_download() as dl:
        p.click('a[href*="what=content"]')
    path = dl.value.path(); data = json.load(open(path, encoding='utf-8'))
    check('services' in data and dl.value.suggested_filename.startswith('demir-content-'), 'content.json download')
    pn.go('backups')
    with p.expect_download() as dl:
        p.click('a[href*="what=full"]')
    z = zipfile.ZipFile(dl.value.path()); names = z.namelist()
    check('index.html' in names and 'data/content.json' in names and 'admin/index.php' in names and any(n.startswith('assets/uploads/projects/') for n in names), 'full backup zip has pages, content, panel and photos')
    check('form-config.php' not in names and 'data/admin-auth.php' not in names and not any(n.startswith('data/backups/') for n in names), 'full backup has no passwords and no old backups')
    # import: upload the downloaded content.json back
    data['contact']['hours_short'] = 'IMPORTED HOURS'
    imp = os.path.join(TMP, 'import.json'); json.dump(data, open(imp, 'w'), ensure_ascii=False)
    pn.go('backups')
    p.locator('input[name=json]').set_input_files(imp)
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('form:has(input[name=json]) button[type=submit]')
    check('IMPORTED HOURS' in rd('contact.html'), 'content file imported')
    bad = os.path.join(TMP, 'bad.json'); open(bad, 'w').write('{"hello": 1}')
    p.locator('input[name=json]').set_input_files(bad)
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('form:has(input[name=json]) button[type=submit]')
    check('içerik dosyası değil' in pn.flash(), 'wrong JSON file refused')

def t_cleanup(pn, P):
    p = pn.p
    pn.go('sec&s=founder')
    upload_and_wait(p, '.imgf', P['g1'])
    orphan = p.locator('.imgf .imgf-file').first.input_value()
    pn.go('dash')                                   # leave without saving (the browser asks; Playwright accepts)
    check(exists(orphan), f'uploaded-but-not-saved photo is on the server ({orphan})')
    pn.go('backups')
    check(p.locator('button:has-text("Temizle")').count() == 1, 'backups page offers to clean up unused photos')
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('button:has-text("Temizle")')
    check('silindi' in pn.flash() and not exists(orphan) and not exists(orphan.replace('.webp', '-sm.webp')), 'unused photo deleted')
    used = content()['founder']['photo']['file']
    check(exists(used), 'photos still in use are kept')
    proj = os.listdir(os.path.join(SITE, 'assets/uploads/projects'))
    check(len([f for f in proj if not f.endswith('-sm.webp')]) == 5, 'project photos kept (an older backup still uses them)')

def t_custom(pn, P):
    p = pn.p
    pn.go('sec&s=custom')
    check(p.locator('h1').inner_text().startswith('Özel Kod'), 'custom code page opens from the menu')
    css = 'body[data-page="about"] h1 { color: rgb(200, 0, 0) !important; }\n/* </style> must not end the style block */'
    js = "<script>\ndocument.documentElement.setAttribute('data-custom-js', 'ok');\n</script>"
    p.fill('[name="f[custom.css]"]', css)
    p.fill('[name="f[custom.js]"]', js)
    p.fill('[name="f[custom.head]"]', '<meta name="dm-custom-test" content="head-ok">')
    p.fill('[name="f[custom.body_start]"]', '<div id="dm-bs-test" hidden></div>')
    p.fill('[name="f[custom.body_end]"]', '<script>window.dmBodyEnd = 1;</script>')
    posts = []
    p.on('request', lambda r: posts.append(r.post_data or '') if r.method == 'POST' else None)
    pn.save()
    check('Kaydedildi' in pn.flash(), 'custom code saved')
    body = ''.join(posts).lower()
    check('b64%3a' in body and '<script' not in body and '%3cscript' not in body, 'code is sent encoded (no <script> in the form post, firewall-safe)')
    c = content()['custom']
    check(c['js'] == "document.documentElement.setAttribute('data-custom-js', 'ok');", 'pasted <script> wrapper removed from the JavaScript field')
    a = rd('about.html')
    check('<style id="dm-custom-css">' in a and '<\\/style>' in a and a.index('dm-custom-css') < a.index('</head>'), 'custom CSS in <head>, after the site styles, cannot close the style block')
    check('<body data-page="about" data-type="about">' in a, 'pages carry data-page / data-type for targeting')
    check('data-type="service"' in rd('drain-cleaning.html') and 'data-type="city"' in rd('plumber-toronto.html') and 'data-page="index" data-type="home"' in rd('index.html'), 'page types for services, cities and home')
    check(a.index('<meta name="dm-custom-test" content="head-ok">') < a.index('</head>'), 'head snippet inside <head>')
    check(re.search(r'<body[^>]*><div id="dm-bs-test" hidden></div>', a) is not None, 'body-start snippet right after <body>')
    check(a.index('id="dm-custom-js"') < a.index('window.dmBodyEnd = 1;') < a.index('</body>'), 'custom JS and body-end snippet at the end of the page')
    v = p.context.new_page()
    v.goto(f'{BASE}/about.html'); v.wait_for_load_state()
    col = v.evaluate("getComputedStyle(document.querySelector('h1')).color")
    ok_js = v.evaluate("document.documentElement.getAttribute('data-custom-js') === 'ok' && window.dmBodyEnd === 1")
    v.goto(f'{BASE}/index.html'); v.wait_for_load_state()
    col_home = v.evaluate("getComputedStyle(document.querySelector('h1')).color")
    v.close()
    check(col == 'rgb(200, 0, 0)' and col_home != 'rgb(200, 0, 0)', f'CSS for one page applies only there ({col} / {col_home})')
    check(ok_js, 'custom JavaScript and body-end script run on the live page')
    # a tag-style snippet in the JavaScript field is refused with a hint
    pn.go('sec&s=custom')
    p.fill('[name="f[custom.js]"]', '<script async src="https://example.com/x.js"></script>')
    pn.save()
    check('HTML alanlarına' in p.locator('.ferr').first.inner_text(), 'tag snippet in the JavaScript field is refused with a hint')
    check(content()['custom']['js'].startswith('document.documentElement'), 'nothing saved after the error')
    # preview a chosen page with unsaved custom code
    pn.go('sec&s=custom')
    p.fill('[name="f[custom.css]"]', 'body[data-page="faq"] h1 { color: rgb(0, 150, 0); }')
    p.select_option('select[name=preview_page]', 'faq.html')
    with p.context.expect_page() as newp:
        p.click('button[name=a][value=preview]')
    pv = newp.value; pv.wait_for_load_state()
    check('ÖNİZLEME' in pv.content() and pv.evaluate("getComputedStyle(document.querySelector('h1')).color") == 'rgb(0, 150, 0)', 'preview of a chosen page with unsaved custom CSS')
    pv.close()
    check(p.locator('[name="f[custom.css]"]').input_value().startswith('body[data-page="faq"]'), 'code field keeps its text after preview')
    # switch everything off without deleting it
    p.locator('[name="f[custom.enabled]"][type=checkbox]').uncheck()
    pn.save()
    a = rd('about.html')
    check('dm-custom-css' not in a and 'dm-custom-test' not in a and 'dmBodyEnd' not in a and 'data-page="about"' in a, 'switched off: no custom code on the pages')
    check(content()['custom']['css'].startswith('body[data-page="faq"]'), 'switched off: the code itself is kept')

def t_theme(pn, P):
    # Özel Kod → "Sitenin kendi kodu": the site's own CSS and JavaScript, editable, with a way back to the original
    p = pn.p
    pn.go('sec&s=custom')
    p.locator('[name="f[custom.enabled]"][type=checkbox]').check()
    ta = p.locator('textarea[name="f[custom.theme_css]"]'); tj = p.locator('textarea[name="f[custom.theme_js]"]')
    css0 = ta.input_value(); js0 = tj.input_value()
    style = rd('app/theme/style.html')
    check(len(css0) > 5000 and css0 in style and not css0.lstrip().startswith('<style'), f'site CSS shown in the editor without its <style> wrapper ({len(css0)} characters)')
    check(js0.lstrip().startswith('<script') and '__GA4__' in js0 and js0 in rd('app/theme/script.html'), 'site JavaScript shown with its <script> blocks')
    check('orijinal' in p.locator('.ftheme .tc-state').first.inner_text(), 'state: the original code is in use')
    ta.fill(css0 + '\nbody[data-page] { outline: 3px solid rgb(1, 2, 3); }')
    tj.fill(js0 + '\n<script>window.dmThemeJs = 1;</script>')
    pn.save()
    check('Kaydedildi' in pn.flash(), 'edited site code saved')
    c = content()['custom']
    check(c['theme_css'].endswith('rgb(1, 2, 3); }') and c['theme_css_base'] == hashlib.sha1(css0.encode()).hexdigest() and c['theme_js_base'] != '', 'edited copy stored with a fingerprint of the original')
    a = rd('about.html')
    check(a.count(css0[:300]) == 1 and a.count('rgb(1, 2, 3)') == 1 and a.count(js0[:200]) == 1, 'pages use the edited copy instead of the original (nothing doubled)')
    v = p.context.new_page(); errs = []
    v.on('pageerror', lambda e: errs.append(str(e)))
    v.goto(f'{BASE}/about.html'); v.wait_for_load_state()
    check(v.evaluate("getComputedStyle(document.body).outlineColor") == 'rgb(1, 2, 3)' and v.evaluate('window.dmThemeJs') == 1 and not errs, 'edited CSS and JavaScript work on the live page')
    v.close()
    pn.go('sec&s=custom')
    check('Düzenlenmiş' in p.locator('.ftheme .tc-state').first.inner_text(), 'state: the edited copy is in use')
    p.locator('textarea[name="f[custom.theme_js]"]').fill('alert(1)')
    pn.save()
    check('blokları içinde' in p.locator('.ferr').first.inner_text() and content()['custom']['theme_js'].endswith('dmThemeJs = 1;</script>'), 'JavaScript outside <script> blocks refused, nothing saved')
    pn.go('sec&s=custom')
    p.once('dialog', lambda d: d.accept())
    p.locator('.ftheme').first.locator('[data-code-reset]').click()
    check(p.locator('textarea[name="f[custom.theme_css]"]').input_value() == css0, '"Orijinal koda dön" brings the original back into the editor')
    p.once('dialog', lambda d: d.accept())
    p.locator('.ftheme').nth(1).locator('[data-code-reset]').click()
    pn.save()
    c = content()['custom']; a = rd('about.html')
    check(c['theme_css'] == '' and c['theme_js'] == '' and c['theme_css_base'] == '' and 'rgb(1, 2, 3)' not in a and 'dmThemeJs' not in a and a.count(css0[:300]) == 1, 'back to the original: nothing stored, pages use the shipped code again')

def media_toast(p, timeout=20000):
    p.wait_for_selector('.toast', timeout=timeout)
    txt = p.locator('.toast').last.inner_text()
    p.evaluate("document.querySelectorAll('.toast').forEach(t => t.remove())")
    return txt

def t_media(pn, P):
    # Galeri: a grid of photos; a click shows file name + alt text in the panel on the right; add / delete photos;
    # photos of the gallery are used in the editors with "Galeriden seç" / "Galeriden ekle"
    p = pn.p
    pn.go('media')
    n = p.locator('.mtile:not([hidden])').count()
    check(p.locator('h1').inner_text() == 'Galeri' and n >= 20 and p.locator('#mediaPane .mp-empty').is_visible(), f'Galeri: photo grid ({n}) with an empty details panel')
    c = content()
    svc = c['services'][0]
    tile = p.locator(f'.mtile[data-media-open="{svc["image"]["file"]}"]')
    check(tile.count() == 1 and tile.locator('img').count() == 1 and tile.locator('.mt-name').inner_text() == os.path.basename(svc['image']['file']), 'each photo is a picture tile (name on hover)')
    p.fill('#mediaSearch', os.path.basename(svc['image']['file'])[:12])
    vis = p.locator('.mtile:not([hidden])').count()
    check(0 < vis < n, f'search filters the photos ({vis})')
    p.fill('#mediaSearch', '')
    tile.click()
    p.wait_for_selector('#mediaPane form.media-ed')
    f = p.locator('#mediaPane form.media-ed')
    pb = p.locator('#mediaPane').bounding_box(); gb = p.locator('#mediaGrid').bounding_box()
    check(pb['x'] >= gb['x'] + gb['width'] and f.locator('#mdAlt').input_value() == svc['image']['alt'] and f.locator('#mdName').input_value() == os.path.splitext(os.path.basename(svc['image']['file']))[0],
          'click: file name and alt text open in the panel on the right')
    check('on' in tile.get_attribute('class').split() and 'open=' in p.url, 'the photo is marked as selected, the address remembers it')
    check('Hizmet: ' + svc['name'] in f.locator('.musage').inner_text(), 'panel lists where the photo is used')
    f.locator('#mdAlt').fill('Plumber clearing a kitchen drain in a Toronto home')
    f.locator('#mdName').fill('Kitchen Drain Toronto')
    f.locator('button[value=media_save]').click()
    media_toast(p)
    c = content(); img = c['services'][0]['image']
    check(img['alt'] == 'Plumber clearing a kitchen drain in a Toronto home', 'alt text saved into the content')
    check(img['file'].startswith('assets/uploads/') and os.path.basename(img['file']).startswith('kitchen-drain-toronto') and exists(img['file']), f'file renamed as a new copy ({img["file"]})')
    check(exists(svc['image']['file']), 'old file kept (old backups still use it)')
    page = rd(svc['slug'] + '.html')
    check(os.path.basename(img['file']) in page and 'alt="Plumber clearing a kitchen drain in a Toronto home"' in page, 'the service page shows the new name and alt text')
    check(f.locator('#mdName').input_value().startswith('kitchen-drain-toronto') and 'on' in p.locator(f'.mtile[data-media-open="{img["file"]}"]').get_attribute('class').split(), 'panel and grid show the renamed photo')
    f.locator('#mdAlt').fill('')
    f.locator('button[value=media_save]').click()
    p.wait_for_selector('#mediaPane [data-media-err]:not([hidden])')
    check('boş bırakılamaz' in f.locator('[data-media-err]').inner_text() and content()['services'][0]['image']['alt'] != '', 'empty alt text refused for a service photo')
    p.once('dialog', lambda d: d.accept())                 # "not saved, continue anyway?"
    p.locator('#mediaPane [data-media-nav=next]').click()
    check(wait_until(p, lambda: p.locator('#mediaPane form.media-ed').count() == 1 and p.locator('#mediaPane form.media-ed').get_attribute('data-file') != img['file']), 'arrow in the panel opens the next photo')
    p.keyboard.press('Escape')
    check(wait_until(p, lambda: p.locator('#mediaPane .mp-empty').count() == 1) and p.locator('.mtile.on').count() == 0, 'Esc closes the panel')
    p.click('#mediaChips a[data-f=logo]')
    cats = set(x.get_attribute('data-cat') for x in p.locator('.mtile:not([hidden])').all())
    check(cats == {'logo'}, 'filter: logos only')
    p.click('#mediaChips a[data-f=""]')
    # "Fotoğraf ekle": several photos at once
    before = n
    p.set_input_files('[data-media-add]', [P['g2'], P['g3']])
    note = media_toast(p, 40000)
    lib = content().get('library', [])
    check('2 fotoğraf galeriye eklendi' in note and len(lib) == 2 and all(x['file'].startswith('assets/uploads/photos/site-photo-') and exists(x['file']) and exists(x['file'].replace('.webp', '-sm.webp')) for x in lib),
          'Fotoğraf ekle: two photos uploaded into the gallery (with phone copies)')
    first, second = lib[0]['file'], lib[1]['file']
    check(p.locator('.mtile:not([hidden])').count() == before + 2 and p.locator('#mediaPane form.media-ed').get_attribute('data-file') == first and p.evaluate('document.activeElement.id') == 'mdAlt',
          'new photos at the top of the grid, the first one open and ready for its alt text')
    marks = p.locator(f'.mtile[data-media-open="{first}"] .mk').all_inner_texts()
    check('Alt yok' in marks and 'Kullanılmıyor' in marks and 'henüz sitede kullanılmıyor' in p.locator('#mediaPane').inner_text().lower(), 'new photos marked: no alt text yet, not used yet')
    p.fill('#mdAlt', 'Copper water lines in a new basement bathroom')
    p.click('#mediaPane button[value=media_save]')
    media_toast(p)
    check(content()['library'][0]['alt'] == 'Copper water lines in a new basement bathroom' and 'Alt yok' not in p.locator(f'.mtile[data-media-open="{first}"]').inner_text(), 'alt text of a gallery photo saved')
    # photos of the gallery survive "Kullanılmayan fotoğrafları temizle"
    pn.go('sec&s=founder')
    upload_and_wait(p, '.imgf', P['g4'])
    orphan = p.locator('.imgf .imgf-file').first.input_value()
    pn.go('backups')
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('button:has-text("Temizle")')
    check(not exists(orphan) and exists(first) and exists(second), 'cleaning up unused photos keeps the gallery photos')
    # "Galeriden seç" in an editor
    city = content()['cities'][0]
    pn.go('item&k=cities&i=0')
    p.click('.imgf-pick')
    p.wait_for_selector('.pick-dlg .pk-i')
    top = p.locator('.pick-dlg .pk-i').first.get_attribute('title').split(' ')[0]
    check(p.locator('.pick-dlg .pk-i').count() >= 20 and top in (os.path.basename(first), os.path.basename(second)), f'Galeriden seç: the gallery opens, newest photos first ({top})')
    p.fill('.pick-dlg [data-pk-q]', 'basement bathroom')
    check(p.locator('.pick-dlg .pk-i').count() == 1, 'search in the picker')
    p.locator('.pick-dlg .pk-i').first.click()
    p.click('.pick-dlg [data-pk-ok]')
    check(p.locator('.imgf-file').first.input_value() == first and p.locator('.imgf-alt').first.input_value() == 'Copper water lines in a new basement bathroom', 'picked photo and its alt text filled in')
    pn.save('save_item')
    c = content()
    check(c['cities'][0]['image']['file'] == first and first.split('/')[-1] in rd(city['slug'] + '.html'), 'the city page uses the gallery photo')
    # "Galeriden ekle" in a project gallery
    pn.go('item&k=projects&i=new')
    pn.field('title').fill('Basement Bathroom Rough-In — Vaughan')
    pn.field('summary').fill('New supply and drain lines for a basement bathroom.')
    p.click('.gal-pick')
    p.wait_for_selector('.pick-dlg .pk-i')
    p.locator(f'.pick-dlg .pk-i[title^="{os.path.basename(second)}"]').click()
    p.locator('.pick-dlg .pk-i').nth(5).click()
    check('2 fotoğraf ekle' in p.locator('.pick-dlg [data-pk-ok]').inner_text(), 'several photos can be picked at once')
    p.click('.pick-dlg [data-pk-ok]')
    check(p.locator('.gal-item').count() == 2, 'Galeriden ekle: two photos added to the project gallery')
    pn.save('save_item')
    proj = [x for x in content()['projects'] if x['title'].startswith('Basement Bathroom Rough-In')]
    check(proj and len(proj[0]['photos']) == 2 and proj[0]['photos'][0]['file'] == second, 'project saved with the gallery photos')
    # delete: only photos that nothing uses
    pn.go('media')
    p.locator(f'.mtile[data-media-open="{first}"]').click()
    p.wait_for_selector(f'#mediaPane form.media-ed[data-file="{first}"]')
    check(p.locator('#mediaPane button[value=media_delete]').count() == 0 and 'Bölge: ' in p.locator('#mediaPane .musage').inner_text(), 'a photo in use cannot be deleted (and shows where it is used)')
    p.set_input_files('[data-media-add]', [P['g0']])
    media_toast(p, 40000)
    third = content()['library'][-1]['file']
    check(p.locator('#mediaPane form.media-ed').get_attribute('data-file') == third, 'third photo added')
    msgs = []
    p.once('dialog', lambda d: (msgs.append(d.message), d.accept()))
    p.click('#mediaPane button[value=media_delete]')
    note = media_toast(p)
    check('silindi' in note and not exists(third) and not exists(third.replace('.webp', '-sm.webp')) and third not in [x['file'] for x in content()['library']], 'unused photo deleted (file, phone copy and gallery entry)')
    check(msgs and 'kalıcı olarak silinsin mi' in msgs[0] and p.locator(f'.mtile[data-media-open="{third}"]').count() == 0 and p.locator('#mediaPane .mp-empty').count() == 1, 'asked first; gone from the grid')

def inv_pdf_text(data, name):
    path = os.path.join(TMP, name)
    open(path, 'wb').write(data)
    ok = subprocess.run(['qpdf', '--check', path], capture_output=True, text=True)
    txt = subprocess.run(['pdftotext', '-layout', path, '-'], capture_output=True, text=True).stdout
    pages = len(re.findall(r'\f', txt)) or 1
    subprocess.run(['pdftoppm', '-png', '-r', '60', '-f', '1', '-l', '1', path, path[:-4]], capture_output=True)
    return ok.returncode == 0, txt, pages

def inv_totals_text(p):
    return {k: p.locator(f'.inv-sum [data-inv={k}]').inner_text() for k in ('sub', 'disc', 'tax', 'total', 'paid', 'due')}

def t_invoices(pn, P):
    p = pn.p
    pn.go('invoices')
    check(p.locator('h1').inner_text() == 'Faturalar' and p.locator('.empty').count() == 1, 'Faturalar page opens (no invoices yet)')
    check('HST numaranız' in p.locator('.flash.warn').inner_text(), 'reminder to add the HST number')
    # settings: panel-only, nothing on the website changes
    pn.go('sec&s=invoicing')
    check(p.locator('button[value=preview]').count() == 0 and p.locator('button[value=save_sec]').inner_text().strip() == 'Kaydet', 'Fatura Ayarları: no site preview, plain Save')
    before = rd('index.html')
    pn.field('invoice.tax_number').fill('123456789 RT0001')
    pn.save()
    check('Fatura Ayarları kaydedildi' in pn.flash() and content()['invoice']['tax_number'] == '123456789 RT0001', 'invoice settings saved')
    check(rd('index.html') == before and '123456789' not in rd('contact.html'), 'website pages unchanged by invoice settings')
    # a new invoice
    pn.go('invoice&id=new')
    now = datetime.datetime.now(ZoneInfo('America/Toronto'))          # the panel's time zone
    today = now.strftime('%Y-%m-%d')
    check(pn.field('number').input_value() == 'DM-1001' and pn.field('date').input_value() == today and pn.field('status').input_value() == 'sent', 'new invoice: next number, today, "Kesildi"')
    check(pn.field('due').input_value() == (now + datetime.timedelta(days=14)).strftime('%Y-%m-%d'), 'due date 14 days later')
    pn.field('customer.name').fill('Jane Cooper')
    pn.field('customer.address').fill('12 Maple Avenue\nToronto, ON M4E 1A1')
    pn.field('customer.email').fill('jane@example.com')
    pn.field('customer.phone').fill('416-555-0199')
    pn.field('site').fill('88 Lakeshore Road, Unit 4, Mississauga')
    p.fill('[name="f[items][0][desc]"]', 'Replaced kitchen faucet – supplied Moen chrome faucet')
    p.fill('[name="f[items][0][qty]"]', '1'); p.fill('[name="f[items][0][price]"]', '289.50')
    p.click('.rep-add')
    p.fill('[name="f[items][1][desc]"]', 'Labour (hours)')
    p.fill('[name="f[items][1][qty]"]', '2,5'); p.fill('[name="f[items][1][price]"]', '95')
    t = inv_totals_text(p)
    check(t['sub'] == '$527.00' and t['tax'] == '$68.51' and t['total'] == '$595.51' and t['due'] == '$595.51', f'live totals while typing ({t})')
    check(p.locator('.rep-item').nth(1).locator('.inv-line').inner_text() == '$237.50' and p.locator('.savebar [data-inv=total]').inner_text() == '$595.51', 'line amount and total in the save bar')
    pn.field('discount').fill('27'); pn.field('paid').fill('100')
    t = inv_totals_text(p)
    check(t['disc'] == '-$27.00' and t['tax'] == '$65.00' and t['total'] == '$565.00' and t['paid'] == '-$100.00' and t['due'] == '$465.00', f'discount before tax, deposit taken off ({t})')
    # preview: a PDF of what is on the screen, nothing saved
    with p.context.expect_page() as newp:
        p.click('button[value=invoice_pdf]')
    newp.value.close()
    data = dict(p.evaluate("Array.from(new FormData(document.querySelector('form[data-invoice]')))"))
    data['a'] = 'invoice_pdf'
    r = p.request.post(ADMIN + 'index.php', form=data)
    ok, txt, _ = inv_pdf_text(r.body(), 'preview.pdf')
    check(r.headers.get('content-type') == 'application/pdf' and 'inline' in r.headers.get('content-disposition', '') and ok and 'PREVIEW' in txt and 'Jane Cooper' in txt, '"PDF önizle" gives a valid PDF of the unsaved invoice')
    check(not exists('data/invoices.json'), 'preview saved nothing')
    pn.save('invoice_save')
    check('DM-1001 kaydedildi' in pn.flash() and 'p=invoice&id=' in p.url, 'invoice saved, editor stays open')
    db = json.loads(rd('data/invoices.json'))
    inv = db['list'][0]
    check(len(db['list']) == 1 and inv['customer']['name'] == 'Jane Cooper' and len(inv['items']) == 2 and inv['items'][1]['qty'] == 2.5 and db['next'] == 1002, 'stored in data/invoices.json')
    check(oct(os.stat(os.path.join(SITE, 'data/invoices.json')).st_mode & 0o777) == '0o600', 'invoice file readable only by the server account')
    check(inv_totals_text(p)['due'] == '$465.00', 'saved totals match the live totals')
    r = p.request.get(ADMIN + 'index.php?p=invoice_pdf&id=' + inv['id'] + '&dl=1')
    ok, txt, pages = inv_pdf_text(r.body(), 'invoice.pdf')
    check(r.headers.get('content-type') == 'application/pdf' and 'attachment; filename="Invoice-DM-1001.pdf"' in r.headers.get('content-disposition', '') and ok, 'PDF download: valid file named Invoice-DM-1001.pdf')
    want = ['INVOICE', 'DM-1001', 'Demir Mechanical & Construction Ltd.', 'HST # 123456789 RT0001', 'BILL TO', 'Jane Cooper', '12 Maple Avenue', 'jane@example.com',
            'SERVICE ADDRESS', 'Replaced kitchen faucet – supplied Moen chrome faucet', 'Labour (hours)', '$237.50', 'Subtotal', '$527.00', 'Discount', '-$27.00', 'HST (13%)', '$65.00',
            'Total', '$565.00', 'Amount paid', '-$100.00', 'Balance due (CAD)', '$465.00', 'Thank you for your business!', 'Interac e-Transfer: info@demirmechanical.com', 'Cheque payable to: Demir Mechanical & Construction Ltd.']
    miss = [w for w in want if w not in txt]
    check(not miss and 'PREVIEW' not in txt and 'Ltd..' not in txt, 'PDF has the business, customer, lines and totals' + (f' (missing: {miss})' if miss else ''))
    check(p.request.get(ADMIN + 'index.php?p=invoice_pdf&id=' + inv['id']).headers.get('content-disposition', '').startswith('inline'), '"PDF aç" opens the PDF in the browser')
    # mistakes are caught, nothing is saved
    pn.go('invoice&id=new')
    check(pn.field('number').input_value() == 'DM-1002', 'next invoice gets the next number')
    pn.field('number').fill('dm-1001')
    pn.field('due').fill('2020-01-01')
    p.fill('[name="f[items][0][price]"]', '50')
    pn.save('invoice_save')
    errs = ' '.join(p.locator('.ferr').all_inner_texts())
    check('kullanılıyor' in errs and 'boş bırakılamaz' in errs and 'açıklama yazın' in errs and 'önce olamaz' in errs, 'duplicate number, missing name and description, due date before invoice date refused')
    check(len(json.loads(rd('data/invoices.json'))['list']) == 1, 'nothing saved after the errors')
    # a customer invoiced before: the name fills the other details
    pn.go('invoice&id=new')
    pn.field('customer.name').fill('Jane Cooper')
    check(pn.field('customer.email').input_value() == 'jane@example.com' and pn.field('customer.address').input_value().startswith('12 Maple') and pn.field('site').input_value().startswith('88 Lakeshore'), 'known customer: details filled in automatically')
    # list, search and filters
    pn.go('invoices')
    row = p.locator('.irow').first
    check(p.locator('.irow').count() == 1 and 'Jane Cooper' in row.inner_text() and '$565.00' in row.inner_text() and 'kalan $465.00' in row.inner_text() and row.locator('.tag').inner_text() == 'Kesildi', 'list shows customer, total, balance and status')
    check('$465.00' in p.locator('.inv-tiles').inner_text(), 'unpaid total at the top')
    p.fill('#invSearch', 'maple'); vis1 = p.locator('.irow:not([hidden])').count()
    p.fill('#invSearch', 'zzz'); vis2 = p.locator('.irow:not([hidden])').count()
    check(vis1 == 1 and vis2 == 0 and p.locator('#invApp .seo-none').is_visible(), 'search by address finds the invoice')
    p.fill('#invSearch', '')
    # paid in full
    pn.go('invoice&id=' + inv['id'])
    pn.field('status').select_option('paid')
    check(inv_totals_text(p)['due'] == '$0.00', 'live: "Ödendi" leaves nothing to pay')
    pn.save('invoice_save')
    ok, txt, _ = inv_pdf_text(p.request.get(ADMIN + 'index.php?p=invoice_pdf&id=' + inv['id']).body(), 'paid.pdf')
    check(ok and 'PAID' in txt and 'AMOUNT PAID' in txt and re.search(r'Balance due \(CAD\)\s+\$0\.00', txt), 'paid invoice: PAID stamp, balance $0.00')
    # copy for a repeat job, then make it overdue
    with p.expect_navigation():
        p.click('form.inline button:has-text("Kopyala")')
    check('Kopya oluşturuldu: DM-1002' in pn.flash() and pn.field('status').input_value() == 'draft' and pn.field('paid').input_value() == '0', 'copy: new number, draft, nothing paid')
    pn.field('status').select_option('sent'); pn.field('date').fill('2026-01-05'); pn.field('due').fill('2026-01-19')
    pn.save('invoice_save')
    pn.go('invoices')
    check(p.locator('.irow[data-st=overdue]').count() == 1 and 'Gecikmiş' in p.locator('#invChips').inner_text(), 'past the due date: shown as overdue')
    p.click('#invChips a[data-f=paid]')
    check([r.get_attribute('data-st') for r in p.locator('.irow:not([hidden])').all()] == ['paid'] and 'f=paid' in p.url, 'filter: paid only')
    pn.go('dash')
    check('son ödeme tarihi geçti' in p.locator('.todo').inner_text() and 'ödenmemiş' in p.locator('.tile:has-text("Faturalar")').inner_text(), 'dashboard: overdue reminder and unpaid amount')
    # a long invoice continues on a second page
    db = json.loads(rd('data/invoices.json'))
    big = dict(db['list'][0]); big['id'] = 'b1g000000001'; big['number'] = 'DM-1500'; big['status'] = 'void'
    big['items'] = [{'desc': f'Line {i + 1}: copper pipe, fittings and labour for the basement bathroom rough-in', 'qty': 1, 'price': 10 + i} for i in range(45)]
    db['list'].append(big); open(os.path.join(SITE, 'data/invoices.json'), 'w').write(json.dumps(db))
    ok, txt, pages = inv_pdf_text(p.request.get(ADMIN + 'index.php?p=invoice_pdf&id=b1g000000001').body(), 'long.pdf')
    check(ok and pages >= 2 and 'Page 1 of' in txt and 'Line 45:' in txt and 'VOID' in txt, f'long invoice: {pages} pages with page numbers, VOID stamp')
    # delete (a copy stays in the backups)
    pn.go('invoice&id=b1g000000001')
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation():
        p.click('form.inline button.danger')
    check('DM-1500' in pn.flash() and 'silindi' in pn.flash() and len(json.loads(rd('data/invoices.json'))['list']) == 2, 'invoice deleted')
    check(len([x for x in os.listdir(os.path.join(SITE, 'data/backups')) if x.startswith('invoices-')]) >= 3, 'every change keeps a copy in data/backups')
    # private: no session, no PDF
    st, body = http('/admin/index.php?p=invoice_pdf&id=' + inv['id'] + '&dl=1')
    check('%PDF' not in body and 'Tekrar hoş geldiniz' in body, 'PDF needs the panel login')

def t_version_rebuild(pn, P):
    pub = json.loads(rd('data/published.json')); pub['version'] = '2000.01.01'
    open(os.path.join(SITE, 'data/published.json'), 'w').write(json.dumps(pub))
    open(os.path.join(SITE, 'about.html'), 'w').write('stale page')
    pn.go()
    check('yeni sürüme' in pn.flash(), 'new code version: pages rebuilt on the next panel visit')
    check('<h1' in rd('about.html'), 'stale page regenerated')
    # deploy hook
    st, _ = http('/admin/hook.php', 'POST', b'', {'X-DM-Key': 'nope'})
    check(st == 403, 'hook refuses a wrong key')
    pn.go('account')
    pn.p.click('details.dev > summary')
    with pn.p.expect_navigation(): pn.p.click('button:has-text("Anahtar oluştur")')
    key = pn.p.locator('code.sel').text_content().strip()
    stored = json.loads(rd('data/admin-auth.php').split('\n', 1)[1]).get('hook', '')
    check(key == stored and len(key) == 40, f'hook key shown in the panel ({key[:6]}… / {stored[:6]}…)')
    pub = json.loads(rd('data/published.json')); pub['version'] = 'old'
    open(os.path.join(SITE, 'data/published.json'), 'w').write(json.dumps(pub))
    st, body = http('/admin/hook.php', 'POST', b'', {'X-DM-Key': key})
    j = json.loads(body)
    check(st == 200 and j.get('rebuilt') is True, f'hook with the key rebuilds after a code update ({body[:160]})')
    st, body = http('/admin/hook.php', 'POST', b'', {'X-DM-Key': key})
    check(json.loads(body)['rebuilt'] is False, 'hook does nothing when already up to date')
    st, _ = http('/admin/hook.php')
    check(st == 405, 'hook only accepts POST')

def t_account(pn, P):
    p = pn.p
    pn.go('account')
    p.fill('[name=cur]', 'wrong-current'); p.fill('[name=pw]', PW2); p.fill('[name=pw2]', PW2)
    with p.expect_navigation(): p.click('button:has-text("Şifreyi değiştir")')
    check('yanlış' in pn.flash(), 'password change needs the current password')
    p.fill('[name=cur]', PW1); p.fill('[name=pw]', PW2); p.fill('[name=pw2]', PW2)
    with p.expect_navigation(): p.click('button:has-text("Şifreyi değiştir")')
    check('değiştirildi' in pn.flash(), 'password changed')
    pn.go('dash')
    check(p.locator('h1').inner_text() == 'Hoş geldiniz', 'this device stays logged in after the change')
    other = p.context.browser.new_context().new_page()
    other.goto(ADMIN); other.fill('[name=pw]', PW1); other.click('button[type=submit]'); other.wait_for_load_state()
    check(other.locator('h1').inner_text() == 'Tekrar hoş geldiniz', 'old password no longer works')
    other.fill('[name=pw]', PW2); other.click('button[type=submit]'); other.wait_for_load_state()
    check(other.locator('h1').inner_text() == 'Hoş geldiniz', 'new password works on another device')
    pn.go('account')
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('button:has-text("Tüm cihazlardan çıkış yap")')
    other.goto(ADMIN + 'index.php?p=dash'); other.wait_for_load_state()
    check(other.locator('h1').inner_text() == 'Tekrar hoş geldiniz', 'logout everywhere ends the other session')
    other.context.close()
    p.fill('[name=pw]', PW2); p.click('button[type=submit]'); p.wait_for_load_state()

def t_mobile(pn, P, browser):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True, device_scale_factor=2)
    m = ctx.new_page()
    m.goto(ADMIN); m.fill('[name=pw]', PW2); m.click('button[type=submit]'); m.wait_for_load_state()
    check(m.locator('.burger').is_visible() and not m.locator('#side').evaluate("e => e.getBoundingClientRect().right > 0"), 'phone: menu hidden behind the menu button')
    m.click('.burger'); m.wait_for_timeout(400)
    check(m.locator('#side').evaluate("e => e.getBoundingClientRect().left >= 0"), 'phone: menu slides in')
    m.click('.snav a:has-text("Ana Sayfa")'); m.wait_for_load_state()
    sw = m.evaluate('document.documentElement.scrollWidth')
    check(sw <= 390, f'phone: no sideways scrolling in the editor ({sw}px)')
    bar = m.locator('.savebar').bounding_box()
    check(bar and bar['y'] + bar['height'] <= 844 + 1, 'phone: save bar visible at the bottom')
    inv = json.loads(rd('data/invoices.json'))['list'][0]['id'] if exists('data/invoices.json') else 'new'
    for q in ['list&k=services', 'item&k=projects&i=0', 'seo', 'media', 'invoices', 'invoice&id=new', 'invoice&id=' + inv, 'sec&s=invoicing', 'backups', 'account', 'sec&s=menu', 'sec&s=custom']:
        m.goto(ADMIN + 'index.php?p=' + q); m.wait_for_load_state()
        sw = m.evaluate('document.documentElement.scrollWidth')
        check(sw <= 390, f'phone: {q} fits the screen ({sw}px)')
    m.goto(ADMIN + 'index.php?p=seo'); m.wait_for_load_state()
    m.locator('.srow[data-path="drain-cleaning.html"] a').click()
    m.wait_for_selector('#seoModal form.seo-ed')
    box = m.locator('#seoModal').bounding_box()
    inner = m.evaluate("Math.max(document.querySelector('#seoModal').scrollWidth, document.querySelector('#seoModal .sd-body').scrollWidth)")
    save = m.locator('#seoModal button[value=seo_save]').bounding_box()
    check(box and box['width'] >= 389 and box['height'] >= 843 and inner <= 390, f'phone: SEO window fills the screen without sideways scrolling ({inner}px)')
    check(save and save['y'] + save['height'] <= 844 and save['width'] > 80, 'phone: Save button visible at the bottom of the SEO window')
    m.goto(ADMIN + 'index.php?p=media'); m.wait_for_load_state()
    check(m.evaluate("getComputedStyle(document.querySelector('#mediaGrid')).gridTemplateColumns.split(' ').length") == 3, 'phone: Galeri shows three photos per row')
    m.locator('.mtile').nth(1).click()
    m.wait_for_selector('#mediaPane.open form.media-ed'); m.wait_for_timeout(450)
    box = m.locator('#mediaPane').bounding_box(); sv = m.locator('#mediaPane button[value=media_save]').bounding_box()
    check(box and box['x'] <= 1 and box['width'] >= 389 and sv and sv['y'] + sv['height'] <= 844 and m.evaluate('document.documentElement.scrollWidth') <= 390, 'phone: photo details slide in over the page, Save button visible')
    m.locator('#mediaPane [data-media-close]').first.click(); m.wait_for_timeout(450)
    check(not m.locator('#mediaPane').evaluate("e => e.classList.contains('open')"), 'phone: details close again')
    ctx.close()

def t_parity(pn, P):
    # the panel writes exactly what the command-line build produces for the same content
    out = os.path.join(TMP, 'cli')
    subprocess.run(['php', os.path.join(SITE, '_dev/build.php'), '--out=' + out, '--content=' + os.path.join(SITE, 'data/content.json')], check=True, capture_output=True, cwd=SITE)
    same = diff = 0
    for n in os.listdir(out):
        a = open(os.path.join(out, n), encoding='utf-8').read(); b = rd(n)
        if a == b: same += 1
        else: diff += 1; print('     differs:', n)
    check(diff == 0, f'panel output identical to the CLI build ({same} files)')

TESTS = [('setup', t_setup), ('login', t_login), ('login_look', t_login_look), ('security', t_security), ('business', t_business), ('menu', t_menu), ('branding', t_branding),
         ('services', t_services), ('cities', t_cities), ('projects', t_projects), ('reviews_faq', t_reviews_faq), ('seo', t_seo),
         ('preview', t_preview), ('uploads', t_upload_errors), ('backups', t_backups), ('cleanup', t_cleanup), ('custom', t_custom), ('theme', t_theme), ('media', t_media), ('invoices', t_invoices), ('version', t_version_rebuild), ('account', t_account),
         ('mobile', t_mobile), ('parity', t_parity)]

def main():
    make_site(); start_server(); P = make_images()
    print('site:', SITE, ' server:', BASE)
    try:
        with sync_playwright() as pw:
            br = pw.chromium.launch()
            ctx = br.new_context(viewport={'width': 1366, 'height': 900}, accept_downloads=True)
            page = ctx.new_page()
            errors = []
            page.on('pageerror', lambda e: errors.append(str(e)))
            page.on('console', lambda m: errors.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
            page.set_default_timeout(15000)
            pn = Panel(page)
            for name, fn in TESTS:
                if ONLY and name not in ONLY and name not in ('setup',): continue
                print(f'[{name}]', flush=True)
                try:
                    fn(pn, P, br) if name == 'mobile' else fn(pn, P)
                except Exception as e:
                    check(False, f'{name}: exception {type(e).__name__}: {str(e)[:300]}')
            check(not errors, 'no script errors in the panel' + (': ' + '; '.join(errors[:3]) if errors else ''))
            br.close()
    finally:
        server.send_signal(signal.SIGTERM)
        log = open(os.path.join(TMP, 'server.log')).read()
        php_err = [l for l in log.splitlines() if re.search(r'PHP (Warning|Notice|Deprecated|Fatal)', l)]
        check(not php_err, 'no PHP warnings or errors' + (': ' + php_err[0][:300] if php_err else ''))
        bad = [m for ok, m in results if not ok]
        print(f'\n{len(results) - len(bad)}/{len(results)} checks passed')
        if not KEEP: shutil.rmtree(TMP, ignore_errors=True)
        else: print('kept:', TMP)
        sys.exit(1 if bad else 0)

if __name__ == '__main__':
    main()
