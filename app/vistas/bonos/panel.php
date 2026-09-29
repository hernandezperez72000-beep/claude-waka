<?php /* CÓMO VAN LOS BONOS (módulo 5): el seguimiento de Dirección y de
         Administración. Quién va ganando, al que le falta menos, cuánto se va
         a pagar «si todo cierra como va hoy», lo pagado del mes y lo que
         falta pagar. Un bono ya cerrado no cambia. */ ?>
<?php $cb = $caceria_bono ?? null; $qp = !empty($paises) ? '?p=' . (int)$pais : ''; ?>
<?php if (!empty($paises)): ?>
  <div class="filtros" style="margin-bottom:12px">
    <?php foreach ($paises as $pa): ?>
      <a class="<?= (int)$pa['id'] === (int)$pais ? 'on' : '' ?>" href="<?= e(url('/bonos?p=' . (int)$pa['id'])) ?>"><?= e((string)$pa['nombre']) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<div class="caceria-franja" id="franja-caceria">
  <div class="caceria-franja__t">
    <span class="mini">Cacería del Día</span>
    <?php if (!$cb || (int)$cb['activo'] !== 1): ?>
      <strong>Apagada</strong><span class="mini">Se enciende en Configuración › Bonos.</span>
    <?php elseif ($caceria_hoy): ?>
      <strong><?= e((string)$caceria_hoy['titulo']) ?></strong>
      <span class="mini">Lanzada <?= e(hace((string)$caceria_hoy['lanzada_en'])) ?> · <?= e(caceria_destino_texto((string)$caceria_hoy['destino'])) ?> · cuenta hasta medianoche</span>
    <?php else: ?>
      <strong>Todavía no se lanza la de hoy</strong>
    <?php endif; ?>
  </div>
  <?php if (puede('bonos.lanzar') && $cb && (int)$cb['activo'] === 1): ?>
    <a class="btn btn--amarillo" href="<?= e(url('/bonos/caceria')) ?>"><?= $caceria_hoy ? 'CORREGIR EL TEXTO' : 'LANZAR EL BONO DEL DÍA' ?></a>
  <?php endif; ?>
</div>

<div class="rejilla rejilla--panel">
  <div>
    <div class="tarjeta" id="como-van">
      <div class="tarjeta__cab"><h2>Los bonos</h2>
        <?php if (!empty($es_asesor)): ?><a class="chip chip--linea" href="<?= e(url('/bonos?v=mios')) ?>">Mis bonos</a><?php endif; ?></div>
      <div class="tabla__caja">
        <table class="tabla">
          <thead><tr><th>Bono</th><th>Período</th><th>Estado</th><th>Va ganando</th><th>El más cerca</th><th class="der">Se va a pagar</th></tr></thead>
          <tbody>
          <?php foreach ($filas as $x): $b = $x['bono']; $ev = $x['ev']; ?>
            <tr class="<?= $ev ? '' : 'apagada' ?>">
              <td class="principal"><div class="fila__t"><?= e(bono_nombre($b)) ?></div><div class="fila__s"><?= e(bono_resumen($b)) ?></div></td>
              <td data-k="Período"><span class="mini"><?= e(bono_periodo_texto((string)$b['periodo'], $x['inicio'], $x['fin'])) ?></span></td>
              <td data-k="Estado">
                <?php if (!$ev): ?><span class="chip chip--gris">Apagado</span>
                <?php elseif (!$ev['lanzado']): ?><span class="chip chip--gris">Sin lanzar</span>
                <?php elseif ($ev['ganadores'] > 0): ?><span class="chip chip--verde"><?= plural((int)$ev['ganadores'], 'lo hace', 'lo hacen') ?></span>
                <?php else: ?><span class="chip chip--ambar">En curso</span><?php endif; ?>
              </td>
              <td data-k="Va ganando">
                <?php $l = $x['lider']; if ($ev && $l && ((int)$l['valor'] > 0 || !empty($l['gana']))): ?>
                  <div class="fila__t"><?= e(primer_nombre((string)$l['nombre'])) ?><?= !empty($l['puesto']) ? ' · ' . (int)$l['puesto'] . '.º' : '' ?></div>
                  <div class="fila__s"><?= e((string)$l['texto']) ?></div>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td data-k="El más cerca">
                <?php $c = $x['cerca']; if ($ev && $c): ?>
                  <div class="fila__t"><?= e(primer_nombre((string)$c['nombre'])) ?></div>
                  <div class="fila__s"><?= e((string)$c['falta']) ?></div>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td class="der num" data-k="Se va a pagar"><?= $ev ? e(soles_corto((int)$ev['se_paga'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="bono-total" id="bono-total">Si todo cierra como va hoy, se paga <strong><?= e(soles_corto((int)$total)) ?></strong>.</p>
      <p class="mini" style="margin:4px 0 0">Un bono ya cerrado no se puede cambiar: guarda las reglas y las cifras con que se cerró.</p>
    </div>

    <?php if (puede('bonos.pagar')): ?>
      <div class="tarjeta" style="margin-top:12px" id="por-pagar">
        <div class="tarjeta__cab"><h2>Por pagar <span class="chip <?= $por_pagar ? 'chip--ambar' : 'chip--gris' ?>"><?= count($por_pagar) ?></span></h2></div>
        <?php if (!$por_pagar): ?><p class="mini" style="margin:0">No hay premios por pagar.</p><?php endif; ?>
        <?php foreach ($por_pagar as $pp): ?>
          <div class="fila">
            <span class="fila__crece">
              <span class="fila__t"><?= e(trim((string)$pp['u_nombre'] . ' ' . (string)$pp['u_apellidos']) ?: (string)$pp['eq_nombre']) ?></span>
              <span class="fila__s"><?= e((string)$pp['bono_nombre']) ?> · <?= e(fecha_corta((string)$pp['periodo_inicio'])) ?><?= $pp['periodo_fin'] !== $pp['periodo_inicio'] ? ' al ' . e(fecha_corta((string)$pp['periodo_fin'])) : '' ?><?= $pp['puesto'] ? ' · ' . (int)$pp['puesto'] . '.º' : '' ?></span>
            </span>
            <strong class="num"><?= e(soles_corto((int)$pp['premio_centimos'])) ?></strong>
            <form method="post" action="<?= e(url('/bonos/pagar')) ?>" style="display:inline">
              <?= campo_csrf() ?>
              <input type="hidden" name="id" value="<?= (int)$pp['id'] ?>">
              <button class="chip chip--marca" type="submit" style="cursor:pointer">Pagado</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if ($pagados_hoy): ?>
          <div class="mini" style="margin:10px 0 4px">Marcados hoy</div>
          <?php foreach ($pagados_hoy as $ph): ?>
            <div class="fila">
              <span class="fila__crece fila__s"><?= e(trim((string)$ph['u_nombre']) ?: (string)$ph['eq_nombre']) ?> · <?= e((string)$ph['bono_nombre']) ?> · <?= e(soles_corto((int)$ph['premio_centimos'])) ?></span>
              <form method="post" action="<?= e(url('/bonos/pagar')) ?>" style="display:inline">
                <?= campo_csrf() ?>
                <input type="hidden" name="id" value="<?= (int)$ph['id'] ?>">
                <input type="hidden" name="deshacer" value="1">
                <button class="chip chip--suave" type="submit" style="cursor:pointer">Deshacer</button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="tarjeta" id="pagado-mes">
      <div class="tarjeta__cab"><h2>Lo pagado este mes</h2></div>
      <?php if (!$pagado_mes): ?><p class="mini" style="margin:0">Nada todavía.</p><?php endif; ?>
      <?php foreach ($pagado_mes as $nom => $t): ?>
        <div class="dato"><span class="dato__k"><?= e((string)$nom) ?></span><span class="dato__t num"><?= e(soles_corto((int)$t)) ?></span></div>
      <?php endforeach; ?>
      <?php if ($pagado_mes): ?><div class="dato"><span class="dato__k"><strong>Total</strong></span><span class="dato__t num"><strong><?= e(soles_corto(array_sum($pagado_mes))) ?></strong></span></div><?php endif; ?>
    </div>
    <div class="tarjeta" style="margin-top:12px">
      <div class="tarjeta__cab"><h2>Rachas</h2></div>
      <a class="fila" href="<?= e(url('/bonos/rachas')) ?>"><?= ico('rayo') ?><span class="fila__crece fila__t">Quién está en racha hoy</span><?= ico('flecha',14) ?></a>
      <?php if (puede('bonos.configurar')): ?>
        <a class="fila" href="<?= e(url('/configuracion/bonos' . $qp)) ?>"><?= ico('rueda') ?><span class="fila__crece fila__t">Configurar los bonos</span><?= ico('flecha',14) ?></a>
      <?php endif; ?>
    </div>
  </div>
</div>
