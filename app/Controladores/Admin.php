<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Datos;
use App\Modelos\Asistencia;
use App\Modelos\Evento;
use App\Modelos\Persona;
use App\Modelos\Usuario;
use App\Nucleo\App;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Guardia;
use App\Nucleo\Peticion;
use App\Nucleo\Qr;
use App\Nucleo\Respuesta;
use App\Nucleo\Tema;
use App\Nucleo\Url;

/**
 * Backoffice del equipo organizador.
 *
 * Cada método asume que el guardia de su ruta ya comprobó el rol; el permiso
 * está declarado en app/rutas.php y no se repite aquí. Lo que sí se hace en
 * cada acción que escribe es dejar rastro en la bitácora.
 */
final class Admin
{
    /* =====================================================================
       Panel
       ===================================================================== */

    public function panel(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        if (!$evento) {
            Respuesta::redirigir('/admin/eventos', 'Crea el primer evento para empezar.', 'warn');
        }

        Respuesta::vista('admin/panel', [
            'titulo'      => 'Panel',
            'pantalla'    => 'panel',
            'resumen'     => Evento::resumen((int) $evento['id']),
            'porJornada'  => Asistencia::totalPorJornada((int) $evento['id']),
            'municipios'  => Evento::porMunicipio((int) $evento['id']),
            'bitacora'    => Bitacora::recientes(12),
            'pendientes'  => $this->pendientes((int) $evento['id']),
        ]);
    }

    /** Cosas que alguien debería mirar hoy. */
    private function pendientes(int $eventoId): array
    {
        $lista = [];

        $porAprobar = (int) Bd::valor(
            "SELECT COUNT(*) FROM {propuesta} pr JOIN {persona} p ON p.id = pr.persona_id
              WHERE p.evento_id = ? AND pr.estado = 'pendiente'",
            [$eventoId]
        );
        if ($porAprobar > 0) {
            $lista[] = ['texto' => $porAprobar . ' propuestas de exposición por revisar',
                        'ruta' => '/admin/expositores', 'tipo' => 'warn'];
        }

        $sinSegundoFactor = (int) Bd::valor(
            "SELECT COUNT(*) FROM {usuario}
              WHERE rol = 'administrador' AND estado = 'activo' AND totp_confirmado = 0"
        );
        if ($sinSegundoFactor > 0) {
            $lista[] = ['texto' => $sinSegundoFactor . ' administradores sin segundo factor activo',
                        'ruta' => '/admin/organizadores', 'tipo' => 'danger'];
        }

        $tema = Tema::del($eventoId);
        if ($tema['logo'] === '') {
            $lista[] = ['texto' => 'El evento todavía no tiene logo cargado',
                        'ruta' => '/admin/identidad', 'tipo' => ''];
        }

        if (!App::peticion()->esSegura()) {
            $lista[] = ['texto' => 'La plataforma no está sirviéndose por HTTPS',
                        'ruta' => '/admin', 'tipo' => 'danger'];
        }

        return $lista;
    }

    /* =====================================================================
       Escáner del operador
       ===================================================================== */

    public function escaner(Peticion $peticion): void
    {
        $usuario = Guardia::usuarioActual();
        $evento = App::eventoActivo();

        Respuesta::vista('admin/escaner', [
            'titulo'      => 'Escanear carnet',
            'pantalla'    => 'admin-escaner',
            'jornadas'    => Evento::jornadas((int) $evento['id']),
            'jornadaHoy'  => Evento::jornadaDeHoy((int) $evento['id']),
            'escaneosHoy' => Asistencia::escaneosDeHoy((int) $usuario['id']),
        ]);
    }

    /**
     * Búsqueda manual, para cuando el carnet no se puede leer.
     *
     * Es la salida cuando alguien perdió el teléfono o el código está rayado.
     * Devuelve pocos resultados y queda registrada: es una consulta de datos
     * personales hecha a mano.
     */
    public function buscarPersona(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $texto = $peticion->campo('q');

        if (mb_strlen($texto) < 3) {
            Respuesta::redirigir('/admin/escaner', 'Escribe al menos tres caracteres para buscar.', 'warn');
        }

        $encontradas = Persona::buscar((int) $evento['id'], ['texto' => $texto, 'limite' => 10]);
        Bitacora::registrar('busqueda_manual', 'persona', null, ['resultados' => count($encontradas)]);

        $usuario = Guardia::usuarioActual();
        Respuesta::vista('admin/escaner', [
            'titulo'      => 'Escanear carnet',
            'pantalla'    => 'admin-escaner',
            'jornadas'    => Evento::jornadas((int) $evento['id']),
            'jornadaHoy'  => Evento::jornadaDeHoy((int) $evento['id']),
            'escaneosHoy' => Asistencia::escaneosDeHoy((int) $usuario['id']),
            'busqueda'    => $texto,
            'encontradas' => $encontradas,
        ]);
    }

    /* =====================================================================
       Registros
       ===================================================================== */

    public function registros(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $filtros = [
            'texto' => $peticion->query('q'),
            'rol'   => $peticion->query('rol'),
            'dia'   => $peticion->entero('dia'),
        ];

        $personas = Persona::buscar((int) $evento['id'], $filtros);

        Respuesta::vista('admin/registros', [
            'titulo'   => 'Registros',
            'pantalla' => 'admin-registros',
            'personas' => $personas,
            'jornadas' => Evento::jornadas((int) $evento['id']),
            'filtros'  => $filtros,
            'total'    => (int) Bd::valor('SELECT COUNT(*) FROM {persona} WHERE evento_id = ?', [(int) $evento['id']]),
            'puedeVerSensibles' => Guardia::puede('administrador'),
        ]);
    }

    /**
     * Exportación a CSV.
     *
     * La caracterización solo va si quien exporta es administrador y la pide
     * explícitamente. Ambas cosas quedan en la bitácora.
     */
    public function exportar(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $conSensibles = $peticion->query('caracterizacion') === '1';

        if ($conSensibles && !Guardia::puede('administrador')) {
            Respuesta::error(403, 'No tienes permiso',
                'La exportación con caracterización está reservada al rol administrador.');
        }

        $filtros = [
            'texto'  => $peticion->query('q'),
            'rol'    => $peticion->query('rol'),
            'dia'    => $peticion->entero('dia'),
            'limite' => 500,
        ];
        $personas = Persona::buscar((int) $evento['id'], $filtros);
        $jornadas = Evento::jornadas((int) $evento['id']);

        $cabecera = ['Nombre', 'Documento', 'Correo', 'Teléfono', 'Entidad', 'Departamento', 'Municipio', 'Perfil'];
        foreach ($jornadas as $j) {
            $cabecera[] = 'Día ' . $j['numero'];
        }
        if ($conSensibles) {
            $cabecera = array_merge($cabecera, ['Género', 'Rango de edad', 'Etnia', 'Discapacidad']);
        }

        $filas = [$cabecera];
        foreach ($personas as $p) {
            $dias = Persona::diasDe($p['dias'] ?? null);
            $fila = [
                $p['nombre'],
                Persona::documento($p),
                $p['correo'],
                $p['telefono'],
                $p['entidad'],
                $p['departamento'],
                $p['municipio'],
                etiquetaRol((string) $p['rol']),
            ];
            foreach ($jornadas as $j) {
                $fila[] = in_array((int) $j['numero'], $dias, true) ? 'Sí' : 'No';
            }
            if ($conSensibles) {
                $c = Persona::caracterizacion((int) $p['id']);
                $fila = array_merge($fila, [
                    $c['genero'] ?? '', $c['rango_edad'] ?? '', $c['etnia'] ?? '', $c['discapacidad'] ?? '',
                ]);
            }
            $filas[] = $fila;
        }

        Bitacora::registrar('exportacion', 'persona', null, [
            'total' => count($personas),
            'sensible' => $conSensibles,
        ]);

        Respuesta::descarga(
            $conSensibles ? 'registros-caracterizacion.csv' : 'registros.csv',
            "\xEF\xBB\xBF" . $this->aCsv($filas),   // BOM para que Excel lea las tildes
            'text/csv'
        );
    }

    /**
     * CSV con el punto y coma como separador, que es lo que espera Excel en
     * configuración regional española.
     *
     * Los valores que empiezan por = + - @ se neutralizan con un apóstrofo: sin
     * eso, alguien podría escribir una fórmula en el campo «entidad» del
     * formulario público y esa fórmula se ejecutaría al abrir el reporte en el
     * equipo de un funcionario.
     */
    private function aCsv(array $filas): string
    {
        $lineas = [];
        foreach ($filas as $fila) {
            $celdas = [];
            foreach ($fila as $celda) {
                $valor = (string) $celda;
                if ($valor !== '' && str_contains("=+-@\t\r", $valor[0])) {
                    $valor = "'" . $valor;
                }
                $celdas[] = '"' . str_replace('"', '""', $valor) . '"';
            }
            $lineas[] = implode(';', $celdas);
        }
        return implode("\r\n", $lineas);
    }

    /* =====================================================================
       Códigos QR por jornada
       ===================================================================== */

    public function codigosDia(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $jornadas = Evento::jornadas((int) $evento['id']);

        foreach ($jornadas as &$j) {
            $j['url'] = Url::absoluta('/d/' . $j['token']);
            $j['qr'] = Qr::svg($j['url'], [
                'nivel' => 'M', 'silencio' => 2, 'clase' => 'qr',
                'titulo' => 'Código de acceso del día ' . $j['numero'],
            ]);
        }
        unset($j);

        Respuesta::vista('admin/qr-dias', [
            'titulo'   => 'Códigos QR por día',
            'pantalla' => 'admin-qr',
            'jornadas' => $jornadas,
            'puedeRotar' => Guardia::puede('administrador'),
        ]);
    }

    public function imprimirCodigo(Peticion $peticion, array $parametros): void
    {
        $evento = App::eventoActivo();
        $jornada = Evento::jornada((int) $evento['id'], (int) $parametros['numero']);
        if (!$jornada) {
            Respuesta::error(404, 'Jornada no encontrada', 'Ese día no existe en este evento.');
        }

        $url = Url::absoluta('/d/' . $jornada['token']);

        Respuesta::vista('admin/qr-imprimir', [
            'titulo'       => 'Código del día ' . $jornada['numero'],
            'jornada'      => $jornada,
            'url'          => $url,
            'qr'           => Qr::svg($url, ['nivel' => 'M', 'silencio' => 2, 'clase' => 'qr',
                                             'titulo' => 'Código de acceso']),
            'sinPlantilla' => true,
        ]);
    }

    public function rotarCodigo(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $numero = $peticion->entero('numero');
        if (!Evento::jornada((int) $evento['id'], $numero)) {
            Respuesta::redirigir('/admin/qr-dias', 'Esa jornada no existe.', 'warn');
        }

        Evento::rotarToken((int) $evento['id'], $numero);
        Respuesta::redirigir('/admin/qr-dias',
            'Código del día ' . $numero . ' regenerado. El pliego impreso anterior ya no sirve: reemplázalo.', 'warn');
    }

    /* =====================================================================
       Propuestas de exposición
       ===================================================================== */

    public function expositores(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $estado = $peticion->query('estado');

        $donde = ['p.evento_id = :evento'];
        $parametros = ['evento' => (int) $evento['id']];
        if (in_array($estado, ['pendiente', 'observada', 'aprobada', 'rechazada'], true)) {
            $donde[] = 'pr.estado = :estado';
            $parametros['estado'] = $estado;
        }

        $propuestas = Bd::filas(
            'SELECT pr.*, p.nombre AS expositor, p.entidad, p.correo,
                    c.id AS charla_id, c.hora_inicio, c.salon
               FROM {propuesta} pr
               JOIN {persona} p ON p.id = pr.persona_id
          LEFT JOIN {charla} c ON c.propuesta_id = pr.id
              WHERE ' . implode(' AND ', $donde) . '
           ORDER BY FIELD(pr.estado, "pendiente", "observada", "aprobada", "rechazada"), pr.creado_en DESC',
            $parametros
        );

        $conteos = [];
        foreach (Bd::filas(
            'SELECT pr.estado, COUNT(*) AS n
               FROM {propuesta} pr JOIN {persona} p ON p.id = pr.persona_id
              WHERE p.evento_id = ? GROUP BY pr.estado',
            [(int) $evento['id']]
        ) as $fila) {
            $conteos[$fila['estado']] = (int) $fila['n'];
        }

        Respuesta::vista('admin/expositores', [
            'titulo'     => 'Expositores',
            'pantalla'   => 'admin-expositores',
            'propuestas' => $propuestas,
            'conteos'    => $conteos,
            'estado'     => $estado,
            'jornadas'   => Evento::jornadas((int) $evento['id']),
        ]);
    }

    /**
     * Aprobar, observar o rechazar.
     * Al aprobar se crea la charla: es lo que la vuelve visible en la agenda.
     */
    public function decidirPropuesta(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $id = $peticion->entero('propuesta');
        $decision = $peticion->campo('decision');
        $observacion = mb_substr($peticion->campo('observacion'), 0, 1000);

        if (!in_array($decision, ['aprobada', 'observada', 'rechazada'], true)) {
            Respuesta::redirigir('/admin/expositores', 'Decisión no válida.', 'warn');
        }

        $propuesta = Bd::fila(
            'SELECT pr.* FROM {propuesta} pr
               JOIN {persona} p ON p.id = pr.persona_id
              WHERE pr.id = ? AND p.evento_id = ?',
            [$id, (int) $evento['id']]
        );
        if (!$propuesta) {
            Respuesta::redirigir('/admin/expositores', 'Esa propuesta no existe.', 'warn');
        }

        if ($decision === 'observada' && trim($observacion) === '') {
            Respuesta::redirigir('/admin/expositores',
                'Escribe la observación antes de devolver la propuesta.', 'warn');
        }

        $usuario = Guardia::usuarioActual();

        Bd::transaccion(static function () use ($propuesta, $decision, $observacion, $usuario, $peticion, $evento): void {
            Bd::actualizar('propuesta', [
                'estado'       => $decision,
                'observacion'  => $observacion !== '' ? $observacion : null,
                'revisada_por' => (int) $usuario['id'],
                'revisada_en'  => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $propuesta['id']]);

            if ($decision !== 'aprobada') {
                Bd::ejecutar('DELETE FROM {charla} WHERE propuesta_id = ?', [$propuesta['id']]);
                return;
            }

            $numero = $peticion->entero('dia', (int) $propuesta['dia_preferido']);
            $jornada = Evento::jornada((int) $evento['id'], $numero)
                ?? Evento::jornada((int) $evento['id'], (int) $propuesta['dia_preferido']);
            if (!$jornada) {
                return;
            }

            $hora = $peticion->campo('hora', '09:00');
            if (!preg_match('/^\d{2}:\d{2}$/', $hora)) {
                $hora = '09:00';
            }

            Bd::ejecutar(
                'INSERT INTO {charla} (propuesta_id, evento_dia_id, hora_inicio, salon, publicada)
                      VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE evento_dia_id = VALUES(evento_dia_id),
                                         hora_inicio = VALUES(hora_inicio),
                                         salon = VALUES(salon),
                                         publicada = 1',
                [$propuesta['id'], $jornada['id'], $hora . ':00', mb_substr($peticion->campo('salon'), 0, 80)]
            );
        });

        Bitacora::registrar('propuesta_decidida', 'propuesta', (int) $propuesta['id'], ['decision' => $decision]);

        Respuesta::redirigir('/admin/expositores', match ($decision) {
            'aprobada'  => 'Propuesta aprobada y publicada en la agenda.',
            'observada' => 'Propuesta devuelta con observaciones.',
            default     => 'Propuesta rechazada.',
        });
    }

    /* =====================================================================
       Equipo
       ===================================================================== */

    public function organizadores(Peticion $peticion): void
    {
        Respuesta::vista('admin/organizadores', [
            'titulo'   => 'Organizadores',
            'pantalla' => 'admin-organizadores',
            'equipo'   => Usuario::todos(),
        ]);
    }

    public function crearUsuario(Peticion $peticion): void
    {
        $clave = $peticion->campoCrudo('clave');

        if (mb_strlen($clave) < 12) {
            Respuesta::redirigir('/admin/organizadores',
                'La contraseña inicial debe tener al menos 12 caracteres.', 'warn');
        }
        if (!filter_var($peticion->campo('correo'), FILTER_VALIDATE_EMAIL)) {
            Respuesta::redirigir('/admin/organizadores', 'Escribe un correo válido.', 'warn');
        }
        if (mb_strlen($peticion->campo('nombre')) < 5) {
            Respuesta::redirigir('/admin/organizadores', 'Escribe el nombre completo.', 'warn');
        }

        try {
            Usuario::crear([
                'nombre'       => $peticion->campo('nombre'),
                'correo'       => $peticion->campo('correo'),
                'clave'        => $clave,
                'rol'          => $peticion->campo('rol', 'operador'),
                'puesto'       => $peticion->campo('puesto'),
                'debe_cambiar' => true,
            ]);
        } catch (\DomainException $e) {
            Respuesta::redirigir('/admin/organizadores', $e->getMessage(), 'warn');
        }

        Respuesta::redirigir('/admin/organizadores',
            'Cuenta creada. Entrégale la contraseña por un canal seguro; deberá cambiarla al entrar.');
    }

    public function cambiarEstadoUsuario(Peticion $peticion): void
    {
        $yo = Guardia::usuarioActual();
        $id = $peticion->entero('usuario');

        if ($id === (int) $yo['id']) {
            Respuesta::redirigir('/admin/organizadores',
                'No puedes suspender tu propia cuenta.', 'warn');
        }

        $objetivo = Usuario::porId($id);
        if (!$objetivo) {
            Respuesta::redirigir('/admin/organizadores', 'Esa cuenta no existe.', 'warn');
        }

        // Sin administradores activos nadie podría volver a configurar nada.
        if ($objetivo['rol'] === 'administrador' && $peticion->campo('estado') === 'suspendido') {
            $activos = (int) Bd::valor(
                "SELECT COUNT(*) FROM {usuario} WHERE rol = 'administrador' AND estado = 'activo'"
            );
            if ($activos <= 1) {
                Respuesta::redirigir('/admin/organizadores',
                    'Es el único administrador activo. Nombra otro antes de suspenderlo.', 'warn');
            }
        }

        Usuario::cambiarEstado($id, $peticion->campo('estado'));
        Respuesta::redirigir('/admin/organizadores', 'Estado de la cuenta actualizado.');
    }

    /* =====================================================================
       Eventos
       ===================================================================== */

    public function eventos(Peticion $peticion): void
    {
        Respuesta::vista('admin/eventos', [
            'titulo'   => 'Eventos',
            'pantalla' => 'admin-eventos',
            'eventos'  => Evento::todos(),
        ]);
    }

    public function crearEvento(Peticion $peticion): void
    {
        $nombre = $peticion->campo('nombre');
        $fecha = $peticion->campo('fecha_inicio');
        $jornadas = $peticion->entero('jornadas', 1);

        if (mb_strlen($nombre) < 3) {
            Respuesta::redirigir('/admin/eventos', 'Escribe el nombre del evento.', 'warn');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || strtotime($fecha) === false) {
            Respuesta::redirigir('/admin/eventos', 'Elige una fecha de inicio válida.', 'warn');
        }

        $id = Evento::crear([
            'nombre'       => $nombre,
            'dependencia'  => $peticion->campo('dependencia'),
            'sede'         => $peticion->campo('sede'),
            'fecha_inicio' => $fecha,
            'jornadas'     => $jornadas,
            'estado'       => 'borrador',
            'activo'       => false,
        ]);

        Bitacora::registrar('evento_creado', 'evento', $id);
        Respuesta::redirigir('/admin/eventos',
            'Evento creado como borrador. Configura su identidad antes de activarlo.');
    }

    public function activarEvento(Peticion $peticion): void
    {
        $id = $peticion->entero('evento');
        if (!Evento::porId($id)) {
            Respuesta::redirigir('/admin/eventos', 'Ese evento no existe.', 'warn');
        }

        Evento::activar($id);
        App::olvidarEvento();
        Respuesta::redirigir('/admin/eventos', 'Ese es ahora el evento activo.');
    }

    /* =====================================================================
       Identidad
       ===================================================================== */

    public function identidad(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $tema = Tema::del((int) $evento['id']);

        Respuesta::vista('admin/identidad', [
            'titulo'      => 'Identidad',
            'pantalla'    => 'admin-identidad',
            'temaEvento'  => $tema,
            'presets'     => Tema::PRESETS,
            'tipografias' => Tema::TIPOGRAFIAS,
            'contraste'   => Tema::revisarContraste($tema['colores']),
        ]);
    }

    public function guardarIdentidad(Peticion $peticion): void
    {
        $evento = App::eventoActivo();
        $eventoId = (int) $evento['id'];

        Bd::actualizar('evento', [
            'nombre'      => mb_substr($peticion->campo('nombre') ?: (string) $evento['nombre'], 0, 160),
            'dependencia' => mb_substr($peticion->campo('dependencia'), 0, 160),
            'sede'        => mb_substr($peticion->campo('sede'), 0, 160),
        ], 'id = :id', ['id' => $eventoId]);

        $colores = [];
        foreach (['brand', 'accent', 'bg', 'surface', 'sunken', 'line', 'title', 'text', 'muted', 'onBrand'] as $clave) {
            $valor = $peticion->campo('color_' . $clave);
            if ($valor !== '' && Tema::hexValido($valor)) {
                $colores[$clave] = $valor;
            }
        }

        $datos = [
            'preset'     => $peticion->campo('preset', 'tic-nocturno'),
            'tipografia' => $peticion->campo('tipografia', 'tecnologica'),
            'colores'    => $colores,
        ];

        $archivo = $peticion->archivo('logo');
        if ($archivo !== null) {
            try {
                [$nombreArchivo, $tipo] = $this->guardarLogo($archivo, $eventoId);
                $datos['logo_archivo'] = $nombreArchivo;
                $datos['logo_tipo'] = $tipo;
            } catch (\DomainException $e) {
                Respuesta::redirigir('/admin/identidad', $e->getMessage(), 'warn');
            }
        }
        if ($peticion->marcado('quitar_logo')) {
            $datos['logo_archivo'] = '';
            $datos['logo_tipo'] = '';
        }

        Tema::guardar($eventoId, $datos);
        App::olvidarEvento();
        Bitacora::registrar('identidad_guardada', 'evento', $eventoId, [
            'preset' => $datos['preset'],
            'tipografia' => $datos['tipografia'],
        ]);

        Respuesta::redirigir('/admin/identidad', 'Identidad guardada y aplicada a toda la plataforma.');
    }

    /**
     * Guarda el logo del evento.
     *
     * Los controles importantes están aquí:
     *   · el tipo se decide por el contenido real del archivo, no por su
     *     extensión ni por lo que declare el navegador;
     *   · los mapas de bits se vuelven a generar, lo que descarta cualquier
     *     carga útil escondida en los metadatos;
     *   · el SVG se limpia de scripts y referencias externas antes de guardar;
     *   · el nombre lo pone el servidor.
     * Ver docs/SEGURIDAD.md, sección de subida de archivos.
     */
    private function guardarLogo(array $archivo, int $eventoId): array
    {
        if (($archivo['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new \DomainException('No se pudo recibir el archivo. ¿Supera el límite del servidor?');
        }
        if (($archivo['size'] ?? 0) > 512 * 1024) {
            throw new \DomainException('El logo pesa más de 512 KB. Reduce el archivo e inténtalo de nuevo.');
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            throw new \DomainException('El archivo no llegó por una subida válida.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $tipoReal = (string) $finfo->file($archivo['tmp_name']);

        $permitidos = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            'text/plain'    => 'svg',   // algunos servidores detectan así el SVG
            'text/xml'      => 'svg',
        ];
        if (!isset($permitidos[$tipoReal])) {
            throw new \DomainException('Formato no admitido. Usa SVG, PNG, JPG o WEBP.');
        }
        $extension = $permitidos[$tipoReal];

        $directorio = RAIZ . '/almacen/logos';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        $nombre = 'evento-' . $eventoId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $destino = $directorio . '/' . $nombre;

        if ($extension === 'svg') {
            $contenido = (string) file_get_contents($archivo['tmp_name']);
            if (!str_contains($contenido, '<svg')) {
                throw new \DomainException('El archivo no parece un SVG válido.');
            }
            file_put_contents($destino, $this->limpiarSvg($contenido));
            $tipoGuardado = 'image/svg+xml';
        } else {
            $this->reescribirImagen($archivo['tmp_name'], $destino, $extension);
            $tipoGuardado = $tipoReal === 'image/jpeg' ? 'image/jpeg'
                : ($tipoReal === 'image/webp' ? 'image/webp' : 'image/png');
        }
        @chmod($destino, 0640);

        // Se borra el logo anterior para no dejar archivos huérfanos.
        $temaAnterior = Tema::del($eventoId);
        if ($temaAnterior['logo'] !== '' && $temaAnterior['logo'] !== $nombre) {
            @unlink($directorio . '/' . basename($temaAnterior['logo']));
        }

        return [$nombre, $tipoGuardado];
    }

    /**
     * Quita del SVG todo lo que pueda ejecutar o traer contenido externo.
     *
     * Un SVG es un documento XML capaz de contener JavaScript. Aunque la
     * plataforma lo sirve con Content-Disposition y una política restrictiva,
     * limpiarlo antes de guardarlo evita depender de una sola barrera.
     */
    private function limpiarSvg(string $svg): string
    {
        // Elementos peligrosos con todo su contenido.
        $svg = preg_replace(
            '#<\s*(script|foreignObject|iframe|embed|object|animate|set|handler)\b[^>]*>.*?<\s*/\s*\1\s*>#is',
            '',
            $svg
        ) ?? $svg;
        // Y sus versiones vacías.
        $svg = preg_replace(
            '#<\s*(script|foreignObject|iframe|embed|object|animate|set|handler)\b[^>]*/?>#i',
            '',
            $svg
        ) ?? $svg;
        // Atributos de evento: onload, onclick, onerror…
        $svg = preg_replace('#\son[a-z]+\s*=\s*(["\']).*?\1#is', '', $svg) ?? $svg;
        $svg = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $svg) ?? $svg;
        // Referencias a otros orígenes y a javascript:
        $svg = preg_replace('#(xlink:href|href)\s*=\s*(["\'])\s*(?!#)[^"\']*\2#i', '', $svg) ?? $svg;
        $svg = preg_replace('#javascript\s*:#i', '', $svg) ?? $svg;
        // Declaraciones de entidades: la vía de los ataques XXE.
        $svg = preg_replace('#<!DOCTYPE.*?>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<!ENTITY.*?>#is', '', $svg) ?? $svg;

        return $svg;
    }

    /** Vuelve a generar la imagen: descarta metadatos y cargas ocultas. */
    private function reescribirImagen(string $origen, string $destino, string $extension): void
    {
        if (!function_exists('imagecreatefromstring')) {
            // Sin gd no se puede reescribir; se copia tal cual, que sigue siendo
            // seguro porque nunca se sirve como código.
            copy($origen, $destino);
            return;
        }

        $imagen = @imagecreatefromstring((string) file_get_contents($origen));
        if ($imagen === false) {
            throw new \DomainException('La imagen está dañada o no se pudo leer.');
        }

        // Un logo no necesita más de 600 px de lado.
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $maximo = 600;
        if ($ancho > $maximo || $alto > $maximo) {
            $escala = $maximo / max($ancho, $alto);
            $nueva = imagescale($imagen, (int) round($ancho * $escala), (int) round($alto * $escala));
            if ($nueva !== false) {
                imagedestroy($imagen);
                $imagen = $nueva;
            }
        }

        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);

        match ($extension) {
            'jpg'  => imagejpeg($imagen, $destino, 88),
            'webp' => imagewebp($imagen, $destino, 88),
            default => imagepng($imagen, $destino, 9),
        };
        imagedestroy($imagen);
    }
}
