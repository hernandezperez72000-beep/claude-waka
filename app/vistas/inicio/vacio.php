<?php
/** Estado vacío. Regla: nunca una pantalla en blanco. Siempre se dice qué
 *  falta, por qué, y cuál es el siguiente paso que esa persona sí puede dar. */
$ico    = $ico    ?? '·';
$titulo = $titulo ?? 'Todavía no hay nada aquí';
$texto  = $texto  ?? '';
$boton  = $boton  ?? '';
$ruta   = $ruta   ?? '';
$marca  = $marca  ?? false;
$pie    = $pie    ?? '';
?>
<div class="vacio">
  <span class="vacio__ico <?= $marca ? 'marca' : '' ?>"><?= e($ico) ?></span>
  <h3><?= e($titulo) ?></h3>
  <?php if ($texto): ?><p><?= e($texto) ?></p><?php endif; ?>
  <?php if ($boton && $ruta): ?>
    <a class="btn <?= $marca ? 'btn--negro' : 'btn--linea' ?>" href="<?= e(url($ruta)) ?>"><?= e($boton) ?></a>
  <?php endif; ?>
  <?php if ($pie): ?><span class="mini"><?= e($pie) ?></span><?php endif; ?>
</div>
