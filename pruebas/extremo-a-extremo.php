<?php
/**
 * Prueba de extremo a extremo contra un servidor real.
 *
 * Instala la plataforma desde cero por el asistente, y luego recorre lo que de
 * verdad va a pasar el día del evento: alguien se preregistra, recibe su
 * carnet, escanea el código de la jornada, un operador lo acredita, dos
 * asistentes intercambian contacto y el equipo exporta los registros.
 *
 * Se hace por HTTP y no llamando a las clases: así se comprueban también el
 * enrutado, las cookies, los testigos anti-falsificación y los guardias, que
 * es donde suelen estar los errores.
 *
 * Uso:
 *   php -S 127.0.0.1:8900 -t /tmp/web /tmp/web/router.php &
 *   php pruebas/extremo-a-extremo.php http://127.0.0.1:8900/cumbreAI
 */
declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8900/cumbreAI', '/');
$BD = [
    'nombre'  => getenv('BD_NOMBRE') ?: 'eventos_pruebas',
    'usuario' => getenv('BD_USUARIO') ?: 'eventos_app',
    'clave'   => getenv('BD_CLAVE') ?: 'clave_de_prueba_2026',
    'prefijo' => 'evt_',
];

$ok = 0;
$fallos = [];

function comprobar(string $nombre, bool $condicion, string $extra = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  ✓ $nombre\n";
    } else {
        $fallos[] = $nombre . ($extra ? " → $extra" : '');
        echo "  ✗ $nombre" . ($extra ? "  → $extra" : '') . "\n";
    }
}

function titulo(string $texto): void
{
    echo "\n$texto\n";
}

/* =========================================================================
   Cliente HTTP con cookies, uno por «persona» del guion
   ========================================================================= */
final class Cliente
{
    private array $cookies = [];
    public int $codigo = 0;
    public string $cuerpo = '';
    public array $cabeceras = [];

    private string $raiz;

    public function __construct(private string $base)
    {
        // Las redirecciones llegan como rutas absolutas que ya incluyen la
        // subcarpeta (/cumbreAI/instalar). Sin separar el origen de la base, al
        // seguirlas se duplicaría la subcarpeta.
        $partes = parse_url($base);
        $this->raiz = $partes['scheme'] . '://' . $partes['host']
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
    }

    private function urlDe(string $ruta): string
    {
        if (str_starts_with($ruta, 'http')) {
            return $ruta;
        }
        $rutaBase = parse_url($this->base, PHP_URL_PATH) ?: '';
        if ($rutaBase !== '' && str_starts_with($ruta, $rutaBase)) {
            return $this->raiz . $ruta;
        }
        return $this->base . $ruta;
    }

    public function get(string $ruta, bool $seguirRedireccion = true): string
    {
        return $this->pedir('GET', $ruta, null, $seguirRedireccion);
    }

    public function post(string $ruta, array $datos, bool $seguirRedireccion = true): string
    {
        // El testigo se toma de la cookie, igual que hace el navegador.
        if (!isset($datos['_testigo']) && isset($this->cookies['evtic_csrf'])) {
            $datos['_testigo'] = $this->cookies['evtic_csrf'];
        }
        return $this->pedir('POST', $ruta, $datos, $seguirRedireccion);
    }

    private function pedir(string $metodo, string $ruta, ?array $datos, bool $seguir, int $saltos = 0): string
    {
        $url = $this->urlDe($ruta);

        $opciones = [
            'http' => [
                'method'        => $metodo,
                'header'        => $this->cabeceraCookies(),
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout'       => 20,
            ],
        ];
        if ($datos !== null) {
            $cuerpo = http_build_query($datos);
            $opciones['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Content-Length: ' . strlen($cuerpo) . "\r\n";
            $opciones['http']['content'] = $cuerpo;
        }

        $contexto = stream_context_create($opciones);
        $this->cuerpo = (string) @file_get_contents($url, false, $contexto);
        $this->cabeceras = $http_response_header ?? [];
        $this->codigo = $this->codigoDe($this->cabeceras);
        $this->guardarCookies($this->cabeceras);

        if ($seguir && in_array($this->codigo, [301, 302, 303, 307, 308], true) && $saltos < 5) {
            $destino = $this->cabecera('Location');
            if ($destino !== '') {
                return $this->pedir('GET', $destino, null, true, $saltos + 1);
            }
        }
        return $this->cuerpo;
    }

    public function cabecera(string $nombre): string
    {
        foreach ($this->cabeceras as $linea) {
            if (stripos($linea, $nombre . ':') === 0) {
                return trim(substr($linea, strlen($nombre) + 1));
            }
        }
        return '';
    }

    private function codigoDe(array $cabeceras): int
    {
        foreach (array_reverse($cabeceras) as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    private function cabeceraCookies(): string
    {
        $partes = [];
        foreach ($this->cookies as $nombre => $valor) {
            if ($valor !== '') {
                $partes[] = "$nombre=$valor";
            }
        }
        $cabecera = "User-Agent: PruebaExtremoAExtremo/1.0\r\n";
        return $partes ? $cabecera . 'Cookie: ' . implode('; ', $partes) . "\r\n" : $cabecera;
    }

    private function guardarCookies(array $cabeceras): void
    {
        foreach ($cabeceras as $linea) {
            if (stripos($linea, 'Set-Cookie:') !== 0) {
                continue;
            }
            $trozo = trim(substr($linea, 11));
            [$par] = explode(';', $trozo, 2);
            if (!str_contains($par, '=')) {
                continue;
            }
            [$nombre, $valor] = explode('=', $par, 2);
            $this->cookies[trim($nombre)] = trim($valor);
        }
    }

    public function cookie(string $nombre): string
    {
        return $this->cookies[$nombre] ?? '';
    }
}

/* =========================================================================
   Utilidades
   ========================================================================= */

/** Extrae el token de un enlace /d/xxxx o /c/xxxx que aparezca en el HTML. */
function tokenDe(string $html, string $tipo): string
{
    return preg_match('#/(' . $tipo . ')/([a-f0-9]{32})#', $html, $m) ? $m[2] : '';
}

$RAIZ = dirname(__DIR__);

echo "Prueba de extremo a extremo · $BASE\n";
echo str_repeat('=', 62) . "\n";

/* =========================================================================
   0 · Punto de partida limpio
   ========================================================================= */
titulo('Preparación');
@unlink($RAIZ . '/config/config.php');
array_map('unlink', glob($RAIZ . '/almacen/registro/*.log.php') ?: []);

$pdo = new PDO(
    "mysql:host=localhost;dbname={$BD['nombre']};charset=utf8mb4",
    $BD['usuario'],
    $BD['clave'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
    $pdo->exec("DROP TABLE IF EXISTS `$tabla`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
comprobar('base de datos vacía', count($pdo->query('SHOW TABLES')->fetchAll()) === 0);

/* =========================================================================
   1 · Instalación por el asistente
   ========================================================================= */
titulo('Instalación');
$instalador = new Cliente($BASE);

$html = $instalador->get('/');
comprobar('sin instalar, todo lleva al asistente', str_contains($html, 'Comprobación del servidor'));

$html = $instalador->post('/instalar', ['accion' => 'paso1']);
comprobar('paso 1 → 2', str_contains($html, 'Conexión a la base de datos'));

$respuesta = $instalador->post('/instalar', [
    'accion' => 'probar_conexion',
    'bd_host' => 'localhost', 'bd_puerto' => '3306',
    'bd_nombre' => $BD['nombre'], 'bd_usuario' => $BD['usuario'],
    'bd_clave' => $BD['clave'], 'bd_prefijo' => $BD['prefijo'],
]);
$json = json_decode($respuesta, true);
comprobar('la prueba de conexión responde', ($json['ok'] ?? false) === true, (string) ($json['mensaje'] ?? $respuesta));

$html = $instalador->post('/instalar', [
    'accion' => 'paso2',
    'bd_host' => 'localhost', 'bd_puerto' => '3306',
    'bd_nombre' => $BD['nombre'], 'bd_usuario' => $BD['usuario'],
    'bd_clave' => $BD['clave'], 'bd_prefijo' => 'MAL PREFIJO',
]);
comprobar('rechaza un prefijo inválido', str_contains($html, 'prefijo debe empezar'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso2',
    'bd_host' => 'localhost', 'bd_puerto' => '3306',
    'bd_nombre' => $BD['nombre'], 'bd_usuario' => $BD['usuario'],
    'bd_clave' => $BD['clave'], 'bd_prefijo' => $BD['prefijo'],
]);
comprobar('paso 2 → 3', str_contains($html, 'Tablas de la aplicación'));
comprobar('detecta que la base está vacía', str_contains($html, 'No hay ninguna tabla'));

$html = $instalador->post('/instalar', ['accion' => 'paso3', 'modo' => 'limpio']);
comprobar('paso 3 → 4', str_contains($html, 'Cuenta administradora'));

$tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
comprobar('creó las 16 tablas del esquema', count($tablas) === 16, count($tablas) . ' encontradas');
comprobar('respetó el prefijo', str_starts_with((string) $tablas[0], $BD['prefijo']), (string) $tablas[0]);

$html = $instalador->post('/instalar', [
    'accion' => 'paso4', 'ad_nombre' => 'Andrea Lucía Erazo',
    'ad_correo' => 'aerazo@narino.gov.co', 'ad_clave' => 'corta', 'ad_clave2' => 'corta',
]);
comprobar('exige contraseña de 12 caracteres', str_contains($html, 'al menos 12 caracteres'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso4', 'ad_nombre' => 'Andrea Lucía Erazo',
    'ad_correo' => 'aerazo@narino.gov.co',
    'ad_clave' => 'una frase larga y facil de recordar',
    'ad_clave2' => 'otra distinta',
]);
comprobar('exige que las contraseñas coincidan', str_contains($html, 'deben coincidir'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso4', 'ad_nombre' => 'Andrea Lucía Erazo',
    'ad_correo' => 'aerazo@narino.gov.co',
    'ad_clave' => 'una frase larga y facil de recordar',
    'ad_clave2' => 'una frase larga y facil de recordar',
    'ad_2fa' => '',   // sin segundo factor, para poder seguir la prueba
]);
comprobar('paso 4 → 5', str_contains($html, 'Primer evento'));

$html = $instalador->post('/instalar', [
    'accion' => 'paso5',
    'ev_nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'ev_dependencia' => 'Secretaría TIC',
    'ev_sede' => 'Pasto',
    'ev_inicio' => date('Y-m-d'),
    'ev_dias' => '3',
    'preset' => 'tic-nocturno',
    'tipografia' => 'tecnologica',
]);
comprobar('paso 5 → 6, instalación terminada', str_contains($html, 'Instalación terminada'));
comprobar('escribió config/config.php', is_file($RAIZ . '/config/config.php'));

$config = require $RAIZ . '/config/config.php';
comprobar('guardó la llave de cifrado', strlen(base64_decode((string) $config['llave_cifrado'], true) ?: '') === 32);
comprobar('la contraseña de la base no quedó en la cookie de instalación',
    !str_contains(base64_decode($instalador->cookie('evtic_instalacion'), true) ?: '', $BD['clave']));

$jornadas = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}evento_dia")->fetchColumn();
comprobar('creó una jornada por día', $jornadas === 3, (string) $jornadas);

$html = $instalador->get('/instalar');
comprobar('el asistente se cierra tras instalar', str_contains($html, 'ya está instalada'));

/* =========================================================================
   2 · Preregistro de un asistente
   ========================================================================= */
titulo('Preregistro');
$maria = new Cliente($BASE);

$html = $maria->get('/');
comprobar('la portada ya no redirige al asistente', str_contains($html, 'Regístrate una vez'));
comprobar('muestra el nombre del evento', str_contains($html, 'Cumbre Tecnológica CIOS Nariño'));

$maria->get('/preregistro');
$html = $maria->post('/preregistro', [
    'correo' => 'mzambrano@narino.gov.co', 'nombre' => 'Mar',
    'tipo_documento' => 'CC', 'documento' => '12',
]);
comprobar('rechaza datos incompletos', str_contains($html, 'nombre completo'));
comprobar('exige la autorización de datos', str_contains($html, 'autorizar el tratamiento'));

$datosMaria = [
    'correo' => 'mzambrano@narino.gov.co',
    'nombre' => 'María Fernanda Zambrano',
    'tipo_documento' => 'CC', 'documento' => '1085234567',
    'telefono' => '+57 316 220 4471', 'rol' => 'participante',
    'entidad' => 'Gobernación de Nariño',
    'departamento' => 'Nariño', 'municipio' => 'Pasto',
    'genero' => 'F', 'rango_edad' => '26–35', 'etnia' => 'Ninguno', 'discapacidad' => 'No',
    'habeas' => '1',
];
$html = $maria->post('/preregistro', $datosMaria);
comprobar('el preregistro lleva al carnet', str_contains($html, 'Tu carnet digital'), substr(strip_tags($html), 0, 120));
comprobar('el carnet muestra el nombre', str_contains($html, 'María Fernanda Zambrano'));
comprobar('el carnet muestra el documento formateado', str_contains($html, '1.085.234.567'));

$fila = $pdo->query("SELECT * FROM {$BD['prefijo']}persona WHERE correo = 'mzambrano@narino.gov.co'")->fetch(PDO::FETCH_ASSOC);
comprobar('el documento se guardó cifrado', !str_contains((string) $fila['documento_cifrado'], '1085234567'));
comprobar('guardó la huella para buscar sin descifrar', strlen((string) $fila['documento_huella']) === 64);
comprobar('registró la autorización de datos', !empty($fila['autorizo_datos_en']));

$caract = $pdo->query("SELECT * FROM {$BD['prefijo']}persona_caracterizacion")->fetch(PDO::FETCH_ASSOC);
comprobar('la caracterización quedó en su tabla aparte', ($caract['genero'] ?? '') === 'F');

$tokenCarnet = tokenDe($html, 'c');
comprobar('el carnet trae un token en su QR', strlen($tokenCarnet) === 32, $tokenCarnet);
comprobar('el QR es un SVG generado en el servidor', str_contains($html, '<svg') && str_contains($html, 'shape-rendering'));
comprobar('el QR no contiene datos personales',
    !str_contains(substr($html, strpos($html, 'carnet__qrbox') ?: 0, 4000), '1085234567'));

/* =========================================================================
   3 · Un segundo asistente, para el intercambio de contactos
   ========================================================================= */
titulo('Segundo asistente');
$carlos = new Cliente($BASE);
$carlos->get('/preregistro');
$html = $carlos->post('/preregistro', [
    'correo' => 'cbolanos@tumaco.gov.co', 'nombre' => 'Carlos Andrés Bolaños',
    'tipo_documento' => 'CC', 'documento' => '12994510',
    'telefono' => '+57 315 908 3344', 'rol' => 'expositor',
    'entidad' => 'Alcaldía de Tumaco', 'departamento' => 'Nariño', 'municipio' => 'Tumaco',
    'expositor' => '1', 'tema' => 'Emprender TIC desde el Pacífico',
    'categoria' => 'Emprendimiento y startups TIC',
    'detalle' => 'Panel con cuatro emprendimientos del Pacífico nariñense y su acceso a capital.',
    'dia_preferido' => '2', 'duracion' => '40', 'requerimientos' => 'HDMI',
    'habeas' => '1',
]);
comprobar('el segundo preregistro funciona', str_contains($html, 'Carlos Andrés Bolaños'));

$propuestas = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}propuesta")->fetchColumn();
comprobar('guardó la propuesta de exposición', $propuestas === 1);

$otro = new Cliente($BASE);
$otro->get('/preregistro');
$html = $otro->post('/preregistro', [
    'correo' => 'otro@narino.gov.co', 'nombre' => 'Persona Distinta',
    'tipo_documento' => 'CC', 'documento' => '1085234567',   // documento de María
    'habeas' => '1',
]);
comprobar('rechaza un documento ya registrado con otro correo', str_contains($html, 'ya está registrado con otro correo'));

/* =========================================================================
   4 · El código QR de la jornada
   ========================================================================= */
titulo('Código QR de la jornada');
$token = (string) $pdo->query("SELECT token FROM {$BD['prefijo']}evento_dia WHERE numero = 1")->fetchColumn();

$anonimo = new Cliente($BASE);
$html = $anonimo->get("/d/$token", false);
$destino = $anonimo->cabecera('Location');
comprobar('sin sesión, el código del día pide identificarse',
    $anonimo->codigo === 303 && str_contains($destino, '/entrar'), $anonimo->codigo . ' ' . $destino);
comprobar('recuerda a dónde iba', str_contains(urldecode($destino), "/d/$token"));

$html = $maria->get("/d/$token");
comprobar('con sesión, registra el ingreso', str_contains($html, 'Ingreso registrado'));

$asistencias = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia")->fetchColumn();
comprobar('quedó una asistencia', $asistencias === 1);

$html = $maria->get("/d/$token");
comprobar('escanear dos veces no duplica', str_contains($html, 'Ya tenías el ingreso'));
comprobar('sigue habiendo una sola asistencia',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia")->fetchColumn() === 1);

$tokenDia2 = (string) $pdo->query("SELECT token FROM {$BD['prefijo']}evento_dia WHERE numero = 2")->fetchColumn();
$html = $maria->get("/d/$tokenDia2");
comprobar('el código de otro día no sirve hoy', str_contains($html, 'Todavía no se puede registrar'));

$html = $maria->get('/d/' . str_repeat('a', 32));
comprobar('un token inventado no revela nada', str_contains($html, 'ya no sirve'));

/* =========================================================================
   5 · El QR del carnet: intercambio de contacto
   ========================================================================= */
titulo('QR del carnet');
$html = $anonimo->get("/c/$tokenCarnet");
comprobar('sin sesión, pregunta quién eres', str_contains($html, '¿Cómo participas en el evento?'));
comprobar('no revela de quién es el carnet', !str_contains($html, 'María Fernanda'));

$html = $carlos->get("/c/$tokenCarnet");
comprobar('otro asistente ve el intercambio de contacto', str_contains($html, 'Intercambiar contacto'));
comprobar('muestra los cuatro datos compartidos', str_contains($html, 'mzambrano@narino.gov.co'));

$html = $carlos->post("/c/$tokenCarnet/contacto", []);
comprobar('el intercambio se guarda', str_contains($html, 'Contacto agregado'));
comprobar('el intercambio es recíproco',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}contacto")->fetchColumn() === 2);

$html = $maria->get('/contactos');
comprobar('María ve a Carlos en sus contactos', str_contains($html, 'Carlos Andrés Bolaños'));

$vcf = $maria->get('/contactos/exportar');
comprobar('exporta vCard', str_contains($vcf, 'BEGIN:VCARD') && str_contains($vcf, 'Carlos'));

/* =========================================================================
   6 · El equipo organizador
   ========================================================================= */
titulo('Equipo organizador');
$admin = new Cliente($BASE);
$admin->get('/admin/entrar');

$html = $admin->get('/admin', false);
comprobar('el panel exige identificarse',
    $admin->codigo === 303 && str_contains($admin->cabecera('Location'), '/admin/entrar'));

$html = $admin->post('/admin/entrar', ['correo' => 'aerazo@narino.gov.co', 'clave' => 'incorrecta']);
comprobar('rechaza la contraseña equivocada', str_contains($html, 'Correo o contraseña incorrectos'));

$html = $admin->post('/admin/entrar', [
    'correo' => 'aerazo@narino.gov.co',
    'clave' => 'una frase larga y facil de recordar',
]);
comprobar('entra con las credenciales correctas', str_contains($html, 'Panel del evento'));
comprobar('el panel cuenta los preregistrados', str_contains($html, 'Preregistrados'));

$html = $admin->get('/admin/registros');
comprobar('ve el listado de registros', str_contains($html, 'María Fernanda Zambrano'));
comprobar('muestra el documento descifrado', str_contains($html, '1.085.234.567'));

$csv = $admin->get('/admin/registros/exportar');
comprobar('exporta CSV', str_contains($csv, 'María Fernanda Zambrano') && str_contains($csv, ';'));
comprobar('el CSV no trae caracterización por defecto', !str_contains($csv, 'Género'));

$csv = $admin->get('/admin/registros/exportar?caracterizacion=1');
comprobar('el administrador sí puede exportar la caracterización', str_contains($csv, 'Género'));

$html = $admin->get('/admin/expositores');
comprobar('ve la propuesta pendiente', str_contains($html, 'Emprender TIC desde el Pacífico'));

$idPropuesta = (int) $pdo->query("SELECT id FROM {$BD['prefijo']}propuesta LIMIT 1")->fetchColumn();
$html = $admin->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idPropuesta, 'decision' => 'observada', 'observacion' => '',
]);
comprobar('devolver exige escribir la observación', str_contains($html, 'Escribe la observación'));

$html = $admin->post('/admin/expositores/decidir', [
    'propuesta' => (string) $idPropuesta, 'decision' => 'aprobada',
    'dia' => '2', 'hora' => '15:00', 'salon' => 'Auditorio principal',
]);
comprobar('aprobar publica en la agenda', str_contains($html, 'publicada en la agenda'));

$html = $anonimo->get('/agenda?dia=2');
comprobar('la agenda pública muestra la charla aprobada', str_contains($html, 'Emprender TIC desde el Pacífico'));

/* =========================================================================
   7 · Acreditación por el operador
   ========================================================================= */
titulo('Acreditación');
$html = $admin->get("/c/$tokenCarnet");
comprobar('el equipo ve la ficha de acreditación', str_contains($html, 'Carnet reconocido'));
comprobar('la ficha muestra el documento', str_contains($html, '1.085.234.567'));
comprobar('avisa que ya tiene ingreso de hoy', str_contains($html, 'Ya tiene ingreso del día'));

$tokenCarlos = (string) $pdo->query(
    "SELECT c.token FROM {$BD['prefijo']}credencial c
       JOIN {$BD['prefijo']}persona p ON p.id = c.persona_id
      WHERE p.correo = 'cbolanos@tumaco.gov.co'"
)->fetchColumn();

$html = $admin->post("/c/$tokenCarlos/asistencia", ['jornada' => '1']);
comprobar('el operador sella el ingreso', str_contains($html, 'Ingreso de Carlos Andrés Bolaños'));
comprobar('quedan dos asistencias',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia")->fetchColumn() === 2);
comprobar('la asistencia queda atribuida al operador',
    (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}asistencia WHERE operador_id IS NOT NULL")->fetchColumn() === 1);

/* =========================================================================
   8 · Identidad del evento
   ========================================================================= */
titulo('Identidad del evento');
$html = $admin->get('/admin/identidad');
comprobar('la pantalla de identidad carga', str_contains($html, 'Identidad del evento'));
comprobar('muestra la revisión de contraste', str_contains($html, 'Revisión de contraste'));

$html = $admin->post('/admin/identidad', [
    'nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'dependencia' => 'Secretaría TIC', 'sede' => 'Pasto',
    'preset' => 'narino-verde', 'tipografia' => 'neutra',
]);
comprobar('guarda la identidad', str_contains($html, 'Identidad guardada'));

$html = $anonimo->get('/');
comprobar('el color nuevo llega a la parte pública', str_contains($html, '#7CF7C8'));
comprobar('la tipografía nueva también', str_contains($html, 'data-tipografia="neutra"'));

$html = $admin->post('/admin/identidad', [
    'nombre' => 'Cumbre Tecnológica CIOS Nariño',
    'preset' => 'narino-verde', 'tipografia' => 'neutra',
    'color_accent' => 'javascript:alert(1)',
]);
$html = $anonimo->get('/');
comprobar('un color inválido no entra en el CSS', !str_contains($html, 'javascript'));

/* =========================================================================
   9 · Seguridad
   ========================================================================= */
titulo('Seguridad');
$html = $anonimo->get('/admin/registros', false);
comprobar('un anónimo no ve los registros', $anonimo->codigo === 303);

$html = $maria->get('/admin/registros', false);
comprobar('un asistente tampoco entra al backoffice',
    $maria->codigo === 303 && str_contains($maria->cabecera('Location'), '/admin/entrar'));

$sinTestigo = new Cliente($BASE);
$sinTestigo->get('/preregistro');
$html = $sinTestigo->post('/preregistro', ['_testigo' => 'falso', 'correo' => 'x@y.co', 'nombre' => 'Prueba XSS']);
comprobar('un envío con testigo falso se rechaza', str_contains($html, 'demasiado tiempo abierta'));

foreach (['/app/Nucleo/Bd.php', '/config/config.php', '/almacen/registro/', '/pruebas/flujos.js'] as $ruta) {
    $c = new Cliente($BASE);
    $c->get($ruta, false);
    comprobar("no se sirve $ruta", $c->codigo === 404 || $c->codigo === 403, (string) $c->codigo);
}

$c = new Cliente($BASE);
$c->get('/');
$cabeceras = implode("\n", $c->cabeceras);
comprobar('envía Content-Security-Policy', str_contains($cabeceras, 'Content-Security-Policy'));
comprobar('envía X-Frame-Options', str_contains($cabeceras, 'X-Frame-Options: DENY'));
comprobar('envía X-Content-Type-Options', str_contains($cabeceras, 'nosniff'));
comprobar('las cookies son HttpOnly', str_contains($cabeceras, 'HttpOnly'));
comprobar('las cookies llevan SameSite', str_contains($cabeceras, 'SameSite=Lax'));

// Reflejo de un intento de inyección en un campo de texto
$xss = new Cliente($BASE);
$xss->get('/preregistro');
$html = $xss->post('/preregistro', [
    'correo' => 'xss@prueba.co', 'nombre' => '<script>alert(1)</script>Nombre',
    'tipo_documento' => 'CC', 'documento' => '99887766', 'habeas' => '1',
    'entidad' => '"><img src=x onerror=alert(1)>',
]);
comprobar('el nombre con etiquetas se escapa', !str_contains($html, '<script>alert(1)</script>'));
// La cadena «onerror=alert(1)» sí aparece, pero como texto: lo que importa es
// que no llegue a formarse una etiqueta ni a cerrarse el atributo.
comprobar('la etiqueta inyectada no se forma', !str_contains($html, '<img src=x'));
comprobar('el valor se muestra escapado', str_contains($html, '&lt;img') || str_contains($html, '&quot;&gt;'));

// Redirección abierta
$c = new Cliente($BASE);
$c->get('/entrar?destino=https://sitio-falso.example/roba', false);
$html = $c->get('/entrar?destino=https://sitio-falso.example/roba');
comprobar('no acepta un destino externo', !str_contains($html, 'sitio-falso.example'));

// Bitácora
$entradas = (int) $pdo->query("SELECT COUNT(*) FROM {$BD['prefijo']}bitacora")->fetchColumn();
comprobar('la bitácora registró la actividad', $entradas > 5, (string) $entradas);
$conClave = (int) $pdo->query(
    "SELECT COUNT(*) FROM {$BD['prefijo']}bitacora WHERE detalle LIKE '%frase larga%'"
)->fetchColumn();
comprobar('la bitácora no guarda contraseñas', $conClave === 0);

$sesiones = $pdo->query("SELECT id FROM {$BD['prefijo']}sesion LIMIT 1")->fetchColumn();
comprobar('la cookie de sesión no es el identificador guardado',
    $sesiones && $maria->cookie('evtic_asis') !== '' && $sesiones !== $maria->cookie('evtic_asis'));

/* =========================================================================
   10 · Cierre de sesión
   ========================================================================= */
titulo('Cierre de sesión');
$maria->post('/salir', []);
$maria->get('/carnet', false);
comprobar('tras salir, el carnet vuelve a pedir acceso', $maria->codigo === 303);

$admin->post('/admin/salir', []);
$admin->get('/admin', false);
comprobar('tras salir, el panel vuelve a pedir acceso', $admin->codigo === 303);

/* =========================================================================
   Resultado
   ========================================================================= */
echo "\n" . str_repeat('─', 62) . "\n";
printf("%d comprobaciones correctas · %d fallidas\n", $ok, count($fallos));
foreach ($fallos as $f) {
    echo "  ✗ $f\n";
}

$errores = glob($RAIZ . '/almacen/registro/*.log.php') ?: [];
if ($errores) {
    $contenido = (string) file_get_contents($errores[0]);
    $conteo = substr_count($contenido, 'ERROR');
    if ($conteo > 0) {
        echo "\nErrores registrados por la aplicación: $conteo\n";
        foreach (array_slice(array_filter(explode("\n", $contenido), static fn($l) => str_contains($l, 'ERROR')), 0, 5) as $linea) {
            echo '  ! ' . mb_substr($linea, 0, 160) . "\n";
        }
    }
}

exit($fallos ? 1 : 0);
