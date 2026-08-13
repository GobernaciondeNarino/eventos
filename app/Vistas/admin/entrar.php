<?php
/** Acceso del equipo organizador. @var string $correo @var string $destino @var array $errores */
defined('EVENTOS_TIC') || exit;

$marca = require __DIR__ . '/../parciales/marca.php';
?>
<main class="acceso" id="contenido">
  <div class="acceso__inner">

    <div class="acceso__marca">
      <?= $marca('lg') ?>
      <h1><?= e($evento['nombre'] ?? 'Plataforma de Eventos TIC') ?></h1>
      <span class="kicker">Acceso del equipo</span>
    </div>

    <form class="card" method="post" action="<?= e(u('/admin/entrar')) ?>" novalidate>
      <?= testigo() ?>
      <input type="hidden" name="destino" value="<?= e($destino) ?>">

      <div class="card__head"><span>Identifícate</span></div>
      <div class="card__body stack stack--4">

        <?php if (isset($errores['general'])): ?>
          <div class="notice notice--danger">
            <span class="notice__icon" aria-hidden="true">▲</span>
            <span><?= e($errores['general']) ?></span>
          </div>
        <?php endif; ?>

        <div class="field">
          <label class="label" for="correo">Correo institucional</label>
          <input class="input" id="correo" name="correo" type="email" value="<?= e($correo) ?>"
                 autocomplete="username" inputmode="email" placeholder="nombre@narino.gov.co"
                 autofocus required>
        </div>

        <div class="field">
          <label class="label" for="clave">Contraseña</label>
          <input class="input" id="clave" name="clave" type="password"
                 autocomplete="current-password" required>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">Entrar</button>

        <p class="help">
          Los accesos quedan registrados con fecha, hora y dirección IP. Tras varios
          intentos fallidos la cuenta se bloquea temporalmente.
        </p>
      </div>
    </form>

    <p class="acceso__pie">
      <a href="<?= e(u('/entrar')) ?>">Soy asistente ›</a>
      &nbsp;·&nbsp;
      <a href="<?= e(u('/')) ?>">Inicio</a>
    </p>

  </div>
</main>
