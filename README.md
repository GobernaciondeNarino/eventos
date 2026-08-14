# Plataforma de Eventos TIC

Registro, acreditación y control de asistencia para los eventos de la **Secretaría TIC,
Innovación y Gobierno Abierto** de la Gobernación de Nariño.

Una persona se preregistra una sola vez, recibe un carnet digital con su código QR, y cada
mañana del evento marca su ingreso escaneando el código de la jornada con la cámara de su
teléfono. Ese mismo carnet le sirve para intercambiar datos de contacto con otros asistentes
y para que un organizador registre su entrada si el código de la puerta no le funciona.

**Despliegue actual:** https://tic.narino.gov.co/cumbreAI/

---

## Estado

Las dos fases están terminadas. La plataforma funciona de extremo a extremo: se instala, se
registra gente, se sella asistencia, se aprueban exposiciones y se exportan reportes.

| | Qué hay |
|---|---|
| **Interfaz** | 20 pantallas, diseño configurable por evento, responsive |
| **Backend** | PHP 8.1+ con PDO y MySQL/MariaDB, sin framework ni Composer |
| **Instalación** | Asistente de seis pasos que crea, actualiza o anexa las tablas |
| **Autenticación** | Asistentes por código de correo; equipo con contraseña y segundo factor |
| **Códigos QR** | Generador propio, verificado contra una librería de referencia |
| **Pruebas** | 122 comprobaciones de extremo a extremo sobre un servidor real |

---

## Instalación

**En Plesk:** sigue [`docs/DESPLIEGUE-PLESK.md`](docs/DESPLIEGUE-PLESK.md). Resumen: crear la
base de datos con cotejamiento `utf8mb4_unicode_ci`, subir la carpeta a
`httpdocs/cumbreAI/`, dar permiso de escritura a `config/` y `almacen/`, activar HTTPS y
abrir la URL. El asistente hace el resto.

**Sin navegador,** en un solo comando —útil cuando el asistente no llega a terminar:

```bash
php herramientas/instalar.php \
    --bd-nombre=eventos_tic --bd-usuario=eventos_app --bd-clave='…' \
    --admin-correo=tu@narino.gov.co --admin-nombre="Nombre Apellido" \
    --evento="Cumbre Tecnológica CIOS Nariño" --inicio=2026-09-01 --dias=3 \
    --url=https://tic.narino.gov.co/cumbreAI
```

Usa las mismas clases que el asistente, va contando cada paso, y volver a ejecutarlo no
duplica nada.

**Si algo no cuadra:** `/instalar/diagnostico` dice el estado real —archivos, base de datos,
cuentas, eventos y los últimos errores— sin mostrar credenciales. Es público mientras la
plataforma no funcione, y exige administrador en cuanto funciona.

**En local, para probar:**

```bash
git clone https://github.com/GobernaciondeNarino/eventos.git
cd eventos
php -S localhost:8000
```

Abre <http://localhost:8000>. Sin configuración, cualquier dirección lleva al asistente.

### Funciona en cualquier subcarpeta

No hay que configurar la ruta en ningún lado: `index.php` la deduce de `SCRIPT_NAME` y todas
las URLs —enlaces, formularios, cookies y el contenido de los QR— se construyen a partir de
ahí.

| Dónde se sube | Ruta base | URL de ejemplo |
|---|---|---|
| `httpdocs/cumbreAI/` | `/cumbreAI` | `/cumbreAI/carnet` |
| `httpdocs/` | *(raíz)* | `/carnet` |
| `httpdocs/eventos/2026/` | `/eventos/2026` | `/eventos/2026/carnet` |

Mover la plataforma de sitio es copiar la carpeta y actualizar `url_base` en
`config/config.php`. Los QR ya impresos llevan la URL absoluta dentro: si cambia el dominio,
hay que regenerarlos.

---

## Cómo se usa

### El asistente

1. **Preregistro** — nombre y documento; el resto es opcional. Si va a exponer, adjunta su
   propuesta en el mismo formulario.
2. **Carnet** — se emite al instante y llega por correo. No hace falta imprimirlo.
3. **Cada mañana** — apunta la cámara al pliego de la entrada. El código abre la plataforma,
   sella la hora y muestra su historial.
4. **Contactos** — al escanear el carnet de otra persona intercambian nombre, entidad, correo
   y —si lo autorizaron— teléfono. Exportable en `.vcf`.

### El equipo organizador

Se entra en **`/admin/entrar`** —en el despliegue actual,
<https://tic.narino.gov.co/cumbreAI/admin/entrar>. La dirección `/admin` a secas es el panel:
sin sesión responde 303 y lleva allí. No hay ninguna carpeta `admin/` en el servidor; todo
pasa por `index.php`.

Las cuentas del equipo viven en la tabla **`evt_usuario`** (el prefijo se elige al instalar),
con la contraseña en hash Argon2id. Los asistentes al evento no están ahí: van a
`evt_persona` y entran por correo, sin contraseña.

Si nadie puede entrar —la instalación se interrumpió, se perdió la contraseña, el segundo
factor quedó en un teléfono que ya no está— el diagnóstico y el arreglo están en la consola:

```bash
php herramientas/cuenta.php estado          # qué hay instalado y qué falta
php herramientas/cuenta.php crear --correo=… --nombre="…"
php herramientas/cuenta.php clave --correo=…
php herramientas/cuenta.php sin-2fa --correo=…
```

Y si la base quedó sin ninguna cuenta administradora, el asistente de instalación **se reabre
solo** en modo reparación: sin cuenta no hay forma de entrar, y cerrarlo ahí dejaría el sitio
sin puerta. Sigue pidiendo las credenciales de la base de datos, que son la llave real del
proceso, y no ofrece la opción que borra tablas.

| Pantalla | Para qué |
|---|---|
| Panel | Indicadores, ingresos por jornada, cobertura territorial, pendientes |
| Escanear carnet | Acreditar a alguien cuyo código de puerta falló; incluye búsqueda manual |
| Registros | Listado con filtros y exportación a CSV |
| QR por día | Un código por jornada, imprimible a página completa y regenerable |
| Expositores | Aprobar, observar o rechazar propuestas; al aprobar se publica en la agenda |
| Organizadores | Equipo, roles y estado del segundo factor |
| Eventos | Varios eventos a la vez; el activo es el que ven los asistentes |
| Identidad | Colores, tipografía y logo, con revisión de contraste |

**Roles:** `administrador` ⊃ `operador` ⊃ `consulta`. El operador sella ingresos pero no
exporta datos sensibles ni toca la configuración.

---

## Los códigos QR

Son el centro del sistema y funcionan **desde cualquier aplicación de cámara**, sin instalar
nada.

**Código de la jornada** (`/d/{token}`) — el pliego pegado en la entrada. Cambia cada día,
tiene ventana horaria y se puede regenerar si se filtra. Al escanearlo: si hay sesión,
registra el ingreso; si no, lleva al acceso y vuelve para completarlo.

**Código del carnet** (`/c/{token}`) — la credencial de una persona. Quien lo escanea decide
qué pasa:

| Quién escanea | Qué obtiene |
|---|---|
| Nadie identificado | Se le pregunta quién es y se le lleva al acceso que corresponde. **No se revela de quién es el carnet** |
| Otro asistente | Intercambio de contacto, recíproco |
| Operador o administrador | Ficha de acreditación con documento, y el botón de sellar |

El QR **no contiene datos personales**: solo un identificador opaco de 128 bits. Quien
fotografíe un carnet ajeno no obtiene nada por sí mismo.

El generador está escrito desde cero, sin dependencias, en PHP y en JavaScript. Ambas
versiones se comparan matriz a matriz entre sí, y la de JavaScript está verificada contra la
librería `qrcode` de Python en 2 368 casos: versiones 1 a 20, los cuatro niveles de
corrección y las ocho máscaras.

---

## Personalización por evento

Todo lo configurable vive como variable CSS. El panel de identidad las reescribe y el
servidor las imprime en el `<head>`: las hojas de estilo no conocen ningún color.

- **5 paletas base** y ajuste color por color (10 variables).
- **4 combinaciones tipográficas**, con las familias autoalojadas.
- **Logo propio** en SVG, PNG, JPG o WEBP.
- **Revisión de contraste** contra WCAG 2.1 AA, en vivo. Un evento puede verse muy bien en la
  pantalla del diseñador y ser ilegible bajo el sol en la puerta del recinto.

Cada evento tiene su propia identidad: cambiar de evento activo cambia toda la plataforma.

---

## Cómo está organizado

```
eventos/                        ← esto es lo que se sube al servidor
├── index.php                   punto de entrada único
├── .htaccess                   reescritura, cabeceras y bloqueos
├── app/
│   ├── rutas.php               toda la superficie expuesta, con su guardia
│   ├── Esquema.php             las 16 tablas, fuente única
│   ├── Datos.php               listas de referencia del formulario
│   ├── ayudas.php              e(), u(), testigo()…
│   ├── Nucleo/                 App, Peticion, Enrutador, Guardia, Bd, Sesion,
│   │                           Csrf, Cripto, Limite, Bitacora, Qr, Tema, Correo, Totp
│   ├── Controladores/          Publico, Acceso, Carnet, Escaneo, Contactos,
│   │                           Admin, Medios, Instalador
│   ├── Modelos/                Evento, Persona, Credencial, Asistencia, Usuario
│   └── Vistas/                 PHP plano; parciales, publico/, admin/, instalar/
├── assets/                     css, js, tipografías, imágenes
├── config/                     config.php lo escribe el instalador
├── almacen/                    logos, fotos, respaldos, registro de errores
├── docs/                       despliegue, seguridad, esquema de datos
├── herramientas/               cuenta.php, tipografías, documentación
└── pruebas/
```

### Sin dependencias en tiempo de ejecución

Ni framework, ni Composer, ni CDN, ni `node_modules`. PHP plano y CSS con variables. Las
razones son concretas: la plataforma debe subirse por FTP a un alojamiento compartido sin
ejecutar nada previo, funcionar en sedes con internet restringido, no enviar la IP de los
asistentes a terceros, y poder mantenerse dentro de cinco años sin arqueología de
dependencias.

Node y Python se usan solo para herramientas y pruebas, nunca en producción.

---

## Pruebas

```bash
# Extremo a extremo: instala, registra, sella, acredita y exporta
php -S 127.0.0.1:8900 -t /tmp/web /tmp/web/router.php &
php pruebas/extremo-a-extremo.php

# El generador de QR
php pruebas/qr-php-contra-js.php          # servidor contra referencia JS
python3 pruebas/qr-contra-referencia.py   # JS contra la librería de Python

# Las pantallas en un navegador real
node pruebas/pantallas.js                 # escritorio
ANCHO=390 node pruebas/pantallas.js       # móvil
```

`extremo-a-extremo.php` habla por HTTP y no llamando a las clases, así que comprueba también
el enrutado, las cookies, los testigos y los guardias, que es donde suelen estar los errores.
Incluye 23 comprobaciones de seguridad.

Estado actual: **122 de 122** de extremo a extremo, **198** casos de QR idénticos entre PHP y
JavaScript, **161** entre JavaScript y la referencia, y las 13 pantallas limpias en escritorio
y móvil.

Las últimas comprobaciones cubren el caso que sacó a la luz un error real: una instalación que
se interrumpe a mitad deja la plataforma marcada como instalada y sin ninguna cuenta con la que
entrar. La prueba lo reproduce, comprueba que ahora tiene salida y que se vuelve a cerrar sola.

---

## Documentación

- [`docs/DESPLIEGUE-PLESK.md`](docs/DESPLIEGUE-PLESK.md) — instalación paso a paso, nginx por
  delante, copias de seguridad, actualizaciones y problemas frecuentes.
- [`docs/SEGURIDAD.md`](docs/SEGURIDAD.md) — revisión de seguridad: qué reduce riesgo, qué
  controles hay, hallazgos abiertos y cumplimiento de la Ley 1581 de 2012.
- [`docs/ESQUEMA-DATOS.md`](docs/ESQUEMA-DATOS.md) — las 16 tablas con sus columnas y llaves,
  generado desde `app/Esquema.php`.

---

## Créditos

Diseño basado en el prototipo «Plataforma Eventos TIC». Desarrollo asistido con
[Claude Code](https://github.com/anthropics/claude-code).
