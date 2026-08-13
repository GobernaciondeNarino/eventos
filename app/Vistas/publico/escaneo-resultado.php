<?php
/**
 * Resultado de escanear el código de la jornada.
 *
 * Es la pantalla que alguien mira de pie en la puerta, con fila detrás: el
 * estado se ve de un vistazo y lo que hay que hacer está en una sola frase.
 *
 * @var string $estado 'ok' | 'aviso' | 'error'
 * @var string $encabezado @var string $mensaje @var array|null $historial
 */
defined('EVENTOS_TIC') || exit;

$colores = [
    'ok'    => ['borde' => 'var(--c-accent)', 'icono' => '✓', 'fondo' => 'var(--a-08)'],
    'aviso' => ['borde' => 'var(--c-warn)', 'icono' => '!', 'fondo' => 'rgba(var(--c-warn-rgb), .08)'],
    'error' => ['borde' => 'var(--c-danger)', 'icono' => '×', 'fondo' => 'rgba(var(--c-danger-rgb), .08)'],
];
$c = $colores[$estado] ?? $colores['ok'];
?>
<div class="view view--narrow stack stack--4" style="max-width:520px;margin:auto">

  <div class="success" style="border-color:<?= e($c['borde']) ?>;background:<?= e($c['fondo']) ?>">
    <span class="success__mark" style="border-color:<?= e($c['borde']) ?>;color:<?= e($c['borde']) ?>" aria-hidden="true"><?= e($c['icono']) ?></span>
    <strong class="success__title"><?= e($encabezado) ?></strong>
    <span class="success__meta">
      <?php if (!empty($persona)): ?>
        <?= e($persona['nombre']) ?><br>
      <?php endif; ?>
      <?= e($mensaje) ?>
    </span>
  </div>

  <?php if (!empty($historial)): ?>
    <div class="card">
      <div class="card__head"><span>Mis ingresos</span></div>
      <div class="card__body--tight">
        <?php foreach ($historial as $h): ?>
          <div class="kv">
            <span class="kv__k">Día <?= e((string) $h['numero']) ?> · <?= e(fecha((string) $h['fecha'])) ?></span>
            <span class="mono" style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:<?= $h['registrado_en'] ? 'var(--c-accent)' : 'var(--c-muted)' ?>">
              <?= $h['registrado_en'] ? 'Ingresó ' . e(hora((string) $h['registrado_en'])) : 'Sin ingreso' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="row">
    <a class="btn btn--primary" href="<?= e(u('/agenda')) ?>">Ver la agenda de hoy</a>
    <a class="btn" href="<?= e(u('/carnet')) ?>">Mi carnet</a>
  </div>
</div>
