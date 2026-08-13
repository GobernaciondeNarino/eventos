<?php
/** Navegación inferior del móvil, más la hoja con el resto de opciones. */
defined('EVENTOS_TIC') || exit;

if (empty($pestanasMovil)) {
    return;
}
?>
<nav class="tabbar" aria-label="Navegación rápida">
  <div class="tabbar__inner">
    <?php foreach ($pestanasMovil as $item): ?>
      <a class="tabbar__btn<?= activo($item['clave'], $pantalla) ?>" href="<?= e(u($item['ruta'])) ?>"
         aria-label="<?= e($item['etiqueta']) ?>"
         <?= $item['clave'] === $pantalla ? 'aria-current="page"' : '' ?>>
        <?= $icono($item['icono'], 22, $item['clave'] === $pantalla ? 2 : 1.5) ?>
      </a>
    <?php endforeach; ?>
    <button class="tabbar__btn" type="button" data-abrir-hoja="menu-movil"
            aria-label="Más opciones" aria-expanded="false">
      <?= $icono('user', 22) ?>
    </button>
  </div>
</nav>

<div class="sheet hidden" id="menu-movil" hidden>
  <div class="sheet__panel" role="dialog" aria-label="Ir a" aria-modal="true">
    <div class="card__head"><span>Ir a</span>
      <button class="modal__close" type="button" data-cerrar-hoja aria-label="Cerrar">&times;</button>
    </div>
    <div class="sheet__list">
      <?php foreach ($navegacion as $grupo): ?>
        <span class="sidebar__groupLabel"><?= e($grupo['titulo']) ?></span>
        <?php foreach ($grupo['items'] as $item): ?>
          <a class="navlink<?= activo($item['clave'], $pantalla) ?>" href="<?= e(u($item['ruta'])) ?>">
            <?= $icono($item['icono']) ?><span><?= e($item['etiqueta']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <?php if ($usuario !== null || $persona !== null): ?>
        <form method="post" action="<?= e(u($usuario !== null ? '/admin/salir' : '/salir')) ?>" style="padding:8px 4px">
          <?= testigo() ?>
          <button class="btn btn--sm btn--block" type="submit">Cerrar sesión</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
