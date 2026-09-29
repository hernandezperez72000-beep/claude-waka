<?php /* Configuración › Métodos de pago (3f). */ ?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/configuracion')) ?>">Configuración</a></div>
    <h1>Métodos de pago</h1>
    <div class="cabecera__sub">Qué pide cada uno al registrar un pago</div>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<div class="metodos" id="lista-metodos">
  <?php foreach ($metodos as $m): ?>
    <form method="post" class="tarjeta metodo<?= (int)$m['activo'] ? '' : ' metodo--apagado' ?>" data-metodo="<?= (int)$m['id'] ?>">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <div class="metodo__cab">
        <input class="metodo__nombre" type="text" name="valor" maxlength="60" required value="<?= e((string)$m['valor']) ?>"
               aria-label="Nombre del método">
        <?php if (!(int)$m['activo']): ?><span class="chip chip--gris">Apagado</span><?php endif; ?>
      </div>
      <div class="metodo__ops">
        <label class="metodo__op"><input type="checkbox" name="pide_comprobante" value="1" <?= (int)($m['pide_comprobante'] ?? 0) ? 'checked' : '' ?>>
          Pide voucher</label>
        <?php if ($hay_dni): ?>
          <label class="metodo__op"><input type="checkbox" name="pide_dni" value="1" <?= (int)($m['pide_dni'] ?? 0) ? 'checked' : '' ?>>
            Pide foto del DNI</label>
        <?php endif; ?>
        <label class="metodo__op"><input type="checkbox" name="activo" value="1" <?= (int)$m['activo'] ? 'checked' : '' ?>>
          Encendido</label>
        <label class="metodo__rec">Recargo
          <span class="metodo__rec__c"><input type="text" name="recargo" inputmode="decimal" maxlength="5"
                 value="<?= (int)($m['recargo_centesimas'] ?? 0) ? e(rtrim(rtrim(number_format((int)$m['recargo_centesimas'] / 100, 2, '.', ''), '0'), '.')) : '' ?>"
                 placeholder="0"> %</span></label>
      </div>
      <div class="metodo__pie">
        <button class="btn btn--linea btn--chico" type="submit">GUARDAR</button>
      </div>
    </form>
  <?php endforeach; ?>
</div>

<form method="post" class="tarjeta" id="form-metodo-nuevo" style="margin-top:14px">
  <?= campo_csrf() ?>
  <input type="hidden" name="accion" value="nuevo">
  <div class="tarjeta__cab"><h2>Añadir un método</h2></div>
  <div class="form__fila" style="align-items:flex-end">
    <label style="flex:1">Nombre
      <input type="text" name="valor" maxlength="60" required placeholder="Transferencia BBVA, Izipay…"></label>
    <button class="btn btn--negro" type="submit">AÑADIR</button>
  </div>
</form>

<p class="mini" style="margin-top:12px">Un método no se borra: se apaga. Apagado deja de salir al registrar
  un pago y los pagos viejos siguen diciendo con qué se pagaron.</p>
