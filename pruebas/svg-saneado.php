<?php
/**
 * El saneado de los logos SVG.
 *
 * Un SVG es un documento XML que puede llevar JavaScript dentro. La plataforma
 * lo sirve con una política que ya impide que se ejecute —ver
 * Respuesta::archivo()—, pero el archivo se limpia además al guardarlo, para no
 * depender de una sola barrera. Esto comprueba esa segunda barrera.
 *
 * Se llama al método real por reflexión y no a una copia: una copia se queda
 * desactualizada y da una seguridad falsa.
 *
 * Uso:  php pruebas/svg-saneado.php
 */
declare(strict_types=1);

define('EVENTOS_TIC', true);
define('RAIZ', dirname(__DIR__));
define('APP_VERSION', 'pruebas');

spl_autoload_register(static function (string $clase): void {
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = RAIZ . '/app/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
require RAIZ . '/app/ayudas.php';

$metodo = new ReflectionMethod(App\Controladores\Admin::class, 'limpiarSvg');
$metodo->setAccessible(true);
$admin = new App\Controladores\Admin();
$limpiar = static fn(string $svg): string => (string) $metodo->invoke($admin, $svg);

/* ------------------------------------------------------------------------
   Lo que tiene que desaparecer
   ------------------------------------------------------------------------ */
$peligrosos = [
    // El truco que rompía la versión de una sola pasada: al quitar la etiqueta
    // interior, los dos trozos de fuera se juntan y reconstruyen <script>.
    'reconstrucción por anidado' => '<svg><scr<script>ipt>alert(1)</script>ipt>alert(1)</svg>',
    'script simple'              => '<svg><script>alert(1)</script></svg>',
    'script en mayúsculas'       => '<svg><SCRIPT>alert(1)</SCRIPT></svg>',
    'script sin cerrar'          => '<svg><script src="x.js"/></svg>',
    'onload sin comillas'        => '<svg onload=alert(1)><rect/></svg>',
    'onload con comillas'        => '<svg onload="alert(1)"><rect/></svg>',
    'onload con salto de línea'  => "<svg onload\n=\"alert(1)\"><rect/></svg>",
    'onerror en una imagen'      => '<svg><image onerror="alert(1)"/></svg>',
    'referencia externa'         => '<svg><image href="https://ajeno.example/x.png"/></svg>',
    'javascript: en un enlace'   => '<svg><a xlink:href="javascript:alert(1)">x</a></svg>',
    'entidad externa (XXE)'      => '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>',
    'foreignObject con HTML'     => '<svg><foreignObject><body onload="alert(1)"/></foreignObject></svg>',
    'animate hacia javascript'   => '<svg><animate attributeName="href" values="javascript:alert(1)"/></svg>',
];

/* ------------------------------------------------------------------------
   Lo que tiene que sobrevivir: un logo de verdad no puede quedar roto
   ------------------------------------------------------------------------ */
$legitimos = [
    'trazado con relleno' => ['<svg viewBox="0 0 24 24"><path d="M2 2h20v20H2z" fill="#0af"/></svg>',
                              ['<path', 'fill="#0af"', 'viewBox']],
    'estilos internos'    => ['<svg><style>.a{fill:#e10600}</style><circle class="a" r="8"/></svg>',
                              ['<style', 'fill:#e10600', '<circle']],
    'referencia interna'  => ['<svg><defs><g id="i"><rect/></g></defs><use href="#i"/></svg>',
                              ['<use href="#i"', '<defs>']],
    'texto y grupos'      => ['<svg><g transform="translate(4,4)"><text x="0" y="10">TIC</text></g></svg>',
                              ['<text', 'translate(4,4)', 'TIC']],
];

$ok = 0;
$fallos = [];

echo "Saneado de logos SVG\n" . str_repeat('=', 52) . "\n\n";
echo "Se elimina\n";

$rastros = '#<script|\son[a-z]+\s*=|javascript:|<!ENTITY|<!DOCTYPE|ajeno\.example|<foreignObject#i';
foreach ($peligrosos as $nombre => $entrada) {
    $salida = $limpiar($entrada);
    if (preg_match($rastros, $salida)) {
        $fallos[] = $nombre;
        echo "  ✗ $nombre  → " . mb_strimwidth($salida, 0, 60, '…') . "\n";
    } else {
        $ok++;
        echo "  ✓ $nombre\n";
    }
}

echo "\nSe conserva\n";
foreach ($legitimos as $nombre => [$entrada, $trozos]) {
    $salida = $limpiar($entrada);
    $faltan = array_values(array_filter($trozos, static fn($t) => !str_contains($salida, $t)));
    if ($faltan) {
        $fallos[] = $nombre;
        echo "  ✗ $nombre  → se perdió " . implode(', ', $faltan) . "\n";
    } else {
        $ok++;
        echo "  ✓ $nombre\n";
    }
}

echo "\n" . str_repeat('─', 52) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
exit($fallos ? 1 : 0);
