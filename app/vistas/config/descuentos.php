<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/configuracion')) ?>">Configuración</a></div>
    <h1>Descuentos</h1>
    <div class="cabecera__sub">Si el descuento del asesor pide permiso, y hasta cuánto</div>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?= e($errores[0]) ?></span>
  </div>
<?php endif; ?>

<div class="tarjeta" style="max-width:560px" id="tarjeta-descuentos">
  <div class="tarjeta__cab"><h2>El descuento del asesor</h2></div>
  <p class="mini" style="margin:0 0 12px">
    El asesor escribe el descuento en soles al registrar la venta, con su motivo. Siempre queda
    anotado en el pedido: cuánto, por qué y quién lo hizo.
  </p>
  <form method="post" class="form">
    <?= campo_csrf() ?>
    <label class="check" style="margin-bottom:12px">
      <input type="checkbox" name="pide" value="1" id="desc-pide" <?= $pide ? 'checked' : '' ?>
             <?= $puedo_tocar ? '' : 'disabled' ?>>
      <span>Pedir aprobación a Administración si pasa del tope</span>
    </label>
    <span class="ayuda" style="margin:-6px 0 12px">Apagado, ningún descuento espera: la venta sale sola.
      Encendido, lo que pasa del tope espera a Administración y la venta no sale a despacho hasta que conteste.</span>
    <label>Tope sin aprobación (%)
      <input type="text" name="tope" maxlength="3" inputmode="numeric" value="<?= e((string)$tope) ?>"
             <?= $puedo_tocar ? '' : 'disabled' ?>>
      <span class="ayuda">Con <strong>0</strong>, todo descuento pide aprobación.</span>
    </label>
    <label class="check" style="margin-top:12px">
      <input type="checkbox" name="con_cashback" value="1" <?= $con_cb ? 'checked' : '' ?>
             <?= $puedo_tocar ? '' : 'disabled' ?>>
      <span>Se puede usar cashback y descuento en la misma venta</span>
    </label>
    <?php if ($puedo_tocar): ?>
      <button class="btn btn--negro" type="submit" style="margin-top:12px">GUARDAR</button>
    <?php else: ?>
      <span class="chip chip--gris" style="margin-top:12px"><?= ico('ojo',13) ?> Solo miras</span>
    <?php endif; ?>
  </form>
</div>
