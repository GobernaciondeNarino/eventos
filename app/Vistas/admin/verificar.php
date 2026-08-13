<?php
/** Segundo factor. @var array $errores */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
?>
<main class="acceso" id="contenido">
  <div class="acceso__inner">

    <div class="acceso__marca">
      <?= $marca('lg') ?>
      <h1>Verificación en dos pasos</h1>
      <span class="kicker">Segundo factor</span>
    </div>

    <form class="card" method="post" action="<?= e(u('/admin/verificar')) ?>" novalidate>
      <?= testigo() ?>
      <div class="card__head"><span>Código de la aplicación</span></div>
      <div class="card__body stack stack--4">

        <?php if (isset($errores['codigo'])): ?>
          <div class="notice notice--danger">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span><?= e($errores['codigo']) ?></span>
          </div>
        <?php endif; ?>

        <div class="field">
          <label class="label" for="codigo">Los seis dígitos que muestra tu aplicación</label>
          <input class="input campo-otp" id="codigo" name="codigo" inputmode="numeric"
                 maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                 placeholder="000000" autofocus required>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">Verificar y entrar</button>

        <p class="help">
          El código cambia cada treinta segundos. Si nunca coincide, revisa que la hora del
          teléfono esté puesta en automático.
        </p>
      </div>
    </form>

    <form method="post" action="<?= e(u('/admin/salir')) ?>" class="acceso__pie">
      <?= testigo() ?>
      <button class="btn btn--sm" type="submit">Cancelar y salir</button>
    </form>

  </div>
</main>
