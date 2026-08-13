/* Pantalla de ingreso: valida el correo y pasa al preregistro. */
(function () {
  'use strict';

  var form = UI.$('#form-acceso');
  var correo = UI.$('#correo');
  var perfil = Datos.perfil();
  if (perfil.correo) correo.value = perfil.correo;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var valor = correo.value.trim();
    if (!UI.marcar(correo, UI.valida.correo(valor), 'Escribe un correo válido, por ejemplo nombre@entidad.gov.co')) {
      correo.focus();
      return;
    }
    Datos.guardarEstado({ perfil: Object.assign({}, Datos.estado.perfil, { correo: valor }) });
    // Fase 2: aquí el servidor decide si el correo ya existe y envía un enlace
    // de un solo uso; el prototipo salta directo al formulario.
    UI.toast('Correo reconocido. Continuando al preregistro…');
    setTimeout(function () { window.location.href = 'preregistro.html'; }, 600);
  });

  UI.$('#sede').textContent = Datos.evento.sede;

  UI.$('#jornadas').innerHTML = Datos.evento.dias.map(function (d) {
    var activo = d.estado === 'activo';
    return '<div class="stack" style="gap:6px">'
      + '<span class="kpi__label">Día ' + d.n + '</span>'
      + '<strong style="font-family:var(--f-display);font-size:20px;color:var(--c-title)">' + UI.esc(d.etiqueta) + '</strong>'
      + '<span class="tag ' + (activo ? '' : 'tag--mute') + '">' + (activo ? 'Jornada activa' : 'Programada') + '</span>'
      + '</div>';
  }).join('');
})();
