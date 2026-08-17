<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * La fotografía del carnet.
 *
 * Es la única subida que hace un visitante sin cuenta del equipo, así que es la
 * que más cuidado pide. Las reglas son las mismas que ya rigen para el logo del
 * evento (ver docs/SEGURIDAD.md) y aquí se aplican sin excepción:
 *
 *   · el tipo lo decide el contenido real del archivo, nunca su extensión ni lo
 *     que declare el navegador;
 *   · la imagen se vuelve a generar entera, lo que descarta cualquier carga útil
 *     escondida en los metadatos EXIF o detrás de la cabecera;
 *   · no se admite SVG. Para un logo tiene sentido; para una foto de una cara no
 *     hay ningún motivo, y un SVG es un documento capaz de ejecutar guiones;
 *   · el nombre del archivo lo pone el servidor;
 *   · el archivo se guarda fuera de la raíz web y solo se sirve a través de PHP.
 *
 * Además se recorta cuadrada y se reduce a 480 px: un carnet no necesita más, y
 * así una foto de 12 megapíxeles no ocupa doce megas en el disco del servidor.
 */
final class Imagen
{
    /** 6 MB. Una foto de teléfono cabe de sobra; un vídeo disfrazado, no. */
    private const PESO_MAXIMO = 6 * 1024 * 1024;

    /** Lado del cuadrado final, en píxeles. */
    private const LADO = 480;

    private const TIPOS = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * Recibe $_FILES['foto'] y devuelve [nombreArchivo, tipoMime].
     *
     * @throws \DomainException con un texto que se le puede enseñar a la persona
     */
    public static function guardarFoto(array $archivo, int $personaId): array
    {
        self::validarSubida($archivo);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $tipoReal = (string) $finfo->file($archivo['tmp_name']);
        if (!isset(self::TIPOS[$tipoReal])) {
            throw new \DomainException(
                'Esa foto no está en un formato que podamos usar. Sirven JPG, PNG y WEBP.'
            );
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new \DomainException(
                'Este servidor no tiene la extensión GD de PHP, que es la que procesa las '
                . 'imágenes. Pídele al área de sistemas que la active.'
            );
        }

        $original = @imagecreatefromstring((string) file_get_contents($archivo['tmp_name']));
        if ($original === false) {
            throw new \DomainException('La imagen está dañada o no se pudo leer. Prueba con otra.');
        }

        try {
            $cuadrada = self::recortarCuadrado($original, $archivo['tmp_name'], $tipoReal);
        } finally {
            imagedestroy($original);
        }

        $directorio = RAIZ . '/almacen/fotos';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        // El nombre lo pone el servidor y lleva azar: sin él, saber el id de una
        // persona bastaría para adivinar la ruta de su foto.
        $nombre = 'p' . $personaId . '-' . bin2hex(random_bytes(8)) . '.jpg';
        $destino = $directorio . '/' . $nombre;

        // Siempre JPEG a la salida, sea cual sea la entrada: un solo formato que
        // servir, y ninguna posibilidad de que sobreviva un trozo del original.
        $escrita = imagejpeg($cuadrada, $destino, 82);
        imagedestroy($cuadrada);

        if (!$escrita) {
            throw new \DomainException(
                'No se pudo guardar la foto en el servidor. Puede ser un problema de permisos '
                . 'en la carpeta almacen/fotos.'
            );
        }
        @chmod($destino, 0640);

        return [$nombre, 'image/jpeg'];
    }

    /** Borra la foto de una persona del disco. */
    public static function borrarFoto(string $nombre): void
    {
        if ($nombre === '') {
            return;
        }
        // basename() corta cualquier intento de salir del directorio, aunque el
        // nombre lo haya puesto el servidor.
        @unlink(RAIZ . '/almacen/fotos/' . basename($nombre));
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    private static function validarSubida(array $archivo): void
    {
        $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \DomainException(
                'La foto supera el tamaño máximo que admite el servidor ('
                . ini_get('upload_max_filesize') . '). Hazle una foto más pequeña o recórtala.'
            );
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \DomainException('No se pudo recibir la foto. Inténtalo de nuevo.');
        }
        if ((int) ($archivo['size'] ?? 0) > self::PESO_MAXIMO) {
            throw new \DomainException('La foto pesa más de 6 MB. Reduce el tamaño e inténtalo de nuevo.');
        }
        if (!is_uploaded_file((string) $archivo['tmp_name'])) {
            throw new \DomainException('El archivo no llegó por una subida válida.');
        }
    }

    /**
     * Recorta el centro y reduce a un cuadrado de 480 px.
     *
     * Se respeta la orientación EXIF antes de recortar. Sin eso, las fotos
     * hechas con el teléfono en vertical salen acostadas: el sensor graba
     * siempre igual y la orientación real va en una etiqueta que se pierde al
     * regenerar la imagen, que es justo lo que hacemos aquí a propósito.
     *
     * @param \GdImage $original
     * @return \GdImage
     */
    private static function recortarCuadrado(\GdImage $original, string $ruta, string $tipo): \GdImage
    {
        $imagen = self::enderezar($original, $ruta, $tipo);

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lado = min($ancho, $alto);

        // El centro horizontal, y algo por encima del centro vertical: en un
        // retrato la cara está en el tercio superior, y un recorte al centro
        // exacto la corta por la frente.
        $x = (int) round(($ancho - $lado) / 2);
        $y = (int) round(($alto - $lado) * 0.35);

        $destino = imagecreatetruecolor(self::LADO, self::LADO);

        // Fondo blanco: si la fuente es un PNG con transparencia, sin esto el
        // JPEG resultante sale con el fondo negro.
        $blanco = imagecolorallocate($destino, 255, 255, 255);
        imagefilledrectangle($destino, 0, 0, self::LADO, self::LADO, $blanco);

        imagecopyresampled($destino, $imagen, 0, 0, $x, $y, self::LADO, self::LADO, $lado, $lado);

        if ($imagen !== $original) {
            imagedestroy($imagen);
        }
        return $destino;
    }

    /**
     * Aplica la orientación EXIF, si la hay y si la extensión está disponible.
     *
     * @param \GdImage $imagen
     * @return \GdImage el mismo recurso si no había nada que girar
     */
    private static function enderezar(\GdImage $imagen, string $ruta, string $tipo): \GdImage
    {
        if ($tipo !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $imagen;
        }

        // exif_read_data() avisa con un warning ante metadatos rotos, y el
        // manejador de errores de la plataforma convierte los warnings en
        // excepciones: una foto con EXIF mal formado tumbaría el preregistro.
        try {
            $exif = @exif_read_data($ruta);
        } catch (\Throwable $e) {
            return $imagen;
        }

        $orientacion = (int) ($exif['Orientation'] ?? 1);
        $girada = match ($orientacion) {
            3 => imagerotate($imagen, 180, 0),
            6 => imagerotate($imagen, -90, 0),
            8 => imagerotate($imagen, 90, 0),
            default => false,
        };

        return $girada === false ? $imagen : $girada;
    }
}
