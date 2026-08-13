/* Códigos QR por jornada: uno por día, imprimibles y regenerables. */
(function () {
  'use strict';

  var tema = Tema.get();

  function ingresosDe(n) {
    return Datos.asistentes.filter(function (a) { return a.dias.indexOf(n) !== -1; }).length;
  }

  function pintar() {
    UI.$('#tarjetas').innerHTML = Datos.evento.dias.map(function (d) {
      var activo = d.estado === 'activo';
      var carga = Datos.qrDia(d.n);

      return '<div class="card stack" style="gap:0">'
        + '<div class="card__head" style="color:inherit">'
          + '<div class="stack" style="gap:2px">'
            + '<strong style="font-family:var(--f-display);font-size:17px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--c-title)">Día ' + d.n + '</strong>'
            + '<span class="mono muted" style="font-size:10.5px;letter-spacing:normal;text-transform:none">' + UI.esc(d.etiqueta) + '</span>'
          + '</div>'
          + '<span class="tag ' + (activo ? '' : 'tag--mute') + '">' + (activo ? 'Activo' : 'Programado') + '</span>'
        + '</div>'
        + '<div class="card__body stack stack--3">'
          + '<div class="qr-frame" data-qr="' + d.n + '"></div>'
          + '<div class="stack" style="gap:7px">'
            + '<span class="mono muted" style="font-size:10.5px;letter-spacing:.08em">' + UI.esc(Datos.tokenDia(d.n)) + '</span>'
            + '<div class="row row--between mono" style="font-size:12px">'
              + '<span class="muted" style="letter-spacing:.1em;text-transform:uppercase">Ingresos</span>'
              + '<strong class="accent" style="font-weight:500">' + (activo ? UI.fmt.numero(ingresosDe(d.n)) : '—') + '</strong>'
            + '</div>'
          + '</div>'
          + '<div class="row" style="flex-wrap:nowrap">'
            + '<button class="btn btn--sm" style="flex:1" type="button" data-imprimir="' + d.n + '">Imprimir</button>'
            + '<button class="btn btn--sm btn--primary" style="flex:1" type="button" data-regenerar="' + d.n + '">Regenerar</button>'
          + '</div>'
        + '</div>'
        + '</div>';
    }).join('');

    // Los QR se pintan después de insertar el HTML, con el contenido real.
    Datos.evento.dias.forEach(function (d) {
      QR.render(UI.$('[data-qr="' + d.n + '"]'), Datos.qrDia(d.n), {
        ecl: 'M',           // el pliego se imprime grande y limpio: basta nivel medio
        quiet: 2,
        className: 'qr',
        title: 'Código de acceso del día ' + d.n
      });
    });
  }

  UI.$('#tarjetas').addEventListener('click', function (e) {
    var imprimir = e.target.closest('[data-imprimir]');
    var regenerar = e.target.closest('[data-regenerar]');

    if (imprimir) {
      var n = Number(imprimir.dataset.imprimir);
      var d = Datos.dia(n);
      UI.$('#pliego').innerHTML =
        '<h1 class="print-sheet__title">' + UI.esc(tema.evento) + '</h1>'
        + '<p class="print-sheet__sub">Registro de ingreso · Día ' + n + ' · ' + UI.esc(d.etiqueta) + '</p>'
        + QR.svg(Datos.qrDia(n), { ecl: 'M', quiet: 2, className: 'qr', title: 'Código del día ' + n })
        + '<p class="print-sheet__token">' + UI.esc(Datos.tokenDia(n)) + '</p>';
      window.print();
      return;
    }

    if (regenerar) {
      var dia = Number(regenerar.dataset.regenerar);
      UI.modal({
        etiqueta: 'Confirmación',
        titulo: 'Regenerar el código del día ' + dia,
        html: '<h2 style="font-size:22px">Regenerar el código del día ' + dia + '</h2>'
          + '<p class="help">El código actual dejará de funcionar de inmediato. Si ya hay pliegos '
          + 'impresos y pegados en la entrada, habrá que reemplazarlos antes de que abra la jornada.</p>'
          + '<div class="row row--end">'
            + '<button class="btn" id="cancelar-regen">Cancelar</button>'
            + '<button class="btn btn--primary" id="confirmar-regen">Regenerar</button>'
          + '</div>'
      });
      UI.$('#cancelar-regen').addEventListener('click', UI.cerrarModal);
      UI.$('#confirmar-regen').addEventListener('click', function () {
        Datos.rotarTokenDia(dia);
        UI.cerrarModal();
        pintar();
        UI.toast('Código del día ' + dia + ' regenerado. El anterior queda inválido.');
      });
    }
  });

  pintar();
})();
