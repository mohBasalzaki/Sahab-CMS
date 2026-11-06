/* Sahab admin — vanilla JS, CSP-safe (no inline handlers) */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var T = function (s) { return s; };

  // ---- mobile nav
  var toggle = $('#nav-toggle'), scrim = $('#scrim');
  function nav(open) {
    document.body.classList.toggle('nav-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (toggle) toggle.addEventListener('click', function () { nav(!document.body.classList.contains('nav-open')); });
  if (scrim) scrim.addEventListener('click', function () { nav(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { nav(false); closeModal(); } });

  // ---- confirm on destructive forms
  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (m && !window.confirm(m)) e.preventDefault();
  });
  document.addEventListener('focusin', function (e) { if (e.target.hasAttribute && e.target.hasAttribute('data-select')) e.target.select(); });

  // ---- slug auto-fill (Arabic friendly)
  var src = $('[data-slug-source]'), tgt = $('[data-slug-target]');
  if (src && tgt) {
    var touched = !!tgt.value;
    tgt.addEventListener('input', function () { touched = true; });
    src.addEventListener('input', function () {
      if (!touched) tgt.value = src.value.trim().toLowerCase().replace(/[ً-ٰٟـ]/g, '').replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '');
    });
  }

  // ---- repeater (type / form builders)
  var rows = $('#field-rows'), tpl = $('#field-tpl'), add = $('#add-field');
  function refreshRow(row) {
    var sel = $('[data-ftype]', row), val = sel ? sel.value : ''; var tag = $('[data-ftag]', row); if (tag) tag.textContent = val;
    $$('[data-show]', row).forEach(function (el) { el.hidden = el.getAttribute('data-show').split(' ').indexOf(val) < 0; });
  }
  function renumber() {
    $$('[data-row]', rows).forEach(function (row, i) {
      $$('[name^="fields["]', row).forEach(function (el) { el.name = el.name.replace(/^fields\[[^\]]*\]/, 'fields[' + i + ']'); });
    });
  }
  if (rows && tpl) {
    var counter = rows.children.length;
    $$('[data-row]', rows).forEach(refreshRow);
    var picker = $('#type-picker');
    function addRow(type, silent) {
      var html = tpl.innerHTML.replace(/__i__/g, counter++);
      var d = document.createElement('div'); d.innerHTML = html.trim();
      var row = d.firstElementChild; rows.appendChild(row);
      var sel = $('[data-ftype]', row); if (type && sel) sel.value = type;
      refreshRow(row);
      if (!silent) { var first = $('input', row); if (first) first.focus(); row.scrollIntoView({ block: 'center' }); }
      return row;
    }
    if (add) add.addEventListener('click', function () { if (picker) picker.hidden = !picker.hidden; else addRow(); });
    if (picker) picker.addEventListener('click', function (e) { var b = e.target.closest('[data-pick]'); if (b) { picker.hidden = true; addRow(b.getAttribute('data-pick')); } });
    // ---- presets (Content-Type Builder): fill name/plural/slug/kind/designer and the field rows
    var presetData = $('#presets');
    if (presetData && picker !== undefined) {
      var presets = JSON.parse(presetData.textContent);
      var tf = $('#type-form');
      Array.prototype.forEach.call(document.querySelectorAll('[data-preset]'), function (btn) {
        btn.addEventListener('click', function () {
          var key = btn.getAttribute('data-preset'), p = presets.filter(function (x) { return x.key === key; })[0];
          Array.prototype.forEach.call(document.querySelectorAll('[data-preset]'), function (b) { b.classList.toggle('on', b === btn); });
          rows.innerHTML = ''; counter = 0;
          var set = function (n, v) { var i = tf.querySelector('[name="' + n + '"]'); if (i) i.value = v; };
          if (!p) { set('name', ''); set('plural', ''); set('slug', ''); tf.querySelector('[name="designer"]').checked = false; return; }
          set('name', p.name); set('plural', p.plural); set('slug', p.slug);
          var kr = tf.querySelector('[name="kind"][value="' + p.kind + '"]'); if (kr) kr.checked = true;
          var dz = tf.querySelector('[name="designer"]'); if (dz) dz.checked = !!p.designer;
          p.fields.forEach(function (f) {
            var row = addRow(f.type, true), q = function (s) { return row.querySelector(s); };
            q('[name$="[name]"]').value = f.name; q('[name$="[label]"]').value = f.label;
            if (f.required) q('[name$="[required]"]').checked = true;
            if (f.multiple && q('[name$="[multiple]"]')) q('[name$="[multiple]"]').checked = true;
            if (f.options && q('[name$="[options]"]')) q('[name$="[options]"]').value = f.options.join('\n');
            var fl = q('[data-flabel]'); if (fl) fl.textContent = f.label;
          });
          tf.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
    }    rows.addEventListener('input', function (e) { if (e.target.hasAttribute('data-label-input')) { var l = $('[data-flabel]', e.target.closest('[data-row]')); if (l) l.textContent = e.target.value; } });
    rows.addEventListener('change', function (e) { if (e.target.hasAttribute('data-ftype')) refreshRow(e.target.closest('[data-row]')); });
    rows.addEventListener('click', function (e) {
      var row = e.target.closest('[data-row]'); if (!row) return;
      if (e.target.hasAttribute('data-remove')) { if (window.confirm(T('حذف هذا الحقل؟ ستفقد بياناته.'))) { row.remove(); renumber(); } }
      var mv = e.target.getAttribute('data-move');
      if (mv === '-1' && row.previousElementSibling) { rows.insertBefore(row, row.previousElementSibling); renumber(); }
      if (mv === '1' && row.nextElementSibling) { rows.insertBefore(row.nextElementSibling, row); renumber(); }
    });
    var f = rows.closest('form');
    if (f) f.addEventListener('submit', renumber);
  }

  // ---- rich text editor (contenteditable, progressive enhancement)
  $$('textarea[data-rte]').forEach(function (ta) {
    var wrap = document.createElement('div'); wrap.className = 'rte-wrap';
    var bar = document.createElement('div'); bar.className = 'rte-bar';
    var area = document.createElement('div'); area.className = 'rte-area'; area.contentEditable = 'true'; area.innerHTML = ta.value;
    area.setAttribute('dir', 'auto');
    var cmds = [['bold', 'bold', 'عريض'], ['italic', 'italic', 'مائل'], ['underline', 'underline', 'تسطير'], ['h2', 'formatBlock:h2', 'عنوان 2'], ['h3', 'formatBlock:h3', 'عنوان 3'], ['paragraph', 'formatBlock:p', 'فقرة'], ['listbullet', 'insertUnorderedList', 'قائمة نقطية'], ['listnumber', 'insertOrderedList', 'قائمة مرقمة'], ['quote', 'formatBlock:blockquote', 'اقتباس'], ['link', 'link', 'رابط'], ['image', 'image', 'صورة'], ['clearformat', 'removeFormat', 'مسح التنسيق']];
    var icons = document.getElementById('rte-icons');
    cmds.forEach(function (c0) {
      var c = [c0[0], c0[1]];
      var b = document.createElement('button'); b.type = 'button'; b.title = c0[2]; b.setAttribute('aria-label', c0[2]);
      var src = icons && icons.querySelector('[data-ic="' + c0[0] + '"]'); if (src) b.innerHTML = src.innerHTML; else b.textContent = c0[2];      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        area.focus();
        if (c[1] === 'link') { var u = window.prompt('الرابط (https://...)'); if (u && /^(https?:|mailto:|tel:|\/|#)/i.test(u)) document.execCommand('createLink', false, u); }
        else if (c[1] === 'image') { openPicker('image', false, function (items) { items.forEach(function (m) { document.execCommand('insertHTML', false, '<img src="' + m.url.replace(/"/g, '&quot;') + '" alt="' + (m.alt || '').replace(/"/g, '&quot;') + '">'); sync(); }); }); }
        else if (c[1].indexOf('formatBlock:') === 0) document.execCommand('formatBlock', false, c[1].split(':')[1]);
        else document.execCommand(c[1], false, null);
        sync();
      });
      bar.appendChild(b);
    });
    function sync() { ta.value = area.innerHTML; }
    area.addEventListener('input', sync);
    area.addEventListener('paste', function (e) { e.preventDefault(); var t = (e.clipboardData || window.clipboardData).getData('text/plain'); document.execCommand('insertText', false, t); });
    wrap.appendChild(bar); wrap.appendChild(area);
    ta.parentNode.insertBefore(wrap, ta); ta.hidden = true;
    ta.form.addEventListener('submit', sync);
  });

  // ---- media picker modal
  var modal = null;
  function closeModal() { if (modal) { modal.remove(); modal = null; } }
  function openPicker(kind, multiple, done) {
    closeModal();
    var base = ($('[data-media-pick]') || {}).dataset ? ($('[data-media-pick]').dataset.url) : null;
    base = base || (location.pathname.replace(/\/admin\/.*/, '/admin') + '/media');
    modal = document.createElement('div'); modal.className = 'modal';
    modal.innerHTML = '<div class="modal-box"><div class="row-wrap"><strong>اختر من الوسائط</strong><button type="button" class="btn sm" data-close>إغلاق</button></div>' +
      '<div class="row-wrap" style="margin:.6rem 0"><input type="file" id="pk-file" accept="image/*,application/pdf,video/mp4,audio/mpeg,text/plain" multiple style="flex:1"><span id="pk-status" class="muted small"></span></div>' +
      '<div class="media-grid" id="pk-grid"></div><div class="row-wrap" style="margin-top:.8rem"><button type="button" class="btn primary" data-ok>تم</button></div></div>';
    document.body.appendChild(modal);
    var grid = $('#pk-grid', modal), picked = {};
    function card(m) {
      var f = document.createElement('figure'); f.className = 'media-item'; f.dataset.id = m.id;
      f.innerHTML = (m.is_image ? '<img alt="" loading="lazy">' : '<span class="file-ico">📄</span>') + '<figcaption><span class="name"></span></figcaption>';
      if (m.is_image) $('img', f).src = m.thumb; $('.name', f).textContent = m.original;
      f.addEventListener('click', function () {
        if (!multiple) { picked = {}; $$('.sel', grid).forEach(function (x) { x.classList.remove('sel'); }); }
        if (picked[m.id]) { delete picked[m.id]; f.classList.remove('sel'); } else { picked[m.id] = m; f.classList.add('sel'); }
      });
      return f;
    }
    function load() {
      fetch(base + '?json=1' + (kind === 'image' ? '&kind=image' : ''), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); }).then(function (d) { grid.innerHTML = ''; d.items.forEach(function (m) { grid.appendChild(card(m)); }); });
    }
    load();
    $('#pk-file', modal).addEventListener('change', function () {
      var fd = new FormData(); Array.prototype.forEach.call(this.files, function (f) { fd.append('files[]', f); });
      fd.append('_csrf', ($('input[name=_csrf]') || {}).value || '');
      $('#pk-status', modal).textContent = 'جاري الرفع...';
      fetch(base + '/upload', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': ($('input[name=_csrf]') || {}).value || '' } })
        .then(function (r) { return r.json(); }).then(function (d) { $('#pk-status', modal).textContent = (d.errors || []).join(' | '); load(); });
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.hasAttribute('data-close')) closeModal();
      if (e.target.hasAttribute('data-ok')) { var items = Object.keys(picked).map(function (k) { return picked[k]; }); closeModal(); if (items.length) done(items); }
    });
  }

  $$('[data-media-field]').forEach(function (box) {
    var input = $('[data-media-input]', box), prev = $('[data-media-preview]', box), multiple = box.dataset.multiple === '1';
    function ids() { return $$('figure', prev).map(function (f) { return f.dataset.id; }); }
    function save() { input.value = ids().join(','); }
    prev.addEventListener('click', function (e) { if (e.target.classList.contains('rm')) { e.target.closest('figure').remove(); save(); } });
    $('[data-media-pick]', box).addEventListener('click', function () {
      openPicker(null, multiple, function (items) {
        if (!multiple) prev.innerHTML = '';
        items.forEach(function (m) {
          if (ids().indexOf(String(m.id)) >= 0) return;
          var f = document.createElement('figure'); f.dataset.id = m.id;
          f.innerHTML = (m.is_image ? '<img alt="">' : '<span class="file-ico">📄</span>') + '<figcaption></figcaption><button type="button" class="rm" aria-label="إزالة">×</button>';
          if (m.is_image) $('img', f).src = m.thumb; $('figcaption', f).textContent = m.original;
          prev.appendChild(f);
        });
        save();
      });
    });
  });
})();

(function () { var k = document.getElementById('d-kind'); if (!k) return; function s() { Array.prototype.forEach.call(document.querySelectorAll('[data-kind]'), function (x) { x.hidden = x.getAttribute('data-kind') !== k.value; }); } k.addEventListener('change', s); s(); })();

(function () { Array.prototype.forEach.call(document.querySelectorAll('[data-confirm-slug]'), function (f) { var i = f.querySelector('input[name=confirm]'), b = f.querySelector('button[type=submit]'); function s() { b.disabled = i.value.trim() !== f.getAttribute('data-confirm-slug'); } i.addEventListener('input', s); s(); }); })();
