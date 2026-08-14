<?php
/**
 * Diagnóstico de la instalación.
 * @var array $estado @var array $servidor @var array $archivos @var array $errores
 * @var bool $publico
 */
defined('EVENTOS_TIC') || exit;

$publico = $publico ?? true;

use App\Esquema;

$icono = static fn(string $e): string => $e === 'ok' ? '✓' : ($e === 'warn' ? '▲' : '✕');

$fila = static function (string $nombre, string $detalle, string $valor, string $estado) use ($icono): void {
    echo '<div class="check check--' . e($estado) . '">'
        . '<span class="check__icon" aria-hidden="true">' . $icono($estado) . '</span>'
        . '<div class="stack" style="gap:2px;min-width:0">'
        . '<span class="check__name">' . e($nombre) . '</span>'
        . '<span class="check__detail">' . e($detalle) . '</span>'
        . '</div>'
        . '<span class="check__value">' . e($valor) . '</span>'
        . '</div>';
};

// El mismo contenido en texto plano, para pegarlo en un correo o un ticket sin
// tener que describir una captura de pantalla.
$resumen = [];
$resumen[] = 'DIAGNÓSTICO · Plataforma de Eventos TIC · ' . date('Y-m-d H:i:s');
$resumen[] = str_repeat('-', 56);
foreach ($servidor as $clave => $valor) {
    $resumen[] = sprintf('%-22s %s', $clave, $valor);
}
$resumen[] = '';
foreach ($archivos as $a) {
    $resumen[] = sprintf('[%s] %-24s %s', strtoupper($a['estado']), $a['nombre'], $a['valor']);
}
$resumen[] = '';
$resumen[] = sprintf('[%s] base de datos', $estado['bd'] ? 'OK' : 'FAIL');
$resumen[] = sprintf('tablas %d/%d · administradores %d · usuarios %d · eventos %d',
    $estado['tablas'], $estado['esperadas'], $estado['administradores'], $estado['usuarios'], $estado['eventos']);
if ($estado['faltantes']) {
    $resumen[] = 'faltan: ' . implode(', ', $estado['faltantes']);
}
if ($estado['motivo'] !== '') {
    $resumen[] = 'motivo: ' . $estado['motivo'];
}
if ($errores) {
    $resumen[] = '';
    $resumen[] = 'ÚLTIMOS ERRORES';
    foreach ($errores as $linea) {
        $resumen[] = $linea;
    }
}
?>
<main class="installer" id="contenido">
  <div class="installer__inner">

    <header class="stack stack--2">
      <div class="row" style="gap:12px">
        <span class="brandmark brandmark--lg" aria-hidden="true">E</span>
        <div class="stack" style="gap:2px">
          <span class="brandtext__name" style="font-size:15px">Plataforma de Eventos TIC</span>
          <span class="brandtext__sub">Diagnóstico de la instalación</span>
        </div>
      </div>
    </header>

    <?php if ($estado['completa']): ?>
      <div class="notice notice--ok">
        <span class="notice__icon" aria-hidden="true">✓</span>
        <span>La instalación está completa y utilizable.<?= $estado['motivo'] !== '' ? ' ' . e($estado['motivo']) : '' ?></span>
      </div>
    <?php else: ?>
      <div class="notice notice--danger">
        <span class="notice__icon" aria-hidden="true">▲</span>
        <span class="stack" style="gap:6px">
          <strong style="font-family:var(--f-display);font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
            La instalación no está utilizable
          </strong>
          <span><?= e($estado['motivo'] !== '' ? $estado['motivo'] : 'Falta algo por terminar.') ?></span>
          <span class="help">
            Si todas las direcciones del sitio llevan al asistente, es por esto. Termínalo en
            <a href="<?= e(u('/instalar')) ?>">el asistente de instalación</a>, o desde la consola con
            <span class="mono">php herramientas/instalar.php</span>.
          </span>
        </span>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><span>Archivos y permisos</span></div>
      <div class="card__body">
        <div class="check-list">
          <?php foreach ($archivos as $a) { $fila($a['nombre'], $a['detalle'], $a['valor'], $a['estado']); } ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <span>Base de datos</span>
        <span class="muted"><?= (int) $estado['tablas'] ?> de <?= (int) $estado['esperadas'] ?> tablas</span>
      </div>
      <div class="card__body">
        <div class="check-list">
          <?php
          $fila('Conexión', 'Con los datos de config/config.php', $estado['bd'] ? 'correcta' : 'sin conexión', $estado['bd'] ? 'ok' : 'fail');
          $fila('Tablas del esquema', 'Versión ' . Esquema::VERSION,
              $estado['tablas'] . ' de ' . $estado['esperadas'], $estado['faltantes'] ? 'fail' : 'ok');
          if ($estado['faltantes']) {
              $fila('Tablas que faltan', 'Se crean con el asistente en modo Actualizar',
                  implode(', ', array_slice($estado['faltantes'], 0, 4)) . (count($estado['faltantes']) > 4 ? '…' : ''), 'fail');
          }
          $fila('Cuentas administradoras', 'Activas, con las que se puede entrar al panel',
              (string) $estado['administradores'], $estado['administradores'] > 0 ? 'ok' : 'fail');
          $fila('Cuentas del equipo', 'Todas, incluidos operador y consulta',
              (string) $estado['usuarios'], 'ok');
          $fila('Eventos', 'Sin evento, la parte pública responde 503',
              (string) $estado['eventos'], $estado['eventos'] > 0 ? 'ok' : 'warn');
          ?>
        </div>
      </div>
    </div>

    <?php if (\App\Nucleo\App::peticion()->detrasDeProxySinConfigurar()): ?>
      <div class="notice notice--warn">
        <span class="notice__icon" aria-hidden="true">▲</span>
        <span class="stack" style="gap:6px">
          <strong style="font-family:var(--f-display);font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
            Hay un proxy por delante sin declarar
          </strong>
          <span>
            La aplicación está viendo la dirección del proxy y no la de cada visitante, así que
            el límite de intentos es uno solo para todo el mundo: veinte accesos fallidos de
            cualquiera dejarían fuera al equipo entero.
          </span>
          <span class="help">
            Se arregla en <span class="mono">config/config.php</span>, añadiendo la dirección
            desde la que llegan las peticiones:
            <span class="mono">'proxies_confiables' =&gt; ['<?= e((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')) ?>']</span>
          </span>
        </span>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><span>Servidor</span></div>
      <div class="card__body">
        <div class="check-list">
          <?php foreach ($servidor as $clave => $valor) { $fila((string) $clave, '', (string) $valor, 'ok'); } ?>
        </div>
      </div>
    </div>

    <?php if ($errores): ?>
      <div class="card">
        <div class="card__head">
          <span>Últimos errores registrados</span>
          <span class="muted">almacen/registro/</span>
        </div>
        <?php if ($publico): ?>
          <div class="card__body" style="padding-bottom:0">
            <p class="help">
              Esta pantalla es pública mientras la plataforma no funcione, así que los correos y
              las cifras largas van tapados. El archivo completo está en
              <span class="mono">almacen/registro/</span>.
            </p>
          </div>
        <?php endif; ?>
        <div class="card__body" style="padding:0">
          <pre class="sql-preview"><?= e(implode("\n", $errores)) ?></pre>
        </div>
      </div>
    <?php endif; ?>

    <details class="card">
      <summary class="card__head" style="cursor:pointer;list-style:none">
        <span>Copiar el diagnóstico como texto</span>
      </summary>
      <div class="card__body" style="padding:0">
        <pre class="sql-preview"><?= e(implode("\n", $resumen)) ?></pre>
      </div>
    </details>

    <div class="row row--end">
      <a class="btn" href="<?= e(u('/instalar')) ?>">Ir al asistente</a>
      <a class="btn btn--primary" href="<?= e(u('/admin/entrar')) ?>">Acceso del equipo</a>
    </div>

  </div>
</main>
