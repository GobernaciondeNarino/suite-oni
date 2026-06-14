/* [man_timeline] — slider de meses ONI. Controla el globo vía evento 'man:mes'. */
(function () {
  'use strict';
  var C = window.MANcore;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-timeline]'), init);
  });

  function init(cont) {
    var inicio = cont.getAttribute('data-inicio') || '';
    var fin = cont.getAttribute('data-fin') || '';
    C.rest('/oni')
      .then(function (d) {
        var serie = d.serie || [];
        // Acota la ventana a [inicio, fin] (p. ej. 2026-03 → 2027-03). El /oni
        // puede traer la serie completa de NOAA; aquí solo mostramos la ventana.
        if (inicio && fin) {
          var win = serie.filter(function (s) { return s.mes >= inicio && s.mes <= fin; });
          if (win.length) { serie = win; }
        }
        montar(cont, serie);
      })
      .catch(function () { C.error(cont, 'No se pudo cargar la línea de tiempo ONI.', function () { init(cont); }); });
  }

  function montar(cont, serie) {
    if (!serie.length) { C.error(cont, 'Sin serie ONI disponible.'); return; }

    var slider = cont.querySelector('.man-timeline__slider');
    var lblMes = cont.querySelector('.man-timeline__mes');
    var lblOni = cont.querySelector('.man-timeline__oni');
    var btnPlay = cont.querySelector('[data-accion="play"]');
    var marcas = cont.querySelector('.man-timeline__marcas');

    slider.max = serie.length - 1;

    // Marcas de meses (observado / proyectado), clicables.
    if (marcas) {
      marcas.style.gridTemplateColumns = 'repeat(' + serie.length + ', 1fr)';
      marcas.innerHTML = '';
      serie.forEach(function (s, i) {
        var li = document.createElement('li');
        var nombre = mesLargo(s.mes).split(' ')[0].slice(0, 3);
        var anio = String(s.mes).split('-')[0];
        li.textContent = (i === 0 || /-01$/.test(s.mes)) ? (nombre + ' ' + anio.slice(2)) : nombre;
        li.className = 'man-timeline__marca ' + (s.proyectado ? 'es-proy' : 'es-obs');
        li.setAttribute('data-i', i);
        li.addEventListener('click', function () { set(i); });
        marcas.appendChild(li);
      });
    }

    // Posición inicial: enero de 2026 (inicio del rango ene-2026 → mar-2027).
    var idx = 0;

    var velocidad = cont.querySelector('.man-timeline__velocidad select');
    var intervalo = velocidad ? parseInt(velocidad.value, 10) : 1200;
    var playing = false, timer = null;

    function arrancarTimer() {
      clearInterval(timer);
      timer = setInterval(function () {
        var v = parseInt(slider.value, 10) + 1;
        if (v > serie.length - 1) { v = 0; }
        set(v);
      }, intervalo);
    }
    if (velocidad) {
      velocidad.addEventListener('change', function () {
        intervalo = parseInt(velocidad.value, 10) || 1200;
        if (playing) { arrancarTimer(); }
      });
    }

    function set(i) {
      i = Math.max(0, Math.min(serie.length - 1, i));
      slider.value = i;
      var s = serie[i];
      lblMes.textContent = mesLargo(s.mes);
      lblOni.textContent = 'ONI ' + (s.oni >= 0 ? '+' : '') + C.num(s.oni, 1) + ' · ' + s.fase;
      if (marcas) {
        Array.prototype.forEach.call(marcas.querySelectorAll('.man-timeline__marca'), function (li) {
          li.classList.toggle('activo', Number(li.getAttribute('data-i')) === i);
        });
      }
      window.dispatchEvent(new CustomEvent('man:mes', {
        detail: { mes: s.mes, oni: s.oni, fase: s.fase, tipo: s.proyectado ? 'proyectado' : 'observado', indice: i }
      }));
    }

    slider.addEventListener('input', function () { set(parseInt(slider.value, 10)); });
    cont.querySelector('[data-accion="anterior"]').addEventListener('click', function () { set(parseInt(slider.value, 10) - 1); });
    cont.querySelector('[data-accion="siguiente"]').addEventListener('click', function () { set(parseInt(slider.value, 10) + 1); });

    btnPlay.addEventListener('click', function () {
      playing = !playing;
      btnPlay.textContent = playing ? '⏸' : '▶';
      if (playing) { arrancarTimer(); } else { clearInterval(timer); }
    });

    set(idx);
  }

  function mesLargo(mes) {
    var M = { '01': 'Enero', '02': 'Febrero', '03': 'Marzo', '04': 'Abril', '05': 'Mayo', '06': 'Junio', '07': 'Julio', '08': 'Agosto', '09': 'Septiembre', '10': 'Octubre', '11': 'Noviembre', '12': 'Diciembre' };
    var p = String(mes).split('-');
    return (M[p[1]] || p[1]) + ' ' + p[0];
  }
})();
