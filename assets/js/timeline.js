/* [man_timeline] — barra de tiempo del fenómeno (diseño del mockup): identidad,
   chip de estado, controles, velocidad, menú "Capas", slider con gradiente ENSO,
   divisor observado/proyectado y marcas de mes. Controla el globo vía 'man:mes'
   y las capas vía 'man:capa'. Autoplay al cargar (respeta prefers-reduced-motion). */
(function () {
  'use strict';
  var C = window.MANcore;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var MESES = { '01': 'Ene', '02': 'Feb', '03': 'Mar', '04': 'Abr', '05': 'May', '06': 'Jun', '07': 'Jul', '08': 'Ago', '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dic' };

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-timeline]'), init);
  });

  function init(cont) {
    var inicio = cont.getAttribute('data-inicio') || '';
    var fin = cont.getAttribute('data-fin') || '';
    C.rest('/oni')
      .then(function (d) {
        var serie = d.serie || [];
        if (inicio && fin) {
          var win = serie.filter(function (s) { return s.mes >= inicio && s.mes <= fin; });
          if (win.length) { serie = win; }
        }
        montar(cont, serie, d.estado || '');
      })
      .catch(function () { C.error(cont, 'No se pudo cargar la línea de tiempo ONI.', function () { init(cont); }); });
  }

  function montar(cont, serie, estado) {
    if (!serie.length) { C.error(cont, 'Sin serie ONI disponible.'); return; }

    var rango = cont.querySelector('.man-timeline__rango');
    var marcas = cont.querySelector('.man-timeline__marcas');
    var divisor = cont.querySelector('.man-timeline__divisor');
    var chip = cont.querySelector('.man-timeline__estado-chip');
    var btnPlay = cont.querySelector('[data-accion="play"]');
    var velocidad = cont.querySelector('.man-timeline__velocidad select');

    rango.max = serie.length - 1;
    if (chip) { chip.textContent = estado || 'Fenómeno ENSO · Nariño'; }

    // Marcas de mes (observado/proyectado), clicables y operables por teclado.
    marcas.style.gridTemplateColumns = 'repeat(' + serie.length + ', 1fr)';
    marcas.innerHTML = '';
    serie.forEach(function (s, i) {
      var li = document.createElement('li');
      var nombre = MESES[String(s.mes).split('-')[1]] || '';
      var anio = String(s.mes).split('-')[0];
      li.textContent = (i === 0 || /-01$/.test(s.mes)) ? (nombre + ' ' + anio.slice(2)) : nombre;
      li.className = 'man-timeline__marca ' + (s.proyectado ? 'es-proy' : 'es-obs');
      li.setAttribute('data-i', i);
      li.setAttribute('role', 'button');
      li.setAttribute('tabindex', '0');
      li.setAttribute('aria-label', mesLargo(s.mes) + (s.proyectado ? ' (proyectado)' : ' (observado)'));
      li.addEventListener('click', function () { pausar(); set(i); });
      li.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pausar(); set(i); } });
      marcas.appendChild(li);
    });

    // Divisor observado/proyectado.
    var idxUltObs = -1;
    serie.forEach(function (s, i) { if (!s.proyectado) { idxUltObs = i; } });
    if (divisor && idxUltObs >= 0 && idxUltObs < serie.length - 1) {
      divisor.style.left = (((idxUltObs + 0.5) / serie.length) * 100) + '%';
      divisor.hidden = false;
    } else if (divisor) {
      divisor.hidden = true;
    }

    // Franja "mapa de calor": cada mes coloreado por su ONI (azul frío → rojo cálido).
    var heat = cont.querySelector('.man-timeline__heat');
    if (heat) {
      var stops = serie.map(function (s, i) {
        var pos = serie.length > 1 ? (i / (serie.length - 1)) * 100 : 50;
        return oniColor(s.oni) + ' ' + pos.toFixed(1) + '%';
      });
      heat.style.background = serie.length > 1
        ? 'linear-gradient(to right, ' + stops.join(', ') + ')'
        : oniColor(serie[0] ? serie[0].oni : 0);
    }

    var intervalo = velocidad ? parseInt(velocidad.value, 10) : 1200;
    var playing = false, timer = null;

    function set(i) {
      i = Math.max(0, Math.min(serie.length - 1, i));
      rango.value = i;
      var s = serie[i];
      Array.prototype.forEach.call(marcas.querySelectorAll('.man-timeline__marca'), function (li) {
        var on = Number(li.getAttribute('data-i')) === i;
        li.classList.toggle('activo', on);
        li.setAttribute('aria-current', on ? 'true' : 'false');
      });
      window.dispatchEvent(new CustomEvent('man:mes', {
        detail: {
          mes: s.mes, oni: s.oni, fase: s.fase, tipo: s.proyectado ? 'proyectado' : 'observado',
          prob: s.prob, resumen: s.resumen, indice: i
        }
      }));
    }

    function arrancar() {
      clearInterval(timer);
      timer = setInterval(function () {
        var v = parseInt(rango.value, 10) + 1;
        if (v > serie.length - 1) { v = 0; }
        set(v);
      }, intervalo);
    }
    function reproducir() { playing = true; btnPlay.textContent = '⏸'; btnPlay.classList.add('is-playing'); arrancar(); }
    function pausar() { playing = false; btnPlay.textContent = '▶'; btnPlay.classList.remove('is-playing'); clearInterval(timer); }

    rango.addEventListener('input', function () { pausar(); set(parseInt(rango.value, 10)); });
    cont.querySelector('[data-accion="anterior"]').addEventListener('click', function () { pausar(); set(parseInt(rango.value, 10) - 1); });
    cont.querySelector('[data-accion="siguiente"]').addEventListener('click', function () { pausar(); set(parseInt(rango.value, 10) + 1); });
    btnPlay.addEventListener('click', function () { if (playing) { pausar(); } else { reproducir(); } });
    if (velocidad) {
      velocidad.addEventListener('change', function () {
        intervalo = parseInt(velocidad.value, 10) || 1200;
        if (playing) { arrancar(); }
      });
    }

    // Menú "Capas": cada checkbox activa/desactiva una capa del globo (man:capa).
    Array.prototype.forEach.call(cont.querySelectorAll('.man-timeline__capas-opcion input[data-capa]'), function (chk) {
      chk.addEventListener('change', function () {
        window.dispatchEvent(new CustomEvent('man:capa', { detail: { capa: chk.getAttribute('data-capa'), visible: chk.checked } }));
      });
    });

    set(0); // posición inicial: enero/marzo de 2026 (inicio de la ventana)

    // Autoplay al cargar (salvo prefers-reduced-motion).
    if (cont.getAttribute('data-autoplay') !== 'no' && !reduce) {
      setTimeout(reproducir, 400);
    }
  }

  function mesLargo(mes) {
    var M = { '01': 'Enero', '02': 'Febrero', '03': 'Marzo', '04': 'Abril', '05': 'Mayo', '06': 'Junio', '07': 'Julio', '08': 'Agosto', '09': 'Septiembre', '10': 'Octubre', '11': 'Noviembre', '12': 'Diciembre' };
    var p = String(mes).split('-');
    return (M[p[1]] || p[1]) + ' ' + p[0];
  }

  /* ---- Rampa de color del ONI (diverging azul frío → pálido → rojo cálido) ---- */
  function hex2rgb(h) { h = h.replace('#', ''); return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)]; }
  function mix(c1, c2, t) {
    var a = hex2rgb(c1), b = hex2rgb(c2);
    return 'rgb(' + Math.round(a[0] + (b[0] - a[0]) * t) + ',' + Math.round(a[1] + (b[1] - a[1]) * t) + ',' + Math.round(a[2] + (b[2] - a[2]) * t) + ')';
  }
  function ramp(t, stops) {
    for (var i = 0; i < stops.length - 1; i++) {
      if (t <= stops[i + 1][0]) {
        var lo = stops[i], hi = stops[i + 1];
        var f = (t - lo[0]) / ((hi[0] - lo[0]) || 1);
        return mix(lo[1], hi[1], Math.max(0, Math.min(1, f)));
      }
    }
    return stops[stops.length - 1][1];
  }
  function oniColor(o) {
    o = +o || 0;
    if (o >= 0) { return ramp(Math.min(1, o / 2.5), [[0, '#eef3e8'], [0.2, '#ffe08a'], [0.45, '#f59e0b'], [0.7, '#ea580c'], [1, '#b91c1c']]); }
    return ramp(Math.min(1, -o / 2.5), [[0, '#eef3e8'], [0.3, '#bfe0f5'], [0.6, '#5aa9e6'], [1, '#1d4ed8']]);
  }
})();
