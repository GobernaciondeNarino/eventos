/* Listado de eventos. La plataforma es multievento: la identidad, las jornadas
   y los registros pertenecen a un evento, no al sistema. */
(function () {
  'use strict';

  var ESTADOS = {
    'En curso': 'tag--ok',
    'Abierto': '',
    'Borrador': 'tag--mute',
    'Cerrado': 'tag--mute'
  };

  var eventos = Datos.eventos.map(function (e) { return Object.assign({}, e); });
  var activo = 1;

  function pintar() {
    UI.$('#conteo').textContent = eventos.length + ' eventos registrados';

    UI.$('#filas').innerHTML = eventos.map(function (e) {
      return '<div class="table__row cols-eventos">'
        + '<div class="stack" style="gap:2px;min-width:0">'
          + '<strong style="font-weight:500;color:var(--c-title)">' + UI.esc(e.nombre) + '</strong>'
          + '<span class="mono muted" style="font-size:11px">'
            + (e.id === activo ? 'Evento activo · visible para los asistentes' : 'ID ' + e.id) + '</span>'
        + '</div>'
        + '<span class="mono" style="font-size:12.5px;color:var(--c-text)">' + UI.esc(UI.fmt.fecha(e.inicio)) + '</span>'
        + '<span style="color:var(--c-text)">' + e.dias + ' días</span>'
        + '<span class="mono accent">' + UI.fmt.numero(e.registros) + '</span>'
        + '<div class="row" style="gap:6px">'
          + '<span class="tag ' + (ESTADOS[e.estado] || '') + '">' + UI.esc(e.estado) + '</span>'
          + (e.id === activo ? '' : '<button class="btn btn--sm" type="button" data-activar="' + e.id + '">Activar</button>')
        + '</div>'
        + '</div>';
    }).join('');
  }

  UI.$('#duplicado').innerHTML = [
    { t: 'Se copia', l: ['Identidad visual completa', 'Categorías de exposición', 'Roles y permisos del equipo', 'Plantillas de correo'] },
    { t: 'Se reinicia', l: ['Jornadas y fechas', 'Códigos QR del día', 'Numeración de credenciales'] },
    { t: 'No se copia', l: ['Registros de personas', 'Asistencias', 'Contactos intercambiados', 'Bitácora'] }
  ].map(function (b) {
    return '<div class="stack" style="gap:8px">'
      + '<strong style="font-family:var(--f-display);font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--c-accent)">' + UI.esc(b.t) + '</strong>'
      + '<ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.75;color:var(--c-text)">'
      + b.l.map(function (x) { return '<li>' + UI.esc(x) + '</li>'; }).join('')
      + '</ul></div>';
  }).join('');

  UI.$('#filas').addEventListener('click', function (e) {
    var boton = e.target.closest('[data-activar]');
    if (!boton) return;
    activo = Number(boton.dataset.activar);
    var ev = eventos.filter(function (x) { return x.id === activo; })[0];
    pintar();
    UI.toast('«' + ev.nombre + '» es ahora el evento activo.');
  });

  UI.$('#nuevo').addEventListener('click', function () {
    UI.modal({
      etiqueta: 'Nuevo evento',
      titulo: 'Crear evento',
      html: '<h2 style="font-size:22px">Crear evento</h2>'
        + '<div class="field"><label class="label" for="e-nombre">Nombre del evento</label>'
          + '<input class="input" id="e-nombre" placeholder="Ej. Encuentro de Gobierno Digital"></div>'
        + '<div class="grid-2">'
          + '<div class="field"><label class="label" for="e-inicio">Fecha de inicio</label>'
            + '<input class="input" id="e-inicio" type="date"></div>'
          + '<div class="field"><label class="label" for="e-dias">Número de jornadas</label>'
            + '<input class="input input--mono" id="e-dias" type="number" min="1" max="10" value="3"></div>'
        + '</div>'
        + '<div class="field"><label class="label" for="e-base">Partir de</label>'
          + '<select class="select" id="e-base"><option value="">Configuración en blanco</option>'
          + eventos.map(function (x) { return '<option value="' + x.id + '">Copiar identidad de: ' + UI.esc(x.nombre) + '</option>'; }).join('')
          + '</select></div>'
        + '<div class="row row--end">'
          + '<button class="btn" id="cancelar-evento">Cancelar</button>'
          + '<button class="btn btn--primary" id="guardar-evento">Crear</button>'
        + '</div>'
    });

    UI.$('#cancelar-evento').addEventListener('click', UI.cerrarModal);
    UI.$('#guardar-evento').addEventListener('click', function () {
      var nombre = UI.$('#e-nombre'), inicio = UI.$('#e-inicio');
      var ok = UI.marcar(nombre, nombre.value.trim().length >= 5, 'Escribe el nombre del evento.');
      ok = UI.marcar(inicio, !!inicio.value, 'Elige la fecha de inicio.') && ok;
      if (!ok) return;

      eventos.unshift({
        id: Math.max.apply(null, eventos.map(function (x) { return x.id; })) + 1,
        nombre: nombre.value.trim(),
        inicio: inicio.value,
        dias: Number(UI.$('#e-dias').value) || 1,
        registros: 0,
        estado: 'Borrador'
      });
      UI.cerrarModal();
      pintar();
      UI.toast('Evento creado como borrador. Configura su identidad antes de abrirlo.');
    });
  });

  pintar();
})();
