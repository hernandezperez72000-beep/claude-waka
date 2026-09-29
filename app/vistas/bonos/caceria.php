<?php /* LANZAR LA CACERÍA DEL DÍA (módulo 5). A la derecha, la vista previa
         exacta de lo que verá el asesor, con el contador en 0. */
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$titulo = $post ? (string) pedir('titulo') : (string)($ya['titulo'] ?? 'La Cacería del Día');
$frase = $post ? (string) pedir('frase') : (string)$frase_sugerida;
$bloq = $ya ? 'disabled' : '';
?>
<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="caceria-errores">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>
<?php if ((int)$bono['activo'] !== 1): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px"><span><?= ico('alerta',17) ?></span>
    <span>La Cacería del Día está apagada. Se enciende en <a href="<?= e(url('/configuracion/bonos')) ?>" style="text-decoration:underline">Configuración › Bonos</a>.</span></div>
<?php endif; ?>
<?php if ($ya): ?>
  <div class="aviso aviso--verde" style="margin-bottom:14px"><span><?= ico('check',17) ?></span>
    <span>La de hoy ya se lanzó <?= e(hace((string)$ya['lanzada_en'])) ?>. Puedes corregir el título y la frase; la escalera y a quién le llega ya no cambian.</span></div>
<?php endif; ?>
<div class="rejilla rejilla--panel">
  <form method="post" class="tarjeta form" id="form-caceria">
    <?= campo_csrf() ?>
    <h3 class="bono-sub">1 · Título y frase</h3>
    <label>Título <input type="text" name="titulo" maxlength="120" required value="<?= e($titulo) ?>" id="c-titulo"></label>
    <label>Frase <input type="text" name="frase" maxlength="240" value="<?= e($frase) ?>" id="c-frase"></label>
    <?php if (count($frases) > 1): ?>
      <div class="acciones" style="margin-top:0"><button type="button" class="btn btn--linea btn--chico" id="otra-frase" data-frases="<?= e(json_encode($frases, JSON_UNESCAPED_UNICODE)) ?>">Otra frase</button></div>
    <?php endif; ?>
    <h3 class="bono-sub">2 · La escalera de hoy</h3>
    <p class="mini" style="margin:0 0 6px">Una gestión es una venta de stock de hoy con el pago confirmado. Cambiarla aquí vale solo por hoy.</p>
    <div class="tabla__caja"><table class="tabla tabla--form" id="c-escalera">
      <thead><tr><th>Desde (gestiones)</th><th>Premio (S/)</th></tr></thead><tbody>
      <?php for ($i = 0; $i < max(4, count($escalera)); $i++): $e = $escalera[$i] ?? null; ?>
        <tr><td data-k="Desde"><input type="number" name="e_desde[]" min="1" value="<?= $e ? (int)$e['desde'] : '' ?>" style="max-width:100px" <?= $bloq ?>></td>
            <td data-k="Premio"><input type="text" name="e_premio[]" inputmode="decimal" value="<?= $e ? e(soles((int)$e['premio'], false)) : '' ?>" style="max-width:120px" <?= $bloq ?>></td></tr>
      <?php endfor; ?>
    </tbody></table></div>
    <h3 class="bono-sub">3 · A quién le llega</h3>
    <label>Para <select name="destino" <?= $bloq ?>>
      <option value="todos">Todos (<?= (int)$cuantos ?>)</option>
      <?php foreach ($oficinas as $o): ?><option value="oficina:<?= (int)$o['id'] ?>" <?= ($ya['destino'] ?? '') === 'oficina:' . (int)$o['id'] ? 'selected' : '' ?>>Oficina <?= e((string)$o['nombre']) ?></option><?php endforeach; ?>
      <?php foreach ($equipos as $q): ?><option value="equipo:<?= (int)$q['id'] ?>" <?= ($ya['destino'] ?? '') === 'equipo:' . (int)$q['id'] ? 'selected' : '' ?>><?= e((string)$q['nombre']) ?></option><?php endforeach; ?>
    </select></label>
    <div class="acciones"><button class="btn btn--amarillo" type="submit" <?= (int)$bono['activo'] !== 1 ? 'disabled' : '' ?>><?= $ya ? 'GUARDAR' : 'LANZAR LA CACERÍA' ?></button></div>
    <p class="mini" style="margin:6px 0 0">Les sale al entrar, sin sonido. Cierra sola a medianoche y el resultado sale al día siguiente.</p>
  </form>
  <div class="tarjeta" id="c-previa">
    <div class="tarjeta__cab"><h2>Lo que verá el asesor</h2></div>
    <div class="caceria">
      <div class="caceria__t" id="p-titulo"><?= e($titulo) ?></div>
      <div class="caceria__f" id="p-frase"><?= e($frase) ?></div>
      <div class="caceria__n"><span class="num">0</span> <span class="mini">Hoy arrancas de cero</span></div>
      <div class="escalera" id="p-escalera">
        <?php foreach ($escalera as $k => $e): ?>
          <div class="escalera__p <?= $k === 0 ? 'escalera__p--toca' : '' ?>"><span><?= (int)$e['desde'] ?> gestiones</span><strong><?= e(soles_corto((int)$e['premio'])) ?></strong></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var t = document.getElementById('c-titulo'), f = document.getElementById('c-frase');
  var pt = document.getElementById('p-titulo'), pf = document.getElementById('p-frase');
  if (t) t.addEventListener('input', function () { pt.textContent = t.value; });
  if (f) f.addEventListener('input', function () { pf.textContent = f.value; });
  var otra = document.getElementById('otra-frase');
  if (otra) {
    var lista = JSON.parse(otra.getAttribute('data-frases') || '[]'), i = Math.max(0, lista.indexOf(f.value));
    otra.addEventListener('click', function () { i = (i + 1) % lista.length; f.value = lista[i]; pf.textContent = f.value; });
  }
  var tb = document.getElementById('c-escalera'), pe = document.getElementById('p-escalera');
  if (tb && pe) tb.addEventListener('input', function () {
    pe.innerHTML = '';
    var primero = true;
    Array.prototype.forEach.call(tb.tBodies[0].rows, function (r) {
      var d = r.querySelector('[name^=e_desde]').value, p = r.querySelector('[name^=e_premio]').value;
      if (!d) return;
      var div = document.createElement('div'); div.className = 'escalera__p' + (primero ? ' escalera__p--toca' : ''); primero = false;
      var s = document.createElement('span'); s.textContent = d + ' gestiones';
      var st = document.createElement('strong'); st.textContent = 'S/ ' + (p || '0');
      div.appendChild(s); div.appendChild(st); pe.appendChild(div);
    });
  });
})();
</script>
