#!/usr/bin/env python3
"""
WhatsApp + Google Analytics + social links on the generated pages (settings come from the admin panel).
    python3 _dev/tests/site_features.py
"""
import json, os, re, shutil, socket, subprocess, sys, tempfile, threading, http.server, functools
from playwright.sync_api import sync_playwright

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TMP = tempfile.mkdtemp(prefix='dm-feat-')
ok_all = True
def check(c, m):
    global ok_all
    ok_all &= bool(c); print(('  ok  ' if c else '  FAIL ') + m)

def build(name, change, preview=False):
    c = json.load(open(os.path.join(REPO, 'app/content.default.json')))
    change(c)
    cf = os.path.join(TMP, name + '.json'); json.dump(c, open(cf, 'w'))
    out = os.path.join(TMP, name)
    shutil.copytree(os.path.join(REPO, 'assets'), os.path.join(out, 'assets'))
    subprocess.run(['php', os.path.join(REPO, '_dev/build.php'), '--out=' + out, '--content=' + cf] + (['--preview'] if preview else []), check=True, capture_output=True)
    return out

def serve(root):
    s = socket.socket(); s.bind(('127.0.0.1', 0)); port = s.getsockname()[1]; s.close()
    class Quiet(http.server.SimpleHTTPRequestHandler):
        def log_message(self, *a): pass
    h = functools.partial(Quiet, directory=root)
    srv = http.server.ThreadingHTTPServer(('127.0.0.1', port), h)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return f'http://127.0.0.1:{port}/'

def set_links(**kw):
    def f(c): c['links'].update(kw)
    return f

default = build('default', lambda c: None)
nowa = build('nowa', set_links(whatsapp=''))
ga = build('ga', set_links(ga4='G-TEST1234', instagram='https://www.instagram.com/demir', google='https://maps.app.goo.gl/abc'))
gaprev = build('gaprev', set_links(ga4='G-TEST1234'), preview=True)

idx = open(os.path.join(default, 'index.html')).read()
check('class="wa-fab"' in idx and 'https://wa.me/16479193677?text=' in idx, 'WhatsApp button with the number')
check('wa.me/16479193677?text=Hi%20Demir%20Mechanical' in open(os.path.join(default, 'drain-cleaning.html')).read() or 'wa.me/16479193677?text=' in open(os.path.join(default, 'drain-cleaning.html')).read(), 'WhatsApp link on service pages')
n = open(os.path.join(nowa, 'index.html')).read()
check('data-wa' not in n.split('<script>')[0] and 'wa-fab' not in re.sub(r'<style.*?</style>', '', n, flags=re.S), 'no WhatsApp number: every WhatsApp button removed')
check("var GA='';" in idx, 'no Analytics ID: Analytics not loaded')
g = open(os.path.join(ga, 'index.html')).read()
check("var GA='G-TEST1234';" in g, 'Analytics ID written into the pages')
check('aria-label="Instagram"' in g and 'aria-label="Google Business Profile"' in g and '"sameAs": ["https://www.instagram.com/demir", "https://maps.app.goo.gl/abc"]' in g, 'social icons + Google sameAs from the panel settings')
check('aria-label="Facebook"' not in g, 'empty Facebook link: no icon')
check('site-links.js' not in g, 'pages do not load site-links.js any more')

with sync_playwright() as p:
    b = p.chromium.launch()
    for root, expect, label in [(ga, True, 'live page loads Google Analytics'), (gaprev, False, 'Claude preview never loads Analytics'), (default, False, 'no ID: no Analytics request')]:
        url = serve(root); ctx = b.new_context(); pg = ctx.new_page(); hits = []
        ctx.route('**/*googletagmanager.com/**', lambda r: (hits.append(r.request.url), r.fulfill(status=200, body='', content_type='text/javascript')))
        ctx.route('**/*google.com/maps*', lambda r: r.abort())
        ctx.route('**/fonts.g*/**', lambda r: r.abort())
        pg.goto(url + 'index.html'); pg.wait_for_timeout(500)
        check(bool(hits) == expect and (not expect or 'id=G-TEST1234' in hits[0]), label)
        if expect:
            ev = pg.evaluate("() => { window.dataLayer = window.dataLayer || []; document.querySelector('a[href^=\"tel:\"]').dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true})); return JSON.stringify(window.dataLayer.map(a => Array.from(a))); }")
            check('phone_call_click' in ev, 'phone click recorded as an Analytics event')
        ctx.close()
    b.close()
shutil.rmtree(TMP, ignore_errors=True)
print('ALL OK' if ok_all else 'FAILURES'); sys.exit(0 if ok_all else 1)
