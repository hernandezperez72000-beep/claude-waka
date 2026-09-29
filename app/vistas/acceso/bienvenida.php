<div class="bienvenida <?= $oscuro ? 'bienvenida--noche' : 'bienvenida--dia' ?>">
  <img class="bienvenida__logo" src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka Importaciones">

  <div class="bienvenida__medio">
    <div class="bienvenida__fecha"><?= e($fecha) ?></div>
    <div class="bienvenida__saludo"><?= e($saludo) ?></div>
    <div class="bienvenida__frase"><?= e($frase) ?></div>
    <?php if ($linea): ?>
      <div class="bienvenida__linea"><?= e($linea) ?></div>
    <?php endif; ?>
  </div>

  <div class="bienvenida__pie">
    <a class="btn btn--negro bienvenida__btn" href="<?= e(url($destino)) ?>"><?= e($boton) ?></a>
    <?php if ($usuario): ?>
      <p class="mini" style="margin:0;opacity:.65">
        Estás como <?= e($usuario['nombre']) ?>. ·
        <a href="<?= e(url('/salir?t=' . urlencode(csrf()))) ?>" style="text-decoration:underline">No soy yo</a>
      </p>
    <?php endif; ?>
  </div>
</div>
