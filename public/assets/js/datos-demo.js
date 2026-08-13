/* =========================================================================
   datos-demo.js — Datos de muestra para la validación de la interfaz
   -------------------------------------------------------------------------
   FASE 1 ÚNICAMENTE. Nada de esto sobrevive a la fase 2: cada arreglo de aquí
   corresponde a una tabla del esquema descrito en docs/ESQUEMA-DATOS.md y será
   reemplazado por una consulta. Las personas son ficticias.
   ========================================================================= */
(function (global) {
  'use strict';

  var EVENTO = {
    id: 1,
    nombre: 'Semana TIC Nariño 2026',
    dependencia: 'Secretaría TIC, Innovación y Gobierno Abierto',
    sede: 'Centro de Convenciones, Pasto',
    dominio: 'eventos.narino.gov.co',
    dias: [
      { n: 1, fecha: '2026-09-15', etiqueta: '15 sep 2026', estado: 'activo' },
      { n: 2, fecha: '2026-09-16', etiqueta: '16 sep 2026', estado: 'programado' },
      { n: 3, fecha: '2026-09-17', etiqueta: '17 sep 2026', estado: 'programado' }
    ]
  };

  var EVENTOS = [
    { id: 1, nombre: 'Semana TIC Nariño 2026', inicio: '2026-09-15', dias: 3, registros: 1284, estado: 'En curso' },
    { id: 2, nombre: 'Encuentro de Gobierno Digital', inicio: '2026-11-04', dias: 2, registros: 172, estado: 'Abierto' },
    { id: 3, nombre: 'Hackatón Datos Abiertos Nariño', inicio: '2027-02-19', dias: 2, registros: 0, estado: 'Borrador' },
    { id: 4, nombre: 'Semana TIC Nariño 2025', inicio: '2025-09-16', dias: 3, registros: 968, estado: 'Cerrado' }
  ];

  var MUNICIPIOS = {
    'Nariño': ['Pasto', 'Ipiales', 'Tumaco', 'Túquerres', 'La Unión', 'Sandoná', 'Samaniego',
      'Barbacoas', 'Cumbal', 'El Charco', 'Guachucal', 'La Cruz', 'Leiva', 'Linares',
      'Policarpa', 'Ricaurte', 'Taminango', 'Consacá', 'Buesaco', 'Yacuanquer'],
    'Putumayo': ['Mocoa', 'Puerto Asís', 'Orito', 'Villagarzón', 'Sibundoy', 'Valle del Guamuez'],
    'Cauca': ['Popayán', 'Santander de Quilichao', 'Puerto Tejada', 'Silvia', 'Bolívar'],
    'Valle del Cauca': ['Cali', 'Palmira', 'Buenaventura', 'Tuluá'],
    'Bogotá D.C.': ['Bogotá D.C.']
  };

  var CATEGORIAS = [
    'Gobierno digital y servicios ciudadanos',
    'Conectividad e infraestructura',
    'Inteligencia artificial y datos',
    'Ciberseguridad',
    'Emprendimiento y startups TIC',
    'Educación y talento digital',
    'Desarrollo de software',
    'Soluciones comerciales y empresariales',
    'Innovación pública y gobierno abierto',
    'Economía creativa y contenidos digitales'
  ];

  var EDADES = ['14–17', '18–25', '26–35', '36–45', '46–60', '60+'];

  var ROLES = [
    { clave: 'participante', etiqueta: 'Participante', descripcion: 'Asiste a las jornadas.' },
    { clave: 'visitante', etiqueta: 'Visitante', descripcion: 'Ingreso por una jornada puntual.' },
    { clave: 'expositor', etiqueta: 'Expositor', descripcion: 'Presenta charla, stand o demostración.' },
    { clave: 'organizador', etiqueta: 'Organizador', descripcion: 'Equipo de la Secretaría; puede registrar ingresos.' },
    { clave: 'prensa', etiqueta: 'Prensa', descripcion: 'Cubrimiento periodístico.' }
  ];

  var ASISTENTES = [
    { id: 1, nombre: 'María Fernanda Zambrano', correo: 'mzambrano@narino.gov.co', doc: '1085234567', tipoDoc: 'CC', municipio: 'Pasto', entidad: 'Gobernación de Nariño', rol: 'expositor', tel: '+57 316 220 4471', dias: [1, 2] },
    { id: 2, nombre: 'Jhon Alexander Cuaspud', correo: 'jcuaspud@ipiales.gov.co', doc: '1087443902', tipoDoc: 'CC', municipio: 'Ipiales', entidad: 'Alcaldía de Ipiales', rol: 'participante', tel: '+57 311 553 0021', dias: [1, 2, 3] },
    { id: 3, nombre: 'Luisa Katherine Ortega', correo: 'luisa.ortega@udenar.edu.co', doc: '1089771203', tipoDoc: 'CC', municipio: 'Pasto', entidad: 'Universidad de Nariño', rol: 'participante', tel: '+57 318 776 2210', dias: [1] },
    { id: 4, nombre: 'Carlos Andrés Bolaños', correo: 'cbolanos@tumaco.gov.co', doc: '12994510', tipoDoc: 'CC', municipio: 'Tumaco', entidad: 'Alcaldía de Tumaco', rol: 'participante', tel: '+57 315 908 3344', dias: [2, 3] },
    { id: 5, nombre: 'Diana Marcela Rosero', correo: 'diana@tech-sur.co', doc: '1084229663', tipoDoc: 'CC', municipio: 'Túquerres', entidad: 'TechSur SAS', rol: 'expositor', tel: '+57 320 551 8890', dias: [1, 3] },
    { id: 6, nombre: 'Édinson Chamorro Pai', correo: 'echamorro@cumbal.gov.co', doc: '5204881', tipoDoc: 'CC', municipio: 'Cumbal', entidad: 'Resguardo Indígena de Cumbal', rol: 'participante', tel: '+57 312 664 1907', dias: [1, 2, 3] },
    { id: 7, nombre: 'Sofía Alejandra Narváez', correo: 'snarvaez@sandona.gov.co', doc: '1090334712', tipoDoc: 'CC', municipio: 'Sandoná', entidad: 'Alcaldía de Sandoná', rol: 'participante', tel: '+57 317 442 9015', dias: [3] },
    { id: 8, nombre: 'Ricardo Enríquez Guerrero', correo: 'renriquez@mintic.gov.co', doc: '79554201', tipoDoc: 'CC', municipio: 'Bogotá D.C.', entidad: 'MinTIC', rol: 'expositor', tel: '+57 310 442 7781', dias: [2] },
    { id: 9, nombre: 'Andrea Lucía Erazo', correo: 'aerazo@narino.gov.co', doc: '1085990233', tipoDoc: 'CC', municipio: 'Pasto', entidad: 'Secretaría TIC', rol: 'organizador', tel: '+57 313 220 7788', dias: [1, 2, 3] },
    { id: 10, nombre: 'Wilson Fernando Timaná', correo: 'wtimana@radionarino.co', doc: '98442015', tipoDoc: 'CC', municipio: 'Pasto', entidad: 'Radio Nariño', rol: 'prensa', tel: '+57 300 118 4420', dias: [1] },
    { id: 11, nombre: 'Yeimy Paola Muñoz', correo: 'ypmunoz@sena.edu.co', doc: '1088223904', tipoDoc: 'CC', municipio: 'Pasto', entidad: 'SENA Regional Nariño', rol: 'participante', tel: '+57 314 667 2201', dias: [1, 2] },
    { id: 12, nombre: 'Óscar Iván Portilla', correo: 'oportilla@tuquerres.gov.co', doc: '13005788', tipoDoc: 'CC', municipio: 'Túquerres', entidad: 'Alcaldía de Túquerres', rol: 'visitante', tel: '', dias: [2] }
  ];

  var CONTACTOS = [
    { nombre: 'Ricardo Enríquez Guerrero', entidad: 'MinTIC', correo: 'renriquez@mintic.gov.co', tel: '+57 310 442 7781', cuando: 'hoy, 9:24' },
    { nombre: 'Luisa Katherine Ortega', entidad: 'Universidad de Nariño', correo: 'luisa.ortega@udenar.edu.co', tel: '+57 318 776 2210', cuando: 'hoy, 10:05' },
    { nombre: 'Carlos Andrés Bolaños', entidad: 'Alcaldía de Tumaco', correo: 'cbolanos@tumaco.gov.co', tel: '+57 315 908 3344', cuando: 'hoy, 11:32' },
    { nombre: 'Diana Marcela Rosero', entidad: 'TechSur SAS', correo: 'diana@tech-sur.co', tel: '+57 320 551 8890', cuando: 'hoy, 14:10' },
    { nombre: 'Édinson Chamorro Pai', entidad: 'Resguardo Indígena de Cumbal', correo: 'echamorro@cumbal.gov.co', tel: '+57 312 664 1907', cuando: 'hoy, 15:48' }
  ];

  var CHARLAS = [
    { id: 1, dia: 1, hora: '09:00', dur: '40 min', titulo: 'Nariño conectado: balance de infraestructura 2026', expositor: 'María Fernanda Zambrano', entidad: 'Gobernación de Nariño', cat: 'Conectividad', salon: 'Auditorio principal', estado: 'aprobada', detalle: 'Estado actual de los kilómetros de fibra desplegados, zonas digitales activas y los municipios priorizados para la siguiente fase. Cierra con el mapa de brechas de conectividad por subregión.' },
    { id: 2, dia: 1, hora: '10:30', dur: '40 min', titulo: 'Trámites sin ventanilla: rediseño de servicios digitales', expositor: 'Diana Marcela Rosero', entidad: 'TechSur SAS', cat: 'Gobierno digital', salon: 'Sala 2', estado: 'aprobada', detalle: 'Cómo se rediseñaron cinco trámites municipales partiendo de la experiencia del ciudadano, y qué se necesita para que un trámite pase de presencial a completamente en línea.' },
    { id: 3, dia: 1, hora: '14:00', dur: '1 hora', titulo: 'IA aplicada a la gestión pública territorial', expositor: 'Ricardo Enríquez Guerrero', entidad: 'MinTIC', cat: 'IA y datos', salon: 'Auditorio principal', estado: 'aprobada', detalle: 'Casos de uso reales en entidades territoriales, criterios de uso responsable y la hoja de ruta nacional. Incluye una demostración en vivo con datos abiertos del departamento.' },
    { id: 4, dia: 2, hora: '09:00', dur: '40 min', titulo: 'Ciberseguridad para alcaldías pequeñas', expositor: 'Jhon Alexander Cuaspud', entidad: 'Alcaldía de Ipiales', cat: 'Ciberseguridad', salon: 'Sala 2', estado: 'aprobada', detalle: 'Controles mínimos viables con presupuesto limitado: respaldos, gestión de contraseñas, respuesta a incidentes y qué hacer en las primeras dos horas de un ataque de ransomware.' },
    { id: 5, dia: 2, hora: '11:00', dur: '40 min', titulo: 'Talento digital en la frontera: el caso de Ipiales', expositor: 'Luisa Katherine Ortega', entidad: 'Universidad de Nariño', cat: 'Talento digital', salon: 'Sala 3', estado: 'aprobada', detalle: 'Resultados de la formación de 400 jóvenes en programación y datos, con seguimiento de empleabilidad a doce meses y lecciones sobre deserción.' },
    { id: 6, dia: 2, hora: '15:00', dur: '1 hora', titulo: 'Emprender TIC desde el sur', expositor: 'Carlos Andrés Bolaños', entidad: 'Alcaldía de Tumaco', cat: 'Emprendimiento', salon: 'Auditorio principal', estado: 'aprobada', detalle: 'Panel con cuatro emprendimientos del Pacífico nariñense: acceso a capital, mercado público y el rol de las entidades territoriales como primer cliente.' },
    { id: 7, dia: 3, hora: '09:30', dur: '40 min', titulo: 'Datos abiertos que sí se usan', expositor: 'Édinson Chamorro Pai', entidad: 'Resguardo Indígena de Cumbal', cat: 'Gobierno abierto', salon: 'Sala 2', estado: 'aprobada', detalle: 'De la publicación al uso: cómo las comunidades pueden pedir, leer y auditar datos del departamento, con enfoque en información étnica y territorial.' },
    { id: 8, dia: 3, hora: '14:00', dur: '40 min', titulo: 'Contenidos digitales y economía creativa', expositor: 'Sofía Alejandra Narváez', entidad: 'Alcaldía de Sandoná', cat: 'Economía creativa', salon: 'Sala 3', estado: 'aprobada', detalle: 'Cómo la producción audiovisual local se volvió una línea de ingreso para artesanos y colectivos culturales del norte del departamento.' }
  ];

  var PROPUESTAS = [
    { id: 21, expositor: 'Yeimy Paola Muñoz', entidad: 'SENA Regional Nariño', titulo: 'Formación dual en desarrollo de software', cat: 'Educación y talento digital', dia: 2, dur: '40 min', estado: 'pendiente', reqs: 'HDMI, internet', detalle: 'Resultados del programa de formación dual con doce empresas de Pasto y la ruta para replicarlo en Ipiales y Túquerres.' },
    { id: 22, expositor: 'Óscar Iván Portilla', entidad: 'Alcaldía de Túquerres', titulo: 'Catastro multipropósito con drones', cat: 'Innovación pública y gobierno abierto', dia: 3, dur: '20 min', estado: 'pendiente', reqs: 'Proyector', detalle: 'Levantamiento predial con vuelos no tripulados y su integración al sistema de información municipal.' },
    { id: 23, expositor: 'Wilson Fernando Timaná', entidad: 'Radio Nariño', titulo: 'Radios comunitarias en la era del pódcast', cat: 'Economía creativa y contenidos digitales', dia: 1, dur: '40 min', estado: 'observada', reqs: 'Audio, dos micrófonos', detalle: 'Transición de la radio comunitaria a formatos digitales bajo demanda en municipios sin banda ancha estable.' },
    { id: 24, expositor: 'Diana Marcela Rosero', entidad: 'TechSur SAS', titulo: 'Interoperabilidad entre sistemas municipales', cat: 'Desarrollo de software', dia: 2, dur: '1 hora', estado: 'aprobada', reqs: 'HDMI', detalle: 'Un estándar mínimo de intercambio de datos entre alcaldías y la Gobernación, con ejemplos de implementación.' }
  ];

  var ORGANIZADORES = [
    { id: 1, nombre: 'Andrea Lucía Erazo', correo: 'aerazo@narino.gov.co', rol: 'Administrador', puesto: 'Puerta principal', escaneos: 128, estado: 'activo', doble: true },
    { id: 2, nombre: 'Hernán Darío Chaves', correo: 'hchaves@narino.gov.co', rol: 'Operador de acceso', puesto: 'Puerta secundaria', escaneos: 74, estado: 'activo', doble: true },
    { id: 3, nombre: 'Paula Andrea Guerrero', correo: 'pguerrero@narino.gov.co', rol: 'Operador de acceso', puesto: 'Sala 2', escaneos: 41, estado: 'activo', doble: false },
    { id: 4, nombre: 'Iván Camilo Delgado', correo: 'idelgado@narino.gov.co', rol: 'Consulta', puesto: 'Reportes', escaneos: 0, estado: 'suspendido', doble: false }
  ];

  /* Bitácora: en fase 2 es la tabla de auditoría, no un arreglo. */
  var BITACORA = [
    { cuando: 'hoy, 15:52', quien: 'Andrea Lucía Erazo', accion: 'Registró ingreso de Édinson Chamorro Pai (día 1)' },
    { cuando: 'hoy, 15:40', quien: 'Sistema', accion: 'Rotó el código del día 2 por vencimiento programado' },
    { cuando: 'hoy, 14:31', quien: 'Hernán Darío Chaves', accion: 'Registró ingreso de Diana Marcela Rosero (día 1)' },
    { cuando: 'hoy, 11:02', quien: 'Andrea Lucía Erazo', accion: 'Aprobó la propuesta “Interoperabilidad entre sistemas municipales”' },
    { cuando: 'ayer, 18:20', quien: 'Andrea Lucía Erazo', accion: 'Cambió la paleta del evento a TIC Nocturno' }
  ];

  /* ---- Persona que ve el prototipo ------------------------------------------- */
  var YO = {
    nombre: 'María Fernanda Zambrano',
    correo: 'mzambrano@narino.gov.co',
    tipoDoc: 'CC',
    doc: '1085234567',
    entidad: 'Gobernación de Nariño',
    municipio: 'Pasto',
    depto: 'Nariño',
    rol: 'participante',
    tel: '+57 316 220 4471',
    foto: '',
    credencial: 'STIC-2026-000482',
    token: '9f3a2b7d10c4e5'
  };

  /* Estado del prototipo que sí conviene recordar entre pantallas. */
  var CLAVE = 'eventostic.demo.v1';
  function cargar() {
    try {
      var s = localStorage.getItem(CLAVE);
      return s ? JSON.parse(s) : {};
    } catch (e) { return {}; }
  }
  function guardar(estado) {
    try { localStorage.setItem(CLAVE, JSON.stringify(estado)); } catch (e) { /* sin persistencia */ }
  }

  var Datos = {
    evento: EVENTO,
    eventos: EVENTOS,
    municipios: MUNICIPIOS,
    departamentos: Object.keys(MUNICIPIOS),
    categorias: CATEGORIAS,
    edades: EDADES,
    roles: ROLES,
    asistentes: ASISTENTES,
    contactos: CONTACTOS,
    charlas: CHARLAS,
    propuestas: PROPUESTAS,
    organizadores: ORGANIZADORES,
    bitacora: BITACORA,
    yo: YO,

    estado: cargar(),
    guardarEstado: function (parche) {
      Datos.estado = Object.assign({}, Datos.estado, parche || {});
      guardar(Datos.estado);
      return Datos.estado;
    },
    limpiarEstado: function () {
      Datos.estado = {};
      try { localStorage.removeItem(CLAVE); } catch (e) { /* nada que limpiar */ }
    },

    /** Perfil que se muestra en el carnet: mezcla de la persona y lo capturado. */
    perfil: function () {
      return Object.assign({}, YO, Datos.estado.perfil || {});
    },

    dia: function (n) {
      return EVENTO.dias.filter(function (d) { return d.n === Number(n); })[0] || EVENTO.dias[0];
    },

    /* --- Cargas útiles de los QR ------------------------------------------------
       Fase 1: cadena legible para poder verla en pantalla.
       Fase 2: el servidor firma el token y aquí solo viaja una URL corta con un
       identificador opaco; nunca datos personales. Ver docs/SEGURIDAD.md.     */
    qrCarnet: function (persona) {
      var p = persona || Datos.perfil();
      return 'https://' + EVENTO.dominio + '/c/' + (p.token || 'SIN-TOKEN');
    },
    qrDia: function (n) {
      var d = Datos.dia(n);
      return 'https://' + EVENTO.dominio + '/d/' + Datos.tokenDia(n) + '?f=' + d.fecha;
    },
    tokenDia: function (n) {
      var semillas = Object.assign({ 1: '4F9A2C', 2: 'B71E80', 3: 'C36D14' }, Datos.estado.semillasDia || {});
      return 'EVT-2026-D' + n + '-' + semillas[n];
    },
    rotarTokenDia: function (n) {
      var nuevo = '';
      var alfabeto = '0123456789ABCDEF';
      var bytes = new Uint8Array(6);
      (global.crypto || global.msCrypto).getRandomValues(bytes);
      for (var i = 0; i < 6; i++) nuevo += alfabeto[bytes[i] % 16];
      var semillas = Object.assign({ 1: '4F9A2C', 2: 'B71E80', 3: 'C36D14' }, Datos.estado.semillasDia || {});
      semillas[n] = nuevo;
      Datos.guardarEstado({ semillasDia: semillas });
      return Datos.tokenDia(n);
    }
  };

  global.Datos = Datos;
})(window);
