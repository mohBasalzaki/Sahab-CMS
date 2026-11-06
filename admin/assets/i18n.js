/* Sahab admin: client-side translator for text built by JavaScript (editor, mapper, tours, confirm dialogs).
   The dictionary is the same Arabic→target map the server uses (embedded as JSON); "*" in a key is a wildcard. */
(function () {
  'use strict';
  var node = document.getElementById('i18n-map'), M;
  try { M = JSON.parse(node.textContent); } catch (e) { return; }
  var pats = [], pre = [];
  Object.keys(M).forEach(function (k) {
    if (k.indexOf('*') >= 0) pats.push([new RegExp('^' + k.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '(.*?)') + '$', 'u'), M[k]]);
    else if (/[:\s(·\/]$/.test(k) && k.trim().length > 1) pre.push(k);
  });
  pre.sort(function (a, b) { return b.length - a.length; });
  var all = Object.keys(M).filter(function (k) { return k.indexOf('*') < 0 && k.length > 2; }).sort(function (a, b) { return b.length - a.length; });

  function tr(s) {
    if (!s || !/[؀-ۿ]/.test(s)) return s;
    if (M[s] !== undefined) return M[s];
    var core = s.trim();
    if (core !== s) { if (M[core] !== undefined) return s.slice(0, s.indexOf(core)) + M[core] + s.slice(s.indexOf(core) + core.length); }
    for (var i = 0; i < pats.length; i++) { var m = core.match(pats[i][0]); if (m) { var n = 0; return pats[i][1].replace(/\*/g, function () { return m[++n] || ''; }); } }
    for (var j = 0; j < pre.length; j++) if (s.indexOf(pre[j]) === 0) return M[pre[j]] + tr(s.slice(pre[j].length));
    for (var q = 0; q < all.length; q++) if (s.indexOf(all[q] + ' ') === 0) return M[all[q]] + ' ' + tr(s.slice(all[q].length + 1));   // "label side" style strings
    var parts = s.split(/(\s+\u2190\s+|\s+\/\s+|\s+\u00B7\s+|:\s+)/);   // "a  ←  b", "a / b", "a: b"
    if (parts.length > 1) { var out = parts.map(function (p, i) { return i % 2 ? p : tr(p); }).join(''); if (out !== s) return out; }
    return s;
  }
  window.__t = tr;

  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, NOSCRIPT: 1 };
  var ATTRS = ['placeholder', 'title', 'aria-label', 'alt', 'data-label', 'data-confirm'];
  function skipped(n) { for (var e = n.nodeType === 1 ? n : n.parentNode; e && e.nodeType === 1; e = e.parentNode) { if (SKIP[e.tagName] || e.hasAttribute('data-i18n-skip') || e.isContentEditable) return true; } return false; }
  function text(n) { if (skipped(n)) return; var v = n.nodeValue, t = tr(v); if (t !== v) n.nodeValue = t; }
  function attrs(e) {
    ATTRS.forEach(function (a) { if (e.hasAttribute && e.hasAttribute(a)) { var v = e.getAttribute(a), t = tr(v); if (t !== v) e.setAttribute(a, t); } });
    if (e.tagName === 'INPUT' && /^(submit|button|reset)$/.test(e.type)) { var v2 = e.value, t2 = tr(v2); if (t2 !== v2) e.value = t2; }
  }
  function walk(root) {
    if (root.nodeType === 3) { text(root); return; }
    if (root.nodeType !== 1 || skipped(root)) return;
    attrs(root);
    for (var c = root.firstChild; c; c = c.nextSibling) walk(c);
  }
  function run() {
    document.title = tr(document.title);
    walk(document.body || document.documentElement);
    new MutationObserver(function (ms) {
      ms.forEach(function (m) {
        if (m.type === 'childList') m.addedNodes.forEach(walk);
        else if (m.type === 'characterData') text(m.target);
        else if (m.type === 'attributes') attrs(m.target);
      });
    }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();

  ['alert', 'confirm', 'prompt'].forEach(function (f) { var o = window[f]; window[f] = function (m) { var a = Array.prototype.slice.call(arguments); a[0] = tr(String(m == null ? '' : m)); return o.apply(window, a); }; });
})();
