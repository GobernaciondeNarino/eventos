<?php
/**
 * Pliego imprimible del código de una jornada.
 *
 * Sale a página completa para pegarlo en la entrada: el código tiene que
 * leerse desde metro y medio, con gente pasando.
 *
 * @var array $jornada @var string $url @var string $qr
 */
defined('EVENTOS_TIC') || exit;
?>
<main class="main" id="contenido" style="padding:24px">
  <div class="view view--narrow stack stack--4" style="max-width:700px;margin:auto">

    <div class="row row--between no-print">
      <div class="stack stack--2">
        <span class="kicker">Impresión</span>
        <h1 style="font-size:24px">Código del día <?= e((string) $jornada['numero']) ?></h1>
      </div>
      <div class="row">
        <button class="btn btn--primary" type="button" onclick="window.print()">Imprimir</button>
        <a class="btn" href="<?= e(u('/admin/qr-dias')) ?>">Volver</a>
      </div>
    </div>

    <div class="print-sheet" style="display:block">
      <h1 class="print-sheet__title" style="font-family:var(--f-display);font-size:34px;color:var(--c-title);text-align:center">
        <?= e($evento['nombre']) ?>
      </h1>
      <p class="print-sheet__sub" style="text-align:center;color:var(--c-text);margin:8px 0 24px">
        Registro de ingreso · Día <?= e((string) $jornada['numero']) ?> · <?= e(fecha((string) $jornada['fecha'])) ?>
      </p>

      <div class="qr-frame" style="max-width:420px;margin:0 auto"><?= $qr ?></div>

      <p style="text-align:center;margin-top:22px;font-family:var(--f-display);font-size:20px;letter-spacing:.06em;text-transform:uppercase;color:var(--c-title)">
        Escanea con la cámara de tu celular
      </p>
      <p class="print-sheet__token mono" style="text-align:center;margin-top:10px;color:var(--c-muted);font-size:12px;word-break:break-all">
        <?= e($url) ?>
      </p>
      <p style="text-align:center;margin-top:6px;color:var(--c-muted);font-size:12px">
        Horario de la jornada: <?= e(substr((string) $jornada['abre_a'], 0, 5)) ?> a <?= e(substr((string) $jornada['cierra_a'], 0, 5)) ?>
      </p>
    </div>

  </div>
</main>
