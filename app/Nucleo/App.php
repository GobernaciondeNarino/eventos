<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Arranque de la aplicación.
 *
 * Orden deliberado: primero las cabeceras de seguridad —que deben salir aunque
 * después todo falle—, luego la configuración, luego la base de datos, y solo
 * al final el despacho de la ruta.
 */
final class App
{
    private static ?Peticion $peticion = null;
    private static ?array $evento = null;

    public static function peticion(): Peticion
    {
        return self::$peticion ??= new Peticion();
    }

    public static function arrancar(): never
    {
        self::configurarErrores();
        $peticion = self::peticion();
        self::cabeceras($peticion);

        // Valores mínimos para que la plantilla pueda pintarse desde el primer
        // instante. Sin esto, un error ocurrido antes de conectar a la base de
        // datos —o el propio instalador— reventaría al intentar dibujar la
        // página de error, y el visitante vería una pantalla en blanco.
        self::compartirBase();

        Config::cargar();

        date_default_timezone_set((string) Config::obtener('zona_horaria', 'America/Bogota'));

        // Sin instalación no hay base de datos a la que conectarse, así que se
        // despacha de una vez: la única ruta que responderá es el asistente,
        // que abre su propia conexión cuando el usuario le da los datos.
        if (!Config::instalado()) {
            self::modoInstalacion($peticion);
            self::despachar($peticion);
        }

        try {
            Bd::conectar();
        } catch (\Throwable $e) {
            Registro::excepcion($e);
            Respuesta::error(503, 'Base de datos fuera de servicio',
                'La plataforma no puede conectarse a su base de datos en este momento. '
                . 'Si el problema continúa, avisa al área de sistemas.');
        }

        Sesion::limpiar();
        self::compartirConVistas();

        // El testigo se emite en cualquier página, no solo donde hay un
        // formulario: quien llega directo a una pantalla que envía por POST
        // —el lector de QR abre rutas sueltas— no debería toparse con un
        // «la página expiró» sin haber hecho nada raro.
        if (!$peticion->esPost()) {
            Csrf::token();
        }

        self::despachar($peticion);
    }

    private static function despachar(Peticion $peticion): never
    {
        $enrutador = new Enrutador();
        require RAIZ . '/app/rutas.php';
        $enrutador->despachar($peticion);
    }

    /* =====================================================================
       Modo instalación
       ===================================================================== */

    private static function modoInstalacion(Peticion $peticion): void
    {
        $ruta = $peticion->ruta();
        // Los recursos estáticos tienen que seguir sirviéndose para que el
        // asistente se vea con su propio diseño.
        if (str_starts_with($ruta, '/assets/')) {
            return;
        }
        if (!str_starts_with($ruta, '/instalar')) {
            Respuesta::redirigirAbsoluto(Url::a('/instalar'));
        }
    }

    /* =====================================================================
       Cabeceras de seguridad
       ===================================================================== */

    private static function cabeceras(Peticion $peticion): void
    {
        // Se envían desde PHP además de desde .htaccess. En Plesk es común que
        // mod_headers no esté activo o que nginx sirva por delante sin aplicar
        // las reglas de Apache; así la protección no depende de eso.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header_remove('X-Powered-By');

        // La cámara del escáner y el resto de la aplicación son del mismo
        // origen; no hay CDN. La excepción de estilos en línea está explicada
        // en docs/SEGURIDAD.md.
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; "
            . "connect-src 'self'; media-src 'self' blob:; form-action 'self'; "
            . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

        if ($peticion->esSegura()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /* =====================================================================
       Errores
       ===================================================================== */

    private static function configurarErrores(): void
    {
        // Nunca se muestran los errores al visitante: una traza revela rutas del
        // servidor, nombres de tablas y a veces credenciales.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_exception_handler(static function (\Throwable $e): void {
            Registro::excepcion($e);
            if (Config::obtener('depurar', false)) {
                Respuesta::error(500, 'Error interno',
                    get_class($e) . ': ' . $e->getMessage() . ' — '
                    . basename($e->getFile()) . ':' . $e->getLine());
            }
            Respuesta::error(500, 'Algo salió mal',
                'Se registró el problema para revisarlo. Vuelve a intentarlo en unos minutos.');
        });

        set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
            if (!(error_reporting() & $nivel)) {
                return false;
            }
            throw new \ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Registro::error($error['message'], [
                    'archivo' => $error['file'] . ':' . $error['line'],
                ]);
            }
        });
    }

    /* =====================================================================
       Contexto de las vistas
       ===================================================================== */

    /** Contexto mínimo, disponible incluso sin base de datos. */
    private static function compartirBase(): void
    {
        Respuesta::compartir('evento', null);
        Respuesta::compartir('tema', Tema::del(0));
        Respuesta::compartir('persona', null);
        Respuesta::compartir('usuario', null);
        Respuesta::compartir('aviso', null);
        Respuesta::compartir('titulo', '');
        Respuesta::compartir('pantalla', '');
        Respuesta::compartir('rutaActual', self::peticion()->ruta());
    }

    private static function compartirConVistas(): void
    {
        $evento = self::eventoActivo();

        Respuesta::compartir('evento', $evento);
        Respuesta::compartir('tema', Tema::del($evento['id'] ?? 0));
        Respuesta::compartir('persona', Guardia::personaActual());
        Respuesta::compartir('usuario', Guardia::usuarioActual());
        Respuesta::compartir('aviso', Aviso::tomar());
        Respuesta::compartir('rutaActual', self::peticion()->ruta());
    }

    /** El evento marcado como activo; si no hay ninguno, el más reciente. */
    public static function eventoActivo(): ?array
    {
        if (self::$evento !== null) {
            return self::$evento;
        }
        $fila = Bd::fila('SELECT * FROM {evento} WHERE activo = 1 ORDER BY id DESC LIMIT 1')
            ?? Bd::fila('SELECT * FROM {evento} ORDER BY id DESC LIMIT 1');
        return self::$evento = $fila;
    }

    public static function olvidarEvento(): void
    {
        self::$evento = null;
    }
}
