// =====================================================================
// main.js — Orquestador: carga JSON, inicializa módulos y conecta
// timeline ↔ globo ↔ gráficos ↔ prevención.
// =====================================================================

import { GloboElNino } from './globo.js';
import { Timeline } from './timeline.js';
import { PanelGraficos } from './graficos.js';
import { ModoExplicativo } from './explicativo.js';
import { PanelPrevencion } from './prevencion.js';
import { MapaNarino } from './mapaNarino.js';
import { AnalisisPestana } from './analisisPestana.js';

const RUTA_DATOS = './data/datos_globo_elnino_narino_2026.json';
const RUTA_PREDICCIONES = './data/predicciones_elnino_narino_2026.json';
const RUTA_HISTORICO = './data/historico_enso_episodios.json';

// ----- Utilidades DOM -----
const $ = (id) => document.getElementById(id);
const mostrarCarga = (mostrar) => {
  const el = $('capaCarga');
  el.setAttribute('aria-hidden', mostrar ? 'false' : 'true');
};
const mostrarError = (mensaje) => {
  $('capaErrorMensaje').textContent = mensaje;
  $('capaError').hidden = false;
};
const esconderError = () => {
  $('capaError').hidden = true;
};

// ----- Esperar a que Chart.js (script no-módulo) esté disponible -----
function esperarChartJs(maxMs = 5000) {
  return new Promise((resolve, reject) => {
    if (window.Chart) return resolve();
    const t0 = performance.now();
    const intervalo = setInterval(() => {
      if (window.Chart) {
        clearInterval(intervalo);
        resolve();
      } else if (performance.now() - t0 > maxMs) {
        clearInterval(intervalo);
        reject(new Error('Chart.js no se cargó en el tiempo esperado.'));
      }
    }, 50);
  });
}

async function cargarJson(ruta, { obligatorio = true } = {}) {
  const bust = Math.floor(Date.now() / 60000);
  const url = `${ruta}?v=${bust}`;
  const respuesta = await fetch(url, { cache: 'no-cache' });
  if (!respuesta.ok) {
    if (obligatorio) {
      throw new Error(`No se pudo leer ${ruta} (HTTP ${respuesta.status}).`);
    }
    return null;
  }
  return respuesta.json();
}

async function cargarDatos() {
  const datos = await cargarJson(RUTA_DATOS, { obligatorio: true });
  if (!datos?.global?.meses?.length || !datos?.narino?.meses?.length) {
    throw new Error('El archivo de datos no contiene la estructura esperada.');
  }
  return datos;
}

// DECISIÓN: La pieza muestra una ventana de Feb 2026 → Feb 2027 (13 meses).
// El JSON contiene 15 meses (Ene 2026 → Mar 2027) pero recortamos en el
// cliente para enfocar la visualización en un ciclo completo verde→rojo→verde.
// Si el JSON cambia su ventana, basta ajustar MES_INICIO / MES_FIN.
const MES_INICIO_VENTANA = '2026-02';
const MES_FIN_VENTANA = '2027-02';

function recortarVentana(datos, predicciones) {
  const meses = datos?.global?.meses || [];
  const idxInicio = meses.findIndex((m) => m.mes === MES_INICIO_VENTANA);
  const idxFin = meses.findIndex((m) => m.mes === MES_FIN_VENTANA);
  if (idxInicio < 0 || idxFin < 0 || idxFin < idxInicio) {
    console.warn('[Ventana] No se pudo recortar — manteniendo rango completo.');
    return;
  }
  const recortar = (arr) => arr.slice(idxInicio, idxFin + 1);
  datos.global.meses = recortar(datos.global.meses);
  datos.narino.meses = recortar(datos.narino.meses);
  if (predicciones?.municipios) {
    Object.values(predicciones.municipios).forEach((m) => {
      if (Array.isArray(m.serie_mensual)) m.serie_mensual = recortar(m.serie_mensual);
    });
  }
  console.log(`[Ventana] Visualización recortada a ${datos.global.meses.length} meses: ${MES_INICIO_VENTANA} → ${MES_FIN_VENTANA}.`);
}

async function cargarPredicciones() {
  try {
    const pr = await cargarJson(RUTA_PREDICCIONES, { obligatorio: false });
    if (pr?.municipios && Object.keys(pr.municipios).length) {
      console.log(`[Predicciones] ${Object.keys(pr.municipios).length} municipios cargados.`);
      return pr;
    }
    console.warn('[Predicciones] Archivo presente pero sin "municipios"; se usará respaldo por subregión.');
    return null;
  } catch (e) {
    console.warn('[Predicciones] No se pudieron cargar — heat-map caerá a respaldo:', e.message);
    return null;
  }
}

async function cargarHistorico() {
  try {
    const h = await cargarJson(RUTA_HISTORICO, { obligatorio: false });
    if (h?.episodios?.length) {
      console.log(`[Histórico] ${h.episodios.length} episodios ENSO cargados.`);
      return h;
    }
    return null;
  } catch (e) {
    console.warn('[Histórico] No se pudo cargar:', e.message);
    return null;
  }
}

function pintarMarcaSuperior(datos) {
  // Aviso de naturaleza ilustrativa (pie de página)
  const aviso = $('avisoNaturaleza');
  if (aviso) {
    aviso.textContent = datos.meta?.naturaleza
      || 'Datos ilustrativos. Verificar contra boletines oficiales.';
  }

  // Chip de estado del fenómeno — ahora vive dentro de la barra de timeline
  const chip = $('chipEstado');
  if (chip) {
    const f = datos.fenomeno || {};
    const estadoCorto = (f.estado_actual || 'Vigilancia').replace(/\.$/, '');
    chip.textContent = estadoCorto.split('/')[0].trim();
  }

  document.title = datos.meta?.titulo || document.title;
}

function pintarCintillo(datos, indice) {
  const g = datos.global.meses[indice] || {};
  const n = datos.narino.meses[indice] || {};
  $('cintilloMes').textContent = g.nombre_mes || '—';
  $('cintilloOni').textContent = (typeof g.oni === 'number') ? g.oni.toFixed(2) : '—';
  $('cintilloProb').textContent = (typeof g.probabilidad_el_nino_pct === 'number')
    ? `${g.probabilidad_el_nino_pct}%` : '—';
  $('cintilloResumen').textContent = n.resumen || g.anomalia_pacifico_descripcion || '';
}

async function iniciar() {
  // DECISIÓN: limpiar siempre el overlay de error al iniciar — protege contra
  // estados residuales si la página se reintentó tras una falla previa.
  esconderError();
  mostrarCarga(true);
  try {
    const [datos, predicciones, historico] = await Promise.all([
      cargarDatos(),
      cargarPredicciones(),
      cargarHistorico(),
      esperarChartJs().catch((e) => { console.warn(e); }),
    ]);

    // Ventana de visualización: Feb 2026 → Feb 2027 (13 meses).
    recortarVentana(datos, predicciones);

    pintarMarcaSuperior(datos);

    // Globo 3D
    const contenedor3D = $('escena3d');
    const globo = new GloboElNino(contenedor3D);

    // Mapa de Nariño (capa GeoJSON sobre el globo, coloreado por municipio
    // según el JSON de predicciones cuando está disponible).
    const mapaNarino = new MapaNarino(globo, datos, predicciones);
    // El mapa de calor por municipio es la pieza central — visible por defecto.
    mapaNarino.setVisible(true);

    // DEBUG: exponemos instancias para diagnóstico desde consola DevTools.
    // Uso: window.__debug.resumenColores() devuelve la lista de municipios + color hex
    // y nivel del mes activo (útil para verificar que cada uno se pinta distinto).
    window.__debug = {
      globo,
      mapa: mapaNarino,
      predicciones,
      resumenColores: () => {
        return mapaNarino.municipios.map((m) => ({
          divipola: m.divipola,
          nombre: m.nombre,
          color: '#' + m.material.color.getHexString(),
          opacidad: m.material.opacity.toFixed(2),
          nivel: m.ficha?.serie_mensual?.[mapaNarino.indiceMes]?.nivel || 'sin pred',
          indice: m.ficha?.serie_mensual?.[mapaNarino.indiceMes]?.indice_riesgo?.toFixed(3) || '—',
        }));
      },
    };
    // Botones de cámara cambian visibilidad: "mecanismo" oculta para ver el Pacífico,
    // "local" lo vuelve a mostrar.
    document.querySelectorAll('[data-camara="local"]').forEach((b) => {
      b.addEventListener('click', () => mapaNarino.setVisible(true));
    });
    document.querySelectorAll('[data-camara="mecanismo"]').forEach((b) => {
      b.addEventListener('click', () => mapaNarino.setVisible(false));
    });
    // El paso 5 del mecanismo enfatiza el mapa de Nariño; otros pasos lo ocultan.
    window.addEventListener('mecanismo-paso', (e) => {
      mapaNarino.setVisible(e.detail.paso === 5);
    });

    // Paneles
    const panelGraficos = window.Chart
      ? new PanelGraficos(datos, historico)
      : null;
    if (!window.Chart) {
      console.warn('Chart.js no disponible — gráficos deshabilitados, pero el resto funciona.');
    }
    const panelPrevencion = new PanelPrevencion(datos);

    // Panel de análisis dinámico que reacciona a la pestaña activa Y al mes activo
    const analisisPestana = new AnalisisPestana(datos);

    // Timeline (control maestro)
    const timeline = new Timeline({
      meses: datos.narino.meses,
      onCambioMes: (i) => {
        globo.actualizarMes(datos.global.meses[i], datos.narino.meses[i]);
        if (panelGraficos) panelGraficos.actualizarMes(i);
        panelPrevencion.actualizarMes(i);
        analisisPestana.actualizarMes(i);
        // Mapa de calor por municipio: índice 0..11 + objeto del mes
        // (este último sirve de respaldo si no hay predicción por municipio).
        mapaNarino.actualizarMes(i, datos.narino.meses[i]);
        pintarCintillo(datos, i);
      },
    });

    // Modo explicativo
    const explicativo = new ModoExplicativo({ datos, globo, timeline });
    explicativo.setRestaurador(() => {
      // Al cerrar el modo explicativo, restablecer el mes activo en el globo
      globo.actualizarMes(
        datos.global.meses[timeline.indice],
        datos.narino.meses[timeline.indice]
      );
    });

    // Cámaras predefinidas
    document.querySelectorAll('[data-camara]').forEach((btn) => {
      btn.addEventListener('click', () => globo.irACamara(btn.dataset.camara));
    });

    // Modo ligero
    $('toggleLigero').addEventListener('change', (e) => globo.setModoLigero(e.target.checked));

    // Reintentar (capa de error)
    $('botonReintentar').addEventListener('click', () => location.reload());

    // -------- Drawer "Comparar con históricos" --------
    if (historico?.episodios?.length) {
      cablearHistorico(globo, historico, datos);
      dibujarSparklineHistorico(historico, datos);
    } else {
      const btn = $('botonHistorico');
      if (btn) btn.disabled = true;
      const chkHist = $('capaHistoricoSlider');
      if (chkHist) chkHist.disabled = true;
    }
    // Toggles de capas (sparkline + foco de calor) — siempre se cablean,
    // funcionan de forma INDEPENDIENTE entre sí.
    cablearCapas(globo);

    mostrarCarga(false);
    esconderError(); // Garantía: si algún estado anterior dejó el overlay visible, lo cerramos.
    console.log('[Globo El Niño] Inicialización completa.');
  } catch (err) {
    console.error(err);
    mostrarCarga(false);
    mostrarError(err.message || 'Error desconocido al iniciar la aplicación.');
  }
}

// ===================== HISTÓRICO ENSO =====================

// Cablea el botón "📊 Históricos", el drawer y los clicks sobre cada tarjeta
// para reproducir el episodio sobre el globo (ráfaga de partículas).
function cablearHistorico(globo, historico, datos) {
  const drawer = $('drawerHistorico');
  const btnAbrir = $('botonHistorico');
  const btnCerrar = $('botonCerrarHistorico');
  const btnReset = $('botonResetHistorico');
  const cont = $('historicoDrawerTarjetas');
  const resumenActual = $('historicoActualResumen');

  if (!drawer || !btnAbrir) return;

  // Resumen actual
  if (resumenActual) {
    const oniProyMax = Math.max(...(datos.global?.meses || []).map((m) => m.oni || 0));
    resumenActual.innerHTML =
      `ONI pico proyectado <b>${oniProyMax.toFixed(2)}</b> · ` +
      `${datos.fenomeno?.intensidad_prevista || ''}`;
  }

  // Tarjetas con acordeón (1 por episodio + 1 "evento actual proyectado")
  if (cont) {
    cont.innerHTML = '';

    const construirDetalleEpisodio = (ep) => {
      const impCol = ep.impactos_colombia || {};
      const impNar = ep.impactos_narino || {};
      return `
        <p class="historico-mini__contexto">${ep.contexto || ''}</p>
        <dl class="historico-mini__metricas">
          <dt>ONI pico</dt><dd><b>${ep.oni_pico ?? '—'}</b></dd>
          <dt>Duración</dt><dd>${ep.duracion_meses ?? '—'} meses</dd>
          ${impCol.hectareas_incendios ? `<dt>Ha incendios CO</dt><dd>${impCol.hectareas_incendios.toLocaleString('es-CO')}</dd>` : ''}
          ${impCol.municipios_calamidad_agua ? `<dt>Mpios calamidad agua</dt><dd>${impCol.municipios_calamidad_agua}</dd>` : ''}
          ${impCol.municipios_desabastecimiento ? `<dt>Mpios desabastecimiento</dt><dd>${impCol.municipios_desabastecimiento}</dd>` : ''}
          ${impNar.municipios_afectados ? `<dt>Mpios Nariño afectados</dt><dd>${impNar.municipios_afectados}</dd>` : ''}
          ${impNar.hectareas_afectadas ? `<dt>Ha afectadas Nariño</dt><dd>${impNar.hectareas_afectadas.toLocaleString('es-CO')}</dd>` : ''}
        </dl>
        ${impNar.detalle ? `<p class="historico-mini__detalle-texto">${impNar.detalle}</p>` : ''}
        <p class="historico-mini__accion">Escenario activo en el globo · ONI forzado a ${ep.oni_pico}.</p>
      `;
    };

    const construirDetalleActual = () => {
      const oniProyMax = Math.max(...(datos.global?.meses || []).map((m) => m.oni || 0));
      return `
        <p class="historico-mini__contexto">${datos.fenomeno?.estado_actual || ''}</p>
        <dl class="historico-mini__metricas">
          <dt>ONI pico proyectado</dt><dd><b>${oniProyMax.toFixed(2)}</b></dd>
          <dt>Intensidad prevista</dt><dd>${datos.fenomeno?.intensidad_prevista || '—'}</dd>
          <dt>Probabilidad</dt><dd>${datos.fenomeno?.probabilidad_resumen || '—'}</dd>
        </dl>
        <p class="historico-mini__accion">Escenario proyectado activo en el globo.</p>
      `;
    };

    const alternarAcordeon = (cabecera, contenedorEp, onActivar) => {
      const estabaActivo = contenedorEp.classList.contains('activo');
      // Cierra todos los demás
      cont.querySelectorAll('.historico-mini').forEach((c) => c.classList.remove('activo'));
      if (!estabaActivo) {
        contenedorEp.classList.add('activo');
        onActivar();
      } else {
        // Si volvieron a clickear el mismo, limpia el episodio del globo
        globo.limpiarEpisodioHistorico();
      }
    };

    historico.episodios.forEach((ep) => {
      const cat = ep.categoria || 'sin dato';
      const wrap = document.createElement('div');
      wrap.className = `historico-mini historico-mini--${cat}`;
      wrap.innerHTML = `
        <button type="button" class="historico-mini__cabecera" aria-expanded="false">
          <strong>${ep.periodo}</strong>
          <span class="historico-mini__cat">${etiquetaCategoria(cat)}</span>
          <span class="historico-mini__oni">ONI ${ep.oni_pico}</span>
          <span class="historico-mini__chevron" aria-hidden="true">▾</span>
        </button>
        <div class="historico-mini__detalle">
          ${construirDetalleEpisodio(ep)}
        </div>
      `;
      const cab = wrap.querySelector('.historico-mini__cabecera');
      cab.addEventListener('click', () => {
        alternarAcordeon(cab, wrap, () => {
          cab.setAttribute('aria-expanded', 'true');
          globo.reproducirEpisodioHistorico(ep);
        });
        if (!wrap.classList.contains('activo')) {
          cab.setAttribute('aria-expanded', 'false');
        }
      });
      cont.appendChild(wrap);
    });

    // Evento actual proyectado
    const oniProyMax = Math.max(...(datos.global?.meses || []).map((m) => m.oni || 0));
    const wrapAct = document.createElement('div');
    wrapAct.className = 'historico-mini historico-mini--proyectado';
    wrapAct.innerHTML = `
      <button type="button" class="historico-mini__cabecera" aria-expanded="false">
        <strong>2026-27</strong>
        <span class="historico-mini__cat">En desarrollo</span>
        <span class="historico-mini__oni">ONI ${oniProyMax.toFixed(2)}</span>
        <span class="historico-mini__chevron" aria-hidden="true">▾</span>
      </button>
      <div class="historico-mini__detalle">
        ${construirDetalleActual()}
      </div>
    `;
    const cabAct = wrapAct.querySelector('.historico-mini__cabecera');
    cabAct.addEventListener('click', () => {
      alternarAcordeon(cabAct, wrapAct, () => {
        cabAct.setAttribute('aria-expanded', 'true');
        globo.reproducirEpisodioHistorico({
          periodo: '2026-27 proyectado',
          oni_pico: oniProyMax,
          categoria: 'proyectado',
        });
      });
      if (!wrapAct.classList.contains('activo')) {
        cabAct.setAttribute('aria-expanded', 'false');
      }
    });
    cont.appendChild(wrapAct);
  }

  // Apertura / cierre
  btnAbrir.addEventListener('click', () => {
    drawer.hidden = false;
    document.body.classList.add('drawer-abierto');
    setTimeout(() => globo.redimensionar(), 400);
  });
  if (btnCerrar) {
    btnCerrar.addEventListener('click', () => {
      drawer.hidden = true;
      document.body.classList.remove('drawer-abierto');
      globo.limpiarEpisodioHistorico();
      setTimeout(() => globo.redimensionar(), 400);
    });
  }
  if (btnReset) {
    btnReset.addEventListener('click', () => {
      globo.limpiarEpisodioHistorico();
      cont?.querySelectorAll('.historico-mini').forEach((b) => b.classList.remove('activo'));
    });
  }
}

// Capas visuales — toggles INDEPENDIENTES:
//   · capaHistoricoSlider → sparkline del slider (timeline)
//   · capaFocoCalor       → mancha cálida sobre el Pacífico (globo)
function cablearCapas(globo) {
  const chkHist = $('capaHistoricoSlider');
  const chkFoco = $('capaFocoCalor');

  // Histórico en línea de tiempo (sparkline + leyenda)
  if (chkHist) {
    const aplicarHist = () => {
      const visible = !!chkHist.checked;
      const sp = $('sparklineHistorico');
      if (sp) sp.style.display = visible ? '' : 'none';
      document.body.classList.toggle('sin-historico-slider', !visible);
    };
    chkHist.addEventListener('change', aplicarHist);
    aplicarHist();
  }

  // Foco de calor (capa estacional del globo)
  if (chkFoco) {
    const aplicarFoco = () => {
      const visible = !!chkFoco.checked;
      if (globo && typeof globo.setFocoCalorBloqueado === 'function') {
        globo.setFocoCalorBloqueado(!visible);
      }
    };
    chkFoco.addEventListener('change', aplicarFoco);
    aplicarFoco();
  }

  // Cerrar el menú al hacer click fuera (UX)
  const menu = $('capasMenu');
  if (menu) {
    document.addEventListener('click', (e) => {
      if (menu.open && !menu.contains(e.target)) menu.open = false;
    });
  }
}

function etiquetaCategoria(cat) {
  return ({
    debil: 'Débil',
    moderado: 'Moderado',
    fuerte: 'Fuerte',
    muy_fuerte: 'Muy fuerte',
    proyectado: 'En desarrollo',
  })[cat] || cat;
}

// Mini-curva de ONI histórico debajo del slider: muestra la trayectoria de
// los 3 episodios + el ONI proyectado para 2026-27 en la misma escala.
function dibujarSparklineHistorico(historico, datos) {
  const cvs = document.getElementById('sparklineHistorico');
  if (!cvs) return;

  // Tamaño físico siguiendo el contenedor padre
  const ajustar = () => {
    const ancho = cvs.parentElement.clientWidth;
    const alto = 22;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    cvs.width = ancho * dpr;
    cvs.height = alto * dpr;
    cvs.style.width = ancho + 'px';
    cvs.style.height = alto + 'px';
    const ctx = cvs.getContext('2d');
    ctx.scale(dpr, dpr);
    return { ctx, ancho, alto };
  };
  const { ctx, ancho, alto } = ajustar();

  // Serie ONI proyectado del evento 2026-27 (lo trazamos en dorado)
  const mesesOni = (datos.global?.meses || []).map((m) => m.oni || 0);
  const oniMax = 2.8; // límite superior de la escala
  const yPix = (v) => alto - 2 - ((v / oniMax) * (alto - 4));
  const xPix = (i, n) => (i / Math.max(1, n - 1)) * ancho;

  ctx.clearRect(0, 0, ancho, alto);

  // Línea base umbral 0.5 (El Niño débil)
  ctx.strokeStyle = 'rgba(232,160,32,0.45)';
  ctx.lineWidth = 0.6;
  ctx.setLineDash([2, 3]);
  ctx.beginPath();
  ctx.moveTo(0, yPix(0.5));
  ctx.lineTo(ancho, yPix(0.5));
  ctx.stroke();
  ctx.setLineDash([]);

  // Picos históricos como barras verticales finas
  const coloresCat = {
    debil: '#2e7d32', moderado: '#f9a825', fuerte: '#ef6c00', muy_fuerte: '#c62828',
  };
  (historico.episodios || []).forEach((ep, i) => {
    const x = ((i + 0.5) / (historico.episodios.length + 1)) * (ancho * 0.55);
    const y = yPix(ep.oni_pico || 0);
    ctx.strokeStyle = coloresCat[ep.categoria] || '#888';
    ctx.lineWidth = 2.2;
    ctx.beginPath();
    ctx.moveTo(x, alto - 2);
    ctx.lineTo(x, y);
    ctx.stroke();
    // Cabecera de la barra
    ctx.fillStyle = coloresCat[ep.categoria] || '#888';
    ctx.beginPath();
    ctx.arc(x, y, 2.4, 0, Math.PI * 2);
    ctx.fill();
  });

  // Curva del evento 2026-27 (toda la ventana, en azul institucional)
  ctx.strokeStyle = '#1A5276';
  ctx.lineWidth = 1.4;
  ctx.beginPath();
  mesesOni.forEach((v, i) => {
    const x = (ancho * 0.6) + (i / Math.max(1, mesesOni.length - 1)) * (ancho * 0.4);
    const y = yPix(v);
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  // Re-render en resize
  window.addEventListener('resize', () => dibujarSparklineHistorico(historico, datos), { once: true });
}

// Arrancar tras DOM listo
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', iniciar);
} else {
  iniciar();
}
