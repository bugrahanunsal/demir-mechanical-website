/* Demir Mechanical — admin panel behaviour (no libraries). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var CSRF = document.body.getAttribute('data-csrf') || '';
  var ICONS = {};
  try { ICONS = JSON.parse(($('#dm-icons') || {}).textContent || '{}'); } catch (e) {}
  var PHOTO = { photo: 1, project: 1, hero: 1, portrait: 1 };

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
    s = String(s || '').replace(/[ıİşŞğĞüÜöÖçÇ&]/g, function (c) { return map[c]; });
    try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) {}
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
    if (sub && sub.value === 'preview') return;               // preview opens a new tab; this page stays as it is
    if ($('.imgf.busy', f)) { e.preventDefault(); toast('Fotoğraf yüklemesinin bitmesini bekleyin.'); return; }
    leaving = true;
    if (sub && sub.hasAttribute('data-busy')) {
      setTimeout(function () { sub.disabled = true; var sp = sub.querySelector('span'); if (sp) sp.textContent = sub.getAttribute('data-busy'); }, 0);
    }
  });
  // Enter in a one-line field should not send the form
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches && e.target.matches('form.edit input[type=text]')) e.preventDefault();
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
    var rn = t.closest('[data-rename]');
    if (rn) {
      var file = rn.getAttribute('data-rename'), base = file.split('/').pop().replace(/\.\w+$/, '');
      var v = window.prompt('Yeni dosya adı (İngilizce ve açıklayıcı, örnek: copper-riser-replacement-north-york):', base);
      if (!v || slugify(v) === '' || slugify(v) === base) return;
      var rf = $('#renameForm'), det = rn.closest('details');
      rf.querySelector('[name=file]').value = file;
      rf.querySelector('[name=name]').value = v;
      rf.querySelector('[name=back]').value = det ? det.querySelector('input[name=path]').value : '';
      leaving = true; rf.submit();
    }
  });
  document.addEventListener('input', function (e) {
    var it = e.target.closest && e.target.closest('.rep-item');
    if (it) syncTitle(it);
    if (e.target.hasAttribute && e.target.hasAttribute('data-seo')) counter(e.target);
    if (e.target.hasAttribute && e.target.hasAttribute('data-serp')) serp(e.target);
  });

  /* ---------- icon pickers ---------- */
  function initIcons(root) {
    $$('.ficon select', root).forEach(function (s) { var p = s.parentElement.querySelector('.ic-prev'); if (p) p.innerHTML = iconSvg(s.value); });
  }
  initIcons(document);
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('.ficon select')) { var p = e.target.parentElement.querySelector('.ic-prev'); if (p) p.innerHTML = iconSvg(e.target.value); }
  });

  /* ---------- SEO counters + Google preview ---------- */
  function counter(inp) {
    var title = inp.getAttribute('data-seo') === 'title', max = title ? 60 : 160, min = title ? 25 : 70;
    var c = inp._cnt;
    if (!c) {
      c = document.createElement('span'); c.className = 'cnt';
      var fld = inp.closest('.fld'), lab = fld && fld.querySelector('.fl');
      if (lab) lab.appendChild(c); else inp.parentElement.appendChild(c);
      inp._cnt = c;
    }
    var auto = inp.value === '' && inp.placeholder;
    var n = Array.from(inp.value || inp.placeholder || '').length;
    c.textContent = n + ' / ' + max + (auto ? ' · otomatik' : '');
    c.className = 'cnt ' + (n > max ? 'bad' : n < min ? 'warn' : 'ok');
  }
  function serp(inp) {
    var d = inp.closest('details'); if (!d) return;
    var el = d.querySelector(inp.getAttribute('data-serp') === 't' ? '[data-serp-t]' : '[data-serp-d]');
    if (el) el.textContent = inp.value || inp.placeholder || '';
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

  /* ---------- small things ---------- */
  $$('.flash.ok').forEach(function (fl) { setTimeout(function () { fl.style.transition = 'opacity .4s'; fl.style.opacity = '0'; setTimeout(function () { fl.remove(); }, 450); }, 9000); });
  $$('.rep-item').forEach(syncTitle);
})();
