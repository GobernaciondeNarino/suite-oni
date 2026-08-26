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
        C.copiar(url).then(ok, ok);
      });
    });
  });
})();
