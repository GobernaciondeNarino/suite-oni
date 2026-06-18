/* [man_animacion] — esquema animado del Pacífico ecuatorial con Anime.js.
   Explica el mecanismo ENSO comparando Neutral / El Niño / La Niña: vientos
   alisios, piscina de agua cálida, termoclina, afloramiento y convección
   (lluvias). El globo 3D usa Three.js; este módulo usa Anime.js (v3, global).
   Si Anime.js no estuviera disponible, aplica los estados sin transición. */
(function () {
  'use strict';
  var C = window.MANcore;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Parámetros por fase (unidades del viewBox 720×340).
  var FASES = {
    neutral: {
      warmTx: 0, warmSx: 1, convTx: 0, windsOp: 1, windsSx: 1, windsEOp: 0,
      coldOp: 0.6, coldSx: 1, thermoY2: 168,
      texto: 'Condiciones neutrales: los vientos alisios soplan hacia el oeste y acumulan agua cálida en el Pacífico occidental. Frente a Sudamérica hay afloramiento de agua fría y la termoclina se inclina (profunda al oeste, somera al este).'
    },
    el_nino: {
      warmTx: 150, warmSx: 1.5, convTx: 165, windsOp: 0.22, windsSx: 0.6, windsEOp: 0.9,
      coldOp: 0.12, coldSx: 0.6, thermoY2: 215,
      texto: 'El Niño: los alisios se debilitan o se invierten; el agua cálida se desplaza al este, la termoclina se hunde y se reduce el afloramiento. Las lluvias se trasladan al Pacífico central-oriental. En la zona andina de Nariño suele asociarse a déficit de lluvia (sequía e incendios) y en el litoral, a más oleaje.'
    },
    la_nina: {
      warmTx: -55, warmSx: 0.82, convTx: -55, windsOp: 1, windsSx: 1.2, windsEOp: 0,
      coldOp: 1, coldSx: 1.15, thermoY2: 138,
      texto: 'La Niña: los alisios se intensifican, el agua cálida se concentra en el oeste y se refuerza el afloramiento frío en el este. En Nariño suele favorecer lluvias por encima del promedio (riesgo de deslizamientos y crecientes).'
    }
  };
  var ORDEN = ['neutral', 'el_nino', 'la_nina'];

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-animacion]'), init);
  });

  function init(cont) {
    var escena = cont.querySelector('.man-animacion__escena');
    if (!escena) { return; }
    escena.innerHTML = svg();
    var refs = {
      warm: escena.querySelector('.ani-warm'),
      cold: escena.querySelector('.ani-cold'),
      winds: escena.querySelector('.ani-winds'),
      windsE: escena.querySelector('.ani-windsE'),
      conv: escena.querySelector('.ani-conv'),
      thermo: escena.querySelector('.ani-thermo')
    };
    // Orígenes de transformación para escalados coherentes.
    origen(refs.warm, 'center'); origen(refs.cold, '650px center');
    origen(refs.winds, 'right center'); origen(refs.conv, 'center');

    var narr = cont.querySelector('.man-animacion__narracion');
    var botones = cont.querySelectorAll('.man-animacion__controles [data-fase]');
    var estado = cont.getAttribute('data-estado') || 'el_nino';
    var autoplay = cont.getAttribute('data-autoplay') !== 'no' && !reduce;
    var timer = null;

    function ir(fase, animar) {
      if (!FASES[fase]) { fase = 'neutral'; }
      estado = fase;
      aplicar(refs, FASES[fase], animar);
      if (narr) { narr.textContent = FASES[fase].texto; }
      Array.prototype.forEach.call(botones, function (b) {
        var on = b.getAttribute('data-fase') === fase;
        b.classList.toggle('man-btn--primario', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    function parar() { if (timer) { clearInterval(timer); timer = null; } }

    Array.prototype.forEach.call(botones, function (b) {
      b.addEventListener('click', function () { parar(); ir(b.getAttribute('data-fase'), !reduce); });
    });

    ir(estado, false); // estado inicial sin animación

    if (autoplay) {
      var i = ORDEN.indexOf(estado);
      timer = setInterval(function () {
        i = (i + 1) % ORDEN.length;
        ir(ORDEN[i], true);
      }, 3400);
      // Pausa el ciclo si el componente sale del viewport.
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (ents) {
          ents.forEach(function (en) { if (!en.isIntersecting) { parar(); } });
        }).observe(cont);
      }
    }
  }

  /* Aplica un estado, con o sin animación. */
  function aplicar(r, st, animar) {
    if (animar && window.anime) {
      anime({ targets: r.warm, translateX: st.warmTx, scaleX: st.warmSx, duration: 1100, easing: 'easeInOutQuad' });
      anime({ targets: r.conv, translateX: st.convTx, duration: 1100, easing: 'easeInOutQuad' });
      anime({ targets: r.winds, opacity: st.windsOp, scaleX: st.windsSx, duration: 900, easing: 'easeInOutQuad' });
      anime({ targets: r.windsE, opacity: st.windsEOp, duration: 900, easing: 'easeInOutQuad' });
      anime({ targets: r.cold, opacity: st.coldOp, scaleX: st.coldSx, duration: 1000, easing: 'easeInOutQuad' });
      anime({ targets: r.thermo, y2: st.thermoY2, duration: 1100, easing: 'easeInOutSine' });
    } else {
      r.warm.style.transform = 'translateX(' + st.warmTx + 'px) scaleX(' + st.warmSx + ')';
      r.conv.style.transform = 'translateX(' + st.convTx + 'px)';
      r.winds.style.transform = 'scaleX(' + st.windsSx + ')'; r.winds.style.opacity = st.windsOp;
      r.windsE.style.opacity = st.windsEOp;
      r.cold.style.transform = 'scaleX(' + st.coldSx + ')'; r.cold.style.opacity = st.coldOp;
      r.thermo.setAttribute('y2', st.thermoY2);
    }
  }

  function origen(el, val) {
    if (!el) { return; }
    el.style.transformBox = 'fill-box';
    el.style.transformOrigin = val;
  }

  /* SVG del esquema (cross-section del Pacífico ecuatorial). */
  function svg() {
    return '' +
      '<svg viewBox="0 0 720 340" class="man-animacion__svg" preserveAspectRatio="xMidYMid meet" aria-hidden="true">' +
      '<defs>' +
      '<linearGradient id="ani-cielo" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#eef3f8"/><stop offset="1" stop-color="#d6e3ee"/></linearGradient>' +
      '<linearGradient id="ani-mar" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2b6491"/><stop offset="1" stop-color="#103a59"/></linearGradient>' +
      '<radialGradient id="ani-calido" cx="0.5" cy="0.4" r="0.6"><stop offset="0" stop-color="#e8731a"/><stop offset="1" stop-color="#c0392b" stop-opacity="0.12"/></radialGradient>' +
      '</defs>' +
      '<rect x="0" y="0" width="720" height="120" fill="url(#ani-cielo)"/>' +
      '<circle cx="62" cy="40" r="11" fill="#f2c14e" opacity="0.8"/>' +
      '<rect x="0" y="118" width="720" height="200" fill="url(#ani-mar)"/>' +
      // continentes
      '<path d="M0,118 L70,118 L60,180 L0,200 Z" fill="#3c6b3a"/>' +
      '<path d="M720,118 L650,118 L662,210 L720,230 Z" fill="#6a8f43"/>' +
      '<circle cx="676" cy="150" r="5" fill="#ffd500" stroke="#003087" stroke-width="2"/>' +
      '<text x="676" y="138" font-size="10" fill="#1a1f2c" text-anchor="middle" font-weight="600">Nariño</text>' +
      // termoclina (línea animada)
      '<line class="ani-thermo" x1="70" y1="250" x2="650" y2="168" stroke="#9fd0ff" stroke-width="3" stroke-dasharray="7 5"/>' +
      '<text x="120" y="244" font-size="10" fill="#cfe6ff">termoclina</text>' +
      // afloramiento frío (este)
      '<g class="ani-cold"><ellipse cx="630" cy="178" rx="70" ry="30" fill="#1565c0" opacity="0.55"/>' +
      '<path d="M632,210 q-6,-22 0,-44 q6,22 0,44" fill="none" stroke="#bcdcff" stroke-width="2" opacity="0.7"/></g>' +
      // piscina cálida (oeste, animada)
      '<g class="ani-warm"><ellipse cx="250" cy="166" rx="120" ry="34" fill="url(#ani-calido)"/></g>' +
      // vientos alisios (oeste) — grupo animado
      '<g class="ani-winds">' + flecha(560, 100, -56) + flecha(430, 100, -56) + flecha(300, 100, -56) + '</g>' +
      // viento anómalo del oeste (sopla al este) — aparece en El Niño
      '<g class="ani-windsE" opacity="0">' + flecha(300, 100, 64, '#e8731a') + '</g>' +
      // convección: nubes + lluvia (sobre la piscina cálida)
      '<g class="ani-conv">' + nube(250, 72) + '</g>' +
      // etiquetas estáticas
      '<text x="120" y="306" font-size="11" fill="#eaf4ff" text-anchor="middle">Pacífico occidental</text>' +
      '<text x="600" y="306" font-size="11" fill="#eaf4ff" text-anchor="middle">Pacífico oriental</text>' +
      '<text x="360" y="20" font-size="9" fill="#5a738a" text-anchor="middle">Vientos alisios →← (corte ecuatorial, esquema didáctico)</text>' +
      '</svg>';
  }

  /* Flecha horizontal: (x,y) origen, len con signo (negativo = hacia el oeste). */
  function flecha(x, y, len, color) {
    color = color || '#ffffff';
    var x2 = x + len;
    var dir = len < 0 ? -1 : 1;
    var tip = x2 + dir * 8;
    var tri = tip + ',' + y + ' ' + x2 + ',' + (y - 4) + ' ' + x2 + ',' + (y + 4);
    return '<line x1="' + x + '" y1="' + y + '" x2="' + x2 + '" y2="' + y + '" stroke="' + color + '" stroke-width="2.2" stroke-linecap="round" opacity="0.92"/>' +
      '<polygon points="' + tri + '" fill="' + color + '"/>';
  }

  /* Nube sobria (forma plana) con lluvia tenue. */
  function nube(cx, cy) {
    var n = '<g fill="#e9eef3" opacity="0.92">' +
      '<rect x="' + (cx - 34) + '" y="' + (cy - 6) + '" width="68" height="20" rx="10"/>' +
      '<circle cx="' + (cx - 16) + '" cy="' + (cy - 4) + '" r="12"/>' +
      '<circle cx="' + (cx + 14) + '" cy="' + (cy - 6) + '" r="14"/></g>';
    for (var i = -1; i <= 1; i++) {
      var lx = cx + i * 15;
      n += '<line x1="' + lx + '" y1="' + (cy + 16) + '" x2="' + (lx - 3) + '" y2="' + (cy + 34) + '" stroke="#8fb8d6" stroke-width="1.5" stroke-linecap="round"/>';
    }
    return n;
  }
})();
