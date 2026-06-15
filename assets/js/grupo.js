/* Bus de estado por "grupo" para componentes composables.
   Permite que [man_grafico], [man_filtro] y [man_panel] que comparten el mismo
   atributo grupo="..." se comuniquen: un filtro cambia el estado (vista/tipo/
   mes) y el gráfico se re-renderiza; el panel muestra los detalles del gráfico
   vigente. Sin dependencias; expone window.MANGrupo. */
(function () {
  'use strict';
  var grupos = {};

  function ensure(g) {
    if (!grupos[g]) { grupos[g] = { estado: {}, subs: [], psubs: [] }; }
    return grupos[g];
  }

  window.MANGrupo = {
    // Siembra el estado inicial (la primera llamada gana; las siguientes no pisan).
    init: function (g, estado) {
      var x = ensure(g);
      x.estado = Object.assign({}, estado || {}, x.estado);
      return x.estado;
    },
    get: function (g) { return ensure(g).estado; },
    // Cambia el estado (query) y notifica a los suscriptores.
    set: function (g, partial) {
      var x = ensure(g);
      x.estado = Object.assign({}, x.estado, partial || {});
      x.subs.forEach(function (fn) { try { fn(x.estado); } catch (e) { /* aislado */ } });
    },
    // Suscribe a cambios de estado (query). Devuelve el estado actual.
    subscribe: function (g, fn) {
      var x = ensure(g);
      x.subs.push(fn);
      return x.estado;
    },
    // El gráfico publica su payload (datos + metadatos) tras cada fetch.
    payload: function (g, payload) {
      var x = ensure(g);
      x.ultimoPayload = payload;
      x.psubs.forEach(function (fn) { try { fn(payload, x.estado); } catch (e) { /* aislado */ } });
    },
    // Suscribe a payloads (paneles). Recibe el último payload si ya existe.
    onPayload: function (g, fn) {
      var x = ensure(g);
      x.psubs.push(fn);
      if (x.ultimoPayload) { try { fn(x.ultimoPayload, x.estado); } catch (e) { /* aislado */ } }
    }
  };
})();
