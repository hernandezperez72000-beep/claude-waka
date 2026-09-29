<?php
/** Sección todavía no construida. Se avisa con claridad: no se rompe ni se
 *  deja una pantalla en blanco. */
?>
<header class="cabecera">
  <div><h1><?= e($seccion) ?></h1><div class="cabecera__sub">Muy pronto</div></div>
</header>

<div class="tarjeta">
  <div class="vacio">
    <span class="vacio__ico"><?= ico('reloj', 24) ?></span>
    <h3>Esta sección todavía no está lista</h3>
    <p><strong><?= e($seccion) ?></strong> llega pronto. Mientras tanto, usa el resto del menú con normalidad.</p>
    <a class="btn btn--linea" href="<?= e(url('/inicio')) ?>">Volver al inicio</a>
  </div>
</div>
