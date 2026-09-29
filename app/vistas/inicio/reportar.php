<div class="rejilla rejilla--panel">
  <div class="tarjeta">
    <?php if ($enviado): ?>
      <div class="vacio">
        <span class="vacio__ico marca"><?= ico('check', 24) ?></span>
        <h3>Gracias, ya llegó</h3>
        <p>Administración ya lo tiene. Cuando lo resuelvan, te avisamos por aquí.</p>
        <a class="btn btn--linea" href="<?= e(url('/inicio')) ?>">Volver al inicio</a>
      </div>
    <?php else: ?>
      <?php if ($error): ?><div class="aviso aviso--rojo" style="margin-bottom:14px"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="form">
        <?= campo_csrf() ?>
        <input type="hidden" name="pantalla" value="<?= e($_SERVER['HTTP_REFERER'] ?? '') ?>">
        <label>¿Dónde te pasó?
          <select name="seccion">
            <?php foreach ($secciones as $s): ?>
              <option value="<?= e($s) ?>"><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Cuéntanos qué pasó
          <textarea name="texto" required placeholder="Registré el pedido y el stock no bajó..."><?= e(pedir('texto')) ?></textarea>
          <span class="ayuda">Si puedes, di qué esperabas que pasara y qué pasó en su lugar.
            Con eso se arregla el doble de rápido.</span>
        </label>
        <button class="btn btn--amarillo btn--ancho" type="submit">ENVIAR EL REPORTE</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Esto se envía solo</h2></div>
    <p class="mini" style="margin:0 0 12px">Se adjunta solo, para que administración no tenga que preguntarte.</p>
    <?php
      $ctx = [
        'Quién reporta' => $usuario['nombre'] . ($usuario['equipo'] ? ' · ' . $usuario['equipo'] : ''),
        'Oficina'       => $usuario['oficina'] ?: '—',
        'Equipo'        => mb_substr(navegador(), 0, 40),
        'Fecha y hora'  => fecha_hora(date('Y-m-d H:i:s')),
      ];
      foreach ($ctx as $k => $v): ?>
        <div style="display:flex;justify-content:space-between;gap:12px;padding:5px 0">
          <span class="mini"><?= e($k) ?></span>
          <span class="mini" style="color:var(--tx-2);font-weight:700;text-align:right"><?= e($v) ?></span>
        </div>
    <?php endforeach; ?>
  </div>
</div>
