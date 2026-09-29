<div class="rejilla rejilla--panel">
  <form method="post" class="form" enctype="multipart/form-data">
    <?= campo_csrf() ?>

    <div class="tarjeta" style="display:flex;align-items:center;gap:16px">
      <?= avatar($u, 96, true) ?>
      <div>
        <div style="font-size:19px;font-weight:800;letter-spacing:-.5px"><?= e(trim($u['nombre'].' '.($u['apellidos'] ?? ''))) ?></div>
        <div class="fila__s"><?= e($u['rol_nombre']) ?><?= $u['oficina'] ? ' · ' . e($u['oficina']) : '' ?><?= $u['equipo'] ? ' · ' . e($u['equipo']) : '' ?></div>
        <div class="fila__s"><?= e($u['email']) ?></div>
      </div>
    </div>

    <fieldset class="bloque">
      <legend>Mis datos</legend>
      <label>Celular <input type="tel" name="celular" value="<?= e($u['celular']) ?>" inputmode="tel"></label>
      <label style="font-size:12.5px;font-weight:700;display:flex;flex-direction:column;gap:6px">
        Cambiar mi foto
        <input type="file" name="foto" accept="image/jpeg,image/png"
               style="border:1.5px dashed var(--linea);background:var(--campo);border-radius:12px;padding:12px;font-size:12px">
        <span class="ayuda">JPG o PNG · cuadrada · mínimo 400 × 400 px · máximo 2 MB.</span>
      </label>
    </fieldset>

    <fieldset class="bloque">
      <legend>Avisos y aspecto</legend>
      <label class="check">
        <input type="checkbox" name="avisos_recepcion" value="1" <?= (int)$u['avisos_recepcion'] ? 'checked' : '' ?>>
        Avisarme cuando llegue un cliente a la oficina
      </label>
      <p class="mini" style="margin:-6px 0 4px">Este aviso suena: hay una persona esperando de pie.</p>
      <label class="check">
        <input type="checkbox" name="avisos_rachas" value="1" <?= (int)$u['avisos_rachas'] ? 'checked' : '' ?>>
        Avisarme de mis rachas y bonos
      </label>
      <p class="mini" style="margin:-6px 0 4px">Este es silencioso: puede esperar a que mires el teléfono.</p>
      <label>Modo noche
        <select name="tema">
          <option value="auto"   <?= $u['tema']==='auto'   ? 'selected':'' ?>>Automático · se enciende de 7 p. m. a 6 a. m.</option>
          <option value="claro"  <?= $u['tema']==='claro'  ? 'selected':'' ?>>Siempre claro</option>
          <option value="oscuro" <?= $u['tema']==='oscuro' ? 'selected':'' ?>>Siempre oscuro</option>
        </select>
      </label>
    </fieldset>

    <div class="acciones">
      <button class="btn btn--negro" type="submit">GUARDAR</button>
      <a class="btn btn--linea" href="<?= e(url('/mi-perfil/contrasena')) ?>">Cambiar mi contraseña</a>
    </div>
  </form>

  <div>
    <div class="tarjeta" style="margin-bottom:12px">
      <div class="tarjeta__cab"><h2>Tu acceso</h2></div>
      <div class="fila"><span class="fila__crece fila__s">Correo</span><span class="fila__t"><?= e($u['email']) ?></span></div>
      <div class="fila"><span class="fila__crece fila__s">Último acceso</span><span class="fila__t"><?= $u['ultimo_acceso'] ? e(fecha_hora($u['ultimo_acceso'])) : '—' ?></span></div>
      <div class="fila"><span class="fila__crece fila__s">Contraseña cambiada</span><span class="fila__t"><?= $u['password_cambiada_en'] ? e(fecha_corta($u['password_cambiada_en'])) : 'nunca' ?></span></div>
      <div class="fila" style="border-bottom:0"><span class="fila__crece fila__s">Ámbito</span>
        <span class="fila__t"><?= e(['propio'=>'Lo tuyo','equipo'=>'Tu equipo','todo'=>'Todo'][$u['ambito']] ?? $u['ambito']) ?></span></div>
    </div>

    <div class="tarjeta">
      <a class="fila" href="<?= e(url('/reportar-error')) ?>"><?= ico('alerta') ?><span class="fila__crece fila__t">Reportar un error</span><?= ico('flecha',14) ?></a>
      <a class="fila" href="<?= e(url('/salir?t=' . urlencode(csrf()))) ?>" style="color:var(--rojo);border-bottom:0">
        <?= ico('salir') ?><span class="fila__crece fila__t" style="color:var(--rojo)">Cerrar sesión</span>
      </a>
    </div>
  </div>
</div>
