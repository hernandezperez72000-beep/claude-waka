<?php /* MIS BONOS (módulo 5): si clasifica a cada bono, sin hacer cálculos.
         Arriba, «Métele garra»; después cada bono encendido con su estado,
         lo que lleva y lo que le falta; y al final lo que ya se cerró.
         Un bono que ya no se puede ganar se ve APAGADO, no escondido. */
$chip_de = function (string $frase): string {
    if ($frase === BONO_FRASE_HECHO) return 'chip--verde';
    if ($frase === BONO_FRASE_FUERA) return 'chip--gris';
    if ($frase === BONO_FRASE_POCO)  return 'chip--marca';
    if (str_starts_with($frase, 'Vas') || str_starts_with($frase, 'Van')) return 'chip--ambar';
    return 'chip--linea';
};
$pct = fn($x) => max(0, min(100, (int) round((float)$x)));
?>
<header class="cabecera">
  <div class="crece">
    <h1>Mis bonos</h1>
    <div class="cabecera__sub">Todo por lo cobrado · <?= $nivel > 0 ? e(nivel_texto((int)$usuario['pais_id'], $nivel)) : 'Sin nivel de cuota' ?></div>
  </div>
  <div class="cabecera__acciones">
    <a class="chip chip--linea racha-chip" href="<?= e(url('/bonos/rachas')) ?>" id="chip-racha-bonos">
      <?= ico('rayo', 13) ?> x<?= (int)$racha_hoy ?> · <?= plural((int)$racha['dias'], 'día', 'días') ?></a>
  </div>
</header>

<?php if ($garra): ?>
  <div class="garra" id="metele-garra">
    <span class="garra__t"><?= e($garra['texto']) ?></span>
    <a class="btn btn--negro" href="<?= e(url('/pedidos/nuevo')) ?>">MÉTELE GARRA</a>
  </div>
<?php endif; ?>

<?php if (!$mis): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', ['ico' => 'BO', 'titulo' => 'Todavía no hay bonos encendidos',
          'texto' => 'Cuando Administración los encienda, aquí verás cómo vas en cada uno.']); ?>
  </div>
<?php endif; ?>

<div class="bonos">
<?php foreach ($mis as $m): $b = $m['bono']; $f = $m['fila']; $r = $b['reglas'];
      $fuera = $f && ($f['frase'] ?? '') === BONO_FRASE_FUERA; ?>
  <div class="tarjeta bono <?= $fuera ? 'bono--fuera' : '' ?>" id="bono-<?= e((string)$b['tipo']) ?>">
    <div class="tarjeta__cab">
      <h2><?= e(bono_nombre($b)) ?></h2>
      <?php if ($f && ($f['frase'] ?? '') !== ''): ?><span class="chip <?= $chip_de((string)$f['frase']) ?>"><?= e((string)$f['frase']) ?></span><?php endif; ?>
    </div>
    <div class="bono__per mini"><?= e(ucfirst(bono_periodo_texto((string)$b['periodo'], $m['inicio'], $m['fin']))) ?>
      <?php $dq = bono_dias_quedan($m['fin']); if ($dq > 1): ?>· quedan <?= $dq ?> días<?php elseif ($dq === 1 && $b['periodo'] !== 'dia'): ?>· último día<?php endif; ?></div>

    <?php if ($b['forma'] === 'escalera'): ?>
      <?php if (!$m['ev']['lanzado']): ?>
        <p class="mini" style="margin:8px 0 0">Hoy todavía no hay Cacería. Cuando la lancen, te sale aquí.</p>
      <?php elseif (!$f): ?>
        <p class="mini" style="margin:8px 0 0">La Cacería de hoy es para otro grupo.</p>
      <?php else: $cac = caceria_de((int)$b['pais_id'], $m['inicio']); $esc = $cac['escalera'] ?: (array)($r['escalera'] ?? []); ?>
        <div class="caceria">
          <div class="caceria__t"><?= e((string)$cac['titulo']) ?></div>
          <?php if (trim((string)$cac['frase']) !== ''): ?><div class="caceria__f"><?= e((string)$cac['frase']) ?></div><?php endif; ?>
          <div class="caceria__n"><span class="num"><?= (int)$f['valor'] ?></span> <span class="mini"><?= (int)$f['valor'] === 0 ? 'Hoy arrancas de cero' : plural((int)$f['valor'], 'gestión', 'gestiones', false) ?></span></div>
          <?php if ((int)$f['extra'] > 0): ?><div class="mini"><?= plural((int)$f['extra'], 'venta espera', 'ventas esperan') ?> que confirmen el pago para contar.</div><?php endif; ?>
          <div class="escalera">
            <?php $marcada = false; foreach ($esc as $e): $ok = (int)$f['valor'] >= (int)$e['desde']; $toca = !$ok && !$marcada; if ($toca) $marcada = true; ?>
              <div class="escalera__p <?= $ok ? 'escalera__p--ok' : ($toca ? 'escalera__p--toca' : '') ?>">
                <span><?= (int)$e['desde'] ?> gestiones</span><strong><?= e(soles_corto((int)$e['premio'])) ?></strong></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php elseif (!$f): ?>
      <p class="mini" style="margin:8px 0 0"><?= $b['forma'] === 'equipo' ? 'No estás en ningún equipo: este bono es por equipo.' : 'Todavía no participas.' ?></p>

    <?php elseif ($b['forma'] === 'niveles'): ?>
      <?php if (!empty($f['sin_nivel'])): ?>
        <p class="mini" style="margin:8px 0 0">Todavía no tienes nivel de cuota. Pídeselo a Administración.</p>
      <?php else: $niv = array_values((array)($r['niveles'] ?? [])); ?>
        <div class="bono__cifra"><span class="num"><?= e(soles_corto((int)$f['valor'])) ?></span> <span class="mini">de <?= e(soles_corto((int)$f['objetivo'])) ?></span></div>
        <div class="pista"><div class="pista__llena marca" style="width:<?= $pct($f['pct']) ?>%"></div></div>
        <?php if ($f['falta'] !== ''): ?><p class="bono__falta"><?= e($f['falta']) ?></p><?php endif; ?>
        <div class="escalera escalera--niveles">
          <?php for ($i = (int)$f['nivel'] - 1; $i < count($niv); $i++): $nv = $niv[$i]; $ok = (int)$f['valor'] >= (int)$nv['cuota']; ?>
            <div class="escalera__p <?= $ok ? 'escalera__p--ok' : '' ?> <?= $i === (int)$f['nivel'] - 1 ? 'escalera__p--mio' : '' ?>">
              <span><?= e($nv['nombre']) ?> · <?= e(soles_corto((int)$nv['cuota'])) ?></span><strong><?= e(soles_corto((int)$nv['premio'])) ?></strong></div>
          <?php endfor; ?>
        </div>
        <?php if ((int)($r['rapido_premio'] ?? 0) > 0): ?>
          <div class="mini bono__linea"><?= !empty($f['rapido']) ? '✓ ' : '' ?>Bono rápido +<?= e(soles_corto((int)$r['rapido_premio'])) ?>:
            <?= !empty($f['rapido']) ? 'lo hiciste' : ($f['rapido_hasta'] >= date('Y-m-d') ? 'llega a tu cuota hasta el ' . e(fecha_corta($f['rapido_hasta'])) : 'ya pasaron los primeros ' . (int)$r['rapido_dias'] . ' días') ?></div>
        <?php endif; ?>
        <?php if ((int)($r['duo_premio'] ?? 0) > 0): ?>
          <div class="mini bono__linea"><?= !empty($f['duo']) ? '✓ ' : '' ?>Bono dúo +<?= e(soles_corto((int)$r['duo_premio'])) ?>: cumple tu cuota en las dos quincenas del mes.</div>
        <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($b['forma'] === 'puestos'): $ev = $m['ev']; ?>
      <div class="bono__cifra"><span class="num"><?= e(soles_corto((int)$f['valor'])) ?></span>
        <?php if ((int)($r['clasifica_ops'] ?? 0) > 0): ?><span class="mini">· <?= plural((int)$f['extra'], 'operación', 'operaciones') ?></span><?php endif; ?></div>
      <?php if (empty($f['clasifica'])): ?>
        <div class="pista"><div class="pista__llena marca" style="width:<?= $pct($f['pct']) ?>%"></div></div>
        <p class="bono__falta"><?= e($f['falta']) ?></p>
      <?php else: ?>
        <?php $primero = $ev['orden'][0] ?? null; ?>
        <?php if ($primero !== null && (int)$primero !== (int)$usuario['id']): ?>
          <p class="bono__falta">El 1.º lleva <?= e(soles_corto((int)$ev['filas'][$primero]['valor'])) ?>. <?= e($f['falta']) ?></p>
        <?php elseif ($f['falta'] !== ''): ?><p class="bono__falta"><?= e($f['falta']) ?></p><?php endif; ?>
        <?php if (!empty($f['gana']) && (int)$f['premio'] > 0): ?><p class="mini">Si cierra así: <?= e(soles_corto((int)$f['premio'])) ?></p><?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($r['destino'])): ?><p class="mini bono__linea">Premio: <?= e((string)($r['premio_texto'] ?? 'Un viaje')) ?> a <?= e((string)$r['destino']) ?>. Gana el que más cobre de los que lleguen.</p><?php endif; ?>

    <?php elseif ($b['forma'] === 'primeros'): ?>
      <div class="bono__cifra"><span class="num"><?= (int)$f['valor'] ?></span> <span class="mini">de <?= (int)$f['objetivo'] ?> clientes distintos esta semana</span></div>
      <div class="pista"><div class="pista__llena marca" style="width:<?= $pct($f['pct']) ?>%"></div></div>
      <?php /* Los dos contadores, separados: el del bono y la racha de hoy. */ ?>
      <p class="bono__falta"><?= e($f['falta']) ?> · hoy vas x<?= (int)$racha_hoy ?></p>

    <?php elseif ($b['forma'] === 'equipo'): ?>
      <div class="bono__cifra"><span class="num"><?= e(soles_corto((int)$f['valor'])) ?></span> <span class="mini">de <?= e(soles_corto((int)$f['objetivo'])) ?> · <?= e((string)$f['nombre']) ?></span></div>
      <div class="pista"><div class="pista__llena marca" style="width:<?= $pct($f['pct']) ?>%"></div></div>
      <div class="manada">
        <?php foreach ((array)$f['miembros'] as $mb): $falta = (int)$mb['falta']; ?>
          <div class="manada__m <?= $falta > 0 ? 'manada__m--falta' : '' ?>">
            <span><?= e(primer_nombre((string)$mb['nombre'])) ?><?= (int)$mb['usuario_id'] === (int)$usuario['id'] ? ' · tú' : '' ?></span>
            <span class="num"><?= e(soles_corto((int)$mb['valor'])) ?></span>
            <?php if ($falta > 0): ?><span class="manada__falta">le faltan <?= e(soles_corto($falta)) ?> del mínimo</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($f['falta'] !== ''): ?><p class="bono__falta"><?= e($f['falta']) ?></p><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<?php if ($resultados): ?>
  <div class="tarjeta" style="margin-top:12px" id="bonos-resultados">
    <div class="tarjeta__cab"><h2>Lo que ya se cerró</h2><span class="mini">Con sus cifras del cierre</span></div>
    <?php foreach ($resultados as $x): $d = json_decode((string)$x['detalle'], true) ?: []; $gano = (int)$x['ganado'] === 1; ?>
      <div class="fila fila--cierre">
        <span class="fila__crece">
          <span class="fila__t"><?= e((string)$x['bono_nombre']) ?> · <?= e(bono_periodo_texto((string)$x['periodo'], (string)$x['periodo_inicio'], (string)$x['periodo_fin'])) ?></span>
          <span class="fila__s"><?= e((string)($d['texto'] ?? '')) ?><?= !$gano && ($d['falta'] ?? '') !== '' ? ' · ' . e((string)$d['falta']) : '' ?></span>
        </span>
        <?php if ($gano && (int)$x['premio_centimos'] > 0): ?>
          <span class="chip chip--verde"><?= e(soles_corto((int)$x['premio_centimos'])) ?></span>
          <span class="chip <?= (int)$x['pagado'] === 1 ? 'chip--gris' : 'chip--ambar' ?>"><?= (int)$x['pagado'] === 1 ? 'Pagado' : 'Por pagar' ?></span>
        <?php elseif ($gano): ?>
          <span class="chip chip--verde"><?= $x['puesto'] ? (int)$x['puesto'] . '.º' : 'Ganado' ?></span>
        <?php else: ?>
          <span class="chip chip--gris">No llegó</span>
        <?php endif; ?>
        <?php if ($gano): ?>
          <button type="button" class="chip chip--linea logro-compartir" data-logro="<?= e(json_encode(bono_logro($x, trim((string)$usuario['nombre'])), JSON_UNESCAPED_UNICODE)) ?>">Compartir</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php parte('bonos/compartir', ['montos_bloqueado' => $montos_bloqueado]); ?>
