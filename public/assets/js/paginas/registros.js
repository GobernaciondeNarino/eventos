/* Registros y asistencia: búsqueda, filtros, marcas de ingreso y exportación. */
(function () {
  'use strict';

  UI.$('#filtro-rol').insertAdjacentHTML('beforeend', Datos.roles.map(function (r) {
    return '<option value="' + UI.esc(r.clave) + '">' + UI.esc(r.etiqueta) + '</option>';
  }).join(''));

  UI.$('#filtro-dia').insertAdjacentHTML('beforeend', Datos.evento.dias.map(function (d) {
    return '<option value="' + d.n + '">Con ingreso día ' + d.n + '</option>';
  }).join(''));

  function etiquetaRol(clave) {
    var r = Datos.roles.filter(function (x) { return x.clave === clave; })[0];
    return r ? r.etiqueta : clave;
  }

  function filtrados() {
    var q = UI.$('#buscar').value.trim().toLowerCase();
    var rol = UI.$('#filtro-rol').value;
    var dia = UI.$('#filtro-dia').value;

    return Datos.asistentes.filter(function (a) {
      if (rol && a.rol !== rol) return false;
      if (dia && a.dias.indexOf(Number(dia)) === -1) return false;
      if (!q) return true;
      return (a.nombre + ' ' + a.doc + ' ' + a.entidad + ' ' + a.correo + ' ' + a.municipio)
        .toLowerCase().indexOf(q) !== -1;
    });
  }

  function pintarKpis() {
    var lista = filtrados();
    var conIngreso = lista.filter(function (a) { return a.dias.length > 0; }).length;
    var completos = lista.filter(function (a) { return a.dias.length === Datos.evento.dias.length; }).length;
    var entidades = {};
    lista.forEach(function (a) { entidades[a.entidad] = true; });

    UI.$('#kpis').innerHTML = [
      { label: 'En el filtro', v: lista.length, sub: 'de ' + Datos.asistentes.length + ' registros' },
      { label: 'Con al menos un ingreso', v: conIngreso, sub: lista.length ? Math.round(conIngreso / lista.length * 100) + '%' : '—' },
      { label: 'Asistencia completa', v: completos, sub: 'los ' + Datos.evento.dias.length + ' días' },
      { label: 'Entidades', v: Object.keys(entidades).length, sub: 'representadas' }
    ].map(function (k) {
      return '<div class="kpi">'
        + '<span class="kpi__label">' + UI.esc(k.label) + '</span>'
        + '<strong class="kpi__value">' + UI.esc(k.v) + '</strong>'
        + '<span class="kpi__sub">' + UI.esc(k.sub) + '</span>'
        + '</div>';
    }).join('');
  }

  function pintar() {
    var lista = filtrados();
    pintarKpis();

    UI.$('#conteo').textContent = 'Mostrando ' + lista.length + ' de ' + Datos.asistentes.length + ' registros';

    if (!lista.length) {
      UI.$('#filas').innerHTML = '<div class="empty" style="margin:16px">Ningún registro coincide con el filtro</div>';
      return;
    }

    UI.$('#filas').innerHTML = lista.map(function (a) {
      var marcas = Datos.evento.dias.map(function (d) {
        var on = a.dias.indexOf(d.n) !== -1;
        return '<span class="daymark' + (on ? ' is-on' : '') + '"'
          + ' title="Día ' + d.n + (on ? ': con ingreso' : ': sin ingreso') + '">' + d.n + '</span>';
      }).join('');

      return '<div class="table__row cols-registros">'
        + '<div class="stack" style="gap:2px;min-width:0">'
          + '<strong style="font-weight:500;color:var(--c-title)">' + UI.esc(a.nombre) + '</strong>'
          + '<span class="mono muted" style="font-size:11px">' + UI.esc(a.correo) + '</span>'
        + '</div>'
        + '<span class="mono" style="font-size:12.5px;color:var(--c-text)">' + UI.esc(UI.fmt.documento(a.doc)) + '</span>'
        + '<span style="color:var(--c-text)">' + UI.esc(a.municipio) + '</span>'
        + '<span style="color:var(--c-text)">' + UI.esc(a.entidad) + '</span>'
        + '<span><span class="tag ' + (a.rol === 'expositor' ? 'tag--warn' : a.rol === 'organizador' ? 'tag--ok' : '') + '">'
          + UI.esc(etiquetaRol(a.rol)) + '</span></span>'
        + '<div class="daymarks">' + marcas + '</div>'
        + '</div>';
    }).join('');
  }

  UI.$('#buscar').addEventListener('input', pintar);
  UI.$('#filtro-rol').addEventListener('change', pintar);
  UI.$('#filtro-dia').addEventListener('change', pintar);

  /* ---- Exportación -----------------------------------------------------------
     Fase 2: el archivo lo arma el servidor y se descarga por un enlace de un
     solo uso; aquí se genera en el navegador solo para validar el formato.  */
  UI.$$('[data-exportar]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var tipo = this.dataset.exportar;
      var lista = filtrados();
      if (!lista.length) { UI.toast('No hay registros para exportar con este filtro.'); return; }

      if (tipo === 'pdf') {
        UI.toast('El reporte PDF se genera en el servidor (fase funcional).');
        return;
      }

      if (tipo === 'caracterizacion') {
        UI.modal({
          etiqueta: 'Datos sensibles',
          titulo: 'Exportar con caracterización',
          html: '<h2 style="font-size:22px">Exportar con caracterización</h2>'
            + '<p class="help">Este archivo incluye género, pertenencia étnica y condición de '
            + 'discapacidad: son datos sensibles según la Ley 1581 de 2012. La descarga queda '
            + 'registrada en la bitácora a tu nombre.</p>'
            + '<div class="notice notice--warn"><span class="notice__icon">▲</span>'
            + '<span>En la fase funcional esta acción exige permiso explícito y vuelve a pedir la contraseña.</span></div>'
            + '<div class="row row--end"><button class="btn btn--primary" id="confirmar-export">Entiendo, exportar</button></div>'
        });
        UI.$('#confirmar-export').addEventListener('click', function () {
          exportarCsv(lista, true);
          UI.cerrarModal();
        });
        return;
      }

      exportarCsv(lista, false);
    });
  });

  function exportarCsv(lista, conCaracterizacion) {
    var cabecera = ['Nombre', 'Documento', 'Correo', 'Entidad', 'Municipio', 'Perfil'];
    Datos.evento.dias.forEach(function (d) { cabecera.push('Día ' + d.n); });
    if (conCaracterizacion) cabecera = cabecera.concat(['Género', 'Etnia', 'Discapacidad']);

    var filas = [cabecera].concat(lista.map(function (a) {
      var fila = [a.nombre, a.doc, a.correo, a.entidad, a.municipio, etiquetaRol(a.rol)];
      Datos.evento.dias.forEach(function (d) { fila.push(a.dias.indexOf(d.n) !== -1 ? 'Sí' : 'No'); });
      if (conCaracterizacion) fila = fila.concat([a.genero || '', a.etnia || '', a.discapacidad || '']);
      return fila;
    }));

    // BOM para que Excel en Windows lea bien las tildes.
    UI.descargar(
      conCaracterizacion ? 'registros-caracterizacion.csv' : 'registros.csv',
      '﻿' + UI.aCsv(filas),
      'text/csv'
    );
    UI.toast('Exportando ' + lista.length + ' registros…');
  }

  pintar();
})();
