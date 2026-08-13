/* Panel del evento: indicadores, ingresos por jornada, cobertura y pendientes. */
(function () {
  'use strict';

  var tema = Tema.get();
  UI.$('#subtitulo').textContent = tema.evento + ' · ' + Datos.evento.sede;

  var total = Datos.asistentes.length;
  var expositores = Datos.asistentes.filter(function (a) { return a.rol === 'expositor'; }).length;
  var municipios = {};
  Datos.asistentes.forEach(function (a) { municipios[a.municipio] = (municipios[a.municipio] || 0) + 1; });
  var ingresosHoy = Datos.asistentes.filter(function (a) { return a.dias.indexOf(1) !== -1; }).length;
  var porAprobar = Datos.propuestas.filter(function (p) { return p.estado === 'pendiente'; }).length;

  /* ---- Indicadores ---------------------------------------------------------- */
  UI.$('#kpis').innerHTML = [
    { label: 'Preregistrados', v: UI.fmt.numero(total), sub: 'Total del evento' },
    { label: 'Ingresos día 1', v: UI.fmt.numero(ingresosHoy), sub: Math.round(ingresosHoy / total * 100) + '% de preregistrados' },
    { label: 'Expositores', v: UI.fmt.numero(expositores), sub: porAprobar + ' propuestas por aprobar' },
    { label: 'Municipios', v: UI.fmt.numero(Object.keys(municipios).length), sub: 'de 64 del departamento' }
  ].map(function (k) {
    return '<div class="kpi">'
      + '<span class="kpi__label">' + UI.esc(k.label) + '</span>'
      + '<strong class="kpi__value">' + UI.esc(k.v) + '</strong>'
      + '<span class="kpi__sub">' + UI.esc(k.sub) + '</span>'
      + '</div>';
  }).join('');

  /* ---- Ingresos por jornada -------------------------------------------------
     Barras en CSS puro: sin librería de gráficos, se imprime bien y no añade
     peso a una pantalla que se consulta desde el celular en la puerta.     */
  var porDia = Datos.evento.dias.map(function (d) {
    return {
      dia: d,
      n: Datos.asistentes.filter(function (a) { return a.dias.indexOf(d.n) !== -1; }).length
    };
  });
  var maximo = Math.max.apply(null, porDia.map(function (p) { return p.n; })) || 1;

  UI.$('#grafico').innerHTML = porDia.map(function (p) {
    var pct = Math.round(p.n / maximo * 100);
    return '<div class="stack" style="gap:6px">'
      + '<div class="row row--between">'
        + '<span class="mono" style="font-size:12px;color:var(--c-text)">Día ' + p.dia.n + ' · ' + UI.esc(p.dia.etiqueta) + '</span>'
        + '<span class="mono accent" style="font-size:13px">' + UI.fmt.numero(p.n) + '</span>'
      + '</div>'
      + '<div class="progress"><div class="progress__bar" style="width:' + pct + '%"></div></div>'
      + '</div>';
  }).join('');
  UI.$('#pie-grafico').textContent = 'Máximo ' + maximo + ' asistentes';

  /* ---- Cobertura territorial ------------------------------------------------- */
  var lista = Object.keys(municipios).map(function (m) { return { m: m, n: municipios[m] }; })
    .sort(function (a, b) { return b.n - a.n; });
  var tope = lista[0] ? lista[0].n : 1;

  UI.$('#municipios').innerHTML = lista.map(function (x) {
    return '<div class="row" style="gap:12px;flex-wrap:nowrap">'
      + '<span style="width:150px;flex-shrink:0;font-size:13px;color:var(--c-title)">' + UI.esc(x.m) + '</span>'
      + '<span class="progress" style="flex:1"><span class="progress__bar" style="display:block;width:' + Math.round(x.n / tope * 100) + '%"></span></span>'
      + '<span class="mono muted" style="width:28px;text-align:right;font-size:12px">' + x.n + '</span>'
      + '</div>';
  }).join('');

  /* ---- Pendientes ------------------------------------------------------------ */
  var pendientes = [
    { t: porAprobar + ' propuestas de exposición por revisar', url: 'expositores.html', tipo: 'warn' },
    { t: 'Código del día 2 aún no impreso', url: 'qr-dias.html', tipo: 'warn' },
    { t: 'Un organizador sin segundo factor activo', url: 'organizadores.html', tipo: 'danger' },
    { t: 'Identidad del evento sin logo cargado', url: 'identidad.html', tipo: '' }
  ];
  UI.$('#pendientes').innerHTML = pendientes.map(function (p) {
    return '<a class="notice' + (p.tipo ? ' notice--' + p.tipo : '') + '" href="' + p.url + '"'
      + ' style="text-decoration:none;color:inherit">'
      + '<span class="notice__icon" aria-hidden="true">›</span><span>' + UI.esc(p.t) + '</span></a>';
  }).join('');

  /* ---- Bitácora --------------------------------------------------------------- */
  UI.$('#bitacora').innerHTML = Datos.bitacora.map(function (b) {
    return '<div class="stack" style="gap:3px;padding:10px 0;border-bottom:1px solid var(--hair-soft)">'
      + '<span style="font-size:13px;color:var(--c-title)">' + UI.esc(b.accion) + '</span>'
      + '<span class="mono muted" style="font-size:11px">' + UI.esc(b.cuando) + ' · ' + UI.esc(b.quien) + '</span>'
      + '</div>';
  }).join('');
})();
