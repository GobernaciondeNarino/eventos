/* Check-in del participante: simula el escaneo del código del día y el
   sellado del ingreso. Los tres estados del teléfono son los mismos que
   tendrá la vista real; solo cambia de dónde salen los datos. */
(function () {
  'use strict';

  var perfil = Datos.perfil();
  var diaActivo = Number(Datos.estado.diaSimulado) || 1;
  var ingresos = Datos.estado.ingresos || {};   // { "1": "08:01", ... }

  UI.$('#dias-total').textContent = Datos.evento.dias.length;
  UI.$('#dominio').textContent = Datos.evento.dominio;

  function pintarDias() {
    UI.$('#dias-sim').innerHTML = Datos.evento.dias.map(function (d) {
      return '<button type="button" class="chip' + (d.n === diaActivo ? ' is-active' : '') + '"'
        + ' data-valor="' + d.n + '" aria-pressed="' + (d.n === diaActivo) + '">Día ' + d.n + '</button>';
    }).join('');
    UI.$$('.dia-eco').forEach(function (e) { e.textContent = diaActivo; });
    UI.$('#dia-activo').textContent = diaActivo;
    UI.$('#reloj').textContent = '8:0' + diaActivo;
  }

  UI.$('#dias-sim').addEventListener('click', function (e) {
    var chip = e.target.closest('.chip');
    if (!chip) return;
    diaActivo = Number(chip.dataset.valor);
    Datos.guardarEstado({ diaSimulado: diaActivo });
    pintarDias();
    mostrar('escaner');
  });

  function mostrar(cual) {
    ['escaner', 'correo', 'listo'].forEach(function (p) {
      UI.$('#pane-' + p).classList.toggle('hidden', p !== cual);
    });
  }

  /* ---- Estado 1 → 2 ---------------------------------------------------------
     Fase 2: aquí se abre la cámara (getUserMedia + BarcodeDetector) y el token
     leído se valida contra el servidor. La sesión abierta salta el paso 2.  */
  UI.$('#escanear').addEventListener('click', function () {
    this.disabled = true;
    this.innerHTML = '<span class="spinner"></span> Leyendo…';
    var boton = this;
    setTimeout(function () {
      boton.disabled = false;
      boton.textContent = 'Escanear código';
      UI.$('#correo-checkin').value = perfil.correo || '';
      mostrar('correo');
      UI.$('#correo-checkin').focus();
    }, 900);
  });

  /* ---- Estado 2 → 3 --------------------------------------------------------- */
  UI.$('#confirmar').addEventListener('click', function () {
    var campo = UI.$('#correo-checkin');
    if (!UI.marcar(campo, UI.valida.correo(campo.value), 'Escribe el correo con el que te preregistraste.')) {
      campo.focus();
      return;
    }
    var hora = '8:0' + diaActivo;
    ingresos[diaActivo] = hora;
    Datos.guardarEstado({ ingresos: ingresos });
    pintarListo(hora);
    mostrar('listo');
    UI.toast('Ingreso del día ' + diaActivo + ' registrado.');
  });

  UI.$('#sin-registro').addEventListener('click', function () {
    window.location.href = 'index.html';
  });

  UI.$('#reiniciar').addEventListener('click', function () {
    ingresos = {};
    Datos.guardarEstado({ ingresos: {} });
    mostrar('escaner');
    UI.toast('Simulación reiniciada.');
  });

  function pintarListo(hora) {
    var dia = Datos.dia(diaActivo);
    UI.$('#detalle-ingreso').innerHTML =
      UI.esc(perfil.nombre) + '<br>Día ' + diaActivo + ' · ' + UI.esc(dia.etiqueta) + ' · ' + UI.esc(hora);

    UI.$('#historial').innerHTML = Datos.evento.dias.map(function (d) {
      var reg = ingresos[d.n];
      return '<div class="kv">'
        + '<span style="font-size:12.5px;color:var(--c-text)">Día ' + d.n + ' · ' + UI.esc(d.etiqueta) + '</span>'
        + '<span class="mono" style="font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:'
        + (reg ? 'var(--c-accent)' : 'var(--c-muted)') + '">'
        + (reg ? 'Ingresó ' + UI.esc(reg) : 'Sin ingreso') + '</span>'
        + '</div>';
    }).join('');
  }

  pintarDias();
  if (ingresos[diaActivo]) {
    pintarListo(ingresos[diaActivo]);
    mostrar('listo');
  }
})();
