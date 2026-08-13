/* Propuestas de exposición: revisión, aprobación y devolución con observaciones. */
(function () {
  'use strict';

  var ESTADOS = {
    pendiente: { etiqueta: 'Pendiente', clase: 'tag--warn' },
    aprobada: { etiqueta: 'Aprobada', clase: 'tag--ok' },
    observada: { etiqueta: 'Con observaciones', clase: '' },
    rechazada: { etiqueta: 'Rechazada', clase: 'tag--danger' }
  };

  // Copia local: el prototipo no persiste decisiones en el servidor.
  var propuestas = Datos.propuestas.map(function (p) { return Object.assign({}, p); });
  var filtro = '';

  function pintarFiltros() {
    UI.$('#filtros-estado').innerHTML = [''].concat(Object.keys(ESTADOS)).map(function (e) {
      var etiqueta = e ? ESTADOS[e].etiqueta : 'Todas';
      return '<button type="button" class="chip' + (e === filtro ? ' is-active' : '') + '"'
        + ' data-valor="' + e + '" aria-pressed="' + (e === filtro) + '">' + UI.esc(etiqueta) + '</button>';
    }).join('');
  }

  function lista() {
    return propuestas.filter(function (p) { return !filtro || p.estado === filtro; });
  }

  function pintar() {
    var items = lista();

    UI.$('#kpis').innerHTML = Object.keys(ESTADOS).map(function (e) {
      var n = propuestas.filter(function (p) { return p.estado === e; }).length;
      return '<div class="kpi">'
        + '<span class="kpi__label">' + UI.esc(ESTADOS[e].etiqueta) + '</span>'
        + '<strong class="kpi__value">' + n + '</strong>'
        + '<span class="kpi__sub">propuestas</span>'
        + '</div>';
    }).join('');

    UI.$('#conteo').textContent = 'Mostrando ' + items.length + ' de ' + propuestas.length + ' propuestas';

    if (!items.length) {
      UI.$('#filas').innerHTML = '<div class="empty" style="margin:16px">Sin propuestas en este estado</div>';
      return;
    }

    UI.$('#filas').innerHTML = items.map(function (p) {
      var est = ESTADOS[p.estado];
      return '<div class="table__row cols-expositores" style="cursor:pointer" data-id="' + p.id + '">'
        + '<div class="stack" style="gap:2px;min-width:0">'
          + '<strong style="font-weight:500;color:var(--c-title)">' + UI.esc(p.titulo) + '</strong>'
          + '<span class="mono muted" style="font-size:11px">' + UI.esc(p.dur) + ' · ' + UI.esc(p.reqs || 'sin requerimientos') + '</span>'
        + '</div>'
        + '<div class="stack" style="gap:2px;min-width:0">'
          + '<span style="color:var(--c-title)">' + UI.esc(p.expositor) + '</span>'
          + '<span class="mono muted" style="font-size:11px">' + UI.esc(p.entidad) + '</span>'
        + '</div>'
        + '<span style="color:var(--c-text);font-size:13px">' + UI.esc(p.cat) + '</span>'
        + '<span class="mono" style="font-size:12.5px;color:var(--c-text)">Día ' + p.dia + '</span>'
        + '<span><span class="tag ' + est.clase + '">' + UI.esc(est.etiqueta) + '</span></span>'
        + '</div>';
    }).join('');
  }

  UI.$('#filtros-estado').addEventListener('click', function (e) {
    var chip = e.target.closest('.chip');
    if (!chip) return;
    filtro = chip.dataset.valor;
    pintarFiltros();
    pintar();
  });

  UI.$('#filas').addEventListener('click', function (e) {
    var fila = e.target.closest('[data-id]');
    if (!fila) return;
    abrir(Number(fila.dataset.id));
  });

  function abrir(id) {
    var p = propuestas.filter(function (x) { return x.id === id; })[0];
    if (!p) return;
    var d = Datos.dia(p.dia);

    UI.modal({
      etiqueta: p.cat,
      titulo: p.titulo,
      html: '<h2 style="font-size:24px">' + UI.esc(p.titulo) + '</h2>'
        + '<div class="row mono" style="gap:16px;font-size:12.5px;color:var(--c-text)">'
          + '<span><span class="muted">Expositor:</span> ' + UI.esc(p.expositor) + '</span>'
          + '<span><span class="muted">Entidad:</span> ' + UI.esc(p.entidad) + '</span>'
        + '</div>'
        + '<div class="row">'
          + '<span class="tag">Día ' + p.dia + ' · ' + UI.esc(d.etiqueta) + '</span>'
          + '<span class="tag tag--mute">' + UI.esc(p.dur) + '</span>'
          + '<span class="tag tag--mute">' + UI.esc(p.reqs || 'Sin requerimientos') + '</span>'
        + '</div>'
        + '<p style="font-size:14.5px;line-height:1.7;color:var(--c-text)">' + UI.esc(p.detalle) + '</p>'
        + '<div class="field"><label class="label" for="observacion">Observación para el expositor</label>'
        + '<textarea class="textarea" id="observacion" rows="3" placeholder="Opcional: qué debe ajustar antes de aprobar."></textarea></div>'
        + '<div class="row row--end">'
          + '<button class="btn btn--danger" data-decidir="rechazada">Rechazar</button>'
          + '<button class="btn" data-decidir="observada">Devolver con observaciones</button>'
          + '<button class="btn btn--primary" data-decidir="aprobada">Aprobar y agendar</button>'
        + '</div>'
    });

    UI.$$('[data-decidir]').forEach(function (b) {
      b.addEventListener('click', function () {
        var decision = this.dataset.decidir;
        var obs = (UI.$('#observacion') || {}).value || '';
        if (decision === 'observada' && !obs.trim()) {
          UI.toast('Escribe la observación antes de devolver la propuesta.');
          return;
        }
        p.estado = decision;
        p.observacion = obs.trim();
        UI.cerrarModal();
        pintar();
        UI.toast('Propuesta de ' + p.expositor + ': ' + ESTADOS[decision].etiqueta.toLowerCase() + '.');
      });
    });
  }

  pintarFiltros();
  pintar();
})();
