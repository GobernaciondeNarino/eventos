/* Contactos intercambiados por QR entre asistentes. */
(function () {
  'use strict';

  var perfil = Datos.perfil();
  var compartirTel = Datos.estado.compartirTel !== false;
  // Cuántos contactos lleva la persona; el resto de la lista simula los que
  // aún no ha intercambiado.
  var cuantos = Datos.estado.contactos == null ? 3 : Datos.estado.contactos;

  function lista() { return Datos.contactos.slice(0, cuantos); }

  function pintar() {
    var items = lista();
    var cont = UI.$('#lista-contactos');

    if (!items.length) {
      cont.innerHTML = '<div class="empty">Sin contactos intercambiados</div>';
    } else {
      cont.innerHTML = items.map(function (c) {
        return '<article class="contact">'
          + '<span class="contact__ini" aria-hidden="true">' + UI.esc(UI.fmt.iniciales(c.nombre)) + '</span>'
          + '<div class="stack" style="gap:3px;min-width:0">'
            + '<strong class="contact__name">' + UI.esc(c.nombre) + '</strong>'
            + '<span class="contact__org">' + UI.esc(c.entidad) + '</span>'
            + '<span class="contact__data">' + UI.esc(c.correo)
              + (compartirTel && c.tel ? ' · ' + UI.esc(c.tel) : '') + '</span>'
          + '</div>'
          + '<span class="contact__when">' + UI.esc(c.cuando) + '</span>'
          + '</article>';
      }).join('');
    }

    var entidades = {};
    items.forEach(function (c) { entidades[c.entidad] = true; });
    UI.$('#resumen-contactos').innerHTML = [
      { k: 'Contactos', v: items.length },
      { k: 'Entidades', v: Object.keys(entidades).length },
      { k: 'Teléfonos compartidos', v: compartirTel ? items.filter(function (c) { return c.tel; }).length : 0 }
    ].map(function (f) {
      return '<div class="kv"><span class="kv__k">' + UI.esc(f.k) + '</span>'
        + '<strong class="kv__v">' + UI.esc(f.v) + '</strong></div>';
    }).join('');
  }

  UI.interruptor(UI.$('#switch-tel'), function (activo) {
    compartirTel = activo;
    Datos.guardarEstado({ compartirTel: activo });
    pintar();
    UI.toast(activo ? 'Tu teléfono se comparte al intercambiar contacto.' : 'Tu teléfono ya no se comparte.');
  });

  UI.interruptor(UI.$('#switch-directorio'), function (activo) {
    Datos.guardarEstado({ enDirectorio: activo });
    UI.toast(activo ? 'Apareces en el directorio del evento.' : 'Saliste del directorio del evento.');
  });

  /* ---- Escanear otro carnet ------------------------------------------------- */
  UI.$('#escanear-carnet').addEventListener('click', function () {
    if (cuantos >= Datos.contactos.length) {
      UI.toast('Ya intercambiaste contacto con todos los asistentes de la demostración.');
      return;
    }
    var nuevo = Datos.contactos[cuantos];
    cuantos++;
    Datos.guardarEstado({ contactos: cuantos });
    pintar();
    UI.toast('Contacto agregado: ' + nuevo.nombre);
  });

  /* ---- Exportar ------------------------------------------------------------- */
  UI.$('#exportar-vcf').addEventListener('click', function () {
    var items = lista();
    if (!items.length) { UI.toast('Todavía no tienes contactos para exportar.'); return; }
    var vcf = UI.aVcf(items.map(function (c) {
      return Object.assign({}, c, { tel: compartirTel ? c.tel : '', evento: Datos.evento.nombre });
    }));
    UI.descargar('contactos-evento.vcf', vcf, 'text/vcard');
    UI.toast('Descargando ' + items.length + ' contactos en formato .vcf');
  });

  /* ---- Mi QR de contacto ----------------------------------------------------- */
  UI.$('#mi-qr').addEventListener('click', function () {
    var carga = Datos.qrCarnet(perfil);
    UI.modal({
      etiqueta: 'Intercambio de contacto',
      titulo: 'Mi código',
      html: '<div class="stack stack--3" style="align-items:center">'
        + '<div class="qr-frame" style="width:220px">' + QR.svg(carga, { ecl: 'Q', quiet: 2, title: 'Mi código de contacto' }) + '</div>'
        + '<strong style="font-family:var(--f-display);font-size:18px;color:var(--c-title)">' + UI.esc(perfil.nombre) + '</strong>'
        + '<span class="help text-center">Quien escanee este código recibirá tu nombre, entidad, correo'
        + (compartirTel ? ' y teléfono.' : '. Tu teléfono no se comparte.') + '</span>'
        + '</div>'
    });
  });

  pintar();
})();
