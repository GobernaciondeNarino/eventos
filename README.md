# Plataforma de Eventos TIC

Registro, acreditación y control de asistencia para los eventos de la **Secretaría TIC,
Innovación y Gobierno Abierto** de la Gobernación de Nariño.

Una persona se preregistra una sola vez, recibe un carnet digital con su código QR, y
cada mañana del evento marca su ingreso escaneando el código de la jornada. Ese mismo
carnet le sirve para intercambiar datos de contacto con otros asistentes y para que un
organizador registre su entrada si el código de la puerta no le funciona.

---

## Estado actual: fase 1 de 2

El desarrollo se pidió en dos tiempos, y este repositorio está al final del primero.

| | Fase 1 — Interfaz | Fase 2 — Funcionalidad |
|---|---|---|
| **Estado** | Terminada, lista para validar | Sin iniciar |
| **Qué hay** | Las 16 pantallas navegables, el sistema de diseño, el generador de QR, el asistente de instalación y la documentación | — |
| **Qué falta** | — | PHP, base de datos, autenticación, envío de correo, lectura por cámara |

Lo que ya funciona de verdad, no simulado:

- **Los códigos QR son reales y escaneables.** Generador propio, validado matriz a matriz
  contra la librería de referencia en 2 368 casos (versiones 1 a 20, cuatro niveles de
  corrección) y decodificado con un lector independiente. Apunta el celular a la pantalla
  del carnet y lee.
- **La personalización por evento funciona.** Cambiar paleta, tipografía o logo repinta la
  plataforma entera al instante y persiste entre pantallas.
- **El SQL del instalador es real.** Se genera desde la definición del esquema, en los tres
  modos, y su sintaxis está validada.

Lo que está simulado y avisa que lo está: el inicio de sesión, la lectura por cámara, la
conexión a la base de datos y el envío de correo.

> **No publiques esto en internet todavía.** Las pantallas de administración son HTML
> estático sin autenticación: cualquiera que sepa la URL entra. Para validar, sírvelo en
> local o en la red interna. El detalle está en [`docs/SEGURIDAD.md`](docs/SEGURIDAD.md).

---

## Cómo verlo

No hay que compilar nada. Solo se necesita un servidor de archivos estáticos, porque el
navegador bloquea algunas cosas si se abre el HTML con doble clic.

```bash
git clone https://github.com/GobernaciondeNarino/eventos.git
cd eventos

# Con Node
npx http-server public -p 8899 -s

# o con Python
python3 -m http.server 8899 --directory public

# o con PHP
php -S localhost:8899 -t public
```

Abre <http://localhost:8899>.

### Por dónde empezar

| Recorrido | Ruta |
|---|---|
| El del asistente, de principio a fin | `/index.html` → preregistro → carnet → check-in |
| El carnet, que es el corazón del sistema | `/carnet.html` — tócalo para voltearlo y ver el QR |
| Personalizar el evento (requisito 4) | `/admin/identidad.html` — cambia la paleta y mira el resto |
| El asistente de instalación (requisito 9) | `/install/index.html` — los seis pasos |
| El día del evento, desde la puerta | `/admin/escaner.html` y `/admin/qr-dias.html` |

Para verlo como se verá de verdad, abre el check-in y el carnet en un celular: la interfaz
está pensada primero para esa pantalla.

---

## Las pantallas

**Participante** — `public/`

| Pantalla | Qué hace |
|---|---|
| `index.html` | Entrada por correo, con las jornadas del evento |
| `preregistro.html` | Nombre y documento obligatorios; caracterización y perfil de expositor opcionales |
| `carnet.html` | La credencial, con anverso, reverso y QR. Cambia de color según el rol |
| `checkin.html` | Escaneo del código del día desde el celular |
| `contactos.html` | Contactos intercambiados y control de qué se comparte |
| `agenda.html` | Programación por jornada, con búsqueda y detalle |

**Administración** — `public/admin/`

| Pantalla | Qué hace |
|---|---|
| `login.html` | Acceso con segundo factor |
| `index.html` | Panel: indicadores, ingresos por jornada, cobertura territorial, pendientes |
| `escaner.html` | El operador lee el carnet y sella el ingreso |
| `registros.html` | Tabla de asistentes con filtros y exportación |
| `qr-dias.html` | Un código por jornada, imprimible y regenerable |
| `expositores.html` | Aprobar, observar o rechazar propuestas |
| `organizadores.html` | Equipo, roles y permisos |
| `eventos.html` | Varios eventos a la vez |
| `identidad.html` | Colores, tipografía y logo del evento |

**Instalación** — `public/install/index.html`: seis pasos, del estilo de los instaladores
clásicos.

---

## Los cinco requisitos, uno por uno

**Carnet virtual para participantes, organizadores y expositores.** Cinco roles
—participante, visitante, expositor, organizador y prensa—, cada uno con su color y su
rótulo en la credencial, para distinguirlos de lejos. El carnet se voltea, se imprime a
tamaño CR80 y lleva un QR real. En `carnet.html` hay un selector para ver cómo queda cada
rol sin tener que volver a registrarse.

**Configurable por evento: colores, tipografía y logo.** Todo lo personalizable vive como
variable CSS en `public/assets/css/tokens.css`. El panel de identidad las reescribe en vivo
y guarda el resultado; la fase 2 solo tiene que emitir las mismas variables desde la base de
datos. Hay cinco paletas base y cuatro combinaciones tipográficas, y además se puede ajustar
color por color. La pantalla comprueba el contraste contra la norma WCAG 2.1 AA y avisa
cuando el texto quedaría ilegible bajo el sol de la puerta del recinto.

**Registro de ingreso cada día, con un QR por jornada.** El código pertenece a la jornada,
no al evento: cambia cada día y el anterior deja de servir. Se imprime desde
`admin/qr-dias.html` y se puede regenerar si el pliego se filtra —una foto en redes basta—.
El esquema guarda hora de apertura y cierre por jornada, de modo que un escaneo fuera de esa
ventana se rechaza.

**El QR del carnet sirve para dos cosas.** Que otro asistente lo escanee e intercambien
contacto —nombre, entidad, correo y, si la persona quiere, teléfono—, y que un organizador
lo lea para registrar el ingreso del día cuando el código de la puerta falla.

**Asistente de instalación tipo WordPress.** Comprueba el servidor, pide los datos de
conexión, detecta qué tablas ya existen y propone un plan con tres modos: **limpio** (crea
desde cero), **actualizar** (conserva los datos y aplica solo los cambios pendientes) y
**anexar** (crea únicamente lo que falte). Muestra el SQL exacto antes de ejecutarlo, marca
en rojo lo destructivo y termina recordando que hay que borrar la carpeta de instalación.

---

## Cómo está organizado

```
eventos/
├── public/                      ← lo único que debe publicarse
│   ├── index.html … agenda.html     pantallas del participante
│   ├── admin/                       backoffice
│   ├── install/                     asistente de instalación
│   ├── assets/
│   │   ├── css/     tokens · base · componentes · impresión
│   │   ├── js/
│   │   │   ├── tema.js          identidad configurable (requisito 4)
│   │   │   ├── qr.js            generador de QR, sin dependencias
│   │   │   ├── esquema.js       modelo de datos, fuente única
│   │   │   ├── layout.js        armazón: barras y navegación
│   │   │   ├── ui.js            avisos, modales, formato, validación
│   │   │   ├── instalador.js    los seis pasos
│   │   │   ├── datos-demo.js    datos de muestra (desaparece en fase 2)
│   │   │   └── paginas/         un archivo por pantalla
│   │   ├── fonts/               tipografías autoalojadas
│   │   └── img/
│   └── .htaccess                cabeceras de seguridad
├── docs/
│   ├── SEGURIDAD.md             revisión de seguridad (requisito 7)
│   └── ESQUEMA-DATOS.md         las 14 tablas, generado
├── herramientas/
│   ├── descargar-fuentes.py     autoaloja las tipografías
│   └── generar-doc-esquema.js   regenera la documentación del esquema
├── pruebas/
│   ├── paginas.js               carga cada pantalla en un navegador real
│   ├── flujos.js                recorre los caminos completos
│   └── qr-contra-referencia.py  valida el generador de QR
└── .htaccess                    red de seguridad si el dominio no apunta a public/
```

### Sin dependencias en el navegador

No hay framework, ni CDN, ni `node_modules` en tiempo de ejecución. JavaScript plano y CSS
con variables. Las razones son concretas: la plataforma tiene que funcionar en sedes con
internet restringido, la IP de los asistentes no debe viajar a servidores de terceros, y una
entidad pública debería poder mantener esto dentro de cinco años sin arqueología de
dependencias. Node y Python solo se usan para las herramientas y las pruebas.

---

## Pruebas

```bash
npx http-server public -p 8899 -s        # en otra terminal

node pruebas/paginas.js                   # las 16 pantallas, escritorio
ANCHO=390 node pruebas/paginas.js         # las 16 pantallas, móvil
node pruebas/flujos.js                    # 58 comprobaciones de recorridos
python3 pruebas/qr-contra-referencia.py   # el generador de QR
```

`paginas.js` verifica que cada pantalla arme su armazón, no suelte errores de consola y no
desborde en horizontal, y deja capturas en `pruebas/capturas/`. `flujos.js` recorre el
preregistro completo, la emisión del carnet, el check-in, el escáner del operador, la
personalización de identidad y los seis pasos del instalador. `qr-contra-referencia.py`
necesita `pip install qrcode` y compara matriz a matriz contra esa librería.

Las tres pasan hoy: 16 pantallas limpias en ambos anchos, 58 de 58 comprobaciones y 0
diferencias en los QR.

---

## Lo que sigue: fase 2

La interfaz se construyó pensando en cómo se va a portar, no como una maqueta desechable.

- Cada archivo de `assets/js/paginas/` corresponde a una pantalla y concentra ahí su lógica.
- `datos-demo.js` es la única fuente de datos falsos; cada arreglo suyo equivale a una
  consulta y está anotado con la tabla que le corresponde.
- `esquema.js` ya define las 14 tablas con sus llaves e índices, y de ahí salen tanto el SQL
  del instalador como la documentación.
- `tema.js` lee de `window.EVENTO_TEMA` si el servidor lo imprime, y solo cae en el
  almacenamiento del navegador cuando no hay backend. La pantalla de identidad no cambia.
- Los comentarios marcados con «Fase 2» señalan los puntos exactos donde entra el servidor.

**Pila prevista:** PHP 8.1 o superior con PDO y MySQL/MariaDB, sin framework, para que
despliegue en el alojamiento compartido que ya usa la entidad. El asistente de instalación
es la puerta de entrada, igual que en WordPress.

**Orden sugerido:** instalador real → autenticación y sesiones → preregistro y emisión del
carnet → control de asistencia → reportes → correo.

Antes de escribir la primera línea de PHP conviene leer la sección 5 de
[`docs/SEGURIDAD.md`](docs/SEGURIDAD.md): es la lista de controles que el backend debe
implementar, escrita como lista de verificación.

---

## Documentación

- [`docs/SEGURIDAD.md`](docs/SEGURIDAD.md) — revisión de seguridad: lo que ya reduce riesgo,
  los hallazgos abiertos, el cumplimiento de la Ley 1581 de 2012 y los controles pendientes.
- [`docs/ESQUEMA-DATOS.md`](docs/ESQUEMA-DATOS.md) — las 14 tablas con sus columnas, llaves y
  el porqué de cada una.

---

## Créditos

Diseño basado en el prototipo «Plataforma Eventos TIC». Desarrollo asistido con
[Claude Code](https://github.com/anthropics/claude-code).
