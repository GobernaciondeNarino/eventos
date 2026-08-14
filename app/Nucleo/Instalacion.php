<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

use App\Esquema;

/**
 * Salud de la instalación.
 *
 * Existe por un caso concreto que ocurrió en producción: la configuración
 * quedó escrita y marcada como instalada, pero la base no tenía ninguna cuenta
 * administradora. El resultado era el peor posible —el asistente respondía «ya
 * está instalada» y el acceso del equipo respondía «correo o contraseña
 * incorrectos»— y no había forma de entrar ni de saber por qué.
 *
 * Estar instalado no es tener un archivo de configuración: es tener las tablas,
 * una cuenta con la que entrar y un evento que mostrar. Esta clase lo comprueba
 * y el resto del sistema decide qué hacer con la respuesta.
 *
 * Nunca lanza excepciones. Se la consulta justo en los momentos en que algo ya
 * está roto, y entonces no puede ser ella quien remate la página.
 */
final class Instalacion
{
    private static ?array $cache = null;

    /**
     * @return array{
     *   config: bool, bd: bool, tablas: int, esperadas: int, faltantes: array<int,string>,
     *   administradores: int, usuarios: int, eventos: int, completa: bool, motivo: string
     * }
     */
    public static function diagnostico(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $d = [
            'config'          => Config::existe(),
            'bd'              => false,
            'tablas'          => 0,
            'esperadas'       => count(Esquema::nombres()),
            'faltantes'       => Esquema::nombres(),
            'administradores' => 0,
            'usuarios'        => 0,
            'eventos'         => 0,
            'completa'        => false,
            'motivo'          => '',
        ];

        if (!$d['config']) {
            $d['motivo'] = 'Todavía no existe config/config.php: la plataforma nunca se instaló.';
            return self::$cache = $d;
        }

        try {
            Bd::conectar();
            $d['bd'] = true;

            $presentes = Esquema::existentes();
            $d['tablas'] = count($presentes);
            $d['faltantes'] = array_values(array_diff(Esquema::nombres(), $presentes));

            if ($d['faltantes']) {
                $d['motivo'] = 'Faltan ' . count($d['faltantes']) . ' tablas: '
                    . implode(', ', array_slice($d['faltantes'], 0, 5))
                    . (count($d['faltantes']) > 5 ? '…' : '') . '.';
                return self::$cache = $d;
            }

            $d['usuarios'] = (int) Bd::valor('SELECT COUNT(*) FROM {usuario}');
            $d['administradores'] = (int) Bd::valor(
                "SELECT COUNT(*) FROM {usuario} WHERE rol = 'administrador' AND estado = 'activo'"
            );
            $d['eventos'] = (int) Bd::valor('SELECT COUNT(*) FROM {evento}');

            if ($d['administradores'] === 0) {
                $d['motivo'] = $d['usuarios'] === 0
                    ? 'Las tablas están creadas pero no hay ninguna cuenta del equipo: la instalación '
                        . 'se interrumpió antes de crear la cuenta administradora.'
                    : 'Hay cuentas del equipo pero ninguna administradora activa.';
                return self::$cache = $d;
            }

            $d['completa'] = true;
            if ($d['eventos'] === 0) {
                $d['motivo'] = 'Hay cuenta administradora pero ningún evento creado.';
            }
        } catch (\Throwable $e) {
            $d['motivo'] = 'No se pudo consultar la base de datos: ' . $e->getMessage();
        }

        return self::$cache = $d;
    }

    /** Hay tablas y al menos una cuenta administradora activa con la que entrar. */
    public static function completa(): bool
    {
        return self::diagnostico()['completa'];
    }

    /**
     * Marcada como instalada, pero inservible.
     *
     * Es el estado que reabre el asistente en modo reparación. La barrera no se
     * pierde: el paso 2 sigue exigiendo las credenciales de la base de datos,
     * que quien llega de fuera no tiene.
     */
    public static function incompleta(): bool
    {
        return Config::instalado() && !self::completa();
    }

    public static function hayAdministrador(): bool
    {
        return self::diagnostico()['administradores'] > 0;
    }

    public static function hayEvento(): bool
    {
        return self::diagnostico()['eventos'] > 0;
    }

    /** Tras crear una cuenta o un evento, el diagnóstico anterior ya no vale. */
    public static function olvidar(): void
    {
        self::$cache = null;
    }

    /**
     * Últimos errores que registró la aplicación.
     *
     * Siempre se quita la ruta absoluta del servidor. Y cuando la pantalla es
     * pública —el diagnóstico lo es mientras la plataforma no funcione, porque
     * si no nadie podría averiguar qué falta— se tapan además los correos y las
     * cifras largas: en ese registro acaban, por ejemplo, las direcciones de los
     * asistentes a los que no se les pudo enviar el carnet, y eso no puede verlo
     * cualquiera que pase por ahí.
     *
     * @return array<int, string>
     */
    public static function erroresRecientes(int $cuantos = 10, bool $publico = true): array
    {
        $archivos = glob(Registro::directorio() . '/*.log.php') ?: [];
        if (!$archivos) {
            return [];
        }
        rsort($archivos);

        $lineas = [];
        foreach (array_slice($archivos, 0, 3) as $archivo) {
            $contenido = @file_get_contents($archivo);
            if ($contenido === false) {
                continue;
            }
            foreach (explode("\n", $contenido) as $linea) {
                if (!str_contains($linea, 'ERROR') && !str_contains($linea, 'AVISO')) {
                    continue;
                }
                $linea = str_replace(RAIZ, '…', mb_substr(trim($linea), 0, 400));
                if ($publico) {
                    $linea = (string) preg_replace('/[\w.+-]+@[\w.-]+\.\w+/u', '[correo]', $linea);
                    $linea = (string) preg_replace('/\d{6,}/', '[número]', $linea);
                }
                $lineas[] = $linea;
            }
        }

        return array_slice(array_reverse($lineas), 0, max(1, $cuantos));
    }
}
