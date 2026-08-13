/* Escáner del operador: lee el carnet de un asistente y sella su ingreso.
   Recorre la lista de asistentes de muestra, uno por escaneo. */
(function () {
  'use strict';

  var operador = Datos.organizadores[0];
  var diaActivo = Number(Datos.estado.diaOperador) || 1;
  var escaneos = operador.escaneos;
  var indice = 0;
  var persona = null;
  // Ingresos registrados en esta sesión, sobre los que ya traía cada asistente.
  var registrados = {};

  UI.$('#operador').textContent = operador.nombre + ' · ' + operador.puesto;

  function pintarCabecera() {
    UI.$('#conteo-escaneos').textContent = escaneos + ' escaneos hoy';
    UI.$('#dia-activo').textContent = diaActivo;
    UI.$('#dias-admin').innerHTML = Datos.evento.dias.map(function (d) {
      return '<button type="button" class="chip' + (d.n === diaActivo ? ' is-active' : '') + '"'
        + ' data-valor="' + d.n + '" aria-pressed="' + (d.n === diaActivo) + '">Día ' + d.n
        + ' · ' + UI.esc(d.etiqueta) + '</button>';
    }).join('');
  }

  UI.$('#dias-admin').addEventListener('click', function (e) {
    var chip = e.target.closest('.chip');
    if (!chip) return;
    diaActivo = Number(chip.dataset.valor);
    Datos.guardarEstado({ diaOperador: diaActivo });
    pintarCabecera();
    mostrar('escaner');
  });

  function mostrar(cual) {
    ['escaner', 'encontrado', 'listo'].forEach(function (p) {
      UI.$('#pane-' + p).classList.toggle('hidden', p !== cual);
    });
  }

  function yaIngreso(p) {
    var extra = registrados[p.id] || [];
    return p.dias.indexOf(diaActivo) !== -1 || extra.indexOf(diaActivo) !== -1;
  }

  /* ---- Escaneo -------------------------------------------------------------- */
  UI.$('#escanear').addEventListener('click', function () {
    var boton = this;
    boton.disabled = true;
    boton.innerHTML = '<span class="spinner"></span> Leyendo…';

    setTimeout(function () {
      boton.disabled = false;
      boton.textContent = 'Escanear carnet';

      persona = Datos.asistentes[indice % Datos.asistentes.length];
      indice++;

      var rol = Datos.roles.filter(function (r) { return r.clave === persona.rol; })[0];
      var repetido = yaIngreso(persona);

      UI.$('#ficha').innerHTML =
        '<strong style="font-family:var(--f-display);font-size:20px;font-weight:600;letter-spacing:.04em;color:var(--c-title);line-height:1.2">'
          + UI.esc(persona.nombre) + '</strong>'
        + '<span class="mono" style="font-size:12.5px;color:var(--c-text)">'
          + UI.esc(persona.entidad) + ' · ' + UI.esc(persona.municipio) + '</span>'
        + '<span class="mono" style="font-size:12.5px;color:var(--c-text)">'
          + UI.esc(persona.tipoDoc) + ' ' + UI.esc(UI.fmt.documento(persona.doc)) + '</span>'
        + '<div class="row" style="margin-top:4px">'
          + '<span class="tag ' + (persona.rol === 'expositor' ? 'tag--warn' : '') + '">'
            + UI.esc(rol ? rol.etiqueta : persona.rol) + '</span>'
          + '<span class="tag tag--ok">Preregistro válido</span>'
        + '</div>';

      UI.$('#aviso-ingreso').innerHTML = repetido
        ? '<span class="tag tag--warn">Ya tiene ingreso del día ' + diaActivo + '</span> '
          + 'Registrar de nuevo no duplica la asistencia: solo deja la marca en la bitácora.'
        : 'Sin ingreso registrado para el día ' + diaActivo + '. Confirma para sellar la asistencia.';

      UI.$('#confirmar').textContent = repetido
        ? 'Registrar de todos modos'
        : 'Registrar ingreso · Día ' + diaActivo;

      mostrar('encontrado');
    }, 850);
  });

  /* ---- Confirmación --------------------------------------------------------- */
  UI.$('#confirmar').addEventListener('click', function () {
    if (!persona) return;
    registrados[persona.id] = (registrados[persona.id] || []).concat(diaActivo);
    escaneos++;
    pintarCabecera();

    var dia = Datos.dia(diaActivo);
    UI.$('#detalle').innerHTML = UI.esc(persona.nombre) + '<br>'
      + 'Día ' + diaActivo + ' · ' + UI.esc(dia.etiqueta) + ' · 9:1' + diaActivo + '<br>'
      + 'Operador: ' + UI.esc(operador.nombre);
    mostrar('listo');
    UI.toast('Asistencia de ' + persona.nombre + ' registrada.');
  });

  UI.$('#cancelar').addEventListener('click', function () { mostrar('escaner'); });
  UI.$('#siguiente').addEventListener('click', function () { mostrar('escaner'); });

  pintarCabecera();
})();
