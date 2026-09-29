<?php /* MODO TEAM (módulo 5). De tu equipo ves personas; de los otros, solo
         el total (y eso lo decide el servidor, no esta vista). */ ?>
<?php if ($mio && $frase !== ''): ?>
  <div class="garra garra--grande" id="grito">
    <span class="garra__t"><strong><?= e($frase) ?></strong><br><?= e($dato) ?><?= $para_pasar !== '' ? ' ' . e($para_pasar) : '' ?></span>
    <?php if ($es_asesor): ?><a class="btn btn--negro" href="<?= e(url('/pedidos/nuevo')) ?>">MÉTELE GARRA</a><?php endif; ?>
  </div>
<?php elseif ($es_asesor && !$ver): ?>
  <div class="aviso aviso--gris" style="margin-bottom:14px"><span><?= ico('personas',17) ?></span>
    <span>No estás en ningún equipo. Pídele a Administración que te ponga en el tuyo.</span></div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div>
    <?php if ($ver && $personas): ?>
      <div class="tarjeta" id="team-personas">
        <div class="tarjeta__cab"><h2>Cómo va cada uno</h2><span class="mini"><?= e((string)($mio['nombre'] ?? '')) ?></span></div>
        <div class="tabla__caja"><table class="tabla">
          <thead><tr><th>#</th><th>Asesor</th><th class="der">Cobrado</th><th class="der">Pedidos</th><th class="der">Vendido</th><th class="der">Meta</th></tr></thead>
          <tbody>
          <?php foreach ($personas as $p): ?>
            <tr class="<?= $p['yo'] ? 'fila--yo' : '' ?>">
              <td class="num" data-k="#"><?= (int)$p['puesto'] ?>.º</td>
              <td class="principal"><div class="fila__t"><?= e(primer_nombre((string)$p['nombre'])) ?><?= $p['yo'] ? ' <span class="chip chip--marca">TÚ</span>' : '' ?></div></td>
              <td class="der num" data-k="Cobrado"><?= e(soles_corto((int)$p['cobrado'])) ?></td>
              <td class="der num" data-k="Pedidos"><?= (int)$p['pedidos'] ?></td>
              <td class="der num" data-k="Vendido"><?= e(soles_corto((int)$p['vendido'])) ?></td>
              <td class="der num" data-k="Meta"><?= (int)$p['avance'] ?> %</td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php if ($mio): ?>
          <div class="dato" style="margin-top:8px"><span class="dato__k">El equipo</span><span class="dato__t num"><?= e(soles_corto((int)$mio['cobrado'])) ?> de <?= e(soles_corto((int)$mio['meta'])) ?></span></div>
          <div class="pista"><div class="pista__llena marca" style="width:<?= $mio['meta'] > 0 ? min(100, (int) round($mio['cobrado'] * 100 / $mio['meta'])) : 0 ?>%"></div></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($manada && $manada['fila']): $mf = $manada['fila']; ?>
      <div class="tarjeta" style="margin-top:12px" id="team-manada">
        <div class="tarjeta__cab"><h2><?= e(bono_nombre($manada['bono'])) ?></h2><span class="chip chip--linea"><?= e((string)$mf['frase']) ?></span></div>
        <div class="mini"><?= e(ucfirst(bono_periodo_texto('quincena', $manada['inicio'], $manada['fin']))) ?> · <?= e((string)$mf['texto']) ?></div>
        <div class="manada">
          <?php foreach ((array)$mf['miembros'] as $mb): ?>
            <div class="manada__m <?= (int)$mb['falta'] > 0 ? 'manada__m--falta' : '' ?>">
              <span><?= e(primer_nombre((string)$mb['nombre'])) ?></span><span class="num"><?= e(soles_corto((int)$mb['valor'])) ?></span>
              <?php if ((int)$mb['falta'] > 0): ?><span class="manada__falta">le faltan <?= e(soles_corto((int)$mb['falta'])) ?> del mínimo</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <div>
    <div class="tarjeta tarjeta--negra" id="team-tabla">
      <div class="tarjeta__cab"><h2>Los equipos</h2><span class="mini">Solo el total</span></div>
      <?php if (!$equipos): ?><p class="mini" style="margin:0">Todavía no hay equipos con asesores.</p><?php endif; ?>
      <?php foreach ($equipos as $eq): $es = (int)$eq['id'] === (int)$ver; ?>
        <?php if ($puede_elegir): ?><a class="team <?= $es ? 'team--mio' : '' ?>" href="<?= e(url('/equipo?e=' . (int)$eq['id'])) ?>"><?php else: ?><div class="team <?= $es ? 'team--mio' : '' ?>"><?php endif; ?>
          <span class="team__p"><?= (int)$eq['puesto'] ?>.º</span>
          <span class="team__n"><?= e((string)$eq['nombre']) ?><?php if ($eq['lider'] !== ''): ?><span class="team__l">★ <?= e(primer_nombre((string)$eq['lider'])) ?></span><?php endif; ?></span>
          <span class="team__v num"><?= e(soles_corto((int)$eq['cobrado'])) ?><span class="team__m">de <?= e(soles_corto((int)$eq['meta'])) ?></span></span>
        <?php if ($puede_elegir): ?></a><?php else: ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>
