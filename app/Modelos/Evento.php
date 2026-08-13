<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;

/**
 * El evento y sus jornadas.
 *
 * El código QR de acceso pertenece a la jornada y no al evento: por eso vive en
 * evento_dia y por eso cambia cada día sin que nadie tenga que hacer nada.
 */
final class Evento
{
    public static function porId(int $id): ?array
    {
        return Bd::fila('SELECT * FROM {evento} WHERE id = ?', [$id]);
    }

    public static function todos(): array
    {
        return Bd::filas(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM {persona} p WHERE p.evento_id = e.id) AS registros
               FROM {evento} e
           ORDER BY e.activo DESC, e.fecha_inicio DESC'
        );
    }

    /**
     * Crea el evento con sus jornadas y su identidad.
     * Todo en una transacción: un evento con la mitad de sus días no sirve.
     */
    public static function crear(array $datos): int
    {
        return Bd::transaccion(static function () use ($datos): int {
            $jornadas = max(1, min(30, (int) ($datos['jornadas'] ?? 1)));

            $id = Bd::insertar('evento', [
                'nombre'       => mb_substr(trim((string) $datos['nombre']), 0, 160),
                'dependencia'  => mb_substr(trim((string) ($datos['dependencia'] ?? '')), 0, 160),
                'sede'         => mb_substr(trim((string) ($datos['sede'] ?? '')), 0, 160),
                'fecha_inicio' => $datos['fecha_inicio'],
                'jornadas'     => $jornadas,
                'estado'       => $datos['estado'] ?? 'borrador',
                'activo'       => !empty($datos['activo']) ? 1 : 0,
            ]);

            if (!empty($datos['activo'])) {
                Bd::ejecutar('UPDATE {evento} SET activo = 0 WHERE id <> ?', [$id]);
            }

            self::generarJornadas($id, (string) $datos['fecha_inicio'], $jornadas);

            Bd::insertar('evento_tema', [
                'evento_id'  => $id,
                'preset'     => $datos['preset'] ?? 'tic-nocturno',
                'tipografia' => $datos['tipografia'] ?? 'tecnologica',
            ]);

            return $id;
        });
    }

    public static function generarJornadas(int $eventoId, string $fechaInicio, int $cuantas): void
    {
        for ($n = 1; $n <= $cuantas; $n++) {
            $fecha = date('Y-m-d', strtotime($fechaInicio . ' +' . ($n - 1) . ' day'));
            Bd::ejecutar(
                'INSERT INTO {evento_dia} (evento_id, numero, fecha, token)
                      VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE fecha = VALUES(fecha)',
                [$eventoId, $n, $fecha, Cripto::token(16)]
            );
        }
    }

    public static function jornadas(int $eventoId): array
    {
        return Bd::filas(
            'SELECT d.*,
                    (SELECT COUNT(*) FROM {asistencia} a WHERE a.evento_dia_id = d.id) AS ingresos
               FROM {evento_dia} d
              WHERE d.evento_id = ?
           ORDER BY d.numero',
            [$eventoId]
        );
    }

    public static function jornada(int $eventoId, int $numero): ?array
    {
        return Bd::fila(
            'SELECT * FROM {evento_dia} WHERE evento_id = ? AND numero = ?',
            [$eventoId, $numero]
        );
    }

    /** Busca la jornada por el token de su código QR. */
    public static function jornadaPorToken(string $token): ?array
    {
        return Bd::fila(
            'SELECT d.*, e.nombre AS evento_nombre, e.id AS evento_id
               FROM {evento_dia} d
               JOIN {evento} e ON e.id = d.evento_id
              WHERE d.token = ?',
            [$token]
        );
    }

    /** La jornada de hoy, si el evento está en curso. */
    public static function jornadaDeHoy(int $eventoId): ?array
    {
        return Bd::fila(
            'SELECT * FROM {evento_dia} WHERE evento_id = ? AND fecha = CURDATE()',
            [$eventoId]
        );
    }

    /**
     * ¿Se puede registrar ingreso en esta jornada ahora mismo?
     *
     * Dos condiciones: que sea el día correcto y que esté dentro del horario.
     * La segunda evita que un código fotografiado la noche anterior sirva a las
     * tres de la mañana.
     */
    public static function jornadaAbierta(array $jornada): array
    {
        $hoy = date('Y-m-d');
        if ($jornada['fecha'] !== $hoy) {
            $cuando = strtotime((string) $jornada['fecha']) > strtotime($hoy) ? 'todavía no empieza' : 'ya terminó';
            return [false, 'Esta jornada ' . $cuando . '. El código corresponde al '
                . fecha((string) $jornada['fecha']) . '.'];
        }

        $ahora = date('H:i:s');
        if ($ahora < $jornada['abre_a']) {
            return [false, 'El registro de ingreso abre a las ' . substr((string) $jornada['abre_a'], 0, 5) . '.'];
        }
        if ($ahora > $jornada['cierra_a']) {
            return [false, 'El registro de ingreso cerró a las ' . substr((string) $jornada['cierra_a'], 0, 5) . '.'];
        }
        return [true, ''];
    }

    /** Cambia el token de una jornada: el código impreso anterior deja de servir. */
    public static function rotarToken(int $eventoId, int $numero): string
    {
        $nuevo = Cripto::token(16);
        Bd::ejecutar(
            'UPDATE {evento_dia} SET token = ?, token_rotado_en = NOW()
              WHERE evento_id = ? AND numero = ?',
            [$nuevo, $eventoId, $numero]
        );
        Bitacora::registrar('token_dia_rotado', 'evento_dia', $numero, ['evento' => $eventoId]);
        return $nuevo;
    }

    public static function activar(int $id): void
    {
        Bd::transaccion(static function () use ($id): void {
            Bd::ejecutar('UPDATE {evento} SET activo = 0');
            Bd::ejecutar('UPDATE {evento} SET activo = 1 WHERE id = ?', [$id]);
        });
        Bitacora::registrar('evento_activado', 'evento', $id);
    }

    /** Indicadores del panel. */
    public static function resumen(int $eventoId): array
    {
        $total = (int) Bd::valor('SELECT COUNT(*) FROM {persona} WHERE evento_id = ?', [$eventoId]);
        $expositores = (int) Bd::valor(
            "SELECT COUNT(*) FROM {persona} WHERE evento_id = ? AND rol = 'expositor'",
            [$eventoId]
        );
        $municipios = (int) Bd::valor(
            "SELECT COUNT(DISTINCT municipio) FROM {persona} WHERE evento_id = ? AND municipio <> ''",
            [$eventoId]
        );
        $porAprobar = (int) Bd::valor(
            "SELECT COUNT(*) FROM {propuesta} pr
               JOIN {persona} p ON p.id = pr.persona_id
              WHERE p.evento_id = ? AND pr.estado = 'pendiente'",
            [$eventoId]
        );

        $hoy = self::jornadaDeHoy($eventoId);
        $ingresosHoy = $hoy
            ? (int) Bd::valor('SELECT COUNT(*) FROM {asistencia} WHERE evento_dia_id = ?', [$hoy['id']])
            : 0;

        return [
            'registros'    => $total,
            'expositores'  => $expositores,
            'municipios'   => $municipios,
            'por_aprobar'  => $porAprobar,
            'jornada_hoy'  => $hoy,
            'ingresos_hoy' => $ingresosHoy,
        ];
    }

    /** Cobertura territorial, para el panel. */
    public static function porMunicipio(int $eventoId, int $limite = 12): array
    {
        $limite = max(1, min(64, $limite));
        return Bd::filas(
            "SELECT municipio, COUNT(*) AS n
               FROM {persona}
              WHERE evento_id = ? AND municipio <> ''
           GROUP BY municipio
           ORDER BY n DESC
              LIMIT $limite",
            [$eventoId]
        );
    }
}
