<?php /* La pantalla que sale cuando al HUB le falta pasar actualizar.php.
         Existe porque «Por mandar a despacho» es una pestaña fija del celular
         del asesor: si contesta un error 500 se encuentra solo, y una página de
         «Algo se rompió» sin menú ni barra deja a la persona sin salida. */ ?>
<div class="tarjeta">
  <?php $vende = puede('pedidos.crear'); ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><strong>Esta lista todavía no está disponible.</strong>
      No se ha perdido nada<?= $vende ? ': tus ventas y pagos están todos' : '' ?>.</span>
  </div>
  <p class="mini" style="margin:0 0 14px">Avisa a Administración: falta terminar la actualización.
    <?php if ($vende): ?>Mientras tanto puedes seguir registrando ventas y cobrando con normalidad.<?php endif; ?></p>
  <?php /* A quien no ve pedidos (Almacén, 3g), el botón lo lleva al inicio. */ ?>
  <a class="btn btn--negro" href="<?= e(url(puede('pedidos.ver') ? '/pedidos' : '/inicio')) ?>"><?= puede('pedidos.ver') ? 'VOLVER A PEDIDOS' : 'VOLVER AL INICIO' ?></a>
</div>
