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
