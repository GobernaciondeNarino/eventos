<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * La petición HTTP entrante.
 *
 * Lo importante de esta clase es la detección de la ruta base. La plataforma
 * tiene que funcionar igual colgando de la raíz de un dominio
 * (https://eventos.narino.gov.co/) que de una subcarpeta
 * (https://tic.narino.gov.co/cumbreAI/), sin tocar un solo archivo de
 * configuración. Todo lo demás del sistema construye sus URLs a partir de lo
 * que aquí se calcula.
 */
final class Peticion
{
    private string $metodo;
    private string $ruta;
    private string $base;
    private array $consulta;
    private array $cuerpo;
    private array $archivos;

    public function __construct()
    {
        $this->metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // dirname('/cumbreAI/index.php') = '/cumbreAI'
        // dirname('/index.php')          = '/'   → se normaliza a ''
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $base = str_replace('\\', '/', dirname($script));
        $this->base = ($base === '/' || $base === '.') ? '' : rtrim($base, '/');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (($corte = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $corte);
        }
        $uri = rawurldecode($uri);

        // Se descuenta la subcarpeta para quedarse con la ruta de la aplicación.
        if ($this->base !== '' && str_starts_with($uri, $this->base)) {
            $uri = substr($uri, strlen($this->base));
        }

        $this->ruta = '/' . trim($uri, '/');
        $this->consulta = $_GET;
        $this->cuerpo = $_POST;
        $this->archivos = $_FILES;
    }

    public function metodo(): string { return $this->metodo; }
    public function ruta(): string { return $this->ruta; }
    public function base(): string { return $this->base; }

    public function esPost(): bool { return $this->metodo === 'POST'; }

    /** Valor de la cadena de consulta, siempre como texto ya recortado. */
    public function query(string $clave, string $porDefecto = ''): string
    {
        $v = $this->consulta[$clave] ?? $porDefecto;
        return is_string($v) ? trim($v) : $porDefecto;
    }

    /** Campo del formulario. Nunca devuelve arreglos: eso evita sorpresas al validar. */
    public function campo(string $clave, string $porDefecto = ''): string
    {
        $v = $this->cuerpo[$clave] ?? $porDefecto;
        return is_string($v) ? trim($v) : $porDefecto;
    }

    public function campoCrudo(string $clave): string
    {
        $v = $this->cuerpo[$clave] ?? '';
        return is_string($v) ? $v : '';
    }

    public function marcado(string $clave): bool
    {
        return isset($this->cuerpo[$clave]) && $this->cuerpo[$clave] !== '';
    }

    public function entero(string $clave, int $porDefecto = 0): int
    {
        $v = $this->cuerpo[$clave] ?? $this->consulta[$clave] ?? null;
        return is_numeric($v) ? (int) $v : $porDefecto;
    }

    public function archivo(string $clave): ?array
    {
        $a = $this->archivos[$clave] ?? null;
        if (!is_array($a) || ($a['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $a;
    }

    public function esAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    /**
     * Dirección del cliente.
     *
     * En Plesk suele haber un nginx por delante de Apache, así que la IP real
     * llega en X-Forwarded-For. Solo se hace caso a esa cabecera cuando la
     * conexión viene de un proxy declarado en la configuración: de otro modo
     * cualquiera podría falsear su origen y saltarse el límite de intentos.
     */
    public function ip(): string
    {
        $remota = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $confiables = Config::obtener('proxies_confiables', []);

        if ($confiables && in_array($remota, $confiables, true)) {
            $reenviada = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            foreach (explode(',', $reenviada) as $candidata) {
                $candidata = trim($candidata);
                if (filter_var($candidata, FILTER_VALIDATE_IP)) {
                    return $candidata;
                }
            }
        }
        return filter_var($remota, FILTER_VALIDATE_IP) ? $remota : '0.0.0.0';
    }

    public function agente(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /** ¿La petición llegó por HTTPS? Contempla el proxy de Plesk. */
    public function esSegura(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $confiables = Config::obtener('proxies_confiables', []);
        if ($confiables && in_array($_SERVER['REMOTE_ADDR'] ?? '', $confiables, true)) {
            return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    /** Esquema y host, para armar los enlaces absolutos que van en los correos. */
    public function origen(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // El host llega del cliente: se limita a lo que puede ser un nombre válido.
        $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host) ?: 'localhost';
        return ($this->esSegura() ? 'https://' : 'http://') . $host;
    }
}
