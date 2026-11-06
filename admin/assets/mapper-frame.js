/* Runs inside the imported-site preview iframe: select elements, show bound elements, block navigation. */
(function () {
  'use strict';
  var sel = null;
  function send(m) { parent.postMessage(m, location.origin); }
  function own(el) { var t = ''; for (var i = 0; i < el.childNodes.length; i++) if (el.childNodes[i].nodeType === 3) t += el.childNodes[i].textContent; return t.replace(/\s+/g, ' ').trim(); }
  function info(el) {
    var chain = [], p = el.parentElement;
    while (p && p.getAttribute && p.hasAttribute('data-sp')) { chain.push(+p.getAttribute('data-sp')); p = p.parentElement; }
    var attrs = {}, keep = ['href', 'src', 'alt', 'title', 'value', 'placeholder', 'content', 'action', 'name', 'type', 'poster', 'data-src', 'srcset'];
    keep.forEach(function (k) { if (el.hasAttribute(k)) attrs[k] = el.getAttribute(k).slice(0, 80); });
    return { t: 'select', sp: +el.getAttribute('data-sp'), tag: el.tagName.toLowerCase(), text: own(el).slice(0, 80), attrs: attrs, chain: chain, kids: el.children.length, cls: (el.getAttribute('class') || '').slice(0, 60) };
  }
  document.addEventListener('click', function (e) {
    e.preventDefault(); e.stopPropagation();
    var el = e.target.closest('[data-sp]'); if (el) send(info(el));
  }, true);
  document.addEventListener('contextmenu', function (e) {
    e.preventDefault(); e.stopPropagation();
    var el = e.target.closest('[data-sp]'); if (!el) return;
    var m = info(el); m.t = 'ctx'; m.x = e.clientX; m.y = e.clientY; send(m);
  }, true);
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin) return;
    var m = e.data || {};
    if (m.t === 'marks') {
      Array.prototype.forEach.call(document.querySelectorAll('[data-bound]'), function (x) { x.removeAttribute('data-bound'); });
      Object.keys(m.marks || {}).forEach(function (sp) { var el = document.querySelector('[data-sp="' + sp + '"]'); if (el) el.setAttribute('data-bound', m.marks[sp]); });
    } else if (m.t === 'sel') {
      Array.prototype.forEach.call(document.querySelectorAll('.sp-sel'), function (x) { x.classList.remove('sp-sel'); });
      var el = document.querySelector('[data-sp="' + m.sp + '"]');
      if (el) { el.classList.add('sp-sel'); if (m.scroll) el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }
  });
  // Reveal content that the site's own JS would normally show (opacity:0 / lazy images), without running that JS.
  function reveal() {
    Array.prototype.forEach.call(document.querySelectorAll('img[data-src],img[data-lazy-src],img[data-original]'), function (im) {
      var s = im.getAttribute('data-src') || im.getAttribute('data-lazy-src') || im.getAttribute('data-original'); if (s && (!im.getAttribute('src') || /^data:/.test(im.getAttribute('src')))) im.setAttribute('src', s);
      im.removeAttribute('loading');
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-sp]'), function (el) {
      var cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.position === 'fixed') return;
      if (parseFloat(cs.opacity) < 0.05 && (el.textContent || '').trim() !== '' || (cs.visibility === 'hidden' && el.tagName !== 'INPUT' && (el.textContent || '').trim() !== '')) {
        el.style.setProperty('opacity', '1', 'important'); el.style.setProperty('visibility', 'visible', 'important'); el.style.setProperty('transform', 'none', 'important');
      }
    });
  }
  reveal(); window.addEventListener('load', reveal); setTimeout(reveal, 400); setTimeout(reveal, 1500);
  send({ t: 'ready' });})();
