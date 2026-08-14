<?php
/**
 * Pantalla de check-in del asistente.
 *
 * El registro de ingreso real ocurre al escanear el código de la puerta con la
 * cámara del teléfono; esta pantalla existe para dos cosas: ver el estado de
 * los propios ingresos y ofrecer un lector dentro de la aplicación a quien
 * prefiera no salir de ella.
 *
 * @var array $historial @var array|null $jornadaHoy @var array|null $yaIngreso
 */
defined('EVENTOS_TIC') || exit;

guiones('escaner.js');
?>
<div class="view view--medium split" style="align-items:start">

  <div class="stack stack--4">
    <span class="kicker">
      Control de acceso<?= $jornadaHoy ? ' · Día ' . e((string) $jornadaHoy['numero']) : '' ?>
    </span>
    <h1 class="hero-title">Un escaneo por día,<br><em>desde el celular.</em></h1>
    <p class="lead">
      El código de la entrada cambia cada jornada. Al escanearlo, el sistema sella la
      fecha y la hora exacta de tu ingreso.
    </p>

    <?php if (!$jornadaHoy): ?>
      <div class="notice notice--warn">
        <span class="notice__icon">▲</span>
        <span>Hoy no hay ninguna jornada programada para este evento. Revisa las fechas en la agenda.</span>
      </div>
    <?php elseif ($yaIngreso): ?>
      <div class="notice notice--ok">
        <span class="notice__icon">✓</span>
        <span>
          Tu ingreso del día <?= e((string) $jornadaHoy['numero']) ?> quedó registrado a las
          <?= e(hora((string) $yaIngreso['registrado_en'])) ?>. No hace falta escanear de nuevo.
        </span>
      </div>
    <?php else: ?>
      <div class="notice">
        <span class="notice__icon">◆</span>
        <span>
          Hoy es el día <?= e((string) $jornadaHoy['numero']) ?>. Apunta la cámara al código
          pegado en la entrada; se abrirá esta plataforma y quedará registrado.
        </span>
      </div>
    <?php endif; ?>

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
  </div>

  <div class="phone">
    <div class="phone__screen" style="min-height:520px">
      <div class="phone__status">
        <span><?= e(date('g:i')) ?></span>
        <span>Lector integrado</span>
      </div>

      <div class="phone__pane" data-escaner data-destino-tipo="dia">
        <div class="scanner" data-escaner-marco>
          <video data-escaner-video playsinline muted hidden></video>
          <div class="scanner__box"></div>
          <div class="scanner__line"></div>
          <span class="scanner__hint" data-escaner-pista>Apunta al código de la entrada</span>
        </div>
        <button class="btn btn--primary btn--block btn--lg" type="button" data-escaner-iniciar>
          Abrir la cámara
        </button>
        <p class="help" data-escaner-alterna>
          También sirve la aplicación de cámara del teléfono: al enfocar el código se abre
          esta misma plataforma.
        </p>
      </div>
    </div>
  </div>

</div>
