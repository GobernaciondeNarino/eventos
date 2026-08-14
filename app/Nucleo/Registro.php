<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Registro de errores en archivo.
 *
 * Distinto de la bitácora: aquí van los fallos técnicos, no las acciones de las
 * personas. La bitácora vive en la base de datos; esto tiene que funcionar
 * también cuando la base de datos es justamente lo que falló.
 *
 * Los archivos se llaman *.log.php y empiezan con una salida inmediata. Es una
 * precaución para el caso —frecuente en Plesk— en que nginx sirve estáticos sin
 * pasar por las reglas de Apache: si alguien pide el registro por la web, se
 * ejecuta como PHP y no devuelve nada, en vez de mostrar rutas y trazas.
 */
final class Registro
{
    private const GUARDA = "<?php exit; ?>\n";

    public static function directorio(): string
    {
        return RAIZ . '/almacen/registro';
    }

    public static function error(string $mensaje, array $contexto = []): void
    {
        self::escribir('ERROR', $mensaje, $contexto);
    }

    public static function aviso(string $mensaje, array $contexto = []): void
    {
        self::escribir('AVISO', $mensaje, $contexto);
    }

    public static function excepcion(\Throwable $e): void
    {
        self::escribir('ERROR', get_class($e) . ': ' . $e->getMessage(), [
            'archivo' => $e->getFile() . ':' . $e->getLine(),
            'traza'   => explode("\n", $e->getTraceAsString()),
        ]);
    }

    /**
     * ¿Se pudo escribir el último apunte?
     *
     * Lo pregunta el diagnóstico. Un registro que no se puede escribir es
     * exactamente el peor momento para enterarse por las malas: es cuando algo
     * ya falló y no queda rastro de qué.
     */
    public static function escribible(): bool
    {
        $directorio = self::directorio();
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }
        return is_dir($directorio) && is_writable($directorio);
    }

    private static function escribir(string $nivel, string $mensaje, array $contexto): void
    {
        $linea = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            $nivel,
            str_replace(["\r", "\n"], ' ', $mensaje),
            $contexto ? ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        $directorio = self::directorio();
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        $archivo = $directorio . '/' . date('Y-m-d') . '.log.php';
        $nuevo = !is_file($archivo);

        $escrito = @file_put_contents($archivo, ($nuevo ? self::GUARDA : '') . $linea, FILE_APPEND | LOCK_EX);
        if ($escrito !== false) {
            if ($nuevo) {
                @chmod($archivo, 0640);
            }
            return;
        }

        // No se pudo escribir en almacen/registro/. Pasa en Plesk cuando la
        // carpeta llega del repositorio con el propietario equivocado, y hasta
        // ahora el fallo se perdía en silencio: la pantalla decía «se registró
        // el problema para revisarlo» y no se había registrado nada, de modo
        // que no quedaba absolutamente ningún rastro de la avería.
        //
        // El registro de PHP siempre está en algún sitio —en Plesk, en los
        // registros del dominio—, así que al menos ahí queda.
        @error_log('[eventos-tic] ' . rtrim($linea));
    }

    /** Borra los registros viejos. Los llama el mantenimiento periódico. */
    public static function podar(int $diasAConservar = 30): int
    {
        $borrados = 0;
        $limite = time() - $diasAConservar * 86400;
        foreach (glob(self::directorio() . '/*.log.php') ?: [] as $archivo) {
            if (filemtime($archivo) < $limite && @unlink($archivo)) {
                $borrados++;
            }
        }
        return $borrados;
    }
}
