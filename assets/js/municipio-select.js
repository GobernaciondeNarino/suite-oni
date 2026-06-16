/* Selector de municipio compartido: un <select data-man-muni-select data-target="ID">
   actualiza el data-municipio del componente destino y le pide recargar
   (lo usan [man_pronostico_select] y [man_hidrico_select]). */
(function () {
  'use strict';
  var C = window.MANcore;
  if (!C) { return; }

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('select[data-man-muni-select]'), function (sel) {
      sel.addEventListener('change', function () {
        var destino = document.getElementById(sel.getAttribute('data-target'));
        if (!destino) { return; }
        destino.setAttribute('data-municipio', sel.value);
        destino.dispatchEvent(new CustomEvent('man:recargar'));
      });
    });
  });
})();
