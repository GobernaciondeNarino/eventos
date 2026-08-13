<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Generador de códigos QR (ISO/IEC 18004), sin dependencias.
 *
 * Es el mismo algoritmo de assets/js/qr.js, portado a PHP. Hace falta en el
 * servidor por dos razones: el correo con el carnet lleva el código incrustado
 * —y ahí no hay JavaScript que valga— y las hojas para imprimir se generan
 * completas del lado del servidor.
 *
 * Ambas versiones se comparan matriz a matriz en pruebas/qr-php-contra-js.php,
 * y la de JavaScript ya está verificada contra una librería de referencia.
 *
 * Modo byte (UTF-8), versiones 1 a 20, los cuatro niveles de corrección.
 */
final class Qr
{
    private const NIVELES = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];
    private const BITS_FORMATO = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];
    private const VERSION_MAXIMA = 20;

    /** Palabras de corrección por bloque, por nivel y versión 1..20. */
    private const ECC_POR_BLOQUE = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28],
    ];

    /** Cantidad de bloques, por nivel y versión 1..20. */
    private const BLOQUES = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25],
    ];

    private array $modulos = [];
    private array $reservados = [];
    private int $tamano = 0;

    private function __construct(
        private readonly int $version,
        private readonly string $nivel,
        private readonly int $mascara
    ) {
        $this->tamano = $version * 4 + 17;
    }

    /* =====================================================================
       API
       ===================================================================== */

    /**
     * Matriz de módulos: arreglo de filas de booleanos.
     * $mascaraForzada solo se usa en las pruebas de comparación.
     */
    public static function matriz(string $texto, string $nivel = 'M', ?int $mascaraForzada = null): array
    {
        return self::construir($texto, $nivel, $mascaraForzada)['modulos'];
    }

    public static function construir(string $texto, string $nivel = 'M', ?int $mascaraForzada = null): array
    {
        $nivel = strtoupper($nivel);
        if (!isset(self::NIVELES[$nivel])) {
            $nivel = 'M';
        }
        $indice = self::NIVELES[$nivel];
        $bytes = array_values(unpack('C*', $texto) ?: []);

        $version = 1;
        while ($version <= self::VERSION_MAXIMA) {
            $capacidad = self::palabrasDeDatos($version, $indice) * 8;
            $necesario = 4 + ($version < 10 ? 8 : 16) + count($bytes) * 8;
            if ($necesario <= $capacidad) {
                break;
            }
            $version++;
        }
        if ($version > self::VERSION_MAXIMA) {
            throw new \RangeException(
                'El contenido excede la capacidad de un QR versión 20 nivel ' . $nivel . '.'
            );
        }

        $palabras = self::intercalar(self::palabras($bytes, $version, $indice), $version, $indice);

        // Se prueban las ocho máscaras y se conserva la de menor penalización.
        $mejor = null;
        $mejorPuntos = PHP_INT_MAX;
        $mascaras = $mascaraForzada !== null ? [$mascaraForzada] : range(0, 7);

        foreach ($mascaras as $m) {
            $qr = new self($version, $nivel, $m);
            $qr->dibujarPatrones();
            $qr->dibujarFormato();
            $qr->dibujarDatos($palabras);
            $qr->aplicarMascara();
            $puntos = $qr->penalizacion();
            if ($puntos < $mejorPuntos) {
                $mejorPuntos = $puntos;
                $mejor = $qr;
            }
        }

        return [
            'modulos' => $mejor->modulos,
            'tamano'  => $mejor->tamano,
            'version' => $version,
            'mascara' => $mejor->mascara,
            'nivel'   => $nivel,
        ];
    }

    /**
     * SVG listo para incrustar.
     *
     * SVG y no PNG a propósito: se imprime nítido a cualquier tamaño —el pliego
     * de la puerta se amplía a media hoja— y pesa menos que el PNG equivalente.
     */
    public static function svg(string $texto, array $opciones = []): string
    {
        $silencio = $opciones['silencio'] ?? 4;
        $oscuro = $opciones['oscuro'] ?? '#000000';
        $claro = $opciones['claro'] ?? '#FFFFFF';
        $codigo = self::construir($texto, $opciones['nivel'] ?? 'M');

        $lado = $codigo['tamano'] + $silencio * 2;
        $trazo = '';
        for ($y = 0; $y < $codigo['tamano']; $y++) {
            for ($x = 0; $x < $codigo['tamano']; $x++) {
                if ($codigo['modulos'][$y][$x]) {
                    $trazo .= 'M' . ($x + $silencio) . ' ' . ($y + $silencio) . 'h1v1h-1z';
                }
            }
        }

        $etiqueta = isset($opciones['titulo'])
            ? ' role="img" aria-label="' . htmlspecialchars((string) $opciones['titulo'], ENT_QUOTES) . '"'
            : ' aria-hidden="true"';
        $clase = isset($opciones['clase'])
            ? ' class="' . htmlspecialchars((string) $opciones['clase'], ENT_QUOTES) . '"'
            : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '"'
            . ' shape-rendering="crispEdges"' . $etiqueta . $clase . '>'
            . '<rect width="' . $lado . '" height="' . $lado . '" fill="' . $claro . '"/>'
            . '<path fill="' . $oscuro . '" d="' . $trazo . '"/></svg>';
    }

    /** PNG, para los clientes de correo que no pintan SVG. */
    public static function png(string $texto, int $escala = 8, array $opciones = []): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('Falta la extensión gd para generar el PNG.');
        }
        $silencio = $opciones['silencio'] ?? 4;
        $codigo = self::construir($texto, $opciones['nivel'] ?? 'M');
        $lado = ($codigo['tamano'] + $silencio * 2) * $escala;

        $imagen = imagecreatetruecolor($lado, $lado);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        $negro = imagecolorallocate($imagen, 0, 0, 0);
        imagefilledrectangle($imagen, 0, 0, $lado, $lado, $blanco);

        for ($y = 0; $y < $codigo['tamano']; $y++) {
            for ($x = 0; $x < $codigo['tamano']; $x++) {
                if ($codigo['modulos'][$y][$x]) {
                    imagefilledrectangle(
                        $imagen,
                        ($x + $silencio) * $escala,
                        ($y + $silencio) * $escala,
                        ($x + $silencio + 1) * $escala - 1,
                        ($y + $silencio + 1) * $escala - 1,
                        $negro
                    );
                }
            }
        }

        ob_start();
        imagepng($imagen, null, 9);
        imagedestroy($imagen);
        return (string) ob_get_clean();
    }

    /* =====================================================================
       Capacidades
       ===================================================================== */

    private static function modulosCrudos(int $version): int
    {
        $resultado = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $alineacion = intdiv($version, 7) + 2;
            $resultado -= (25 * $alineacion - 10) * $alineacion - 55;
            if ($version >= 7) {
                $resultado -= 36;
            }
        }
        return $resultado;
    }

    private static function palabrasDeDatos(int $version, int $nivel): int
    {
        return intdiv(self::modulosCrudos($version), 8)
            - self::ECC_POR_BLOQUE[$nivel][$version] * self::BLOQUES[$nivel][$version];
    }

    private static function posicionesAlineacion(int $version): array
    {
        if ($version === 1) {
            return [];
        }
        $cuantos = intdiv($version, 7) + 2;
        $paso = (int) (ceil(($version * 4 + 4) / ($cuantos * 2 - 2)) * 2);
        $resultado = [6];
        for ($p = $version * 4 + 17 - 7; count($resultado) < $cuantos; $p -= $paso) {
            array_splice($resultado, 1, 0, [$p]);
        }
        return $resultado;
    }

    /* =====================================================================
       GF(256) y Reed-Solomon
       ===================================================================== */

    private static function multiplicar(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private static function divisor(int $grado): array
    {
        $resultado = array_fill(0, $grado - 1, 0);
        $resultado[] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grado; $i++) {
            for ($j = 0; $j < count($resultado); $j++) {
                $resultado[$j] = self::multiplicar($resultado[$j], $raiz);
                if ($j + 1 < count($resultado)) {
                    $resultado[$j] ^= $resultado[$j + 1];
                }
            }
            $raiz = self::multiplicar($raiz, 0x02);
        }
        return $resultado;
    }

    private static function residuo(array $datos, array $divisor): array
    {
        $resultado = array_fill(0, count($divisor), 0);
        foreach ($datos as $byte) {
            $factor = $byte ^ array_shift($resultado);
            $resultado[] = 0;
            foreach ($divisor as $i => $d) {
                $resultado[$i] ^= self::multiplicar($d, $factor);
            }
        }
        return $resultado;
    }

    /* =====================================================================
       Codificación
       ===================================================================== */

    private static function palabras(array $bytes, int $version, int $nivel): array
    {
        $bits = [];
        $agregar = static function (int $valor, int $largo) use (&$bits): void {
            for ($i = $largo - 1; $i >= 0; $i--) {
                $bits[] = ($valor >> $i) & 1;
            }
        };

        $agregar(4, 4);                                   // modo byte
        $agregar(count($bytes), $version < 10 ? 8 : 16);  // contador
        foreach ($bytes as $b) {
            $agregar($b, 8);
        }

        $capacidad = self::palabrasDeDatos($version, $nivel) * 8;
        $agregar(0, min(4, $capacidad - count($bits)));   // terminador
        $agregar(0, (8 - count($bits) % 8) % 8);          // ajuste a byte

        $relleno = 0xEC;
        while (count($bits) < $capacidad) {
            $agregar($relleno, 8);
            $relleno ^= 0xEC ^ 0x11;
        }

        $datos = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $datos[] = $byte;
        }
        return $datos;
    }

    private static function intercalar(array $datos, int $version, int $nivel): array
    {
        $cuantos = self::BLOQUES[$nivel][$version];
        $largoEcc = self::ECC_POR_BLOQUE[$nivel][$version];
        $crudas = intdiv(self::modulosCrudos($version), 8);
        $cortos = $cuantos - $crudas % $cuantos;
        $largoCorto = intdiv($crudas, $cuantos);

        $bloques = [];
        $divisor = self::divisor($largoEcc);
        $k = 0;
        for ($i = 0; $i < $cuantos; $i++) {
            $largo = $largoCorto - $largoEcc + ($i < $cortos ? 0 : 1);
            $trozo = array_slice($datos, $k, $largo);
            $k += $largo;
            $ecc = self::residuo($trozo, $divisor);
            // Relleno en los bloques cortos para que todos queden alineados;
            // ese byte se salta al intercalar y no viaja en el código.
            if ($i < $cortos) {
                $trozo[] = 0;
            }
            $bloques[] = array_merge($trozo, $ecc);
        }

        $resultado = [];
        $total = count($bloques[0]);
        for ($i = 0; $i < $total; $i++) {
            foreach ($bloques as $j => $bloque) {
                if ($i !== $largoCorto - $largoEcc || $j >= $cortos) {
                    $resultado[] = $bloque[$i];
                }
            }
        }
        return $resultado;
    }

    /* =====================================================================
       Trazado
       ===================================================================== */

    private function poner(int $x, int $y, bool $oscuro, bool $reservar = false): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->tamano || $y >= $this->tamano) {
            return;
        }
        $this->modulos[$y][$x] = $oscuro;
        if ($reservar) {
            $this->reservados[$y][$x] = true;
        }
    }

    private function dibujarPatrones(): void
    {
        $n = $this->tamano;
        $this->modulos = array_fill(0, $n, array_fill(0, $n, false));
        $this->reservados = array_fill(0, $n, array_fill(0, $n, false));

        // Patrones de tiempo
        for ($i = 0; $i < $n; $i++) {
            $this->poner(6, $i, $i % 2 === 0, true);
            $this->poner($i, 6, $i % 2 === 0, true);
        }

        // Patrones de búsqueda con su separador
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $distancia = max(abs($dx), abs($dy));
                    $this->poner($cx + $dx, $cy + $dy, $distancia !== 2 && $distancia !== 4, true);
                }
            }
        }

        // Patrones de alineación
        $posiciones = self::posicionesAlineacion($this->version);
        $cuantos = count($posiciones);
        for ($i = 0; $i < $cuantos; $i++) {
            for ($j = 0; $j < $cuantos; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $cuantos - 1) || ($i === $cuantos - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->poner($posiciones[$j] + $dx, $posiciones[$i] + $dy, max(abs($dx), abs($dy)) !== 1, true);
                    }
                }
            }
        }

        // Reserva de la información de formato. El índice 6 se salta: ahí pasa
        // el patrón de tiempo y el formato no lo ocupa.
        for ($i = 0; $i <= 8; $i++) {
            if ($i === 6) {
                continue;
            }
            $this->poner($i, 8, false, true);
            $this->poner(8, $i, false, true);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->poner($n - 1 - $i, 8, false, true);
            $this->poner(8, $n - 1 - $i, false, true);
        }
        $this->poner(8, $n - 8, true, true);

        // Información de versión (7 en adelante)
        if ($this->version >= 7) {
            $resto = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $resto = ($resto << 1) ^ (($resto >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $resto;
            for ($i = 0; $i < 18; $i++) {
                $color = (($bits >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->poner($a, $b, $color, true);
                $this->poner($b, $a, $color, true);
            }
        }
    }

    private function dibujarFormato(): void
    {
        $datos = (self::BITS_FORMATO[$this->nivel] << 3) | $this->mascara;
        $resto = $datos;
        for ($i = 0; $i < 10; $i++) {
            $resto = ($resto << 1) ^ (($resto >> 9) * 0x537);
        }
        $bits = (($datos << 10) | $resto) ^ 0x5412;
        $n = $this->tamano;

        for ($i = 0; $i <= 5; $i++) {
            $this->poner(8, $i, (($bits >> $i) & 1) === 1, true);
        }
        $this->poner(8, 7, (($bits >> 6) & 1) === 1, true);
        $this->poner(8, 8, (($bits >> 7) & 1) === 1, true);
        $this->poner(7, 8, (($bits >> 8) & 1) === 1, true);
        for ($i = 9; $i < 15; $i++) {
            $this->poner(14 - $i, 8, (($bits >> $i) & 1) === 1, true);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->poner($n - 1 - $i, 8, (($bits >> $i) & 1) === 1, true);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->poner(8, $n - 15 + $i, (($bits >> $i) & 1) === 1, true);
        }
        $this->poner(8, $n - 8, true, true);
    }

    private function dibujarDatos(array $palabras): void
    {
        $n = $this->tamano;
        $i = 0;
        $total = count($palabras) * 8;

        for ($derecha = $n - 1; $derecha >= 1; $derecha -= 2) {
            if ($derecha === 6) {
                $derecha = 5;
            }
            for ($vertical = 0; $vertical < $n; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $derecha - $j;
                    $haciaArriba = (($derecha + 1) & 2) === 0;
                    $y = $haciaArriba ? $n - 1 - $vertical : $vertical;
                    if (!$this->reservados[$y][$x] && $i < $total) {
                        $this->modulos[$y][$x] = (($palabras[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function aplicarMascara(): void
    {
        for ($y = 0; $y < $this->tamano; $y++) {
            for ($x = 0; $x < $this->tamano; $x++) {
                if ($this->reservados[$y][$x]) {
                    continue;
                }
                $invertir = match ($this->mascara) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => false,
                };
                if ($invertir) {
                    $this->modulos[$y][$x] = !$this->modulos[$y][$x];
                }
            }
        }
    }

    /* =====================================================================
       Penalización (N1=3, N2=3, N3=40, N4=10)
       ===================================================================== */

    private function penalizacion(): int
    {
        $n = $this->tamano;
        $total = 0;

        // Reglas 1 y 3, por filas
        for ($y = 0; $y < $n; $y++) {
            $color = false;
            $racha = 0;
            $historial = [0, 0, 0, 0, 0, 0, 0];
            for ($x = 0; $x < $n; $x++) {
                if ($this->modulos[$y][$x] === $color) {
                    $racha++;
                    if ($racha === 5) {
                        $total += 3;
                    } elseif ($racha > 5) {
                        $total++;
                    }
                } else {
                    $this->agregarHistorial($historial, $racha);
                    if (!$color) {
                        $total += $this->contarBuscadores($historial) * 40;
                    }
                    $color = $this->modulos[$y][$x];
                    $racha = 1;
                }
            }
            $total += $this->cerrarHistorial($color, $racha, $historial) * 40;
        }

        // Reglas 1 y 3, por columnas
        for ($x = 0; $x < $n; $x++) {
            $color = false;
            $racha = 0;
            $historial = [0, 0, 0, 0, 0, 0, 0];
            for ($y = 0; $y < $n; $y++) {
                if ($this->modulos[$y][$x] === $color) {
                    $racha++;
                    if ($racha === 5) {
                        $total += 3;
                    } elseif ($racha > 5) {
                        $total++;
                    }
                } else {
                    $this->agregarHistorial($historial, $racha);
                    if (!$color) {
                        $total += $this->contarBuscadores($historial) * 40;
                    }
                    $color = $this->modulos[$y][$x];
                    $racha = 1;
                }
            }
            $total += $this->cerrarHistorial($color, $racha, $historial) * 40;
        }

        // Regla 2: bloques 2×2 del mismo color
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modulos[$y][$x];
                if ($c === $this->modulos[$y][$x + 1]
                    && $c === $this->modulos[$y + 1][$x]
                    && $c === $this->modulos[$y + 1][$x + 1]) {
                    $total += 3;
                }
            }
        }

        // Regla 4: desbalance claro/oscuro
        $oscuros = 0;
        for ($y = 0; $y < $n; $y++) {
            foreach ($this->modulos[$y] as $m) {
                if ($m) {
                    $oscuros++;
                }
            }
        }
        $celdas = $n * $n;
        $k = (int) ceil(abs($oscuros * 20 - $celdas * 10) / $celdas) - 1;
        return $total + $k * 10;
    }

    private function agregarHistorial(array &$historial, int $racha): void
    {
        if ($historial[0] === 0) {
            $racha += $this->tamano;   // borde claro virtual al inicio
        }
        array_pop($historial);
        array_unshift($historial, $racha);
    }

    private function contarBuscadores(array $h): int
    {
        $n = $h[1];
        $nucleo = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;
        return ($nucleo && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0)
            + ($nucleo && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0);
    }

    private function cerrarHistorial(bool $color, int $racha, array $historial): int
    {
        if ($color) {
            $this->agregarHistorial($historial, $racha);
            $racha = 0;
        }
        $racha += $this->tamano;      // borde claro virtual al final
        $this->agregarHistorial($historial, $racha);
        return $this->contarBuscadores($historial);
    }
}
