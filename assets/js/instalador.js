/* Instalador: prueba de conexión sin recargar y aviso del modo destructivo. */
(function () {
  'use strict';

  /* ---- Probar la conexión ------------------------------------------------- */
  var boton = document.querySelector('[data-probar-conexion]');
  if (boton) {
    var estado = document.querySelector('[data-estado-conexion]');

    boton.addEventListener('click', function () {
      var formulario = boton.closest('form');
      if (!formulario) return;

      var datos = new FormData(formulario);
      datos.set('accion', 'probar_conexion');

      boton.disabled = true;
      if (estado) estado.innerHTML = '<span class="spinner"></span> Conectando…';

      fetch(formulario.getAttribute('action'), {
        method: 'POST',
        body: datos,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.json(); })
        .then(function (respuesta) {
          if (!estado) return;
          estado.innerHTML = respuesta.ok
            ? '<span style="color:var(--c-ok)">✓ ' + escapar(respuesta.mensaje) + '</span>'
            : '<span style="color:var(--c-danger)">✕ ' + escapar(respuesta.mensaje) + '</span>';
        })
        .catch(function () {
          if (estado) estado.innerHTML = '<span style="color:var(--c-danger)">✕ No se pudo comprobar la conexión.</span>';
        })
        .finally(function () { boton.disabled = false; });
    });
  }

  /* ---- El aviso rojo solo aplica al modo limpio ---------------------------- */
  var aviso = document.querySelector('[data-aviso-limpio]');
  if (aviso) {
    var modos = document.querySelectorAll('[data-modo]');
    var refrescar = function () {
      var elegido = document.querySelector('[data-modo]:checked');
      aviso.hidden = !elegido || elegido.value !== 'limpio';
    };
    Array.prototype.forEach.call(modos, function (radio) {
      radio.addEventListener('change', refrescar);
    });
    refrescar();
  }

  function escapar(texto) {
    var div = document.createElement('div');
    div.textContent = String(texto || '');
    return div.innerHTML;
  }
})();
