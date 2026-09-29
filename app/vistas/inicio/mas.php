<header class="cabecera">
  <div><h1>Más</h1><div class="cabecera__sub">Lo que no cabe en la barra de abajo</div></div>
</header>

<a class="tarjeta" href="<?= e(url('/mi-perfil')) ?>" style="display:flex;align-items:center;gap:13px">
  <?= avatar($usuario, 46, true) ?>
  <div class="fila__crece">
    <div style="font-size:15px;font-weight:800"><?= e($usuario['nombre']) ?></div>
    <div class="fila__s"><?= e($usuario['rol_nombre']) ?><?= $usuario['oficina'] ? ' · ' . e($usuario['oficina']) : '' ?></div>
  </div>
  <span class="mini" style="font-weight:700">Mi perfil</span>
</a>

<?php /* Sin secciones no se pinta la tarjeta: a facturación le salía una
         tarjeta blanca vacía entre su perfil y los ajustes. */ ?>
<?php if ($secciones): ?>
<div class="tarjeta">
  <?php foreach ($secciones as $s): ?>
    <a class="fila" href="<?= e($s['url']) ?>">
      <span class="av av--32" style="border-radius:9px"><?= ico($s['icono'], 15) ?></span>
      <span class="fila__crece">
        <span class="fila__t"><?= e($s['texto']) ?></span>
        <?php if (!empty($s['solo_lectura'])): ?>
          <span class="fila__s">Solo miras</span>
        <?php endif; ?>
      </span>
      <?php if (!empty($s['contador'])): ?>
        <span class="chip chip--marca"><?= (int)$s['contador'] ?></span>
      <?php endif; ?>
      <?= ico('flecha', 14) ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tarjeta">
  <a class="fila" href="<?= e(url('/mi-perfil/contrasena')) ?>">
    <?= ico('candado') ?><span class="fila__crece fila__t">Cambiar mi contraseña</span><?= ico('flecha',14) ?>
  </a>
  <form method="post" action="<?= e(url('/mi-perfil/tema')) ?>" class="fila" style="border-bottom:1px solid var(--linea-suave)">
    <?= campo_csrf() ?>
    <input type="hidden" name="volver" value="/mas">
    <?= ico('luna') ?>
    <span class="fila__crece fila__t">Modo noche</span>
    <select name="tema" onchange="this.form.submit()"
            style="border:0;background:transparent;font-weight:600;font-size:12.5px;color:var(--tx-3)">
      <option value="auto"   <?= $usuario['tema']==='auto'   ? 'selected':'' ?>>Automático</option>
      <option value="claro"  <?= $usuario['tema']==='claro'  ? 'selected':'' ?>>Siempre claro</option>
      <option value="oscuro" <?= $usuario['tema']==='oscuro' ? 'selected':'' ?>>Siempre oscuro</option>
    </select>
  </form>
  <a class="fila" href="<?= e(url('/reportar-error')) ?>">
    <?= ico('alerta') ?><span class="fila__crece fila__t">Reportar un error</span><?= ico('flecha',14) ?>
  </a>
  <a class="fila" href="<?= e(url('/salir?t=' . urlencode(csrf()))) ?>" style="color:var(--rojo)">
    <?= ico('salir') ?><span class="fila__crece fila__t" style="color:var(--rojo)">Cerrar sesión</span>
  </a>
</div>

<p class="mini" style="text-align:center;margin-top:20px">
  El modo noche automático se enciende de 7 de la noche a 6 de la mañana.<br>
  Waka Importaciones · Desarrollado por Bloomit.pe
</p>
