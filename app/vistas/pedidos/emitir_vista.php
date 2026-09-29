<?php /* LA BOLETA O LA FACTURA EN VISTA PREVIA (5b, usuario 2026-09-29): se ve
         como va a salir, se corrige el texto (nombre, dirección, correo, la
         descripción de cada línea, la observación) y se manda. Los montos no
         se tocan: el comprobante dice lo que el cliente pagó. Lo corregido
         queda anotado en la bitácora. */
$p = $pv['p']; $c = $pv['cliente']; $m = $pv['montos'];
$nombre_tipo = $tipo === 'factura' ? 'FACTURA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA';
$prueba = nubefact_ambiente() === 'prueba';
$bloqueo = (string)($pv['bloqueo'] ?? '');
$num = fn(string $x) => 'S/ ' . number_format((float)$x, 2, '.', ',');
?>
<header class="cabecera">
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e($url_volver) ?>"><?= ico('atras', 13) ?> Volver</a></div>
    <h1>Emitir <?= e($tipo) ?> · <?= e((string)$p['codigo']) ?></h1>
    <div class="cabecera__sub">Vista previa: revisa y corrige el texto antes de mandarla<?= $prueba ? ' · ambiente de prueba (no va a SUNAT)' : '' ?></div>
  </div>
</header>

<?php if ($bloqueo !== ''): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px"><span><?= ico('alerta',17) ?></span><span><?= e($bloqueo) ?></span></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/pedidos/emitir')) ?>" class="documento" id="form-emitir">
  <?= campo_csrf() ?>
  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
  <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
  <input type="hidden" name="volver" value="<?= e($volver) ?>">
  <input type="hidden" name="previa" value="1">
  <div class="documento__cab">
    <img src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka" class="documento__logo">
    <div class="documento__num">
      <span><?= e($nombre_tipo) ?></span>
      <strong><?= e(($pv['serie'] ?: '____') . '-········') ?></strong>
      <em>El número se pone al mandarla</em>
    </div>
  </div>

  <div class="documento__bloque">
    <h3>Cliente</h3>
    <div class="doc-rejilla">
      <label class="doc-campo doc-campo--ancho"><span><?= $tipo === 'factura' ? 'Razón social' : 'Nombre' ?></span>
        <input type="text" name="c_nombre" maxlength="100" value="<?= e((string)$c['nombre']) ?>"></label>
      <label class="doc-campo"><span><?= $tipo === 'factura' ? 'RUC' : 'Documento' ?></span>
        <input type="text" value="<?= e((string)$c['doc']) ?>" disabled title="El documento sale de la ficha del cliente"></label>
      <label class="doc-campo doc-campo--ancho"><span><?= $tipo === 'factura' ? 'Dirección fiscal' : 'Dirección' ?></span>
        <input type="text" name="c_direccion" maxlength="100" value="<?= e((string)$c['direccion'] === '-' ? '' : (string)$c['direccion']) ?>" placeholder="Opcional en una boleta"></label>
      <label class="doc-campo"><span>Correo (le llega el PDF)</span>
        <input type="email" name="c_email" maxlength="120" value="<?= e((string)$c['email']) ?>"></label>
    </div>
    <p class="mini" style="margin:6px 0 0">El documento se corrige en la ficha del cliente: aquí solo el texto.</p>
  </div>

  <div class="documento__bloque">
    <h3>Detalle</h3>
    <div class="tabla__caja">
      <table class="tabla tabla--form doc-items">
        <thead><tr><th class="der">Cant.</th><th>Descripción</th><th class="der">P. unitario</th><th class="der">Importe</th></tr></thead>
        <tbody>
        <?php foreach ($m['items'] as $k => $it): ?>
          <tr>
            <td data-k="Cant." class="der num"><?= (int)$it['cantidad'] ?></td>
            <td data-k="Descripción"><input type="text" name="i_desc[<?= (int)$k ?>]" maxlength="250" value="<?= e((string)$it['descripcion']) ?>"></td>
            <td data-k="P. unitario" class="der num"><?= e($num((string)$it['precio_unitario'])) ?></td>
            <td data-k="Importe" class="der num"><?= e($num((string)$it['total'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="doc-totales">
      <?php if ((int)$m['descuento_global'] > 0): ?><div><span>Descuento (sin IGV)</span><strong>− <?= e(soles((int)$m['descuento_global'])) ?></strong></div><?php endif; ?>
      <div><span>Op. gravada</span><strong><?= e(soles((int)$m['total_gravada'])) ?></strong></div>
      <div><span>IGV</span><strong><?= e(soles((int)$m['total_igv'])) ?></strong></div>
      <div class="doc-totales__total"><span>Total</span><strong><?= e(soles((int)$m['total'])) ?></strong></div>
    </div>
  </div>

  <div class="documento__bloque">
    <label class="doc-campo doc-campo--ancho"><span>Observaciones</span>
      <input type="text" name="observaciones" maxlength="250" value="<?= e('Pedido ' . (string)$p['codigo']) ?>"></label>
  </div>

  <div class="acciones documento__pie">
    <button class="btn btn--amarillo" type="submit" <?= $bloqueo !== '' ? 'disabled' : '' ?>
            data-confirmar="<?= e(nubefact_confirmar_texto($tipo === 'factura' ? 'Factura' : 'Boleta', (int)$m['total'])) ?>">
      <?= ico('doc', 16) ?> MANDAR <?= e(mb_strtoupper($tipo)) ?></button>
    <a class="btn btn--linea" href="<?= e($url_volver) ?>">Ahora no</a>
  </div>
</form>
