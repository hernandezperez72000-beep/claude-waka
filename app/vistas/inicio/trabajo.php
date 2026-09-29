<?php /** Inicio de Almacén y Marketing (3g): lo suyo de hoy y sus secciones. Sin cifras de dinero. */
$total_bandeja = 0; foreach ($bandeja as $b) $total_bandeja += (int)$b['n'];
$por_alistar  = $por_alistar ?? null;
$sin_precio   = $sin_precio ?? null;
?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><?= e($fecha) ?></div>
    <h1><?= e($saludo) ?></h1>
    <?php if ($frase): ?><div class="cabecera__sub"><?= e($frase) ?></div><?php endif; ?>
    <?php if ($linea): ?><div class="cabecera__sub"><?= e($linea) ?></div><?php endif; ?>
  </div>
  <div class="cabecera__acciones">
    <span class="chip chip--linea"><?= e($usuario['pais'] ?? 'Perú') ?></span>
  </div>
</header>

<?php if (!empty($cumplen)): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px">
    <span><?= ico('medalla',17) ?></span>
    <span><strong>Hoy cumple años
      <?= e(implode(' y ', array_map(fn($c) => trim((string)$c['nombre'] . ' ' . (string)$c['apellidos']), $cumplen))) ?>.</strong>
      Un saludo del equipo se agradece.</span>
  </div>
<?php endif; ?>

<?php if ($bandeja): ?>
  <div class="tira" style="margin-bottom:14px">
    <div>
      <div class="tira__k">Tu bandeja</div>
      <div class="tira__t"><?= plural($total_bandeja, 'cosa espera por ti', 'cosas esperan por ti') ?></div>
    </div>
    <div class="tira__chips">
      <?php foreach ($bandeja as $b): ?>
        <a class="tira__chip <?= $b['urgente'] ? 'urgente' : '' ?>" href="<?= e(url($b['ruta'])) ?>">
          <?= (int)$b['n'] ?> <?= e($b['texto']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($por_alistar !== null || $sin_precio !== null): ?>
<div class="rejilla rejilla--2" style="margin-bottom:14px" id="hoy-trabajo">
  <?php if ($por_alistar !== null): ?>
    <a class="cifra" href="<?= e(url('/pedidos/por-alistar')) ?>" style="color:inherit" id="cifra-alistar">
      <span class="cifra__k">Por alistar</span>
      <span class="cifra__v<?= $por_alistar > 0 ? ' ambar' : '' ?>"><?= (int)$por_alistar ?></span>
      <span class="cifra__d"><?= $por_alistar > 0 ? 'Listos para preparar' : 'Nada por preparar' ?></span>
    </a>
  <?php endif; ?>
  <?php if ($sin_precio !== null): ?>
    <a class="cifra" href="<?= e(url('/stock?v=sin_precio')) ?>" style="color:inherit" id="cifra-sin-precio">
      <span class="cifra__k">Productos sin precio</span>
      <span class="cifra__v<?= $sin_precio > 0 ? ' ambar' : '' ?>"><?= (int)$sin_precio ?></span>
      <span class="cifra__d"><?= $sin_precio > 0 ? 'No se pueden vender todavía' : 'Todos se pueden vender' ?></span>
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="tarjeta">
  <div class="tarjeta__cab"><h2>Tus secciones</h2></div>
  <?php foreach ($atajos as $m): ?>
    <a class="fila" href="<?= e($m['url']) ?>"><?= ico($m['icono']) ?>
      <span class="fila__crece fila__t"><?= e($m['texto']) ?></span>
      <?php if (!empty($m['solo_lectura'])): ?><span class="chip chip--gris">Solo miras</span><?php endif; ?>
      <?= ico('flecha',14) ?></a>
  <?php endforeach; ?>
  <a class="fila" href="<?= e(url('/reportar-error')) ?>"><?= ico('alerta') ?><span class="fila__crece fila__t">Reportar un error</span><?= ico('flecha',14) ?></a>
</div>
