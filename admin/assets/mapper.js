/* Site mapper: click an element of the imported page and bind it to CMS data. Vanilla JS, CSP-safe. */
(function () {
  'use strict';
  var B = JSON.parse(document.getElementById('boot').textContent);
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var S = { assign: B.assign || { kind: 'none' }, bindings: B.bindings || [], title: B.title || '', description: B.description || '', info: null, dirty: false };
  var frame = $('#frame'), ready = false;
  S.fields = (B.block && B.block.fields) ? JSON.parse(JSON.stringify(B.block.fields)) : [];   // block mode: the block's own inputs
  frame.src = B.urls.frame;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') e.className = attrs[k]; else if (k === 'text') e.textContent = attrs[k]; else if (k === 'html') e.innerHTML = attrs[k];
      else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]); else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  var MIRROR = ['back', 'next', 'undo', 'redo', 'logout', 'richtext', 'layout'];
  function ic(name, size) { var s = B.icons[name] || ''; return el('span', { class: 'ico-i', html: '<svg class="ic' + (MIRROR.indexOf(name) >= 0 ? ' mirror' : '') + '" width="' + (size || 14) + '" height="' + (size || 14) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + s + '</svg>' }); }
  function typeOf(slug) { return B.types.filter(function (t) { return t.slug === slug; })[0]; }
  function dirty() { S.dirty = true; $('#m-saved').textContent = '● غير محفوظ'; }
  function post(m) { if (ready) frame.contentWindow.postMessage(m, location.origin); }

  // ------------------------------------------------------------ context: which data is available for an element
  function ctxFor(info) {
    var chain = [info.sp].concat(info.chain);
    for (var i = 0; i < chain.length; i++) {
      var b = S.bindings.filter(function (x) { return x.kind === 'repeat' && x.sp === chain[i]; })[0];
      if (b) return { v: 'it', type: b.src === 'items' ? S.assign.type : b.rtype };
    }
    if (S.assign.kind === 'single') return { v: 'entry', type: S.assign.type };
    return null;
  }
  var SITE = [['site.title', 'عنوان الموقع'], ['site.description', 'وصف الموقع'], ['site.url', 'رابط الموقع'], ['year', 'السنة الحالية']];
  /** modes: text | html | url | image */
  function sources(ctx, mode) {
    var out = [];
    if (ctx && typeOf(ctx.type)) {
      typeOf(ctx.type).fields.forEach(function (f) {
        var p = ctx.v + '.' + f.name, t = f.type;
        if (mode === 'text' && ['text', 'textarea', 'number', 'date', 'datetime', 'select', 'email', 'url', 'uid'].indexOf(t) >= 0) out.push([p, f.label + ' (' + f.name + ')']);
        if (mode === 'text' && t === 'relation') out.push([p + '.title', f.label + ' / العنوان']);
        if (mode === 'html' && ['richtext', 'textarea'].indexOf(t) >= 0) out.push([p, f.label + ' (' + f.name + ')']);
        if (mode === 'url') {
          if (t === 'url') out.push([p, f.label + ' (' + f.name + ')']);
          if (t === 'media') out.push([p + '.url', f.label + ' / الرابط']);
          if (t === 'relation') out.push([p + '.url', f.label + ' / رابط المدخل']);
        }
        if (mode === 'image' && t === 'media') out.push([p + '.url', f.label + ' (' + f.name + ')'], [p + '.thumb', f.label + ' (مصغّرة)']);
      });
      if (mode === 'url' && ctx.v) out.unshift([ctx.v + '.url', 'رابط المدخل نفسه']);
    }
    if (mode === 'url') Object.keys(B.pages || {}).forEach(function (f) { out.push(['page:' + f, 'صفحة الموقع: ' + f + ' (' + B.pages[f] + ')']); });
    if (mode === 'text' || mode === 'url') SITE.forEach(function (s) { if (mode === 'text' || s[0] === 'site.url') out.push(s); });
    if (B.block) S.fields.forEach(function (f) {   // block mode: the block's own fields (◆)
      var p = 'block.' + f.name, l = '◆ ' + f.label;
      if (mode === 'text' && ['text', 'textarea', 'number', 'select', 'color', 'url'].indexOf(f.type) >= 0) out.unshift([p, l]);
      if (mode === 'html' && ['richtext', 'textarea'].indexOf(f.type) >= 0) out.unshift([p, l]);
      if (mode === 'url' && f.type === 'url') out.unshift([p, l]);
      if ((mode === 'url' || mode === 'image') && f.type === 'image') out.unshift([p + '.url', l]);
    });
    return out;
  }
  function selectOf(opts, current, empty) {
    var s = el('select', {}, (empty ? [el('option', { value: '', text: empty })] : []).concat(opts.map(function (o) { return el('option', { value: o[0], text: o[1] }); })));
    if (current) s.value = current; return s;
  }

  // ------------------------------------------------------------ bindings model
  function add(b) {
    S.bindings = S.bindings.filter(function (x) { return !(x.sp === b.sp && x.kind === b.kind && (x.attr || '') === (b.attr || '')); });
    S.bindings.push(b); dirty(); refresh();
  }
  function removeAt(i) { S.bindings.splice(i, 1); dirty(); refresh(); }
  function refresh() { marks(); list(); layers(); inspect(); }
  function marks() {
    var m = {}; S.bindings.forEach(function (b) { m[b.sp] = b.kind; });
    post({ t: 'marks', marks: m });
  }
  function desc(b) {
    return b.kind === 'repeat' ? (b.src === 'items' ? 'items' : b.rtype + ' × ' + (b.limit || 6)) : b.kind === 'menu' ? 'menu(' + b.menu + ')' : b.kind === 'form' ? 'form: ' + b.form : b.kind === 'remove' ? '—' : (b.attr ? b.attr + ' = ' : '') + (b.src || '') + (b.fmt ? ' | ' + b.fmt : '');
  }
  function list() {
    var box = $('#m-list'); box.innerHTML = '';
    if (!S.bindings.length) box.appendChild(el('p', { class: 'muted small', text: 'لا توجد روابط بعد.' }));
    S.bindings.forEach(function (b, i) {
      var chip = el('div', { class: 'm-chip' + (S.info && S.info.sp === b.sp ? ' on' : '') }, [
        el('span', { class: 'k', text: '#' + b.sp + ' ' + b.kind }), el('span', { class: 'src', text: desc(b) }),
        el('button', { type: 'button', title: 'حذف', onclick: function (e) { e.stopPropagation(); removeAt(i); } }, [ic('close', 14)])
      ]);
      chip.addEventListener('click', function () { selectSp(b.sp); });
      box.appendChild(chip);
    });
  }

  // ------------------------------------------------------------ inspector
  function section(title, icon, body, hint) {
    var s = el('div', { class: 'm-sec' }, [el('h4', {}, [ic(icon, 15), title])]);
    if (hint) s.appendChild(el('p', { class: 'm-hint', text: hint }));
    body.forEach(function (b) { if (b) s.appendChild(b); });
    return s;
  }
  function applyBtn(fn, label) { return el('button', { type: 'button', class: 'btn sm primary', text: label || 'ربط', onclick: fn }); }
  // ------------------------------------------------------------ block mode: the block's own fields (inputs shown when the block is added in the designer)
  var FT = [['text', 'نص قصير'], ['textarea', 'نص طويل'], ['richtext', 'نص منسّق'], ['url', 'رابط'], ['image', 'صورة'], ['number', 'رقم'], ['color', 'لون'], ['boolean', 'نعم / لا']];
  function fieldKey(label) { var s = String(label || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''); if (!/^[a-z]/.test(s)) s = 'field'; s = s.slice(0, 24); var base = s, n = 2; while (S.fields.some(function (f) { return f.name === s; })) s = base + '_' + (n++); return s; }
  function fieldsSection() {
    var box = el('div', { class: 'm-sec m-fields' });
    box.appendChild(el('h4', {}, [ic('layers', 14), ' حقول الكتلة']));
    box.appendChild(el('p', { class: 'm-hint', text: 'كل حقل يظهر كخانة إدخال عند إضافة الكتلة في المصمم. اربطه بعنصر من القوائم أدناه (يظهر بعلامة ◆).' }));
    S.fields.forEach(function (f, idx) {
      var lb = el('input', { type: 'text', dir: 'auto', value: f.label, 'aria-label': 'اسم الحقل' }); lb.addEventListener('input', function () { f.label = lb.value; dirty(); });
      var ty = el('select', {}, FT.map(function (t) { return el('option', { value: t[0], text: t[1] }); })); ty.value = f.type; ty.addEventListener('change', function () { f.type = ty.value; dirty(); refresh(); });
      var df = el('input', { type: 'text', dir: 'auto', placeholder: 'القيمة الافتراضية', value: f.default || '' }); df.addEventListener('input', function () { f.default = df.value; dirty(); });
      var rm = el('button', { type: 'button', class: 'btn sm danger', 'aria-label': 'حذف الحقل', onclick: function () { S.fields.splice(idx, 1); S.bindings = S.bindings.filter(function (b) { return String(b.src || '').indexOf('block.' + f.name) !== 0; }); dirty(); refresh(); } }, [ic('trash', 14)]);
      box.appendChild(el('div', { class: 'm-field' }, [el('div', { class: 'row-inline' }, [lb, ty]), el('div', { class: 'row-inline' }, [df, rm]), el('code', { class: 'tok', dir: 'ltr', text: 'block.' + f.name })]));
    });
    var nl = el('input', { type: 'text', dir: 'auto', placeholder: 'حقل جديد (مثال: عنوان العرض)', 'aria-label': 'حقل جديد' });
    var nt = el('select', {}, FT.map(function (t) { return el('option', { value: t[0], text: t[1] }); }));
    var go = function () { var label = nl.value.trim(); if (!label) return; S.fields.push({ name: fieldKey(label), label: label, type: nt.value, default: '' }); dirty(); refresh(); };
    nl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    box.appendChild(el('div', { class: 'row-inline' }, [nl, nt]));
    box.appendChild(applyBtn(go, 'إضافة حقل'));
    return box;
  }
  function inspect() {
    var p = $('#m-inspect'); p.innerHTML = '';
    if (B.block) p.appendChild(fieldsSection());
    var i = S.info;
    if (!i) { p.appendChild(el('p', { class: 'muted', text: 'انقر أي عنصر في المعاينة لربطه.' })); return; }
    var ctx = ctxFor(i);
    p.appendChild(el('div', { class: 'm-el', text: '<' + i.tag + '> #' + i.sp + (i.cls ? ' .' + i.cls.split(' ')[0] : '') + (i.text ? ' — "' + i.text + '"' : '') }));
    p.appendChild(el('p', { class: 'm-hint', text: ctx ? 'البيانات المتاحة: ' + (typeOf(ctx.type) ? typeOf(ctx.type).plural : ctx.type) + (ctx.v === 'it' ? ' (داخل التكرار)' : ' (المدخل الحالي)') : 'لا توجد بيانات مدخل هنا: اربط عنصراً متكرراً أولاً، أو عيّن الصفحة كـ«صفحة مدخل».' }));

    // text
    var ts = selectOf(sources(ctx, 'text'), '', '— اختر الحقل —');
    var tf = el('select', {}, [el('option', { value: '', text: 'التنسيق: كما هو' }), el('option', { value: 'excerpt', text: 'مقتطف قصير (140 حرفاً)' }), el('option', { value: 'excerpt_long', text: 'مقتطف طويل (320 حرفاً)' }), el('option', { value: 'date', text: 'تاريخ مُنسّق' }), el('option', { value: 'nl2br', text: 'نص مع فواصل الأسطر' })]);
    p.appendChild(section('نص', 'text', [ts, tf, applyBtn(function () { if (ts.value) add({ sp: i.sp, kind: 'text', src: ts.value, fmt: tf.value }); })], 'يستبدل نص العنصر مع الإبقاء على وسومه الداخلية.'));
    // html
    var hs = sources(ctx, 'html');
    if (hs.length) { var hsel = selectOf(hs, '', '— اختر الحقل —'); p.appendChild(section('محتوى منسّق (HTML)', 'code', [hsel, applyBtn(function () { if (hsel.value) add({ sp: i.sp, kind: 'html', src: hsel.value, fmt: ((ctx && typeOf(ctx.type) ? typeOf(ctx.type).fields : []).filter(function (f) { return ctx.v + '.' + f.name === hsel.value; })[0] || {}).type === 'textarea' ? 'nl2br' : '' }); })])); }
    // attribute
    var common = { img: ['src', 'alt'], a: ['href', 'title'], input: ['value', 'placeholder'], meta: ['content'], source: ['src'], video: ['poster', 'src'], iframe: ['src'] }[i.tag] || ['title', 'href', 'src'];
    var attrs = Object.keys(i.attrs).filter(function (k) { return k !== 'type' && k !== 'name'; });
    common.forEach(function (c) { if (attrs.indexOf(c) < 0) attrs.push(c); });
    var as = el('select', {}, attrs.map(function (a) { return el('option', { value: a, text: a + (i.attrs[a] ? ' = ' + i.attrs[a].slice(0, 24) : '') }); }));
    var asrc = el('select', {});
    function fillSrc() {
      var url = ['href', 'src', 'poster', 'data-src', 'action', 'srcset'].indexOf(as.value) >= 0;
      var opts = url ? sources(ctx, 'url').concat(sources(ctx, 'image')) : sources(ctx, 'text');
      asrc.innerHTML = ''; asrc.appendChild(el('option', { value: '', text: '— اختر المصدر —' }));
      opts.forEach(function (o) { asrc.appendChild(el('option', { value: o[0], text: o[1] })); });
    }
    as.addEventListener('change', fillSrc); fillSrc();
    p.appendChild(section('خاصية (src / href / alt …)', 'link', [as, asrc, applyBtn(function () { if (asrc.value) add({ sp: i.sp, kind: 'attr', attr: as.value, src: asrc.value }); })]));
    // background image
    var bs = selectOf(sources(ctx, 'image'), '', '— حقل صورة —');
    if (sources(ctx, 'image').length) p.appendChild(section('صورة خلفية', 'image', [bs, applyBtn(function () { if (bs.value) add({ sp: i.sp, kind: 'bgimage', src: bs.value }); })]));
    // repeat
    var rk = el('select', {}, [].concat(S.assign.kind === 'archive' ? [el('option', { value: 'items', text: 'عناصر الصفحة الحالية (الأرشيف)' })] : [], [el('option', { value: 'type', text: 'قائمة من نوع محتوى' })]));
    var rt = selectOf(B.types.filter(function (t) { return t.kind === 'collection'; }).map(function (t) { return [t.slug, t.plural]; }), S.assign.type || '');
    var rl = el('input', { type: 'text', inputmode: 'numeric', dir: 'ltr', value: '6', 'aria-label': 'limit' });
    var ro = el('select', {});
    function fillOrd() { ro.innerHTML = ''; ro.appendChild(el('option', { value: '', text: 'الأحدث أولاً' })); var t = typeOf(rt.value); if (t) t.fields.forEach(function (f) { ro.appendChild(el('option', { value: f.name + ':asc', text: f.label + ' ↑' })); ro.appendChild(el('option', { value: f.name + ':desc', text: f.label + ' ↓' })); }); }
    rt.addEventListener('change', fillOrd); fillOrd();
    var dd = el('input', { type: 'checkbox', checked: 'checked' });
    p.appendChild(section('تكرار لكل مدخل', 'repeat', [rk, rt, el('div', { class: 'row-inline' }, [rl, ro]), el('label', { class: 'check' }, [dd, ' حذف العناصر المشابهة التالية']),
      applyBtn(function () { add(rk.value === 'items' ? { sp: i.sp, kind: 'repeat', src: 'items', dedupe: dd.checked } : { sp: i.sp, kind: 'repeat', src: 'type', rtype: rt.value, limit: parseInt(rl.value, 10) || 6, order: ro.value, dedupe: dd.checked }); })],
      'اختر العنصر الذي يمثّل «بطاقة» واحدة (مثل <article> أو <li>).'));
    // menu
    var ms = el('select', {}, [el('option', { value: 'main', text: 'القائمة الرئيسية (main)' }), el('option', { value: 'footer', text: 'قائمة التذييل (footer)' })]);
    p.appendChild(section('قائمة روابط', 'menuitems', [ms, applyBtn(function () { add({ sp: i.sp, kind: 'menu', menu: ms.value }); })], 'اختر <ul> أو <nav>؛ أول عنصر فيها يصبح قالب كل رابط.'));
    // form
    if (i.tag === 'form' && B.forms.length) {
      var fs = selectOf(B.forms.map(function (f) { return [f.slug, f.name]; }), '');
      var fh = el('p', { class: 'm-hint', text: '' });
      fs.addEventListener('change', function () { var f = B.forms.filter(function (x) { return x.slug === fs.value; })[0]; fh.textContent = f ? 'أسماء الحقول المطلوبة (name): ' + f.fields.join(', ') : ''; }); fs.dispatchEvent(new Event('change'));
      p.appendChild(section('نموذج تواصل', 'form', [fs, fh, applyBtn(function () { add({ sp: i.sp, kind: 'form', form: fs.value }); })]));
    }
    // save as designer block
    var bn = el('input', { type: 'text', placeholder: 'اسم الكتلة (بطاقة مقال، الرأس...)' });
    p.appendChild(section('حفظ العنصر ككتلة', 'layers', [bn, applyBtn(function () {
      if (!bn.value.trim()) return;
      $('#m-saved').textContent = 'جارٍ حفظ الكتلة...';
      save().then(function () {
        return fetch(B.urls.block, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf }, body: JSON.stringify({ sp: i.sp, name: bn.value.trim() }) });
      }).then(function (r) { return r.json(); }).then(function (r) { $('#m-saved').textContent = r.ok ? '✔ حُفظت الكتلة وأُضيفت لمكتبة الكتل' : '✘ ' + (r.error || 'فشل'); bn.value = ''; });
    }, 'حفظ كتلة')], 'يحفظ العنصر بعد ربط قيمه، لتضيفه في المصمم داخل أي صفحة (يحتفظ بتصميم الموقع).'));
    // remove    p.appendChild(section('حذف العنصر', 'trash', [applyBtn(function () { add({ sp: i.sp, kind: 'remove' }); }, 'حذف من الصفحة')]));
  }

  // ------------------------------------------------------------ top bar: assignment + title/description
  function assignUI() {
    var k = $('#a-kind'), t = $('#a-type'), s = $('#a-slug');
    k.value = S.assign.kind;
    function types() { t.innerHTML = ''; B.types.filter(function (x) { return S.assign.kind === 'archive' ? x.kind === 'collection' : true; }).forEach(function (x) { t.appendChild(el('option', { value: x.slug, text: x.plural })); }); t.value = S.assign.type || t.value; if (!S.assign.type) S.assign.type = t.value; }
    function show() { t.hidden = ['archive', 'single'].indexOf(S.assign.kind) < 0; s.hidden = S.assign.kind !== 'static'; if (!t.hidden) types(); s.value = S.assign.slug || ''; }
    k.addEventListener('change', function () { S.assign = { kind: k.value }; show(); dirty(); fillMeta(); inspect(); });
    t.addEventListener('change', function () { S.assign.type = t.value; dirty(); fillMeta(); inspect(); });
    s.addEventListener('input', function () { S.assign.slug = s.value.trim(); dirty(); });
    show();
  }
  function fillMeta() {
    var ctx = S.assign.kind === 'single' ? { v: 'entry', type: S.assign.type } : null;
    [['#m-title', 'title'], ['#m-desc', 'description']].forEach(function (x) {
      var sel = $(x[0]); sel.innerHTML = ''; sel.appendChild(el('option', { value: '', text: '— كما في الملف —' }));
      sources(ctx, 'text').forEach(function (o) { sel.appendChild(el('option', { value: o[0], text: o[1] })); });
      sel.value = S[x[1]] || ''; sel.onchange = function () { S[x[1]] = sel.value; dirty(); };
    });
  }

  // ------------------------------------------------------------ layers (element tree of the page, works even if the preview is blank)
  var N = {}, open = {};
  (function index(list, parents) {
    list.forEach(function (n) { N[n.sp] = { node: n, chain: parents.slice() }; index(n.kids || [], [n.sp].concat(parents)); });
  })(B.tree || [], []);
  Object.keys(N).forEach(function (k) { if (N[k].chain.length < 2) open[k] = true; });
  function infoOf(sp) {
    var x = N[sp]; if (!x) return null; var n = x.node;
    return { t: 'select', sp: n.sp, tag: n.tag, text: n.text, attrs: n.attrs || {}, chain: x.chain, kids: (n.kids || []).length, cls: n.cls };
  }
  function selectSp(sp, scrollFrame) {
    var i = infoOf(sp); if (!i) return;
    S.info = i;
    i.chain.forEach(function (a) { open[a] = true; });
    post({ t: 'sel', sp: sp, scroll: scrollFrame !== false });
    layers(); list(); inspect();
    $('#ed-right').classList.add('open');
    var row = $('#p-layers [data-sp="' + sp + '"]'); if (row) row.scrollIntoView({ block: 'nearest' });
  }
  function boundKinds(sp) { return S.bindings.filter(function (b) { return b.sp === sp; }).map(function (b) { return b.kind; }); }
  function layers() {
    var box = $('#p-layers'); if (!box) return; var keep = box.scrollTop; box.innerHTML = '';
    if (!(B.tree || []).length) { box.appendChild(el('p', { class: 'muted small', text: 'لا توجد عناصر في هذه الصفحة.' })); return; }
    (function walk(list, depth) {
      list.forEach(function (n) {
        var has = (n.kids || []).length > 0, isOpen = !!open[n.sp], kinds = boundKinds(n.sp);
        var tog = has ? el('button', { type: 'button', class: 'lay-tog', 'aria-label': 'toggle', onclick: function (e) { e.stopPropagation(); open[n.sp] = !isOpen; layers(); } }, [ic(isOpen ? 'down' : 'next', 13)]) : el('span', { class: 'lay-tog' });
        var row = el('div', { class: 'lay-row' + (S.info && S.info.sp === n.sp ? ' on' : ''), 'data-sp': n.sp, role: 'treeitem' }, [
          tog, el('span', { class: 'lay-tag', dir: 'ltr', text: n.tag }),
          el('span', { class: 'lay-name' }, [n.cls ? el('bdi', { dir: 'ltr', text: '.' + n.cls.split(' ')[0] }) : null, n.text ? el('bdi', { dir: 'auto', text: ' ' + n.text }) : null]),
          kinds.length ? el('span', { class: 'lay-bound', text: kinds[0] }) : null
        ]);
        row.style.paddingInlineStart = (4 + depth * 12) + 'px';
        row.addEventListener('click', function () { selectSp(n.sp); });
        row.addEventListener('contextmenu', function (e) { e.preventDefault(); showMenu(n.sp, e.clientX, e.clientY); });
        box.appendChild(row);
        if (has && isOpen) walk(n.kids, depth + 1);
      });
    })(B.tree, 0);
    box.scrollTop = keep;
  }

  // ------------------------------------------------------------ right-click menu (preview + layers): quick bindings
  function hideMenu() { var m = $('#ctx-menu'); if (m) m.remove(); }
  function srcItems(list, fn) { return list.length ? list.map(function (o) { return { label: o[1], fn: function () { fn(o[0]); } }; }) : [{ label: 'لا توجد حقول متاحة هنا', off: true }]; }
  function ctxItems(i) {
    var ctx = ctxFor(i), bound = S.bindings.some(function (b) { return b.sp === i.sp; });
    var typeOfSrc = function (src) { var t = ctx && typeOf(ctx.type), name = (src.split('.')[1] || ''); var f = t && t.fields.filter(function (x) { return x.name === name; })[0]; return f ? f.type : ''; };
    var textFmt = function (src) { var t = typeOfSrc(src); return t === 'date' || t === 'datetime' ? 'date' : (t === 'textarea' ? 'excerpt' : ''); };
    var attrs = { img: [['src', 'url'], ['src', 'image'], ['alt', 'text']], a: [['href', 'url'], ['title', 'text']], input: [['value', 'text'], ['placeholder', 'text']] }[i.tag] || [['title', 'text'], ['href', 'url']];
    var attrItems = [];
    attrs.forEach(function (ad) {
      var list = ad[1] === 'image' ? sources(ctx, 'image') : sources(ctx, ad[1] === 'url' ? 'url' : 'text');
      list.forEach(function (o) { attrItems.push({ label: ad[0] + '  ←  ' + o[1], fn: function () { add({ sp: i.sp, kind: 'attr', attr: ad[0], src: o[0] }); } }); });
    });
    var items = [
      { label: 'ربط النص', ico: 'text', sub: function () { return srcItems(sources(ctx, 'text'), function (s) { add({ sp: i.sp, kind: 'text', src: s, fmt: textFmt(s) }); }); } },
      { label: 'ربط محتوى HTML', ico: 'code', sub: function () { return srcItems(sources(ctx, 'html'), function (s) { add({ sp: i.sp, kind: 'html', src: s, fmt: typeOfSrc(s) === 'textarea' ? 'nl2br' : '' }); }); } },
      { label: 'ربط خاصية (src / href / alt…)', ico: 'link', sub: function () { return attrItems.length ? attrItems.slice(0, 40) : [{ label: 'لا توجد حقول متاحة هنا', off: true }]; } },
      { label: 'ربط صورة خلفية', ico: 'image', sub: function () { return srcItems(sources(ctx, 'image'), function (s) { add({ sp: i.sp, kind: 'bgimage', src: s }); }); } },
      { sep: true },
      { label: 'تكرار لكل مدخل', ico: 'repeat', sub: function () {
        var l = [];
        if (S.assign.kind === 'archive') l.push({ label: 'عناصر صفحة الأرشيف الحالية', fn: function () { add({ sp: i.sp, kind: 'repeat', src: 'items', dedupe: true }); } });
        B.types.filter(function (t) { return t.kind === 'collection'; }).forEach(function (t) { l.push({ label: 'قائمة: ' + t.plural, fn: function () { add({ sp: i.sp, kind: 'repeat', src: 'type', rtype: t.slug, limit: 6, order: '', dedupe: true }); } }); });
        return l.length ? l : [{ label: 'أنشئ نوع محتوى أولاً', off: true }];
      } },
      { label: 'قائمة روابط', ico: 'menuitems', sub: function () { return [{ label: 'القائمة الرئيسية (main)', fn: function () { add({ sp: i.sp, kind: 'menu', menu: 'main' }); } }, { label: 'قائمة التذييل (footer)', fn: function () { add({ sp: i.sp, kind: 'menu', menu: 'footer' }); } }]; } }
    ];
    if (i.tag === 'form') items.push({ label: 'ربط بنموذج تواصل', ico: 'form', sub: function () { return B.forms.length ? B.forms.map(function (f) { return { label: f.name, fn: function () { add({ sp: i.sp, kind: 'form', form: f.slug }); } }; }) : [{ label: 'لا توجد نماذج', off: true }]; } });
    items.push({ sep: true });
    items.push({ label: 'حفظ ككتلة للمصمم…', ico: 'layers', fn: function () {
      var name = window.prompt('اسم الكتلة؟', i.tag + (i.cls ? '.' + i.cls.split(' ')[0] : '')); if (!name) return;
      $('#m-saved').textContent = 'جارٍ حفظ الكتلة...';
      save().then(function () { return fetch(B.urls.block, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf }, body: JSON.stringify({ sp: i.sp, name: name }) }); })
        .then(function (r) { return r.json(); }).then(function (r) { $('#m-saved').textContent = r.ok ? '✔ حُفظت الكتلة' : '✘ ' + (r.error || 'فشل'); });
    } });
    items.push({ label: 'تحديد العنصر الأب', ico: 'up', fn: function () { if (i.chain.length) selectSp(i.chain[0]); }, off: !i.chain.length });
    items.push({ label: 'إزالة روابط هذا العنصر', fn: function () { S.bindings = S.bindings.filter(function (b) { return b.sp !== i.sp; }); dirty(); refresh(); }, off: !bound });
    items.push({ label: 'حذف العنصر من الصفحة', ico: 'trash', danger: true, fn: function () { add({ sp: i.sp, kind: 'remove' }); } });
    return items;
  }
  function buildMenu(items, root) {
    items.forEach(function (it) {
      if (it.sep) { root.appendChild(el('div', { class: 'ctx-sep' })); return; }
      var b = el('button', { type: 'button', class: 'ctx-item' + (it.danger ? ' danger' : '') + (it.off ? ' off' : ''), disabled: it.off ? 'disabled' : null }, [
        it.ico ? ic(it.ico, 15) : el('span', { class: 'ctx-ico' }), el('span', { class: 'ctx-label', text: it.label }), it.sub ? ic('next', 13) : null]);
      if (it.sub) { var fly = el('div', { class: 'ctx-fly' }); buildMenu(it.sub(), fly); b.appendChild(fly); }
      else if (!it.off) b.addEventListener('click', function () { hideMenu(); it.fn(); });
      root.appendChild(b);
    });
  }
  function showMenu(sp, x, y) {
    hideMenu(); var i = infoOf(sp); if (!i) return;
    if (!S.info || S.info.sp !== sp) selectSp(sp, false);
    var m = el('div', { id: 'ctx-menu', class: 'ctx', role: 'menu' }); buildMenu(ctxItems(i), m); document.body.appendChild(m);
    var w = m.offsetWidth, h = m.offsetHeight;
    m.style.left = Math.max(4, Math.min(x, innerWidth - w - 4)) + 'px'; m.style.top = Math.max(4, Math.min(y, innerHeight - h - 4)) + 'px';
    if (x + w + 230 > innerWidth) m.classList.add('fly-left');
  }
  document.addEventListener('click', hideMenu); document.addEventListener('scroll', hideMenu, true); window.addEventListener('blur', hideMenu);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
  // ------------------------------------------------------------ capture: run the page's own JS once (sandboxed) and keep the DOM it builds
  function capture(trusted) {
    var modal = el('div', { class: 'modal' }), box = el('div', { class: 'modal-box cap-box' }), status = el('p', { text: 'جارٍ تشغيل الصفحة وانتظار بناء محتواها...' });
    var fr = document.createElement('iframe'); fr.setAttribute('sandbox', trusted ? 'allow-scripts allow-same-origin' : 'allow-scripts'); fr.className = 'cap-frame'; fr.setAttribute('title', 'capture'); fr.src = B.urls.render + (trusted ? '&trusted=1' : '');
    var body = el('div', {});
    box.appendChild(el('h2', { text: 'التقاط الصفحة بعد تشغيل JS' })); box.appendChild(status); box.appendChild(body); modal.appendChild(box); document.body.appendChild(modal); document.body.appendChild(fr);
    function close() { window.removeEventListener('message', onMsg); modal.remove(); fr.remove(); }
    function onMsg(e) {
      if (e.source !== fr.contentWindow || !e.data || e.data.t !== 'snapshot') return;
      var html = e.data.html, kb = Math.round(html.length / 1024);
      status.textContent = 'تم الالتقاط (' + kb + ' KB). سيحلّ محل مصدر هذا الملف، وتُصفَّر روابطه (النسخة الأصلية تُحفظ).';
      var strip = el('input', { type: 'checkbox', checked: 'checked' });
      body.appendChild(el('label', { class: 'check' }, [strip, ' حذف سكربتات الموقع (موصى به: وإلا ستعيد إنشاء المحتوى فوق ما تربطه)']));
      body.appendChild(el('div', { class: 'row-wrap' }, [
        el('button', { type: 'button', class: 'btn primary', text: 'اعتماد الالتقاط', onclick: function () {
          status.textContent = 'جارٍ الحفظ...';
          fetch(B.urls.snapshot, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf }, body: JSON.stringify({ html: html, strip: strip.checked }) })
            .then(function (r) { return r.json(); }).then(function (r) { if (r.ok) { S.dirty = false; location.reload(); } else status.textContent = '✘ ' + (r.error || 'فشل الحفظ'); });
        } }),
        el('button', { type: 'button', class: 'btn', text: 'إلغاء', onclick: close })
      ]));
    }
    window.addEventListener('message', onMsg);
    setTimeout(function () { if (!body.firstChild) { status.textContent = 'لم تُرجع الصفحة نتيجة. قد تعتمد على طلبات شبكة أو تخزين غير متاح في الإطار المعزول.'; body.appendChild(el('div', { class: 'row-wrap' }, [el('button', { type: 'button', class: 'btn', text: 'محاولة بدون عزل (لمواقع تثق بها)', onclick: function () { if (window.confirm('في هذا الوضع يعمل JS الموقع داخل اللوحة بلا عزل. تابع فقط إن كان الموقع موثوقاً.')) { close(); capture(true); } } }), el('button', { type: 'button', class: 'btn', text: 'إغلاق', onclick: close })])); } }, 12000);
  }
  $('#m-capture').addEventListener('click', function () { capture(false); });
  if (B.jsHeavy) $('#m-banner').hidden = false;

  // ------------------------------------------------------------ io
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || e.source !== frame.contentWindow) return;
    var m = e.data || {};
    if (m.t === 'ready') { ready = true; marks(); if (B.block) post({ t: 'sel', sp: B.block.sp, scroll: true }); }
    else if (m.t === 'select') { hideMenu(); selectSp(m.sp, false); }
    else if (m.t === 'ctx') { var r = frame.getBoundingClientRect(); showMenu(m.sp, r.left + m.x, r.top + m.y); }
  });
  /** Saves the file's bindings; in block mode it also regenerates the block from its element. */
  function save() {
    return saveFile().then(function () {
      if (!B.block) return;
      var name = ($('#m-bname').value || B.block.name || '').trim();
      return fetch(B.urls.block, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf }, body: JSON.stringify({ sp: B.block.sp, name: name, block: B.block.id, fields: S.fields }) })
        .then(function (r) { return r.json(); }).then(function (r) { $('#m-saved').textContent = r.ok ? '✔ حُفظت الكتلة' : '✘ ' + (r.error || 'فشل حفظ الكتلة'); });
    });
  }
  function saveFile() {
    $('#m-saved').textContent = 'جارٍ الحفظ...';
    return fetch(B.urls.save, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf }, body: JSON.stringify({ assign: S.assign, bindings: S.bindings, title: S.title, description: S.description }) })
      .then(function (r) { return r.json(); }).then(function (r) { if (r.ok) { S.dirty = false; $('#m-saved').textContent = '✔ تم الحفظ وتوليد القالب'; } else $('#m-saved').textContent = '✘ ' + (r.error || 'فشل الحفظ'); })
      .catch(function () { $('#m-saved').textContent = '✘ تعذر الاتصال'; });
  }
  $('#m-save').addEventListener('click', save);
  $('#tog-right').addEventListener('click', function () { $('#ed-right').classList.toggle('open'); });
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); } });
  window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });
  Array.prototype.forEach.call(document.querySelectorAll('[data-ltab]'), function (b) { b.addEventListener('click', function () { Array.prototype.forEach.call(document.querySelectorAll('[data-ltab]'), function (x) { x.classList.toggle('on', x === b); }); $('#p-layers').hidden = b.dataset.ltab !== 'layers'; $('#p-binds').hidden = b.dataset.ltab !== 'binds'; }); });
  assignUI(); fillMeta(); list(); layers(); inspect();
  if (B.block) {
    ['#a-kind', '#a-type', '#a-slug', '#m-capture'].forEach(function (s) { var e = $(s); if (e) e.hidden = true; });
    var bn = $('#m-bname'); bn.hidden = false; bn.value = B.block.name; $('#m-blockbadge').hidden = false;
    var bk = $('#m-back'); if (bk) bk.href = B.urls.back;
    document.title = 'كتلة: ' + B.block.name;
    selectSp(B.block.sp, false);
  }
})();
