<?php
declare(strict_types=1);

namespace App\Nucleo;

use PDO;
use PDOException;
use PDOStatement;

defined('EVENTOS_TIC') || exit;

/**
 * Acceso a la base de datos.
 *
 * Una sola puerta, y esa puerta solo acepta sentencias preparadas. No existe
 * un método que reciba SQL ya interpolado con datos: si alguien quiere
 * concatenar, tiene que salirse de esta clase, y eso se nota en la revisión.
 *
 * El prefijo de tablas se resuelve aquí con la notación {tabla}, para que las
 * consultas queden legibles y no haya que arrastrar el prefijo por todo el
 * código.
 */
final class Bd
{
    private static ?PDO $pdo = null;
    private static string $prefijo = '';
    private static int $consultas = 0;

    public static function conectar(?array $parametros = null): PDO
    {
        if (self::$pdo !== null && $parametros === null) {
            return self::$pdo;
        }

        $p = $parametros ?? [
            'host'     => Config::obtener('bd_host', 'localhost'),
            'puerto'   => Config::obtener('bd_puerto', 3306),
            'nombre'   => Config::obtener('bd_nombre', ''),
            'usuario'  => Config::obtener('bd_usuario', ''),
            'clave'    => Config::obtener('bd_clave', ''),
            'prefijo'  => Config::obtener('bd_prefijo', 'evt_'),
        ];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $p['host'],
            (int) $p['puerto'],
            $p['nombre']
        );

        $pdo = new PDO($dsn, $p['usuario'], (string) $p['clave'], [
            // Que un error de base de datos sea una excepción y no un valor
            // falso que se arrastre silenciosamente hasta producir datos malos.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Sentencias preparadas de verdad, en el servidor. Con la emulación
            // activada PDO interpola del lado del cliente, y ahí es donde
            // aparecen las inyecciones por juegos de caracteres.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // Modo estricto: un dato que no cabe en su columna debe fallar, no
        // guardarse recortado en silencio.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET SESSION time_zone = '" . self::desplazamientoHorario() . "'");

        if ($parametros === null) {
            self::$pdo = $pdo;
            self::$prefijo = (string) $p['prefijo'];
        }
        return $pdo;
    }

    private static function desplazamientoHorario(): string
    {
        // Se fija el desfase de la zona configurada para que NOW() coincida con
        // lo que ve PHP. Sin esto, los sellos de ingreso pueden salir con cinco
        // horas de diferencia según cómo esté el servidor.
        $zona = new \DateTimeZone(Config::obtener('zona_horaria', 'America/Bogota'));
        $segundos = $zona->getOffset(new \DateTime('now', $zona));
        $signo = $segundos < 0 ? '-' : '+';
        $segundos = abs($segundos);
        return sprintf('%s%02d:%02d', $signo, intdiv($segundos, 3600), intdiv($segundos % 3600, 60));
    }

    public static function establecerPrefijo(string $prefijo): void
    {
        self::$prefijo = $prefijo;
    }

    public static function prefijo(): string
    {
        return self::$prefijo !== '' ? self::$prefijo : (string) Config::obtener('bd_prefijo', 'evt_');
    }

    /** Reemplaza {tabla} por `prefijo_tabla`. */
    public static function resolver(string $sql): string
    {
        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn(array $m): string => '`' . self::prefijo() . $m[1] . '`',
            $sql
        ) ?? $sql;
    }

    public static function ejecutar(string $sql, array $parametros = []): PDOStatement
    {
        self::$consultas++;
        $sentencia = self::conectar()->prepare(self::resolver($sql));
        $sentencia->execute($parametros);
        return $sentencia;
    }

    public static function fila(string $sql, array $parametros = []): ?array
    {
        $fila = self::ejecutar($sql, $parametros)->fetch();
        return $fila === false ? null : $fila;
    }

    public static function filas(string $sql, array $parametros = []): array
    {
        return self::ejecutar($sql, $parametros)->fetchAll();
    }

    public static function valor(string $sql, array $parametros = []): mixed
    {
        $v = self::ejecutar($sql, $parametros)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insertar(string $tabla, array $datos): int
    {
        $columnas = array_keys($datos);
        $sql = sprintf(
            'INSERT INTO {%s} (%s) VALUES (%s)',
            $tabla,
            implode(', ', array_map(static fn($c) => "`$c`", $columnas)),
            implode(', ', array_map(static fn($c) => ":$c", $columnas))
        );
        self::ejecutar($sql, $datos);
        return (int) self::conectar()->lastInsertId();
    }

    public static function actualizar(string $tabla, array $datos, string $donde, array $parametrosDonde = []): int
    {
        $asignaciones = implode(', ', array_map(static fn($c) => "`$c` = :s_$c", array_keys($datos)));
        $parametros = [];
        foreach ($datos as $c => $v) {
            $parametros["s_$c"] = $v;
        }
        $sql = sprintf('UPDATE {%s} SET %s WHERE %s', $tabla, $asignaciones, $donde);
        return self::ejecutar($sql, $parametros + $parametrosDonde)->rowCount();
    }

    public static function transaccion(callable $trabajo): mixed
    {
        $pdo = self::conectar();
        $pdo->beginTransaction();
        try {
            $resultado = $trabajo();
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** ¿Existe la tabla, ya con el prefijo aplicado? */
    public static function existeTabla(string $tabla): bool
    {
        $sql = 'SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
        return (int) self::valorDirecto($sql, [self::prefijo() . $tabla]) > 0;
    }

    public static function existeColumna(string $tabla, string $columna): bool
    {
        $sql = 'SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?';
        return (int) self::valorDirecto($sql, [self::prefijo() . $tabla, $columna]) > 0;
    }

    /** Consulta sin resolución de {tabla}: para information_schema. */
    public static function valorDirecto(string $sql, array $parametros = []): mixed
    {
        $sentencia = self::conectar()->prepare($sql);
        $sentencia->execute($parametros);
        $v = $sentencia->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function filasDirecto(string $sql, array $parametros = []): array
    {
        $sentencia = self::conectar()->prepare($sql);
        $sentencia->execute($parametros);
        return $sentencia->fetchAll();
    }

    /** Ejecuta SQL sin parámetros. Reservado al instalador y a las migraciones. */
    public static function ejecutarBruto(string $sql): void
    {
        self::conectar()->exec(self::resolver($sql));
    }

    public static function totalConsultas(): int
    {
        return self::$consultas;
    }

    /** Prueba de conexión para el instalador: devuelve [ok, mensaje]. */
    public static function probar(array $parametros): array
    {
        try {
            $pdo = self::conectar($parametros);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $juego = $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch();
            $charset = $juego['Value'] ?? 'desconocido';

            if (!str_starts_with($charset, 'utf8')) {
                return [false, "La base de datos usa el juego de caracteres «$charset». "
                    . 'Debe ser utf8mb4 o las tildes y la ñ se guardarán mal.'];
            }
            return [true, "Conexión correcta · $version · $charset"];
        } catch (PDOException $e) {
            return [false, self::mensajeAmable($e)];
        }
    }

    /**
     * Traduce el error de PDO a algo accionable.
     *
     * El mensaje crudo puede incluir el usuario y el host, así que no se
     * muestra tal cual en una pantalla pública.
     */
    private static function mensajeAmable(PDOException $e): string
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        return match ($codigo) {
            1045 => 'Usuario o contraseña incorrectos.',
            1049 => 'La base de datos no existe. Créala primero desde Plesk.',
            2002, 2003 => 'No se pudo conectar al servidor de base de datos. Revisa el servidor y el puerto.',
            1044 => 'El usuario existe pero no tiene permisos sobre esa base de datos.',
            default => 'No se pudo conectar (código ' . $codigo . ').',
        };
    }
}
