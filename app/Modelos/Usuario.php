<?php
declare(strict_types=1);

namespace App\Modelos;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;
use App\Nucleo\Bitacora;
use App\Nucleo\Cripto;

/**
 * El equipo organizador: quien entra al backoffice.
 *
 * Distinto de Persona a propósito. Un asistente y un operador tienen ciclos de
 * vida, riesgos y formas de identificarse muy distintos; mezclarlos en una sola
 * tabla obliga a poner banderas por todas partes y termina en que alguien se
 * autentica por el camino equivocado.
 */
final class Usuario
{
    public const ROLES = ['administrador', 'operador', 'consulta'];

    /**
     * Hash de una contraseña que no es de nadie.
     *
     * Sirve para gastar el mismo tiempo cuando el correo no existe, y que la
     * duración de la respuesta no delate qué cuentas están registradas.
     * Generado con bcrypt de coste 12; ninguna contraseña real coincide.
     */
    public const HASH_DESCARTE = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7Nt1zMcbGtVDXTfLXAmBGCnfoDMS6Hy';

    public static function porId(int $id): ?array
    {
        return Bd::fila('SELECT * FROM {usuario} WHERE id = ?', [$id]);
    }

    public static function porCorreo(string $correo): ?array
    {
        return Bd::fila('SELECT * FROM {usuario} WHERE correo = ?', [mb_strtolower(trim($correo))]);
    }

    public static function todos(): array
    {
        return Bd::filas(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM {asistencia} a
                      WHERE a.operador_id = u.id AND DATE(a.registrado_en) = CURDATE()) AS escaneos_hoy
               FROM {usuario} u
           ORDER BY FIELD(u.rol, "administrador", "operador", "consulta"), u.nombre'
        );
    }

    public static function crear(array $datos): int
    {
        $correo = mb_strtolower(trim((string) $datos['correo']));
        if (self::porCorreo($correo)) {
            throw new \DomainException('Ya existe una cuenta con ese correo.');
        }

        $rol = in_array($datos['rol'] ?? '', self::ROLES, true) ? $datos['rol'] : 'operador';

        $id = Bd::insertar('usuario', [
            'nombre'       => mb_substr(trim((string) $datos['nombre']), 0, 160),
            'correo'       => $correo,
            'clave_hash'   => Cripto::hashClave((string) $datos['clave']),
            'rol'          => $rol,
            'puesto'       => mb_substr(trim((string) ($datos['puesto'] ?? '')), 0, 80),
            'estado'       => 'activo',
            // Quien recibe una clave puesta por otra persona debe cambiarla.
            'debe_cambiar' => !empty($datos['debe_cambiar']) ? 1 : 0,
        ]);

        Bitacora::registrar('usuario_creado', 'usuario', $id, ['rol' => $rol]);
        return $id;
    }

    /**
     * Deja lista una cuenta administradora con ese correo, exista o no.
     *
     * La usa el instalador. Tiene que ser repetible: si el paso final falla por
     * cualquier motivo —permisos del archivo de configuración, por ejemplo— hay
     * que poder volver a pulsar «Terminar» sin toparse con «ya existe una cuenta
     * con ese correo» y sin quedar a medias.
     *
     * Recibe el hash y no la contraseña: quien llama ya la convirtió, para no
     * arrastrarla en claro entre pasos.
     */
    public static function asegurarAdministrador(string $correo, string $nombre, string $claveHash): int
    {
        $correo = mb_strtolower(trim($correo));
        $existente = self::porCorreo($correo);

        if ($existente === null) {
            $id = Bd::insertar('usuario', [
                'nombre'       => mb_substr(trim($nombre), 0, 160),
                'correo'       => $correo,
                'clave_hash'   => $claveHash,
                'rol'          => 'administrador',
                'puesto'       => 'Administración del evento',
                'estado'       => 'activo',
                'debe_cambiar' => 0,
            ]);
            Bitacora::registrar('usuario_creado', 'usuario', $id, ['rol' => 'administrador']);
            return $id;
        }

        // Ya existía: se le devuelve el acceso. Quien llega hasta aquí tuvo que
        // dar las credenciales de la base de datos, así que ya podía hacer esto
        // mismo por fuera.
        $id = (int) $existente['id'];
        Bd::ejecutar(
            "UPDATE {usuario}
                SET nombre = ?, clave_hash = ?, rol = 'administrador', estado = 'activo', debe_cambiar = 0
              WHERE id = ?",
            [mb_substr(trim($nombre), 0, 160), $claveHash, $id]
        );
        \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);
        Bitacora::registrar('usuario_restablecido', 'usuario', $id, ['rol' => 'administrador']);
        return $id;
    }

    public static function verificarClave(array $usuario, string $clave): bool
    {
        if (!Cripto::verificarClave($clave, (string) $usuario['clave_hash'])) {
            return false;
        }
        // Si el algoritmo cambió de parámetros, se rehashea al vuelo.
        //
        // Se compara contra el algoritmo que se usa de verdad. Comparando
        // contra PASSWORD_DEFAULT —que es bcrypt— un hash Argon2id parecía
        // desactualizado siempre, y cada inicio de sesión reescribía la
        // contraseña en la base sin ninguna necesidad.
        $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $opciones = defined('PASSWORD_ARGON2ID')
            ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]
            : ['cost' => 12];

        if (password_needs_rehash((string) $usuario['clave_hash'], $algoritmo, $opciones)) {
            Bd::ejecutar('UPDATE {usuario} SET clave_hash = ? WHERE id = ?', [
                Cripto::hashClave($clave),
                $usuario['id'],
            ]);
        }
        return true;
    }

    public static function cambiarClave(int $id, string $clave): void
    {
        Bd::ejecutar('UPDATE {usuario} SET clave_hash = ?, debe_cambiar = 0 WHERE id = ?', [
            Cripto::hashClave($clave),
            $id,
        ]);
        // Cambiar la contraseña cierra las demás sesiones: es lo que se espera
        // cuando alguien la cambia justamente porque cree que se la robaron.
        \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);
    }

    public static function registrarAcceso(int $id): void
    {
        Bd::ejecutar('UPDATE {usuario} SET ultimo_acceso = NOW() WHERE id = ?', [$id]);
    }

    public static function cambiarEstado(int $id, string $estado): void
    {
        $estado = $estado === 'suspendido' ? 'suspendido' : 'activo';
        Bd::ejecutar('UPDATE {usuario} SET estado = ? WHERE id = ?', [$estado, $id]);
        if ($estado === 'suspendido') {
            \App\Nucleo\Sesion::cerrarTodasDe('admin', $id);
        }
        Bitacora::registrar('usuario_estado', 'usuario', $id, ['estado' => $estado]);
    }

    /* =====================================================================
       Segundo factor
       ===================================================================== */

    public static function guardarSecretoTotp(int $id, string $secreto): void
    {
        Bd::ejecutar('UPDATE {usuario} SET totp_secreto = ?, totp_confirmado = 0 WHERE id = ?', [
            Cripto::cifrar($secreto),
            $id,
        ]);
    }

    public static function secretoTotp(array $usuario): string
    {
        if (empty($usuario['totp_secreto'])) {
            return '';
        }
        try {
            return Cripto::descifrar((string) $usuario['totp_secreto']);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function confirmarTotp(int $id): void
    {
        Bd::ejecutar('UPDATE {usuario} SET totp_confirmado = 1 WHERE id = ?', [$id]);
    }

    /**
     * ¿Esta cuenta necesita segundo factor?
     *
     * Obligatorio para administradores: son quienes pueden exportar datos
     * personales y cambiar la configuración. Para operador y consulta es
     * opcional, porque son cuentas que se usan a la carrera en la puerta y en
     * teléfonos prestados, donde exigir una aplicación de códigos frena más de
     * lo que protege.
     */
    public static function exigeSegundoFactor(array $usuario): bool
    {
        return $usuario['rol'] === 'administrador';
    }

    public static function tieneSegundoFactor(array $usuario): bool
    {
        return !empty($usuario['totp_secreto']) && (int) $usuario['totp_confirmado'] === 1;
    }
}
