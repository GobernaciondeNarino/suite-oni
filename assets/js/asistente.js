/* [man_asistente] — asistente de IA sobre los datos del monitor.

   Texto siempre; voz cuando el navegador y la configuración lo permiten:
   la ESCUCHA usa el reconocimiento del propio navegador (no sale audio del
   equipo del visitante) y la LECTURA en voz alta la sintetiza el servidor.
   Degrada a solo texto sin avisar de carencias que el visitante no puede
   resolver. */
(function () {
  'use strict';
  var C = window.MANcore;

  // Reconocimiento de voz del navegador (Chrome, Edge, Android). Ausente en
  // Firefox y iOS: allí el botón de micrófono no se muestra.
  var Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-asistente]'), montar);
  });

  function montar(cont) {
    var panel = cont.querySelector('.man-asistente__panel');
    var hilo = cont.querySelector('.man-asistente__hilo');
    var form = cont.querySelector('.man-asistente__form');
    var input = cont.querySelector('.man-asistente__input');
    var enviar = cont.querySelector('.man-asistente__enviar');
    var mic = cont.querySelector('.man-asistente__mic');
    var toggle = cont.querySelector('.man-asistente__toggle');
    if (!panel || !form || !input) { return; }

    var estado = {
      municipio: cont.getAttribute('data-municipio') || '',
      voz: cont.getAttribute('data-voz') === '1',
      ocupado: false,
      audio: null
    };

    // Panel plegable cuando vive dentro del globo.
    if (toggle) {
      toggle.addEventListener('click', function () {
        var abierto = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!abierto));
        panel.hidden = abierto;
        if (!abierto) { input.focus(); }
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      preguntar(input.value);
    });

    // Micrófono: solo si el navegador lo soporta.
    if (mic && estado.voz && Reconocimiento) {
      mic.hidden = false;
      prepararMicrofono(mic, input, preguntar);
    }

    function preguntar(texto) {
      texto = String(texto || '').trim();
      if (!texto || estado.ocupado) { return; }

      estado.ocupado = true;
      input.value = '';
      enviar.disabled = true;
      mensaje(hilo, texto, 'yo');
      var pensando = mensaje(hilo, 'Consultando los datos…', 'bot', true);

      C.rest('/ai/consulta', null, {
        metodo: 'POST',
        timeout: 45000,
        cuerpo: { pregunta: texto, municipio: estado.municipio }
      })
        .then(function (d) {
          pensando.parentNode.removeChild(pensando);

          // El endpoint responde 200 siempre; el fallo viene marcado en ok.
          if (d && d.ok === false) {
            reportar('consulta', d, { pregunta: texto, municipio: estado.municipio });
            mensaje(hilo, d.mensaje || 'No se pudo consultar al asistente.', 'bot');
            return;
          }

          var respuesta = (d && d.respuesta) ? d.respuesta : 'No recibí respuesta.';
          var nodo = mensaje(hilo, respuesta, 'bot');
          if (estado.voz) { añadirEscucha(nodo, respuesta, estado); }
        })
        .catch(function (err) {
          pensando.parentNode.removeChild(pensando);
          reportar('consulta', { mensaje: err && err.message, estado: err && err.estado }, { pregunta: texto });
          mensaje(hilo, (err && err.message) ? err.message : 'No se pudo consultar al asistente. Intente de nuevo.', 'bot');
        })
        .then(function () {
          estado.ocupado = false;
          enviar.disabled = false;
          hilo.scrollTop = hilo.scrollHeight;
        });
    }
  }

  /* ---------- Diagnóstico en consola ----------
     Escribe el detalle del fallo donde un técnico pueda verlo sin entrar al
     servidor. El mensaje para el visitante sigue siendo el de la burbuja. */
  function reportar(paso, datos, contexto) {
    if (!window.console) { return; }
    var d = datos || {};
    var titulo = '[Monitor Ambiental · asistente] Fallo en «' + paso + '»' +
      (d.codigo ? ' — ' + d.codigo : '');

    var grupo = console.groupCollapsed ? 'groupCollapsed' : 'log';
    try {
      console[grupo](titulo);
      console.error('Mensaje mostrado al visitante:', d.mensaje || '(sin mensaje)');

      if (d.diagnostico) {
        // Solo llega si quien mira es administrador del sitio.
        console.error('Detalle del proveedor:', d.diagnostico.detalle || '(sin detalle)');
        if (d.diagnostico.codigo_proveedor) {
          console.error('Código HTTP del proveedor:', d.diagnostico.codigo_proveedor);
        }
        console.info(d.diagnostico.nota);
      } else {
        console.info('Inicie sesión como administrador para ver el detalle técnico aquí, ' +
          'o consulte Monitor Ambiental → Asistente IA → Registro de diagnóstico.');
      }

      if (d.estado) { console.error('Estado HTTP de la petición:', d.estado); }
      if (contexto && Object.keys(contexto).length) { console.debug('Contexto:', contexto); }
      console.debug('Respuesta completa:', d);
    } catch (e) { /* una consola limitada no debe romper la interfaz */ }
    if (console.groupEnd) { console.groupEnd(); }
  }

  /* ---------- Mensajes ---------- */
  function mensaje(hilo, texto, quien, provisional) {
    var p = C.el('p', 'man-asistente__msg man-asistente__msg--' + (quien === 'yo' ? 'yo' : 'bot'));
    if (provisional) { p.className += ' is-provisional'; }
    p.textContent = texto;
    hilo.appendChild(p);
    hilo.scrollTop = hilo.scrollHeight;
    return p;
  }

  /** Botón de escucha junto a una respuesta. */
  function añadirEscucha(nodo, texto, estado) {
    var btn = C.el('button', 'man-asistente__escuchar');
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Escuchar la respuesta');
    btn.innerHTML = '<span aria-hidden="true">🔊</span>';

    btn.addEventListener('click', function () {
      if (estado.audio) { estado.audio.pause(); estado.audio = null; }
      btn.disabled = true;

      C.rest('/ai/voz', null, { metodo: 'POST', timeout: 45000, cuerpo: { texto: texto } })
        .then(function (d) {
          if (d && d.ok === false) { reportar('voz', d, {}); throw new Error(d.mensaje || 'Sin audio'); }
          if (!d || !d.audio) { throw new Error('Sin audio'); }
          var audio = new Audio('data:' + (d.tipo || 'audio/mpeg') + ';base64,' + d.audio);
          estado.audio = audio;
          audio.addEventListener('ended', function () { btn.disabled = false; });
          return audio.play();
        })
        .catch(function (err) {
          btn.disabled = false;
          btn.title = (err && err.message) ? err.message : 'No se pudo reproducir el audio';
          reportar('voz', { mensaje: err && err.message }, {});
        });
    });

    nodo.appendChild(btn);
  }

  /* ---------- Micrófono del navegador ---------- */
  function prepararMicrofono(mic, input, preguntar) {
    var rec = new Reconocimiento();
    rec.lang = 'es-CO';
    rec.interimResults = false;
    rec.maxAlternatives = 1;
    var escuchando = false;

    rec.addEventListener('result', function (e) {
      var dicho = (e.results && e.results[0] && e.results[0][0]) ? e.results[0][0].transcript : '';
      if (dicho) {
        input.value = dicho;
        preguntar(dicho);
      }
    });

    rec.addEventListener('error', function (e) {
      detener();
      // 'not-allowed' = permiso denegado: no tiene sentido insistir.
      if (e && e.error === 'not-allowed') { mic.hidden = true; }
    });

    rec.addEventListener('end', detener);

    mic.addEventListener('click', function () {
      if (escuchando) { rec.stop(); return; }
      try {
        rec.start();
        escuchando = true;
        mic.classList.add('is-escuchando');
        mic.setAttribute('aria-label', 'Dejar de escuchar');
      } catch (err) { detener(); }
    });

    function detener() {
      escuchando = false;
      mic.classList.remove('is-escuchando');
      mic.setAttribute('aria-label', 'Hablar al asistente');
    }
  }
})();
