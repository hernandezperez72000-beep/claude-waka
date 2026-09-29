<div class="tabla__caja">
  <?php foreach ($secciones as $s): ?>
    <?php $tag = $s['listo'] ? 'a' : 'div'; ?>
    <<?= $tag ?> class="fila" style="padding:13px 16px<?= $s['listo'] ? '' : ';opacity:.55' ?>"
        <?= $s['listo'] ? 'href="' . e(url($s['ruta'])) . '"' : '' ?>>
      <span class="av av--32" style="border-radius:9px;font-size:10px"><?= e($s['i']) ?></span>
      <span class="fila__crece">
        <span class="fila__t"><?= e($s['n']) ?></span>
        <span class="fila__s"><?= e($s['d']) ?></span>
      </span>
      <?php if ($s['chip']): ?><span class="chip chip--marca"><?= (int)$s['chip'] ?></span><?php endif; ?>
      <?php /* La flecha solo donde lleva a algún sitio: con ella, las filas
               «Pronto» se leían como pantallas que no abren. */ ?>
      <?php if (!$s['listo']): ?><span class="chip chip--gris">Pronto</span><?php endif; ?>
      <?= $s['listo'] ? ico('flecha', 14) : '' ?>
    </<?= $tag ?>>
  <?php endforeach; ?>
</div>


