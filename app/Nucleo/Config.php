<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Configuración de la instalación.
 *
 * Vive en config/config.php, un archivo PHP que devuelve un arreglo. Que sea
 * PHP y no .ini o .json es deliberado: si el servidor web llegara a servirlo
 * como archivo estático —cosa que pasa cuando alguien copia mal la
 * configuración de nginx— un .php se ejecuta y no devuelve nada, mientras que
 * un .ini mostraría la contraseña de la base de datos en pantalla.
 */
final class Config
{
    private static array $valores = [];
    private static bool $cargada = false;

    public static function rutaArchivo(): string
    {
        return RAIZ . '/config/config.php';
    }

    public static function existe(): bool
    {
        return is_file(self::rutaArchivo());
    }

    /**
     * Carga config/config.php, y si no se puede, lo dice.
     *
     * Antes era un require a secas. Si el archivo existía pero PHP no podía
     * leerlo —propietario root tras copiarlo por SSH, permisos 0600 de otro
     * usuario: lo normal en un servidor recién montado— el require producía un
     * error fatal antes de que hubiera manejador de errores, y el visitante
     * recibía una página en blanco de cero bytes. También el diagnóstico, que
     * es justo lo que hacía falta en ese momento.
     */
    public static function cargar(): void
    {
        if (self::$cargada) {
            return;
        }
        self::$cargada = true;

        $ruta = self::rutaArchivo();
        if (!is_file($ruta)) {
            return;
        }
        if (!is_readable($ruta)) {
            self::$problema = 'config/config.php existe pero PHP no puede leerlo. '
                . 'Revisa su propietario y sus permisos: debe poder leerlo el usuario del dominio.';
            return;
        }

        try {
            $datos = require $ruta;
        } catch (\Throwable $e) {
            self::$problema = 'config/config.php no se pudo interpretar: ' . $e->getMessage();
            return;
        }

        if (!is_array($datos)) {
            self::$problema = 'config/config.php no devuelve un arreglo. Puede haber quedado truncado.';
            return;
        }
        self::$valores = $datos;
    }

    private static string $problema = '';

    /** Qué impidió leer la configuración, si es que algo lo impidió. */
    public static function problema(): string
    {
        self::cargar();
        return self::$problema;
    }

    public static function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        self::cargar();
        return self::$valores[$clave] ?? $porDefecto;
    }

    public static function todo(): array
    {
        self::cargar();
        return self::$valores;
    }

    /** Solo lo usa el instalador, para poder probar antes de escribir. */
    public static function establecerEnMemoria(array $valores): void
    {
        self::$valores = $valores + self::$valores;
        self::$cargada = true;
    }

    /**
     * Escribe config/config.php.
     *
     * var_export produce PHP válido y escapa por sí solo, así que no hay forma
     * de que una contraseña con comillas rompa el archivo o inyecte código.
     */
    public static function escribir(array $valores): bool
    {
        $cabecera = <<<'PHP'
<?php
/**
 * Configuración de la Plataforma de Eventos TIC.
 *
 * Generado por el asistente de instalación. Contiene credenciales y la llave
 * de cifrado: no debe subirse al repositorio ni compartirse. Si necesitas
 * moverlo de servidor, cópialo por un canal seguro y conserva sus permisos.
 *
 * Para reinstalar desde cero, borra este archivo.
 */

return
PHP;

        $contenido = $cabecera . ' ' . var_export($valores, true) . ";\n";

        $directorio = dirname(self::rutaArchivo());
        if (!is_dir($directorio) && !@mkdir($directorio, 0750, true)) {
            return false;
        }

        // Se escribe en un temporal y se renombra: si el proceso muere a mitad,
        // no queda una configuración truncada que deje la aplicación colgada.
        $temporal = $directorio . '/.config-' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporal, $contenido, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporal, 0640);

        if (!@rename($temporal, self::rutaArchivo())) {
            @unlink($temporal);
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::rutaArchivo(), true);
        }

        self::$valores = $valores;
        self::$cargada = true;
        return true;
    }

    /** ¿Hay una instalación terminada y utilizable? */
    public static function instalado(): bool
    {
        return self::existe() && (bool) self::obtener('instalado', false);
    }
}
