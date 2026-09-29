<?php
/* Configuración › Facturación electrónica (NUBEFACT). Parche 2u. */
$amb_nombre = nubefact_ambientes()[$amb_edit];
$campo_serie = function (string $campo, string $titulo, string $ej) use ($cfg, $sig, $puedo_tocar) {
    ?>
    <div class="form__fila">
      <label><?= e($titulo) ?>
        <input type="text" name="<?= e($campo) ?>" maxlength="4" style="text-transform:uppercase"
               value="<?= e($cfg[$campo]) ?>" placeholder="<?= e($ej) ?>" <?= $puedo_tocar ? '' : 'disabled' ?>>
      </label>
      <label>Próximo número
        <input type="text" name="sig_<?= e($campo) ?>" inputmode="numeric" maxlength="8"
               value="<?= (int)($sig[$campo] ?? 1) ?>" <?= $puedo_tocar ? '' : 'disabled' ?>>
      </label>
    </div>
    <?php
};
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/configuracion')) ?>">Configuración</a></div>
    <h1>Facturación electrónica</h1>
    <div class="cabecera__sub">Boletas, facturas y notas de crédito con NUBEFACT</div>
  </div>
</header>

<?php if (!$hay_tablas): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
        <span>Falta terminar la actualización para usar esta sección.</span>
  </div>
<?php endif; ?>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div style="display:flex;flex-direction:column;gap:12px">

    <?php /* EN USO. Arriba y con su color: emitir en prueba creyendo que es
             producción —o al revés— es el error que más cuesta ver. */ ?>
    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Se emite en</h2>
        <span class="chip <?= $en_uso === 'produccion' ? 'chip--verde' : 'chip--ambar' ?>">
          <?= e(nubefact_ambientes()[$en_uso]) ?></span></div>
      <p class="mini" style="margin:0 0 10px">
        <?= $en_uso === 'produccion'
            ? 'Los comprobantes van a SUNAT y le llegan al cliente.'
            : 'Modo de prueba: los comprobantes no van a SUNAT. Úsalo para probar antes de empezar.' ?>
      </p>
      <?php if ($puedo_tocar): ?>
        <form method="post" class="acciones">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="usar">
          <?php foreach (nubefact_ambientes() as $k => $t): if ($k === $en_uso) continue; ?>
            <input type="hidden" name="ambiente" value="<?= e($k) ?>">
            <button class="btn btn--linea" type="submit"
                    <?= $k === 'produccion' ? 'data-confirmar="Pasar a producción: desde ahora los comprobantes van a SUNAT, y las boletas de prueba dejan de contar. ¿Seguimos?"' : '' ?>>
              Pasar a <?= e(mb_strtolower($t)) ?></button>
          <?php endforeach; ?>
        </form>
      <?php endif; ?>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Datos de NUBEFACT</h2></div>
      <div class="filtros" style="margin-bottom:12px">
        <?php foreach (nubefact_ambientes() as $k => $t): ?>
          <a class="<?= $amb_edit === $k ? 'on' : '' ?>" href="?amb=<?= e($k) ?>"><?= e($t) ?></a>
        <?php endforeach; ?>
      </div>
      <form method="post" class="form">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="amb" value="<?= e($amb_edit) ?>">
        <label>Ruta (<?= e($amb_nombre) ?>)
          <input type="url" name="ruta" value="<?= e($cfg['ruta']) ?>" placeholder="https://api.nubefact.com/api/v1/…"
                 <?= $puedo_tocar ? '' : 'disabled' ?>>
        </label>
        <label>Token (<?= e($amb_nombre) ?>)
          <?php /* El token NO se repinta: se guarda y se enseñan sus cuatro
                   últimos caracteres. Dejar el campo vacío conserva el que hay. */ ?>
          <input type="password" name="token" autocomplete="off" value=""
                 placeholder="<?= $cfg['token'] !== '' ? e(nubefact_token_tapado($cfg['token'])) . ' · pega uno nuevo para cambiarlo' : 'Pégalo desde tu cuenta de NUBEFACT' ?>"
                 <?= $puedo_tocar ? '' : 'disabled' ?>>
          <span class="ayuda">En NUBEFACT: API (Integración). Se guarda aquí y no se vuelve a mostrar.</span>
        </label>

        <h3 style="font-size:13px;margin:14px 0 4px">Series</h3>
        <?php $campo_serie('serie_boleta', 'Boletas', 'B001'); ?>
        <?php $campo_serie('serie_factura', 'Facturas', 'F001'); ?>
        <?php $campo_serie('serie_nc_boleta', 'Notas de crédito de boletas', 'BC01'); ?>
        <?php $campo_serie('serie_nc_factura', 'Notas de crédito de facturas', 'FC01'); ?>
        <span class="ayuda">Las mismas que tienes creadas en NUBEFACT. Si ya emitiste con una de ellas allá, pon como próximo número el que sigue al último. Solo se puede subir.</span>

        <?php if ($puedo_tocar): ?>
          <div class="acciones" style="margin-top:12px">
            <button class="btn btn--negro" type="submit">GUARDAR</button>
          </div>
        <?php endif; ?>
      </form>
      <?php if ($puedo_tocar && $cfg['ruta'] !== '' && $cfg['token'] !== ''): ?>
        <form method="post" style="margin-top:10px">
          <?= campo_csrf() ?>
          <input type="hidden" name="accion" value="probar">
          <input type="hidden" name="amb" value="<?= e($amb_edit) ?>">
          <button class="btn btn--linea" type="submit">PROBAR LA CONEXIÓN</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Cómo queda</h2></div>
    <p class="mini" style="margin:0 0 8px">Facturación pulsa <strong>Emitir boleta</strong> o
      <strong>Emitir factura</strong> en la venta. Se puede en cuanto hay un pago confirmado.</p>
    <p class="mini" style="margin:0 0 8px">El PDF le llega al cliente a su correo, y queda en el pedido.</p>
    <p class="mini" style="margin:0 0 8px">El cashback usado sale como descuento: el comprobante
      dice lo que el cliente pagó.</p>
    <p class="mini" style="margin:0">Al anular una venta con comprobante se emite su nota de
      crédito. Si la nota no sale, la venta no se anula.</p>
  </div>
</div>
