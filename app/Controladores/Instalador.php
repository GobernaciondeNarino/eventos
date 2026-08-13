<?php
declare(strict_types=1);

namespace App\Controladores;

defined('EVENTOS_TIC') || exit;

use App\Esquema;
use App\Modelos\Evento;
use App\Modelos\Usuario;
use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Config;
use App\Nucleo\Correo;
use App\Nucleo\Cripto;
use App\Nucleo\Peticion;
use App\Nucleo\Respuesta;
use App\Nucleo\Tema;
use App\Nucleo\Url;

/**
 * Asistente de instalación.
 *
 * Seis pasos. El estado va en una cookie firmada y no en sesión, porque
 * todavía no hay base de datos donde guardar sesiones. La cookie no lleva la
 * contraseña de la base ni la del administrador: esas viajan en cada envío y se
 * usan de inmediato.
 *
 * Al terminar, el asistente se cierra solo: la ruta queda bloqueada mientras
 * exista config/config.php con la marca de instalación completa. No hay que
 * acordarse de borrar ninguna carpeta.
 */
final class Instalador
{
    private const COOKIE = 'evtic_instalacion';
    private const PASOS = ['Servidor', 'Base de datos', 'Tablas', 'Cuenta', 'Evento', 'Fin'];

    public function asistente(Peticion $peticion): void
    {
        $estado = $this->leerEstado();
        $paso = max(1, min(6, (int) ($peticion->query('paso') ?: $estado['paso'] ?? 1)));
        $errores = [];

        if ($peticion->esPost()) {
            $accion = $peticion->campo('accion');

            // Acciones que responden sin cambiar de paso.
            if ($accion === 'probar_conexion') {
                $this->probarConexion($peticion);
            }

            [$paso, $errores, $estado] = $this->procesar($peticion, $accion, $estado);
            $this->guardarEstado($estado + ['paso' => $paso]);

            if (!$errores) {
                // Terminada la instalación, esta misma ruta queda cerrada por el
                // guardia. El resumen y los siguientes pasos viven en una ruta
                // aparte, que solo muestra información y no ejecuta nada.
                Respuesta::redirigirAbsoluto(Url::a($paso === 6 ? '/instalar/listo' : '/instalar', $paso === 6 ? [] : ['paso' => $paso]));
            }
        }

        Respuesta::vista('instalar/asistente', [
            'paso'        => $paso,
            'pasos'       => self::PASOS,
            'estado'      => $estado,
            'errores'     => $errores,
            'requisitos'  => $this->requisitos(),
            'permisos'    => $this->permisos(),
            'bloqueantes' => $this->bloqueantes(),
            'presets'     => Tema::PRESETS,
            'tipografias' => Tema::TIPOGRAFIAS,
            'sinPlantilla' => true,
        ]);
    }

    /**
     * Resumen final.
     *
     * Ruta aparte porque, una vez escrita la configuración, /instalar queda
     * cerrada por el guardia y con ella se perdería la pantalla que explica qué
     * se hizo y qué falta por hacer. Aquí no se ejecuta nada: solo se muestra lo
     * que quedó anotado en la cookie firmada del proceso.
     */
    public function terminado(Peticion $peticion): void
    {
        $estado = $this->leerEstado();
        $resultado = $estado['resultado'] ?? null;

        if (!is_array($resultado)) {
            Respuesta::redirigir('/admin/entrar');
        }

        Respuesta::vista('instalar/asistente', [
            'paso'        => 6,
            'pasos'       => self::PASOS,
            'estado'      => ['resultado' => $resultado],
            'errores'     => [],
            'requisitos'  => [],
            'permisos'    => [],
            'bloqueantes' => [],
            'presets'     => Tema::PRESETS,
            'tipografias' => Tema::TIPOGRAFIAS,
            'sinPlantilla' => true,
        ]);
    }

    /* =====================================================================
       Paso 1 · Comprobación del servidor
       ===================================================================== */

    private function requisitos(): array
    {
        $lista = [];

        $lista[] = [
            'nombre'  => 'Versión de PHP',
            'detalle' => 'Se requiere 8.1 o superior',
            'valor'   => PHP_VERSION,
            'estado'  => PHP_VERSION_ID >= 80100 ? 'ok' : 'fail',
        ];

        foreach ([
            'pdo_mysql' => ['Acceso a la base de datos', true],
            'mbstring'  => ['Manejo correcto de tildes y ñ', true],
            'openssl'   => ['Cifrado del documento y de los tokens', true],
            'json'      => ['Serialización interna', true],
            'fileinfo'  => ['Verificar el tipo real de los archivos subidos', true],
            'gd'        => ['Reducir el logo y las fotos del carnet', false],
            'sodium'    => ['Cifrado moderno; sin él se usa AES-GCM', false],
        ] as $extension => [$para, $obligatoria]) {
            $presente = extension_loaded($extension);
            $lista[] = [
                'nombre'  => 'Extensión ' . $extension,
                'detalle' => $para,
                'valor'   => $presente ? 'presente' : 'ausente',
                'estado'  => $presente ? 'ok' : ($obligatoria ? 'fail' : 'warn'),
            ];
        }

        $argon = defined('PASSWORD_ARGON2ID');
        $lista[] = [
            'nombre'  => 'Argon2id para contraseñas',
            'detalle' => $argon ? 'Disponible' : 'Se usará bcrypt, que también es aceptable',
            'valor'   => $argon ? 'disponible' : 'bcrypt',
            'estado'  => $argon ? 'ok' : 'warn',
        ];

        $seguro = \App\Nucleo\App::peticion()->esSegura();
        $lista[] = [
            'nombre'  => 'HTTPS activo',
            'detalle' => 'Sin TLS las credenciales y los tokens viajan en claro',
            'valor'   => $seguro ? 'sí' : 'no detectado',
            'estado'  => $seguro ? 'ok' : 'warn',
        ];

        $lista[] = [
            'nombre'  => 'Envío de correo',
            'detalle' => 'Para enviar el carnet y los códigos de acceso',
            'valor'   => function_exists('mail') ? 'función mail() disponible' : 'sin mail()',
            'estado'  => function_exists('mail') ? 'ok' : 'warn',
        ];

        $reescritura = $this->hayReescritura();
        $lista[] = [
            'nombre'  => 'Reescritura de URL',
            'detalle' => 'mod_rewrite en Apache; sin él las direcciones no funcionan',
            'valor'   => $reescritura === null ? 'no se pudo comprobar' : ($reescritura ? 'activa' : 'inactiva'),
            'estado'  => $reescritura === false ? 'fail' : ($reescritura === null ? 'warn' : 'ok'),
        ];

        return $lista;
    }

    /**
     * ¿Está mod_rewrite? Si se llegó aquí por una URL limpia, la respuesta es sí.
     * Con nginx por delante —lo habitual en Plesk— no se puede saber desde PHP,
     * y entonces se avisa en vez de afirmar.
     */
    private function hayReescritura(): ?bool
    {
        if (function_exists('apache_get_modules')) {
            return in_array('mod_rewrite', apache_get_modules(), true);
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, '/instalar') && !str_contains($uri, 'index.php')) {
            return true;
        }
        return null;
    }

    private function permisos(): array
    {
        $carpetas = [
            'config'            => 'Aquí se escribe config.php',
            'almacen/logos'     => 'Logos de cada evento',
            'almacen/fotos'     => 'Fotografías de los carnets',
            'almacen/respaldos' => 'Copias antes de cada migración',
            'almacen/registro'  => 'Registro de errores',
        ];

        $lista = [];
        foreach ($carpetas as $relativa => $para) {
            $ruta = RAIZ . '/' . $relativa;
            if (!is_dir($ruta)) {
                @mkdir($ruta, 0750, true);
            }
            $escribible = is_dir($ruta) && is_writable($ruta);
            $lista[] = [
                'nombre'  => $relativa . '/',
                'detalle' => $para,
                'valor'   => $escribible ? 'escritura' : (is_dir($ruta) ? 'solo lectura' : 'no existe'),
                'estado'  => $escribible ? 'ok' : 'fail',
            ];
        }
        return $lista;
    }

    /** Requisitos con estado 'fail': impiden continuar. */
    private function bloqueantes(): array
    {
        $todos = array_merge($this->requisitos(), $this->permisos());
        return array_values(array_filter($todos, static fn(array $r): bool => $r['estado'] === 'fail'));
    }

    /* =====================================================================
       Paso 2 · Conexión
       ===================================================================== */

    private function probarConexion(Peticion $peticion): never
    {
        $parametros = $this->parametrosBd($peticion);
        $errores = $this->validarParametrosBd($parametros);

        if ($errores) {
            Respuesta::json(['ok' => false, 'mensaje' => reset($errores)]);
        }

        [$ok, $mensaje] = Bd::probar($parametros);
        Respuesta::json(['ok' => $ok, 'mensaje' => $mensaje]);
    }

    private function parametrosBd(Peticion $peticion): array
    {
        return [
            'host'    => $peticion->campo('bd_host', 'localhost'),
            'puerto'  => (int) ($peticion->campo('bd_puerto', '3306') ?: 3306),
            'nombre'  => $peticion->campo('bd_nombre'),
            'usuario' => $peticion->campo('bd_usuario'),
            'clave'   => $peticion->campoCrudo('bd_clave'),
            'prefijo' => $peticion->campo('bd_prefijo', 'evt_'),
        ];
    }

    private function validarParametrosBd(array $p): array
    {
        $errores = [];
        if ($p['nombre'] === '') {
            $errores['bd_nombre'] = 'Escribe el nombre de la base de datos.';
        }
        if ($p['usuario'] === '') {
            $errores['bd_usuario'] = 'Escribe el usuario de la base de datos.';
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $p['prefijo'])) {
            $errores['bd_prefijo'] = 'El prefijo debe empezar por letra minúscula y usar solo letras, números y guion bajo.';
        }
        if ($p['host'] === '' || !preg_match('/^[A-Za-z0-9._\-]{1,120}$/', $p['host'])) {
            $errores['bd_host'] = 'El servidor no parece válido.';
        }
        return $errores;
    }

    /* =====================================================================
       Procesamiento de cada paso
       ===================================================================== */

    private function procesar(Peticion $peticion, string $accion, array $estado): array
    {
        return match ($accion) {
            'paso1' => $this->paso1($estado),
            'paso2' => $this->paso2($peticion, $estado),
            'paso3' => $this->paso3($peticion, $estado),
            'paso4' => $this->paso4($peticion, $estado),
            'paso5' => $this->paso5($peticion, $estado),
            'atras' => [max(1, (int) $peticion->campo('a', '1')), [], $estado],
            default => [(int) ($estado['paso'] ?? 1), [], $estado],
        };
    }

    private function paso1(array $estado): array
    {
        if ($this->bloqueantes()) {
            return [1, ['general' => 'Hay requisitos sin cumplir. Corrígelos antes de continuar.'], $estado];
        }
        return [2, [], $estado];
    }

    private function paso2(Peticion $peticion, array $estado): array
    {
        $parametros = $this->parametrosBd($peticion);
        $errores = $this->validarParametrosBd($parametros);
        if ($errores) {
            return [2, $errores, $estado + ['bd' => $this->sinClave($parametros)]];
        }

        [$ok, $mensaje] = Bd::probar($parametros);
        if (!$ok) {
            return [2, ['general' => $mensaje], $estado + ['bd' => $this->sinClave($parametros)]];
        }

        // La conexión queda activa para que el paso 3 pueda mirar las tablas.
        Bd::conectar($parametros);
        Bd::establecerPrefijo($parametros['prefijo']);
        Config::establecerEnMemoria([
            'bd_host' => $parametros['host'], 'bd_puerto' => $parametros['puerto'],
            'bd_nombre' => $parametros['nombre'], 'bd_usuario' => $parametros['usuario'],
            'bd_clave' => $parametros['clave'], 'bd_prefijo' => $parametros['prefijo'],
        ]);

        $estado['bd'] = $parametros;   // la clave se guarda cifrada en la cookie
        return [3, [], $estado];
    }

    private function paso3(Peticion $peticion, array $estado): array
    {
        $modo = $peticion->campo('modo', 'limpio');
        if (!in_array($modo, ['limpio', 'actualizar', 'anexar'], true)) {
            $modo = 'limpio';
        }

        if (!$this->reconectar($estado)) {
            return [2, ['general' => 'Se perdió la conexión con la base de datos. Vuelve a escribir los datos.'], $estado];
        }

        try {
            $existentes = Esquema::existentes();
            $hechas = Esquema::aplicar($modo, $existentes);
        } catch (\Throwable $e) {
            return [3, ['general' => 'No se pudo aplicar el esquema: ' . $e->getMessage()], $estado];
        }

        $estado['modo'] = $modo;
        $estado['tablas'] = $hechas;
        return [4, [], $estado];
    }

    private function paso4(Peticion $peticion, array $estado): array
    {
        $nombre = $peticion->campo('ad_nombre');
        $correo = mb_strtolower($peticion->campo('ad_correo'));
        $clave = $peticion->campoCrudo('ad_clave');
        $clave2 = $peticion->campoCrudo('ad_clave2');

        $errores = [];
        if (mb_strlen($nombre) < 5) {
            $errores['ad_nombre'] = 'Escribe el nombre completo.';
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores['ad_correo'] = 'Escribe un correo válido.';
        }
        if (mb_strlen($clave) < 12) {
            $errores['ad_clave'] = 'La contraseña debe tener al menos 12 caracteres.';
        }
        if ($clave !== $clave2) {
            $errores['ad_clave2'] = 'Las dos contraseñas deben coincidir.';
        }
        if ($errores) {
            return [4, $errores, $estado + ['admin' => ['nombre' => $nombre, 'correo' => $correo]]];
        }

        $estado['admin'] = [
            'nombre' => $nombre,
            'correo' => $correo,
            'clave'  => $clave,
            'exigir_2fa' => $peticion->marcado('ad_2fa'),
        ];
        return [5, [], $estado];
    }

    /** Paso 5: se crea todo y se escribe la configuración. */
    private function paso5(Peticion $peticion, array $estado): array
    {
        if (empty($estado['bd']) || empty($estado['admin'])) {
            return [2, ['general' => 'Faltan datos de pasos anteriores. Empieza de nuevo.'], $estado];
        }
        if (!$this->reconectar($estado)) {
            return [2, ['general' => 'Se perdió la conexión con la base de datos.'], $estado];
        }

        $nombreEvento = $peticion->campo('ev_nombre', 'Evento sin nombre');
        $fecha = $peticion->campo('ev_inicio');
        $jornadas = max(1, min(30, (int) $peticion->campo('ev_dias', '3')));

        $errores = [];
        if (mb_strlen($nombreEvento) < 3) {
            $errores['ev_nombre'] = 'Escribe el nombre del evento.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || strtotime($fecha) === false) {
            $errores['ev_inicio'] = 'Elige la fecha de inicio.';
        }
        if ($errores) {
            return [5, $errores, $estado];
        }

        $preset = $peticion->campo('preset', 'tic-nocturno');
        $tipografia = $peticion->campo('tipografia', 'tecnologica');

        try {
            // La llave de cifrado se genera antes de guardar nada: la persona y
            // su documento ya se cifran con ella desde el primer registro.
            $llave = Cripto::generarLlave();
            $configuracion = [
                'instalado'       => true,
                'version'         => APP_VERSION,
                'bd_host'         => $estado['bd']['host'],
                'bd_puerto'       => (int) $estado['bd']['puerto'],
                'bd_nombre'       => $estado['bd']['nombre'],
                'bd_usuario'      => $estado['bd']['usuario'],
                'bd_clave'        => $estado['bd']['clave'],
                'bd_prefijo'      => $estado['bd']['prefijo'],
                'llave_cifrado'   => $llave,
                'zona_horaria'    => 'America/Bogota',
                'url_base'        => rtrim(\App\Nucleo\App::peticion()->origen()
                                        . \App\Nucleo\App::peticion()->base(), '/'),
                'correo_remitente' => 'no-responder@' . $this->dominioDelSitio(),
                'correo_nombre'   => $nombreEvento,
                'modo_correo'     => function_exists('mail') ? 'php' : 'registro',
                'exigir_2fa_admin' => (bool) ($estado['admin']['exigir_2fa'] ?? true),
                'proxies_confiables' => [],
                'depurar'         => false,
                'instalado_en'    => date('c'),
            ];

            if (!Config::escribir($configuracion)) {
                return [5, ['general' => 'No se pudo escribir config/config.php. Revisa los permisos de esa carpeta.'], $estado];
            }

            Config::establecerEnMemoria($configuracion);
            Bd::establecerPrefijo($estado['bd']['prefijo']);

            $usuarioId = Usuario::crear([
                'nombre' => $estado['admin']['nombre'],
                'correo' => $estado['admin']['correo'],
                'clave'  => $estado['admin']['clave'],
                'rol'    => 'administrador',
                'puesto' => 'Administración del evento',
            ]);

            $eventoId = Evento::crear([
                'nombre'       => $nombreEvento,
                'dependencia'  => $peticion->campo('ev_dependencia'),
                'sede'         => $peticion->campo('ev_sede'),
                'fecha_inicio' => $fecha,
                'jornadas'     => $jornadas,
                'estado'       => 'abierto',
                'activo'       => true,
                'preset'       => $preset,
                'tipografia'   => $tipografia,
            ]);

            Bitacora::registrar('instalacion', 'sistema', $eventoId, [
                'modo'     => $estado['modo'] ?? 'limpio',
                'jornadas' => $jornadas,
            ]);

            $estado['resultado'] = [
                'usuario_id' => $usuarioId,
                'evento_id'  => $eventoId,
                'correo'     => $estado['admin']['correo'],
                'evento'     => $nombreEvento,
                'jornadas'   => $jornadas,
                'prefijo'    => $estado['bd']['prefijo'],
                'exigir_2fa' => (bool) ($estado['admin']['exigir_2fa'] ?? true),
                'https'      => \App\Nucleo\App::peticion()->esSegura(),
                'correo_ok'  => Correo::disponible(),
            ];
            // Ya no hace falta arrastrar credenciales en la cookie.
            unset($estado['bd'], $estado['admin']);
            $this->guardarEstado(['paso' => 6, 'resultado' => $estado['resultado']]);

            return [6, [], $estado];
        } catch (\Throwable $e) {
            \App\Nucleo\Registro::excepcion($e);
            return [5, ['general' => 'La instalación falló: ' . $e->getMessage()], $estado];
        }
    }

    private function dominioDelSitio(): string
    {
        $host = parse_url(\App\Nucleo\App::peticion()->origen(), PHP_URL_HOST) ?: 'localhost';
        return preg_replace('/^www\./', '', (string) $host) ?: 'localhost';
    }

    private function reconectar(array $estado): bool
    {
        if (empty($estado['bd'])) {
            return false;
        }
        try {
            Bd::conectar($estado['bd']);
            Bd::establecerPrefijo((string) $estado['bd']['prefijo']);
            Config::establecerEnMemoria([
                'bd_host' => $estado['bd']['host'], 'bd_puerto' => $estado['bd']['puerto'],
                'bd_nombre' => $estado['bd']['nombre'], 'bd_usuario' => $estado['bd']['usuario'],
                'bd_clave' => $estado['bd']['clave'], 'bd_prefijo' => $estado['bd']['prefijo'],
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function sinClave(array $parametros): array
    {
        unset($parametros['clave']);
        return $parametros;
    }

    /* =====================================================================
       Estado entre pasos
       -------------------------------------------------------------------------
       Va en una cookie firmada con una llave derivada del servidor. Todavía no
       hay base de datos donde guardarlo. La firma evita que alguien manipule
       los datos de conexión a mitad del proceso.
       ===================================================================== */

    private function llaveEstado(): string
    {
        // Estable durante la instalación y distinta en cada servidor.
        return hash('sha256', RAIZ . '|' . PHP_VERSION . '|instalador');
    }

    private function leerEstado(): array
    {
        $crudo = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($crudo) || $crudo === '') {
            return [];
        }
        $decodificado = base64_decode($crudo, true);
        if ($decodificado === false || !str_contains($decodificado, '|')) {
            return [];
        }
        [$firma, $carga] = explode('|', $decodificado, 2);
        if (!hash_equals(hash_hmac('sha256', $carga, $this->llaveEstado()), $firma)) {
            return [];
        }
        $datos = json_decode($carga, true);
        return is_array($datos) ? $datos : [];
    }

    private function guardarEstado(array $estado): void
    {
        $carga = json_encode($estado, JSON_UNESCAPED_UNICODE) ?: '{}';
        $valor = base64_encode(hash_hmac('sha256', $carga, $this->llaveEstado()) . '|' . $carga);

        $peticion = \App\Nucleo\App::peticion();
        setcookie(self::COOKIE, $valor, [
            'expires'  => time() + 3600,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $valor;
    }

    private function olvidarEstado(): void
    {
        $peticion = \App\Nucleo\App::peticion();
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
