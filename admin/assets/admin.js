/* Demir Mechanical — admin panel behaviour (no libraries). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var CSRF = document.body.getAttribute('data-csrf') || '';
  var ICONS = {};
  try { ICONS = JSON.parse(($('#dm-icons') || {}).textContent || '{}'); } catch (e) {}
  var PHOTO = { photo: 1, project: 1, hero: 1, portrait: 1, og: 1 };
  var SEOW = {};                                // Arial widths for Google titles (same table as the PHP side)
  try { SEOW = JSON.parse(($('#seo-px') || {}).textContent || '{}'); } catch (e) {}
  function chars(s) { return Array.from(String(s || '')); }
  function seoPx(s) { var t = 0; chars(s).forEach(function (ch) { var w = SEOW[ch.codePointAt(0)]; t += w === undefined ? 556 : w; }); return Math.round(t * 20 / 1000); }

  function kid(el, cls) {                       // direct child with a class (nested repeaters have their own)
    for (var c = el && el.firstElementChild; c; c = c.nextElementSibling) if (c.classList.contains(cls)) return c;
    return null;
  }
  function isItem(n) { return n && n.classList && (n.classList.contains('rep-item') || n.classList.contains('gal-item')); }
  function count(list) { var n = 0; for (var c = list.firstElementChild; c; c = c.nextElementSibling) if (isItem(c)) n++; return n; }
  function toast(msg) {
    var t = document.createElement('div'); t.className = 'toast'; t.textContent = msg; document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3200);
  }
  function slugify(s) {
    var map = { 'ı': 'i', 'İ': 'i', 'ş': 's', 'Ş': 's', 'ğ': 'g', 'Ğ': 'g', 'ü': 'u', 'Ü': 'u', 'ö': 'o', 'Ö': 'o', 'ç': 'c', 'Ç': 'c', '&': ' and ' };
    s = String(s || '').replace(/['’‘]/g, '').replace(/[ıİşŞğĞüÜöÖçÇ&]/g, function (c) { return map[c]; });
    try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  function iconSvg(k) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[k] || ICONS.check || '') + '</svg>';
  }

  /* ---------- side menu (phones) ---------- */
  function setNav(open) {
    document.body.classList.toggle('nav-open', open);
    var s = $('#side'); if (s) s.classList.toggle('open', open);
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setNav(false); });

  /* ---------- unsaved changes ---------- */
  var dirty = false, leaving = false;
  function markDirty(f) { if (f && f.hasAttribute('data-guard')) { f.classList.add('dirty'); dirty = true; } }
  $$('form[data-guard]').forEach(function (f) {
    f.addEventListener('input', function () { markDirty(f); });
    f.addEventListener('change', function (e) { if (!e.target.classList.contains('imgf-up') && !e.target.classList.contains('gal-up')) markDirty(f); });
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !leaving) { e.preventDefault(); e.returnValue = ''; return ''; }
  });
  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    var sub = e.submitter;
    if (sub && (sub.value === 'preview' || sub.getAttribute('formtarget') === '_blank')) return;   // previews open a new tab; this page stays as it is
    if ($('.imgf.busy', f)) { e.preventDefault(); toast('Fotoğraf yüklemesinin bitmesini bekleyin.'); return; }
    leaving = true;
    if (sub && sub.hasAttribute('data-busy')) {
      setTimeout(function () { sub.disabled = true; var sp = sub.querySelector('span'); if (sp) sp.textContent = sub.getAttribute('data-busy'); }, 0);
    }
  });
  // Enter in a one-line field should not send the form
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches && e.target.matches('form.edit input[type=text], form.edit input[type=date]')) e.preventDefault();
  });

  /* ---------- code fields (Özel Kod) ---------- */
  // Sent base64-encoded: some hosting firewalls refuse any form post that contains "<script".
  function b64(s) { var b = new TextEncoder().encode(s), o = ''; for (var i = 0; i < b.length; i++) o += String.fromCharCode(b[i]); return btoa(o); }
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target, made = [];
    $$('textarea[data-code]', f).forEach(function (ta) {
      if (!ta.name || ta.value === '') return;
      var h = document.createElement('input'); h.type = 'hidden'; h.name = ta.name; h.value = 'b64:' + b64(ta.value);
      ta.setAttribute('data-name', ta.name); ta.removeAttribute('name'); f.appendChild(h); made.push([ta, h]);
    });
    if (made.length) setTimeout(function () { made.forEach(function (p) { p[0].name = p[0].getAttribute('data-name'); p[1].remove(); }); }, 0);
  });
  // Tab types two spaces in a code field; press Esc first to move on with Tab
  document.addEventListener('keydown', function (e) {
    var ta = e.target;
    if (!ta.matches || !ta.matches('textarea[data-code]')) return;
    if (e.key === 'Escape') { ta._tabOut = true; return; }
    if (e.key !== 'Tab' || e.shiftKey || e.altKey || e.ctrlKey || e.metaKey || ta._tabOut) { ta._tabOut = false; return; }
    e.preventDefault();
    var a = ta.selectionStart, b = ta.selectionEnd;
    ta.value = ta.value.slice(0, a) + '  ' + ta.value.slice(b);
    ta.selectionStart = ta.selectionEnd = a + 2;
    markDirty(ta.closest('form'));
  });

  /* ---------- repeaters ---------- */
  function renumber(form) {
    if (!form) return;
    $$('[data-n]', form).forEach(function (inp) {
      var idx = [], node = inp.parentElement;
      while (node && node !== form) {
        if (isItem(node)) {
          var i = 0;
          for (var c = node.parentElement.firstElementChild; c && c !== node; c = c.nextElementSibling) if (isItem(c)) i++;
          idx.unshift(i);
        }
        node = node.parentElement;
      }
      inp.name = inp.getAttribute('data-n').replace(/\{(\d+)\}/g, function (m, d) { return idx[+d] !== undefined ? idx[+d] : m; });
    });
  }
  function hasContent(it) {
    return $$('input[type=text], textarea', it).some(function (x) { return x.value.trim() !== ''; }) || $$('.imgf-file', it).some(function (x) { return x.value !== ''; });
  }
  function titleOf(it) {
    var tk = it.getAttribute('data-tk'); if (!tk) return null;
    var body = kid(it, 'rep-body'); if (!body) return null;
    var re = new RegExp('\\[' + tk + '\\]$');
    var hit = null;
    $$('input[type=text], textarea', body).some(function (x) { if (re.test(x.getAttribute('data-n') || '') && x.closest('.rep-item') === it) { hit = x; return true; } return false; });
    return hit;
  }
  function syncTitle(it) {
    var src = titleOf(it), t = it.querySelector('.rep-t');
    if (src && t) t.textContent = src.value.trim() || '(yeni)';
  }
  function pulse(it) { it.classList.add('flashme'); setTimeout(function () { it.classList.remove('flashme'); }, 600); }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest('[data-side-open]')) { setNav(true); return; }
    if (t.closest('[data-side-close]')) { setNav(false); return; }
    var fx = t.closest('.flash-x'); if (fx) { fx.closest('.flash').remove(); return; }
    var bk = t.closest('[data-back]'); if (bk && history.length > 1) { e.preventDefault(); history.back(); return; }
    var cr = t.closest('[data-code-reset]');
    if (cr) {
      var fld = cr.closest('.ftheme'), ta = fld.querySelector('textarea'), tpl = fld.querySelector('template.code-orig');
      if (ta.value !== tpl.content.textContent && !window.confirm('Bu koddaki bütün değişiklikler silinip sitenin orijinal kodu geri gelecek (kaydedince geçerli olur). Devam edilsin mi?')) return;
      ta.value = tpl.content.textContent; markDirty(ta.closest('form'));
      var st = fld.querySelector('.tc-state'); st.className = 'tc-state orig'; var msg = st.querySelector('[data-tc-msg]'); if (msg) msg.textContent = 'Orijinal koda döndürüldü; kaydedince geçerli olur.';
      return;
    }
    var eye = t.closest('[data-pw-toggle]');
    if (eye) {
      var pin = eye.parentElement.querySelector('input'), show = pin.type === 'password';
      pin.type = show ? 'text' : 'password'; eye.classList.toggle('on', show);
      eye.setAttribute('aria-label', show ? 'Şifreyi gizle' : 'Şifreyi göster'); eye.title = eye.getAttribute('aria-label');
      eye.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (show
        ? '<path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 4.2A10 10 0 0 1 12 4c6.4 0 10 8 10 8a17 17 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.6 8 10 8a9.7 9.7 0 0 0 5.4-1.6"/>'
        : '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>') + '</svg>';
      pin.focus();
      return;
    }

    var add = t.closest('.rep-add');
    if (add) {
      var rep = add.closest('.rep'), items = kid(rep, 'rep-items'), tpl = kid(rep, 'rep-tpl');
      var max = +rep.getAttribute('data-max') || 0;
      if (max && count(items) >= max) { toast('En fazla ' + max + ' öğe olabilir.'); return; }
      var node = tpl.content.firstElementChild.cloneNode(true);
      node.classList.add('open');
      items.appendChild(node);
      var form = add.closest('form'); renumber(form); markDirty(form); initIcons(node);
      var first = node.querySelector('input[type=text], textarea'); if (first) first.focus();
      return;
    }
    var tog = t.closest('.rep-tog');
    if (tog) { var ri = tog.closest('.rep-item'); ri.classList.toggle('open'); tog.setAttribute('aria-expanded', ri.classList.contains('open') ? 'true' : 'false'); return; }
    var mv = t.closest('.rep-up, .rep-down');
    if (mv) {
      var it = mv.closest('.rep-item, .gal-item'), up = mv.classList.contains('rep-up'), sib = up ? it.previousElementSibling : it.nextElementSibling;
      while (sib && !isItem(sib)) sib = up ? sib.previousElementSibling : sib.nextElementSibling;
      if (!sib) return;
      if (up) it.parentElement.insertBefore(it, sib); else it.parentElement.insertBefore(sib, it);
      var f2 = it.closest('form'); renumber(f2); markDirty(f2); pulse(it);
      return;
    }
    var del = t.closest('.rep-del');
    if (del) {
      var di = del.closest('.rep-item, .gal-item'), holder = di.parentElement.closest('.rep, .gal');
      var min = holder ? +holder.getAttribute('data-min') || 0 : 0;
      if (min && count(di.parentElement) <= min) { toast('En az ' + min + ' öğe kalmalı.'); return; }
      if (hasContent(di) && !window.confirm(di.classList.contains('gal-item') ? 'Bu fotoğraf galeriden çıkarılsın mı?' : 'Bu öğe silinsin mi?')) return;
      var f3 = di.closest('form'); di.remove(); renumber(f3); markDirty(f3);
      return;
    }
    var clr = t.closest('.imgf-clear');
    if (clr) {
      var box = clr.closest('.imgf');
      setVal(box, '.imgf-file', '');
      kid(box, 'imgf-prev').innerHTML = '<span class="noimg">Fotoğraf yok</span>';
      var nm = box.querySelector('.imgf-name'); if (nm) nm.textContent = '';
      clr.hidden = true; markDirty(box.closest('form'));
      return;
    }
  });
  document.addEventListener('input', function (e) {
    var it = e.target.closest && e.target.closest('.rep-item');
    if (it) syncTitle(it);
    if (e.target.hasAttribute && e.target.hasAttribute('data-seo')) counter(e.target);
  });

  /* ---------- icon pickers ---------- */
  function initIcons(root) {
    $$('.ficon select', root).forEach(function (s) { var p = s.parentElement.querySelector('.ic-prev'); if (p) p.innerHTML = iconSvg(s.value); });
  }
  initIcons(document);
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('.ficon select')) { var p = e.target.parentElement.querySelector('.ic-prev'); if (p) p.innerHTML = iconSvg(e.target.value); }
  });

  /* ---------- SEO length counters in the page editors ---------- */
  function counter(inp) {
    var title = inp.getAttribute('data-seo') === 'title', c = inp._cnt;
    if (!c) {
      c = document.createElement('span'); c.className = 'cnt';
      var fld = inp.closest('.fld'), lab = fld && fld.querySelector('.fl');
      if (lab) lab.appendChild(c); else inp.parentElement.appendChild(c);
      inp._cnt = c;
    }
    var v = inp.value || inp.placeholder || '', n = chars(v).length, auto = inp.value === '' && inp.placeholder ? ' · otomatik' : '';
    if (title) {
      var p = seoPx(v);
      c.textContent = n + ' karakter · ~' + p + ' / 600 px' + auto;
      c.className = 'cnt ' + (n === 0 || p > 600 ? 'bad' : n < 30 ? 'warn' : 'ok');
    } else {
      c.textContent = n + ' / 156' + auto;
      c.className = 'cnt ' + (n === 0 || n > 156 ? 'bad' : n < 120 ? 'warn' : 'ok');
    }
  }
  $$('[data-seo]').forEach(counter);

  /* ---------- page address preview for new services / projects ---------- */
  $$('form[data-kind]').forEach(function (f) {
    var kind = f.getAttribute('data-kind');
    var src = f.querySelector('[name="f[name]"], [name="f[title]"]'), slug = f.querySelector('[name="f[slug]"]');
    var prev = document.createElement('div'); prev.className = 'slug-prev';
    var host = location.host;
    function show() {
      var s = kind === 'cities' ? 'plumber-' + slugify(src ? src.value : '') : slugify(slug && slug.value ? slug.value : (src ? src.value : ''));
      prev.innerHTML = 'Sayfa adresi: ' + host + '/<b></b>.html';
      prev.querySelector('b').textContent = (kind === 'projects' ? 'project-' : '') + (s || '…');
    }
    var anchor = kind === 'cities' ? src : slug;
    if (!anchor) return;
    anchor.closest('.fld').appendChild(prev);
    var touched = !f.hasAttribute('data-new') || (slug && slug.value !== '');
    if (slug) slug.addEventListener('input', function () { touched = slug.value !== ''; show(); });
    if (src) src.addEventListener('input', function () {
      if (!touched && slug && kind !== 'cities') slug.value = slugify(src.value);
      show();
    });
    show();
  });

  /* ---------- photo uploads ---------- */
  function setVal(root, sel, v) { var x = root.querySelector(sel); if (x) x.value = v; }
  function shrink(file, kind) {                  // big phone photos are resized here first: faster upload, no server limits
    return new Promise(function (res) {
      if (!PHOTO[kind] || !/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1500000) return res(file);
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, s = Math.min(1, 2400 / Math.max(w, h));
        if (s >= 1 && file.size < 4000000) { URL.revokeObjectURL(url); return res(file); }
        try {
          var cv = document.createElement('canvas'); cv.width = Math.round(w * s); cv.height = Math.round(h * s);
          var ctx = cv.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
          ctx.drawImage(img, 0, 0, cv.width, cv.height);
          URL.revokeObjectURL(url);
          cv.toBlob(function (b) {
            res(b && b.size < file.size ? new File([b], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
          }, 'image/jpeg', 0.9);
        } catch (e) { res(file); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); res(file); };
      img.src = url;
    });
  }
  function upload(file, kind, name, onProgress) {
    return new Promise(function (res, rej) {
      var fd = new FormData();
      fd.append('a', 'upload'); fd.append('_csrf', CSRF); fd.append('kind', kind); fd.append('name', name || '');
      fd.append('file', file, file.name || 'photo.jpg');
      var x = new XMLHttpRequest();
      x.open('POST', 'index.php');
      x.setRequestHeader('Accept', 'application/json');
      x.setRequestHeader('X-CSRF', CSRF);
      x.upload.onprogress = function (ev) { if (ev.lengthComputable && onProgress) onProgress(Math.round(ev.loaded / ev.total * 100)); };
      x.onload = function () {
        var j = null; try { j = JSON.parse(x.responseText); } catch (e) {}
        if (j && j.ok) res(j); else rej(new Error((j && j.error) || 'Sunucu hatası (' + x.status + '). Tekrar deneyin.'));
      };
      x.onerror = function () { rej(new Error('Bağlantı kesildi. İnternet bağlantınızı kontrol edip tekrar deneyin.')); };
      x.send(fd);
    });
  }
  function nameHint(el) {
    var box = el.closest('.imgf'), alt = box && box.querySelector('.imgf-alt');
    if (alt && alt.value.trim()) return alt.value.trim();
    var f = el.closest('form'), t = f && (f.querySelector('[name="f[title]"]') || f.querySelector('[name="f[name]"]'));
    return t && t.value.trim() ? t.value.trim() : '';
  }
  function applyUpload(box, j) {
    var kind = box.getAttribute('data-kind');
    setVal(box, '.imgf-file', kind === 'favicon' ? (j.icons.apple_touch_icon || j.file) : j.file);
    kid(box, 'imgf-prev').innerHTML = '';
    var im = new Image(); im.alt = ''; im.src = j.url; kid(box, 'imgf-prev').appendChild(im);
    var nm = box.querySelector('.imgf-name'); if (nm) { nm.textContent = j.name + ' '; var d = document.createElement('span'); d.className = 'dim'; d.textContent = j.w + '×' + j.h; nm.appendChild(d); }
    var clr = box.querySelector('.imgf-clear'); if (clr) clr.hidden = false;
    if (kind === 'logo') { setVal(box, '.imgf-webp', j.webp || ''); setVal(box, '.imgf-ratio', (j.w / j.h).toFixed(3)); }
    if (kind === 'flogo') { setVal(box, '.imgf-w', String(Math.round(116 * j.w / j.h))); setVal(box, '.imgf-h', '116'); }
    if (kind === 'favicon') ['favicon_32', 'favicon_48', 'icon_512'].forEach(function (k) { if (j.icons[k]) setVal(box, '.imgf-' + k, j.icons[k]); });
  }
  function uploadInto(box, file) {
    var kind = box.getAttribute('data-kind') || 'photo', st = box.querySelector('.imgf-st');
    box.classList.add('busy'); if (st) st.textContent = 'Hazırlanıyor…';
    return shrink(file, kind)
      .then(function (f) { return upload(f, kind, nameHint(box), function (p) { if (st) st.textContent = 'Yükleniyor… %' + p; }); })
      .then(function (j) { applyUpload(box, j); if (st) st.textContent = 'Yüklendi ✓ Kaydetmeyi unutmayın'; markDirty(box.closest('form')); return true; })
      .catch(function (err) { if (st) st.textContent = ''; window.alert(err.message); return false; })
      .then(function (ok) { box.classList.remove('busy'); return ok; });
  }
  function addToGallery(gal, files) {
    var items = gal.querySelector('.gal-items'), tpl = kid(gal, 'gal-tpl'), st = gal.querySelector('.gal-st'), form = gal.closest('form');
    files = files.filter(function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png|webp)$/i.test(f.name); });
    if (!files.length) { toast('JPG, PNG veya WebP fotoğraf seçin.'); return; }
    var i = 0, ok = 0;
    (function next() {
      if (i >= files.length) { if (st) st.textContent = ok ? ok + ' fotoğraf eklendi ✓ Kaydetmeyi unutmayın.' : ''; return; }
      var file = files[i++], node = tpl.content.firstElementChild.cloneNode(true);
      items.appendChild(node); renumber(form);
      var box = node.querySelector('.imgf'), alt = node.querySelector('.imgf-alt');
      var title = (form.querySelector('[name="f[title]"]') || {}).value || '';
      if (alt && title.trim()) alt.value = title.trim() + (count(items) > 1 ? ' – photo ' + count(items) : '');
      if (st) st.textContent = i + ' / ' + files.length + ' yükleniyor…';
      uploadInto(box, file).then(function (done) {
        if (done) ok++; else { node.remove(); renumber(form); }
        next();
      });
    })();
  }
  document.addEventListener('change', function (e) {
    var inp = e.target;
    if (inp.classList && inp.classList.contains('imgf-up')) {
      var f = inp.files && inp.files[0]; var box = inp.closest('.imgf'); inp.value = '';
      if (f) uploadInto(box, f);
    }
    if (inp.classList && inp.classList.contains('gal-up')) {
      var list = Array.prototype.slice.call(inp.files || []); inp.value = '';
      addToGallery(inp.closest('.gal'), list);
    }
  });

  /* ---------- gallery: drop files, drag to reorder ---------- */
  var dragEl = null;
  function hasFiles(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0; }
  document.addEventListener('mousedown', function (e) { var h = e.target.closest && e.target.closest('.drag'); if (h) h.closest('.gal-item').setAttribute('draggable', 'true'); });
  document.addEventListener('dragstart', function (e) {
    var it = e.target.closest && e.target.closest('.gal-item[draggable=true]');
    if (!it) return;
    dragEl = it; it.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', ''); } catch (x) {}
  });
  document.addEventListener('dragover', function (e) {
    if (dragEl) {
      var over = e.target.closest && e.target.closest('.gal-item');
      e.preventDefault();
      if (!over || over === dragEl || over.parentElement !== dragEl.parentElement) return;
      var r = over.getBoundingClientRect(), after = (e.clientX - r.left) > r.width / 2;
      over.parentElement.insertBefore(dragEl, after ? over.nextSibling : over);
      return;
    }
    var gal = e.target.closest && e.target.closest('.gal');
    if (gal && hasFiles(e)) { e.preventDefault(); gal.querySelector('.gal-drop').classList.add('over'); }
  });
  document.addEventListener('dragleave', function (e) {
    var d = e.target.closest && e.target.closest('.gal-drop'); if (d && !d.contains(e.relatedTarget)) d.classList.remove('over');
  });
  document.addEventListener('drop', function (e) {
    if (dragEl) { e.preventDefault(); return; }
    var gal = e.target.closest && e.target.closest('.gal');
    if (gal && hasFiles(e)) { e.preventDefault(); gal.querySelector('.gal-drop').classList.remove('over'); addToGallery(gal, Array.prototype.slice.call(e.dataTransfer.files || [])); }
  });
  document.addEventListener('dragend', function () {
    if (!dragEl) return;
    var f = dragEl.closest('form');
    dragEl.classList.remove('dragging'); dragEl.setAttribute('draggable', 'false'); dragEl = null;
    renumber(f); markDirty(f);
  });

  /* ---------- SEO panel: list + editor window ---------- */
  var seoApp = $('#seoApp');
  if (seoApp) seoPanel(seoApp);
  function seoPanel(app) {
    var list = $('#seoList'), search = $('#seoSearch'), chips = $('#seoChips'), dlg = $('#seoModal');
    var filter = app.getAttribute('data-filter') || '', cur = null, media = {}, downOnBackdrop = false;

    function cutPx(s, max) {                       // like Google on computers: cut at a word, add " ..."
      if (seoPx(s) <= max) return s;
      var ch = chars(s), out = '';
      for (var i = 0; i < ch.length && seoPx(out + ch[i] + ' ...') <= max; i++) out += ch[i];
      var sp = out.lastIndexOf(' '); if (sp > out.length * 0.6) out = out.slice(0, sp);
      return out.replace(/[\s|,;:–—-]+$/, '') + ' ...';
    }
    function cutLen(s, max) {
      var ch = chars(s); if (ch.length <= max) return s;
      var out = ch.slice(0, max).join(''), sp = out.lastIndexOf(' '); if (sp > max * 0.6) out = out.slice(0, sp);
      return out.replace(/[\s,;:–—-]+$/, '') + ' ...';
    }
    function post(fd) {
      return fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF': CSRF } })
        .then(function (r) { return r.json().catch(function () { throw new Error('Sunucu hatası (' + r.status + '). Sayfayı yenileyip tekrar deneyin.'); }); });
    }

    /* list: search and filter chips */
    function rowShown(r) {
      var st = r.getAttribute('data-st'), q = search ? search.value.trim().toLowerCase() : '';
      if (filter === 'fix' && st !== 'ok' && st !== 'bad') return false;
      if (filter === 'changed' && r.getAttribute('data-ch') !== '1') return false;
      return !q || (r.getAttribute('data-q') || '').indexOf(q) >= 0;
    }
    function applyFilter() {
      var any = false;
      $$('.sgroup', list).forEach(function (g) {
        var n = 0;
        $$('.srow', g).forEach(function (r) { var on = rowShown(r); r.hidden = !on; if (on) n++; });
        g.hidden = n === 0; if (n) any = true;
      });
      var none = $('.seo-none', list); if (none) none.hidden = any;
      $$('a[data-f]', chips).forEach(function (a) {
        var on = a.getAttribute('data-f') === filter; a.classList.toggle('on', on);
        if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
      });
    }
    function setUrl() {
      var u = 'index.php?p=seo' + (filter ? '&f=' + filter : '') + (cur && dlg.open ? '&edit=' + encodeURIComponent(cur.path) : '');
      try { history.replaceState(null, '', u); } catch (e) {}
    }
    if (search) search.addEventListener('input', applyFilter);
    chips.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-f]'); if (!a) return;
      e.preventDefault(); filter = a.getAttribute('data-f'); applyFilter(); setUrl();
    });
    applyFilter();

    /* editor window */
    function setDirty(on) {
      if (cur) { cur.dirty = on; cur.form.classList.toggle('dirty', on); }
      dirty = on || !!$('form.dirty[data-guard]');
    }
    function leaveOk() { return !cur || !cur.dirty || window.confirm('Bu sayfadaki değişiklikler kaydedilmedi. Kaydetmeden devam edilsin mi?'); }
    function navTo(f, dir, path) {
      var el = $('[data-seo-nav="' + dir + '"]', f); if (!el) return;
      var n;
      if (path) {
        n = document.createElement('a'); n.className = 'ib'; n.href = 'index.php?p=seo&edit=' + encodeURIComponent(path);
        n.setAttribute('data-seo-open', path); n.title = dir === 'prev' ? 'Önceki sayfa' : 'Sonraki sayfa'; n.setAttribute('aria-label', n.title);
      } else { n = document.createElement('span'); n.className = 'ib off'; }
      n.setAttribute('data-seo-nav', dir); n.textContent = dir === 'prev' ? '‹' : '›';
      el.replaceWith(n);
    }
    function show(html) {
      dlg.innerHTML = html;
      var f = $('form.seo-ed', dlg);
      if (!f) { cur = null; return; }
      cur = { form: f, path: f.getAttribute('data-path'), dirty: false, seq: 0, timer: 0 };
      var rows = $$('.srow', list).filter(function (r) { return !r.hidden; }).map(function (r) { return r.getAttribute('data-path'); });
      var i = rows.indexOf(cur.path);            // previous / next follow the list as it is filtered now
      if (i >= 0) { navTo(f, 'prev', rows[i - 1] || ''); navTo(f, 'next', rows[i + 1] || ''); }
      var v = 'm'; try { v = localStorage.getItem('dm-seo-view') === 'd' ? 'd' : 'm'; } catch (e) {}
      view(f, v);
      if (!dlg.open) { try { dlg.showModal(); } catch (e) { dlg.setAttribute('open', ''); } }
      var tt = $('.sd-ttl', f); if (tt) try { tt.focus({ preventScroll: true }); } catch (e) {}   // start at the page name (screen readers, keyboard)
      document.body.classList.add('modal-open');
      setDirty(false); setUrl();
    }
    function open(path) {
      if (cur && cur.path === path && dlg.open) return;
      if (!leaveOk()) return;
      dlg.classList.add('loading');
      fetch('index.php?p=seo_edit&path=' + encodeURIComponent(path), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
        .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
        .then(function (r) {
          if (r.text.indexOf('class="seo-ed"') < 0) throw new Error(r.status === 404 ? 'Bu sayfa bulunamadı. Listeyi yenileyin.' : 'Sayfa açılamadı. Oturum kapanmış olabilir; sayfayı yenileyin.');
          if (cur) clearTimeout(cur.timer);
          show(r.text);
          var b = $('.sd-body', dlg); if (b) b.scrollTop = 0;
        })
        .catch(function (err) { window.alert(err.message); })
        .then(function () { dlg.classList.remove('loading'); });
    }
    function close() {
      if (!leaveOk()) return;
      if (cur) clearTimeout(cur.timer);
      cur = null; setDirty(false);
      if (dlg.open) dlg.close();
      dlg.removeAttribute('open'); dlg.innerHTML = '';
      document.body.classList.remove('modal-open');
      setUrl();
    }
    function val(inp) { return inp.value.trim() !== '' ? inp.value.trim() : (inp.placeholder || ''); }
    function meter(f, what, v, auto) {
      var n = chars(v).length, p = seoPx(v), lv, pct, txt;
      if (what === 'title') { lv = n === 0 ? 'bad' : (p > 600 || n < 30 ? 'ok' : 'good'); pct = p / 600; txt = n + ' karakter · yaklaşık ' + p + ' / 600 px'; }
      else { lv = n === 0 ? 'bad' : (n < 120 || n > 156 ? 'ok' : 'good'); pct = n / 156; txt = n + ' karakter · ideal 120–156'; }
      var m = $('[data-meter="' + what + '"]', f);
      m.className = 'meter ' + lv; m.firstElementChild.style.width = Math.min(100, Math.round(pct * 100)) + '%';
      $('[data-meter-txt="' + what + '"]', f).textContent = (auto ? 'Otomatik · ' : '') + txt;
      var inp = $('[name=' + what + ']', f), orig = $('[data-seo-orig="' + what + '"]', f);
      if (orig) orig.hidden = inp.value.trim() === (inp.getAttribute('data-orig') || '');
    }
    function refresh() {
      if (!cur) return;
      var f = cur.form, ti = $('[name=title]', f), de = $('[name=desc]', f), t = val(ti), d = val(de);
      meter(f, 'title', t, ti.value.trim() === '' && t !== '');
      meter(f, 'desc', d, de.value.trim() === '' && d !== '');
      var desk = $('[data-gp]', f).classList.contains('d');
      $('[data-gp-t]', f).textContent = desk ? cutPx(t, 600) : t;
      $('[data-gp-d]', f).textContent = desk ? cutLen(d, 156) : d;
      $('[data-og-t]', f).textContent = t; $('[data-og-d]', f).textContent = d;
      var reset = $('[data-seo-reset]', f), same = true;
      ['kp', 'title', 'desc'].forEach(function (k) { var x = $('[name=' + k + ']', f); if (x.value.trim() !== (x.getAttribute('data-orig') || '')) same = false; });
      if ($('[data-og-val]', f).value !== '') same = false;
      reset.disabled = same;
    }
    function view(f, v) {
      var gp = $('[data-gp]', f); gp.classList.toggle('m', v === 'm'); gp.classList.toggle('d', v === 'd');
      $$('[data-seo-view]', f).forEach(function (b) { var on = b.getAttribute('data-seo-view') === v; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      refresh();
    }
    function check() {
      if (!cur) return;
      var c = cur, seq = ++c.seq, fd = new FormData(c.form);
      fd.set('a', 'seo_check');
      post(fd).then(function (j) {
        if (cur !== c || seq !== c.seq || !j.ok) return;
        var box = $('[data-seo-check]', c.form), keep = {};
        $$('details.sc[data-user]', box).forEach(function (d) { keep[d.getAttribute('data-sc')] = d.open; });
        box.innerHTML = j.html;
        Object.keys(keep).forEach(function (k) { var d = $('details.sc[data-sc="' + k + '"]', box); if (d) { d.open = keep[k]; d.setAttribute('data-user', '1'); } });
        var st = $('[data-seo-st]', c.form); st.className = 'sst ' + j.status; st.title = j.label;
      }).catch(function () {});
    }
    function changed() {
      if (!cur) return;
      setDirty(true); refresh();
      var er = $('[data-seo-err]', cur.form); if (er) er.hidden = true;
      clearTimeout(cur.timer); cur.timer = setTimeout(check, 500);
    }
    function showErr(f, msg) {
      var er = $('[data-seo-err]', f); if (!er) { window.alert(msg); return; }
      er.textContent = msg; er.hidden = false;
      var b = $('.sd-body', f); if (b) b.scrollTop = 0;
    }
    function save(f, btn) {
      if (f.classList.contains('busy')) return;
      if (f.classList.contains('uploading')) { toast('Görsel yüklemesinin bitmesini bekleyin.'); return; }
      var fd = new FormData(f); fd.set('a', 'seo_save');
      btn = btn || $('button[value=seo_save]', f);
      var sp = btn && btn.querySelector('span'), old = sp ? sp.textContent : '';
      f.classList.add('busy'); if (btn) btn.disabled = true; if (sp) sp.textContent = 'Kaydediliyor…';
      post(fd).then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Kaydedilemedi.');
        setDirty(false);
        if (j.list) list.innerHTML = j.list;
        if (j.chips) chips.innerHTML = j.chips;
        applyFilter();
        if (j.html) { var top = ($('.sd-body', f) || {}).scrollTop || 0; show(j.html); var b = $('.sd-body', dlg); if (b) b.scrollTop = top; }
        toast(j.note || 'Kaydedildi.');
      }).catch(function (err) {
        showErr(f, err.message);
      }).then(function () {
        f.classList.remove('busy'); if (btn) btn.disabled = false; if (sp) sp.textContent = old;
      });
    }
    function suggest(f, what, btn) {
      var fd = new FormData();
      fd.append('a', 'seo_suggest'); fd.append('_csrf', CSRF); fd.append('path', cur.path); fd.append('what', what); fd.append('kp', $('[name=kp]', f).value);
      btn.disabled = true;
      post(fd).then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Öneri alınamadı.');
        var inp = $('[name=' + what + ']', f); inp.value = j.text; inp.focus(); changed();
      }).catch(function (err) { toast(err.message); }).then(function () { btn.disabled = false; });
    }

    /* sharing image */
    function ogSet(f, v, url, wh) {
      var box = $('[data-og]', f), img = $('[data-og-img]', box), gi = $('[data-gp-img]', f);
      $('[data-og-val]', box).value = v;
      var src = v === 'none' ? '' : (v === '' ? box.getAttribute('data-auto-url') : url);
      [img, gi].forEach(function (el) {
        el.innerHTML = '';
        if (src) { var im = new Image(); im.alt = ''; im.src = src; el.appendChild(im); }
        else if (el === img) { var s = document.createElement('span'); s.className = 'noimg'; s.textContent = 'Görsel yok'; el.appendChild(s); }
      });
      gi.hidden = !src;
      var auto = box.getAttribute('data-auto'), awh = box.getAttribute('data-auto-wh');
      $('[data-og-info]', box).textContent = v === 'none' ? 'Görselsiz paylaşılır.'
        : v === '' ? (auto ? 'Otomatik (' + (box.getAttribute('data-auto-icon') === '1' ? 'site ikonu' : 'sayfanın fotoğrafı') + '): ' + auto.split('/').pop() + (awh ? ' · ' + awh : '') : 'Görsel yok.')
        : 'Seçilen: ' + v.split('/').pop() + (wh ? ' · ' + wh : '');
      changed();
    }
    function fillMedia(f, grid, items) {
      grid.innerHTML = '';
      if (!items.length) { grid.innerHTML = '<p class="muted small">Sitede seçilebilecek fotoğraf yok. "Yeni yükle" ile ekleyin.</p>'; return; }
      var curV = $('[data-og-val]', f).value;
      items.forEach(function (m) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'ogp' + (m.w < 600 ? ' small' : '') + (m.file === curV ? ' on' : '');
        b.setAttribute('data-og-pick', m.file); b.setAttribute('data-url', m.url); b.setAttribute('data-wh', m.w + '×' + m.h);
        b.title = m.name + ' · ' + m.w + '×' + m.h + (m.w < 600 ? ' (küçük)' : '');
        var im = new Image(); im.alt = ''; im.loading = 'lazy'; im.src = m.url; b.appendChild(im);
        var s = document.createElement('span'); s.textContent = m.w + '×' + m.h; b.appendChild(s);
        grid.appendChild(b);
      });
    }
    function pick(f, btn) {
      var grid = $('[data-og-grid]', f), path = cur.path;
      if (!grid.hidden) { grid.hidden = true; btn.setAttribute('aria-expanded', 'false'); return; }
      grid.hidden = false; btn.setAttribute('aria-expanded', 'true');
      if (media[path]) { fillMedia(f, grid, media[path]); return; }
      grid.innerHTML = '<p class="muted small">Fotoğraflar yükleniyor…</p>';
      fetch('index.php?p=seo_media&path=' + encodeURIComponent(path), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error(); media[path] = j.items || []; if (cur && cur.form === f) fillMedia(f, grid, media[path]); })
        .catch(function () { grid.innerHTML = '<p class="muted small">Fotoğraflar yüklenemedi. Sayfayı yenileyip tekrar deneyin.</p>'; });
    }
    function ogUpload(f, input) {
      var file = input.files && input.files[0]; input.value = '';
      if (!file) return;
      var st = $('[data-og-st]', f), name = val($('[name=title]', f)).split('|')[0].trim() || cur.path.replace(/\.html$/, '');
      f.classList.add('uploading'); st.textContent = 'Hazırlanıyor…';
      shrink(file, 'og')
        .then(function (fl) { return upload(fl, 'og', name, function (p) { st.textContent = 'Yükleniyor… %' + p; }); })
        .then(function (j) { st.textContent = 'Yüklendi ✓ Kaydetmeyi unutmayın'; media = {}; if (cur && cur.form === f) { ogSet(f, j.file, j.url, j.w + '×' + j.h); var g = $('[data-og-grid]', f); g.hidden = true; } })
        .catch(function (err) { st.textContent = ''; window.alert(err.message); })
        .then(function () { f.classList.remove('uploading'); });
    }

    /* events */
    app.addEventListener('click', function (e) {
      var t = e.target, f = cur && cur.form;
      var op = t.closest('[data-seo-open]'); if (op) { e.preventDefault(); open(op.getAttribute('data-seo-open')); return; }
      if (t.closest('[data-seo-close]')) { e.preventDefault(); close(); return; }
      if (!f || !f.contains(t)) return;
      var sm = t.closest('summary'); if (sm && sm.parentElement.matches('details.sc')) { sm.parentElement.setAttribute('data-user', '1'); return; }
      var vw = t.closest('[data-seo-view]');
      if (vw) { var v = vw.getAttribute('data-seo-view'); view(f, v); try { localStorage.setItem('dm-seo-view', v); } catch (x) {} return; }
      var sg = t.closest('[data-seo-suggest]'); if (sg) { suggest(f, sg.getAttribute('data-seo-suggest'), sg); return; }
      var og = t.closest('[data-seo-orig]');
      if (og) { var inp = $('[name=' + og.getAttribute('data-seo-orig') + ']', f); inp.value = inp.getAttribute('data-orig') || ''; inp.focus(); changed(); return; }
      if (t.closest('[data-seo-reset]')) {
        ['kp', 'title', 'desc'].forEach(function (k) { var x = $('[name=' + k + ']', f); x.value = x.getAttribute('data-orig') || ''; });
        if ($('[data-og-val]', f).value !== '') ogSet(f, ''); else changed();
        return;
      }
      var act = t.closest('[data-og-act]');
      if (act) {
        var a = act.getAttribute('data-og-act');
        if (a === 'pick') pick(f, act); else ogSet(f, a === 'none' ? 'none' : '');
        return;
      }
      var pk = t.closest('[data-og-pick]');
      if (pk) {
        ogSet(f, pk.getAttribute('data-og-pick'), pk.getAttribute('data-url'), pk.getAttribute('data-wh'));
        $$('.ogp', pk.parentElement).forEach(function (b) { b.classList.toggle('on', b === pk); });
      }
    });
    dlg.addEventListener('input', function (e) { if (cur && cur.form.contains(e.target) && e.target.name) changed(); });
    dlg.addEventListener('change', function (e) { if (cur && e.target.matches('[data-og-up]')) ogUpload(cur.form, e.target); });
    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.matches('input[type=text]')) { e.preventDefault(); }
    });
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(); });                // Esc
    dlg.addEventListener('close', function () {                   // closed by the browser itself (Esc pressed twice)
      if (!cur) return;
      clearTimeout(cur.timer); cur = null; setDirty(false); dlg.innerHTML = '';
      document.body.classList.remove('modal-open'); setUrl();
    });
    dlg.addEventListener('mousedown', function (e) { downOnBackdrop = e.target === dlg; });
    dlg.addEventListener('click', function (e) { if (e.target === dlg && downOnBackdrop) close(); });  // click next to the window
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f.matches || !f.matches('form.seo-ed:not(.media-ed)') || !window.fetch) return;
      e.preventDefault(); e.stopImmediatePropagation();
      save(f, e.submitter);
    }, true);
    // opened from a link (index.php?p=seo&edit=…): make it a real modal window
    var first = $('form.seo-ed', dlg);
    if (first) { var html = dlg.innerHTML; dlg.removeAttribute('open'); show(html); }
  }

  /* ---------- Galeri: photo grid, details panel on the right, "Fotoğraf ekle" ---------- */
  function postJson(fd) {
    return fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF': CSRF } })
      .then(function (r) { return r.json().catch(function () { throw new Error('Sunucu hatası (' + r.status + '). Sayfayı yenileyip tekrar deneyin.'); }); })
      .then(function (j) { if (!j.ok) throw new Error(j.error || 'İşlem yapılamadı.'); return j; });
  }
  var mediaApp = $('#mediaApp');
  if (mediaApp) mediaPanel(mediaApp);
  function mediaPanel(app) {
    var grid = $('#mediaGrid'), search = $('#mediaSearch'), chips = $('#mediaChips'), pane = $('#mediaPane'), upBox = $('#mediaUp');
    var emptyTpl = $('#mediaEmpty'), wide = window.matchMedia('(min-width: 1100px)');
    var filter = app.getAttribute('data-filter') || '', cur = null, adding = false;
    function tiles() { return $$('.mtile', grid); }
    function shown(c) {
      var q = search ? search.value.trim().toLowerCase() : '';
      if (filter === 'unused') { if (c.getAttribute('data-un') !== '1') return false; }
      else {
        if (c.getAttribute('data-sh') !== '1') return false;
        if ((filter === 'photo' || filter === 'og' || filter === 'logo') && c.getAttribute('data-cat') !== filter) return false;
        if (filter === 'noalt' && c.getAttribute('data-na') !== '1') return false;
      }
      return !q || (c.getAttribute('data-q') || '').indexOf(q) >= 0;
    }
    function applyFilter() {
      var any = false;
      tiles().forEach(function (c) { var on = shown(c); c.hidden = !on; if (on) any = true; });
      var none = $('.seo-none', grid); if (none) none.hidden = any;
      $$('a[data-f]', chips).forEach(function (a) {
        var on = a.getAttribute('data-f') === filter; a.classList.toggle('on', on);
        if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
      });
      if (cur) navAll();
    }
    function setUrl() {
      var u = 'index.php?p=media' + (filter ? '&f=' + filter : '') + (cur ? '&open=' + encodeURIComponent(cur.file) : '');
      try { history.replaceState(null, '', u); } catch (e) {}
    }
    function setFilter(f) { filter = f; applyFilter(); setUrl(); }
    function setDirty(on) {
      if (cur) { cur.dirty = on; cur.form.classList.toggle('dirty', on); }
      dirty = on || !!$('form.dirty[data-guard]');
    }
    function leaveOk() { return !cur || !cur.dirty || window.confirm('Bu fotoğraftaki değişiklikler kaydedilmedi. Kaydetmeden devam edilsin mi?'); }
    function select(file) {
      tiles().forEach(function (t) {
        var on = !!file && t.getAttribute('data-media-open') === file; t.classList.toggle('on', on);
        if (on) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
      });
    }
    function nav(f, dir, file) {
      var el = $('[data-media-nav="' + dir + '"]', f); if (!el) return;
      var n;
      if (file) {
        n = document.createElement('a'); n.className = 'ib'; n.href = 'index.php?p=media&open=' + encodeURIComponent(file);
        n.setAttribute('data-media-open', file); n.title = dir === 'prev' ? 'Önceki fotoğraf (←)' : 'Sonraki fotoğraf (→)'; n.setAttribute('aria-label', n.title);
      } else { n = document.createElement('span'); n.className = 'ib off'; }
      n.setAttribute('data-media-nav', dir); n.textContent = dir === 'prev' ? '‹' : '›';
      el.replaceWith(n);
    }
    function navAll() {
      var list = tiles().filter(function (c) { return !c.hidden; }).map(function (c) { return c.getAttribute('data-media-open'); });
      var i = list.indexOf(cur.file);
      nav(cur.form, 'prev', i > 0 ? list[i - 1] : ''); nav(cur.form, 'next', i >= 0 && i < list.length - 1 ? list[i + 1] : '');
    }
    function openPane(on) {
      pane.classList.toggle('open', on);
      document.body.classList.toggle('pane-open', on && !wide.matches);
    }
    function showEmpty() {
      cur = null; setDirty(false); select('');
      pane.innerHTML = emptyTpl ? emptyTpl.innerHTML : '';
      openPane(false); setUrl();
    }
    function show(html, focusAlt) {
      pane.innerHTML = html;
      var f = $('form.media-ed', pane);
      if (!f) { showEmpty(); return; }
      cur = { form: f, file: f.getAttribute('data-file'), dirty: false };
      navAll(); select(cur.file); openPane(true);
      var b = $('.sd-body', pane); if (b) b.scrollTop = 0;
      var a = focusAlt && $('#mdAlt', f), tt = $('.sd-ttl', f);
      try { if (a) a.focus(); else if (tt) tt.focus({ preventScroll: true }); } catch (e) {}
      var t = $('.mtile.on', grid);
      if (t && wide.matches && t.scrollIntoView) { var r = t.getBoundingClientRect(); if (r.top < 0 || r.bottom > window.innerHeight) t.scrollIntoView({ block: 'nearest' }); }
      setDirty(false); setUrl();
    }
    function open(file, focusAlt) {
      if (cur && cur.file === file) { openPane(true); return; }
      if (!leaveOk()) return;
      pane.classList.add('loading');
      fetch('index.php?p=media_edit&file=' + encodeURIComponent(file), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
        .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
        .then(function (r) {
          if (r.text.indexOf('media-ed"') < 0) throw new Error(r.status === 404 ? 'Bu fotoğraf bulunamadı. Sayfayı yenileyin.' : 'Fotoğraf açılamadı. Oturum kapanmış olabilir; sayfayı yenileyin.');
          show(r.text, focusAlt);
        })
        .catch(function (err) { window.alert(err.message); })
        .then(function () { pane.classList.remove('loading'); });
    }
    function close() { if (leaveOk()) showEmpty(); }
    function apply(j, focusAlt) {               // the server's answer after a change: new grid, chips and panel
      if (j.grid) grid.innerHTML = j.grid;
      if (j.chips) chips.innerHTML = j.chips;
      if (j.empty && emptyTpl) emptyTpl.innerHTML = j.empty;
      cur = null;
      applyFilter();
      if (j.html && j.html.indexOf('media-ed"') >= 0) show(j.html, focusAlt); else showEmpty();
    }
    function errBox(f, msg) { var er = $('[data-media-err]', f); if (er) { er.textContent = msg; er.hidden = false; } else window.alert(msg); }
    function busy(f, btn, on, label) {
      var sp = btn && btn.querySelector('span');
      f.classList.toggle('busy', on);
      if (btn) { btn.disabled = on; if (sp) { if (on) { btn._t = sp.textContent; sp.textContent = label; } else if (btn._t) sp.textContent = btn._t; } }
    }
    function save(f, btn) {
      if (f.classList.contains('busy')) return;
      var fd = new FormData(f); fd.set('a', 'media_save');
      btn = btn && btn.value === 'media_save' ? btn : $('button[value=media_save]', f);
      busy(f, btn, true, 'Kaydediliyor…');
      postJson(fd)
        .then(function (j) { setDirty(false); apply(j); toast(j.note || 'Kaydedildi.'); })
        .catch(function (err) { busy(f, btn, false); errBox(f, err.message); });
    }
    function del(f, btn) {
      if (f.classList.contains('busy') || !window.confirm(btn.getAttribute('data-del-msg') || 'Bu fotoğraf silinsin mi?')) return;
      var fd = new FormData(); fd.set('a', 'media_delete'); fd.set('_csrf', CSRF); fd.set('file', f.getAttribute('data-file'));
      busy(f, btn, true, 'Siliniyor…');
      postJson(fd)
        .then(function (j) { setDirty(false); cur = null; apply(j); toast(j.note || 'Silindi.'); })
        .catch(function (err) { busy(f, btn, false); errBox(f, err.message); });
    }
    /* adding photos: several at once, by the button or by dropping them on the page */
    function addFiles(files) {
      files = files.filter(function (f) { return /^image\/(jpeg|png|webp)$/.test(f.type) || /\.(jpe?g|png|webp)$/i.test(f.name || ''); });
      if (!files.length) { toast('JPG, PNG veya WebP fotoğraf seçin.'); return; }
      if (adding) { toast('Önceki yükleme bitmeden yeni fotoğraf eklenemez.'); return; }
      if (!leaveOk()) return;
      adding = true; app.classList.add('adding');
      var done = [], errs = [], i = 0;
      function line(p) {
        upBox.hidden = false;
        upBox.innerHTML = '<span class="mup-sp"></span><b></b><span class="mup-bar"><i></i></span>';
        upBox.querySelector('b').textContent = i + ' / ' + files.length + ' yükleniyor… ' + (p ? '%' + p : '');
        upBox.querySelector('i').style.width = Math.round(((i - 1) + (p || 0) / 100) / files.length * 100) + '%';
      }
      function finish() {
        if (!done.length) {
          adding = false; app.classList.remove('adding'); upBox.hidden = true;
          if (errs.length) window.alert('Yüklenemedi:\n' + errs.join('\n'));
          return;
        }
        upBox.querySelector('b').textContent = 'Galeriye ekleniyor…';
        var fd = new FormData(); fd.set('a', 'media_add'); fd.set('_csrf', CSRF);
        done.forEach(function (f) { fd.append('files[]', f); });
        postJson(fd)
          .then(function (j) {
            if (search) search.value = '';
            filter = ''; cur = null; apply(j, true);
            toast(j.note || 'Eklendi.');
            if (errs.length) window.alert('Bazı dosyalar yüklenemedi:\n' + errs.join('\n'));
          })
          .catch(function (err) { window.alert(err.message); })
          .then(function () { adding = false; app.classList.remove('adding'); upBox.hidden = true; upBox.innerHTML = ''; });
      }
      (function next() {
        if (i >= files.length) { finish(); return; }
        var file = files[i++], name = (file.name || 'photo').replace(/\.[^.]+$/, '');
        line(0);
        shrink(file, 'photo')
          .then(function (fl) { return upload(fl, 'photo', name, function (p) { line(p); }); })
          .then(function (j) { done.push(j.file); }, function (err) { errs.push((file.name || 'Fotoğraf') + ': ' + err.message); })
          .then(next);
      })();
    }
    // drag and drop anywhere on the page
    var depth = 0;
    function hasFiles(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0; }
    document.addEventListener('dragenter', function (e) { if (hasFiles(e)) { depth++; app.classList.add('dropping'); } });
    document.addEventListener('dragleave', function (e) { if (hasFiles(e) && --depth <= 0) { depth = 0; app.classList.remove('dropping'); } });
    document.addEventListener('dragover', function (e) { if (hasFiles(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
    document.addEventListener('drop', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault(); depth = 0; app.classList.remove('dropping');
      addFiles(Array.prototype.slice.call(e.dataTransfer.files || []));
    });
    app.addEventListener('change', function (e) {
      if (!e.target.matches('[data-media-add]')) return;
      var list = Array.prototype.slice.call(e.target.files || []); e.target.value = '';
      addFiles(list);
    });
    /* events */
    if (search) search.addEventListener('input', applyFilter);
    chips.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-f]'); if (!a) return;
      e.preventDefault(); setFilter(a.getAttribute('data-f'));
    });
    app.addEventListener('click', function (e) {
      var go = e.target.closest('[data-f-go]'); if (go) { e.preventDefault(); setFilter(go.getAttribute('data-f-go')); return; }
      var op = e.target.closest('[data-media-open]'); if (op) { e.preventDefault(); open(op.getAttribute('data-media-open')); return; }
      if (e.target.closest('[data-media-close]')) { e.preventDefault(); close(); }
    });
    pane.addEventListener('input', function (e) {
      if (!cur || !cur.form.contains(e.target) || !e.target.name) return;
      setDirty(true); var er = $('[data-media-err]', cur.form); if (er) er.hidden = true;
    });
    pane.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && e.target.matches('input[type=text], #mdAlt')) { e.preventDefault(); if (cur && $('button[value=media_save]', cur.form)) save(cur.form); }
    });
    document.addEventListener('keydown', function (e) {
      if (!cur || e.altKey || e.ctrlKey || e.metaKey || $('dialog[open]')) return;
      var t = e.target, typing = t.matches && t.matches('input, textarea, select, [contenteditable]');
      if (e.key === 'Escape') { if (typing && t.blur) t.blur(); close(); return; }
      if (typing || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
      var n = $('[data-media-nav="' + (e.key === 'ArrowLeft' ? 'prev' : 'next') + '"][data-media-open]', cur.form);
      if (n) { e.preventDefault(); open(n.getAttribute('data-media-open')); }
    });
    wide.addEventListener && wide.addEventListener('change', function () { openPane(!!cur); });
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f.matches || !f.matches('form.media-ed') || !window.fetch) return;
      e.preventDefault(); e.stopImmediatePropagation();
      if (e.submitter && e.submitter.value === 'media_delete') del(f, e.submitter); else save(f, e.submitter);
    }, true);
    applyFilter();
    if ($('form.media-ed', pane)) show(pane.innerHTML); else openPane(false);
  }

  /* ---------- "Galeriden seç" / "Galeriden ekle": use photos of the gallery in the editors ---------- */
  var pick = { dlg: null, items: null, multi: false, sel: [], done: null };
  function pickLoad() {
    if (pick.items) return Promise.resolve(pick.items);
    return fetch('index.php?p=media_pick', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j.ok) throw new Error(); pick.items = j.items || []; return pick.items; });
  }
  function pickRender() {
    var g = $('[data-pk-grid]', pick.dlg), q = $('[data-pk-q]', pick.dlg).value.trim().toLowerCase(), n = 0;
    g.innerHTML = '';
    (pick.items || []).forEach(function (m) {
      if (q && (m.name + ' ' + m.alt).toLowerCase().indexOf(q) < 0) return;
      n++;
      var b = document.createElement('button'); b.type = 'button'; b.className = 'pk-i' + (pick.sel.indexOf(m) >= 0 ? ' on' : '');
      b._m = m; b.title = m.name + (m.alt ? ' — ' + m.alt : '') + ' · ' + m.w + '×' + m.h;
      var im = new Image(); im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.src = m.sm || m.url; b.appendChild(im);
      var c = document.createElement('span'); c.className = 'pk-c'; c.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'; b.appendChild(c);
      if (m.new) { var nw = document.createElement('span'); nw.className = 'pk-new'; nw.textContent = 'Kullanılmıyor'; b.appendChild(nw); }
      g.appendChild(b);
    });
    if (!n) g.innerHTML = '<p class="muted small pk-none">' + ((pick.items || []).length ? 'Aramaya uyan fotoğraf yok.' : 'Galeride fotoğraf yok. Galeri sayfasındaki "Fotoğraf ekle" ile yükleyin.') + '</p>';
    pickInfo();
  }
  function pickInfo() {
    var info = $('[data-pk-n]', pick.dlg), ok = $('[data-pk-ok]', pick.dlg), s = pick.sel;
    ok.disabled = !s.length;
    ok.querySelector('span').textContent = pick.multi ? (s.length ? s.length + ' fotoğraf ekle' : 'Ekle') : 'Seç';
    info.textContent = s.length === 1 ? s[0].name + (s[0].alt ? ' — ' + s[0].alt : ' (alternatif metni yok)') : s.length ? s.length + ' fotoğraf seçildi' : (pick.multi ? 'Eklemek istediğiniz fotoğraflara tıklayın.' : 'Bir fotoğrafa tıklayın.');
  }
  function pickClose() { if (pick.dlg && pick.dlg.open) pick.dlg.close(); }
  function pickOpen(multi, done) {
    if (!pick.dlg) {
      var d = pick.dlg = document.createElement('dialog');
      d.className = 'seo-dlg pick-dlg'; d.setAttribute('aria-label', 'Galeriden fotoğraf seçin');
      d.innerHTML = '<div class="pk"><div class="sd-head"><div class="sd-ttl"><b data-pk-t></b><small>Galerideki fotoğraflar · en yeniler önce</small></div>'
        + '<div class="sd-nav"><button type="button" class="ib" data-pk-close aria-label="Kapat" title="Kapat">×</button></div></div>'
        + '<div class="pk-bar"><label class="seo-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>'
        + '<input type="search" data-pk-q placeholder="Dosya adı veya alt metin ara…" autocomplete="off"></label><a class="btn sm ghost" href="index.php?p=media" target="_blank" rel="noopener">Galeriyi aç</a></div>'
        + '<div class="pk-scroll"><div class="pk-grid" data-pk-grid></div></div>'
        + '<div class="sd-foot"><span class="pk-n" data-pk-n></span><div class="sd-fa"><button type="button" class="btn" data-pk-close>Vazgeç</button><button type="button" class="btn primary" data-pk-ok><span>Seç</span></button></div></div></div>';
      document.body.appendChild(d);
      d.addEventListener('click', function (e) {
        if (e.target === d || e.target.closest('[data-pk-close]')) { pickClose(); return; }
        if (e.target.closest('[data-pk-ok]')) { var s = pick.sel.slice(); pickClose(); if (s.length && pick.done) pick.done(s); return; }
        var b = e.target.closest('.pk-i'); if (!b) return;
        var m = b._m, k = pick.sel.indexOf(m);
        if (pick.multi) { if (k >= 0) pick.sel.splice(k, 1); else pick.sel.push(m); b.classList.toggle('on', k < 0); }
        else { pick.sel = [m]; $$('.pk-i.on', d).forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); }
        pickInfo();
      });
      d.addEventListener('dblclick', function (e) { var b = e.target.closest('.pk-i'); if (b && !pick.multi) { pickClose(); if (pick.done) pick.done([b._m]); } });
      d.addEventListener('input', function (e) { if (e.target.matches('[data-pk-q]')) pickRender(); });
      d.addEventListener('close', function () { document.body.classList.remove('modal-open'); });
    }
    pick.multi = multi; pick.sel = []; pick.done = done;
    $('[data-pk-t]', pick.dlg).textContent = multi ? 'Galeriden fotoğraf ekleyin' : 'Galeriden fotoğraf seçin';
    $('[data-pk-q]', pick.dlg).value = '';
    $('[data-pk-grid]', pick.dlg).innerHTML = '<p class="muted small pk-none">Fotoğraflar yükleniyor…</p>';
    pickInfo();
    try { pick.dlg.showModal(); } catch (e) { pick.dlg.setAttribute('open', ''); }
    document.body.classList.add('modal-open');
    pickLoad().then(pickRender, function () { $('[data-pk-grid]', pick.dlg).innerHTML = '<p class="muted small pk-none">Fotoğraflar yüklenemedi. Sayfayı yenileyip tekrar deneyin.</p>'; });
  }
  function pickInto(box, m) {
    applyUpload(box, { file: m.file, url: m.url, name: m.name, w: m.w, h: m.h });
    var alt = box.querySelector('.imgf-alt'); if (alt && m.alt) alt.value = m.alt;
    var st = box.querySelector('.imgf-st'); if (st) st.textContent = 'Galeriden seçildi ✓ Kaydetmeyi unutmayın';
    markDirty(box.closest('form'));
  }
  function pickIntoGallery(gal, list) {
    var items = gal.querySelector('.gal-items'), tpl = kid(gal, 'gal-tpl'), st = gal.querySelector('.gal-st'), form = gal.closest('form');
    var title = ((form.querySelector('[name="f[title]"]') || {}).value || '').trim();
    list.forEach(function (m) {
      var node = tpl.content.firstElementChild.cloneNode(true);
      items.appendChild(node); renumber(form);
      applyUpload(node.querySelector('.imgf'), { file: m.file, url: m.url, name: m.name, w: m.w, h: m.h });
      var alt = node.querySelector('.imgf-alt'); if (alt) alt.value = m.alt || (title ? title + (count(items) > 1 ? ' – photo ' + count(items) : '') : '');
    });
    if (st) st.textContent = list.length + ' fotoğraf eklendi ✓ Kaydetmeyi unutmayın.';
    markDirty(form);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.imgf-pick, .gal-pick');
    if (!b) return;
    e.preventDefault();
    if (b.classList.contains('gal-pick')) { var gal = b.closest('.gal'); pickOpen(true, function (list) { pickIntoGallery(gal, list); }); }
    else { var box = b.closest('.imgf'); pickOpen(false, function (list) { pickInto(box, list[0]); }); }
  });

  /* ---------- Faturalar: list search + filters ---------- */
  var invApp = $('#invApp');
  if (invApp) (function () {
    var q = $('#invSearch'), chips = $('#invChips'), rows = $$('.irow', invApp), none = $('.seo-none', invApp);
    var f = (chips && $('a.on', chips) || { getAttribute: function () { return ''; } }).getAttribute('data-f') || '';
    function hit(st) { return !f || st === f || (f === 'open' && (st === 'sent' || st === 'overdue')); }
    function apply() {
      var terms = (q ? q.value : '').toLowerCase().trim().split(/\s+/).filter(Boolean), n = 0;
      rows.forEach(function (r) {
        var d = r.getAttribute('data-q') || '', ok = hit(r.getAttribute('data-st')) && terms.every(function (t) { return d.indexOf(t) >= 0; });
        r.hidden = !ok; if (ok) n++;
      });
      if (none) none.hidden = n > 0;
    }
    if (chips) chips.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-f]'); if (!a) return;
      e.preventDefault(); f = a.getAttribute('data-f');
      $$('a[data-f]', chips).forEach(function (c) { var on = c === a; c.classList.toggle('on', on); if (on) c.setAttribute('aria-current', 'true'); else c.removeAttribute('aria-current'); });
      try { history.replaceState(null, '', a.getAttribute('href')); } catch (x) {}
      apply();
    });
    if (q) q.addEventListener('input', apply);
  })();

  /* ---------- Faturalar: editor (live totals, line amounts, known customers) ---------- */
  $$('form[data-invoice]').forEach(function (f) {
    var CUST = {};
    try { CUST = JSON.parse(($('#inv-customers') || {}).textContent || '{}'); } catch (e) {}
    function fld(k) { return f.querySelector('[name="f[' + k + ']"]'); }
    function num(el) { if (!el) return 0; var v = parseFloat(String(el.value).replace(/\s+/g, '').replace(',', '.')); return isFinite(v) ? v : 0; }
    function r2(x) { var s = x < 0 ? -1 : 1; return s * Math.round(parseFloat((Math.abs(x) * 100).toPrecision(15))) / 100; }   // like PHP round(x, 2)
    function money(v) { v = r2(v); return (v < 0 ? '-' : '') + '$' + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function rate(v) { return String(+(+v).toFixed(2)); }
    function set(k, v) { $$('[data-inv="' + k + '"]', f).forEach(function (el) { el.textContent = v; }); }
    function zero(k, on) { var el = $('[data-inv="' + k + '"]', f); if (el && el.parentElement) el.parentElement.classList.toggle('zero', on); }
    function calc() {
      var sub = 0;
      $$('.rep-item', f).forEach(function (it) {
        var q = $('[name$="[qty]"]', it), p = $('[name$="[price]"]', it); if (!q || !p) return;
        var a = r2(num(q) * num(p)); sub += a;
        var head = kid(it, 'rep-head'); if (!head) return;
        var s = $('.inv-line', head);
        if (!s) { s = document.createElement('span'); s.className = 'inv-line'; head.insertBefore(s, kid(head, 'rep-tools')); }
        s.textContent = money(a);
      });
      sub = r2(sub);
      var disc = r2(Math.min(Math.max(0, num(fld('discount'))), Math.max(0, sub)));
      var taxable = r2(sub - disc), tr = num(fld('tax_rate')), tax = r2(taxable * tr / 100), total = r2(taxable + tax);
      var paid = r2(Math.max(0, num(fld('paid')))), st = fld('status');
      if (st && st.value === 'paid') paid = Math.max(paid, total);
      set('sub', money(sub)); set('disc', '-' + money(disc)); set('tax', money(tax)); set('total', money(total)); set('paid', '-' + money(paid)); set('due', money(total - paid));
      zero('disc', disc === 0); zero('paid', paid === 0);
      var tl = $('[data-inv-l="tax"]', f), lab = fld('tax_label');
      if (tl) tl.textContent = ((lab && lab.value.trim()) || 'Vergi') + ' (' + rate(tr) + '%)';
    }
    f.addEventListener('input', calc);
    f.addEventListener('change', calc);
    f.addEventListener('click', function (e) { if (e.target.closest('.rep-add, .rep-del, .rep-up, .rep-down')) setTimeout(calc, 0); });
    calc();
    // a customer invoiced before: picking the name fills the other details (only empty fields, or ones filled this way)
    var name = fld('customer.name'), filled = {};
    if (name) name.addEventListener('input', function () {
      var c = CUST[name.value.trim()]; if (!c) return;
      var n = 0;
      [['company', 'customer.company'], ['address', 'customer.address'], ['email', 'customer.email'], ['phone', 'customer.phone'], ['site', 'site']].forEach(function (p) {
        var el = fld(p[1]), v = c[p[0]] || ''; if (!el || !v) return;
        if (el.value.trim() === '' || el.value === filled[p[1]]) { if (el.value !== v) n++; el.value = v; filled[p[1]] = v; }
      });
      if (n) toast('Müşteri bilgileri önceki faturadan dolduruldu.');
    });
  });

  /* ---------- sign-in pages: resend countdown, password strength ---------- */
  $$('[data-countdown]').forEach(function (b) {
    var n = +b.getAttribute('data-countdown') || 0, sp = b.querySelector('span'), label = sp ? sp.textContent : '';
    if (!n || !sp) return;
    b.disabled = true;
    var t = setInterval(function () {
      n--; sp.textContent = n > 0 ? label + ' (' + n + ' sn)' : label;
      if (n <= 0) { clearInterval(t); b.disabled = false; }
    }, 1000);
    sp.textContent = label + ' (' + n + ' sn)';
  });
  $$('[data-pw-meter]').forEach(function (inp) {
    var m = $('.pw-meter'), t = $('.pw-meter-t');
    if (!m) return;
    inp.addEventListener('input', function () {
      var v = inp.value, s = 0;
      if (v.length >= 10) s++;
      if (v.length >= 14) s++;
      if (/[a-zğüşıöç]/.test(v) && /[A-ZĞÜŞİÖÇ]/.test(v)) s++;
      if (/\d/.test(v) && /[^A-Za-z0-9ğüşıöçĞÜŞİÖÇ]/.test(v)) s++;
      if (v.length < 10) s = v ? 1 : 0;
      m.setAttribute('data-s', String(Math.min(4, s)));
      if (t) t.textContent = !v ? '' : v.length < 10 ? 'En az 10 karakter (' + v.length + '/10)' : ['', 'Zayıf', 'Orta', 'İyi', 'Güçlü'][Math.min(4, s)];
    });
  });

  /* ---------- small things ---------- */
  $$('.flash.ok').forEach(function (fl) { setTimeout(function () { fl.style.transition = 'opacity .4s'; fl.style.opacity = '0'; setTimeout(function () { fl.remove(); }, 450); }, 9000); });
  $$('.rep-item').forEach(syncTitle);
})();
