# Esquema de datos

Plataforma de Eventos TIC · versión del esquema **1.0.0**

> Documento generado con `node herramientas/generar-doc-esquema.js` a partir de
> `public/assets/js/esquema.js`, la misma definición que el asistente de instalación
> usa para armar el plan y el SQL. No lo edites a mano: edita el esquema y vuelve a
> generarlo.

Las tablas llevan el prefijo que se elija durante la instalación (`evt_` por defecto),
para poder compartir la base con otras aplicaciones del alojamiento. En este documento
se muestran con ese prefijo.

## Resumen

| Tabla | Columnas | Para qué existe |
|---|---:|---|
| `evt_evento` | 9 | Un registro por evento. La plataforma es multievento desde el día uno. |
| `evt_evento_tema` | 6 | Identidad visual: colores, tipografía y logo. Es lo que el backend imprime como window.EVENTO_TEMA. |
| `evt_evento_dia` | 9 | Las jornadas. El código QR de acceso cuelga de aquí, no del evento. |
| `evt_persona` | 15 | Quien se preregistra. El documento se guarda cifrado y con un hash aparte para poder buscar sin descifrar. |
| `evt_persona_caracterizacion` | 5 | Datos sensibles (Ley 1581, art. 5) en tabla aparte: se consultan solo cuando de verdad se necesitan y su acceso se audita. |
| `evt_credencial` | 7 | El carnet. El token es lo único que viaja en el QR; nunca datos personales. |
| `evt_asistencia` | 7 | Un ingreso por persona y jornada. La llave única es la que impide contar dos veces a la misma persona. |
| `evt_contacto` | 5 | Intercambio de datos entre asistentes. Guarda quién escaneó a quién y cuándo, para poder revertirlo si alguien lo pide. |
| `evt_propuesta` | 12 | Lo que envía un expositor en el preregistro. Al aprobarse se convierte en charla. |
| `evt_charla` | 6 | La agenda pública: propuestas aprobadas con horario y salón asignados. |
| `evt_usuario` | 12 | El equipo organizador. Contraseña con Argon2id; el segundo factor es obligatorio para el rol administrador. |
| `evt_sesion` | 7 | Sesiones en base de datos, no en archivos: permite cerrar sesiones a distancia y sobrevive a varios servidores. |
| `evt_bitacora` | 8 | Auditoría. Quién hizo qué, cuándo y desde dónde. Solo se inserta: no se actualiza ni se borra. |
| `evt_migracion` | 3 | Qué versión del esquema está aplicada. Sin esto, el asistente no sabría si actualizar o instalar. |

## Cómo se relacionan

```
evento ─┬─ evento_tema        (identidad visual: colores, tipografía, logo)
        ├─ evento_dia ────┬── asistencia
        │                 └── charla
        └─ persona ───┬─── persona_caracterizacion   (datos sensibles, aparte)
                      ├─── credencial                (el carnet y su token)
                      ├─── asistencia                (un ingreso por jornada)
                      ├─── contacto                  (intercambios por QR)
                      └─── propuesta ─── charla      (agenda, al aprobarse)

usuario ─── sesion            (equipo organizador)
bitacora                      (auditoría; solo inserciones)
migracion                     (versión del esquema aplicada)
```

Tres decisiones que explican la forma del modelo:

1. **Todo cuelga de `evento`.** La plataforma es multievento desde el principio, así que
   la identidad, las jornadas y las personas pertenecen a un evento y no al sistema.
2. **La caracterización está separada de `persona`.** Son datos sensibles según la Ley
   1581 de 2012; teniéndolos aparte, las consultas del día a día no los tocan y su
   lectura se puede auditar por separado.
3. **El código QR cuelga de `evento_dia`, no de `evento`.** Es lo que permite que el
   código cambie cada jornada y que el del día anterior deje de servir.

## Detalle de cada tabla

### `evt_evento`

Un registro por evento. La plataforma es multievento desde el día uno.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `nombre` | `VARCHAR(160) NOT NULL` |
| `dependencia` | `VARCHAR(160) NOT NULL DEFAULT ''` |
| `sede` | `VARCHAR(160) NOT NULL DEFAULT ''` |
| `fecha_inicio` | `DATE NOT NULL` |
| `jornadas` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` |
| `estado` | `ENUM('borrador','abierto','en_curso','cerrado') NOT NULL DEFAULT 'borrador'` |
| `activo` | `TINYINT(1) NOT NULL DEFAULT 0` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `KEY idx_evento_estado (estado, activo)`

### `evt_evento_tema`

Identidad visual: colores, tipografía y logo. Es lo que el backend imprime como window.EVENTO_TEMA.

| Columna | Tipo |
|---|---|
| `evento_id` | `INT UNSIGNED NOT NULL` |
| `preset` | `VARCHAR(40) NOT NULL DEFAULT 'tic-nocturno'` |
| `tipografia` | `VARCHAR(40) NOT NULL DEFAULT 'tecnologica'` |
| `colores_json` | `JSON NULL` |
| `logo_ruta` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `actualizado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (evento_id)`
- `CONSTRAINT fk_tema_evento FOREIGN KEY (evento_id) REFERENCES evt_evento (id) ON DELETE CASCADE`

### `evt_evento_dia`

Las jornadas. El código QR de acceso cuelga de aquí, no del evento.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `evento_id` | `INT UNSIGNED NOT NULL` |
| `numero` | `TINYINT UNSIGNED NOT NULL` |
| `fecha` | `DATE NOT NULL` |
| `abre_a` | `TIME NOT NULL DEFAULT '07:00:00'` |
| `cierra_a` | `TIME NOT NULL DEFAULT '18:00:00'` |
| `token` | `CHAR(32) NOT NULL` |
| `token_rotado_en` | `DATETIME NULL` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY uq_dia (evento_id, numero)`
- `UNIQUE KEY uq_token (token)`
- `CONSTRAINT fk_dia_evento FOREIGN KEY (evento_id) REFERENCES evt_evento (id) ON DELETE CASCADE`

### `evt_persona`

Quien se preregistra. El documento se guarda cifrado y con un hash aparte para poder buscar sin descifrar.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `evento_id` | `INT UNSIGNED NOT NULL` |
| `nombre` | `VARCHAR(160) NOT NULL` |
| `correo` | `VARCHAR(190) NOT NULL` |
| `tipo_documento` | `ENUM('CC','CE','TI','PP') NOT NULL DEFAULT 'CC'` |
| `documento_cifrado` | `VARBINARY(255) NOT NULL` |
| `documento_hash` | `CHAR(64) NOT NULL` |
| `telefono` | `VARCHAR(32) NOT NULL DEFAULT ''` |
| `entidad` | `VARCHAR(160) NOT NULL DEFAULT ''` |
| `departamento` | `VARCHAR(80) NOT NULL DEFAULT ''` |
| `municipio` | `VARCHAR(80) NOT NULL DEFAULT ''` |
| `rol` | `ENUM('participante','visitante','expositor','organizador','prensa') NOT NULL DEFAULT 'participante'` |
| `comparte_telefono` | `TINYINT(1) NOT NULL DEFAULT 1` |
| `autorizo_datos_en` | `DATETIME NOT NULL` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY uq_persona_correo (evento_id, correo)`
- `UNIQUE KEY uq_persona_doc (evento_id, documento_hash)`
- `KEY idx_persona_municipio (evento_id, municipio)`
- `CONSTRAINT fk_persona_evento FOREIGN KEY (evento_id) REFERENCES evt_evento (id) ON DELETE CASCADE`

### `evt_persona_caracterizacion`

Datos sensibles (Ley 1581, art. 5) en tabla aparte: se consultan solo cuando de verdad se necesitan y su acceso se audita.

| Columna | Tipo |
|---|---|
| `persona_id` | `INT UNSIGNED NOT NULL` |
| `genero` | `VARCHAR(20) NOT NULL DEFAULT ''` |
| `rango_edad` | `VARCHAR(12) NOT NULL DEFAULT ''` |
| `etnia` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `discapacidad` | `VARCHAR(40) NOT NULL DEFAULT ''` |

Llaves e índices:

- `PRIMARY KEY (persona_id)`
- `CONSTRAINT fk_caract_persona FOREIGN KEY (persona_id) REFERENCES evt_persona (id) ON DELETE CASCADE`

### `evt_credencial`

El carnet. El token es lo único que viaja en el QR; nunca datos personales.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `persona_id` | `INT UNSIGNED NOT NULL` |
| `codigo` | `VARCHAR(32) NOT NULL` |
| `token` | `CHAR(32) NOT NULL` |
| `foto_ruta` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `emitida_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `revocada_en` | `DATETIME NULL` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY uq_credencial_token (token)`
- `UNIQUE KEY uq_credencial_codigo (codigo)`
- `CONSTRAINT fk_credencial_persona FOREIGN KEY (persona_id) REFERENCES evt_persona (id) ON DELETE CASCADE`

### `evt_asistencia`

Un ingreso por persona y jornada. La llave única es la que impide contar dos veces a la misma persona.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `persona_id` | `INT UNSIGNED NOT NULL` |
| `evento_dia_id` | `INT UNSIGNED NOT NULL` |
| `registrado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `via` | `ENUM('qr_dia','carnet_operador','manual') NOT NULL DEFAULT 'qr_dia'` |
| `operador_id` | `INT UNSIGNED NULL` |
| `ip` | `VARBINARY(16) NULL` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY uq_asistencia (persona_id, evento_dia_id)`
- `KEY idx_asistencia_dia (evento_dia_id, registrado_en)`
- `CONSTRAINT fk_asis_persona FOREIGN KEY (persona_id) REFERENCES evt_persona (id) ON DELETE CASCADE`
- `CONSTRAINT fk_asis_dia FOREIGN KEY (evento_dia_id) REFERENCES evt_evento_dia (id) ON DELETE CASCADE`

### `evt_contacto`

Intercambio de datos entre asistentes. Guarda quién escaneó a quién y cuándo, para poder revertirlo si alguien lo pide.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `persona_id` | `INT UNSIGNED NOT NULL` |
| `contacto_id` | `INT UNSIGNED NOT NULL` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `revocado_en` | `DATETIME NULL` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY uq_contacto (persona_id, contacto_id)`
- `CONSTRAINT fk_contacto_a FOREIGN KEY (persona_id) REFERENCES evt_persona (id) ON DELETE CASCADE`
- `CONSTRAINT fk_contacto_b FOREIGN KEY (contacto_id) REFERENCES evt_persona (id) ON DELETE CASCADE`

### `evt_propuesta`

Lo que envía un expositor en el preregistro. Al aprobarse se convierte en charla.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `persona_id` | `INT UNSIGNED NOT NULL` |
| `titulo` | `VARCHAR(200) NOT NULL` |
| `categoria` | `VARCHAR(80) NOT NULL` |
| `detalle` | `TEXT NOT NULL` |
| `dia_preferido` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` |
| `duracion_min` | `SMALLINT UNSIGNED NOT NULL DEFAULT 40` |
| `requerimientos` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `estado` | `ENUM('pendiente','observada','aprobada','rechazada') NOT NULL DEFAULT 'pendiente'` |
| `observacion` | `TEXT NULL` |
| `revisada_por` | `INT UNSIGNED NULL` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `KEY idx_propuesta_estado (estado)`
- `CONSTRAINT fk_propuesta_persona FOREIGN KEY (persona_id) REFERENCES evt_persona (id) ON DELETE CASCADE`

### `evt_charla`

La agenda pública: propuestas aprobadas con horario y salón asignados.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `propuesta_id` | `INT UNSIGNED NOT NULL` |
| `evento_dia_id` | `INT UNSIGNED NOT NULL` |
| `hora_inicio` | `TIME NOT NULL` |
| `salon` | `VARCHAR(80) NOT NULL DEFAULT ''` |
| `publicada` | `TINYINT(1) NOT NULL DEFAULT 0` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY uq_charla_propuesta (propuesta_id)`
- `KEY idx_charla_dia (evento_dia_id, hora_inicio)`
- `CONSTRAINT fk_charla_propuesta FOREIGN KEY (propuesta_id) REFERENCES evt_propuesta (id) ON DELETE CASCADE`
- `CONSTRAINT fk_charla_dia FOREIGN KEY (evento_dia_id) REFERENCES evt_evento_dia (id) ON DELETE CASCADE`

### `evt_usuario`

El equipo organizador. Contraseña con Argon2id; el segundo factor es obligatorio para el rol administrador.

| Columna | Tipo |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `nombre` | `VARCHAR(160) NOT NULL` |
| `correo` | `VARCHAR(190) NOT NULL` |
| `clave_hash` | `VARCHAR(255) NOT NULL` |
| `rol` | `ENUM('administrador','operador','consulta') NOT NULL DEFAULT 'operador'` |
| `puesto` | `VARCHAR(80) NOT NULL DEFAULT ''` |
| `totp_secreto` | `VARBINARY(255) NULL` |
| `estado` | `ENUM('activo','suspendido') NOT NULL DEFAULT 'activo'` |
| `intentos_fallidos` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` |
| `bloqueado_hasta` | `DATETIME NULL` |
| `ultimo_acceso` | `DATETIME NULL` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `UNIQUE KEY uq_usuario_correo (correo)`

### `evt_sesion`

Sesiones en base de datos, no en archivos: permite cerrar sesiones a distancia y sobrevive a varios servidores.

| Columna | Tipo |
|---|---|
| `id` | `CHAR(64) NOT NULL` |
| `usuario_id` | `INT UNSIGNED NOT NULL` |
| `datos` | `TEXT NOT NULL` |
| `ip` | `VARBINARY(16) NULL` |
| `agente` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `expira_en` | `DATETIME NOT NULL` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `KEY idx_sesion_expira (expira_en)`
- `CONSTRAINT fk_sesion_usuario FOREIGN KEY (usuario_id) REFERENCES evt_usuario (id) ON DELETE CASCADE`

### `evt_bitacora`

Auditoría. Quién hizo qué, cuándo y desde dónde. Solo se inserta: no se actualiza ni se borra.

| Columna | Tipo |
|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `usuario_id` | `INT UNSIGNED NULL` |
| `accion` | `VARCHAR(80) NOT NULL` |
| `entidad` | `VARCHAR(60) NOT NULL DEFAULT ''` |
| `entidad_id` | `INT UNSIGNED NULL` |
| `detalle` | `JSON NULL` |
| `ip` | `VARBINARY(16) NULL` |
| `creado_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Llaves e índices:

- `PRIMARY KEY (id)`
- `KEY idx_bitacora_fecha (creado_en)`
- `KEY idx_bitacora_accion (accion)`

### `evt_migracion`

Qué versión del esquema está aplicada. Sin esto, el asistente no sabría si actualizar o instalar.

| Columna | Tipo |
|---|---|
| `version` | `VARCHAR(20) NOT NULL` |
| `aplicada_en` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `descripcion` | `VARCHAR(190) NOT NULL DEFAULT ''` |

Llaves e índices:

- `PRIMARY KEY (version)`

## Modos del instalador

El asistente compara lo que hay en la base con esta definición y ofrece tres caminos:

| Modo | Qué hace | Cuándo usarlo |
|---|---|---|
| **Limpio** | Elimina las tablas con este prefijo y las vuelve a crear | Instalación nueva, o entorno de pruebas que se quiere reiniciar |
| **Actualizar** | Crea las que falten y aplica los `ALTER` pendientes, conservando los datos | Al subir de versión una instalación en uso |
| **Anexar** | Solo crea las tablas que falten; no toca ninguna existente | Base compartida con otra aplicación, o reparación parcial |

El modo limpio es el único destructivo y la interfaz lo advierte en rojo con el conteo
de tablas que se perderían. En los tres casos el instalador hace una copia previa en
`almacen/respaldos/`.

## Versionado

La tabla `evt_migracion` guarda qué versión del esquema está aplicada. Sin ese registro
el asistente no podría distinguir una instalación nueva de una que solo necesita
actualizarse, y ofrecería borrar datos que debía conservar.
