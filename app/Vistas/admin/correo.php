<?php
/**
 * Configuración de correo, con revisión y prueba.
 *
 * @var array $ajustes @var array $revision @var array|null $prueba @var array|null $red
 * @var array $proveedores @var string $sugerido
 */
defined('EVENTOS_TIC') || exit;

$modo = $ajustes['modo'];
$esSmtp = $modo === 'smtp';

$fallos = array_values(array_filter($revision, static fn(array $r): bool => $r['estado'] === 'fail'));
$avisos = array_values(array_filter($revision, static fn(array $r): bool => $r['estado'] === 'warn'));

$marcaEstado = static function (string $estado): string {
    return match ($estado) {
        'ok'   => '<span style="color:var(--c-ok,#3fbf7f)">✓</span>',
        'warn' => '<span style="color:var(--c-warn,#e8a33d)">▲</span>',
        default => '<span style="color:var(--c-peligro,#e2574c)">✕</span>',
    };
};

/** Los códigos que devuelven de verdad Google y Microsoft, y qué hacer con cada uno. */
$codigos = [
    ['535-5.7.8', 'Username and Password not accepted',
     'La contraseña no es una contraseña de aplicación, o el usuario no lleva el dominio.',
     'Genera una contraseña de aplicación de 16 letras y usa la dirección completa como usuario.'],
    ['534-5.7.9', 'Application-specific password required',
     'La cuenta tiene verificación en dos pasos y se está mandando la contraseña normal.',
     'Cuenta de Google → Seguridad → Verificación en dos pasos → Contraseñas de aplicación.'],
    ['534-5.7.14', 'Please log in via your web browser',
     'Google no reconoce el servidor desde el que se conecta.',
     'Abre accounts.google.com/DisplayUnlockCaptcha con esa cuenta y reintenta en 10 minutos.'],
    ['530-5.5.1', 'Authentication Required',
     'Se intentó enviar sin autenticarse, o antes de cifrar el canal.',
     'Completa usuario y contraseña, y usa STARTTLS en el puerto 587.'],
    ['454-4.7.0', 'Too many login attempts',
     'Demasiados intentos seguidos; Google bloquea un rato.',
     'Espera unos minutos. El botón de probar está limitado a 10 cada 5 minutos por eso mismo.'],
    ['550-5.7.1', 'Not allowed to send as / relay denied',
     'El remitente no es la cuenta autenticada ni un alias verificado.',
     'Pon el mismo correo en «Remitente» y «Usuario», o verifica el alias en Gmail → Cuentas → Enviar como.'],
    ['550-5.4.5', 'Daily user sending limit exceeded',
     'Se agotó el cupo diario: 2.000 mensajes en Workspace, 500 en una cuenta gratuita.',
     'Reparte los envíos entre varios días, o usa un servicio de envío masivo para las convocatorias.'],
    ['550-5.1.1', 'User unknown / Recipient address rejected',
     'La dirección de destino no existe.',
     'Revisa que esté bien escrita.'],
    ['553-5.1.8', 'Domain of sender address does not exist',
     'El dominio del remitente no se resuelve.',
     'Usa una dirección de un dominio real, con registros MX.'],
    ['552-5.3.4', 'Message too large',
     'El mensaje supera el tamaño permitido.',
     'No debería pasar con los correos de la plataforma; avisa si ocurre.'],
    ['421-4.7.0', 'Try again later',
     'Fallo temporal del servidor o límite de frecuencia.',
     'Reintenta más tarde. Si se repite siempre, es límite de frecuencia.'],
    ['—', 'Connection refused · Timed out',
     'No se llegó a hablar con el servidor: el puerto de salida está cerrado.',
     'Prueba 465 con SSL directo si el 587 no responde. Desde SSH: nc -vz smtp.gmail.com 587'],
    ['—', 'TLS / certificado',
     'El cifrado no se pudo establecer.',
     'Revisa que openssl esté activo y el reloj del servidor en hora.'],
];
?>
<div class="view view--wide stack stack--4">

  <div class="stack stack--2">
    <span class="kicker">Administrador</span>
    <h1>Correo</h1>
    <p class="lead" style="max-width:64ch">
      Sin correo la plataforma pierde la mitad de lo que hace: quien cierre sesión no podrá
      volver a entrar —el código de acceso es lo único que lo identifica— y los carnets no
      saldrán de la pantalla en que se generaron.
    </p>
  </div>

  <!-- ============ Estado ============ -->
  <section class="card">
    <div class="card__head">
      <span>Estado de la configuración</span>
      <span class="mono" style="font-size:11px;color:var(--c-muted)">
        <?= $fallos ? count($fallos) . ' bloqueante(s)' : ($avisos ? count($avisos) . ' aviso(s)' : 'sin problemas') ?>
      </span>
    </div>
    <div class="card__body stack stack--3">
      <?php if (!$revision): ?>
        <p class="help">Todo en orden.</p>
      <?php endif; ?>
      <?php foreach ($revision as $punto): ?>
        <div style="display:grid;grid-template-columns:20px 1fr;gap:10px;align-items:start">
          <div style="padding-top:2px"><?= $marcaEstado($punto['estado']) ?></div>
          <div class="stack stack--1">
            <strong style="font-size:14px"><?= e($punto['titulo']) ?></strong>
            <p class="help" style="margin:0"><?= e($punto['detalle']) ?></p>
            <?php if ($punto['arreglo'] !== ''): ?>
              <p class="help" style="margin:0;color:var(--c-accent)">→ <?= e($punto['arreglo']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ============ Resultado de la última prueba ============ -->
  <?php if (is_array($prueba)): ?>
    <section class="card" style="border-color:<?= $prueba['ok'] ? 'var(--c-ok,#3fbf7f)' : 'var(--c-peligro,#e2574c)' ?>">
      <div class="card__head">
        <span><?= $prueba['ok'] ? 'La prueba salió bien' : 'La prueba falló' ?></span>
        <?php if ($prueba['codigo'] !== ''): ?>
          <span class="mono" style="font-size:11px">código <?= e($prueba['codigo']) ?></span>
        <?php endif; ?>
      </div>
      <div class="card__body stack stack--3">
        <p style="margin:0"><?= e($prueba['resumen']) ?></p>

        <?php if ($prueba['error'] !== ''): ?>
          <div class="stack stack--1">
            <strong style="font-size:13px">Lo que dijo el servidor</strong>
            <pre class="sql-preview" style="white-space:pre-wrap;margin:0"><?= e($prueba['error']) ?></pre>
          </div>
        <?php endif; ?>

        <?php if ($prueba['pistas']): ?>
          <div class="stack stack--1">
            <strong style="font-size:13px">Qué hacer</strong>
            <ul style="margin:0;padding-left:18px;display:grid;gap:6px">
              <?php foreach ($prueba['pistas'] as $pista): ?>
                <li class="help" style="margin:0"><?= e($pista) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($prueba['transcripcion'] !== ''): ?>
          <details>
            <summary style="cursor:pointer;font-size:13px;font-weight:600">
              Conversación completa con el servidor
            </summary>
            <p class="help" style="margin:8px 0">
              Las líneas de autenticación salen tachadas: llevan la contraseña en base64, que es
              texto plano con un paso más.
            </p>
            <pre class="sql-preview" style="white-space:pre-wrap;margin:0;font-size:11.5px;max-height:340px;overflow:auto"><?= e($prueba['transcripcion']) ?></pre>
          </details>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ============ Diagnóstico de red ============ -->
  <?php if (is_array($red)): ?>
    <?php
      $abiertos = array_values(array_filter($red['intentos'], static fn(array $i): bool => $i['ok']));
      $borde = $abiertos ? 'var(--c-ok,#3fbf7f)' : 'var(--c-peligro,#e2574c)';
    ?>
    <section class="card" style="border-color:<?= $borde ?>">
      <div class="card__head">
        <span>Salida de red hasta <?= e($red['host']) ?></span>
        <span class="mono" style="font-size:11px;color:var(--c-muted)">
          <?= count($abiertos) ?> de <?= count($red['intentos']) ?> intentos abrieron
        </span>
      </div>
      <div class="card__body stack stack--3">
        <p style="margin:0"><?= e($red['resumen']) ?></p>

        <div class="stack stack--1">
          <strong style="font-size:13px">Qué devuelve el DNS</strong>
          <p class="help mono" style="margin:0">
            IPv4: <?= e($red['ipv4'] ? implode(', ', $red['ipv4']) : 'ninguna') ?><br>
            IPv6: <?= e($red['ipv6'] ? implode(', ', $red['ipv6']) : 'ninguna') ?>
          </p>
        </div>

        <?php if ($red['intentos']): ?>
          <div style="overflow-x:auto">
            <table style="width:100%;min-width:520px;border-collapse:collapse">
              <thead>
                <tr style="text-align:left;border-bottom:1px solid var(--a-22)">
                  <?php foreach (['Puerto', 'Familia', 'Dirección', 'Resultado'] as $th): ?>
                    <th style="padding:8px 12px 8px 0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--c-muted);font-weight:600"><?= e($th) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($red['intentos'] as $i): ?>
                  <tr style="border-bottom:1px solid var(--hair,var(--a-14))">
                    <td class="mono" style="padding:9px 12px 9px 0;font-size:12px"><?= (int) $i['puerto'] ?></td>
                    <td class="mono" style="padding:9px 12px 9px 0;font-size:12px"><?= e($i['familia']) ?></td>
                    <td class="mono" style="padding:9px 12px 9px 0;font-size:11.5px;color:var(--c-muted)"><?= e($i['destino']) ?></td>
                    <td style="padding:9px 0;font-size:12.5px">
                      <?php if ($i['ok']): ?>
                        <span style="color:var(--c-ok,#3fbf7f)">✓ abre</span>
                        <span class="mono" style="color:var(--c-muted);font-size:11px"> · <?= (int) $i['ms'] ?> ms</span>
                      <?php else: ?>
                        <span style="color:var(--c-peligro,#e2574c)">✕</span>
                        <span class="mono" style="font-size:11px"> <?= e($i['error']) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php $localOk = array_values(array_filter($red['relayLocal'] ?? [], static fn(array $r): bool => $r['ok'])); ?>
        <?php if (!empty($red['relayLocal'])): ?>
          <div class="stack stack--2" style="padding-top:6px;border-top:1px solid var(--hair,var(--a-14))">
            <strong style="font-size:13px">Servidor de correo de esta misma máquina</strong>
            <p class="help" style="margin:0">
              Conectarse a 127.0.0.1 no es tráfico saliente, así que un bloqueo del proveedor no le
              aplica. Es la ruta por la que WordPress envía en este servidor.
            </p>
            <div style="overflow-x:auto">
              <table style="width:100%;min-width:420px;border-collapse:collapse">
                <tbody>
                  <?php foreach ($red['relayLocal'] as $r): ?>
                    <tr style="border-bottom:1px solid var(--hair,var(--a-14))">
                      <td class="mono" style="padding:8px 12px 8px 0;font-size:12px;white-space:nowrap">127.0.0.1:<?= (int) $r['puerto'] ?></td>
                      <td style="padding:8px 0;font-size:12.5px">
                        <?php if ($r['ok']): ?>
                          <span style="color:var(--c-ok,#3fbf7f)">✓ acepta</span>
                          <?php if ($r['saludo'] !== ''): ?>
                            <span class="mono" style="color:var(--c-muted);font-size:11px"> · <?= e($r['saludo']) ?></span>
                          <?php endif; ?>
                        <?php else: ?>
                          <span style="color:var(--c-muted)">✕ <span class="mono" style="font-size:11px"><?= e($r['error']) ?></span></span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if ($localOk): ?>
              <form method="post" action="<?= e(u('/admin/correo/local')) ?>" class="row" style="gap:10px">
                <?= testigo() ?>
                <input type="hidden" name="puerto" value="<?= (int) $localOk[0]['puerto'] ?>">
                <button class="btn btn--primary" type="submit">
                  Usar el correo local (127.0.0.1:<?= (int) $localOk[0]['puerto'] ?>)
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($red['cortafuegos'])): ?>
          <div class="stack stack--2" style="padding-top:6px;border-top:1px solid var(--hair,var(--a-14))">
            <strong style="font-size:13px">Levantar el bloqueo, si es lo que hay</strong>
            <p class="help" style="margin:0">
              Estas comprobaciones corrieron <strong>como el usuario de PHP</strong>, que es el que
              importa: probar por SSH como <span class="mono">root</span> no vale, porque la regla
              suele dejar salir a root y rechazar a los demás. Si aquí sale «rechazado» y por SSH
              conecta, el bloqueo es por usuario. Las órdenes ya llevan el UID de este proceso.
            </p>
            <?php foreach ($red['cortafuegos'] as $n => $paso): ?>
              <div class="stack stack--1">
                <span class="help" style="margin:0;color:var(--c-accent)"><?= (int) $n + 1 ?>. <?= e($paso['titulo']) ?></span>
                <pre class="sql-preview" style="white-space:pre-wrap;margin:0;font-size:11.5px"><?= e($paso['orden']) ?></pre>
                <span class="help" style="margin:0"><?= e($paso['nota']) ?></span>
              </div>
            <?php endforeach; ?>
            <p class="help" style="margin:0">
              Si no se puede tocar el cortafuegos, el modo <strong>API por HTTPS</strong> sale por el
              443 y no le afecta.
            </p>
          </div>
        <?php endif; ?>

        <details>
          <summary style="cursor:pointer;font-size:13px;font-weight:600">Cómo está PHP en este servidor</summary>
          <pre class="sql-preview" style="white-space:pre-wrap;margin:8px 0 0;font-size:11.5px"><?php
            foreach ($red['local'] as $clave => $valor) {
                echo e(str_pad($clave, 20) . ' ' . $valor) . "\n";
            }
          ?></pre>
        </details>
      </div>
    </section>
  <?php endif; ?>

  <!-- ============ Probar ============ -->
  <section class="card">
    <div class="card__head"><span>Probar ahora</span></div>
    <form class="card__body stack stack--3" method="post" action="<?= e(u('/admin/correo/probar')) ?>">
      <?= testigo() ?>
      <p class="help" style="margin:0">
        Con una dirección, se envía un mensaje de prueba de principio a fin. Sin ella, solo se
        comprueba que se puede conectar y autenticar.
      </p>
      <div class="row" style="gap:10px;flex-wrap:wrap;align-items:end">
        <label class="field" style="flex:1;min-width:240px">
          <span class="label">Enviar la prueba a</span>
          <input type="email" name="destinatario" class="input" autocomplete="off"
                 placeholder="<?= e($sugerido !== '' ? $sugerido : 'alguien@narino.gov.co') ?>"
                 value="<?= e($sugerido) ?>">
        </label>
        <button class="btn btn--primary" type="submit">Probar</button>
      </div>
    </form>
    <div class="card__body" style="border-top:1px solid var(--hair,var(--a-14))">
      <form method="post" action="<?= e(u('/admin/correo/red')) ?>" class="stack stack--2">
        <?= testigo() ?>
        <p class="help" style="margin:0">
          Si la prueba falla antes de hablar con el servidor —«Network is unreachable»,
          «Connection refused», un tiempo de espera agotado— el problema es de red y no de
          correo. Esto mira el DNS y prueba los puertos 587, 465 y 25 por IPv4 y por IPv6.
        </p>
        <div><button class="btn" type="submit">Probar la salida de red</button></div>
      </form>
    </div>
  </section>

  <!-- ============ Configuración ============ -->
  <form class="card" method="post" action="<?= e(u('/admin/correo')) ?>">
    <?= testigo() ?>
    <div class="card__head"><span>Configuración</span></div>
    <div class="card__body stack stack--4">

      <label class="field">
        <span class="label">Modo de envío</span>
        <select name="modo_correo" class="select">
          <option value="smtp" <?= $modo === 'smtp' ? 'selected' : '' ?>>
            Servidor SMTP — recomendado para el correo institucional
          </option>
          <option value="api" <?= $modo === 'api' ? 'selected' : '' ?>>
            API por HTTPS — cuando el cortafuegos rechaza el SMTP saliente
          </option>
          <option value="php" <?= $modo === 'php' ? 'selected' : '' ?>>
            Función mail() del servidor — entrega por el correo local
          </option>
          <option value="registro" <?= $modo === 'registro' ? 'selected' : '' ?>>
            Solo registrar — no envía nada, deja los mensajes en almacen/registro/
          </option>
        </select>
        <span class="help">
          El correo de narino.gov.co está en Google Workspace, así que la opción correcta es SMTP:
          mail() entregaría al servidor de correo local, que no está autorizado a enviar en nombre
          del dominio.
        </span>
      </label>

      <div class="split" style="gap:14px">
        <label class="field">
          <span class="label">Remitente</span>
          <input type="email" name="correo_remitente" class="input" required
                 value="<?= e($ajustes['remitente']) ?>" placeholder="hosting@narino.gov.co">
          <span class="help">La dirección que verán los asistentes.</span>
        </label>
        <label class="field">
          <span class="label">Nombre del remitente</span>
          <input type="text" name="correo_nombre" class="input" maxlength="120"
                 value="<?= e($ajustes['nombre']) ?>" placeholder="Secretaría TIC · Gobernación de Nariño">
        </label>
      </div>

      <fieldset style="border:1px solid var(--a-14);padding:16px;display:grid;gap:14px">
        <legend class="kicker" style="padding:0 6px">Servidor SMTP</legend>

        <div class="split" style="gap:14px">
          <label class="field">
            <span class="label">Servidor</span>
            <input type="text" name="smtp_host" class="input" value="<?= e($ajustes['host']) ?>"
                   placeholder="smtp.gmail.com" autocapitalize="off" spellcheck="false">
          </label>
          <label class="field">
            <span class="label">Puerto</span>
            <input type="number" name="smtp_puerto" class="input" min="1" max="65535"
                   value="<?= e((string) $ajustes['puerto']) ?>">
          </label>
        </div>

        <label class="field">
          <span class="label">Seguridad</span>
          <select name="smtp_seguridad" class="select">
            <option value="tls" <?= $ajustes['seguridad'] === 'tls' ? 'selected' : '' ?>>STARTTLS — puerto 587</option>
            <option value="ssl" <?= $ajustes['seguridad'] === 'ssl' ? 'selected' : '' ?>>SSL directo — puerto 465</option>
            <option value="ninguna" <?= $ajustes['seguridad'] === 'ninguna' ? 'selected' : '' ?>>Sin cifrar — solo relé interno</option>
          </select>
          <span class="help">
            El puerto y la seguridad van juntos: 587 con STARTTLS, o 465 con SSL directo.
            Si uno no responde, prueba el otro: hay proveedores que cierran uno de los dos.
          </span>
        </label>

        <div class="split" style="gap:14px">
          <label class="field">
            <span class="label">Usuario</span>
            <input type="text" name="smtp_usuario" class="input" autocomplete="off"
                   autocapitalize="off" spellcheck="false"
                   value="<?= e($ajustes['usuario']) ?>" placeholder="hosting@narino.gov.co">
            <span class="help">La dirección completa, con dominio.</span>
          </label>
          <label class="field">
            <span class="label">
              Contraseña de aplicación
              <?php if ($ajustes['hayClave']): ?>
                <span class="mono" style="font-size:10px;color:var(--c-muted)">· hay una guardada</span>
              <?php endif; ?>
            </span>
            <input type="password" name="smtp_clave" class="input" autocomplete="new-password"
                   placeholder="<?= $ajustes['hayClave'] ? '•••• •••• •••• ••••  (déjalo vacío para no cambiarla)' : 'xxxx xxxx xxxx xxxx' ?>">
            <span class="help">
              En Google no vale la contraseña de la cuenta: hace falta una contraseña de
              aplicación de 16 letras. Los espacios se quitan solos al guardar.
            </span>
          </label>
        </div>

        <?php if ($ajustes['hayClave']): ?>
          <label class="row" style="gap:8px;cursor:pointer">
            <input type="checkbox" name="borrar_clave" value="1" style="width:16px;height:16px;accent-color:var(--c-accent)">
            <span class="help">Borrar la contraseña guardada</span>
          </label>
        <?php endif; ?>

        <div class="split" style="gap:14px">
          <label class="field">
            <span class="label">Espera máxima (segundos)</span>
            <input type="number" name="smtp_espera" class="input" min="5" max="60"
                   value="<?= e((string) $ajustes['espera']) ?>">
            <span class="help">Cuánto aguantar sin respuesta antes de darlo por fallido.</span>
          </label>
          <div class="field" style="gap:14px">
            <label class="row" style="gap:8px;cursor:pointer">
              <input type="checkbox" name="smtp_verificar_certificado" value="1"
                     style="width:16px;height:16px;accent-color:var(--c-accent)"
                     <?= $ajustes['verificar'] ? 'checked' : '' ?>>
              <span class="label" style="margin:0">Verificar el certificado del servidor</span>
            </label>
            <span class="help" style="margin-top:-8px">
              Déjalo activado. Desactivarlo solo tiene sentido con un servidor de correo interno
              y certificado propio: sin verificación, alguien en medio de la red podría quedarse
              con las credenciales.
            </span>

            <label class="row" style="gap:8px;cursor:pointer">
              <input type="checkbox" name="smtp_solo_ipv4" value="1"
                     style="width:16px;height:16px;accent-color:var(--c-accent)"
                     <?= $ajustes['soloIpv4'] ? 'checked' : '' ?>>
              <span class="label" style="margin:0">Usar solo IPv4</span>
            </label>
            <span class="help" style="margin-top:-8px">
              La plataforma ya intenta IPv4 antes que IPv6. Marca esto para no intentar IPv6
              siquiera, en un servidor donde el DNS devuelve dirección IPv6 pero no hay ruta de
              salida por ahí: es lo que produce «Network is unreachable».
            </span>
          </div>
        </div>
      </fieldset>

      <fieldset style="border:1px solid var(--a-14);padding:16px;display:grid;gap:14px">
        <legend class="kicker" style="padding:0 6px">API por HTTPS</legend>
        <p class="help" style="margin:0">
          Entrega por el puerto 443, el mismo por el que este servidor sirve la web. Una regla de
          cortafuegos que cierre el SMTP saliente no le afecta, así que sirve cuando no se puede
          tocar el cortafuegos. Hace falta una clave del proveedor y <strong>verificar el dominio
          del remitente</strong> en su panel.
        </p>

        <div class="split" style="gap:14px">
          <div class="field">
            <label class="label" for="api-proveedor">Proveedor</label>
            <select name="api_proveedor" id="api-proveedor" class="select">
              <?php foreach ($proveedores as $clave => $p): ?>
                <option value="<?= e($clave) ?>" <?= $ajustes['apiProveedor'] === $clave ? 'selected' : '' ?>>
                  <?= e($p['nombre']) ?> — <?= e($p['gratis']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="help">
              Clave en:
              <?php foreach ($proveedores as $clave => $p): ?>
                <span class="mono" style="font-size:11px"><?= e($p['panel']) ?></span><?= $clave === array_key_last($proveedores) ? '' : ' · ' ?>
              <?php endforeach; ?>
            </span>
          </div>
          <div class="field">
            <label class="label" for="api-clave">
              Clave de API
              <?php if ($ajustes['hayClaveApi']): ?>
                <span class="mono" style="font-size:10px;color:var(--c-muted)">· hay una guardada</span>
              <?php endif; ?>
            </label>
            <input type="password" name="api_clave" id="api-clave" class="input" autocomplete="new-password"
                   placeholder="<?= $ajustes['hayClaveApi'] ? '••••••••  (déjalo vacío para no cambiarla)' : 'xkeysib-… / SG.… / re_…' ?>">
            <span class="help">Dale permiso de envío, no solo de lectura.</span>
          </div>
        </div>

        <?php if ($ajustes['hayClaveApi']): ?>
          <label class="row" style="gap:8px;cursor:pointer">
            <input type="checkbox" name="borrar_clave_api" value="1" style="width:16px;height:16px;accent-color:var(--c-accent)">
            <span class="help">Borrar la clave de API guardada</span>
          </label>
        <?php endif; ?>
      </fieldset>

      <div class="row" style="gap:10px">
        <button class="btn btn--primary" type="submit">Guardar</button>
      </div>
    </div>
  </form>

  <!-- ============ Cómo se configura Google ============ -->
  <section class="card">
    <div class="card__head"><span>Cómo se obtiene la contraseña de aplicación</span></div>
    <div class="card__body stack stack--3">
      <ol style="margin:0;padding-left:20px;display:grid;gap:8px">
        <li class="help" style="margin:0">
          Entra a <span class="mono">myaccount.google.com</span> con la cuenta institucional
          (<span class="mono">hosting@narino.gov.co</span>).
        </li>
        <li class="help" style="margin:0">
          <strong>Seguridad → Verificación en dos pasos.</strong> Tiene que estar activa: sin ella
          Google no ofrece contraseñas de aplicación.
        </li>
        <li class="help" style="margin:0">
          Al final de esa página, <strong>Contraseñas de aplicaciones</strong>. Crea una con un
          nombre reconocible, por ejemplo <span class="mono">eventos</span>.
        </li>
        <li class="help" style="margin:0">
          Google muestra 16 letras en cuatro grupos. Pégalas en el campo de arriba —los espacios
          dan igual— y guarda. <strong>Solo se ven una vez.</strong>
        </li>
        <li class="help" style="margin:0">
          Si la cuenta es de Google Workspace y no aparece la opción, el administrador del dominio
          la tiene bloqueada en la consola de administración.
        </li>
      </ol>
      <p class="help" style="margin:0;padding-top:6px;border-top:1px solid var(--a-14)">
        Una contraseña de aplicación da acceso completo a la cuenta. Si se filtra —o se comparte
        por chat o correo—, revócala desde esa misma pantalla y genera otra.
      </p>
    </div>
  </section>

  <!-- ============ Tabla de códigos ============ -->
  <section class="card">
    <div class="card__head"><span>Códigos de error y qué significan</span></div>
    <div class="card__body" style="overflow-x:auto">
      <table style="width:100%;min-width:720px;border-collapse:collapse">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid var(--a-22)">
            <th style="white-space:nowrap;padding:9px 12px 9px 0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--c-muted);font-weight:600">Código</th>
            <th style="padding:9px 12px 9px 0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--c-muted);font-weight:600">Qué dice el servidor</th>
            <th style="padding:9px 12px 9px 0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--c-muted);font-weight:600">Qué pasa</th>
            <th style="padding:9px 0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--c-muted);font-weight:600">Qué hacer</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($codigos as [$codigo, $texto, $causa, $arreglo]): ?>
            <tr style="border-bottom:1px solid var(--hair,var(--a-14));vertical-align:top">
              <td class="mono" style="white-space:nowrap;font-size:12px;padding:10px 12px 10px 0;color:var(--c-accent)"><?= e($codigo) ?></td>
              <td class="mono" style="font-size:12px;padding:10px 12px 10px 0"><?= e($texto) ?></td>
              <td style="font-size:13px;padding:10px 12px 10px 0;color:var(--c-muted)"><?= e($causa) ?></td>
              <td style="font-size:13px;padding:10px 0"><?= e($arreglo) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ============ Que no acabe en no deseado ============ -->
  <section class="card">
    <div class="card__head"><span>Para que los mensajes lleguen a la bandeja de entrada</span></div>
    <div class="card__body stack stack--2">
      <p class="help" style="margin:0">
        Que el envío funcione no garantiza que el mensaje se lea. Estas tres cosas se configuran
        en el DNS del dominio y no en esta pantalla; las hace el área de sistemas:
      </p>
      <ul style="margin:0;padding-left:18px;display:grid;gap:6px">
        <li class="help" style="margin:0">
          <strong>SPF</strong> — el registro TXT de <span class="mono">narino.gov.co</span> debe
          incluir <span class="mono">include:_spf.google.com</span>.
        </li>
        <li class="help" style="margin:0">
          <strong>DKIM</strong> — se activa en la consola de Google Workspace
          (Aplicaciones → Gmail → Autenticar correo) y se publica el TXT que genera.
        </li>
        <li class="help" style="margin:0">
          <strong>DMARC</strong> — un registro en <span class="mono">_dmarc.narino.gov.co</span>,
          aunque sea <span class="mono">p=none</span> al principio, para poder ver qué pasa.
        </li>
      </ul>
      <p class="help" style="margin:0">
        Cupo diario de Google Workspace: unos 2.000 mensajes. Para una convocatoria masiva conviene
        repartirla en varios días o usar un servicio de envío específico.
      </p>
    </div>
  </section>

</div>
