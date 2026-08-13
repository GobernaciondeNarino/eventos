/* =========================================================================
   esquema.js — Definición del modelo de datos de la versión 1.0
   -------------------------------------------------------------------------
   Una sola fuente de verdad para tres consumidores:
     · el asistente de instalación, que muestra el plan y el SQL;
     · la documentación (docs/ESQUEMA-DATOS.md);
     · la fase funcional, donde el mismo arreglo alimenta las migraciones.

   Cada tabla declara sus columnas, sus llaves y una nota que explica por qué
   existe. El prefijo es configurable para poder compartir la base con otras
   aplicaciones del hosting.
   ========================================================================= */
(function (global) {
  'use strict';

  var VERSION = '1.0.0';

  var TABLAS = [
    {
      nombre: 'evento',
      nota: 'Un registro por evento. La plataforma es multievento desde el día uno.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['nombre', 'VARCHAR(160) NOT NULL'],
        ['dependencia', "VARCHAR(160) NOT NULL DEFAULT ''"],
        ['sede', "VARCHAR(160) NOT NULL DEFAULT ''"],
        ['fecha_inicio', 'DATE NOT NULL'],
        ['jornadas', 'TINYINT UNSIGNED NOT NULL DEFAULT 1'],
        ['estado', "ENUM('borrador','abierto','en_curso','cerrado') NOT NULL DEFAULT 'borrador'"],
        ['activo', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP']
      ],
      llaves: ['PRIMARY KEY (id)', 'KEY idx_evento_estado (estado, activo)']
    },
    {
      nombre: 'evento_tema',
      nota: 'Identidad visual: colores, tipografía y logo. Es lo que el backend imprime como window.EVENTO_TEMA.',
      columnas: [
        ['evento_id', 'INT UNSIGNED NOT NULL'],
        ['preset', "VARCHAR(40) NOT NULL DEFAULT 'tic-nocturno'"],
        ['tipografia', "VARCHAR(40) NOT NULL DEFAULT 'tecnologica'"],
        ['colores_json', 'JSON NULL'],
        ['logo_ruta', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['actualizado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP']
      ],
      llaves: [
        'PRIMARY KEY (evento_id)',
        'CONSTRAINT fk_tema_evento FOREIGN KEY (evento_id) REFERENCES {p}evento (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'evento_dia',
      nota: 'Las jornadas. El código QR de acceso cuelga de aquí, no del evento.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['evento_id', 'INT UNSIGNED NOT NULL'],
        ['numero', 'TINYINT UNSIGNED NOT NULL'],
        ['fecha', 'DATE NOT NULL'],
        ['abre_a', "TIME NOT NULL DEFAULT '07:00:00'"],
        ['cierra_a', "TIME NOT NULL DEFAULT '18:00:00'"],
        ['token', 'CHAR(32) NOT NULL'],
        ['token_rotado_en', 'DATETIME NULL'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'UNIQUE KEY uq_dia (evento_id, numero)',
        'UNIQUE KEY uq_token (token)',
        'CONSTRAINT fk_dia_evento FOREIGN KEY (evento_id) REFERENCES {p}evento (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'persona',
      nota: 'Quien se preregistra. El documento se guarda cifrado y con un hash aparte para poder buscar sin descifrar.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['evento_id', 'INT UNSIGNED NOT NULL'],
        ['nombre', 'VARCHAR(160) NOT NULL'],
        ['correo', 'VARCHAR(190) NOT NULL'],
        ['tipo_documento', "ENUM('CC','CE','TI','PP') NOT NULL DEFAULT 'CC'"],
        ['documento_cifrado', 'VARBINARY(255) NOT NULL'],
        ['documento_hash', 'CHAR(64) NOT NULL'],
        ['telefono', "VARCHAR(32) NOT NULL DEFAULT ''"],
        ['entidad', "VARCHAR(160) NOT NULL DEFAULT ''"],
        ['departamento', "VARCHAR(80) NOT NULL DEFAULT ''"],
        ['municipio', "VARCHAR(80) NOT NULL DEFAULT ''"],
        ['rol', "ENUM('participante','visitante','expositor','organizador','prensa') NOT NULL DEFAULT 'participante'"],
        ['comparte_telefono', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['autorizo_datos_en', 'DATETIME NOT NULL'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'UNIQUE KEY uq_persona_correo (evento_id, correo)',
        'UNIQUE KEY uq_persona_doc (evento_id, documento_hash)',
        'KEY idx_persona_municipio (evento_id, municipio)',
        'CONSTRAINT fk_persona_evento FOREIGN KEY (evento_id) REFERENCES {p}evento (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'persona_caracterizacion',
      nota: 'Datos sensibles (Ley 1581, art. 5) en tabla aparte: se consultan solo cuando de verdad se necesitan y su acceso se audita.',
      columnas: [
        ['persona_id', 'INT UNSIGNED NOT NULL'],
        ['genero', "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['rango_edad', "VARCHAR(12) NOT NULL DEFAULT ''"],
        ['etnia', "VARCHAR(40) NOT NULL DEFAULT ''"],
        ['discapacidad', "VARCHAR(40) NOT NULL DEFAULT ''"]
      ],
      llaves: [
        'PRIMARY KEY (persona_id)',
        'CONSTRAINT fk_caract_persona FOREIGN KEY (persona_id) REFERENCES {p}persona (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'credencial',
      nota: 'El carnet. El token es lo único que viaja en el QR; nunca datos personales.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['persona_id', 'INT UNSIGNED NOT NULL'],
        ['codigo', 'VARCHAR(32) NOT NULL'],
        ['token', 'CHAR(32) NOT NULL'],
        ['foto_ruta', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['emitida_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['revocada_en', 'DATETIME NULL']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'UNIQUE KEY uq_credencial_token (token)',
        'UNIQUE KEY uq_credencial_codigo (codigo)',
        'CONSTRAINT fk_credencial_persona FOREIGN KEY (persona_id) REFERENCES {p}persona (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'asistencia',
      nota: 'Un ingreso por persona y jornada. La llave única es la que impide contar dos veces a la misma persona.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['persona_id', 'INT UNSIGNED NOT NULL'],
        ['evento_dia_id', 'INT UNSIGNED NOT NULL'],
        ['registrado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['via', "ENUM('qr_dia','carnet_operador','manual') NOT NULL DEFAULT 'qr_dia'"],
        ['operador_id', 'INT UNSIGNED NULL'],
        ['ip', 'VARBINARY(16) NULL']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'UNIQUE KEY uq_asistencia (persona_id, evento_dia_id)',
        'KEY idx_asistencia_dia (evento_dia_id, registrado_en)',
        'CONSTRAINT fk_asis_persona FOREIGN KEY (persona_id) REFERENCES {p}persona (id) ON DELETE CASCADE',
        'CONSTRAINT fk_asis_dia FOREIGN KEY (evento_dia_id) REFERENCES {p}evento_dia (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'contacto',
      nota: 'Intercambio de datos entre asistentes. Guarda quién escaneó a quién y cuándo, para poder revertirlo si alguien lo pide.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['persona_id', 'INT UNSIGNED NOT NULL'],
        ['contacto_id', 'INT UNSIGNED NOT NULL'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['revocado_en', 'DATETIME NULL']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'UNIQUE KEY uq_contacto (persona_id, contacto_id)',
        'CONSTRAINT fk_contacto_a FOREIGN KEY (persona_id) REFERENCES {p}persona (id) ON DELETE CASCADE',
        'CONSTRAINT fk_contacto_b FOREIGN KEY (contacto_id) REFERENCES {p}persona (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'propuesta',
      nota: 'Lo que envía un expositor en el preregistro. Al aprobarse se convierte en charla.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['persona_id', 'INT UNSIGNED NOT NULL'],
        ['titulo', 'VARCHAR(200) NOT NULL'],
        ['categoria', 'VARCHAR(80) NOT NULL'],
        ['detalle', 'TEXT NOT NULL'],
        ['dia_preferido', 'TINYINT UNSIGNED NOT NULL DEFAULT 1'],
        ['duracion_min', 'SMALLINT UNSIGNED NOT NULL DEFAULT 40'],
        ['requerimientos', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['estado', "ENUM('pendiente','observada','aprobada','rechazada') NOT NULL DEFAULT 'pendiente'"],
        ['observacion', 'TEXT NULL'],
        ['revisada_por', 'INT UNSIGNED NULL'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'KEY idx_propuesta_estado (estado)',
        'CONSTRAINT fk_propuesta_persona FOREIGN KEY (persona_id) REFERENCES {p}persona (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'charla',
      nota: 'La agenda pública: propuestas aprobadas con horario y salón asignados.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['propuesta_id', 'INT UNSIGNED NOT NULL'],
        ['evento_dia_id', 'INT UNSIGNED NOT NULL'],
        ['hora_inicio', 'TIME NOT NULL'],
        ['salon', "VARCHAR(80) NOT NULL DEFAULT ''"],
        ['publicada', 'TINYINT(1) NOT NULL DEFAULT 0']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'UNIQUE KEY uq_charla_propuesta (propuesta_id)',
        'KEY idx_charla_dia (evento_dia_id, hora_inicio)',
        'CONSTRAINT fk_charla_propuesta FOREIGN KEY (propuesta_id) REFERENCES {p}propuesta (id) ON DELETE CASCADE',
        'CONSTRAINT fk_charla_dia FOREIGN KEY (evento_dia_id) REFERENCES {p}evento_dia (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'usuario',
      nota: 'El equipo organizador. Contraseña con Argon2id; el segundo factor es obligatorio para el rol administrador.',
      columnas: [
        ['id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['nombre', 'VARCHAR(160) NOT NULL'],
        ['correo', 'VARCHAR(190) NOT NULL'],
        ['clave_hash', 'VARCHAR(255) NOT NULL'],
        ['rol', "ENUM('administrador','operador','consulta') NOT NULL DEFAULT 'operador'"],
        ['puesto', "VARCHAR(80) NOT NULL DEFAULT ''"],
        ['totp_secreto', 'VARBINARY(255) NULL'],
        ['estado', "ENUM('activo','suspendido') NOT NULL DEFAULT 'activo'"],
        ['intentos_fallidos', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
        ['bloqueado_hasta', 'DATETIME NULL'],
        ['ultimo_acceso', 'DATETIME NULL'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP']
      ],
      llaves: ['PRIMARY KEY (id)', 'UNIQUE KEY uq_usuario_correo (correo)']
    },
    {
      nombre: 'sesion',
      nota: 'Sesiones en base de datos, no en archivos: permite cerrar sesiones a distancia y sobrevive a varios servidores.',
      columnas: [
        ['id', 'CHAR(64) NOT NULL'],
        ['usuario_id', 'INT UNSIGNED NOT NULL'],
        ['datos', 'TEXT NOT NULL'],
        ['ip', 'VARBINARY(16) NULL'],
        ['agente', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['expira_en', 'DATETIME NOT NULL'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP']
      ],
      llaves: [
        'PRIMARY KEY (id)',
        'KEY idx_sesion_expira (expira_en)',
        'CONSTRAINT fk_sesion_usuario FOREIGN KEY (usuario_id) REFERENCES {p}usuario (id) ON DELETE CASCADE'
      ]
    },
    {
      nombre: 'bitacora',
      nota: 'Auditoría. Quién hizo qué, cuándo y desde dónde. Solo se inserta: no se actualiza ni se borra.',
      columnas: [
        ['id', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'],
        ['usuario_id', 'INT UNSIGNED NULL'],
        ['accion', 'VARCHAR(80) NOT NULL'],
        ['entidad', "VARCHAR(60) NOT NULL DEFAULT ''"],
        ['entidad_id', 'INT UNSIGNED NULL'],
        ['detalle', 'JSON NULL'],
        ['ip', 'VARBINARY(16) NULL'],
        ['creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP']
      ],
      llaves: ['PRIMARY KEY (id)', 'KEY idx_bitacora_fecha (creado_en)', 'KEY idx_bitacora_accion (accion)']
    },
    {
      nombre: 'migracion',
      nota: 'Qué versión del esquema está aplicada. Sin esto, el asistente no sabría si actualizar o instalar.',
      columnas: [
        ['version', 'VARCHAR(20) NOT NULL'],
        ['aplicada_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['descripcion', "VARCHAR(190) NOT NULL DEFAULT ''"]
      ],
      llaves: ['PRIMARY KEY (version)']
    }
  ];

  function sqlTabla(tabla, prefijo, siNoExiste) {
    var p = prefijo || '';
    var partes = tabla.columnas.map(function (c) {
      return '  `' + c[0] + '` ' + c[1];
    }).concat(tabla.llaves.map(function (k) {
      return '  ' + k.replace(/\{p\}/g, p);
    }));

    return '-- ' + tabla.nota + '\n'
      + 'CREATE TABLE ' + (siNoExiste ? 'IF NOT EXISTS ' : '') + '`' + p + tabla.nombre + '` (\n'
      + partes.join(',\n') + '\n'
      + ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
  }

  var Esquema = {
    version: VERSION,
    tablas: TABLAS,

    /** SQL completo. modo: 'limpio' | 'actualizar' | 'anexar' */
    sql: function (prefijo, modo) {
      var p = prefijo || '';
      var salida = [
        '-- Plataforma de Eventos TIC · esquema ' + VERSION,
        '-- Generado por el asistente de instalación',
        '-- Modo: ' + modo,
        'SET NAMES utf8mb4;',
        'SET FOREIGN_KEY_CHECKS = 0;',
        ''
      ];

      if (modo === 'limpio') {
        salida.push('-- Se eliminan las tablas existentes con este prefijo.');
        TABLAS.slice().reverse().forEach(function (t) {
          salida.push('DROP TABLE IF EXISTS `' + p + t.nombre + '`;');
        });
        salida.push('');
      }

      TABLAS.forEach(function (t) {
        salida.push(sqlTabla(t, p, modo !== 'limpio'));
        salida.push('');
      });

      if (modo === 'actualizar') {
        salida.push('-- Ajustes sobre tablas que ya existían.');
        salida.push('-- Nota: ADD COLUMN IF NOT EXISTS es sintaxis de MariaDB. En MySQL 8 hay');
        salida.push('-- que consultar antes information_schema.COLUMNS y ejecutar el ALTER solo');
        salida.push('-- si la columna falta; el instalador lo resuelve según el motor detectado.');
        salida.push('ALTER TABLE `' + p + 'persona`');
        salida.push('  ADD COLUMN IF NOT EXISTS `comparte_telefono` TINYINT(1) NOT NULL DEFAULT 1;');
        salida.push('');
      }

      salida.push('SET FOREIGN_KEY_CHECKS = 1;');
      salida.push("INSERT INTO `" + p + "migracion` (version, descripcion)");
      salida.push("  VALUES ('" + VERSION + "', 'Instalación inicial')");
      salida.push('  ON DUPLICATE KEY UPDATE aplicada_en = CURRENT_TIMESTAMP;');
      return salida.join('\n');
    }
  };

  global.Esquema = Esquema;
  if (typeof module === 'object' && module.exports) module.exports = Esquema;
})(typeof window !== 'undefined' ? window : globalThis);
