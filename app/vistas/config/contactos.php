<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/configuracion')) ?>">Configuración</a></div>
    <h1>Contactos</h1>
    <div class="cabecera__sub">Los WhatsApp de la empresa</div>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?= e($errores[0]) ?></span>
  </div>
<?php endif; ?>

<div class="tarjeta" style="max-width:560px">
  <div class="tarjeta__cab"><h2>WhatsApp de facturación</h2></div>
  <p class="mini" style="margin:0 0 12px">
    El asesor escribe a este número con el botón <strong>HABLAR CON FACTURACIÓN</strong> cuando
    un pago está en espera o denegado. El mensaje ya lleva el código del pedido.
    <strong>Si lo dejas vacío, el botón no aparece.</strong>
  </p>
  <form method="post" class="form">
    <?= campo_csrf() ?>
    <label>Número con código de país
      <input type="text" name="whatsapp_facturacion" maxlength="20" inputmode="numeric"
             value="<?= e($wa) ?>" placeholder="51987654321"
             <?= $puedo_tocar ? '' : 'disabled' ?>>
      <span class="ayuda">Sin el «+» y sin espacios. Un celular peruano queda como
        <strong>51987654321</strong>.</span>
    </label>
    <?php if ($puedo_tocar): ?>
      <button class="btn btn--negro" type="submit" style="margin-top:12px">GUARDAR</button>
    <?php else: ?>
      <span class="chip chip--gris" style="margin-top:12px"><?= ico('ojo',13) ?> Solo miras</span>
    <?php endif; ?>
  </form>
</div>
