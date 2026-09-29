<div class="sola">
  <div class="sola__caja">
    <img class="sola__logo" src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka Importaciones">

    <div class="tarjeta">
      <?php if ($enviado): ?>
        <h1 style="font-size:20px;margin-bottom:8px">Pedido enviado</h1>
        <?php /* El HUB todavía no manda correos: la solicitud queda anotada y
                 Administración le da una contraseña temporal a la persona. Decir
                 «te llegará un enlace» hacía que se quedara esperando un correo
                 que nadie iba a mandar. */ ?>
        <p class="mini" style="margin:0 0 14px;line-height:1.6">
          Si ese correo tiene cuenta, Administración recibe tu solicitud.
          <strong>Avísale también por WhatsApp</strong> para que la vea antes.
        </p>
        <div class="aviso aviso--gris" style="margin-bottom:14px">
          <span><?= ico('candado', 17) ?></span>
          <span>Nadie ve tu contraseña, ni Administración: te dará una
                <strong>temporal</strong> que cambiarás al entrar.</span>
        </div>
        <a class="btn btn--linea btn--ancho" href="<?= e(url('/entrar')) ?>">Volver a entrar</a>
      <?php else: ?>
        <h1 style="font-size:20px;margin-bottom:4px">Olvidé mi contraseña</h1>
        <p class="mini" style="margin:0 0 16px">
          Escribe tu correo. Administración lo aprueba y te da una contraseña temporal
          que cambias al entrar.
        </p>
        <form method="post" class="form">
          <?= campo_csrf() ?>
          <label>Correo
            <input type="email" name="email" value="<?= e($email) ?>" required autofocus
                   autocomplete="username" inputmode="email">
          </label>
          <button class="btn btn--negro btn--ancho" type="submit">PEDIR EL CAMBIO</button>
        </form>
        <p class="mini" style="text-align:center;margin:14px 0 0">
          <a href="<?= e(url('/entrar')) ?>" style="text-decoration:underline">Volver</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
