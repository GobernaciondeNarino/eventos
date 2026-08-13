/* Carnet digital: pinta las dos caras, genera el QR real y permite ver
   cómo se ve la credencial en cada rol. */
(function () {
  'use strict';

  var perfil = Datos.perfil();
  var tema = Tema.get();
  var rolActual = perfil.rol || 'participante';

  var ROTULOS = {
    participante: 'PARTICIPANTE',
    visitante: 'VISITANTE',
    expositor: 'EXPOSITOR',
    organizador: 'ORGANIZADOR',
    prensa: 'PRENSA'
  };

  /* ---- Cabecera de la credencial ------------------------------------------- */
  UI.$('#carnet-marca').innerHTML = tema.logo
    ? '<img src="' + UI.esc(tema.logo) + '" alt="">'
    : UI.esc(Tema.iniciales());
  UI.$('#carnet-evento').textContent = tema.evento;
  UI.$('#carnet-evento-2').textContent = tema.evento;
  UI.$('#carnet-dependencia').textContent = 'Innovación · Territorio · Datos';
  UI.$('#carnet-id').textContent = perfil.credencial;
  UI.$('#correo-envio').textContent = perfil.correo;

  /* ---- Anverso: campos ------------------------------------------------------ */
  function pintarCampos() {
    var campos = [
      { k: 'Nombre', v: perfil.nombre || 'Sin diligenciar' },
      { k: 'Identificación', v: UI.fmt.documento(perfil.doc) || '—' },
      { k: 'Entidad', v: perfil.entidad || 'Independiente' }
    ];
    UI.$('#carnet-campos').innerHTML = campos.map(function (c) {
      return '<div class="carnet__field">'
        + '<span class="carnet__k">' + UI.esc(c.k) + '</span>'
        + '<span class="carnet__v">' + UI.esc(c.v) + '</span>'
        + '</div>';
    }).join('');

    UI.$('#carnet-nombre-2').textContent = perfil.nombre || 'Sin diligenciar';
    UI.$('#carnet-entidad-2').textContent = perfil.entidad || 'Independiente';
    UI.$('#carnet-doc').textContent = (perfil.tipoDoc || 'CC') + ' ' + UI.fmt.documento(perfil.doc);
  }
  pintarCampos();

  /* ---- Reverso: QR real ----------------------------------------------------- */
  var carga = Datos.qrCarnet(perfil);
  QR.render(UI.$('#carnet-qr'), carga, {
    ecl: 'Q',              // nivel alto: el carnet se dobla, se raya y se fotografía
    quiet: 2,              // el marco del carnet ya aporta zona de silencio
    dark: '#08151F',
    light: '#FFFFFF',
    className: 'qr',
    title: 'Código de la credencial'
  });
  UI.$('#qr-contenido').textContent = carga;

  /* ---- Código de barras decorativo ------------------------------------------
     Representa la identificación; su patrón se deriva del documento para que
     sea estable entre recargas, no aleatorio.                                */
  (function barras() {
    var base = String(perfil.doc || '0000000000');
    var html = '';
    for (var i = 0; i < 42; i++) {
      var d = Number(base[i % base.length]) || 1;
      var claro = (i + d) % 3 === 0;
      // flex en vez de ancho fijo: las barras ocupan todo el ancho del carnet
      // sea cual sea el tamaño de impresión.
      html += '<span style="flex:' + (1 + d % 3) + ';width:0;' + (claro ? 'opacity:.15' : '') + '"></span>';
    }
    UI.$('#carnet-barras').innerHTML = html;
  })();

  /* ---- Rol: color y rótulo --------------------------------------------------- */
  function aplicarRol(rol) {
    rolActual = rol;
    UI.$('#carnet').setAttribute('data-rol', rol);
    UI.$('#carnet-rol').textContent = ROTULOS[rol] || 'PARTICIPANTE';
    UI.$$('#roles .chip').forEach(function (c) {
      var act = c.dataset.valor === rol;
      c.classList.toggle('is-active', act);
      c.setAttribute('aria-pressed', String(act));
    });
    pintarResumen();
  }

  UI.$('#roles').innerHTML = Datos.roles.map(function (r) {
    return '<button type="button" class="chip" data-valor="' + UI.esc(r.clave) + '"'
      + ' aria-pressed="false">' + UI.esc(r.etiqueta) + '</button>';
  }).join('');
  UI.$('#roles').addEventListener('click', function (e) {
    var chip = e.target.closest('.chip');
    if (chip) aplicarRol(chip.dataset.valor);
  });

  /* ---- Resumen -------------------------------------------------------------- */
  function pintarResumen() {
    var rol = Datos.roles.filter(function (r) { return r.clave === rolActual; })[0];
    var filas = [
      { k: 'Credencial', v: perfil.credencial },
      { k: 'Perfil', v: rol ? rol.etiqueta : 'Participante' },
      { k: 'Documento', v: (perfil.tipoDoc || 'CC') + ' ' + UI.fmt.documento(perfil.doc) },
      { k: 'Municipio', v: perfil.municipio || 'Sin registrar' },
      { k: 'Entidad', v: perfil.entidad || 'Independiente' },
      { k: 'Jornadas', v: Datos.evento.dias.length + ' días' }
    ];
    UI.$('#resumen').innerHTML = filas.map(function (f) {
      return '<div class="kv"><span class="kv__k">' + UI.esc(f.k) + '</span>'
        + '<strong class="kv__v">' + UI.esc(f.v) + '</strong></div>';
    }).join('');
  }

  aplicarRol(rolActual);

  /* ---- Volteo ---------------------------------------------------------------- */
  var carnet = UI.$('#carnet');
  var boton = UI.$('#voltear');

  function voltear() {
    var reverso = carnet.classList.toggle('is-flipped');
    boton.textContent = reverso ? 'Ver el anverso' : 'Ver el reverso';
    carnet.setAttribute('aria-label', reverso
      ? 'Carnet digital, reverso con el código QR. Presiona para volver al anverso.'
      : 'Carnet digital, anverso. Presiona para ver el reverso.');
  }
  carnet.addEventListener('click', voltear);
  boton.addEventListener('click', function (e) { e.stopPropagation(); voltear(); });

  UI.$('#imprimir').addEventListener('click', function () { window.print(); });

  /* ---- Borrado de los datos de prueba ------------------------------------
     El prototipo guarda en el navegador lo que la persona escribe para poder
     recorrer las pantallas. Quien lo valide con datos reales necesita una
     forma evidente de quitarlos sin abrir las herramientas del navegador. */
  UI.$('#borrar-datos').addEventListener('click', function () {
    Datos.limpiarEstado();
    UI.toast('Datos de prueba borrados de este navegador.');
    setTimeout(function () { window.location.href = 'index.html'; }, 900);
  });
})();
