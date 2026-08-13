/* Genera docs/ESQUEMA-DATOS.md a partir de public/assets/js/esquema.js.
   El esquema se documenta desde su definición y no a mano, para que el
   documento no se desactualice en silencio la primera vez que alguien agregue
   una columna.

   Uso:  node herramientas/generar-doc-esquema.js
*/
'use strict';

const fs = require('fs');
const path = require('path');

const RAIZ = path.dirname(__dirname);
const Esquema = require(path.join(RAIZ, 'public/assets/js/esquema.js'));

const md = [];
const p = (...l) => md.push(...l);

p('# Esquema de datos',
  '',
  `Plataforma de Eventos TIC · versión del esquema **${Esquema.version}**`,
  '',
  '> Documento generado con `node herramientas/generar-doc-esquema.js` a partir de',
  '> `public/assets/js/esquema.js`, la misma definición que el asistente de instalación',
  '> usa para armar el plan y el SQL. No lo edites a mano: edita el esquema y vuelve a',
  '> generarlo.',
  '',
  'Las tablas llevan el prefijo que se elija durante la instalación (`evt_` por defecto),',
  'para poder compartir la base con otras aplicaciones del alojamiento. En este documento',
  'se muestran con ese prefijo.',
  '',
  '## Resumen',
  '',
  '| Tabla | Columnas | Para qué existe |',
  '|---|---:|---|');

Esquema.tablas.forEach(t => p(`| \`evt_${t.nombre}\` | ${t.columnas.length} | ${t.nota} |`));

p('',
  '## Cómo se relacionan',
  '',
  '```',
  'evento ─┬─ evento_tema        (identidad visual: colores, tipografía, logo)',
  '        ├─ evento_dia ────┬── asistencia',
  '        │                 └── charla',
  '        └─ persona ───┬─── persona_caracterizacion   (datos sensibles, aparte)',
  '                      ├─── credencial                (el carnet y su token)',
  '                      ├─── asistencia                (un ingreso por jornada)',
  '                      ├─── contacto                  (intercambios por QR)',
  '                      └─── propuesta ─── charla      (agenda, al aprobarse)',
  '',
  'usuario ─── sesion            (equipo organizador)',
  'bitacora                      (auditoría; solo inserciones)',
  'migracion                     (versión del esquema aplicada)',
  '```',
  '',
  'Tres decisiones que explican la forma del modelo:',
  '',
  '1. **Todo cuelga de `evento`.** La plataforma es multievento desde el principio, así que',
  '   la identidad, las jornadas y las personas pertenecen a un evento y no al sistema.',
  '2. **La caracterización está separada de `persona`.** Son datos sensibles según la Ley',
  '   1581 de 2012; teniéndolos aparte, las consultas del día a día no los tocan y su',
  '   lectura se puede auditar por separado.',
  '3. **El código QR cuelga de `evento_dia`, no de `evento`.** Es lo que permite que el',
  '   código cambie cada jornada y que el del día anterior deje de servir.',
  '',
  '## Detalle de cada tabla',
  '');

Esquema.tablas.forEach(t => {
  p(`### \`evt_${t.nombre}\``, '', t.nota, '', '| Columna | Tipo |', '|---|---|');
  t.columnas.forEach(c => p(`| \`${c[0]}\` | \`${c[1]}\` |`));
  p('', 'Llaves e índices:', '');
  t.llaves.forEach(k => p(`- \`${k.replace(/\{p\}/g, 'evt_')}\``));
  p('');
});

p('## Modos del instalador',
  '',
  'El asistente compara lo que hay en la base con esta definición y ofrece tres caminos:',
  '',
  '| Modo | Qué hace | Cuándo usarlo |',
  '|---|---|---|',
  '| **Limpio** | Elimina las tablas con este prefijo y las vuelve a crear | Instalación nueva, o entorno de pruebas que se quiere reiniciar |',
  '| **Actualizar** | Crea las que falten y aplica los `ALTER` pendientes, conservando los datos | Al subir de versión una instalación en uso |',
  '| **Anexar** | Solo crea las tablas que falten; no toca ninguna existente | Base compartida con otra aplicación, o reparación parcial |',
  '',
  'El modo limpio es el único destructivo y la interfaz lo advierte en rojo con el conteo',
  'de tablas que se perderían. En los tres casos el instalador hace una copia previa en',
  '`almacen/respaldos/`.',
  '',
  '## Versionado',
  '',
  'La tabla `evt_migracion` guarda qué versión del esquema está aplicada. Sin ese registro',
  'el asistente no podría distinguir una instalación nueva de una que solo necesita',
  'actualizarse, y ofrecería borrar datos que debía conservar.',
  '');

const destino = path.join(RAIZ, 'docs', 'ESQUEMA-DATOS.md');
fs.mkdirSync(path.dirname(destino), { recursive: true });
fs.writeFileSync(destino, md.join('\n'));

console.log(`docs/ESQUEMA-DATOS.md generado · ${Esquema.tablas.length} tablas · ${md.length} líneas`);
