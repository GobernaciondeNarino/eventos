# Despliegue en Plesk

Plataforma de Eventos TIC · Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño

Esta guía cubre el caso concreto del despliegue actual —
**https://tic.narino.gov.co/cumbreAI/** — y también el de un dominio propio, porque la
plataforma funciona igual en los dos sin cambiar un solo archivo.

---

## 1. Cómo se resuelve la subcarpeta

No hay que configurar la ruta en ningún lado. `index.php` la deduce de `SCRIPT_NAME`:

| Dónde se sube | Ruta base detectada | Ejemplo de URL interna |
|---|---|---|
| `httpdocs/cumbreAI/` | `/cumbreAI` | `/cumbreAI/carnet` |
| `httpdocs/` | *(vacía)* | `/carnet` |
| `httpdocs/eventos/2026/` | `/eventos/2026` | `/eventos/2026/carnet` |

Todas las URLs del sistema —enlaces, formularios, cookies, y el contenido de los códigos
QR— se construyen con `Url::a()` y `Url::absoluta()`, que anteponen esa base. Mover la
plataforma de sitio es copiar la carpeta y actualizar `url_base` en `config/config.php`.

> **Importante para los QR.** Los códigos ya impresos llevan la URL absoluta dentro. Si
> cambias el dominio o la subcarpeta después de imprimirlos, hay que regenerarlos y volver
> a imprimir (Administrador → QR por día → Regenerar).

---

## 2. Requisitos del servidor

| | Mínimo | Recomendado |
|---|---|---|
| PHP | 8.1 | 8.2 o superior |
| Base de datos | MySQL 5.7 / MariaDB 10.3 | MariaDB 10.6+ |
| Extensiones obligatorias | `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo` | |
| Extensiones recomendadas | `gd` (reduce logos), `sodium` (cifrado moderno) | |
| Apache | con `mod_rewrite` | además `mod_headers` |

En Plesk: **Dominio → Configuración de PHP**. Ahí se elige la versión y se revisan las
extensiones. La plataforma comprueba todo esto en el primer paso del instalador y no deja
continuar si falta algo obligatorio.

---

## 3. Instalación paso a paso

### 3.1 Crear la base de datos

**Plesk → Bases de datos → Añadir base de datos**

- Nombre: `eventos_tic` (o el que prefieras).
- Cotejamiento: **utf8mb4_unicode_ci**. Si se deja `latin1`, las tildes y la ñ se guardarán
  mal y el instalador lo rechazará.
- Crea un **usuario dedicado** para esta base, no reutilices uno existente ni uses `admin`.
  Si la aplicación se ve comprometida, el daño queda acotado a estas tablas.

Anota nombre de base, usuario y contraseña: el instalador los pedirá.

### 3.2 Subir los archivos

**Plesk → Archivos**, o por SFTP.

Sube el contenido del repositorio a `httpdocs/cumbreAI/`. Debe quedar así:

```
httpdocs/cumbreAI/
├── index.php          ← el punto de entrada
├── .htaccess
├── app/
├── assets/
├── config/            ← vacío; el instalador escribe aquí
├── almacen/
└── docs/ herramientas/ pruebas/   ← se pueden omitir
```

`docs/`, `herramientas/` y `pruebas/` no hacen falta en producción. Si los subes no pasa
nada: el `.htaccess` los bloquea.

> Si el panel deja apuntar el documento raíz directamente a la carpeta, mejor. No es
> necesario: la aplicación funciona colgando de una subcarpeta.

### 3.3 Permisos

El instalador necesita escribir en dos sitios:

```
config/     0750   (aquí se escribe config.php)
almacen/    0750   (logos, fotos, respaldos y registro de errores)
```

En Plesk se ajustan desde **Archivos → (menú del directorio) → Cambiar permisos**. El
propietario debe ser el usuario del sistema del dominio.

### 3.4 Activar HTTPS antes de instalar

**Plesk → Certificados SSL/TLS → Instalar (Let's Encrypt)** y activa la redirección
permanente a HTTPS.

Hazlo *antes* de instalar: así el instalador detecta la conexión segura y las cookies de
sesión salen marcadas como `Secure` desde el primer momento. Sin TLS, las contraseñas del
equipo y los tokens de los carnets viajan en claro por la red del recinto, que suele ser
wifi abierto.

Cuando el certificado esté puesto, descomenta en `.htaccess` el bloque de redirección a
HTTPS y la línea de `Strict-Transport-Security`.

### 3.5 Ejecutar el asistente

Abre **https://tic.narino.gov.co/cumbreAI/**. Sin configuración, cualquier dirección lleva
al asistente. Son seis pasos:

1. **Servidor** — comprueba versión de PHP, extensiones y permisos.
2. **Base de datos** — los datos del punto 3.1. El botón «Probar conexión» avisa antes de
   seguir.
3. **Tablas** — detecta lo que ya existe y propone: limpio, actualizar o anexar. Muestra el
   SQL exacto antes de ejecutarlo.
4. **Cuenta administradora** — la primera cuenta; podrá crear el resto del equipo.
5. **Primer evento** — nombre, fechas, jornadas y la identidad visual.
6. **Fin** — resumen y siguientes pasos.

Al terminar, el asistente **se cierra solo**: mientras exista `config/config.php` con la
instalación marcada como completa, la ruta `/instalar` responde 403. No hay que acordarse
de borrar ninguna carpeta. Para reinstalar a propósito, borra `config/config.php` o crea el
archivo `config/permitir-reinstalar`.

### 3.6 Correo saliente

**Plesk → Correo** y crea una cuenta como `no-responder@tic.narino.gov.co`.

La plataforma usa la función `mail()` del servidor, que en Plesk sale firmada con el SPF y
el DKIM del dominio. Ajusta en `config/config.php`:

```php
'correo_remitente' => 'no-responder@tic.narino.gov.co',
'correo_nombre'    => 'Cumbre Tecnológica CIOS Nariño',
'modo_correo'      => 'php',   // 'registro' deja los mensajes en almacen/registro
```

Sin correo funcionando, un asistente que pierda su sesión no puede volver a entrar: el
código de acceso es lo único que lo identifica.

---

## 3.7 Entrar al panel

| | |
|---|---|
| **Dirección** | `https://tic.narino.gov.co/cumbreAI/admin/entrar` |
| **Usuario** | El correo que diste en el paso 4 del asistente |
| **Dónde queda guardada** | Tabla `evt_usuario` (el prefijo es el que elegiste en el paso 2) |

Tres cosas que confunden y conviene tener claras:

**No existe una carpeta `admin/` en el servidor.** Todo pasa por `index.php`; `/cumbreAI/admin/`
es una ruta de la aplicación, no un directorio. Si entras ahí sin sesión, responde un 303 y te
lleva a `/cumbreAI/admin/entrar`, que es el formulario. Si el navegador se queda en `/admin/`
mostrando un error del servidor y no ese formulario, el problema es de Apache —falta
`mod_rewrite`— y no de la plataforma.

**La contraseña no está guardada en ninguna parte.** En `evt_usuario` solo queda su hash
Argon2id. Nadie —tampoco quien tenga acceso completo a la base de datos— puede leerla; sí se
puede reemplazar por otra, que es lo que hacen el asistente y la herramienta de consola.

**Los asistentes al evento no están en esa tabla.** `evt_usuario` es solo el equipo
organizador. Quien se preregistra va a `evt_persona` y entra por correo con un código de un
solo uso, sin contraseña.

---

## 4. Si hay nginx por delante

Plesk suele poner nginx como proxy de Apache. Dos consecuencias:

**La IP real llega en una cabecera.** Para que el límite de intentos y la bitácora vean la
IP del visitante y no la del proxy, declara el proxy en `config/config.php`:

```php
'proxies_confiables' => ['127.0.0.1', '::1'],
```

Solo se hace caso a `X-Forwarded-For` cuando la conexión viene de una de esas direcciones.
Si se confiara siempre, cualquiera podría falsear su origen y saltarse los bloqueos.

**Algunos estáticos no pasan por Apache.** Si en Plesk está activada la opción «Servir
archivos estáticos directamente por nginx», las reglas del `.htaccess` no se aplican a esos
archivos. La aplicación no depende de eso —las cabeceras de seguridad también salen desde
PHP y el código comprueba la constante `EVENTOS_TIC`—, pero para que las carpetas internas
queden bloqueadas también en nginx, agrega en **Dominio → Configuración de Apache y nginx →
Directivas adicionales de nginx**:

```nginx
location ~ ^/cumbreAI/(app|config|almacen|docs|herramientas|pruebas)/ { deny all; }
location ~ ^/cumbreAI/.*\.(sql|log|md|bak|old)$ { deny all; }
```

---

## 5. Comprobación tras instalar

| Qué revisar | Cómo | Qué debe pasar |
|---|---|---|
| Portada | `https://tic.narino.gov.co/cumbreAI/` | Se ve el preregistro |
| Código interno protegido | `.../cumbreAI/app/Nucleo/Bd.php` | 403 o 404, nunca código |
| Configuración protegida | `.../cumbreAI/config/config.php` | 403 o 404, nunca la contraseña |
| Instalador cerrado | `.../cumbreAI/instalar` | «La plataforma ya está instalada» |
| Rutas limpias | `.../cumbreAI/agenda` | Carga la agenda (si da 404, falta `mod_rewrite`) |
| Códigos QR | Administrador → QR por día → Imprimir | El pliego sale con la URL del dominio real |
| Escaneo real | Apunta la cámara del celular al pliego | Abre la plataforma y pide identificarse |

---

## 6. Copias de seguridad

**Plesk → Copias de seguridad → Programar.** Durante la semana del evento, una copia diaria.

Lo que hay que respaldar:

- **La base de datos.** Es donde están los registros y las asistencias.
- **`config/config.php`.** Contiene la llave de cifrado. **Sin ese archivo, los documentos
  de identidad guardados quedan ilegibles para siempre**: no hay forma de recuperarlos.
- **`almacen/logos` y `almacen/fotos`.**

Restaura la copia una vez antes del evento, en un entorno de prueba. Una copia que nunca se
restauró no es una copia.

---

## 7. Actualizar a una versión nueva

1. Copia de seguridad completa (base de datos y archivos).
2. Sube los archivos nuevos **sin tocar `config/` ni `almacen/`**.
3. Si la versión trae cambios de esquema, crea `config/permitir-reinstalar`, entra a
   `/instalar` y elige el modo **Actualizar**: conserva los datos y solo aplica lo que
   falta.
4. Borra `config/permitir-reinstalar`.

---

## 8. Problemas frecuentes

### No puedo entrar al panel

Empieza por saber en qué estado está la instalación. Por consola —en Plesk, **Acceso SSH**, o
**Tareas programadas → Ejecutar un script PHP**:

```bash
php herramientas/cuenta.php estado
```

Responde qué hay y qué falta: el archivo de configuración, la conexión, las 16 tablas, cuántas
cuentas administradoras activas existen, cuántos eventos, y el nombre exacto de la tabla donde
viven las cuentas. Con eso, el caso es uno de estos cuatro:

| Lo que dice el diagnóstico | Qué pasó | Cómo se arregla |
|---|---|---|
| `✕ cuentas administradoras activas: 0` | La instalación se interrumpió antes de crear la cuenta | El asistente se reabre solo en **modo reparación**: entra a `/cumbreAI/instalar`. O `cuenta.php crear` |
| Hay cuenta, pero no recuerdas la contraseña | — | `php herramientas/cuenta.php clave --correo=…` |
| Entra pero se queda pidiendo el código de seis dígitos | El segundo factor quedó en un teléfono que ya no está | `php herramientas/cuenta.php sin-2fa --correo=…` |
| `✕ tablas` o `✕ conexión` | La base no es la que cree, o le falta el esquema | Revisa `config/config.php` y repite el asistente en modo **Actualizar** |

Y dos cosas que **no** son el problema, aunque lo parezcan:

- Que `/cumbreAI/admin/` no muestre un formulario. No debe: redirige a `/cumbreAI/admin/entrar`.
- Que la contraseña no aparezca en ninguna tabla. Nunca aparece: se guarda en hash.

Si no hay forma de ejecutar PHP por consola, todo lo anterior menos el diagnóstico se puede
hacer desde el navegador: crea el archivo `config/permitir-reinstalar` con el administrador de
archivos de Plesk, entra a `/cumbreAI/instalar`, elige el modo **Actualizar** —conserva los
datos— y vuelve a dar los datos de la cuenta administradora en el paso 4. Borra
`permitir-reinstalar` al terminar.

### Otros

**Todas las rutas dan 404 menos la portada.**
Falta `mod_rewrite` o `AllowOverride` está en `None`. En Plesk: **Dominio → Configuración
de Apache y nginx**, y verifica que se permitan los `.htaccess`.

**«No se pudo escribir config/config.php».**
Permisos de `config/`. Debe ser escribible por el usuario del dominio.

**Las tildes salen como signos raros.**
El cotejamiento de la base no es utf8mb4. Créala de nuevo con
`utf8mb4_unicode_ci` y vuelve a instalar.

**El instalador dice que la base de datos ya tiene tablas.**
Es correcto si estás reinstalando. Elige **Actualizar** para conservar los datos o
**Limpio** para empezar de cero —esto último borra todo lo registrado.

**Los correos no llegan.**
Revisa `almacen/registro/`. Si dice «mail() falló», el problema está en el servidor de
correo del dominio, no en la plataforma. Mientras se resuelve, `'modo_correo' => 'registro'`
deja los mensajes en ese archivo para poder seguir probando.

**Alguien escaneó un QR y le pidió identificarse otra vez.**
Es el comportamiento correcto: sin sesión, el código lleva al acceso y después continúa
solo. Si pasa siempre, revisa que las cookies no estén bloqueadas y que el dominio del QR
impreso coincida con el actual.

---

## 9. Antes de abrir al público

- [ ] HTTPS activo, con redirección permanente y HSTS.
- [ ] `/instalar` responde 403.
- [ ] `config/config.php` y `app/` no son accesibles desde el navegador.
- [ ] Correo saliente probado con una cuenta real.
- [ ] Segundo factor activado en todas las cuentas de administrador.
- [ ] Copia de seguridad programada **y una restauración probada**.
- [ ] Códigos QR de las jornadas impresos con el dominio definitivo.
- [ ] Contraste del tema revisado (Identidad → Revisión de contraste): la puerta del
      recinto suele tener sol directo.
