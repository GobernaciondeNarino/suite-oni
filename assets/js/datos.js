/* [man_datos] — botón de datos abiertos: copiar URL de la API al portapapeles. */
(function () {
  'use strict';
  var C = window.MANcore;
  C.ready(function () {
    var botones = document.querySelectorAll('[data-man-datos] [data-copiar]');
    Array.prototype.forEach.call(botones, function (btn) {
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-copiar');
        var original = btn.textContent;
        var ok = function () {
          btn.textContent = '¡URL copiada!';
          setTimeout(function () { btn.textContent = original; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(ok, function () { fallback(url); ok(); });
        } else {
          fallback(url);
          ok();
        }
      });
    });
  });

  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* ignorado */ }
    document.body.removeChild(ta);
  }
})();
