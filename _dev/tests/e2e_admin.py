#!/usr/bin/env python3
"""
End-to-end test of the admin panel against a throw-away copy of the site.

    python3 _dev/tests/e2e_admin.py [--keep] [--only NAME[,NAME]]

Creates a fresh site in a temp folder (pages built like the live server has them, a form-config.php with a test
mailbox password and an old site-links.js), starts `php -S` with _dev/router.php and drives the panel with Playwright:
setup, login + rate limit, CSRF, every editor, uploads (logo, photos, project gallery), projects + photo viewer,
SEO panel, backups/restore/import/downloads, preview, password change, version rebuild, deploy hook, access rules.
"""
import io, json, os, re, shutil, signal, socket, subprocess, sys, tempfile, time, zipfile, urllib.request, urllib.error
from PIL import Image, ImageDraw
from playwright.sync_api import sync_playwright

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
    check(p.locator('h1').inner_text() == 'Giriş', 'logout shows the login page')
    pn.go('sec&s=business')
    check(p.locator('h1').inner_text() == 'Giriş', 'editor pages need login')
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

def t_security(pn, P):
    for path, code in [('/data/content.json', 403), ('/data/admin-auth.php', 403), ('/app/lib.php', 403), ('/app/content.default.json', 403),
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

def t_seo(pn, P):
    p = pn.p
    pn.go('seo')
    d = p.locator('details.seo', has=p.locator('input[name=path][value="about.html"]'))
    d.locator('summary').click()
    d.locator('[name=title]').fill('About Demir Mechanical | Licensed Toronto Plumbers Since 2010')
    check('bad' in (d.locator('.cnt').first.get_attribute('class') or ''), 'title counter turns red over 60 characters')
    check(d.locator('[data-serp-t]').inner_text().startswith('About Demir Mechanical'), 'Google preview follows typing')
    d.locator('[name=title]').fill('About Demir Mechanical | Licensed Toronto Plumbers')
    with p.expect_navigation(): d.locator('button[value=seo_save]').click()
    check('<title>About Demir Mechanical | Licensed Toronto Plumbers</title>' in rd('about.html'), 'SEO title changed from the SEO panel')
    # empty title on a service page -> automatic
    d = p.locator('details.seo', has=p.locator('input[name=path][value="drain-cleaning.html"]'))
    if d.get_attribute('open') is None: d.locator('summary').click()
    d.locator('[name=title]').fill('Drain Cleaning Toronto | Same-Day Service')
    alt = d.locator('input[name^=alt]').first
    alt.fill('Technician snaking a clogged kitchen drain in Toronto')
    f = d.locator('input[name^=altf]').first.input_value()
    with p.expect_navigation(): d.locator('button[value=seo_save]').click()
    page = rd('drain-cleaning.html')
    check('<title>Drain Cleaning Toronto | Same-Day Service</title>' in page, 'service title override')
    check('alt="Technician snaking a clogged kitchen drain in Toronto"' in page, 'alt text changed from the SEO panel')
    refs = [k for k in json.dumps(content())] and json.dumps(content()).count('Technician snaking a clogged kitchen drain in Toronto')
    check(refs >= 1, 'alt text stored in the content')
    # rename a photo file
    d = p.locator('details.seo', has=p.locator('input[name=path][value="drain-cleaning.html"]'))
    if d.get_attribute('open') is None: d.locator('summary').click()
    btn = d.locator('[data-rename]').first
    old = btn.get_attribute('data-rename')
    p.once('dialog', lambda dl: dl.accept('drain-snake-kitchen-sink-toronto'))
    with p.expect_navigation(): btn.click()
    page = rd('drain-cleaning.html')
    check('assets/photos/drain-snake-kitchen-sink-toronto.webp' in page and old not in page, f'photo file renamed ({old} -> drain-snake-kitchen-sink-toronto.webp)')
    check(exists(old) and exists('assets/photos/drain-snake-kitchen-sink-toronto.webp'), 'old file kept for older backups')
    check(exists('assets/photos/drain-snake-kitchen-sink-toronto-sm.webp'), 'phone copy renamed too')
    # clear service title again -> automatic one comes back
    d = p.locator('details.seo', has=p.locator('input[name=path][value="drain-cleaning.html"]'))
    if d.get_attribute('open') is None: d.locator('summary').click()
    d.locator('[name=title]').fill('')
    with p.expect_navigation(): d.locator('button[value=seo_save]').click()
    check('<title>Drain Cleaning Toronto &amp; GTA | Demir Mechanical</title>' in rd('drain-cleaning.html'), 'empty title -> automatic title again')
    # fixed page title cannot be emptied
    d = p.locator('details.seo', has=p.locator('input[name=path][value="contact.html"]'))
    d.locator('summary').click(); d.locator('[name=title]').fill('')
    with p.expect_navigation(): d.locator('button[value=seo_save]').click()
    check('boş bırakılamaz' in pn.flash(), 'fixed page title cannot be empty')
    # bad photo file name is flagged
    pn.go('seo&only=warn')
    check(p.locator('details.seo').count() >= 1, 'warnings filter shows pages with warnings')

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
    check(other.locator('h1').inner_text() == 'Giriş', 'old password no longer works')
    other.fill('[name=pw]', PW2); other.click('button[type=submit]'); other.wait_for_load_state()
    check(other.locator('h1').inner_text() == 'Hoş geldiniz', 'new password works on another device')
    pn.go('account')
    p.once('dialog', lambda d: d.accept())
    with p.expect_navigation(): p.click('button:has-text("Tüm cihazlardan çıkış yap")')
    other.goto(ADMIN + 'index.php?p=dash'); other.wait_for_load_state()
    check(other.locator('h1').inner_text() == 'Giriş', 'logout everywhere ends the other session')
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
    for q in ['list&k=services', 'item&k=projects&i=0', 'seo', 'backups', 'account', 'sec&s=menu', 'sec&s=custom']:
        m.goto(ADMIN + 'index.php?p=' + q); m.wait_for_load_state()
        sw = m.evaluate('document.documentElement.scrollWidth')
        check(sw <= 390, f'phone: {q} fits the screen ({sw}px)')
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

TESTS = [('setup', t_setup), ('login', t_login), ('security', t_security), ('business', t_business), ('menu', t_menu), ('branding', t_branding),
         ('services', t_services), ('cities', t_cities), ('projects', t_projects), ('reviews_faq', t_reviews_faq), ('seo', t_seo),
         ('preview', t_preview), ('uploads', t_upload_errors), ('backups', t_backups), ('cleanup', t_cleanup), ('custom', t_custom), ('version', t_version_rebuild), ('account', t_account),
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
