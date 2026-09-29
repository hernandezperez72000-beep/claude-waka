<?php
/**
 * La línea de tiempo de la venta. Se lee de un vistazo y no se toca a mano.
 *
 * Cada hito dice tres cosas —qué, cuándo y quién— porque «Pago confirmado» sin
 * fecha ni nombre no resuelve la pregunta que se hace de verdad cuando algo se
 * atasca, que es «¿quién lo tiene?».
 */
$hitos = pedido_hitos($p);
/* EL COMPROBANTE NO ES UN PASO (3h.1, usuario 2026-09-28): va aparte, como
   indicador. La venta sale y se entrega igual con la boleta pendiente, y en
   la línea se leía como un paso que frenaba. Sale de pedido_hitos() igual,
   para que siga habiendo una sola definición de qué dice. */
$comp_h = null;
foreach ($hitos as $k_h => $h_h) {
    if ($h_h['clave'] === 'comprobante') { $comp_h = $h_h; unset($hitos[$k_h]); }
}
/* La nota de un hito que todavía no pasó se corta por su PRIMERA FRASE, no a
   los noventa caracteres: cortando por caracteres, «Mandado a despacho» decía
   «…Registra el pago; cuando …» y la línea de tiempo se leía rota. Lo largo ya
   está, entero, en la tarjeta de la derecha. */
$nota_corta = function (string $t): string {
    $t = trim($t);
    $p = mb_strpos($t, '. ');
    if ($p !== false && $p < 90) $t = mb_substr($t, 0, $p + 1);
    return mb_strimwidth($t, 0, 72, '…');
};
?>
<ol class="hitos">
  <?php foreach ($hitos as $h): ?>
    <li class="hito<?= $h['hecho'] ? ' hito--ok' : '' ?><?= !empty($h['parcial']) ? ' hito--medio' : '' ?>">
      <span class="hito__punto" aria-hidden="true"></span>
      <span class="hito__t"><?= e($h['titulo']) ?></span>
      <?php if ($h['hecho'] && $h['cuando'] !== ''): ?>
        <span class="hito__s"><?= e(fecha_corta($h['cuando'])) ?>
          <?= $h['quien'] !== '' ? '· ' . e(primer_nombre($h['quien'])) : '' ?></span>
      <?php elseif ($h['nota'] !== ''): ?>
        <span class="hito__s"><?= e($nota_corta((string)$h['nota'])) ?></span>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
</ol>
<?php if ($comp_h && (string)($p['estado'] ?? '') !== 'anulado'): $no_lleva = (string)($p['comprobante_tipo'] ?? '') === 'ninguno'; ?>
  <div class="hito-comp" id="indicador-comprobante">
    <span class="hito-comp__k">Comprobante</span>
    <?php if (!$comp_h['hecho']): ?>
      <span class="chip chip--ambar">Todavía no</span>
    <?php elseif ($no_lleva): ?>
      <span class="chip chip--gris">No lleva</span>
    <?php else: ?>
      <span class="chip chip--verde"><?= ico('check',13) ?> <?= e($comp_h['titulo']) ?><?= $comp_h['nota'] !== '' ? ' · ' . e((string)$comp_h['nota']) : '' ?></span>
      <?php if ($comp_h['cuando'] !== ''): ?><span class="mini"><?= e(fecha_corta($comp_h['cuando'])) ?><?= $comp_h['quien'] !== '' ? ' · ' . e(primer_nombre($comp_h['quien'])) : '' ?></span><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
