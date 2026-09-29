<?php
/**
 * UN ARCHIVO DEL PAGO: el voucher o la foto del DNI (3f). Una sola pieza para
 * los cuatro sitios —la venta nueva y el pago desde la ficha, cada uno con
 * sus dos fotos—: escrita cuatro veces, una se quedaría sin la cámara.
 *
 * DOS BOTONES. «TOMAR FOTO» abre la cámara del celular directamente
 * (`capture`); «ELEGIR ARCHIVO» abre la galería o los archivos (también un
 * PDF). En muchos Android, un solo campo que acepta PDF no ofrece la cámara:
 * por eso son dos. Llegan al servidor como `campo_cam` y `campo`, y
 * voucher_del_formulario() toma el que venga.
 *
 * $campo   'voucher' | 'foto_dni'
 * $titulo  «Voucher», «Foto del DNI»
 * $ayuda   la línea de abajo
 * $pend    el archivo en espera de un intento anterior (voucher_pendiente), o null
 * $oculto  empieza escondido (la foto del DNI, hasta que el método la pida)
 */
$oculto = !empty($oculto);
/* $solo_foto (3g, lo alistado): sin PDF. $id_extra: para que dos campos de la
   misma página (una tarjeta por pedido) no repitan el id. */
$solo_foto = !empty($solo_foto);
$id_extra  = (string)($id_extra ?? '');
?>
<div class="foto-campo" data-foto-campo="<?= e($campo) ?>" id="fc-<?= e($campo) ?><?= $id_extra !== '' ? '-' . e($id_extra) : '' ?>"<?= $oculto ? ' hidden' : '' ?>>
  <span class="foto-campo__t"><?= e($titulo) ?></span>
  <div class="foto-campo__b">
    <label class="btn btn--linea btn--chico foto-campo__btn">
      <?= ico('camara', 15) ?> TOMAR FOTO
      <input class="foto-campo__in" type="file" name="<?= e($campo) ?>_cam" accept="image/*" capture="environment">
    </label>
    <label class="btn btn--linea btn--chico foto-campo__btn">
      ELEGIR ARCHIVO
      <input class="foto-campo__in" type="file" name="<?= e($campo) ?>" accept="<?= $solo_foto ? 'image/jpeg,image/png' : 'image/jpeg,image/png,application/pdf' ?>">
    </label>
  </div>
  <span class="foto-campo__n" aria-live="polite"></span>
  <span class="ayuda"><?= e($ayuda) ?></span>
  <?php if (!empty($pend)): ?>
    <span class="voucher-tengo">
      <span class="voucher-tengo__n">Adjunto: <?= e((string)$pend['nombre']) ?></span>
      <span class="voucher-tengo__a">Toma otra foto o elige otro archivo para reemplazarlo</span>
      <label class="voucher-tengo__q">
        <input type="checkbox" name="<?= e($campo) ?>_quitar" value="1"> Quitarlo
      </label>
    </span>
  <?php endif; ?>
</div>
