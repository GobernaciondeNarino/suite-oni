/* Admin: botones «Probar conexión» y «Sincronizar ahora» por fuente (AJAX). */
(function () {
  'use strict';
  var CFG = window.MANADMIN || { ajax: '', nonce: '' };

  function post(action, slug, cb) {
    var body = 'action=' + encodeURIComponent(action) +
      '&slug=' + encodeURIComponent(slug) +
      '&nonce=' + encodeURIComponent(CFG.nonce);
    fetch(CFG.ajax, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { cb({ success: false, data: { mensaje: 'Error de red' } }); });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || (!t.classList.contains('man-probar') && !t.classList.contains('man-sincronizar'))) {
      return;
    }
    var slug = t.getAttribute('data-slug');
    var action = t.classList.contains('man-probar') ? 'man_probar' : 'man_sincronizar';
    var span = document.querySelector('.man-resultado[data-slug="' + slug + '"]');
    if (span) { span.textContent = '… procesando'; span.style.color = '#787c82'; }
    t.disabled = true;
    post(action, slug, function (res) {
      t.disabled = false;
      var ok = res && res.success;
      var msg = (res && res.data && res.data.mensaje) ? res.data.mensaje : (ok ? 'OK' : 'Error');
      if (span) {
        span.textContent = msg;
        span.style.color = ok ? '#2e7d32' : '#c62828';
      }
    });
  });

  /* ---- Asistente de IA: probar proveedor y probar voz ---- */

  /** POST genérico con cuerpo arbitrario (los botones del asistente no usan slug). */
  function postCampos(campos, cb) {
    var partes = Object.keys(campos).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(campos[k]);
    });
    partes.push('nonce=' + encodeURIComponent(CFG.nonce));
    fetch(CFG.ajax, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: partes.join('&')
    }).then(function (r) { return r.json(); })
      .then(cb)
      .catch(function (err) {
        console.error('[Monitor Ambiental · asistente] Fallo de red al probar:', err);
        cb({ success: false, data: { mensaje: 'Error de red al contactar con el propio sitio.' } });
      });
  }

  /** Pinta el resultado y deja el detalle completo en consola. */
  function resultado(span, boton, res, etiqueta) {
    boton.disabled = false;
    var ok = res && res.success;
    var msg = (res && res.data && res.data.mensaje) ? res.data.mensaje : (ok ? 'OK' : 'Error');
    if (span) {
      span.textContent = msg;
      span.style.color = ok ? '#2e7d32' : '#c62828';
    }
    if (!ok && window.console) {
      console.error('[Monitor Ambiental · asistente] ' + etiqueta + ':', msg);
      console.debug('Respuesta completa:', res);
      console.info('El registro con la respuesta cruda del proveedor está más abajo, en «Registro de errores».');
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('man-probar-ai')) { return; }

    var prov = t.getAttribute('data-proveedor');
    var span = document.querySelector('.man-resultado-ai[data-proveedor="' + prov + '"]');
    if (span) { span.textContent = '… consultando al proveedor'; span.style.color = '#787c82'; }
    t.disabled = true;

    postCampos({ action: 'man_probar_ai', proveedor: prov }, function (res) {
      resultado(span, t, res, 'Prueba del proveedor «' + prov + '» fallida');
    });
  });

  /* Detectar modelos: comprueba la clave sin gastar consultas y rellena el
     desplegable con lo que la cuenta del proveedor dice admitir. */
  function rellenarModelos(prov, modelos) {
    var sel = document.querySelector('.man-modelo-ai[data-proveedor="' + prov + '"]');
    if (!sel || !modelos) { return; }
    var actual = sel.value;
    while (sel.options.length) { sel.remove(0); }
    Object.keys(modelos).forEach(function (id) {
      var op = document.createElement('option');
      op.value = id;
      op.textContent = modelos[id]; // textContent: el nombre viene del proveedor
      if (id === actual) { op.selected = true; }
      sel.appendChild(op);
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('man-detectar-ai')) { return; }

    var prov = t.getAttribute('data-proveedor');
    var span = document.querySelector('.man-resultado-ai[data-proveedor="' + prov + '"]');
    if (span) { span.textContent = '… consultando los modelos de la cuenta'; span.style.color = '#787c82'; }
    t.disabled = true;

    postCampos({ action: 'man_detectar_ai', proveedor: prov }, function (res) {
      resultado(span, t, res, 'Detección de modelos de «' + prov + '» fallida');
      var datos = (res && res.data) ? res.data : {};
      if (res && res.success) {
        rellenarModelos(prov, datos.modelos);
        if (datos.aviso && span) {
          span.style.color = '#b26a00';
          if (window.console) { console.warn('[Monitor Ambiental · asistente] ' + datos.mensaje); }
        }
        if (window.console) { console.info('[Monitor Ambiental · asistente] Modelos de «' + prov + '»:', datos.modelos); }
      }
    });
  });

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || t.id !== 'man-probar-voz') { return; }

    var span = document.getElementById('man-resultado-voz');
    if (span) { span.textContent = '… comprobando ElevenLabs'; span.style.color = '#787c82'; }
    t.disabled = true;

    postCampos({ action: 'man_probar_voz' }, function (res) {
      resultado(span, t, res, 'Prueba de voz fallida');
    });
  });

  /* Copiar shortcode al portapapeles (página de Elementos). */
  function copiar(texto) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(texto);
    }
    return new Promise(function (resolve, reject) {
      try {
        var ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        resolve();
      } catch (e) { reject(e); }
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target;
    if (!b || !b.classList || !b.classList.contains('man-copiar')) { return; }
    var texto = b.getAttribute('data-copy') || '';
    var original = b.textContent;
    copiar(texto).then(function () {
      b.textContent = '¡Copiado!';
      setTimeout(function () { b.textContent = original; }, 1600);
    }).catch(function () {
      b.textContent = 'Copia manual';
      setTimeout(function () { b.textContent = original; }, 1600);
    });
  });
})();
