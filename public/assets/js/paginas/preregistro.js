/* Formulario de preregistro: llenado de listas, dependencia departamento →
   municipio, sección de expositor y validación antes de emitir el carnet. */
(function () {
  'use strict';

  var perfil = Datos.perfil();
  var estado = { edad: perfil.edad || '', expositor: false };

  /* ---- Datos precargados --------------------------------------------------- */
  UI.$('#correo-sesion').textContent = perfil.correo || 'sin sesión';
  UI.$('#nombre').value = perfil.nombre || '';
  UI.$('#doc').value = perfil.doc || '';
  UI.$('#tipoDoc').value = perfil.tipoDoc || 'CC';
  UI.$('#tel').value = perfil.tel || '';
  UI.$('#entidad').value = perfil.entidad || '';

  /* ---- Listas -------------------------------------------------------------- */
  UI.$('#perfil').innerHTML = Datos.roles
    .filter(function (r) { return r.clave !== 'organizador'; })
    .map(function (r) {
      return '<option value="' + UI.esc(r.clave) + '">' + UI.esc(r.etiqueta) + '</option>';
    }).join('');
  UI.$('#perfil').value = perfil.rol || 'participante';

  function ayudaPerfil() {
    var r = Datos.roles.filter(function (x) { return x.clave === UI.$('#perfil').value; })[0];
    UI.$('#perfil-ayuda').textContent = r ? r.descripcion : '';
  }
  UI.$('#perfil').addEventListener('change', ayudaPerfil);
  ayudaPerfil();

  UI.$('#categoria').insertAdjacentHTML('beforeend', Datos.categorias.map(function (c) {
    return '<option value="' + UI.esc(c) + '">' + UI.esc(c) + '</option>';
  }).join(''));

  UI.$('#diaExpo').innerHTML = Datos.evento.dias.map(function (d) {
    return '<option value="' + d.n + '">Día ' + d.n + ' — ' + UI.esc(d.etiqueta) + '</option>';
  }).join('');

  UI.$('#depto').insertAdjacentHTML('beforeend', Datos.departamentos.map(function (d) {
    return '<option value="' + UI.esc(d) + '">' + UI.esc(d) + '</option>';
  }).join(''));

  UI.$('#edades').innerHTML = Datos.edades.map(function (e) {
    return '<button type="button" class="chip' + (e === estado.edad ? ' is-active' : '') + '"'
      + ' data-valor="' + UI.esc(e) + '" aria-pressed="' + (e === estado.edad) + '">' + UI.esc(e) + '</button>';
  }).join('');
  UI.grupoChips(UI.$('#edades'), function (valor) { estado.edad = valor; });

  /* ---- Departamento → municipio -------------------------------------------- */
  var temporizador;
  UI.$('#depto').addEventListener('change', function () {
    var depto = this.value;
    var sel = UI.$('#municipio');
    var cargando = UI.$('#mun-cargando');

    clearTimeout(temporizador);
    sel.disabled = true;
    sel.innerHTML = '<option value="">Elige primero el departamento</option>';
    if (!depto) { cargando.classList.add('hidden'); return; }

    cargando.classList.remove('hidden');
    // Fase 2: consulta al servidor (división político-administrativa del DANE).
    temporizador = setTimeout(function () {
      cargando.classList.add('hidden');
      var lista = Datos.municipios[depto] || [];
      sel.innerHTML = '<option value="">Selecciona…</option>' + lista.map(function (m) {
        return '<option value="' + UI.esc(m) + '">' + UI.esc(m) + '</option>';
      }).join('');
      sel.disabled = false;
    }, 550);
  });

  if (perfil.depto) {
    UI.$('#depto').value = perfil.depto;
    UI.$('#depto').dispatchEvent(new Event('change'));
    setTimeout(function () { UI.$('#municipio').value = perfil.municipio || ''; }, 700);
  }

  /* ---- Caracterización plegable -------------------------------------------- */
  UI.$('#toggle-opcional').addEventListener('click', function () {
    var bloque = UI.$('#bloque-opcional');
    var oculto = bloque.classList.toggle('hidden');
    this.textContent = oculto ? 'Mostrar' : 'Ocultar';
    this.setAttribute('aria-expanded', String(!oculto));
  });

  /* ---- Perfil expositor ----------------------------------------------------- */
  UI.interruptor(UI.$('#switch-expositor'), function (activo) {
    estado.expositor = activo;
    UI.$('#bloque-expositor').classList.toggle('hidden', !activo);
    if (activo && UI.$('#perfil').value === 'participante') {
      UI.$('#perfil').value = 'expositor';
      ayudaPerfil();
    }
  });

  UI.$('#detalle').addEventListener('input', function () {
    UI.$('#contador-detalle').textContent = this.value.length + ' / 600';
  });

  /* ---- Validación y envío --------------------------------------------------- */
  function validar() {
    var ok = true;
    ok = UI.marcar(UI.$('#nombre'), UI.valida.nombre(UI.$('#nombre').value),
      'Escribe el nombre completo.') && ok;
    ok = UI.marcar(UI.$('#doc'), UI.valida.documento(UI.$('#doc').value),
      'El número de identificación debe tener entre 5 y 12 dígitos.') && ok;
    ok = UI.marcar(UI.$('#tel'), UI.valida.telefono(UI.$('#tel').value),
      'Revisa el número de teléfono.') && ok;

    if (estado.expositor) {
      ok = UI.marcar(UI.$('#tema'), UI.$('#tema').value.trim().length >= 5,
        'Describe el tema de tu exposición.') && ok;
      ok = UI.marcar(UI.$('#categoria'), !!UI.$('#categoria').value,
        'Elige una categoría.') && ok;
      ok = UI.marcar(UI.$('#detalle'), UI.$('#detalle').value.trim().length >= 30,
        'Amplía el detalle: al menos 30 caracteres.') && ok;
    }

    var habeas = UI.$('#habeas');
    var okHabeas = habeas.checked;
    UI.$('#habeas-error').textContent = okHabeas ? '' : 'Debes autorizar el tratamiento de datos para continuar.';
    UI.$('#habeas-error').classList.toggle('hidden', okHabeas);
    return ok && okHabeas;
  }

  function faltantes() {
    var f = [];
    if (!UI.valida.nombre(UI.$('#nombre').value)) f.push('nombre');
    if (!UI.valida.documento(UI.$('#doc').value)) f.push('identificación');
    if (!UI.$('#habeas').checked) f.push('autorización de datos');
    return f;
  }

  function pintarMensaje() {
    var f = faltantes();
    UI.$('#mensaje-validacion').textContent = f.length
      ? 'Falta: ' + f.join(', ')
      : 'Todo listo para emitir el carnet.';
  }
  UI.$('#form-preregistro').addEventListener('input', pintarMensaje);
  UI.$('#form-preregistro').addEventListener('change', pintarMensaje);
  pintarMensaje();

  UI.$('#form-preregistro').addEventListener('submit', function (e) {
    e.preventDefault();
    if (!validar()) {
      var primero = UI.$('.is-invalid') || UI.$('#habeas');
      if (primero) primero.focus();
      UI.toast('Revisa los campos marcados.');
      return;
    }

    var nuevo = {
      correo: perfil.correo,
      nombre: UI.$('#nombre').value.trim(),
      tipoDoc: UI.$('#tipoDoc').value,
      doc: UI.$('#doc').value.replace(/\D/g, ''),
      tel: UI.$('#tel').value.trim(),
      rol: UI.$('#perfil').value,
      genero: UI.$('#genero').value,
      discapacidad: UI.$('#discapacidad').value,
      etnia: UI.$('#etnia').value,
      entidad: UI.$('#entidad').value.trim(),
      edad: estado.edad,
      depto: UI.$('#depto').value,
      municipio: UI.$('#municipio').value,
      expositor: estado.expositor,
      tema: UI.$('#tema').value.trim(),
      categoria: UI.$('#categoria').value,
      detalle: UI.$('#detalle').value.trim(),
      diaExpo: UI.$('#diaExpo').value,
      duracion: UI.$('#duracion').value,
      reqs: UI.$('#reqs').value.trim()
    };

    Datos.guardarEstado({ perfil: Object.assign({}, Datos.estado.perfil, nuevo) });
    UI.toast('Preregistro completo. Emitiendo tu carnet…');
    setTimeout(function () { window.location.href = 'carnet.html'; }, 700);
  });
})();
