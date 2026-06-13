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
})();
