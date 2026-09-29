<?php
$v   = fn(string $k, $d = '') => e((string)($fila[$k] ?? $d));
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
?>
<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span>
      <?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?>
      <?php /* El enlace a la ficha repetida solo si quien mira puede verla:
               si no, este formulario delataría el nombre del cliente del
               asesor de al lado con solo teclear su documento. */ ?>
      <?php if ($repetido && cliente_puedo_ver_ficha($repetido)): ?>
        <div style="margin-top:8px">
          <a class="chip chip--linea" href="<?= e(url('/clientes/ficha?id=' . (int)$repetido['id'])) ?>">
            Abrir la ficha de <?= e(trim((string)$repetido['nombre'] . ' ' . (string)$repetido['apellidos'])) ?>
          </a>
        </div>
      <?php endif; ?>
    </span>
  </div>
<?php endif; ?>

<form method="post" class="form">
  <?= campo_csrf() ?>
  <div class="rejilla rejilla--panel">
    <div style="display:flex;flex-direction:column;gap:12px">

      <fieldset class="bloque">
        <legend>Quién es</legend>
        <div class="form__fila">
          <label>Tipo de documento
            <select name="tipo_doc" required>
              <?php foreach (CLIENTE_TIPOS_DOC as $k => $t): ?>
                <option value="<?= e($k) ?>" <?= $sel($fila['tipo_doc'] ?? 'DNI', $k) ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Número de documento
            <input type="text" name="documento" value="<?= $v('documento') ?>" required
                   inputmode="numeric" autocomplete="off">
            <span class="ayuda">Es su usuario en compraenwaka.</span>
          </label>
        </div>
        <div class="form__fila">
          <label>Nombres <input type="text" name="nombre" value="<?= $v('nombre') ?>" required></label>
          <label>Apellidos <input type="text" name="apellidos" value="<?= $v('apellidos') ?>" required></label>
        </div>
        <div class="form__fila">
          <label>Correo
            <input type="email" name="email" value="<?= $v('email') ?>" required autocomplete="off">
            <span class="ayuda">Obligatorio: sin correo, el cliente no puede ver su Cashback Waka.</span>
          </label>
          <label>Celular
            <input type="tel" name="celular" value="<?= $v('celular') ?>" required inputmode="tel">
            <span class="ayuda">Por aquí se le manda el mensaje de WhatsApp.</span>
          </label>
        </div>
        <div class="form__fila">
          <label>Otro teléfono <input type="tel" name="telefono_alt" value="<?= $v('telefono_alt') ?>" inputmode="tel"></label>
          <label>Cómo nos conoció
            <select name="canal_item_id" required>
              <option value="">Elige una</option>
              <?php foreach ($canales as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $sel($fila['canal_item_id'] ?? '', $c['id']) ?>>
                  <?= e($c['valor']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="ayuda">Cómo llegó la primera vez. En cada pedido se vuelve a elegir.</span>
          </label>
        </div>
      </fieldset>

      <fieldset class="bloque">
        <legend>Facturación</legend>
        <label>Comprobante
          <select name="tipo_comprobante" id="comprobante">
            <option value="boleta"  <?= $sel($fila['tipo_comprobante'] ?? 'boleta','boleta') ?>>Boleta</option>
            <option value="factura" <?= $sel($fila['tipo_comprobante'] ?? '','factura') ?>>Factura</option>
          </select>
          <span class="ayuda">El RUC solo hace falta si pide factura.</span>
        </label>
        <div class="form__fila" id="caja-factura">
          <label>RUC para la factura
            <input type="text" name="ruc_factura" value="<?= $v('ruc_factura') ?>" inputmode="numeric">
          </label>
                    <label>Razón social
            <input type="text" name="razon_social" value="<?= $v('razon_social') ?>">
          </label>
        </div>
        <?php if (columna_existe('clientes', 'direccion_fiscal')): ?>
          <label id="caja-factura-dir">Dirección fiscal
            <input type="text" name="direccion_fiscal" maxlength="160" value="<?= $v('direccion_fiscal') ?>">
            <span class="ayuda">La de la empresa, como figura en SUNAT.</span>
          </label>
        <?php endif; ?>
      </fieldset>

      <fieldset class="bloque">
        <legend>Para acordarse de él</legend>
        <div class="form__fila">
          <label>Rubro
            <input type="text" name="rubro" value="<?= $v('rubro') ?>" placeholder="Bodega, gamer, oficina…">
          </label>
        </div>
        <label>Notas
          <textarea name="notas" rows="3" style="width:100%"><?= $v('notas') ?></textarea>
          <span class="ayuda">Lo que te sirva para la próxima llamada. Lo ve quien vea la ficha.</span>
        </label>
      </fieldset>
    </div>

    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="aviso aviso--gris">
        <span><?= ico('personas',17) ?></span>
        <span><strong>La dirección va en el pedido, no aquí.</strong> Se pide al registrar el
          pedido, y la próxima vez sale la última que usó.</span>
      </div>

      <?php if (!$id): ?>
        <div class="aviso aviso--amarillo">
          <span><?= ico('reloj',17) ?></span>
          <span><strong>Su cuenta en compraenwaka.</strong> Se creará con su documento como
            usuario y le llegará un correo para poner su contraseña. Si ya compró antes por la
            web, se usa la cuenta que ya tiene.</span>
        </div>
      <?php endif; ?>

      <div class="acciones">
        <button class="btn btn--negro" type="submit"><?= $id ? 'GUARDAR CAMBIOS' : 'REGISTRAR CLIENTE' ?></button>
        <a class="btn btn--linea" href="<?= e(url($id ? '/clientes/ficha?id=' . (int)$id : '/clientes')) ?>">Cancelar</a>
      </div>
    </div>
  </div>
</form>

<script>
/* Los campos de factura solo se ven si se pide factura. */
(function () {
  var s = document.getElementById('comprobante');
  var caja = document.getElementById('caja-factura');
  if (!s || !caja) return;
  var dir = document.getElementById('caja-factura-dir');
  function pintar() {
    caja.hidden = s.value !== 'factura';
    if (dir) dir.hidden = s.value !== 'factura';
  }
  s.addEventListener('change', pintar);
  pintar();
})();
</script>
