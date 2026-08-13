<?php
/** Barra superior, solo visible en móvil. */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/marca.php';

$etiquetaPantalla = '';
foreach (($navegacion ?? []) as $grupo) {
    foreach ($grupo['items'] as $item) {
        if ($item['clave'] === ($pantalla ?? '')) {
            $etiquetaPantalla = $item['etiqueta'];
        }
    }
}
?>
<header class="topbar">
  <a class="row" style="min-width:0;gap:10px;text-decoration:none" href="<?= e(u('/')) ?>">
    <?= $marca('sm') ?>
    <span class="brandtext__name"><?= e($evento['nombre'] ?? 'Eventos TIC') ?></span>
  </a>
  <span class="topbar__screen"><?= e($etiquetaPantalla ?: ($titulo ?? '')) ?></span>
</header>
