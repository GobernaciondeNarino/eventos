<?php
/**
 * Página de error.
 * @var int $codigo @var string $titulo @var string $mensaje @var array $acciones
 * @var array $detalle Líneas técnicas. Solo se llenan mientras la plataforma no
 *                     está instalada, o con «depurar» encendido a propósito.
 */
defined('EVENTOS_TIC') || exit;

$acciones = $acciones ?? [];
$detalle = $detalle ?? [];
$ancho = $detalle ? '860px' : '560px';
?>
<div class="view view--narrow stack stack--4" style="max-width:<?= $ancho ?>;margin:auto;padding-top:8vh">
  <span class="kicker">Error <?= e((string) $codigo) ?></span>
  <h1><?= e($titulo) ?></h1>
  <?php if ($mensaje !== ''): ?>
    <p class="lead"><?= e($mensaje) ?></p>
  <?php endif; ?>
  <?php if ($detalle): ?>
    <?php /* Se muestra solo antes de terminar la instalación: en ese momento no
             hay todavía ni cuentas, ni datos personales, ni llave de cifrado que
             se puedan filtrar, y sin esto quien instala se queda ciego ante una
             pantalla que solo dice «algo salió mal». En cuanto config.php existe
             con la marca de instalado, esta caja desaparece sola. */ ?>
    <div class="card stack stack--2" style="border-color:var(--peligro,#e2574c)">
      <strong>Detalle técnico</strong>
      <p class="muted" style="margin:0">
        Se muestra porque la plataforma todavía no terminó de instalarse. Copia
        este bloque si necesitas ayuda: aquí está la causa exacta.
      </p>
      <pre style="white-space:pre-wrap;word-break:break-word;font-size:.82rem;line-height:1.5;margin:0;overflow-x:auto"><?= e(implode("\n", $detalle)) ?></pre>
    </div>
  <?php endif; ?>
  <div class="row">
    <?php if ($acciones): ?>
      <?php foreach ($acciones as $a): ?>
        <a class="btn<?= !empty($a['principal']) ? ' btn--primary' : '' ?>" href="<?= e($a['url']) ?>"><?= e($a['texto']) ?></a>
      <?php endforeach; ?>
    <?php else: ?>
      <a class="btn btn--primary" href="<?= e(u('/')) ?>">Ir al inicio</a>
      <?php /* Antes había aquí un «Volver a intentarlo» apuntando al Referer.
               En un rechazo por testigo inválido, ese Referer es justo la página
               del atacante: el error terminaba invitando a volver a ella. */ ?>
    <?php endif; ?>
  </div>
</div>
