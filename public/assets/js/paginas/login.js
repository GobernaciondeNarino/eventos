/* Acceso administrativo. Está en archivo aparte y no incrustado en el HTML
   para que la política de seguridad de contenido pueda prohibir los scripts
   en línea (script-src 'self', sin 'unsafe-inline'). */
(function () {
  'use strict';

  var tema = Tema.get();
  UI.$('#marca').innerHTML = tema.logo
    ? '<img src="' + UI.esc(tema.logo) + '" alt="">'
    : UI.esc(Tema.iniciales());
  UI.$('#titulo-evento').textContent = tema.evento;

  var paso = 1;

  UI.$('#form-login').addEventListener('submit', function (e) {
    e.preventDefault();
    var usuario = UI.$('#usuario'), clave = UI.$('#clave');

    var ok = UI.marcar(usuario, UI.valida.correo(usuario.value), 'Escribe tu correo institucional.');
    ok = UI.marcar(clave, clave.value.length >= 8, 'La contraseña debe tener al menos 8 caracteres.') && ok;
    if (!ok) return;

    if (paso === 1) {
      // El segundo factor se pide siempre para cuentas administrativas.
      paso = 2;
      UI.$('#campo-otp').classList.remove('hidden');
      UI.$('#entrar').textContent = 'Verificar y entrar';
      UI.$('#otp').focus();
      UI.toast('Ingresa el código de verificación.');
      return;
    }

    var otp = UI.$('#otp');
    if (!UI.marcar(otp, /^\d{6}$/.test(otp.value), 'El código tiene seis dígitos.')) return;

    UI.toast('Acceso concedido. Abriendo el panel…');
    setTimeout(function () { window.location.href = 'index.html'; }, 600);
  });
})();
