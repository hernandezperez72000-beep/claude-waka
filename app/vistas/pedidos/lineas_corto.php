<?php
/* QUÉ LLEVA UNA VENTA, en dos renglones: «1× Silla Gamer» y «rojo · y 2 más».
   Una sola pieza para la lista de pedidos y la bandeja de pagos (3f). */
$lns = $lns ?? [];
?>
<?php if (!$lns): ?><span class="muted">—</span>
<?php else: ?>
  <div class="recorta lineas-corto"><?= (int)$lns[0]['cantidad'] ?>× <?= e((string)$lns[0]['descripcion']) ?></div>
  <div class="fila__s">
    <?= trim((string)$lns[0]['modelo']) !== '' ? e((string)$lns[0]['modelo']) : '' ?>
    <?= count($lns) > 1 ? ' · y ' . (count($lns) - 1) . ' más' : '' ?></div>
<?php endif; ?>
