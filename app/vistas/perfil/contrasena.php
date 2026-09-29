<div class="sola" style="min-height:auto;padding:0">
  <div class="sola__caja">

    <?php if ($obligatorio): ?>
      <img class="sola__logo" src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka Importaciones">
      <div class="aviso aviso--amarillo">
        <span><?= ico('candado',17) ?></span>
        <span><strong>Entraste con una contraseña temporal.</strong> Elige la tuya para continuar:
          desde ahora nadie más la va a conocer, tampoco administración.</span>
      </div>
    <?php endif; ?>

    <?php if ($errores): ?>
      <div class="aviso aviso--rojo">
        <span><?= ico('alerta',17) ?></span>
        <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
      </div>
    <?php endif; ?>

    <div class="tarjeta">
      <form method="post" class="form" id="fcontra">
        <?= campo_csrf() ?>
        <label>Tu contraseña de ahora
          <input type="password" name="actual" required autocomplete="current-password" autofocus>
        </label>
        <label>La nueva
          <input type="password" name="nueva" id="nueva" required autocomplete="new-password">
        </label>
        <label>Repite la nueva
          <input type="password" name="nueva2" id="nueva2" required autocomplete="new-password">
          <span class="ayuda" id="coinciden"></span>
        </label>

        <div style="background:var(--suave);border-radius:12px;padding:13px 15px">
          <div style="font-size:12.5px;font-weight:800;margin-bottom:8px">Qué necesita tu contraseña</div>
          <div class="regla" data-r="largo"><span class="regla__i">·</span> Al menos 10 caracteres</div>
          <div class="regla" data-r="caso"><span class="regla__i">·</span> Una mayúscula y una minúscula</div>
          <div class="regla" data-r="num"><span class="regla__i">·</span> Un número</div>
        </div>

        <button class="btn btn--amarillo btn--ancho" type="submit">GUARDAR</button>
      </form>
    </div>

    <div class="aviso aviso--gris">
      <span><?= ico('candado',17) ?></span>
      <span>Nadie ve tu contraseña, ni administración. Si la olvidas, usa «Olvidé mi contraseña»
        al entrar. Al cambiarla, los equipos donde marcaste «Recordarme» te pedirán entrar de nuevo.</span>
    </div>
  </div>
</div>

<style>
  .regla { display:flex; align-items:center; gap:9px; font-size:12px; color:var(--tx-3); padding:3px 0 }
  .regla__i { width:18px;height:18px;border-radius:999px;background:var(--tarjeta);
              display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800 }
  .regla.ok { color:var(--tx-2) }
  .regla.ok .regla__i { background:var(--verde-bg); color:var(--verde) }
</style>
<script>
(function () {
  var n = document.getElementById('nueva'), n2 = document.getElementById('nueva2'),
      av = document.getElementById('coinciden');
  function marca(r, ok) {
    var el = document.querySelector('.regla[data-r="' + r + '"]'); if (!el) return;
    el.classList.toggle('ok', ok);
    el.querySelector('.regla__i').textContent = ok ? '✓' : '·';
  }
  function revisar() {
    var v = n.value;
    marca('largo', v.length >= 10);
    marca('caso', /[a-záéíóúñ]/.test(v) && /[A-ZÁÉÍÓÚÑ]/.test(v));
    marca('num',  /\d/.test(v));
    if (!n2.value) { av.textContent = ''; av.style.color = ''; return; }
    var igual = n.value === n2.value;
    av.textContent = igual ? 'Coinciden' : 'Todavía no coinciden';
    av.style.color = igual ? 'var(--verde)' : 'var(--rojo)';
  }
  n.addEventListener('input', revisar); n2.addEventListener('input', revisar);
})();
</script>
