/* Light by default. Dark only when the user opted in (stored locally). Loaded blocking in <head> to avoid a flash. */
(function () {
  try { if (localStorage.getItem('sahab-theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); } catch (e) {}
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-theme-toggle]'); if (!b) return;
    var dark = document.documentElement.getAttribute('data-theme') !== 'dark';
    if (dark) document.documentElement.setAttribute('data-theme', 'dark'); else document.documentElement.removeAttribute('data-theme');
    try { localStorage.setItem('sahab-theme', dark ? 'dark' : 'light'); } catch (er) {}
  });
})();
