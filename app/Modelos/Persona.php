<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;

/**
 * El asistente al evento.
 *
 * El número de documento se guarda cifrado y además como huella HMAC. La huella
 * permite detectar que alguien ya está registrado sin descifrar toda la tabla;
 * el valor cifrado solo se abre cuando de verdad hay que mostrarlo, que es en
 * el carnet de esa misma persona y en la pantalla del operador que la está
 * acreditando.
 */
final class Persona
{
    public const ROLES = ['participante', 'visitante', 'expositor', 'organizador', 'prensa'];

    public static function porId(int $id): ?array
    {
        return Bd::fila('SELECT * FROM {persona} WHERE id = ?', [$id]);
    }

    public static function porCorreo(int $eventoId, string $correo): ?array
    {
        return Bd::fila(
            'SELECT * FROM {persona} WHERE evento_id = ? AND correo = ?',
            [$eventoId, mb_strtolower(trim($correo))]
        );
    }

    public static function porDocumento(int $eventoId, string $documento): ?array
    {
        return Bd::fila(
            'SELECT * FROM {persona} WHERE evento_id = ? AND documento_huella = ?',
            [$eventoId, Cripto::huella(self::normalizarDocumento($documento))]
        );
    }

    /**
     * El documento tal como se compara y se guarda.
     *
     * Se conservan las letras. Quitándolas, dos pasaportes distintos —AB123456
     * y CD123456— quedaban en el mismo «123456» y la plataforma rechazaba al
     * segundo diciéndole que su documento ya estaba registrado con otro correo.
     * Lo mismo con las cédulas de extranjería.
     *
     * Se quitan puntos, espacios y guiones, que es lo que la gente escribe de
     * más, y se pasa a mayúsculas para que la comparación no dependa de cómo
     * lo teclee cada quien.
     */
    public static function normalizarDocumento(string $documento): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/u', '', $documento) ?? '');
    }

    public static function documento(array $persona): string
    {
        try {
            return Cripto::descifrar((string) $persona['documento_cifrado']);
        } catch (\Throwable) {
            // Si la llave cambió, el dato es ilegible. Mejor decirlo que
            // mostrar basura en un carnet.
            return '';
        }
    }

    /**
     * Alta o actualización del preregistro.
     *
     * Se admite que una persona vuelva a diligenciar el formulario con el mismo
     * correo: pasa todo el tiempo, porque el enlace se comparte y la gente lo
     * llena dos veces. En ese caso se actualizan sus datos en vez de fallar.
     */
    public static function registrar(int $eventoId, array $datos): array
    {
        $correo = mb_strtolower(trim((string) $datos['correo']));
        $documento = self::normalizarDocumento((string) $datos['documento']);
        $huella = Cripto::huella($documento);

        return Bd::transaccion(static function () use ($eventoId, $datos, $correo, $documento, $huella): array {
            $existente = self::porCorreo($eventoId, $correo);

            // El documento pertenece a otro correo del mismo evento: son dos
            // personas distintas diciendo tener la misma cédula.
            $porDocumento = Bd::fila(
                'SELECT id, correo FROM {persona} WHERE evento_id = ? AND documento_huella = ?',
                [$eventoId, $huella]
            );
            if ($porDocumento && (!$existente || (int) $porDocumento['id'] !== (int) $existente['id'])) {
                throw new \DomainException(
                    'Ese número de identificación ya está registrado con otro correo. '
                    . 'Si es tuyo, entra con el correo que usaste la primera vez.'
                );
            }

            $campos = [
                'nombre'            => mb_substr(trim((string) $datos['nombre']), 0, 160),
                'tipo_documento'    => in_array($datos['tipo_documento'] ?? 'CC', ['CC', 'CE', 'TI', 'PP'], true)
                                        ? $datos['tipo_documento'] : 'CC',
                'documento_cifrado' => Cripto::cifrar($documento),
                'documento_huella'  => $huella,
                'telefono'          => mb_substr(trim((string) ($datos['telefono'] ?? '')), 0, 32),
                'entidad'           => mb_substr(trim((string) ($datos['entidad'] ?? '')), 0, 160),
                'departamento'      => mb_substr(trim((string) ($datos['departamento'] ?? '')), 0, 80),
                'municipio'         => mb_substr(trim((string) ($datos['municipio'] ?? '')), 0, 80),
                'rol'               => in_array($datos['rol'] ?? '', self::ROLES, true) ? $datos['rol'] : 'participante',
            ];

            if ($existente) {
                Bd::actualizar('persona', $campos, 'id = :id', ['id' => $existente['id']]);
                $id = (int) $existente['id'];
                $nueva = false;
            } else {
                $id = Bd::insertar('persona', $campos + [
                    'evento_id'         => $eventoId,
                    'correo'            => $correo,
                    'autorizo_datos_en' => date('Y-m-d H:i:s'),
                ]);
                $nueva = true;
            }

            self::guardarCaracterizacion($id, $datos);

            $credencial = Credencial::asegurar($id);

            Bitacora::registrar($nueva ? 'preregistro' : 'preregistro_actualizado', 'persona', $id, [
                'rol' => $campos['rol'],
                'municipio' => $campos['municipio'],
            ]);

            return ['id' => $id, 'nueva' => $nueva, 'credencial' => $credencial];
        });
    }

    private static function guardarCaracterizacion(int $personaId, array $datos): void
    {
        $campos = [
            'genero'       => mb_substr((string) ($datos['genero'] ?? ''), 0, 20),
            'rango_edad'   => mb_substr((string) ($datos['rango_edad'] ?? ''), 0, 12),
            'etnia'        => mb_substr((string) ($datos['etnia'] ?? ''), 0, 40),
            'discapacidad' => mb_substr((string) ($datos['discapacidad'] ?? ''), 0, 40),
        ];

        // Si no diligenció nada, no se crea la fila: una tabla de datos
        // sensibles llena de registros vacíos solo agranda el riesgo.
        if (implode('', $campos) === '') {
            return;
        }

        $existe = (int) Bd::valor('SELECT COUNT(*) FROM {persona_caracterizacion} WHERE persona_id = ?', [$personaId]);
        if ($existe > 0) {
            Bd::actualizar('persona_caracterizacion', $campos, 'persona_id = :p', ['p' => $personaId]);
        } else {
            Bd::insertar('persona_caracterizacion', $campos + ['persona_id' => $personaId]);
        }
    }

    public static function caracterizacion(int $personaId): array
    {
        return Bd::fila('SELECT * FROM {persona_caracterizacion} WHERE persona_id = ?', [$personaId]) ?? [];
    }

    /**
     * Listado del backoffice, con filtros.
     *
     * Los filtros se arman con parámetros; lo único que se interpola es el
     * nombre de la columna de orden, y sale de una lista blanca.
     */
    public static function buscar(int $eventoId, array $filtros = []): array
    {
        $donde = ['p.evento_id = :evento'];
        $parametros = ['evento' => $eventoId];

        if (!empty($filtros['texto'])) {
            // Un marcador distinto por columna. Con las sentencias preparadas
            // de verdad —sin emulación, que es como está configurado PDO—
            // MySQL no admite repetir el mismo nombre, y la consulta entera
            // fallaba: toda búsqueda por texto del panel respondía 500,
            // incluida la del escáner, que es la que se usa en la puerta
            // cuando a alguien no le funciona el código.
            $donde[] = '(p.nombre LIKE :texto1 OR p.correo LIKE :texto2'
                . ' OR p.entidad LIKE :texto3 OR p.municipio LIKE :texto4)';
            $patron = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filtros['texto']) . '%';
            $parametros += ['texto1' => $patron, 'texto2' => $patron,
                            'texto3' => $patron, 'texto4' => $patron];
        }
        if (!empty($filtros['rol']) && in_array($filtros['rol'], self::ROLES, true)) {
            $donde[] = 'p.rol = :rol';
            $parametros['rol'] = $filtros['rol'];
        }
        if (!empty($filtros['dia'])) {
            $donde[] = 'EXISTS (SELECT 1 FROM {asistencia} a
                                  JOIN {evento_dia} d ON d.id = a.evento_dia_id
                                 WHERE a.persona_id = p.id AND d.numero = :dia)';
            $parametros['dia'] = (int) $filtros['dia'];
        }

        $limite = max(1, min(500, (int) ($filtros['limite'] ?? 200)));

        return Bd::filas(
            'SELECT p.*,
                    (SELECT GROUP_CONCAT(d.numero ORDER BY d.numero)
                       FROM {asistencia} a
                       JOIN {evento_dia} d ON d.id = a.evento_dia_id
                      WHERE a.persona_id = p.id) AS dias
               FROM {persona} p
              WHERE ' . implode(' AND ', $donde) . '
           ORDER BY p.nombre
              LIMIT ' . $limite,
            $parametros
        );
    }

    /** Días en que ingresó, como arreglo de enteros. */
    public static function diasDe(?string $concatenado): array
    {
        if (!$concatenado) {
            return [];
        }
        return array_map('intval', explode(',', $concatenado));
    }

    /* =====================================================================
       Métodos de acceso distintos del código por correo
       -------------------------------------------------------------------------
       Existen porque atar la entrada al correo dejó a todo el mundo fuera
       cuando el correo falló. Ver App\Nucleo\Autenticacion.
       ===================================================================== */

    /** Guarda la contraseña simple de una persona. Se guarda el hash, claro. */
    public static function ponerClave(int $id, string $clave): void
    {
        Bd::ejecutar('UPDATE {persona} SET clave_hash = ? WHERE id = ?', [
            Cripto::hashClave($clave),
            $id,
        ]);
    }

    public static function tieneClave(array $persona): bool
    {
        return (string) ($persona['clave_hash'] ?? '') !== '';
    }

    /**
     * ¿Es esta la contraseña de esta persona?
     *
     * Con hash_equals implícito dentro de password_verify, y sin atajos: si la
     * persona no tiene contraseña puesta, se compara igual contra un hash
     * inventado para que responder tarde lo mismo y no se pueda averiguar quién
     * la tiene y quién no midiendo el tiempo.
     */
    public static function claveValida(array $persona, string $clave): bool
    {
        $hash = (string) ($persona['clave_hash'] ?? '');
        if ($hash === '') {
            Cripto::verificarClave($clave, '$2y$12$' . str_repeat('a', 53));
            return false;
        }
        return Cripto::verificarClave($clave, $hash);
    }

    /**
     * El token del QR de acceso, creándolo si no lo tenía.
     *
     * Son 128 bits en hexadecimal, como los tokens de credencial: quien vea un
     * QR ajeno no puede deducir otro, y adivinarlo no es viable.
     *
     * Es distinto del token de la credencial a propósito. El de la credencial
     * está impreso en la escarapela y se enseña a cualquiera para intercambiar
     * contactos; este identifica a su dueño y abre su sesión. Que fueran el
     * mismo convertiría cada foto de una escarapela en una llave.
     */
    public static function tokenDeAcceso(int $id): string
    {
        $fila = Bd::fila('SELECT acceso_token FROM {persona} WHERE id = ?', [$id]);
        $actual = (string) ($fila['acceso_token'] ?? '');
        if ($actual !== '') {
            return $actual;
        }

        $nuevo = Cripto::token(16);
        Bd::ejecutar('UPDATE {persona} SET acceso_token = ?, acceso_token_en = NOW() WHERE id = ?', [
            $nuevo,
            $id,
        ]);
        return $nuevo;
    }

    /** Cambia el token: invalida el QR anterior. Para cuando se pierde una escarapela. */
    public static function regenerarTokenDeAcceso(int $id): string
    {
        $nuevo = Cripto::token(16);
        Bd::ejecutar('UPDATE {persona} SET acceso_token = ?, acceso_token_en = NOW() WHERE id = ?', [
            $nuevo,
            $id,
        ]);
        return $nuevo;
    }

    public static function porTokenDeAcceso(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return Bd::fila('SELECT * FROM {persona} WHERE acceso_token = ?', [$token]);
    }
}
