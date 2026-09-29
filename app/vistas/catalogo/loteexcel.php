<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<div class="tarjeta" style="margin-bottom:14px">
  <div class="tarjeta__cab"><h2>El archivo</h2><span class="mini">El Excel de ingresos de carga</span></div>
  <form method="post" enctype="multipart/form-data" class="form" id="form-excel">
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="leer">
    <label>Archivo (.xlsx o .csv)
      <input type="file" name="archivo" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    </label>
    <span class="ayuda">En Google Sheets: Archivo › Descargar › Microsoft Excel. Nada se guarda hasta que elijas un contenedor.</span>
    <div class="acciones"><button class="btn btn--negro" type="submit">LEER EL ARCHIVO</button></div>
  </form>
</div>

<?php foreach ($bloques as $k => $b): ?>
  <div class="tarjeta" style="margin-bottom:12px" id="bloque-<?= (int)$k ?>">
    <div class="tarjeta__cab"><h2><?= e($b['nombre']) ?></h2>
      <span class="mini"><?= count($b['filas']) ?> filas · <?= (int)$b['unidades'] ?> unidades</span></div>
    <div class="lote-tarjeta__datos" style="margin-bottom:8px">
      <?php if (!empty($b['viaje']['fecha_salida'])): ?><span class="mini">Salió <?= e(date('d/m/Y', strtotime($b['viaje']['fecha_salida']))) ?></span><?php endif; ?>
      <?php if (!empty($b['viaje']['fecha_llegada_est'])): ?><span class="mini">Llega <?= e(date('d/m/Y', strtotime($b['viaje']['fecha_llegada_est']))) ?></span><?php endif; ?>
      <?php if (!empty($b['viaje']['canal'])): ?><span class="chip canal canal--<?= e($b['viaje']['canal']) ?>">Canal <?= e(lote_canales()[$b['viaje']['canal']] ?? '') ?></span><?php endif; ?>
      <?php if ($b['revisar'] > 0): ?><span class="chip chip--ambar"><?= plural((int)$b['revisar'], 'fila para revisar', 'filas para revisar') ?></span><?php endif; ?>
    </div>
    <?php foreach ($b['avisos'] as $a): ?><div class="aviso aviso--gris" style="margin-bottom:8px"><span><?= ico('alerta',17) ?></span><span><?= e($a) ?></span></div><?php endforeach; ?>
    <details><summary class="mini" style="cursor:pointer;min-height:34px;display:flex;align-items:center">Ver las filas</summary>
      <div class="tabla__caja"><table class="tabla">
        <thead><tr><th>Código</th><th>Producto</th><th>Modelo / color</th><th class="der">Unidades</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($b['filas'] as $f): ?>
          <tr class="<?= $f['revisa'] ? 'fila--revisa' : '' ?>">
            <td data-k="Código"><?= e($f['codigo'] ?: '—') ?></td>
            <td data-k="Producto"><?= e($f['nombre']) ?><?= $f['nuevo'] ? ' <span class="chip chip--marca">Nuevo</span>' : '' ?>
              <?php if (($f['maquina'] ?? '') !== ''): ?><div class="fila__s">Repuesto de <?= e((string)$f['maquina']) ?></div><?php endif; ?></td>
            <td data-k="Modelo"><?= e($f['modelo'] !== '' ? $f['modelo'] : 'Única') ?></td>
            <td data-k="Unidades" class="der num"><?= (int)$f['unidades'] ?></td>
            <td data-k="Revisa"><?= $f['revisa'] ? '<span class="mini">' . e($f['aviso']) . '</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </details>
    <form method="post" style="margin-top:10px">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="traer">
      <input type="hidden" name="bloque" value="<?= (int)$k ?>">
      <button class="btn btn--amarillo" type="submit">TRAER ESTE LOTE</button>
    </form>
  </div>
<?php endforeach; ?>
