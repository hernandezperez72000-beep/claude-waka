<?php
/* LA CASILLA «¿ES REPUESTO?» DE UNA FILA DEL LOTE (3j). Antes era un campo
   «Repuesto de» donde había que escribir el código de la máquina, y no se
   entendía (usuario, 2026-09-28). Ahora: una casilla y, al marcarla, se elige
   la máquina de una lista (las del lote y las del catálogo, del molde
   #maquinas-molde). Lo que viaja al servidor sigue siendo el código de la
   máquina, en f_maquina[], una vez por fila: las columnas van por posición.
   $f: la fila (o null si es nueva) · $bloq: la fila tiene ventas. */
$maq     = $f ? (string)($f['maquina'] ?? '') : '';
$colgado = $f && $maq === '' && !empty($f['producto_padre']);
$es      = $maq !== '' || $colgado;
$nombre  = $f ? (string)($f['maquina_nombre'] ?? '') : '';
?>
<input type="hidden" name="f_maquina[]" value="<?= e($maq) ?>" class="maq-valor">
<?php if (isset($con_repuestos) && !$con_repuestos): ?>
  <span class="mini">—</span>
<?php elseif ($colgado): ?>
  <span class="chip chip--gris">Repuesto</span>
  <?php if ($nombre !== ''): ?><div class="fila__s">de <?= e($nombre) ?></div><?php endif; ?>
<?php elseif ($bloq): ?>
  <?php if ($es): ?><span class="chip chip--gris">Repuesto</span>
    <div class="fila__s">de <?= e($nombre !== '' ? $nombre : $maq) ?></div>
  <?php else: ?><span class="mini">No</span><?php endif; ?>
<?php else: ?>
  <label class="check"><input type="checkbox" class="maq-es" <?= $es ? 'checked' : '' ?>> Es repuesto</label>
  <select class="maq-elige" aria-label="¿De qué máquina?" data-actual="<?= e($maq) ?>"
          data-actual-nombre="<?= e($nombre) ?>" <?= $es ? '' : 'hidden' ?>></select>
<?php endif; ?>
