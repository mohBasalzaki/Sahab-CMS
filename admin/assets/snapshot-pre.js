/* Runs first inside the capture frame. Sandboxed pages cannot use web storage, so give them an in-memory one (only when real storage throws). */
(function () {
  function mem() { var d = {}; return { getItem: function (k) { return Object.prototype.hasOwnProperty.call(d, k) ? d[k] : null; }, setItem: function (k, v) { d[k] = String(v); }, removeItem: function (k) { delete d[k]; }, clear: function () { d = {}; }, key: function (i) { return Object.keys(d)[i] || null; }, get length() { return Object.keys(d).length; } }; }
  ['localStorage', 'sessionStorage'].forEach(function (n) {
    var ok = true; try { window[n].getItem('x'); } catch (e) { ok = false; }
    if (!ok) { try { Object.defineProperty(window, n, { value: mem(), configurable: true }); } catch (e) {} }
  });
})();
