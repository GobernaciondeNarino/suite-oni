/* [man_timeline] — slider de meses ONI. Controla el globo vía evento 'man:mes'. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-timeline]'), init);
  });

  function init(cont) {
    C.rest('/oni')
      .then(function (d) { montar(cont, d.serie || []); })
      .catch(function () { C.error(cont, 'No se pudo cargar la línea de tiempo ONI.', function () { init(cont); }); });
  }

  function montar(cont, serie) {
    if (!serie.length) { C.error(cont, 'Sin serie ONI disponible.'); return; }

    var slider = cont.querySelector('.man-timeline__slider');
    var lblMes = cont.querySelector('.man-timeline__mes');
    var lblOni = cont.querySelector('.man-timeline__oni');
    var btnPlay = cont.querySelector('[data-accion="play"]');

    slider.max = serie.length - 1;

    // Posición inicial: el último mes observado.
    var idx = serie.length - 1;
    for (var i = 0; i < serie.length; i++) {
      if (serie[i].proyectado) { idx = Math.max(0, i - 1); break; }
    }

    var playing = false, timer = null;

    function set(i) {
      i = Math.max(0, Math.min(serie.length - 1, i));
      slider.value = i;
      var s = serie[i];
      lblMes.textContent = mesLargo(s.mes);
      lblOni.textContent = 'ONI ' + (s.oni >= 0 ? '+' : '') + C.num(s.oni, 1) + ' · ' + s.fase;
      window.dispatchEvent(new CustomEvent('man:mes', {
        detail: { mes: s.mes, oni: s.oni, fase: s.fase, indice: i }
      }));
    }

    slider.addEventListener('input', function () { set(parseInt(slider.value, 10)); });
    cont.querySelector('[data-accion="anterior"]').addEventListener('click', function () { set(parseInt(slider.value, 10) - 1); });
    cont.querySelector('[data-accion="siguiente"]').addEventListener('click', function () { set(parseInt(slider.value, 10) + 1); });

    btnPlay.addEventListener('click', function () {
      playing = !playing;
      btnPlay.textContent = playing ? '⏸' : '▶';
      if (playing) {
        timer = setInterval(function () {
          var v = parseInt(slider.value, 10) + 1;
          if (v > serie.length - 1) { v = 0; }
          set(v);
        }, 1200);
      } else {
        clearInterval(timer);
      }
    });

    set(idx);
  }

  function mesLargo(mes) {
    var M = { '01': 'Enero', '02': 'Febrero', '03': 'Marzo', '04': 'Abril', '05': 'Mayo', '06': 'Junio', '07': 'Julio', '08': 'Agosto', '09': 'Septiembre', '10': 'Octubre', '11': 'Noviembre', '12': 'Diciembre' };
    var p = String(mes).split('-');
    return (M[p[1]] || p[1]) + ' ' + p[0];
  }
})();
