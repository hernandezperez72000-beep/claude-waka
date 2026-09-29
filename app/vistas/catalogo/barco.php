<?php /* LA BARRA DEL BARCO (3h): cuánto lleva el viaje, calculado con las
         fechas. Es una estimación: el estado de la carga es la verdad. */
$v = $viaje ?? ['pct' => 0, 'texto' => '', 'tono' => 'gris'];
$o = trim((string)($lote['puerto_origen'] ?? '')); $de = trim((string)($lote['puerto_destino'] ?? ''));
?>
<div class="barco barco--<?= e($v['tono']) ?>">
  <?php if ($o !== '' || $de !== ''): ?>
    <div class="barco__ruta"><span><?= e($o !== '' ? $o : 'Origen') ?></span><span><?= e($de !== '' ? $de : 'Destino') ?></span></div>
  <?php endif; ?>
  <div class="barco__pista" role="img" aria-label="<?= e($v['texto']) ?>">
    <div class="barco__lleno" style="width:<?= (int)$v['pct'] ?>%"></div>
    <span class="barco__icono" style="left:<?= (int)$v['pct'] ?>%"><?= ico('barco', 34) ?></span>
  </div>
  <div class="barco__txt"><?= e($v['texto']) ?></div>
</div>
