<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Mensajes de una sola lectura, entre una redirección y la siguiente página.
 *
 * Van en una cookie corta en vez de en la sesión porque hacen falta también
 * antes de que exista sesión: al preregistrarse, al pedir un código de acceso o
 * al fallar el ingreso. El contenido no es sensible —son frases de interfaz—,
 * pero igual se firma para que nadie inyecte texto arbitrario en la pantalla de
 * otra persona por medio de un enlace.
 */
final class Aviso
{
    private const COOKIE = 'evtic_aviso';

    public static function poner(string $mensaje, string $tipo = 'ok'): void
    {
        $carga = json_encode([
            'm' => mb_substr($mensaje, 0, 300),
            't' => in_array($tipo, ['ok', 'warn', 'danger'], true) ? $tipo : 'ok',
        ], JSON_UNESCAPED_UNICODE);

        $firma = hash_hmac('sha256', (string) $carga, self::llave());
        $valor = base64_encode($firma . '|' . $carga);

        $peticion = App::peticion();
        setcookie(self::COOKIE, $valor, [
            'expires'  => time() + 120,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Devuelve ['mensaje','tipo'] o null, y lo consume. */
    public static function tomar(): ?array
    {
        $crudo = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($crudo) || $crudo === '') {
            return null;
        }
        self::borrar();

        $decodificado = base64_decode($crudo, true);
        if ($decodificado === false || !str_contains($decodificado, '|')) {
            return null;
        }

        [$firma, $carga] = explode('|', $decodificado, 2);
        if (!hash_equals(hash_hmac('sha256', $carga, self::llave()), $firma)) {
            return null;
        }

        $datos = json_decode($carga, true);
        if (!is_array($datos) || !isset($datos['m'])) {
            return null;
        }
        return ['mensaje' => (string) $datos['m'], 'tipo' => (string) ($datos['t'] ?? 'ok')];
    }

    private static function borrar(): void
    {
        $peticion = App::peticion();
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }

    private static function llave(): string
    {
        return (string) Config::obtener('llave_cifrado', 'sin-instalar');
    }
}
