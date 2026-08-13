<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Cifrado y derivados.
 *
 * El número de documento se guarda cifrado porque es el dato que más duele
 * perder en una fuga: identifica a la persona ante cualquier otra entidad. Pero
 * también hay que poder detectar registros duplicados sin descifrar toda la
 * tabla, así que junto al valor cifrado se guarda un HMAC con clave, que es
 * determinista y permite buscar por igualdad sin revelar el contenido.
 *
 * Se usa XChaCha20-Poly1305 de libsodium cuando está disponible —cifrado
 * autenticado, con nonce de 24 bytes— y AES-256-GCM de OpenSSL como respaldo.
 */
final class Cripto
{
    private const VERSION_SODIUM = "\x01";
    private const VERSION_OPENSSL = "\x02";

    private static ?string $llave = null;

    /** Genera la llave maestra. Se llama una sola vez, en la instalación. */
    public static function generarLlave(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function llave(): string
    {
        if (self::$llave !== null) {
            return self::$llave;
        }
        $codificada = (string) Config::obtener('llave_cifrado', '');
        $cruda = base64_decode($codificada, true);
        if ($cruda === false || strlen($cruda) !== 32) {
            throw new \RuntimeException(
                'La llave de cifrado no es válida. Sin ella no se pueden leer los documentos guardados.'
            );
        }
        return self::$llave = $cruda;
    }

    public static function hayLlave(): bool
    {
        $cruda = base64_decode((string) Config::obtener('llave_cifrado', ''), true);
        return $cruda !== false && strlen($cruda) === 32;
    }

    public static function cifrar(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        $llave = self::llave();

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cifrado = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($texto, $nonce, $nonce, $llave);
            return self::VERSION_SODIUM . $nonce . $cifrado;
        }

        $nonce = random_bytes(12);
        $etiqueta = '';
        $cifrado = openssl_encrypt($texto, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $nonce, $etiqueta);
        if ($cifrado === false) {
            throw new \RuntimeException('No se pudo cifrar el dato.');
        }
        return self::VERSION_OPENSSL . $nonce . $etiqueta . $cifrado;
    }

    public static function descifrar(string $paquete): string
    {
        if ($paquete === '') {
            return '';
        }
        $llave = self::llave();
        $version = $paquete[0];
        $resto = substr($paquete, 1);

        if ($version === self::VERSION_SODIUM) {
            $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $nonce = substr($resto, 0, $n);
            $cifrado = substr($resto, $n);
            $claro = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cifrado, $nonce, $nonce, $llave);
            if ($claro === false) {
                throw new \RuntimeException('El dato cifrado no se pudo verificar.');
            }
            return $claro;
        }

        if ($version === self::VERSION_OPENSSL) {
            $nonce = substr($resto, 0, 12);
            $etiqueta = substr($resto, 12, 16);
            $cifrado = substr($resto, 28);
            $claro = openssl_decrypt($cifrado, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $nonce, $etiqueta);
            if ($claro === false) {
                throw new \RuntimeException('El dato cifrado no se pudo verificar.');
            }
            return $claro;
        }

        throw new \RuntimeException('Formato de dato cifrado desconocido.');
    }

    /**
     * Huella determinista para buscar sin descifrar.
     *
     * Es un HMAC y no un hash simple: sin la llave, quien obtenga la tabla no
     * puede probar cédulas por fuerza bruta, que con SHA-256 a secas sería
     * cuestión de minutos por el espacio tan pequeño de los documentos.
     */
    public static function huella(string $valor): string
    {
        return hash_hmac('sha256', $valor, self::llave());
    }

    /** Token opaco para URLs: 128 bits en hexadecimal. */
    public static function token(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Código numérico de un solo uso, para el acceso por correo. */
    public static function codigoNumerico(int $digitos = 6): string
    {
        $maximo = (10 ** $digitos) - 1;
        return str_pad((string) random_int(0, $maximo), $digitos, '0', STR_PAD_LEFT);
    }

    public static function hashClave(string $clave): string
    {
        // Argon2id si está compilado; si no, bcrypt, que sigue siendo aceptable.
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($clave, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }
        return password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verificarClave(string $clave, string $hash): bool
    {
        return password_verify($clave, $hash);
    }

    /** Comparación en tiempo constante, para tokens que llegan del cliente. */
    public static function iguales(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
