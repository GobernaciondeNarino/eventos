# Revisión de seguridad

Plataforma de Eventos TIC · Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño
Revisión sobre la **fase 1 (interfaz)**, con el diseño de controles que la fase 2 debe implementar.

---

## 1. Qué se revisó y qué no

Lo entregado hasta ahora es la capa de interfaz: HTML, CSS y JavaScript de navegador,
sin servidor ni base de datos. Eso acota la revisión.

| Alcance | Estado |
|---|---|
| Código de navegador (inyección en el DOM, manejo de datos, dependencias) | Revisado |
| Configuración del servidor web (cabeceras, exposición de archivos) | Entregada como `.htaccess`, sin probar en producción |
| Datos que viajan en los códigos QR | Revisado a nivel de diseño |
| Autenticación, autorización, sesiones, SQL | **No implementado todavía** — el diseño está en la sección 5 |

Una advertencia que conviene no perder de vista: **ninguna pantalla de administración
está protegida hoy**. `public/admin/` es HTML estático y cualquiera que conozca la URL
lo abre. Es lo esperable en un prototipo de interfaz, pero significa que esto **no debe
publicarse en un dominio accesible desde internet** hasta que exista la fase 2. Para la
validación, sírvelo en local o en una red interna.

---

## 2. Decisiones de diseño que ya reducen riesgo

Estas no son promesas para después: están tomadas en el código de la fase 1.

### 2.1 El QR no lleva datos personales

El código del carnet contiene únicamente una URL con un identificador opaco:

```
https://eventos.narino.gov.co/c/9f3a2b7d10c4e5
```

No lleva el nombre, ni el documento, ni el correo, ni la caracterización. Quien fotografíe
un carnet ajeno —cosa que pasa todo el tiempo en un evento— no obtiene nada por sí mismo:
tiene que preguntarle al servidor, y el servidor decide qué entrega según quién pregunte.

El alterno habría sido meter una vCard completa dentro del QR. Es más simple y funciona sin
conexión, pero convierte cada carnet colgado del cuello en una publicación permanente del
documento de identidad de esa persona. No compensa.

### 2.2 El código del día rota y tiene ventana

El QR de acceso pertenece a la jornada, no al evento. Cambia cada día, se puede regenerar a
mano si el pliego impreso se filtra —una foto en redes sociales basta— y el anterior queda
inválido en ese momento. El esquema incluye `abre_a` y `cierra_a` por jornada: un escaneo
fuera de esa ventana se rechaza y queda anotado.

### 2.3 Los datos sensibles viven aparte

La caracterización (género, pertenencia étnica, condición de discapacidad) es dato sensible
según el artículo 5 de la Ley 1581 de 2012. En el esquema está en `persona_caracterizacion`,
una tabla separada de `persona`, con dos consecuencias prácticas: las consultas corrientes
—listados, conteos, control de acceso— nunca la tocan, y su lectura se puede auditar de
forma independiente.

En la interfaz, la exportación con caracterización está separada de la exportación normal y
pasa por una confirmación que dice explícitamente qué contiene.

### 2.4 Sin dependencias externas en tiempo de ejecución

No hay CDN, ni framework, ni gestor de paquetes en el navegador. Las tipografías están
autoalojadas (`herramientas/descargar-fuentes.py`), el generador de QR es propio y validado.
Ventajas concretas:

- No hay forma de que un tercero comprometido inyecte código en la plataforma.
- La IP de cada asistente no viaja a servidores ajenos.
- La política de seguridad de contenido puede prohibir orígenes externos por completo.
- La interfaz funciona igual en sedes con salida a internet restringida.

### 2.5 Escape sistemático al escribir en el DOM

Todo lo que proviene de una persona pasa por `UI.esc()` antes de tocar `innerHTML`
(`public/assets/js/ui.js`). No se usa `eval` ni `new Function` en ninguna parte.

### 2.6 CSV a prueba de fórmulas

`UI.aCsv()` antepone un apóstrofo a los valores que empiezan por `=`, `+`, `-`, `@` o
tabulación. Sin eso, alguien podría escribir `=HYPERLINK(...)` en el campo «entidad» del
preregistro público y esa celda se ejecutaría al abrir el reporte en Excel, en el equipo de
un funcionario. Es un ataque viejo y sigue funcionando.

### 2.7 Aleatoriedad correcta

La rotación de tokens usa `crypto.getRandomValues()`, no `Math.random()`. En la fase 2 el
equivalente es `random_bytes()` de PHP.

### 2.8 Cabeceras y exposición de archivos

`public/.htaccess` trae `X-Content-Type-Options`, `X-Frame-Options: DENY`,
`Referrer-Policy`, `Permissions-Policy` y una CSP sin orígenes externos. `.htaccess` en la
raíz deniega todo, por si el alojamiento no permite apuntar el dominio a `public/`.

---

## 3. Hallazgos de esta revisión

### 3.1 El prototipo guarda en el navegador lo que se escribe — *aceptado, mitigado*

**Qué pasa.** Para poder recorrer las pantallas sin servidor, el formulario guarda el
perfil (nombre, documento, teléfono) en `localStorage`. Si alguien valida el prototipo con
datos reales en un equipo compartido, esos datos quedan ahí.

**Mitigación aplicada.** La pantalla del carnet lo dice de forma visible e incluye un botón
«Borrar mis datos de prueba».

**En fase 2.** Desaparece: los datos van al servidor y en el navegador solo queda el
identificador de sesión.

### 3.2 Un logo en SVG es código ejecutable — *pendiente para fase 2*

**Qué pasa.** El panel de identidad acepta SVG, que es lo correcto para que el carnet
impreso no salga pixelado. Pero un SVG puede contener `<script>`. Dentro de una etiqueta
`<img>` no se ejecuta —que es como lo usa la interfaz—, pero si el archivo se sirve desde
`/almacen/logos/x.svg` y alguien navega directamente a esa URL, el script corre en el
origen del sitio, con las cookies de sesión de quien lo abra.

**Controles necesarios al implementar la subida:**

1. Validar el tipo real del archivo, no la extensión ni el `Content-Type` que envía el
   navegador.
2. Para SVG: limpiar el archivo (quitar `<script>`, `<foreignObject>`, atributos `on*` y
   referencias externas) o rechazarlo y aceptar solo PNG y WEBP.
3. Servir `almacen/` con `Content-Disposition: attachment` y
   `Content-Security-Policy: sandbox`, o desde un subdominio sin sesión.
4. Reescribir siempre los mapas de bits (PNG, JPG, WEBP) para descartar cargas útiles
   escondidas en los metadatos.
5. Nombre de archivo generado por el sistema, nunca el que venga del cliente.

### 3.3 La CSP necesita `style-src 'unsafe-inline'` — *compromiso consciente*

Las pantallas usan atributos `style=` para ajustes puntuales de maquetación, así que la
política no puede prohibir estilos en línea. Los scripts sí van todos en archivos: no hay
un solo `<script>` en línea en el proyecto, y por eso `script-src 'self'` va sin
excepciones, que es lo que de verdad detiene un XSS.

**Recomendación.** Al portar a PHP, mover esos atributos a clases y quitar la excepción.

### 3.4 El instalador es la puerta trasera clásica — *previsto*

Un asistente de instalación accesible después de instalar permite reinstalar encima de los
datos y quedarse con una cuenta administradora. El paso 6 lo advierte en rojo, y la regla
para la fase 2 es dura: si existe la marca de instalación completa y la carpeta
`public/install/` todavía está ahí, **la aplicación se niega a arrancar**. No un aviso: un
bloqueo.

### 3.5 Sin HTTPS nada de lo anterior sirve — *bloqueante para producción*

El asistente lo marca como aviso, no como error, porque en desarrollo local es normal. Para
producción es condición de salida: sin TLS, las contraseñas del equipo organizador y los
tokens de los carnets viajan en claro por la red del recinto, que suele ser wifi abierto.

---

## 4. Datos personales (Ley 1581 de 2012)

| Exigencia | Cómo se atiende |
|---|---|
| Autorización previa e informada | Casilla obligatoria en el preregistro, con la finalidad declarada. El esquema guarda `autorizo_datos_en` |
| Finalidad determinada | Gestión del evento: acreditación, control de asistencia y reportes de cobertura |
| Datos sensibles con tratamiento reforzado | Tabla aparte, opcionales, exportación con confirmación específica |
| Derecho a conocer, actualizar y suprimir | El intercambio de contactos guarda `revocado_en` para poder deshacerlo. **Falta** definir el procedimiento de supresión a solicitud |
| Minimización | Solo nombre y documento son obligatorios. Todo lo demás es opcional |
| Circulación restringida | El QR no expone datos; el intercambio de contacto comparte cuatro campos y el teléfono es opcional |

**Pendiente:** política de retención. Hoy nada dice cuánto tiempo se conservan los registros
después del evento. Debe definirse con el área jurídica y ejecutarse de forma automática.

---

## 5. Controles que la fase 2 debe implementar

Lista de verificación para cuando entre el backend.

### Autenticación y sesión
- [ ] Contraseñas con `password_hash()` y Argon2id. Nunca MD5, SHA1 ni cifrado reversible.
- [ ] Segundo factor obligatorio para el rol administrador.
- [ ] Bloqueo progresivo por intentos fallidos, por cuenta y por IP.
- [ ] Sesiones en base de datos con `expira_en`; cookies `HttpOnly`, `Secure`, `SameSite=Lax`.
- [ ] Rotar el identificador de sesión al iniciar sesión y al cambiar de privilegio.
- [ ] Cierre de sesión que invalide del lado del servidor, no solo borre la cookie.

### Autorización
- [ ] Verificar el rol en **cada** petición del servidor. Ocultar un botón no es un control.
- [ ] Mínimo privilegio: el operador de acceso no ve caracterización ni exporta.
- [ ] Verificar pertenencia al evento: un operador del evento A no puede sellar en el B.

### Entrada y salida
- [ ] Consultas exclusivamente con sentencias preparadas. Ni una concatenación.
- [ ] Validar en el servidor todo lo que la interfaz ya valida en el navegador.
- [ ] Escapar al renderizar en la plantilla, no al guardar.
- [ ] Token anti-CSRF en cada formulario y en cada petición que modifique datos.

### Tokens de QR
- [ ] 128 bits de entropía como mínimo, de `random_bytes()`.
- [ ] El token del carnet identifica; **no autoriza por sí solo**. Sellar un ingreso exige
      además la sesión válida de un operador con permiso sobre ese evento.
- [ ] Límite de frecuencia por token y por IP: sin él, `/c/{token}` permite enumerar
      credenciales a fuerza bruta.
- [ ] Revocación de credencial (`revocada_en`) para carnets perdidos.

### Cifrado
- [ ] Documento de identidad cifrado en reposo (`documento_cifrado`), con un hash con sal
      aparte (`documento_hash`) para poder buscar duplicados sin descifrar.
- [ ] Llave fuera de la base de datos, en variable de entorno o en `config/` fuera de la
      raíz web, con rotación documentada.

### Operación
- [ ] Registro en bitácora de: inicios de sesión, cambios de rol, exportaciones,
      aprobaciones, rotaciones de token y accesos a caracterización.
- [ ] La bitácora solo admite inserciones.
- [ ] Copia de seguridad diaria durante la semana del evento y una antes de cada migración.
- [ ] Prueba de restauración antes del evento. Una copia que nunca se restauró no es una copia.

---

## 6. Nginx

Traducción de `public/.htaccess` para alojamientos con Nginx.

```nginx
server {
    root /ruta/al/proyecto/public;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(self), microphone=(), geolocation=()" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'" always;

    autoindex off;

    location ~ /\. { deny all; }
    location ~* \.(sql|log|md|ini|bak|old)$ { deny all; }

    # Las subidas son datos, no código.
    location ^~ /almacen/ {
        add_header Content-Disposition "attachment" always;
        add_header Content-Security-Policy "sandbox" always;
    }
}
```

---

## 7. Antes de salir a producción

1. Existe la fase 2 y `public/admin/` está autenticado.
2. HTTPS con certificado válido y HSTS activo.
3. `public/install/` eliminado.
4. Usuario de base de datos dedicado, sin privilegios sobre otras bases.
5. `config/` fuera de la raíz web y sin permiso de lectura para otros usuarios del servidor.
6. Copias de seguridad programadas **y una restauración probada**.
7. Política de retención de datos definida y automatizada.
8. Revisión con datos reales del contraste del tema elegido: la puerta del recinto suele
   tener sol directo.
