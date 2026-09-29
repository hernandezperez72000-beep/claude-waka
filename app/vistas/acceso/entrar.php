<div class="sola">
  <div class="sola__caja">
    <img class="sola__logo" src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka Importaciones">

    <?php if ($error): ?>
      <div class="aviso aviso--rojo">
        <span><?= ico('alerta', 17) ?></span>
        <span>
          <?= e($error) ?>
          <?php if ($restantes !== null && $restantes > 0 && $restantes < 5): ?>
            <br><span class="mini" style="color:inherit;opacity:.85">
              Te quedan <?= (int)$restantes ?> <?= $restantes === 1 ? 'intento' : 'intentos' ?>.
              A los 5 la cuenta se bloquea una hora por seguridad.
            </span>
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>

    <div class="tarjeta">
      <h1 style="font-size:21px;margin-bottom:4px">Entrar al HUB</h1>
      <p class="mini" style="margin:0 0 16px">Con el correo que te dio administración.</p>

      <form method="post" class="form">
        <?= campo_csrf() ?>
        <label>Correo
          <input type="email" name="email" value="<?= e($email) ?>" required autofocus
                 autocomplete="username" inputmode="email">
        </label>
        <label>Contraseña
          <input type="password" name="clave" required autocomplete="current-password">
        </label>
        <label class="check">
          <input type="checkbox" name="recordarme" value="1" checked>
          Recordarme en este equipo por 30 días
        </label>
        <button class="btn btn--negro btn--ancho" type="submit">ENTRAR</button>
      </form>

      <p class="mini" style="text-align:center;margin:14px 0 0">
        <a href="<?= e(url('/recuperar')) ?>" style="text-decoration:underline">Olvidé mi contraseña</a>
      </p>
    </div>

    <p class="mini" style="text-align:center">
      Waka Importaciones · Desarrollado por Bloomit.pe
    </p>
  </div>
</div>
