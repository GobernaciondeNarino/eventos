<?php
/** Acceso del asistente, paso 2. @var string $correo @var array $errores @var bool $modoRegistro */
defined('EVENTOS_TIC') || exit;

// Decir por dónde llegó el código. Poner «revisa tu correo» cuando salió por
// WhatsApp manda a la gente a mirar donde no hay nada.
$canalTexto = match ($metodo ?? 'correo') {
    'whatsapp' => 'tu WhatsApp',
    'sms'      => 'tus mensajes de texto',
    default    => 'tu correo',
};
?>
<div class="view view--narrow stack stack--5" style="max-width:480px;margin:auto">

  <div class="stack stack--2">
    <span class="kicker">Acceso de asistentes</span>
    <h1>Revisa <?= e($canalTexto) ?></h1>
    <p class="lead">
      Si <span class="mono accent"><?= e($correo) ?></span> está registrado en el evento,
      acaba de recibir un código de seis dígitos. Vence en diez minutos.
    </p>
  </div>

  <?php if ($modoRegistro): ?>
    <div class="notice notice--warn">
      <span class="notice__icon">▲</span>
      <span>
        Esta instalación no tiene el envío de correo configurado, así que el código quedó
        anotado en <span class="mono">almacen/registro/</span> en lugar de enviarse.
        Configura el correo antes de abrir el evento al público.
      </span>
    </div>
  <?php endif; ?>

  <form class="card" method="post" action="<?= e(u('/entrar/codigo')) ?>" novalidate>
    <?= testigo() ?>
    <div class="card__head"><span>Código de acceso</span></div>
    <div class="card__body stack stack--4">
      <div class="field">
        <label class="label" for="codigo">Los seis dígitos que recibiste</label>
        <input class="input input--mono<?= isset($errores['codigo']) ? ' is-invalid' : '' ?>"
               id="codigo" name="codigo" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
               autocomplete="one-time-code" placeholder="000000" autofocus required
               style="font-size:26px;letter-spacing:.32em;text-align:center">
        <?php if (isset($errores['codigo'])): ?><span class="error"><?= e($errores['codigo']) ?></span><?php endif; ?>
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Entrar</button>

      <p class="help">
        Mira también la carpeta de correo no deseado. Si en unos minutos no llega nada,
        acércate al punto de información: puede ser un problema del correo del servidor, y
        desde ahí pueden registrar tu ingreso a mano con tu documento.
      </p>

      <p class="help">
        ¿No llegó? Revisa la carpeta de correo no deseado o
        <a href="<?= e(u('/entrar')) ?>">pide otro código</a>.
      </p>
    </div>
  </form>
</div>
