/* Equipo organizador: roles, puestos y estado del segundo factor. */
(function () {
  'use strict';

  var PERMISOS = [
    {
      rol: 'Administrador',
      puede: ['Configurar el evento y la identidad', 'Aprobar propuestas', 'Exportar con caracterización', 'Gestionar el equipo']
    },
    {
      rol: 'Operador de acceso',
      puede: ['Escanear carnets y sellar ingresos', 'Consultar si alguien está preregistrado']
    },
    {
      rol: 'Consulta',
      puede: ['Ver indicadores y registros sin datos sensibles', 'Exportar el listado básico']
    }
  ];

  var equipo = Datos.organizadores.map(function (o) { return Object.assign({}, o); });

  function pintar() {
    UI.$('#conteo').textContent = equipo.length + ' personas en el equipo';

    UI.$('#filas').innerHTML = equipo.map(function (o) {
      return '<div class="table__row cols-organizadores">'
        + '<div class="stack" style="gap:2px;min-width:0">'
          + '<strong style="font-weight:500;color:var(--c-title)">' + UI.esc(o.nombre) + '</strong>'
          + '<span class="mono muted" style="font-size:11px">' + UI.esc(o.correo) + '</span>'
        + '</div>'
        + '<span><span class="tag ' + (o.rol === 'Administrador' ? 'tag--warn' : 'tag--mute') + '">' + UI.esc(o.rol) + '</span></span>'
        + '<span style="color:var(--c-text)">' + UI.esc(o.puesto) + '</span>'
        + '<span class="mono accent">' + UI.fmt.numero(o.escaneos) + '</span>'
        + '<div class="row" style="gap:6px">'
          + '<span class="tag ' + (o.estado === 'activo' ? 'tag--ok' : 'tag--danger') + '">'
            + (o.estado === 'activo' ? 'Activo' : 'Suspendido') + '</span>'
          + (o.doble ? '' : '<span class="tag tag--danger" title="Sin segundo factor">Sin 2FA</span>')
        + '</div>'
        + '</div>';
    }).join('');

    UI.$('#permisos').innerHTML = PERMISOS.map(function (p) {
      return '<div class="stack" style="gap:6px;padding-bottom:12px;border-bottom:1px solid var(--hair-soft)">'
        + '<strong style="font-family:var(--f-display);font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">' + UI.esc(p.rol) + '</strong>'
        + '<ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.7;color:var(--c-text)">'
        + p.puede.map(function (x) { return '<li>' + UI.esc(x) + '</li>'; }).join('')
        + '</ul></div>';
    }).join('');
  }

  UI.$('#nuevo').addEventListener('click', function () {
    UI.modal({
      etiqueta: 'Equipo',
      titulo: 'Agregar persona al equipo',
      html: '<h2 style="font-size:22px">Agregar persona al equipo</h2>'
        + '<div class="field"><label class="label" for="n-nombre">Nombre completo</label>'
          + '<input class="input" id="n-nombre" placeholder="Ej. Andrea Lucía Erazo"></div>'
        + '<div class="field"><label class="label" for="n-correo">Correo institucional</label>'
          + '<input class="input" id="n-correo" type="email" placeholder="nombre@narino.gov.co"></div>'
        + '<div class="grid-2">'
          + '<div class="field"><label class="label" for="n-rol">Rol</label>'
            + '<select class="select" id="n-rol">'
            + PERMISOS.map(function (p) { return '<option>' + UI.esc(p.rol) + '</option>'; }).join('')
            + '</select></div>'
          + '<div class="field"><label class="label" for="n-puesto">Puesto</label>'
            + '<input class="input" id="n-puesto" placeholder="Ej. Puerta principal"></div>'
        + '</div>'
        + '<div class="notice"><span class="notice__icon">◆</span><span>'
        + 'La persona recibirá un correo para definir su contraseña y activar el segundo factor. '
        + 'No podrá escanear hasta completar ambos pasos.</span></div>'
        + '<div class="row row--end">'
          + '<button class="btn" id="cancelar-nuevo">Cancelar</button>'
          + '<button class="btn btn--primary" id="guardar-nuevo">Enviar invitación</button>'
        + '</div>'
    });

    UI.$('#cancelar-nuevo').addEventListener('click', UI.cerrarModal);
    UI.$('#guardar-nuevo').addEventListener('click', function () {
      var nombre = UI.$('#n-nombre'), correo = UI.$('#n-correo');
      var ok = UI.marcar(nombre, UI.valida.nombre(nombre.value), 'Escribe el nombre completo.');
      ok = UI.marcar(correo, UI.valida.correo(correo.value), 'Escribe un correo institucional válido.') && ok;
      if (!ok) return;

      equipo.push({
        id: equipo.length + 1,
        nombre: nombre.value.trim(),
        correo: correo.value.trim(),
        rol: UI.$('#n-rol').value,
        puesto: UI.$('#n-puesto').value.trim() || 'Por asignar',
        escaneos: 0,
        estado: 'activo',
        doble: false
      });
      UI.cerrarModal();
      pintar();
      UI.toast('Invitación enviada a ' + correo.value.trim());
    });
  });

  pintar();
})();
