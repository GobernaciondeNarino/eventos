<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Códigos de un solo uso basados en tiempo (RFC 6238).
 *
 * Compatible con Google Authenticator, Authy, FreeOTP y el gestor de
 * contraseñas que ya use la entidad. Son treinta líneas de código y evitan
 * depender de un SMS, que cuesta dinero y no llega en las zonas del
 * departamento donde justamente se hacen estos eventos.
 */
final class Totp
{
    private const DIGITOS = 6;
    private const PERIODO = 30;
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo en base32, el formato que leen las aplicaciones. */
    public static function generarSecreto(int $bytes = 20): string
    {
        return self::base32Codificar(random_bytes($bytes));
    }

    /**
     * URI otpauth:// para el código QR de alta.
     * El emisor y la cuenta son lo que la aplicación muestra en su listado.
     */
    public static function uri(string $secreto, string $cuenta, string $emisor): string
    {
        return 'otpauth://totp/' . rawurlencode($emisor) . ':' . rawurlencode($cuenta)
            . '?secret=' . $secreto
            . '&issuer=' . rawurlencode($emisor)
            . '&algorithm=SHA1&digits=' . self::DIGITOS . '&period=' . self::PERIODO;
    }

    public static function codigoActual(string $secreto, ?int $momento = null): string
    {
        return self::codigo($secreto, intdiv($momento ?? time(), self::PERIODO));
    }

    /**
     * Verifica el código admitiendo una ventana de tolerancia.
     *
     * ±1 intervalo, es decir hasta treinta segundos de desfase entre el reloj
     * del teléfono y el del servidor. Sin esa tolerancia, un servidor con la
     * hora ligeramente corrida rechaza códigos correctos y nadie entiende por qué.
     */
    public static function verificar(string $secreto, string $codigo, int $ventana = 1): bool
    {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($codigo) !== self::DIGITOS) {
            return false;
        }

        $contador = intdiv(time(), self::PERIODO);
        for ($desvio = -$ventana; $desvio <= $ventana; $desvio++) {
            if (hash_equals(self::codigo($secreto, $contador + $desvio), $codigo)) {
                return true;
            }
        }
        return false;
    }

    private static function codigo(string $secreto, int $contador): string
    {
        $llave = self::base32Decodificar($secreto);
        if ($llave === '') {
            return '';
        }

        $binario = pack('J', $contador);              // 64 bits, extremo grande
        $hash = hash_hmac('sha1', $binario, $llave, true);

        // Truncamiento dinámico del RFC 4226.
        $desplazamiento = ord($hash[19]) & 0x0F;
        $valor = ((ord($hash[$desplazamiento]) & 0x7F) << 24)
            | (ord($hash[$desplazamiento + 1]) << 16)
            | (ord($hash[$desplazamiento + 2]) << 8)
            | ord($hash[$desplazamiento + 3]);

        return str_pad((string) ($valor % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private static function base32Codificar(string $datos): string
    {
        $bits = '';
        foreach (str_split($datos) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $trozo) {
            $salida .= self::ALFABETO[bindec(str_pad($trozo, 5, '0', STR_PAD_RIGHT))];
        }
        return $salida;
    }

    private static function base32Decodificar(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $texto) ?? '');
        if ($texto === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($texto) as $caracter) {
            $indice = strpos(self::ALFABETO, $caracter);
            if ($indice === false) {
                return '';
            }
            $bits .= str_pad(decbin($indice), 5, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 8) as $trozo) {
            if (strlen($trozo) === 8) {
                $salida .= chr(bindec($trozo));
            }
        }
        return $salida;
    }

    /** Agrupa el secreto de cuatro en cuatro, para poder dictarlo o teclearlo. */
    public static function formatear(string $secreto): string
    {
        return trim(chunk_split($secreto, 4, ' '));
    }
}
