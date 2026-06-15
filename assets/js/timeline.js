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
        detail: { mes: s.mes, oni: s.oni, fase: s.fase, tipo: s.proyectado ? 'proyectado' : 'observado', indice: i }
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
})();
