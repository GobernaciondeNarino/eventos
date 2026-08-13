/* Agenda: charlas por día, con búsqueda, filtro por categoría y detalle. */
(function () {
  'use strict';

  var dia = Number(Datos.estado.diaAgenda) || 1;

  var categorias = Datos.charlas.map(function (c) { return c.cat; })
    .filter(function (v, i, a) { return a.indexOf(v) === i; }).sort();

  UI.$('#filtro-categoria').insertAdjacentHTML('beforeend', categorias.map(function (c) {
    return '<option value="' + UI.esc(c) + '">' + UI.esc(c) + '</option>';
  }).join(''));

  function pintarDias() {
    UI.$('#dias-agenda').innerHTML = Datos.evento.dias.map(function (d) {
      return '<button type="button" class="chip chip--stacked' + (d.n === dia ? ' is-active' : '') + '"'
        + ' data-valor="' + d.n + '" aria-pressed="' + (d.n === dia) + '">Día ' + d.n
        + '<small>' + UI.esc(d.etiqueta) + '</small></button>';
    }).join('');
  }

  function filtradas() {
    var q = UI.$('#buscar-agenda').value.trim().toLowerCase();
    var cat = UI.$('#filtro-categoria').value;
    return Datos.charlas.filter(function (c) {
      if (c.dia !== dia) return false;
      if (cat && c.cat !== cat) return false;
      if (!q) return true;
      return (c.titulo + ' ' + c.expositor + ' ' + c.entidad + ' ' + c.cat).toLowerCase().indexOf(q) !== -1;
    });
  }

  function pintar() {
    var items = filtradas();
    UI.$('#conteo-charlas').textContent = items.length + ' de '
      + Datos.charlas.filter(function (c) { return c.dia === dia; }).length + ' del día';

    if (!items.length) {
      UI.$('#lista-charlas').innerHTML = '<div class="empty">No hay exposiciones que coincidan con el filtro</div>';
      return;
    }

    UI.$('#lista-charlas').innerHTML = items.map(function (c) {
      return '<button type="button" class="talk" data-id="' + c.id + '">'
        + '<span class="talk__time">'
          + '<span class="talk__hour">' + UI.esc(c.hora) + '</span>'
          + '<span class="talk__dur">' + UI.esc(c.dur) + '</span>'
        + '</span>'
        + '<span class="stack" style="gap:6px">'
          + '<strong class="talk__title">' + UI.esc(c.titulo) + '</strong>'
          + '<span class="talk__by">' + UI.esc(c.expositor) + ' · ' + UI.esc(c.entidad) + '</span>'
        + '</span>'
        + '<span class="talk__meta">'
          + '<span class="tag">' + UI.esc(c.cat) + '</span>'
          + '<span class="talk__room">' + UI.esc(c.salon) + '</span>'
        + '</span>'
        + '</button>';
    }).join('');
  }

  UI.$('#dias-agenda').addEventListener('click', function (e) {
    var chip = e.target.closest('.chip');
    if (!chip) return;
    dia = Number(chip.dataset.valor);
    Datos.guardarEstado({ diaAgenda: dia });
    pintarDias();
    pintar();
  });

  UI.$('#buscar-agenda').addEventListener('input', pintar);
  UI.$('#filtro-categoria').addEventListener('change', pintar);

  UI.$('#lista-charlas').addEventListener('click', function (e) {
    var boton = e.target.closest('.talk');
    if (!boton) return;
    var c = Datos.charlas.filter(function (x) { return x.id === Number(boton.dataset.id); })[0];
    if (!c) return;
    var d = Datos.dia(c.dia);

    UI.modal({
      etiqueta: c.cat,
      titulo: c.titulo,
      html: '<h2 style="font-size:26px">' + UI.esc(c.titulo) + '</h2>'
        + '<div class="row mono" style="gap:16px;font-size:12.5px;color:var(--c-text)">'
          + '<span><span class="muted">Expositor:</span> ' + UI.esc(c.expositor) + '</span>'
          + '<span><span class="muted">Entidad:</span> ' + UI.esc(c.entidad) + '</span>'
        + '</div>'
        + '<div class="row">'
          + '<span class="tag" style="background:var(--a-08);font-size:11.5px;padding:8px 12px">Día ' + c.dia + ' · ' + UI.esc(d.etiqueta) + ' · ' + UI.esc(c.hora) + '</span>'
          + '<span class="tag tag--mute" style="font-size:11.5px;padding:8px 12px">' + UI.esc(c.salon) + ' · ' + UI.esc(c.dur) + '</span>'
        + '</div>'
        + '<p style="font-size:14.5px;line-height:1.7;color:var(--c-text)">' + UI.esc(c.detalle) + '</p>'
    });
  });

  pintarDias();
  pintar();
})();
