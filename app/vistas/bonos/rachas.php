<?php /* RACHAS (módulo 5). Tres contadores, no dos: la racha del día (clientes
         distintos de hoy), los días seguidos y —en Mis bonos— los clientes de
         la semana de Sin Freno. */
$dias_cortos = ['L', 'M', 'M', 'J', 'V', 'S', 'D']; ?>
<div class="rejilla rejilla--panel">
  <div>
    <?php if ($es_asesor): $nom = racha_nombre((int)$hoy_n); ?>
      <div class="tarjeta racha-grande <?= $hoy_n >= 4 ? 'racha-grande--negra' : ($hoy_n >= 2 ? 'racha-grande--marca' : '') ?>" id="mi-racha">
        <img class="racha-grande__logo" src="<?= e(url('assets/img/isotipo-waka.png')) ?>" alt="Waka">
        <div class="racha-grande__x">x<?= (int)$hoy_n ?></div>
        <div class="racha-grande__n"><?= $nom !== '' ? e($nom) : ((int)$hoy_n === 1 ? 'Primer cliente de hoy' : 'Hoy arrancas de cero') ?></div>
        <div class="racha-grande__d"><?= plural((int)$hoy_n, 'cliente distinto hoy', 'clientes distintos hoy') ?> · <?= plural((int)$racha['dias'], 'día seguido', 'días seguidos') ?></div>
        <?php if ($hoy_n >= 2): ?>
          <button type="button" class="btn btn--linea btn--chico logro-compartir" data-logro="<?= e(json_encode([
              'titulo' => 'x' . (int)$hoy_n . ' · ' . mb_strtoupper($nom), 'dato' => plural((int)$hoy_n, 'cliente', 'clientes') . ' en un día · ' . plural((int)$racha['dias'], 'día seguido', 'días seguidos'),
              'monto' => '', 'fecha' => fecha_corta(date('Y-m-d')), 'nombre' => (string)$usuario['nombre']], JSON_UNESCAPED_UNICODE)) ?>">Compartir</button>
        <?php endif; ?>
      </div>
      <div class="tarjeta" style="margin-top:12px" id="racha-semana">
        <div class="tarjeta__cab"><h2>Tu semana</h2><span class="mini">Tu mejor marca: <?= plural((int)$racha['mejor'], 'día seguido', 'días seguidos') ?></span></div>
        <div class="semana">
          <?php foreach ($racha['semana'] as $f => $vend): ?>
            <div class="semana__d <?= $vend ? 'semana__d--si' : '' ?> <?= $f === date('Y-m-d') ? 'semana__d--hoy' : '' ?>">
              <span><?= $dias_cortos[(int) date('N', strtotime($f)) - 1] ?></span><span class="semana__n"><?= (int) date('j', strtotime($f)) ?></span></div>
          <?php endforeach; ?>
        </div>
        <p class="mini" style="margin:8px 0 0">Cuentan los siete días, domingos incluidos. Un día sin vender corta la racha. Tu mejor día: <?= plural((int)$mejor_dia, 'cliente', 'clientes') ?>.</p>
      </div>
    <?php endif; ?>
    <div class="tarjeta" style="margin-top:<?= $es_asesor ? '12' : '0' ?>px" id="racha-escalera">
      <div class="tarjeta__cab"><h2>La escalera del día</h2></div>
      <?php foreach ($niveles as $k => $t): $toca = $es_asesor && ((int)$hoy_n === $k || ($k === 5 && (int)$hoy_n > 5)); ?>
        <div class="fila <?= $toca ? 'fila--yo' : '' ?>">
          <span class="chip <?= $k >= 4 ? 'chip--negro' : 'chip--marca' ?>">x<?= $k ?><?= $k === 5 ? '+' : '' ?></span>
          <span class="fila__crece fila__t"><?= e($t) ?></span>
          <?php if ($toca): ?><span class="chip chip--linea">Ahora</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <p class="mini" style="margin:8px 0 0">x3 es el tercer cliente distinto de hoy. Un segundo pedido del mismo cliente no suma. Anular un pedido baja la racha.</p>
    </div>
  </div>
  <div>
    <div class="tarjeta" id="en-racha-ahora">
      <div class="tarjeta__cab"><h2>En racha ahora</h2></div>
      <?php if (!$ahora): ?><p class="mini" style="margin:0">Nadie en racha todavía hoy.</p><?php endif; ?>
      <?php foreach ($ahora as $a): ?>
        <div class="fila">
          <span class="chip <?= $a['n'] >= 4 ? 'chip--negro' : 'chip--marca' ?>">x<?= (int)$a['n'] ?></span>
          <span class="fila__crece fila__t"><?= e(primer_nombre((string)$a['nombre'])) ?><?= (int)$a['id'] === (int)$usuario['id'] ? ' · tú' : '' ?></span>
          <span class="mini"><?= e((string)$a['nivel']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php parte('bonos/compartir', ['montos_bloqueado' => $montos_bloqueado]); ?>
