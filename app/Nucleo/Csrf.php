<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Protección contra peticiones falsificadas.
 *
 * Cada formulario que cambia algo lleva un testigo. Sin esto, bastaría con que
 * un organizador con sesión abierta visitara una página cualquiera para que esa
 * página, desde el navegador de la persona, sellara ingresos o aprobara
 * propuestas sin que se entere.
 *
 * El testigo se guarda en una cookie propia y se compara con el campo del
 * formulario: el patrón de doble envío. Se eligió sobre guardarlo en la sesión
 * porque también hace falta en pantallas donde todavía no hay sesión —el
 * preregistro y el propio formulario de acceso— que son justo las que más
 * conviene proteger.
 */
final class Csrf
{
    private const COOKIE = 'evtic_csrf';
    public const CAMPO = '_testigo';

    private static string $token = '';

    public static function token(): string
    {
        if (self::$token !== '') {
            return self::$token;
        }

        $actual = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($actual) && preg_match('/^[a-f0-9]{64}$/', $actual)) {
            return self::$token = $actual;
        }

        $nuevo = bin2hex(random_bytes(32));
        $peticion = App::peticion();
        setcookie(self::COOKIE, $nuevo, [
            'expires'  => 0,
            'path'     => $peticion->base() === '' ? '/' : $peticion->base() . '/',
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $nuevo;
        return self::$token = $nuevo;
    }

    /** Campo oculto listo para poner dentro de un <form>. */
    public static function campo(): string
    {
        return '<input type="hidden" name="' . self::CAMPO . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function valido(Peticion $peticion): bool
    {
        $enviado = $peticion->campo(self::CAMPO);
        $esperado = $_COOKIE[self::COOKIE] ?? '';

        if ($enviado === '' || !is_string($esperado) || $esperado === '') {
            return false;
        }
        return hash_equals($esperado, $enviado);
    }

    /**
     * Comprueba y corta si no cuadra.
     *
     * Se responde 419 —«la sesión expiró»— en vez de 403 porque la causa más
     * común no es un ataque sino una pestaña que llevaba horas abierta.
     */
    public static function exigir(Peticion $peticion): void
    {
        if ($peticion->esPost() && !self::valido($peticion)) {
            Bitacora::registrar('csrf_rechazado', 'seguridad', null, [
                'ruta' => $peticion->ruta(),
            ]);
            Respuesta::error(419, 'La página estuvo demasiado tiempo abierta',
                'Por seguridad se descartó el envío. Vuelve a cargar la página e inténtalo de nuevo.');
        }
    }
}
