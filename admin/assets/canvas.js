/* Designer canvas (runs inside the iframe). Renders server-made HTML and reports selection / drops to the editor. */
(function () {
  'use strict';
  var root = document.getElementById('root'), css = document.getElementById('dz-css'), sel = null;
  var CONT = ['section', 'container', 'div', 'list', 'link', 'button'];
  function send(m) { parent.postMessage(m, location.origin); }
  function $$(s) { return Array.prototype.slice.call(document.querySelectorAll(s)); }
  function mark() {
    $$('.dz-sel').forEach(function (x) { x.classList.remove('dz-sel'); });
    if (sel && sel !== 'root') $$('[data-eid="' + sel + '"]').forEach(function (x) { x.classList.add('dz-sel'); });
    var dz = document.querySelector('.dz'); if (dz) dz.classList.toggle('dz-sel', sel === 'root');
  }
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin) return;
    var m = e.data || {};
    if (m.t === 'render') { root.innerHTML = m.html; css.textContent = m.css; sel = m.sel; mark(); }
    else if (m.t === 'sel') { sel = m.id; mark(); }
  });
  document.addEventListener('click', function (e) {
    e.preventDefault();
    var el = e.target.closest('[data-eid]');
    send({ t: 'select', id: el ? el.getAttribute('data-eid') : 'root' });
  }, true);
  document.addEventListener('contextmenu', function (e) {
    e.preventDefault();
    var el = e.target.closest('[data-eid]');
    send({ t: 'ctx', id: el ? el.getAttribute('data-eid') : 'root', x: e.clientX, y: e.clientY });
  }, true);
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);
  document.addEventListener('keydown', function (e) {
    if (e.target.isContentEditable) return;
    send({ t: 'key', key: e.key, ctrl: e.ctrlKey || e.metaKey, shift: e.shiftKey });
    if (e.ctrlKey || e.metaKey || e.key === 'Delete') e.preventDefault();
  });

  // ---- drag & drop from the palette
  function clear() { $$('.dz-drop-in,.dz-drop-before,.dz-drop-after').forEach(function (x) { x.classList.remove('dz-drop-in', 'dz-drop-before', 'dz-drop-after'); }); }
  function where(e) {
    var el = e.target.closest ? e.target.closest('[data-eid]') : null;
    if (!el) return { id: 'root', pos: 'inside', el: null };
    var r = el.getBoundingClientRect(), y = (e.clientY - r.top) / Math.max(1, r.height), t = el.getAttribute('data-etype');
    var pos = CONT.indexOf(t) >= 0 ? (y < 0.25 ? 'before' : y > 0.75 ? 'after' : 'inside') : (y < 0.5 ? 'before' : 'after');
    return { id: el.getAttribute('data-eid'), pos: pos, el: el };
  }
  document.addEventListener('dragover', function (e) {
    e.preventDefault(); clear();
    var w = where(e);
    if (w.el) w.el.classList.add(w.pos === 'inside' ? 'dz-drop-in' : w.pos === 'before' ? 'dz-drop-before' : 'dz-drop-after');
  });
  document.addEventListener('dragleave', function (e) { if (!e.relatedTarget) clear(); });
  document.addEventListener('drop', function (e) {
    e.preventDefault(); clear();
    var data = e.dataTransfer.getData('text/plain') || '';
    if (data.indexOf('sahab:') !== 0) return;
    var w = where(e);
    send({ t: 'drop', et: data.slice(6), target: w.id, pos: w.pos });
  });
  send({ t: 'ready' });
})();
