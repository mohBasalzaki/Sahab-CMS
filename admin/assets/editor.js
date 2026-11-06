/* Sahab page designer — tree editor (layers), style panel with breakpoints/hover, drag & drop, undo/redo. Vanilla JS, CSP-safe. */
(function () {
  'use strict';
  var B = JSON.parse(document.getElementById('boot').textContent);
  var D = B.design, U = B.urls;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var S = { tree: D.tree, sel: 'root', bp: 'desktop', st: 'normal', hist: [], fut: [], dirty: false, last: 0 };
  var frame = $('#canvas'), ready = false, seq = 0;

  // ------------------------------------------------------------------ element catalogue
  function ic(name, size) { var s = (B.icons || {})[name] || ''; var e = document.createElement('span'); e.className = 'ico-i'; e.innerHTML = '<svg class="ic' + (['back', 'next', 'undo', 'redo', 'richtext', 'layout'].indexOf(name) >= 0 ? ' mirror' : '') + '" width="' + (size || 16) + '" height="' + (size || 16) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + s + '</svg>'; return e; }
  var uid = function () { return 'e' + Math.random().toString(36).slice(2, 8); };
  var ELS = {
    section: { label: 'قسم', ico: 'section', cont: true, make: function () { return { props: { tag: 'section' }, style: { desktop: { 'padding-top': '64px', 'padding-bottom': '64px' }, mobile: { 'padding-top': '32px', 'padding-bottom': '32px' } } }; } },
    container: { label: 'حاوية', ico: 'container', cont: true, make: function () { return { props: {}, style: { desktop: { 'max-width': '1100px', 'margin-left': 'auto', 'margin-right': 'auto', 'padding-left': '16px', 'padding-right': '16px' } } }; } },
    div: { label: 'صندوق (Flex)', ico: 'flex', cont: true, make: function () { return { props: {}, style: { desktop: { display: 'flex', 'flex-direction': 'column', gap: '12px' } } }; } },
    grid: { label: 'شبكة (Grid)', ico: 'grid', cont: true, base: 'div', make: function () { return { props: {}, style: { desktop: { display: 'grid', 'grid-template-columns': 'repeat(3, 1fr)', gap: '16px' }, tablet: { 'grid-template-columns': 'repeat(2, 1fr)' }, mobile: { 'grid-template-columns': '1fr' } } }; } },
    heading: { label: 'عنوان', ico: 'heading', make: function () { return { props: { text: 'عنوان جديد', level: 2 }, style: { desktop: { 'font-size': '28px', 'font-weight': '700', 'line-height': '1.4' } } }; } },
    text: { label: 'فقرة', ico: 'paragraph', make: function () { return { props: { text: 'نص الفقرة. اضغط لتعديله من لوحة الإعدادات.' }, style: { desktop: { color: '#424245' } } }; } },
    richtext: { label: 'نص منسّق', ico: 'richtext', make: function () { return { props: { html: '<p>محتوى منسّق</p>' }, style: {} }; } },
    image: { label: 'صورة', ico: 'image', make: function () { return { props: { url: '', alt: '' }, style: { desktop: { 'max-width': '100%' } } }; } },
    button: { label: 'زر', ico: 'button', make: function () { return { props: { text: 'اضغط هنا', href: '#' }, style: { desktop: { 'background-color': '#0a0178', color: '#ffffff', 'padding': '8px 20px', 'font-weight': '500' }, hover: { 'background-color': '#0f0000' } } }; } },
    link: { label: 'رابط', ico: 'link', cont: true, make: function () { return { props: { text: 'رابط', href: '#' }, style: {} }; } },
    form: { label: 'نموذج', ico: 'form', make: function () { return { props: { form: (B.forms[0] || {}).slug || '' }, style: { desktop: { 'max-width': '560px' } } }; } },
    list: { label: 'قائمة محتوى (CMS)', ico: 'cmslist', cont: true, make: function () { return { props: { type: (B.types[0] || {}).slug || '', limit: 6, order: 'published_at:desc', empty: 'لا يوجد محتوى بعد.' }, style: { desktop: { display: 'grid', 'grid-template-columns': 'repeat(3, 1fr)', gap: '16px' }, tablet: { 'grid-template-columns': 'repeat(2, 1fr)' }, mobile: { 'grid-template-columns': '1fr' } } }; } },
    spacer: { label: 'فراغ', ico: 'spacer', make: function () { return { props: {}, style: { desktop: { height: '32px' } } }; } },
    block: { label: 'كتلة', ico: 'layers', make: function () { return { props: { ref: 0 }, style: {} }; } }
  };
  var CONTAINERS = ['section', 'container', 'div', 'list', 'link', 'button'];
  function newNode(key) {
    var def = ELS[key], m = def.make();
    return { id: uid(), type: def.base || key, props: m.props || {}, style: m.style || {}, children: [] };
  }

  // ------------------------------------------------------------------ tree helpers
  function find(id, n, parent) {
    n = n || S.tree;
    if (n.id === id) return { node: n, parent: parent || null };
    for (var i = 0; i < (n.children || []).length; i++) { var r = find(id, n.children[i], n); if (r) return r; }
    return null;
  }
  function detach(id) {
    var f = find(id); if (!f || !f.parent) return null;
    var i = f.parent.children.indexOf(f.node); f.parent.children.splice(i, 1);
    return f.node;
  }
  function clone(n) { var c = JSON.parse(JSON.stringify(n)); (function re(x) { x.id = uid(); (x.children || []).forEach(re); })(c); return c; }
  function isDesc(anc, id) { return !!(anc && find(id, anc)); }
  function insert(node, targetId, pos) {
    var t = find(targetId) || { node: S.tree, parent: null };
    if (targetId === 'root' || pos === 'inside') {
      if (targetId !== 'root' && CONTAINERS.indexOf(t.node.type) < 0) pos = 'after'; else { t.node.children = t.node.children || []; t.node.children.push(node); return; }
    }
    var p = t.parent || S.tree, i = p.children.indexOf(t.node);
    p.children.splice(pos === 'before' ? i : i + 1, 0, node);
  }

  // ------------------------------------------------------------------ history / render pipeline
  function snap() { return JSON.stringify(S.tree); }
  function commit(coalesce) {
    var now = Date.now();
    if (!(coalesce && now - S.last < 700 && S.hist.length)) { S.hist.push(S.pre); if (S.hist.length > 100) S.hist.shift(); }
    S.last = now; S.fut = []; S.dirty = true; S.pre = snap();
    $('#d-saved').textContent = '● غير محفوظ';
    refresh(); layers();
  }
  function undo() { if (!S.hist.length) return; S.fut.push(snap()); S.tree = JSON.parse(S.hist.pop()); S.pre = snap(); fixSel(); S.dirty = true; refresh(); layers(); panels(); }
  function redo() { if (!S.fut.length) return; S.hist.push(snap()); S.tree = JSON.parse(S.fut.pop()); S.pre = snap(); fixSel(); S.dirty = true; refresh(); layers(); panels(); }
  function fixSel() { if (!find(S.sel)) S.sel = 'root'; }
  var rt = null;
  function refresh() { clearTimeout(rt); rt = setTimeout(doRefresh, 120); }
  function api(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }
  function doRefresh() {
    var my = ++seq;
    api(U.preview, { id: D.id, tree: S.tree, fields: D.fields || [] }).then(function (r) {
      if (my !== seq || !ready || !r.html) return;
      frame.contentWindow.postMessage({ t: 'render', html: r.html, css: r.css, sel: S.sel }, location.origin);
    });
  }

  // ------------------------------------------------------------------ small DOM builder
  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') e.className = attrs[k]; else if (k === 'text') e.textContent = attrs[k]; else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]); else if (attrs[k] !== null && attrs[k] !== undefined) e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }

  // ------------------------------------------------------------------ left: palette
  function palette() {
    var p = $('#p-add'); p.innerHTML = '';
    p.appendChild(el('p', { class: 'muted small', text: 'اسحب عنصراً إلى الصفحة، أو اضغط عليه لإضافته داخل العنصر المحدد.' }));
    var g = el('div', { class: 'pal' });
    Object.keys(ELS).forEach(function (k) {
      if (k === 'block') return;
      var b = el('button', { type: 'button', class: 'pal-item', draggable: 'true', title: ELS[k].label }, [ic(ELS[k].ico, 18), el('span', { text: ELS[k].label })]);
      b.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', 'sahab:' + k); e.dataTransfer.effectAllowed = 'copy'; });
      b.addEventListener('click', function () { addEl(k, S.sel, 'inside'); });
      g.appendChild(b);
    });
    p.appendChild(g);
  }
  // ------------------------------------------------------------------ left: reusable blocks
  function blockById(id) { return (B.blocks || []).filter(function (b) { return b.id === +id; })[0]; }
  function blocksPanel() {
    var p = $('#p-blocks'); if (!p) return; p.innerHTML = '';
    p.appendChild(el('p', { class: 'muted small', text: 'كتل جاهزة: اسحبها إلى الصفحة أو اضغط لإضافتها. الكتلة المرتبطة تتحدّث في كل مكان، ويمكن فصلها كنسخة قابلة للتعديل.' }));
    var g = el('div', { class: 'pal pal-blocks' });
    (B.blocks || []).forEach(function (b) {
      var it = el('button', { type: 'button', class: 'pal-item', draggable: 'true', title: b.name }, [ic(b.kind === 'siteblock' ? 'code' : 'layers', 18), el('span', { text: b.name }), el('small', { class: 'muted', text: b.kind === 'siteblock' ? 'الموقع' : (b.type || 'المصمم') })]);
      it.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', 'sahab:block:' + b.id); e.dataTransfer.effectAllowed = 'copy'; });
      it.addEventListener('click', function () { addEl('block:' + b.id, S.sel, 'inside'); });
      g.appendChild(it);
    });
    p.appendChild(g);
    if (!(B.blocks || []).length) p.appendChild(el('p', { class: 'muted small', text: 'لا توجد كتل بعد. أنشئ كتلة من «مكتبة الكتل»، أو من عنصر محدد هنا بزر «حفظ كتلة».' }));
    p.appendChild(el('a', { class: 'btn sm', href: U.base.replace(/\/design\/\d+$/, '/blocks'), target: '_blank', rel: 'noopener', text: 'مكتبة الكتل' }));
  }
  function addEl(key, target, pos) {
    var n;
    if (key.indexOf('block:') === 0) { n = newNode('block'); n.props.ref = +key.slice(6); }
    else n = newNode(key);
    insert(n, target || 'root', pos || 'inside');
    S.sel = n.id; commit(); panels(); selectInCanvas();
  }

  // ------------------------------------------------------------------ left: layers (tree with drag reorder)
  function label(n) {
    var def = ELS[n.type] || { label: n.type, ico: 'layout' };
    if (n.type === 'block') { var bb = blockById(n.props && n.props.ref); return { ico: 'layers', name: 'كتلة · ' + (bb ? bb.name : '#' + (n.props && n.props.ref)) }; }
    var t = (n.props && (n.props.text || n.props.html || n.props.type || n.props.form)) || '';
    t = String(t).replace(/<[^>]+>/g, '').slice(0, 24);
    return { ico: def.ico, name: def.label + (t ? ' · ' + t : '') };
  }
  function layers() {
    var p = $('#p-layers'); p.innerHTML = '';
    var root = el('div', { class: 'lay-row' + (S.sel === 'root' ? ' on' : ''), 'data-id': 'root' }, [ic('layout', 15), el('span', { text: 'Body' })]);
    root.addEventListener('click', function () { select('root'); });
    p.appendChild(root);
    (function walk(list, depth) {
      list.forEach(function (n) {
        var l = label(n);
        var row = el('div', { class: 'lay-row' + (S.sel === n.id ? ' on' : ''), 'data-id': n.id, draggable: 'true' }, [ic(l.ico, 15), el('span', { text: l.name })]);
        row.style.paddingInlineStart = (8 + depth * 14) + 'px';
        row.addEventListener('click', function () { select(n.id); });
        row.addEventListener('contextmenu', function (e) { e.preventDefault(); showMenu(n.id, e.clientX, e.clientY); });
        row.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', 'layer:' + n.id); e.stopPropagation(); });
        row.addEventListener('dragover', function (e) {
          e.preventDefault(); clearDrop();
          var z = zone(e, row, n); row.classList.add('drop-' + z);
        });
        row.addEventListener('dragleave', function () { row.classList.remove('drop-before', 'drop-after', 'drop-inside'); });
        row.addEventListener('drop', function (e) {
          e.preventDefault(); e.stopPropagation(); clearDrop();
          var d = e.dataTransfer.getData('text/plain') || '', z = zone(e, row, n);
          if (d.indexOf('layer:') === 0) moveTo(d.slice(6), n.id, z);
          else if (d.indexOf('sahab:') === 0) addEl(d.slice(6), n.id, z);
        });
        p.appendChild(row);
        walk(n.children || [], depth + 1);
      });
    })(S.tree.children || [], 0);
  }
  function clearDrop() { $$('.lay-row').forEach(function (r) { r.classList.remove('drop-before', 'drop-after', 'drop-inside'); }); }
  function zone(e, row, n) {
    var r = row.getBoundingClientRect(), y = (e.clientY - r.top) / r.height;
    if (CONTAINERS.indexOf(n.type) >= 0) return y < 0.25 ? 'before' : y > 0.75 ? 'after' : 'inside';
    return y < 0.5 ? 'before' : 'after';
  }
  function moveTo(id, target, pos) {
    if (id === target) return;
    var f = find(id); if (!f || isDesc(f.node, target)) return;
    var n = detach(id); insert(n, target, pos); S.sel = id; commit(); panels(); selectInCanvas();
  }
  function select(id) { S.sel = id; layers(); panels(); selectInCanvas(); }
  function selectInCanvas() { if (ready) frame.contentWindow.postMessage({ t: 'sel', id: S.sel }, location.origin); }

  // ------------------------------------------------------------------ style schema
  var OPT = {
    display: ['', 'block', 'flex', 'grid', 'inline-block', 'inline-flex', 'none'], dir: ['', 'row', 'column', 'row-reverse', 'column-reverse'], wrap: ['', 'nowrap', 'wrap'],
    just: ['', 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'], align: ['', 'stretch', 'flex-start', 'center', 'flex-end', 'baseline'],
    cols: ['', '1fr', 'repeat(2, 1fr)', 'repeat(3, 1fr)', 'repeat(4, 1fr)', '2fr 1fr', '1fr 2fr', 'repeat(2, minmax(200px, 1fr))'],
    weight: ['', '300', '400', '500', '600', '700', '800'], talign: ['', 'start', 'center', 'end', 'justify'], ttrans: ['', 'none', 'uppercase', 'lowercase', 'capitalize'], tdeco: ['', 'none', 'underline', 'line-through'],
    bsize: ['', 'cover', 'contain', 'auto'], bpos: ['', 'center', 'top', 'bottom', 'left', 'right'], brep: ['', 'no-repeat', 'repeat'], bstyle: ['', 'none', 'solid', 'dashed', 'dotted'],
    shadow: ['', 'none', 'sm', 'md', 'lg'], overflow: ['', 'visible', 'hidden', 'auto'], pos: ['', 'static', 'relative', 'sticky'], fit: ['', 'cover', 'contain', 'fill'], cursor: ['', 'auto', 'pointer']
  };
  var GROUPS = [
    ['التخطيط', [['display', 'العرض', 's', 'display'], ['flex-direction', 'الاتجاه', 's', 'dir'], ['flex-wrap', 'الالتفاف', 's', 'wrap'], ['justify-content', 'محاذاة رئيسية', 's', 'just'], ['align-items', 'محاذاة عرضية', 's', 'align'], ['gap', 'المسافة بين العناصر', 't'], ['grid-template-columns', 'أعمدة الشبكة', 'c', 'cols']]],
    ['الحجم', [['width', 'العرض', 't'], ['max-width', 'أقصى عرض', 't'], ['min-height', 'أدنى ارتفاع', 't'], ['height', 'الارتفاع', 't'], ['aspect-ratio', 'النسبة (16 / 9)', 't'], ['object-fit', 'ملاءمة الصورة', 's', 'fit']]],
    ['التباعد', [['margin', 'الهامش الخارجي', 'box'], ['padding', 'الحشو الداخلي', 'box']]],
    ['الخط', [['font-size', 'حجم الخط', 't'], ['font-weight', 'السُمك', 's', 'weight'], ['line-height', 'ارتفاع السطر', 't'], ['letter-spacing', 'تباعد الحروف', 't'], ['text-align', 'المحاذاة', 's', 'talign'], ['text-transform', 'التحويل', 's', 'ttrans'], ['text-decoration', 'الزخرفة', 's', 'tdeco'], ['color', 'اللون', 'color']]],
    ['الخلفية', [['background-color', 'لون الخلفية', 'color'], ['background-image', 'صورة الخلفية', 'media'], ['background-size', 'الحجم', 's', 'bsize'], ['background-position', 'الموضع', 's', 'bpos'], ['background-repeat', 'التكرار', 's', 'brep']]],
    ['الحدود', [['border-width', 'سُمك الحد', 't'], ['border-style', 'النمط', 's', 'bstyle'], ['border-color', 'اللون', 'color'], ['border-radius', 'الاستدارة', 't']]],
    ['التأثيرات', [['opacity', 'الشفافية (0-1)', 't'], ['box-shadow', 'الظل', 's', 'shadow'], ['overflow', 'الفيض', 's', 'overflow'], ['position', 'الموضع', 's', 'pos'], ['cursor', 'المؤشر', 's', 'cursor']]]
  ];
  var openGroups = { 'التخطيط': true, 'التباعد': true, 'الخط': true };
  function skey() { return S.st === 'hover' ? 'hover' : S.bp; }
  function getv(n, prop, key) { var s = n.style && n.style[key || skey()]; return s && s[prop] != null ? s[prop] : ''; }
  function inherited(n, prop) { // value from larger breakpoints, shown as placeholder
    if (S.st === 'hover') return getv(n, prop, 'desktop');
    var order = ['desktop', 'tablet', 'mobile'], i = order.indexOf(S.bp);
    for (var k = i - 1; k >= 0; k--) { var v = getv(n, prop, order[k]); if (v) return v; }
    return '';
  }
  function setv(n, prop, val) {
    n.style = n.style || {}; var k = skey(); n.style[k] = n.style[k] || {};
    if (val === '' || val == null) delete n.style[k][prop]; else n.style[k][prop] = String(val);
    commit(true);
  }

  // ------------------------------------------------------------------ right: style panel
  function ctl(n, prop, lab, kind, optKey) {
    var cur = getv(n, prop), inh = inherited(n, prop), wrap = el('label', { class: 'f' + (cur ? ' set' : '') }, [el('span', { text: lab })]);
    var input;
    if (kind === 's') {
      input = el('select', {}, OPT[optKey].map(function (o) { return el('option', { value: o, text: o || (inh ? '(' + inh + ')' : '—') }); }));
      input.value = cur;
    } else if (kind === 'c') {
      input = el('input', { type: 'text', list: 'dl-' + optKey, value: cur, placeholder: inh });
      if (!$('#dl-' + optKey)) { var dl = el('datalist', { id: 'dl-' + optKey }, OPT[optKey].filter(Boolean).map(function (o) { return el('option', { value: o }); })); document.body.appendChild(dl); }
    } else if (kind === 'color') {
      var txt = el('input', { type: 'text', list: 'dl-vars', value: cur, placeholder: inh || '#000000', dir: 'ltr' });
      var pick = el('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(cur || inh) ? (cur || inh) : '#000000', class: 'swatch' });
      pick.addEventListener('input', function () { txt.value = pick.value; setv(n, prop, pick.value); });
      txt.addEventListener('input', function () { setv(n, prop, txt.value.trim()); });
      return el('div', { class: 'f row' + (cur ? ' set' : '') }, [el('span', { text: lab }), pick, txt]);
    } else if (kind === 'media') {
      var tx = el('input', { type: 'text', value: cur, placeholder: inh || 'رقم الوسائط', dir: 'ltr' });
      var bt = el('button', { type: 'button', class: 'btn sm', text: 'اختيار' });
      bt.addEventListener('click', function () { pickMedia(function (m) { tx.value = m.id; setv(n, prop, m.id); setv2(n, 'background-size', 'cover'); }); });
      tx.addEventListener('input', function () { setv(n, prop, tx.value.trim()); });
      return el('div', { class: 'f row' + (cur ? ' set' : '') }, [el('span', { text: lab }), tx, bt]);
    } else {
      input = el('input', { type: 'text', list: 'dl-vars', value: cur, placeholder: inh, dir: 'ltr' });
    }
    input.addEventListener(kind === 's' ? 'change' : 'input', function () { setv(n, prop, input.value.trim()); });
    wrap.appendChild(input);
    return wrap;
  }
  function setv2(n, p, v) { if (!getv(n, p)) { n.style[skey()][p] = v; } }
  function boxCtl(n, prop, lab) {
    var sides = ['top', 'right', 'bottom', 'left'], g = el('div', { class: 'box' }, [el('span', { text: lab })]);
    var row = el('div', { class: 'box-in' });
    sides.forEach(function (s) {
      var p = prop + '-' + s, cur = getv(n, p) || '', inh = inherited(n, p);
      var i = el('input', { type: 'text', value: cur, placeholder: inh || '0', dir: 'ltr', 'aria-label': lab + ' ' + s, title: s });
      if (cur) i.classList.add('set');
      i.addEventListener('input', function () { setv(n, p, i.value.trim()); i.classList.toggle('set', !!i.value); });
      row.appendChild(i);
    });
    g.appendChild(row); return g;
  }
  function stylePanel() {
    var n = (find(S.sel) || { node: S.tree }).node, p = $('#p-style'); p.innerHTML = '';
    var bar = el('div', { class: 'st-bar' }, [
      el('button', { type: 'button', class: S.st === 'normal' ? 'on' : '', text: 'عادي', onclick: function () { S.st = 'normal'; stylePanel(); } }),
      el('button', { type: 'button', class: S.st === 'hover' ? 'on' : '', text: 'Hover', onclick: function () { S.st = 'hover'; stylePanel(); } })
    ]);
    p.appendChild(bar);
    p.appendChild(el('p', { class: 'muted small', text: S.st === 'hover' ? 'تنسيق عند تمرير المؤشر' : 'التعديل على: ' + { desktop: 'سطح المكتب (الأساس)', tablet: 'الجهاز اللوحي ≤991px', mobile: 'الجوال ≤767px' }[S.bp] }));
    GROUPS.forEach(function (g) {
      var d = el('details', {}, [el('summary', { text: g[0] })]);
      if (openGroups[g[0]]) d.setAttribute('open', '');
      d.addEventListener('toggle', function () { openGroups[g[0]] = d.open; });
      g[1].forEach(function (f) { d.appendChild(f[2] === 'box' ? boxCtl(n, f[0], f[1]) : ctl(n, f[0], f[1], f[2], f[3])); });
      p.appendChild(d);
    });
    p.appendChild(el('button', { type: 'button', class: 'btn sm danger', text: 'مسح تنسيق هذه الحالة', onclick: function () { if (n.style) { delete n.style[skey()]; commit(); stylePanel(); } } }));
  }

  // ------------------------------------------------------------------ right: settings (content) panel
  function ctxType(id) {
    var chain = [], f = find(id);
    (function up(x) { var r = find(x.id); if (!r) return; chain.push(r.node); if (r.parent) up(r.parent); })(f ? f.node : S.tree);
    for (var i = 0; i < chain.length; i++) if (chain[i].type === 'list' && chain[i].props.type) return chain[i].props.type;
    return D.type || '';
  }
  function typeFields(slug, only) {
    var t = B.types.filter(function (x) { return x.slug === slug; })[0];
    return t ? t.fields.filter(function (f) { return !only || only.indexOf(f.type) >= 0; }) : [];
  }
  function field(lab, input) { return el('label', { class: 'f' }, [el('span', { text: lab }), input]); }
  function pset(n, k, v, cls) { n.props[k] = v; commit(true); if (cls) settingsPanel(); }
  function textInput(n, k, multi) {
    var i = el(multi ? 'textarea' : 'input', { dir: 'auto' }); if (multi) i.rows = 4; i.value = n.props[k] || '';
    i.addEventListener('input', function () { pset(n, k, i.value); }); return i;
  }
  function selectInput(n, k, opts, empty) {
    var s = el('select', {}, (empty ? [el('option', { value: '', text: empty })] : []).concat(opts.map(function (o) { return el('option', { value: o[0], text: o[1] }); })));
    s.value = n.props[k] != null ? String(n.props[k]) : ''; s.addEventListener('change', function () { pset(n, k, s.value, true); }); return s;
  }
  function tokenBtn(n, k) {
    var fs = typeFields(ctxType(n.id)).map(function (f) { return { name: f.name, label: f.label }; });
    if (D.kind === 'block') fs = (D.fields || []).map(function (f) { return { name: 'block.' + f.name, label: '◆ ' + f.label }; }).concat(fs);
    if (!fs.length) return null;
    var s = el('select', {}, [el('option', { value: '', text: '+ إدراج حقل ديناميكي' })].concat(fs.map(function (f) { return el('option', { value: f.name, text: f.label + ' (' + f.name + ')' }); })));
    s.addEventListener('change', function () { if (s.value) { n.props[k] = (n.props[k] || '') + '{{' + s.value + '}}'; commit(); settingsPanel(); } });
    return s;
  }
  function settingsPanel() {
    var f = find(S.sel), p = $('#p-settings'); p.innerHTML = '';
    if (!f || S.sel === 'root') { p.appendChild(el('p', { class: 'muted', text: 'اختر عنصراً لتعديل محتواه.' })); return; }
    var n = f.node; n.props = n.props || {}; var t = n.type;
    p.appendChild(el('h4', { text: (ELS[t] || {}).label || t }));
    if (t === 'section') p.appendChild(field('وسم HTML', selectInput(n, 'tag', ['section', 'header', 'footer', 'nav', 'main', 'div', 'aside', 'article'].map(function (x) { return [x, x]; }))));
    if (t === 'container' || t === 'div') p.appendChild(field('وسم HTML', selectInput(n, 'tag', ['div', 'section', 'header', 'footer', 'nav', 'main', 'aside', 'article'].map(function (x) { return [x, x]; }))));
    if (t === 'heading') { p.appendChild(field('النص', textInput(n, 'text', true))); var tb = tokenBtn(n, 'text'); if (tb) p.appendChild(tb); p.appendChild(field('المستوى', selectInput(n, 'level', [1, 2, 3, 4, 5, 6].map(function (x) { return [String(x), 'H' + x]; })))); }
    if (t === 'text') { p.appendChild(field('النص', textInput(n, 'text', true))); var tb2 = tokenBtn(n, 'text'); if (tb2) p.appendChild(tb2); }
    if (t === 'richtext') {
      var rf = typeFields(ctxType(n.id), ['richtext', 'textarea']);
      if (D.kind === 'block') rf = (D.fields || []).filter(function (x) { return x.type === 'richtext' || x.type === 'textarea'; }).map(function (x) { return { name: 'block.' + x.name, label: '◆ ' + x.label }; }).concat(rf);
      if (rf.length) p.appendChild(field('ربط بحقل', selectInput(n, 'bind', rf.map(function (x) { return [x.name, x.label]; }), '— بدون —')));
      p.appendChild(field('HTML (يُنقّى عند الحفظ)', textInput(n, 'html', true)));
    }
    if (t === 'image') {
      var imf = typeFields(ctxType(n.id), ['media']);
      if (D.kind === 'block') imf = (D.fields || []).filter(function (x) { return x.type === 'image'; }).map(function (x) { return { name: 'block.' + x.name, label: '◆ ' + x.label }; }).concat(imf);
      var pb = el('button', { type: 'button', class: 'btn sm', text: n.props.mediaId ? 'تغيير الصورة (#' + n.props.mediaId + ')' : 'اختيار من الوسائط', onclick: function () { pickMedia(function (m) { n.props.mediaId = m.id; n.props.alt = n.props.alt || m.alt || ''; commit(); settingsPanel(); }); } });
      p.appendChild(pb);
      if (imf.length) p.appendChild(field('ربط بحقل وسائط', selectInput(n, 'bind', imf.map(function (x) { return [x.name, x.label]; }), '— بدون —')));
      p.appendChild(field('أو رابط صورة', textInput(n, 'url')));
      p.appendChild(field('النص البديل (alt)', textInput(n, 'alt')));
    }
    if (t === 'button' || t === 'link') {
      p.appendChild(field('النص', textInput(n, 'text'))); p.appendChild(field('الرابط', textInput(n, 'href')));
      var tb3 = tokenBtn(n, 'href'); if (tb3) p.appendChild(tb3);
      var cb = el('input', { type: 'checkbox' }); cb.checked = !!n.props.newtab; cb.addEventListener('change', function () { pset(n, 'newtab', cb.checked); });
      p.appendChild(el('label', { class: 'check' }, [cb, ' فتح في تبويب جديد']));
    }
    if (t === 'form') p.appendChild(field('النموذج', selectInput(n, 'form', B.forms.map(function (x) { return [x.slug, x.name]; }), '— اختر —')));
    if (t === 'list') {
      p.appendChild(field('نوع المحتوى', selectInput(n, 'type', B.types.filter(function (x) { return x.kind === 'collection'; }).map(function (x) { return [x.slug, x.plural]; }), '— اختر —')));
      p.appendChild(field('عدد العناصر', textInput(n, 'limit')));
      var of = typeFields(n.props.type).map(function (x) { return [x.name + ':asc', x.label + ' ↑']; }).concat(typeFields(n.props.type).map(function (x) { return [x.name + ':desc', x.label + ' ↓']; }));
      p.appendChild(field('الترتيب', selectInput(n, 'order', of, '— الأحدث —')));
      p.appendChild(field('نص عند عدم وجود محتوى', textInput(n, 'empty')));
      p.appendChild(el('p', { class: 'muted small', text: 'ضع العناصر داخل القائمة؛ ستتكرر لكل مدخل، واستخدم {{field}} للبيانات.' }));
    }
    if (t === 'block') {
      var bk = blockById(n.props.ref);
      p.appendChild(el('p', { class: 'm-hint', text: bk ? 'الكتلة: ' + bk.name + (bk.type ? ' (بيانات: ' + bk.type + ')' : '') : 'كتلة غير موجودة (حُذفت؟)' }));
      if (bk && (bk.fields || []).length) {
        p.appendChild(el('div', { class: 'm-sec-title', text: 'حقول الكتلة' }));
        bk.fields.forEach(function (f) { p.appendChild(fieldInput(n, f)); });
      }
      if (bk && bk.kind === 'block') {
        p.appendChild(el('a', { class: 'btn sm', href: U.base.replace(/\/\d+$/, '/' + bk.id), target: '_blank', rel: 'noopener', text: 'فتح الكتلة للتعديل' }));
        p.appendChild(el('button', { type: 'button', class: 'btn sm', text: 'فصل كنسخة قابلة للتعديل', onclick: function () {
          var c = clone({ id: 'x', type: 'div', props: {}, style: { desktop: {} }, children: (bk.tree && bk.tree.children) || [] });
          n.type = 'div'; n.props = {}; n.children = c.children; commit(); panels(); selectInCanvas();
        } }));
      } else if (bk) {
        if (bk.edit) p.appendChild(el('a', { class: 'btn sm', href: bk.edit, target: '_blank', rel: 'noopener', text: 'تعديل الكتلة وحقولها (معاينة وربط)' }));
        p.appendChild(el('p', { class: 'muted small', text: 'كتلة من الموقع المستورد: تعتمد تصميم الموقع وتُحدَّث نسخها بتحديثها.' }));
      }
    } else {
      var ci = el('input', { type: 'text', list: 'dl-classes', dir: 'ltr', placeholder: 'wrap card btn ...', value: n.props.class || '' });
      ci.addEventListener('input', function () { n.props.class = ci.value; commit(true); });
      p.appendChild(field('فئات CSS من تصميم الموقع', ci));
      p.appendChild(el('button', { type: 'button', class: 'btn sm', text: 'حفظ العنصر ككتلة', onclick: function () {
        saveBlock(n);
      } }));
    }
    var row = el('div', { class: 'row-wrap' }, [
      el('button', { type: 'button', class: 'btn sm', text: 'تكرار', onclick: dup }),
      el('button', { type: 'button', class: 'btn sm', 'aria-label': 'أعلى', onclick: function () { nudge(-1); } }, [ic('up', 15)]),
      el('button', { type: 'button', class: 'btn sm', 'aria-label': 'أسفل', onclick: function () { nudge(1); } }, [ic('down', 15)]),
      el('button', { type: 'button', class: 'btn sm danger', text: 'حذف', onclick: del })
    ]);
    p.appendChild(row);
  }
  function dup() { var f = find(S.sel); if (!f || !f.parent) return; var c = clone(f.node); f.parent.children.splice(f.parent.children.indexOf(f.node) + 1, 0, c); S.sel = c.id; commit(); panels(); selectInCanvas(); }
  function del() { var f = find(S.sel); if (!f || !f.parent) return; var par = f.parent; detach(S.sel); S.sel = par.id || 'root'; commit(); panels(); selectInCanvas(); }
  function nudge(d) { var f = find(S.sel); if (!f || !f.parent) return; var c = f.parent.children, i = c.indexOf(f.node), j = i + d; if (j < 0 || j >= c.length) return; c.splice(i, 1); c.splice(j, 0, f.node); commit(); }

  // ------------------------------------------------------------------ right: page panel
  function pagePanel() {
    var p = $('#p-page'); p.innerHTML = '';
    var meta = D.meta && !Array.isArray(D.meta) ? D.meta : (D.meta = {});
    var mk = function (lab, obj, k, multi) { var i = el(multi ? 'textarea' : 'input', { dir: 'auto' }); i.value = obj[k] || ''; i.addEventListener('input', function () { obj[k] = i.value; S.dirty = true; $('#d-saved').textContent = '● غير محفوظ'; }); return field(lab, i); };
    if (D.kind === 'page') p.appendChild(mk('الرابط (slug)', D, 'slug'));
    var ls = el('select', {}, [el('option', { value: 'theme', text: 'داخل تخطيط الثيم (رأس وتذييل)' }), el('option', { value: 'blank', text: 'صفحة فارغة بالكامل' })]);
    ls.value = D.layout; ls.addEventListener('change', function () { D.layout = ls.value; S.dirty = true; });
    p.appendChild(field('التخطيط', ls));
    p.appendChild(mk('عنوان SEO', meta, 'title')); p.appendChild(mk('الوصف (SEO)', meta, 'description', true));
    var act = el('div', { class: 'row-wrap' }, [el('button', { type: 'button', class: 'btn sm', text: 'إلغاء النشر', onclick: function () { api(U.base + '/unpublish', {}).then(function () { var b = $('#d-status'); b.textContent = 'مسودة'; b.className = 'badge draft'; }); } }), el('button', { type: 'button', class: 'btn sm danger', text: 'حذف التصميم', onclick: function () { if (confirm('حذف هذا التصميم والرجوع لعرض الثيم؟')) { S.dirty = false; api(U.base + '/delete', {}).then(function (r) { location.href = r.redirect || U.back; }); } } })]);
    p.appendChild(act);
    if (D.kind === 'template') p.appendChild(el('p', { class: 'muted small', text: 'قالب لنوع «' + D.type + '»: يُستخدم لكل مدخلاته على الواجهة، ويعرض الحقول عبر {{field}}.' }));
  }

  // ------------------------------------------------------------------ block fields: a block's own inputs, used via {{block.name}}
  var FT = [['text', 'نص قصير'], ['textarea', 'نص طويل'], ['richtext', 'محتوى منسّق'], ['image', 'صورة'], ['url', 'رابط'], ['number', 'رقم'], ['boolean', 'نعم / لا']];
  function fieldDirty() { S.dirty = true; $('#d-saved').textContent = '● غير محفوظ'; refresh(); }
  function addField(name, label, type) {
    D.fields = D.fields || [];
    var base = name, i = 2; while (D.fields.some(function (f) { return f.name === name; })) name = base + '_' + (i++);
    D.fields.push({ name: name, label: label, type: type, default: '' }); fieldDirty(); fieldsPanel(); settingsPanel();
  }
  function useField(f) {
    var fo = find(S.sel), n = fo && S.sel !== 'root' ? fo.node : null, tok = '{{block.' + f.name + '}}'; if (!n) { $('#d-saved').textContent = 'حدّد عنصراً أولاً'; return; }
    n.props = n.props || {};
    if (n.type === 'image' && f.type === 'image') n.props.bind = 'block.' + f.name;
    else if (n.type === 'richtext' && (f.type === 'richtext' || f.type === 'textarea')) n.props.bind = 'block.' + f.name;
    else if ((n.type === 'button' || n.type === 'link') && f.type === 'url') n.props.href = tok;
    else if (['heading', 'text', 'button', 'link'].indexOf(n.type) >= 0 && f.type !== 'image') n.props.text = (n.props.text ? n.props.text + ' ' : '') + tok;
    else if (n.type === 'image') n.props.alt = tok;
    else { $('#d-saved').textContent = 'هذا الحقل لا يناسب العنصر المحدد'; return; }
    commit(); panels(); selectInCanvas();
  }
  function fieldAsElement(f) {
    var n, tok = '{{block.' + f.name + '}}', tf;
    if (f.type === 'image') { n = newNode('image'); n.props.bind = 'block.' + f.name; }
    else if (f.type === 'richtext' || f.type === 'textarea') { n = newNode('richtext'); n.props.bind = 'block.' + f.name; }
    else if (f.type === 'url') { n = newNode('button'); n.props.href = tok; tf = (D.fields || []).filter(function (x) { return /text|label/.test(x.name) && x.type === 'text'; })[0]; n.props.text = tf ? '{{block.' + tf.name + '}}' : f.label; }
    else if (f.type === 'boolean') { $('#d-saved').textContent = 'حقل نعم/لا يُستخدم لإظهار/إخفاء عناصر لاحقاً'; return; }
    else { n = /title|heading|name|عنوان/i.test(f.name + f.label) ? newNode('heading') : newNode('text'); n.props.text = tok; }
    insert(n, S.sel || 'root', 'inside'); S.sel = n.id; commit(); panels(); selectInCanvas();
  }
  function fieldsPanel() {
    var p = $('#p-fields'); if (!p) return; p.innerHTML = '';
    D.fields = D.fields || [];
    p.appendChild(el('p', { class: 'm-hint', text: 'حقول الكتلة هي مدخلاتها. عند إضافة الكتلة لأي صفحة تظهر كنموذج يملؤه المصمم، أو يربطه بحقل المحتوى تلقائياً. اكتب {{block.اسم_الحقل}} في أي نص أو اربط الصور بها.' }));
    var chips = el('div', { class: 'chips' });
    [['عنوان', 'title', 'text'], ['وصف', 'description', 'textarea'], ['صورة', 'image', 'image'], ['نص الزر', 'button_text', 'text'], ['رابط الزر', 'button_url', 'url'], ['محتوى', 'body', 'richtext']].forEach(function (c) {
      chips.appendChild(el('button', { type: 'button', class: 'chip', onclick: function () { addField(c[1], c[0], c[2]); } }, [ic('plus', 12), ' ' + c[0]]));
    });
    p.appendChild(chips);
    D.fields.forEach(function (f, i) {
      var nm = el('input', { type: 'text', value: f.label, dir: 'auto', 'aria-label': 'label' });
      nm.addEventListener('input', function () { f.label = nm.value; fieldDirty(); });
      var ty = el('select', {}, FT.map(function (t) { return el('option', { value: t[0], text: t[1] }); })); ty.value = f.type;
      ty.addEventListener('change', function () { f.type = ty.value; fieldDirty(); });
      var df = el('input', { type: 'text', value: f.default || '', dir: 'auto', placeholder: 'القيمة الافتراضية (للمعاينة)' });
      df.addEventListener('input', function () { f.default = df.value; fieldDirty(); });
      p.appendChild(el('div', { class: 'bfield' }, [
        el('div', { class: 'row-inline' }, [nm, ty]), df,
        el('code', { class: 'tok', dir: 'ltr', text: '{{block.' + f.name + '}}' }),
        el('div', { class: 'row-wrap' }, [
          el('button', { type: 'button', class: 'btn sm', text: 'ربط بالعنصر المحدد', onclick: function () { useField(f); } }),
          el('button', { type: 'button', class: 'btn sm', text: 'إضافة كعنصر', onclick: function () { fieldAsElement(f); } }),
          el('button', { type: 'button', class: 'btn sm danger', text: 'حذف', onclick: function () { D.fields.splice(i, 1); fieldDirty(); fieldsPanel(); } })
        ])
      ]));
    });
    var nl = el('input', { type: 'text', placeholder: 'حقل جديد (مثال: اسم المنتج)', dir: 'auto' }), nt = el('select', {}, FT.map(function (t) { return el('option', { value: t[0], text: t[1] }); }));
    p.appendChild(el('div', { class: 'm-sec' }, [el('h4', { text: 'إضافة حقل' }), nl, nt, el('button', { type: 'button', class: 'btn sm primary', text: 'إضافة', onclick: function () {
      var label = nl.value.trim(); if (!label) return;
      var slug = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''); if (!/^[a-z]/.test(slug)) slug = 'field';
      addField(slug, label, nt.value);
    } })]));
  }
  // instance form: one input per block field; a value is either static or bound to the page's own CMS field ("@field")
  function fieldInput(n, f) {
    n.props.values = n.props.values || {};
    var cur = n.props.values[f.name], bound = typeof cur === 'string' && cur.charAt(0) === '@', ctl;
    function setVal(v) { n.props.values[f.name] = v; commit(true); }
    var staticVal = bound ? '' : (cur != null ? cur : (f.default || ''));
    if (f.type === 'boolean') { ctl = el('input', { type: 'checkbox' }); ctl.checked = !!staticVal && staticVal !== '0'; ctl.addEventListener('change', function () { setVal(ctl.checked ? '1' : '0'); }); }
    else if (f.type === 'repeater') {
      var rows = Array.isArray(cur) ? cur : (Array.isArray(f.default) ? JSON.parse(JSON.stringify(f.default)) : []);
      ctl = el('div', { class: 'bf-rep' });
      var draw = function () {
        ctl.innerHTML = '';
        rows.forEach(function (rw, ri) {
          var line = el('div', { class: 'row-inline' });
          (f.fields || []).forEach(function (sf) { var inp = el('input', { type: 'text', placeholder: sf.label || sf.name, dir: 'auto' }); inp.value = rw[sf.name] || ''; inp.addEventListener('input', function () { rw[sf.name] = inp.value; setVal(rows); }); line.appendChild(inp); });
          line.appendChild(el('button', { type: 'button', class: 'btn sm danger', text: '×', onclick: function () { rows.splice(ri, 1); setVal(rows); draw(); } }));
          ctl.appendChild(line);
        });
        ctl.appendChild(el('button', { type: 'button', class: 'btn sm', text: '+ عنصر', onclick: function () { rows.push({}); setVal(rows); draw(); } }));
      };
      draw();
      return el('div', { class: 'f bf' }, [el('span', { text: f.label }), ctl]);
    }
    else if (f.type === 'select') { ctl = el('select', {}, (f.options || []).map(function (o) { return el('option', { value: o.value, text: o.label }); })); ctl.value = staticVal; ctl.addEventListener('change', function () { setVal(ctl.value); }); }
    else if (f.type === 'color') { ctl = el('input', { type: 'color' }); ctl.value = /^#[0-9a-f]{6}$/i.test(staticVal) ? staticVal : '#0a0178'; ctl.addEventListener('input', function () { setVal(ctl.value); }); }
    else if (f.type === 'textarea' || f.type === 'richtext') { ctl = el('textarea', { rows: 3, dir: 'auto' }); ctl.value = staticVal; ctl.addEventListener('input', function () { setVal(ctl.value); }); }
    else if (f.type === 'image') {
      var iv = el('input', { type: 'text', dir: 'ltr', placeholder: 'رقم الوسائط أو رابط' }); iv.value = staticVal; iv.addEventListener('input', function () { setVal(iv.value.trim()); });
      var pb = el('button', { type: 'button', class: 'btn sm', text: 'اختيار', onclick: function () { pickMedia(function (m) { iv.value = m.id; setVal(String(m.id)); }); } });
      ctl = el('div', { class: 'row-inline' }, [iv, pb]);
    } else { ctl = el('input', { type: 'text', dir: f.type === 'url' || f.type === 'number' ? 'ltr' : 'auto', inputmode: f.type === 'number' ? 'decimal' : null }); ctl.value = staticVal; ctl.addEventListener('input', function () { setVal(ctl.value); }); }
    var want = { image: ['media'], richtext: ['richtext', 'textarea'], textarea: ['textarea', 'richtext', 'text'], url: ['url', 'text'], number: ['number'], boolean: ['boolean'], text: ['text', 'textarea', 'select', 'email', 'url', 'uid', 'number', 'date', 'datetime'] }[f.type] || ['text'];
    var cf = typeFields(ctxType(n.id)).filter(function (x) { return want.indexOf(x.type) >= 0 || (f.type === 'text' && ['title', 'slug', 'published_at'].indexOf(x.name) >= 0); });
    var src = el('select', {}, [el('option', { value: '', text: '— قيمة ثابتة —' })].concat(cf.map(function (x) { return el('option', { value: '@' + x.name, text: '⟵ ' + x.label }); })));
    src.value = bound ? cur : '';
    function sync() { var b = !!src.value; Array.prototype.forEach.call(ctl.querySelectorAll ? ctl.querySelectorAll('input,textarea,button') : [], function (x) { x.disabled = b; }); if (ctl.tagName === 'INPUT' || ctl.tagName === 'TEXTAREA') ctl.disabled = b; }
    src.addEventListener('change', function () { if (src.value) setVal(src.value); else { var v = ctl.tagName === 'DIV' ? ctl.querySelector('input').value : (ctl.type === 'checkbox' ? (ctl.checked ? '1' : '0') : ctl.value); setVal(v); } sync(); });
    sync();
    var kids = [el('span', { text: f.label }), ctl]; if (cf.length) kids.push(src);
    return el('label', { class: 'f bf' }, kids);
  }

  function panels() {
    var f = find(S.sel), n = f ? f.node : S.tree;
    $('#ed-sel').textContent = S.sel === 'root' ? 'Body' : label(n).name;
    stylePanel(); settingsPanel(); pagePanel(); fieldsPanel();
  }

  // ------------------------------------------------------------------ media picker modal
  function pickMedia(cb) {
    var m = el('div', { class: 'modal' }), box = el('div', { class: 'modal-box' }), grid = el('div', { class: 'media-grid' });
    box.appendChild(el('div', { class: 'row-wrap' }, [el('strong', { text: 'اختر صورة' }), el('button', { type: 'button', class: 'btn sm', text: 'إغلاق', onclick: function () { m.remove(); } })]));
    var up = el('input', { type: 'file', accept: 'image/*' });
    up.addEventListener('change', function () {
      var fd = new FormData(); fd.append('files[]', up.files[0]); fd.append('_csrf', B.csrf);
      fetch(U.media + '/upload', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': B.csrf } }).then(function (r) { return r.json(); }).then(load);
    });
    box.appendChild(up); box.appendChild(grid); m.appendChild(box); document.body.appendChild(m);
    function load() {
      fetch(U.media + '?json=1&kind=image', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
        grid.innerHTML = '';
        d.items.forEach(function (it) {
          var fig = el('figure', { class: 'media-item' }, [el('img', { src: it.thumb, alt: '' }), el('figcaption', {}, [el('span', { class: 'name', text: it.original })])]);
          fig.addEventListener('click', function () { m.remove(); cb(it); }); grid.appendChild(fig);
        });
      });
    }
    load();
  }

  // ------------------------------------------------------------------ top bar
  function setBp(bp) {
    S.bp = bp; $$('[data-bp]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-bp') === bp); });
    $('#frame').className = 'frame bp-' + bp; stylePanel();
  }
  function save(publish) {
    D.title = $('#d-title').value;
    var body = { tree: S.tree, title: D.title, slug: D.slug, layout: D.layout, meta: D.meta, fields: D.fields || [] };
    $('#d-saved').textContent = 'جارٍ الحفظ...';
    return api(U.base + (publish ? '/publish' : '/save'), body).then(function (r) {
      if (!r.ok) { $('#d-saved').textContent = '✘ ' + (r.error || 'فشل الحفظ'); return; }
      S.dirty = false; D.slug = r.slug || D.slug; $('#d-saved').textContent = '✔ ' + (publish ? 'تم النشر' : 'تم الحفظ');
      if (publish) { var b = $('#d-status'); b.textContent = 'منشور'; b.className = 'badge published'; D.hasLive = true; }
      view();
    }).catch(function () { $('#d-saved').textContent = '✘ تعذر الاتصال'; });
  }
  function view() { var a = $('#d-view'); a.href = D.kind === 'page' && D.slug ? U.site.replace(/\/[^\/]*$/, '/') + encodeURIComponent(D.slug) + '?preview=1' : U.site; }

  // ------------------------------------------------------------------ wiring
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || e.source !== frame.contentWindow) return;
    var m = e.data || {};
    if (m.t === 'ready') { ready = true; doRefresh(); }
    else if (m.t === 'select') { hideMenu(); select(m.id); }
    else if (m.t === 'ctx') { var fr = frame.getBoundingClientRect(); showMenu(m.id, fr.left + m.x, fr.top + m.y); }
    else if (m.t === 'drop') { addEl(m.et, m.target, m.pos); }
    else if (m.t === 'key') { hotkey(m); }
  });
  // ------------------------------------------------------------------ right-click context menu (canvas + layers)
  function saveBlock(n) {
    var name = window.prompt('اسم الكتلة؟', label(n).name); if (!name) return;
    api(U.base.replace(/\/\d+$/, '/to-block'), { name: name, type: D.type || '', node: n }).then(function (r) {
      if (r.ok) { B.blocks = [r.block].concat(B.blocks || []); blocksPanel(); $('#d-saved').textContent = 'تم حفظ الكتلة'; } else { $('#d-saved').textContent = '✘ ' + (r.error || 'تعذر الحفظ'); }
    });
  }
  function copyNode() { var f = find(S.sel); if (f && f.parent) { S.clip = JSON.parse(JSON.stringify(f.node)); $('#d-saved').textContent = 'تم النسخ'; } }
  function cutNode() { copyNode(); del(); }
  function pasteNode() {
    if (!S.clip) return;
    var c = clone(S.clip), f = find(S.sel), tgt = S.sel;
    if (S.sel === 'root' || (f && CONTAINERS.indexOf(f.node.type) >= 0)) insert(c, tgt, 'inside'); else insert(c, tgt, 'after');
    S.sel = c.id; commit(); panels(); selectInCanvas();
  }
  function wrapNode() {
    var f = find(S.sel); if (!f || !f.parent) return;
    var w = newNode('div'); w.style = { desktop: {} }; w.children = [f.node];
    f.parent.children[f.parent.children.indexOf(f.node)] = w; S.sel = w.id; commit(); panels(); selectInCanvas();
  }
  function unwrapNode() {
    var f = find(S.sel); if (!f || !f.parent || !(f.node.children || []).length) return;
    var i = f.parent.children.indexOf(f.node), kids = f.node.children;
    Array.prototype.splice.apply(f.parent.children, [i, 1].concat(kids)); S.sel = kids[0].id; commit(); panels(); selectInCanvas();
  }
  function toggleHide() {
    var f = find(S.sel); if (!f || !f.parent) return; var n = f.node; n.style = n.style || {}; n.style.desktop = n.style.desktop || {};
    if (n.style.desktop.display === 'none') delete n.style.desktop.display; else n.style.desktop.display = 'none';
    commit(); panels();
  }
  function hideMenu() { var m = $('#ctx-menu'); if (m) m.remove(); }
  function elementItems(target, pos) {
    var items = [];
    Object.keys(ELS).forEach(function (k) { if (k !== 'block') items.push({ label: ELS[k].label, ico: ELS[k].ico, fn: function () { addEl(k, target, pos); } }); });
    if ((B.blocks || []).length) { items.push({ sep: true }); (B.blocks || []).slice(0, 12).forEach(function (b) { items.push({ label: 'كتلة: ' + b.name, ico: 'layers', fn: function () { addEl('block:' + b.id, target, pos); } }); }); }
    return items;
  }
  function menuItems(id) {
    var f = find(id), n = f ? f.node : S.tree, isRoot = id === 'root' || !f || !f.parent, cont = isRoot || CONTAINERS.indexOf(n.type) >= 0, hidden = !isRoot && n.style && n.style.desktop && n.style.desktop.display === 'none';
    return [
      { label: 'نسخ', key: 'Ctrl+C', ico: 'copy', fn: copyNode, off: isRoot },
      { label: 'قص', key: 'Ctrl+X', fn: cutNode, off: isRoot },
      { label: 'لصق', key: 'Ctrl+V', fn: pasteNode, off: !S.clip },
      { label: 'تكرار', key: 'Ctrl+D', fn: dup, off: isRoot },
      { sep: true },
      { label: 'إضافة داخل', ico: 'plus', sub: function () { return elementItems(id, 'inside'); }, off: !cont },
      { label: 'إضافة قبل', sub: function () { return elementItems(id, 'before'); }, off: isRoot },
      { label: 'إضافة بعد', sub: function () { return elementItems(id, 'after'); }, off: isRoot },
      { sep: true },
      { label: 'تغليف بصندوق', fn: wrapNode, off: isRoot },
      { label: 'فك التغليف', fn: unwrapNode, off: isRoot || !(n.children || []).length },
      { label: 'نقل لأعلى', ico: 'up', fn: function () { nudge(-1); }, off: isRoot },
      { label: 'نقل لأسفل', ico: 'down', fn: function () { nudge(1); }, off: isRoot },
      { label: 'تحديد العنصر الأب', fn: function () { var p = f && f.parent; select(p && p.id ? p.id : 'root'); }, off: isRoot },
      { sep: true },
      { label: hidden ? 'إظهار' : 'إخفاء', ico: 'view', fn: toggleHide, off: isRoot },
      { label: 'حفظ ككتلة', ico: 'layers', fn: function () { saveBlock(n); }, off: isRoot || n.type === 'block' },
      { sep: true },
      { label: 'حذف', ico: 'trash', key: 'Del', fn: del, danger: true, off: isRoot }
    ];
  }
  function buildMenu(items, root) {
    items.forEach(function (it) {
      if (it.sep) { root.appendChild(el('div', { class: 'ctx-sep' })); return; }
      var b = el('button', { type: 'button', class: 'ctx-item' + (it.danger ? ' danger' : '') + (it.off ? ' off' : ''), disabled: it.off ? 'disabled' : null }, [
        it.ico ? ic(it.ico, 15) : el('span', { class: 'ctx-ico' }), el('span', { class: 'ctx-label', text: it.label }),
        it.key ? el('kbd', { text: it.key }) : null, it.sub ? ic('next', 13) : null
      ]);
      if (it.sub) {
        var fly = el('div', { class: 'ctx-fly' }); buildMenu(it.sub(), fly); b.appendChild(fly);
      } else if (!it.off) b.addEventListener('click', function () { hideMenu(); it.fn(); });
      root.appendChild(b);
    });
  }
  function showMenu(id, x, y) {
    hideMenu();
    if (S.sel !== id) { S.sel = id; layers(); panels(); selectInCanvas(); }
    var m = el('div', { id: 'ctx-menu', class: 'ctx', role: 'menu' });
    buildMenu(menuItems(id), m);
    document.body.appendChild(m);
    var w = m.offsetWidth, h = m.offsetHeight, rtl = document.documentElement.dir === 'rtl';
    m.style.left = Math.max(4, Math.min(x, innerWidth - w - 4)) + 'px'; m.style.top = Math.max(4, Math.min(y, innerHeight - h - 4)) + 'px';
    if (x + w + 220 > innerWidth) m.classList.add('fly-left');
  }
  document.addEventListener('click', hideMenu); document.addEventListener('scroll', hideMenu, true); window.addEventListener('blur', hideMenu);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
  function hotkey(k) {
    if (k.ctrl && k.key.toLowerCase() === 'z') { k.shift ? redo() : undo(); }
    else if (k.ctrl && k.key.toLowerCase() === 'y') redo();
    else if (k.ctrl && k.key.toLowerCase() === 'd') dup();
    else if (k.ctrl && k.key.toLowerCase() === 'c') copyNode();
    else if (k.ctrl && k.key.toLowerCase() === 'x') cutNode();
    else if (k.ctrl && k.key.toLowerCase() === 'v') pasteNode();
    else if (k.ctrl && k.key.toLowerCase() === 's') save(false);
    else if (k.key === 'Delete') del();
  }
  document.addEventListener('keydown', function (e) {
    var t = e.target; if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) { if (!((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's')) return; }
    var k = { key: e.key, ctrl: e.ctrlKey || e.metaKey, shift: e.shiftKey };
    if (k.ctrl && ['z', 'y', 'd', 's', 'c', 'x', 'v'].indexOf(e.key.toLowerCase()) >= 0 || e.key === 'Delete') { e.preventDefault(); hotkey(k); }
  });
  $$('[data-bp]').forEach(function (b) { b.addEventListener('click', function () { setBp(b.getAttribute('data-bp')); }); });
  $$('[data-ltab]').forEach(function (b) { b.addEventListener('click', function () { $$('[data-ltab]').forEach(function (x) { x.classList.toggle('on', x === b); }); $('#p-add').hidden = b.dataset.ltab !== 'add'; $('#p-blocks').hidden = b.dataset.ltab !== 'blocks'; $('#p-layers').hidden = b.dataset.ltab !== 'layers'; }); });
  $$('[data-rtab]').forEach(function (b) { b.addEventListener('click', function () { $$('[data-rtab]').forEach(function (x) { x.classList.toggle('on', x === b); }); ['style', 'settings', 'page', 'fields'].forEach(function (k) { var pe = $('#p-' + k); if (pe) pe.hidden = b.dataset.rtab !== k; }); }); });
  $('#undo').addEventListener('click', undo); $('#redo').addEventListener('click', redo);
  $('#d-save').addEventListener('click', function () { save(false); });
  $('#d-publish').addEventListener('click', function () { save(true); });
  $('#tog-left').addEventListener('click', function () { $('#ed-left').classList.toggle('open'); });
  $('#tog-right').addEventListener('click', function () { $('#ed-right').classList.toggle('open'); });
  $('#d-title').addEventListener('input', function () { S.dirty = true; $('#d-saved').textContent = '● غير محفوظ'; });
  window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

  // site design system: classes and CSS variables of the active theme/imported site
  (function () {
    var ds = B.ds || { classes: [], vars: [] };
    var dc = el('datalist', { id: 'dl-classes' }, (ds.classes || []).map(function (c) { return el('option', { value: c }); }));
    var dv = el('datalist', { id: 'dl-vars' }, (ds.vars || []).map(function (v) { return el('option', { value: 'var(' + v + ')' }); }));
    document.body.appendChild(dc); document.body.appendChild(dv);
  })();
  S.pre = snap(); palette(); blocksPanel(); layers(); panels(); view();
})();
