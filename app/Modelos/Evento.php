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
    /**
     * ¿Es una fecha de calendario que existe?
     *
     * La expresión regular sola no basta: «2026-02-30» tiene el formato
     * correcto y strtotime() hasta devuelve un número, pero MySQL en modo
     * estricto rechaza esa fecha y crear el evento terminaba en un 500 en vez
     * de en un aviso junto al campo.
     */
    public static function fechaValida(string $fecha): bool
    {
        if (!preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $fecha, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

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

    /**
     * Agrega una jornada al final del evento.
     *
     * El número es el siguiente libre y la fecha la elige quien la agrega: los
     * eventos reales no siempre son días seguidos —dos jornadas y una tercera
     * de cierre a la semana siguiente— y forzar la continuidad obligaba a
     * reinstalar el evento entero.
     *
     * Devuelve el número asignado.
     */
    public static function agregarJornada(int $eventoId, string $fecha, string $abre = '', string $cierra = ''): int
    {
        if (!self::fechaValida($fecha)) {
            throw new \DomainException('Esa fecha no es válida.');
        }

        $repetida = Bd::fila(
            'SELECT numero FROM {evento_dia} WHERE evento_id = ? AND fecha = ?',
            [$eventoId, $fecha]
        );
        if ($repetida) {
            throw new \DomainException(
                'Ya existe la jornada del ' . fecha($fecha) . ' (día ' . $repetida['numero'] . ').'
            );
        }

        $siguiente = (int) (Bd::valor(
            'SELECT MAX(numero) FROM {evento_dia} WHERE evento_id = ?',
            [$eventoId]
        ) ?? 0) + 1;

        if ($siguiente > 60) {
            throw new \DomainException('Un evento no puede tener más de 60 jornadas.');
        }

        $columnas = [
            'evento_id' => $eventoId,
            'numero'    => $siguiente,
            'fecha'     => $fecha,
            'token'     => Cripto::token(16),
        ];
        if (preg_match('/^\d{2}:\d{2}$/', $abre)) {
            $columnas['abre_a'] = $abre . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $cierra)) {
            $columnas['cierra_a'] = $cierra . ':00';
        }

        Bd::insertar('evento_dia', $columnas);

        // El contador del evento tiene que seguir cuadrando: es lo que se
        // muestra en la portada y lo que usa el selector de día preferido del
        // formulario de expositores.
        Bd::ejecutar(
            'UPDATE {evento} SET jornadas = (SELECT COUNT(*) FROM {evento_dia} WHERE evento_id = ?) WHERE id = ?',
            [$eventoId, $eventoId]
        );

        Bitacora::registrar('jornada_agregada', 'evento_dia', $siguiente, [
            'evento' => $eventoId,
            'fecha'  => $fecha,
        ]);
        return $siguiente;
    }

    /**
     * Elimina una jornada.
     *
     * No se borra una jornada con ingresos registrados: esos registros son la
     * base de los reportes de asistencia del evento y borrarlos en cascada
     * desde una pantalla de configuración sería demasiado fácil. Quien de
     * verdad quiera hacerlo tiene que quitar antes los ingresos.
     *
     * Los números NO se renumeran. El número está impreso en el pliego de la
     * puerta y sale en el historial de cada asistente; corrigiéndolo, el «día
     * 3» de un carnet pasaría a señalar otra fecha.
     */
    public static function eliminarJornada(int $eventoId, int $numero): void
    {
        $jornada = self::jornada($eventoId, $numero);
        if (!$jornada) {
            throw new \DomainException('Esa jornada no existe.');
        }

        $ingresos = (int) Bd::valor(
            'SELECT COUNT(*) FROM {asistencia} WHERE evento_dia_id = ?',
            [(int) $jornada['id']]
        );
        if ($ingresos > 0) {
            throw new \DomainException(
                'El día ' . $numero . ' ya tiene ' . $ingresos . ' ingreso' . ($ingresos === 1 ? '' : 's')
                . ' registrado' . ($ingresos === 1 ? '' : 's') . '. No se puede eliminar sin perder esos datos.'
            );
        }

        $charlas = (int) Bd::valor(
            'SELECT COUNT(*) FROM {charla} WHERE evento_dia_id = ?',
            [(int) $jornada['id']]
        );
        if ($charlas > 0) {
            throw new \DomainException(
                'El día ' . $numero . ' tiene ' . $charlas . ' charla' . ($charlas === 1 ? '' : 's')
                . ' en la agenda. Muévelas de día antes de eliminarlo.'
            );
        }

        if (count(self::jornadas($eventoId)) <= 1) {
            throw new \DomainException('Un evento tiene que quedarse con al menos una jornada.');
        }

        Bd::ejecutar('DELETE FROM {evento_dia} WHERE id = ?', [(int) $jornada['id']]);
        Bd::ejecutar(
            'UPDATE {evento} SET jornadas = (SELECT COUNT(*) FROM {evento_dia} WHERE evento_id = ?) WHERE id = ?',
            [$eventoId, $eventoId]
        );

        Bitacora::registrar('jornada_eliminada', 'evento_dia', $numero, [
            'evento' => $eventoId,
            'fecha'  => (string) $jornada['fecha'],
        ]);
    }

    /** Cambia la fecha y el horario de una jornada. */
    public static function ajustarJornada(int $eventoId, int $numero, string $fecha, string $abre, string $cierra): void
    {
        $jornada = self::jornada($eventoId, $numero);
        if (!$jornada) {
            throw new \DomainException('Esa jornada no existe.');
        }
        if (!self::fechaValida($fecha)) {
            throw new \DomainException('Esa fecha no es válida.');
        }

        $choque = Bd::fila(
            'SELECT numero FROM {evento_dia} WHERE evento_id = ? AND fecha = ? AND numero <> ?',
            [$eventoId, $fecha, $numero]
        );
        if ($choque) {
            throw new \DomainException('El día ' . $choque['numero'] . ' ya está en esa fecha.');
        }

        Bd::ejecutar(
            'UPDATE {evento_dia} SET fecha = ?, abre_a = ?, cierra_a = ? WHERE id = ?',
            [
                $fecha,
                preg_match('/^\d{2}:\d{2}$/', $abre) ? $abre . ':00' : (string) $jornada['abre_a'],
                preg_match('/^\d{2}:\d{2}$/', $cierra) ? $cierra . ':00' : (string) $jornada['cierra_a'],
                (int) $jornada['id'],
            ]
        );

        Bitacora::registrar('jornada_ajustada', 'evento_dia', $numero, [
            'evento' => $eventoId,
            'fecha'  => $fecha,
        ]);
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
