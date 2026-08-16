# Configuración de correo

Plataforma de Eventos TIC · Secretaría TIC, Innovación y Gobierno Abierto — Gobernación de Nariño

Este documento recoge cómo queda configurado el envío de correo para
**https://tic.narino.gov.co/cumbreAI/**, por qué se hizo así, y qué mirar cuando algo falle.

---

## 0. Por qué importa

Sin correo funcionando, la plataforma pierde la mitad de lo que hace:

- **El asistente que cierre sesión no puede volver a entrar.** No tiene contraseña: entra con
  un código de seis dígitos que se le manda al correo. Si el correo no sale, se queda fuera.
- **El carnet no llega.** Se genera en pantalla al terminar el preregistro, y quien cierre esa
  pestaña sin guardarla pierde el enlace.
- **El equipo organizador no puede restablecer contraseñas** sin pasar por la consola.

Por eso el módulo no se limita a guardar unos campos: comprueba, prueba contra el servidor de
verdad y explica cada código de error.

---

## 1. Qué hay que saber del correo institucional

| | |
|---|---|
| **Cuenta** | `hosting@narino.gov.co` |
| **Proveedor** | Google Workspace |
| **Servidor SMTP** | `smtp.gmail.com` |
| **Puerto** | `587` con STARTTLS (alternativa: `465` con SSL directo) |
| **Usuario** | La dirección completa: `hosting@narino.gov.co` |
| **Contraseña** | Una **contraseña de aplicación** de 16 letras. **No sirve la contraseña normal de la cuenta.** |
| **Cupo diario** | ~2.000 mensajes (Workspace). Una cuenta gratuita son 500. |

### Por qué SMTP y no la función `mail()` de PHP

`mail()` entrega al servidor de correo local del servidor web. En este despliegue ese servidor
**no es el que gestiona narino.gov.co**: el buzón está en Google. Un mensaje que salga por ahí
diciendo venir de `@narino.gov.co` no pasa SPF ni lleva firma DKIM del dominio, así que llega a
no deseado en el mejor caso y rebota en el peor.

Hablando SMTP directamente con Google, el mensaje sale autenticado como la cuenta institucional,
con la firma que Google le pone, y llega a la bandeja de entrada.

---

## 2. Obtener la contraseña de aplicación

1. Entrar a `myaccount.google.com` con `hosting@narino.gov.co`.
2. **Seguridad → Verificación en dos pasos.** Tiene que estar activa; sin ella Google no ofrece
   contraseñas de aplicación.
3. Al final de esa página: **Contraseñas de aplicaciones**.
4. Crear una con un nombre reconocible — por ejemplo `eventos`.
5. Google muestra **16 letras en cuatro grupos**. Se copian en el panel; los espacios se quitan
   solos al guardar.

> **Solo se ven una vez.** Si se pierden, se revoca esa y se genera otra: no se pueden recuperar.

> **Una contraseña de aplicación da acceso completo a la cuenta.** Si se filtra —por chat, por
> correo, en una captura de pantalla— hay que **revocarla desde esa misma pantalla** y generar
> otra. No basta con cambiarla en la plataforma.

Si la opción no aparece, la cuenta es de Workspace y el administrador del dominio la tiene
bloqueada en la consola de administración (**Seguridad → Controles de API / Acceso de
aplicaciones menos seguras**).

---

## 3. Configurar en la plataforma

**Administración → Correo** (solo rol administrador).

| Campo | Valor |
|---|---|
| Modo de envío | **Servidor SMTP** |
| Remitente | `hosting@narino.gov.co` |
| Nombre del remitente | `Secretaría TIC · Gobernación de Nariño` |
| Servidor | `smtp.gmail.com` |
| Puerto | `587` |
| Seguridad | **STARTTLS** |
| Usuario | `hosting@narino.gov.co` |
| Contraseña de aplicación | las 16 letras |
| Espera máxima | `15` segundos |
| Verificar certificado | **activado** |

Después, **Probar ahora** con una dirección propia. Sin dirección, solo comprueba que se puede
conectar y autenticar; con dirección, envía un mensaje completo.

### El remitente tiene que ser la cuenta autenticada

Google solo deja enviar «como» la cuenta con la que uno se autentica, o como un alias dado de
alta y **verificado** en *Gmail → Configuración → Cuentas → Enviar como*. Poner en «Remitente»
una dirección distinta sin haberla verificado termina en `550-5.7.1`, o en que Google reescriba
el remitente sin avisar.

Lo más simple: la misma dirección en «Remitente» y en «Usuario».

---

## 4. Dónde queda guardado

En `config/config.php`, junto al resto de la configuración:

```php
'modo_correo'                => 'smtp',
'correo_remitente'           => 'hosting@narino.gov.co',
'correo_nombre'              => 'Secretaría TIC · Gobernación de Nariño',
'smtp_host'                  => 'smtp.gmail.com',
'smtp_puerto'                => 587,
'smtp_seguridad'             => 'tls',        // 'tls' | 'ssl' | 'ninguna'
'smtp_usuario'               => 'hosting@narino.gov.co',
'smtp_clave'                 => '····················',
'smtp_espera'                => 15,
'smtp_verificar_certificado' => true,
```

Sobre cómo se guarda la contraseña, con honestidad: **queda en claro en ese archivo**, igual que
la contraseña de la base de datos y la llave de cifrado. Cifrarla no aportaría nada real, porque
la llave con la que se descifraría vive en el mismo archivo. Lo que la protege es:

- El archivo tiene permisos `0640` y pertenece al usuario del dominio.
- `.htaccess` bloquea toda la carpeta `config/` por web.
- Está en `.gitignore`: nunca llega al repositorio.
- **La plataforma no la devuelve nunca al navegador.** El formulario muestra un campo vacío y la
  nota «hay una guardada»; dejarlo en blanco significa «no la cambies».
- **No aparece en la transcripción SMTP** que se enseña en pantalla: esa línea sale como
  `[contraseña en base64]`.
- **No entra en la bitácora.** Solo se anota que se cambió, no cuál.

Quien pueda leer `config/config.php` ya tiene la base de datos entera y la llave de cifrado; el
correo no es el eslabón débil ahí.

---

## 5. Cuando algo falla

**Administración → Correo** enseña, en este orden:

1. **Estado de la configuración** — lo que está mal *antes* de tocar la red: modo equivocado,
   puerto que no cuadra con la seguridad, contraseña que no parece de aplicación, remitente que
   no es la cuenta autenticada, `openssl` ausente.
2. **Resultado de la última prueba** — el código del servidor, su texto, qué hacer, y la
   **conversación completa** con el servidor.
3. **Tabla de códigos** — de referencia, siempre visible.

### Códigos y qué significan

| Código | Qué dice el servidor | Qué pasa | Qué hacer |
|---|---|---|---|
| `535-5.7.8` | Username and Password not accepted | La contraseña no es de aplicación, o el usuario no lleva dominio | Generar contraseña de aplicación; usuario = dirección completa |
| `534-5.7.9` | Application-specific password required | La cuenta tiene 2FA y se mandó la contraseña normal | Contraseña de aplicación |
| `534-5.7.14` | Please log in via your web browser | Google no reconoce el servidor | Abrir `accounts.google.com/DisplayUnlockCaptcha` y reintentar en 10 min |
| `530-5.5.1` | Authentication Required | Sin credenciales, o antes de cifrar | Completar usuario y contraseña; STARTTLS en el 587 |
| `454-4.7.0` | Too many login attempts | Demasiados intentos seguidos | Esperar unos minutos |
| `550-5.7.1` | Not allowed to send as | Remitente que no es la cuenta ni un alias verificado | Igualar «Remitente» y «Usuario» |
| `550-5.4.5` | Daily user sending limit exceeded | Cupo diario agotado | Repartir los envíos; ver el apartado 7 |
| `550-5.1.1` | User unknown | La dirección de destino no existe | Revisar cómo está escrita |
| `553-5.1.8` | Domain of sender address does not exist | El dominio del remitente no resuelve | Usar un dominio real con MX |
| `552-5.3.4` | Message too large | Mensaje demasiado grande | No debería pasar aquí |
| `421-4.7.0` | Try again later | Fallo temporal o límite de frecuencia | Reintentar más tarde |
| — | Connection refused · Timed out | El puerto de salida está cerrado | Probar 465/SSL si el 587 no responde |
| — | TLS / certificado | El cifrado no se estableció | `openssl` activo y reloj del servidor en hora |

### Si el puerto está cerrado

Muchos proveedores —OVH entre ellos— cierran la salida SMTP para frenar el envío masivo. El 25
está cerrado casi siempre; el 587 y el 465 suelen estar abiertos, pero no siempre los dos.

Desde SSH:

```bash
nc -vz smtp.gmail.com 587
nc -vz smtp.gmail.com 465
```

Si ninguno responde, hay que pedir la apertura al proveedor. Un proxy de salida HTTP no ayuda:
PHP no lo usa para SMTP.

---

## 6. Que los mensajes lleguen a la bandeja de entrada

Que el envío funcione no garantiza que el mensaje se lea. Esto se configura **en el DNS del
dominio**, no en la plataforma, y lo hace el área de sistemas:

- **SPF** — el TXT de `narino.gov.co` debe incluir `include:_spf.google.com`.
- **DKIM** — se activa en la consola de Workspace (*Aplicaciones → Google Workspace → Gmail →
  Autenticar correo*) y se publica el TXT que genera.
- **DMARC** — un TXT en `_dmarc.narino.gov.co`. Empezar con `p=none` para poder observar antes
  de endurecer.

Comprobación rápida: mandar la prueba a una dirección de Gmail y abrir **Mostrar original**.
Deben salir `SPF: PASS`, `DKIM: PASS` y `DMARC: PASS`.

---

## 7. Límites de envío

Google Workspace: unos **2.000 mensajes al día** por cuenta. Cuenta gratuita: **500**.

Para el uso normal de la plataforma sobra: un código de acceso aquí, un carnet allá. Donde se
queda corto es en una convocatoria masiva —mandar el carnet a mil inscritos de golpe—. En ese
caso conviene repartir el envío en varios días, o usar un servicio pensado para eso.

Cuando el cupo se agota, Google responde `550-5.4.5` y deja de aceptar hasta el día siguiente.

---

## 8. Modos alternativos

| Modo | Cuándo usarlo |
|---|---|
| **Servidor SMTP** | Siempre que el correo esté en Google o Microsoft. Es el caso de esta instalación. |
| **Función mail()** | Solo si el buzón del dominio está en el mismo Plesk que la web. |
| **Solo registrar** | Pruebas y desarrollo. No envía nada: escribe los mensajes en `almacen/registro/`. Útil para probar el resto de la plataforma sin gastar cupo ni molestar a nadie. |

En modo «solo registrar», la pantalla de correo lo marca como **bloqueante**: es correcto en
desarrollo y es un fallo en producción.

---

## 9. Desde la consola

El modo y el remitente se pueden fijar al instalar:

```bash
php herramientas/instalar.php … --url=https://tic.narino.gov.co/cumbreAI
```

El instalador deja `modo_correo` en `php` si `mail()` existe, y en `registro` si no. La
configuración SMTP se completa después desde el panel, que es donde se puede probar.

---

## 10. Pruebas

```bash
php pruebas/smtp.php     # el cliente SMTP contra un servidor real, TLS incluido
php pruebas/correo.php   # formato MIME, RFC 2047 e inyección de cabeceras
```

`pruebas/smtp.php` levanta un servidor SMTP de mentira en un puerto local —con certificado
autofirmado para los escenarios con TLS— y reproduce las respuestas que dan de verdad Google y
Microsoft: `535`, `534`, `550`, respuestas de varias líneas, STARTTLS, SSL directo, un servidor
que no contesta y un puerto cerrado. De cada una comprueba dos cosas: que el cliente la entienda,
y **que la explicación que sale en pantalla sea la correcta**. Detectar el 535 no sirve de nada
si al administrador se le enseña «no se pudo enviar».

También comprueba que la contraseña no aparezca en la transcripción.

---

## 11. Historial de esta configuración

| Fecha | Qué se hizo |
|---|---|
| 2026-08-14 | Se detecta que no sale ningún correo. La plataforma solo tenía `mail()`, que no sirve con el buzón en Google Workspace. |
| 2026-08-14 | Se escribe un cliente SMTP propio (`app/Nucleo/Smtp.php`), sin dependencias externas. |
| 2026-08-14 | Nueva pantalla **Administración → Correo**: configuración, revisión, prueba real y tabla de códigos. |
| 2026-08-14 | Se crea la contraseña de aplicación `eventos` para `hosting@narino.gov.co`. **Queda expuesta al compartirla en un canal de chat; se revoca y se genera otra.** |
| 2026-08-14 | Pendiente del área de sistemas: verificar SPF, DKIM y DMARC del dominio. |

> Cuando cambie algo —la cuenta, el proveedor, los límites— **actualizar esta tabla**. Una
> configuración de correo sin historial es la que nadie se atreve a tocar dos años después.
