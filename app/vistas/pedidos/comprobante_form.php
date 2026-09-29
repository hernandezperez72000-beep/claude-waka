<?php
/**
 * El formulario del comprobante. Vive aparte porque la pantalla de facturar lo
 * usa dos veces: abierto cuando todavía no hay comprobante, y dentro de
 * «Corregirlo» cuando ya lo hay.
 *
 * La sugerencia sale de la FICHA del cliente, no del dedo de quien factura:
 * una factura sin RUC no se rehace, se anula.
 */
$actual = $comp ?: $marcado['tipo'];
?>
<form method="post" action="<?= e(url('/pedidos/comprobante')) ?>" class="form"
      enctype="multipart/form-data">
  <?= campo_csrf() ?>
  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
  <input type="hidden" name="volver" value="facturar">

  <?php /* Las dos primeras salen de comprobantes_que_se_solicitan(), que es LA
           lista: escritas a mano aquí, «Boleta» y «Cliente con DNI» estaban en
           dos sitios y el día que cambiara una palabra se arreglaría uno.
           «No se emite» es de esta pantalla y solo de esta: no es algo que el
           asesor pida, es lo que facturación anota cuando no hay comprobante. */ ?>
  <?php $opciones = [];
        foreach (comprobantes_que_se_solicitan() as $oc_k => $oc) {
            $opciones[] = [$oc_k, $oc['nombre'], $oc['pista']];
        }
        $opciones[] = ['ninguno', comprobante_tipo_texto('ninguno'),
                       'Esta venta no lleva comprobante']; ?>
  <div class="opciones">
    <?php foreach ($opciones as [$val, $tit, $sub]): ?>
      <label class="opcion">
        <input type="radio" name="tipo" value="<?= $val ?>" <?= $actual === $val ? 'checked' : '' ?>>
        <span><strong><?= e($tit) ?></strong>
          <em><?= e($sub) ?><?php /* Y se dice DE DÓNDE sale la marca: «lo pidió
                 el asesor» y «lo que pide su ficha» son dos cosas distintas y
                 antes las dos se leían igual. */ ?><?=
            ($comp === '' && $marcado['tipo'] === $val) ? ' · ' . e($marcado['texto']) : '' ?></em></span>
      </label>
    <?php endforeach; ?>
  </div>

  <div class="form__fila" style="margin-top:10px" data-comprobante>
    <label>Serie
      <input type="text" name="serie" maxlength="10" style="text-transform:uppercase"
             value="<?= e((string)($p['comprobante_serie'] ?? '')) ?>"
             placeholder="<?= $actual === 'factura' ? 'F001' : 'B001' ?>">
    </label>
    <label>Número
      <input type="text" name="numero" maxlength="20" inputmode="numeric"
             value="<?= e((string)($p['comprobante_numero'] ?? '')) ?>" placeholder="00001234">
    </label>
  </div>
  <span class="ayuda">Serie y número son <strong>opcionales</strong>: puedes anotarlos después.</span>

  <?php /* El PDF de Tumifactura, para poder VERLO después sin entrar allí.
           Opcional: el comprobante se anota igual sin archivo, y obligarlo
           frenaría a facturación cuando emite diez seguidas. */ ?>
  <label style="margin-top:12px">Archivo de la boleta o factura
    <input type="file" name="archivo" accept=".pdf,image/jpeg,image/png"></label>
    <span class="ayuda">Opcional. El PDF del comprobante, para abrirlo después desde el pedido.
    <?php if (!empty($p['comprobante_archivo'])): ?>
      <strong>Ya hay uno subido</strong> — si eliges otro, lo reemplaza.
    <?php endif; ?></span>

  <button class="btn btn--negro btn--ancho" type="submit" style="margin-top:10px">
    GUARDAR EL COMPROBANTE</button>
</form>

<script>
/* El ejemplo de la serie cambia con lo elegido: las boletas son B001 y las
   facturas F001, y un ejemplo equivocado es peor que no poner ninguno. */
(function () {
  var caja = document.currentScript.previousElementSibling;
  if (!caja || caja.tagName !== 'FORM') return;
  var serie = caja.querySelector('[name=serie]');
  if (!serie) return;
  caja.addEventListener('change', function (ev) {
    if (!ev.target.matches('[name=tipo]')) return;
    serie.placeholder = ev.target.value === 'factura' ? 'F001' : 'B001';
  });
})();
</script>
