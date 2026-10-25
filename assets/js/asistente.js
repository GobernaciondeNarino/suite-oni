/* [man_asistente] — asistente de IA sobre los datos del monitor.

   Un botón «Asistente» abre la ventana: saludo del anfitrión, preguntas
   sugeridas y barra de entrada (micrófono, texto, enviar, detener, cerrar).

   La escucha usa el reconocimiento del propio navegador, así que el audio del
   visitante no sale de su equipo; la lectura en voz alta la sintetiza el
   servidor. Todo fallo se reporta en consola con su código interno
   (MAN-AI-xxx), de modo que el operador sepa qué arreglar sin entrar al
   servidor. */
(function () {
  'use strict';
  var C = window.MANcore;
  var CFG = window.MANAI || { codigos: {}, admin: false };

  // Dictado del navegador: Chrome, Edge y Android. Ausente en Firefox e iOS.
  var Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;

  /* ================= Consola de diagnóstico ================= */

  /** Ficha de un código interno; si no está catalogado, devuelve lo mínimo. */
  function ficha(num) {
    var f = CFG.codigos[num];
    return f || { codigo: 'MAN-AI-' + num, tipo: 'desconocido', que: 'Error no catalogado.', haz: '' };
  }

  /**
   * Reporta un fallo en la consola del navegador.
   * El visitante solo ve la burbuja; aquí queda el detalle para el técnico.
   */
  function reportar(num, contexto, respuesta) {
    if (!window.console) { return; }
    var f = ficha(num);
    var abrir = console.groupCollapsed ? 'groupCollapsed' : 'log';

    try {
      console[abrir]('%c' + f.codigo + '%c ' + f.que,
        'background:#c62828;color:#fff;padding:1px 6px;border-radius:3px;font-weight:bold',
        'color:inherit');

      console.error('Qué pasó:', f.que);
      if (f.haz) { console.warn('Qué hacer:', f.haz); }
      console.info('Tipo:', f.tipo);

      if (respuesta && respuesta.diagnostico) {
        // Solo llega con sesión de administrador.
        if (respuesta.diagnostico.detalle) { console.error('Detalle del proveedor:', respuesta.diagnostico.detalle); }
        if (respuesta.diagnostico.codigo_interno) { console.error('Código del servidor:', respuesta.diagnostico.codigo_interno); }
        if (respuesta.diagnostico.pista) { console.warn('Pista del servidor:', respuesta.diagnostico.pista); }
      } else if (!CFG.admin) {
        console.info('Inicie sesión como administrador para ver aquí el detalle técnico, ' +
          'o consulte Monitor Ambiental → Asistente IA → Registro de errores.');
      }

      if (contexto) { console.debug('Contexto:', contexto); }
      if (respuesta) { console.debug('Respuesta completa:', respuesta); }
    } catch (e) { /* una consola limitada no debe romper la interfaz */ }

    if (console.groupEnd) { console.groupEnd(); }
  }

  /** Deduce el código interno de una respuesta de error del servidor. */
  function codigoDe(respuesta, pordefecto) {
    if (respuesta && respuesta.diagnostico && respuesta.diagnostico.codigo_interno) {
      var m = String(respuesta.diagnostico.codigo_interno).match(/(\d{3})$/);
      if (m) { return m[1]; }
    }
    if (respuesta && respuesta.codigo) {
      var n = String(respuesta.codigo).match(/(\d{3})$/);
      if (n) { return n[1]; }
    }
    return pordefecto;
  }

  /* ================= Montaje ================= */

  C.ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-man-asistente]'), montar);
  });

  function montar(raiz) {
    var el = {
      lanzador: raiz.querySelector('.man-asistente__lanzador'),
      ventana: raiz.querySelector('.man-asistente__ventana'),
      hilo: raiz.querySelector('.man-asistente__hilo'),
      barra: raiz.querySelector('.man-asistente__barra'),
      input: raiz.querySelector('.man-asistente__input'),
      enviar: raiz.querySelector('.man-asistente__btn--enviar'),
      detener: raiz.querySelector('.man-asistente__btn--detener'),
      cerrar: raiz.querySelector('.man-asistente__btn--cerrar'),
      mic: raiz.querySelector('.man-asistente__btn--mic'),
      sugerencias: raiz.querySelector('.man-asistente__sugerencias'),
      plantillaAudio: raiz.querySelector('.man-asistente__plantilla-audio')
    };
    if (!el.ventana || !el.barra || !el.input) { return; }

    var st = {
      municipio: raiz.getAttribute('data-municipio') || '',
      voz: raiz.getAttribute('data-voz') === '1',
      ocupado: false,
      aborta: null,   // cancela la consulta en curso
      audio: null,    // audio reproduciéndose
      rec: null       // reconocimiento de voz
    };

    /* ---- Abrir y cerrar ---- */
    function abrir() {
      el.ventana.hidden = false;
      el.lanzador.setAttribute('aria-expanded', 'true');
      raiz.classList.add('is-abierto');
      el.input.focus();
    }

    function cerrar() {
      cancelar();
      pararAudio();
      el.ventana.hidden = true;
      el.lanzador.setAttribute('aria-expanded', 'false');
      raiz.classList.remove('is-abierto');
      el.lanzador.focus();
    }

    el.lanzador.addEventListener('click', function () {
      if (el.ventana.hidden) { abrir(); } else { cerrar(); }
    });
    if (el.cerrar) { el.cerrar.addEventListener('click', cerrar); }

    // Escape cierra, pero solo si la ventana está abierta.
    raiz.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !el.ventana.hidden) { cerrar(); }
    });

    /* ---- Enviar ---- */
    el.barra.addEventListener('submit', function (e) {
      e.preventDefault();
      preguntar(el.input.value);
    });

    if (el.detener) {
      el.detener.addEventListener('click', function () {
        cancelar();
        pararAudio();
      });
    }

    /* ---- Sugerencias ---- */
    if (el.sugerencias) {
      el.sugerencias.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.man-asistente__sug') : null;
        if (!b) { return; }
        preguntar(b.getAttribute('data-pregunta'));
      });
    }

    /* ---- Micrófono ---- */
    if (el.mic && st.voz) {
      if (Reconocimiento) {
        el.mic.hidden = false;
        prepararMicrofono();
      } else {
        // No es un fallo que el visitante pueda resolver: se anota y se oculta.
        reportar('601', { navegador: navigator.userAgent });
      }
    }

    /* ---- Flujo de consulta ---- */
    function preguntar(texto) {
      texto = String(texto || '').trim();
      if (!texto || st.ocupado) { return; }

      if (el.ventana.hidden) { abrir(); }

      st.ocupado = true;
      el.input.value = '';
      ocupar(true);
      burbuja(texto, 'yo');
      var pensando = burbuja('Consultando los datos…', 'bot', true);

      // AbortController propio para que «Detener» corte de verdad.
      var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      st.aborta = ctl;

      C.rest('/ai/consulta', null, {
        metodo: 'POST',
        timeout: 45000,
        senal: ctl ? ctl.signal : null,
        cuerpo: { pregunta: texto, municipio: st.municipio }
      })
        .then(function (d) {
          quitar(pensando);

          // El endpoint responde 200 siempre; el fallo viene marcado en ok.
          if (d && d.ok === false) {
            reportar(codigoDe(d, '205'), { pregunta: texto, municipio: st.municipio }, d);
            burbuja(d.mensaje || 'No se pudo consultar al asistente.', 'bot');
            return;
          }

          var respuesta = (d && d.respuesta) ? d.respuesta : 'No recibí respuesta.';
          var nodo = burbuja(respuesta, 'bot');
          if (st.voz) { ponerEscucha(nodo, respuesta); }
        })
        .catch(function (err) {
          quitar(pensando);
          if (err && err.name === 'AbortError') { return; } // lo paró el visitante

          // El texto del navegador («Failed to fetch», «NetworkError…») va
          // solo a la consola; al visitante se le explica en español.
          var datos = { pregunta: texto, mensaje: err && err.message, estado: err && err.estado };
          if (err && err.name === 'TimeoutError') {
            reportar('605', datos);
            burbuja('El asistente tardó demasiado en responder. Intente de nuevo en un momento.', 'bot');
          } else {
            reportar('604', datos);
            burbuja('No hay conexión con el asistente en este momento. Revise su conexión e intente de nuevo.', 'bot');
          }
        })
        .then(function () {
          st.ocupado = false;
          st.aborta = null;
          ocupar(false);
        });
    }

    function cancelar() {
      if (st.aborta) {
        st.aborta.abort();
        st.aborta = null;
      }
      var prov = el.hilo.querySelector('.is-provisional');
      if (prov) { quitar(prov); }
      st.ocupado = false;
      ocupar(false);
    }

    /** Bloquea la entrada mientras hay una consulta en curso. */
    function ocupar(si) {
      el.enviar.disabled = si;
      el.input.disabled = si;
      if (el.sugerencias) { el.sugerencias.classList.toggle('is-inactivo', si); }
      actualizarDetener();
    }

    /** «Detener» solo se activa si hay algo que detener: consulta o audio. */
    function actualizarDetener() {
      if (!el.detener) { return; }
      el.detener.disabled = !(st.ocupado || (st.audio && !st.audio.paused));
    }

    /* ---- Burbujas ---- */
    function burbuja(texto, quien, provisional) {
      var div = C.el('div', 'man-asistente__msg man-asistente__msg--' + (quien === 'yo' ? 'yo' : 'bot'));
      if (provisional) { div.className += ' is-provisional'; }
      var p = C.el('p');
      p.textContent = texto;
      div.appendChild(p);
      el.hilo.appendChild(div);
      el.hilo.scrollTop = el.hilo.scrollHeight;
      return div;
    }

    function quitar(nodo) {
      if (nodo && nodo.parentNode) { nodo.parentNode.removeChild(nodo); }
    }

    /* ---- Escuchar la respuesta ---- */

    /** Error ya volcado a la consola; «publico» es el texto para el visitante. */
    function yaReportado(mensaje) {
      var e = new Error(mensaje || 'Sin audio');
      e.reportado = true;
      e.publico = mensaje || '';
      return e;
    }

    function ponerEscucha(nodo, texto) {
      if (!el.plantillaAudio) { return; }
      var btn = el.plantillaAudio.content
        ? el.plantillaAudio.content.firstElementChild.cloneNode(true)
        : null;
      if (!btn) { return; }

      btn.addEventListener('click', function () {
        pararAudio();
        btn.disabled = true;

        C.rest('/ai/voz', null, { metodo: 'POST', timeout: 45000, cuerpo: { texto: texto } })
          .then(function (d) {
            if (d && d.ok === false) {
              reportar(codigoDe(d, '503'), { texto: texto.slice(0, 80) }, d);
              throw yaReportado(d.mensaje);
            }
            if (!d || !d.audio) {
              reportar('503', { texto: texto.slice(0, 80), motivo: 'respuesta sin audio' }, d);
              throw yaReportado();
            }

            var audio = new Audio('data:' + (d.tipo || 'audio/mpeg') + ';base64,' + d.audio);
            st.audio = audio;
            audio.addEventListener('ended', function () {
              btn.disabled = false;
              st.audio = null;
              actualizarDetener();
            });
            return audio.play().then(actualizarDetener);
          })
          .catch(function (err) {
            btn.disabled = false;
            actualizarDetener();
            var nombre = err && err.name;
            var datos = { motivo: nombre, mensaje: err && err.message };

            // play() rechaza con AbortError cuando «Detener» lo pausa antes de
            // empezar: es una cancelación, no un fallo.
            if (nombre === 'AbortError') { return; }
            if (!(err && err.reportado)) {
              if (nombre === 'NotAllowedError' || nombre === 'NotSupportedError') {
                reportar('603', datos); // el navegador no quiso o no pudo reproducir
              } else if (nombre === 'TimeoutError') {
                reportar('605', datos);
              } else {
                reportar('604', datos); // no llegó respuesta de la REST
              }
            }
            btn.title = (err && err.publico) || 'No se pudo reproducir el audio';
          });
      });

      nodo.appendChild(btn);
    }

    function pararAudio() {
      if (st.audio) {
        st.audio.pause();
        st.audio = null;
      }
      // Reactiva cualquier botón de escuchar que quedara esperando.
      Array.prototype.forEach.call(el.hilo.querySelectorAll('.man-asistente__escuchar'), function (b) { b.disabled = false; });
      actualizarDetener();
    }

    /* ---- Dictado ---- */
    function prepararMicrofono() {
      var rec = new Reconocimiento();
      rec.lang = 'es-CO';
      rec.interimResults = false;
      rec.maxAlternatives = 1;
      st.rec = rec;
      var escuchando = false;

      rec.addEventListener('result', function (e) {
        var dicho = (e.results && e.results[0] && e.results[0][0]) ? e.results[0][0].transcript : '';
        if (dicho) { preguntar(dicho); }
      });

      rec.addEventListener('error', function (e) {
        parar();
        if (e && e.error === 'not-allowed') {
          reportar('602', { error: e.error });
          el.mic.hidden = true;   // sin permiso, el botón solo estorba
        } else if (e && e.error !== 'no-speech' && e.error !== 'aborted') {
          reportar('601', { error: e.error });
        }
      });

      rec.addEventListener('end', parar);

      el.mic.addEventListener('click', function () {
        if (escuchando) { rec.stop(); return; }
        try {
          rec.start();
          escuchando = true;
          el.mic.classList.add('is-escuchando');
          el.mic.setAttribute('aria-label', 'Dejar de escuchar');
        } catch (err) {
          parar();
          reportar('601', { mensaje: err && err.message });
        }
      });

      function parar() {
        escuchando = false;
        el.mic.classList.remove('is-escuchando');
        el.mic.setAttribute('aria-label', 'Preguntar por voz');
      }
    }
  }
})();
