/* Runs last in the capture frame: wait for the site's own JS to build the page, then send the resulting HTML to the mapper. */
(function () {
  function send() { try { parent.postMessage({ t: 'snapshot', html: '<!DOCTYPE html>' + document.documentElement.outerHTML }, '*'); } catch (e) {} }
  var done = false; function once() { if (!done) { done = true; send(); } }
  if (document.readyState === 'complete') setTimeout(once, 1200); else window.addEventListener('load', function () { setTimeout(once, 1200); });
  setTimeout(once, 6000);
})();
