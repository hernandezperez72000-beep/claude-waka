<?php
/* EL BOTÓN «MANDAR A DESPACHO» (3h.1, usuario 2026-09-28): un clic y el
   pedido queda en despacho; ya no pasa por la pantalla del mensaje para el
   WhatsApp. Almacén lo ve enseguida en «Por alistar».
   Recibe: $p, $clase (del botón), $html (lo que dice: HTML fijo o ya escapado
   por quien llama), $volver ('ficha'|'lista'|'pordespachar') y, para la
   lista, $volver_q (sus filtros). */
$volver = $volver ?? 'ficha';
?>
<form method="post" action="<?= e(url('/pedidos/despacho')) ?>" class="form-despacho"<?= ($clase ?? '') === '' ? '' : ' style="display:inline"' ?>>
  <?= campo_csrf() ?>
  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
  <input type="hidden" name="primera" value="1">
  <input type="hidden" name="volver" value="<?= e($volver) ?>">
  <?php if (!empty($volver_q)): ?><input type="hidden" name="volver_q" value="<?= e((string)$volver_q) ?>"><?php endif; ?>
  <?php /* EL COSTO DEL ENVÍO QUE QUEDÓ PENDIENTE (3j): se pide aquí mismo,
           al lado del botón, como muy tarde. */ ?>
  <?php if ((int)($p['despacho_veces'] ?? 0) === 0 && pedido_flete_falta($p)): ?>
    <label class="despacho-flete">Costo del envío (S/)
      <input type="text" name="flete" inputmode="decimal" required placeholder="0.00" autocomplete="off">
    </label>
  <?php endif; ?>
  <button class="<?= e($clase ?? 'btn btn--amarillo') ?>" type="submit" data-espera="Mandando…"><?= $html ?? 'MANDAR A DESPACHO' ?></button>
</form>
