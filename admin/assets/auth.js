/* Login pages: show/hide password. */
(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('[data-pw-toggle]'), function (b) {
    b.addEventListener('click', function () {
      var i = b.parentNode.querySelector('input'); if (!i) return;
      var show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.classList.toggle('on', show);
    });
  });
})();