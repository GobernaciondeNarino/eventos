<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Control de acceso a las rutas.
 *
 * Aquí se resuelve el requisito de que los códigos QR funcionen desde fuera de
 * la aplicación. Alguien apunta la cámara a un carnet o al pliego de la puerta:
 * el teléfono abre la URL sin ningún contexto previo. Si no hay sesión, esta
 * clase no responde «no autorizado» —que dejaría a la persona en un callejón
 * sin salida delante de la puerta del evento—, sino que envía al acceso que
 * corresponde recordando a dónde iba, y al terminar la lleva allí.
 *
 * Hay dos accesos distintos y por eso hay dos guardias:
 *   · «asistente»  → acceso por correo con código de un solo uso.
 *   · «admin»      → acceso del equipo, con contraseña y segundo factor.
 */
final class Guardia
{
    public static function exigir(?string $guardia, Peticion $peticion): void
    {
        if ($guardia === null || $guardia === '') {
            return;
        }

        [$tipo, $exigencia] = array_pad(explode(':', $guardia, 2), 2, null);

        match ($tipo) {
            'asistente' => self::asistente($peticion),
            'admin'     => self::admin($peticion, $exigencia),
            'instalar'  => self::instalador(),
            default     => null,
        };
    }

    /* =====================================================================
       Asistente
       ===================================================================== */

    private static function asistente(Peticion $peticion): void
    {
        if (self::personaActual() !== null) {
            return;
        }
        self::pedirIdentificacion($peticion, '/entrar');
    }

    /** Devuelve la persona con sesión abierta, o null. */
    public static function personaActual(): ?array
    {
        $sesion = Sesion::actual('asistente');
        if (!$sesion) {
            return null;
        }

        $persona = Bd::fila('SELECT * FROM {persona} WHERE id = ?', [$sesion['sujeto_id']]);
        if (!$persona) {
            // La persona ya no existe: la sesión sobra.
            Sesion::cerrar('asistente');
            return null;
        }
        return $persona;
    }

    /* =====================================================================
       Equipo organizador
       ===================================================================== */

    private static function admin(Peticion $peticion, ?string $rolExigido): void
    {
        $usuario = self::usuarioActual();

        if ($usuario === null) {
            self::pedirIdentificacion($peticion, '/admin/entrar');
        }

        // Con la contraseña verificada pero el segundo factor pendiente, la
        // sesión existe pero no sirve para nada más que terminar de entrar.
        $datos = Sesion::datos('admin');
        if (!empty($datos['pendiente_2fa'])) {
            Respuesta::redirigir('/admin/verificar');
        }

        if ($usuario['estado'] !== 'activo') {
            Sesion::cerrar('admin');
            Respuesta::error(403, 'Cuenta suspendida',
                'Tu cuenta está suspendida. Comunícate con la administración del evento.');
        }

        if ($rolExigido !== null && !self::tieneRol($usuario, $rolExigido)) {
            Bitacora::registrar('acceso_denegado', 'seguridad', (int) $usuario['id'], [
                'ruta' => $peticion->ruta(),
                'rol_exigido' => $rolExigido,
            ]);
            Respuesta::error(403, 'No tienes permiso',
                'Tu rol en la plataforma no incluye esta sección.');
        }
    }

    public static function usuarioActual(): ?array
    {
        $sesion = Sesion::actual('admin');
        if (!$sesion) {
            return null;
        }
        $usuario = Bd::fila('SELECT * FROM {usuario} WHERE id = ?', [$sesion['sujeto_id']]);
        if (!$usuario) {
            Sesion::cerrar('admin');
            return null;
        }
        return $usuario;
    }

    /**
     * Jerarquía de permisos.
     *
     * El administrador puede todo lo del operador y lo de consulta; el operador
     * puede lo de consulta. Se escribe explícito y no con números para que al
     * leer una ruta se entienda quién entra sin tener que buscar una tabla.
     */
    private const JERARQUIA = [
        'administrador' => ['administrador', 'operador', 'consulta'],
        'operador'      => ['operador', 'consulta'],
        'consulta'      => ['consulta'],
    ];

    public static function tieneRol(array $usuario, string $rolExigido): bool
    {
        $suyos = self::JERARQUIA[$usuario['rol']] ?? [];
        return in_array($rolExigido, $suyos, true);
    }

    /** Para las vistas: ¿le muestro este botón? */
    public static function puede(string $rol): bool
    {
        $usuario = self::usuarioActual();
        return $usuario !== null && self::tieneRol($usuario, $rol);
    }

    /* =====================================================================
       Instalador
       ===================================================================== */

    private static function instalador(): void
    {
        if (!Config::instalado()) {
            return;
        }
        // Ya instalado: el asistente se cierra solo. Reabrirlo exige tocar el
        // servidor —crear config/permitir-reinstalar—, que es justo la barrera
        // que se quiere: quien tenga acceso por FTP ya podía hacer daño igual,
        // pero nadie llega ahí desde el navegador.
        if (is_file(RAIZ . '/config/permitir-reinstalar')) {
            return;
        }
        // Marcada como instalada pero sin cuenta con la que entrar: cerrar el
        // asistente aquí deja la plataforma sin ninguna puerta. Se reabre en
        // modo reparación —sin la opción que borra datos— y se cierra sola en
        // cuanto exista una cuenta administradora. La barrera de verdad sigue
        // en el paso 2, que exige las credenciales de la base de datos.
        //
        // No se anota nada aquí: esta ruta la puede pedir cualquiera y la
        // bitácora se llenaría de ruido. Queda anotado en el paso 2, cuando
        // alguien demuestra tener esas credenciales.
        if (Instalacion::incompleta()) {
            return;
        }
        Respuesta::error(403, 'La plataforma ya está instalada',
            'El asistente de instalación se cierra al terminar. Para volver a ejecutarlo, '
            . 'crea el archivo config/permitir-reinstalar en el servidor.');
    }

    /* =====================================================================
       Redirección al acceso, conservando el destino
       ===================================================================== */

    private static function pedirIdentificacion(Peticion $peticion, string $rutaAcceso): never
    {
        // Solo tiene sentido volver a una pantalla que se pueda visitar; a un
        // POST no se regresa.
        $destino = $peticion->metodo() === 'GET' ? $peticion->ruta() : '/';
        $consulta = $_SERVER['QUERY_STRING'] ?? '';
        if ($destino !== '/' && $consulta !== '') {
            $destino .= '?' . $consulta;
        }

        if ($peticion->esAjax()) {
            Respuesta::json(['error' => 'sesion', 'ir' => Url::a($rutaAcceso)], 401);
        }

        Respuesta::redirigirAbsoluto(Url::a($rutaAcceso, $destino === '/' ? [] : ['destino' => $destino]));
    }
}
