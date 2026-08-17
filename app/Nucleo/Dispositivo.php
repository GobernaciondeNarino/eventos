<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * El teléfono desde el que ya entró una vez.
 *
 * Un asistente se preregistra en la fila de la entrada, con el teléfono en la
 * mano, y a los tres días vuelve a la aplicación desde el mismo teléfono. Que
 * le vuelvan a pedir el correo y esperar un código es exactamente el momento en
 * que la gente abandona. La sesión dura treinta días, pero se pierde con solo
 * borrar los datos del navegador, con el modo de ahorro de algunos teléfonos, o
 * al abrir el enlace desde otra aplicación —el correo, WhatsApp— que usa su
 * propio almacén de cookies.
 *
 * Esto es la segunda cookie: dura seis meses, no abre el panel de nadie del
 * equipo (solo asistentes) y su único poder es volver a abrir la sesión de su
 * dueño.
 *
 * Formato del valor: «selector.validador».
 *   · el selector busca la fila (es un índice, no un secreto);
 *   · del validador se guarda solo el SHA-256, y se compara en tiempo constante.
 * Quien lea la tabla no obtiene cookies utilizables, igual que con las sesiones.
 *
 * No se rota el validador en cada uso a propósito. La rotación detecta el robo
 * de la cookie, pero en un teléfono con varias pestañas —o con la precarga del
 * navegador— dos peticiones simultáneas usan el mismo valor y la segunda
 * quedaría inválida: la persona se encuentra fuera sin haber hecho nada. A
 * cambio se guarda el agente y la fecha de último uso, y el dueño puede cerrar
 * todos sus dispositivos desde su carnet.
 */
final class Dispositivo
{
    private const COOKIE = 'evtic_disp';

    /** Seis meses: más que cualquier evento, y se renueva con cada visita. */
    private const VIDA = 15552000;

    /** Solo se intenta una vez por petición, pase lo que pase. */
    private static ?int $restaurada = null;
    private static bool $intentada = false;

    /* =====================================================================
       Lectura de la cookie
       ===================================================================== */

    /** @return array{0:string,1:string}|null [selector, validador] */
    private static function partes(): ?array
    {
        $valor = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($valor) || !preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $valor, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    private static function ponerCookie(string $valor, int $expira): void
    {
        $peticion = App::peticion();
        $ruta = $peticion->base() === '' ? '/' : $peticion->base() . '/';

        setcookie(self::COOKIE, $valor, [
            'expires'  => $expira,
            'path'     => $ruta,
            'secure'   => $peticion->esSegura(),
            'httponly' => true,
            // Igual que la sesión del asistente: se llega desde el lector de QR
            // del teléfono, que cuenta como navegación externa. Con Strict la
            // cookie no viajaría justo en el caso para el que existe.
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $valor;
    }

    /* =====================================================================
       Recordar
       ===================================================================== */

    /**
     * Deja constancia de que esta persona entró desde este dispositivo.
     *
     * Se llama al abrir cualquier sesión de asistente. Si el dispositivo ya
     * estaba recordado para la misma persona, se refresca la fila en vez de
     * crear otra: si no, cada visita dejaría una fila más.
     */
    public static function recordar(int $personaId): void
    {
        if (!self::utilizable()) {
            return;
        }

        $peticion = App::peticion();
        $expira = time() + self::VIDA;

        $partes = self::partes();
        if ($partes !== null) {
            [$selector, $validador] = $partes;
            $fila = self::fila($selector);
            if ($fila !== null
                && (int) $fila['persona_id'] === $personaId
                && hash_equals((string) $fila['validador_hash'], hash('sha256', $validador))) {
                Bd::ejecutar(
                    'UPDATE {dispositivo} SET ultimo_uso = NOW(), expira_en = ? WHERE selector = ?',
                    [date('Y-m-d H:i:s', $expira), $selector]
                );
                self::ponerCookie($selector . '.' . $validador, $expira);
                return;
            }
        }

        $selector = bin2hex(random_bytes(16));
        $validador = bin2hex(random_bytes(32));

        try {
            Bd::insertar('dispositivo', [
                'selector'       => $selector,
                'persona_id'     => $personaId,
                'validador_hash' => hash('sha256', $validador),
                'agente'         => $peticion->agente(),
                'ip'             => @inet_pton($peticion->ip()) ?: null,
                'ultimo_uso'     => date('Y-m-d H:i:s'),
                'expira_en'      => date('Y-m-d H:i:s', $expira),
            ]);
        } catch (\Throwable $e) {
            // Recordar el dispositivo es una comodidad. Si la tabla todavía no
            // existe —instalación sin actualizar el esquema—, la entrada tiene
            // que funcionar igual.
            Registro::error('No se pudo recordar el dispositivo', ['detalle' => $e->getMessage()]);
            return;
        }

        self::ponerCookie($selector . '.' . $validador, $expira);
    }

    /* =====================================================================
       Restaurar
       ===================================================================== */

    /**
     * Si la cookie es válida, vuelve a abrir la sesión y devuelve la persona.
     *
     * Devuelve el id, no la fila: quien llama ya sabe cómo leer la persona y
     * así esta clase no depende de la forma de esa tabla.
     */
    public static function restaurar(): ?int
    {
        if (self::$intentada) {
            return self::$restaurada;
        }
        self::$intentada = true;

        if (!self::utilizable()) {
            return null;
        }

        $partes = self::partes();
        if ($partes === null) {
            return null;
        }
        [$selector, $validador] = $partes;

        $fila = self::fila($selector);
        if ($fila === null) {
            // La cookie apunta a una fila que ya no existe: sobra.
            self::olvidar();
            return null;
        }

        if (!hash_equals((string) $fila['validador_hash'], hash('sha256', $validador))) {
            // Selector correcto y validador equivocado no pasa por accidente:
            // o alguien probó suerte, o la cookie viajó a otro sitio. Se borra
            // la fila entera, que es lo que corta el uso de una cookie robada.
            Bd::ejecutar('DELETE FROM {dispositivo} WHERE selector = ?', [$selector]);
            self::olvidar();
            Bitacora::registrar('dispositivo_invalido', 'seguridad', (int) $fila['persona_id']);
            return null;
        }

        $persona = Bd::fila('SELECT id FROM {persona} WHERE id = ?', [$fila['persona_id']]);
        if (!$persona) {
            Bd::ejecutar('DELETE FROM {dispositivo} WHERE selector = ?', [$selector]);
            self::olvidar();
            return null;
        }

        $personaId = (int) $persona['id'];
        Sesion::abrir('asistente', $personaId, ['via' => 'dispositivo']);
        Bitacora::registrar('acceso_asistente', 'persona', $personaId, ['via' => 'dispositivo']);

        return self::$restaurada = $personaId;
    }

    /* =====================================================================
       Olvidar
       ===================================================================== */

    /** Borra la marca de este dispositivo. Se llama al cerrar sesión. */
    public static function olvidar(): void
    {
        $partes = self::partes();
        if ($partes !== null && self::utilizable()) {
            try {
                Bd::ejecutar('DELETE FROM {dispositivo} WHERE selector = ?', [$partes[0]]);
            } catch (\Throwable $e) {
                Registro::error('No se pudo olvidar el dispositivo', ['detalle' => $e->getMessage()]);
            }
        }
        self::ponerCookie('', time() - 3600);
        unset($_COOKIE[self::COOKIE]);
        self::$restaurada = null;
        self::$intentada = true;
    }

    /** Todos los dispositivos de una persona. Para «salir de todas partes». */
    public static function olvidarTodos(int $personaId): int
    {
        $cuantos = Bd::ejecutar('DELETE FROM {dispositivo} WHERE persona_id = ?', [$personaId])->rowCount();
        self::ponerCookie('', time() - 3600);
        unset($_COOKIE[self::COOKIE]);
        self::$restaurada = null;
        self::$intentada = true;
        return $cuantos;
    }

    /** Los que siguen vivos, para enseñárselos a su dueño. */
    public static function de(int $personaId): array
    {
        try {
            return Bd::filas(
                'SELECT * FROM {dispositivo} WHERE persona_id = ? AND expira_en > NOW() ORDER BY ultimo_uso DESC',
                [$personaId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Limpieza de caducados. La llama Sesion::limpiar(). */
    public static function podar(): void
    {
        try {
            Bd::ejecutar('DELETE FROM {dispositivo} WHERE expira_en < NOW()');
        } catch (\Throwable $e) {
            // La tabla puede no existir en una instalación sin actualizar.
        }
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    private static function fila(string $selector): ?array
    {
        try {
            return Bd::fila(
                'SELECT * FROM {dispositivo} WHERE selector = ? AND expira_en > NOW()',
                [$selector]
            ) ?: null;
        } catch (\Throwable $e) {
            Registro::error('No se pudo consultar el dispositivo recordado', [
                'detalle' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * ¿Tiene sentido siquiera intentarlo?
     *
     * Sin base de datos configurada no hay nada que consultar, y dejar que la
     * excepción suba convertiría cada página pública en un 500 —el mismo fallo
     * que ya se corrigió en Sesion::actual().
     */
    private static function utilizable(): bool
    {
        return Config::instalado() && (string) Config::obtener('bd_nombre', '') !== '';
    }
}
