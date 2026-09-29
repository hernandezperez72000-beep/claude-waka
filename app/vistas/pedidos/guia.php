<?php /* LA GUÍA DE REMISIÓN, EN VISTA PREVIA (5b). Se ve como el documento y
         los campos se pueden corregir antes de mandarla. Lo que se cambie
         queda anotado en la bitácora. */
$ro = $guia ? 'disabled' : '';
$campo = function (string $nombre, string $etq, $valor, array $op = []) use ($ro) {
    $tipo = $op['tipo'] ?? 'text';
    echo '<label class="doc-campo' . (!empty($op['ancho']) ? ' doc-campo--ancho' : '') . '"><span>' . e($etq) . '</span>';
    echo '<input type="' . e($tipo) . '" name="' . e($nombre) . '" value="' . e((string)$valor) . '"'
       . (!empty($op['max']) ? ' maxlength="' . (int)$op['max'] . '"' : '')
       . (!empty($op['num']) ? ' inputmode="numeric"' : '')
       . (!empty($op['dec']) ? ' inputmode="decimal"' : '')
       . (!empty($op['ph']) ? ' placeholder="' . e($op['ph']) . '"' : '')
       . ' ' . $ro . '></label>';
};
?>
<header class="cabecera">
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e($volver) ?>"><?= ico('atras', 13) ?> Volver</a></div>
    <h1>Guía de remisión · <?= e((string)$p['codigo']) ?></h1>
    <div class="cabecera__sub"><?= $guia ? 'Emitida' : 'Vista previa: revisa y corrige lo que haga falta antes de mandarla' ?><?= $prueba ? ' · ambiente de prueba (no va a SUNAT)' : '' ?></div>
  </div>
  <?php if ($guia): ?>
    <div class="cabecera__acciones">
      <?php if (!empty($guia['enlace_pdf'])): ?>
        <a class="btn btn--amarillo" target="_blank" rel="noopener" href="<?= e((string)$guia['enlace_pdf']) ?>"><?= ico('impresora', 16) ?> VER E IMPRIMIR</a>
      <?php else: ?>
        <form method="post"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="accion" value="consultar">
          <button class="btn btn--linea" type="submit">TRAER EL PDF</button></form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="guia-error"><span><?= ico('alerta',17) ?></span><span><?= e($errores[0]) ?></span></div>
<?php endif; ?>
<?php if (!$aplica && !$guia): ?>
  <div class="aviso aviso--gris" style="margin-bottom:14px"><span><?= ico('alerta',17) ?></span>
    <span>Este pedido no lleva guía: solo los envíos a provincia, cuando ya salieron a despacho.</span></div>
<?php elseif (!$activa && !$guia): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px"><span><?= ico('alerta',17) ?></span>
    <span><strong>Falta la serie de la guía.</strong> Se pone en Configuración › Facturación electrónica<?= puede('listas.gestionar') ? '' : ' (pídeselo a Administración)' ?>.
      Mientras tanto, puedes revisar cómo quedaría.</span></div>
<?php endif; ?>
<?php if ($pendiente && !$guia): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px"><span><?= ico('reloj',17) ?></span>
    <span>La guía <?= e($pendiente['serie'] . '-' . (int)$pendiente['numero']) ?> se mandó y no tuvo respuesta. Pulsa MANDAR otra vez: se comprueba si salió, sin duplicarla.</span></div>
<?php endif; ?>

<form method="post" class="documento" id="form-guia">
  <?= campo_csrf() ?>
  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
  <div class="documento__cab">
    <img src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka" class="documento__logo">
    <div class="documento__num">
      <span>GUÍA DE REMISIÓN ELECTRÓNICA · REMITENTE</span>
      <strong><?= $guia ? e($guia['serie'] . '-' . str_pad((string)(int)$guia['numero'], 8, '0', STR_PAD_LEFT)) : e(($serie ?: 'T___') . '-········') ?></strong>
      <?php if (!$guia): ?><em>El número se pone al mandarla</em><?php endif; ?>
    </div>
  </div>

  <div class="documento__bloque">
    <h3>Destinatario</h3>
    <div class="doc-rejilla">
      <?php $campo('dest_nombre', 'Nombre o razón social', $g['dest_nombre'], ['max' => 150, 'ancho' => 1]); ?>
      <?php $campo('dest_doc', $g['dest_tipo_doc'] ?: 'Documento', $g['dest_doc'], ['max' => 12, 'num' => 1]); ?>
    </div>
  </div>

  <div class="documento__bloque">
    <h3>Traslado</h3>
    <div class="doc-rejilla">
      <label class="doc-campo"><span>Motivo</span>
        <select name="motivo" <?= $ro ?>><?php foreach (guia_motivos() as $k => $t): ?><option value="<?= e($k) ?>" <?= $g['motivo'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
      <?php $campo('fecha_traslado', 'Inicio del traslado', $g['fecha_traslado'], ['tipo' => 'date']); ?>
      <?php $campo('bultos', 'Bultos', $g['bultos'], ['max' => 3, 'num' => 1]); ?>
      <?php $campo('peso', 'Peso total (kg)', rtrim(rtrim(number_format((float)$g['peso'], 2, '.', ''), '0'), '.'), ['max' => 9, 'dec' => 1]); ?>
    </div>
  </div>

  <div class="documento__bloque">
    <h3>Transportista (la agencia)</h3>
    <div class="doc-rejilla">
      <?php $campo('transp_nombre', 'Razón social', $g['transp_nombre'], ['max' => 150, 'ancho' => 1]); ?>
      <?php $campo('transp_ruc', 'RUC', $g['transp_ruc'], ['max' => 11, 'num' => 1, 'ph' => '20…']); ?>
    </div>
    <?php if ($g['transp_ruc'] === '' && !$guia): ?><p class="mini" style="margin:6px 0 0">Escribe el RUC de la agencia una vez: la próxima guía con esa agencia ya lo trae.</p><?php endif; ?>
  </div>

  <div class="documento__bloque doc-dos">
    <div>
      <h3>Punto de partida</h3>
      <?php $campo('partida_dir', 'Dirección', $g['partida_dir'], ['max' => 150, 'ancho' => 1]); ?>
      <?php $campo('partida_ubigeo', 'Ubigeo', $g['partida_ubigeo'], ['max' => 6, 'num' => 1, 'ph' => '150101']); ?>
    </div>
    <div>
      <h3>Punto de llegada</h3>
      <?php $campo('llegada_dir', 'Dirección', $g['llegada_dir'], ['max' => 150, 'ancho' => 1]); ?>
      <?php $campo('llegada_ubigeo', 'Ubigeo', $g['llegada_ubigeo'], ['max' => 6, 'num' => 1, 'ph' => '6 números']); ?>
    </div>
  </div>

  <div class="documento__bloque">
    <h3>Lo que se traslada</h3>
    <div class="tabla__caja">
      <table class="tabla tabla--form doc-items">
        <thead><tr><th>Código</th><th>Descripción</th><th class="der">Cantidad</th><th>Unidad</th></tr></thead>
        <tbody>
        <?php foreach ($g['items'] as $k => $it): ?>
          <tr>
            <td data-k="Código" class="mini"><?= e((string)$it['codigo'] ?: '—') ?></td>
            <td data-k="Descripción"><input type="text" name="i_desc[<?= (int)$k ?>]" maxlength="250" value="<?= e((string)$it['descripcion']) ?>" <?= $ro ?>></td>
            <td data-k="Cantidad" class="der"><input type="text" name="i_cant[<?= (int)$k ?>]" inputmode="numeric" maxlength="6" style="max-width:80px" value="<?= (int)$it['cantidad'] ?>" <?= $ro ?>></td>
            <td data-k="Unidad" class="mini">Unidad</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($g['doc_rel'])): ?><p class="mini" style="margin:8px 0 0">Relacionada con <?= $g['doc_rel']['tipo'] === '01' ? 'la factura' : 'la boleta' ?> <?= e($g['doc_rel']['serie'] . '-' . (int)$g['doc_rel']['numero']) ?>.</p><?php endif; ?>
  </div>

  <div class="documento__bloque">
    <?php $campo('observaciones', 'Observaciones', $g['observaciones'], ['max' => 250, 'ancho' => 1]); ?>
  </div>

  <?php if (!$guia && $aplica): ?>
    <div class="acciones documento__pie">
      <button class="btn btn--amarillo" type="submit" <?= $activa ? '' : 'disabled' ?>
              data-confirmar="<?= e('Mandar la guía de remisión' . ($prueba ? ' (de prueba)' : ' a SUNAT') . '. Lo que corregiste queda anotado. ¿Seguimos?') ?>">
        <?= ico('doc', 16) ?> MANDAR LA GUÍA</button>
      <a class="btn btn--linea" href="<?= e($volver) ?>">Ahora no</a>
    </div>
  <?php endif; ?>
</form>
