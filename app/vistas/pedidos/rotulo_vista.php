<?php /* LA VISTA PREVIA DEL RÓTULO (5b, usuario 2026-09-29): se ve cómo queda,
         se elige cuántos bultos lleva y se imprime una hoja A4 con dos rótulos
         por hoja, cada uno con «BULTO 2 DE 3». Lo que se ve aquí sale de los
         mismos bloques que el PDF (rotulo_bloques). */
$pintar = function (array $bl) {
    foreach ($bl as $b) {
        $t = trim((string)($b['texto'] ?? ''));
        switch ($b['tipo'] ?? 'dato') {
            case 'linea':   echo '<hr class="rm__linea">'; break;
            case 'espacio': echo '<div style="height:4px"></div>'; break;
            case 'titulo':  echo '<div class="rm__titulo">' . e($t) . '</div>'; break;
            case 'bulto':   echo '<div class="rm__bulto">' . e($t) . '</div>'; break;
            case 'clave':   echo '<div class="rm__clave">' . e($t) . '</div>'; break;
            case 'grande':  echo '<div class="rm__grande">' . e($t) . '</div>'; break;
            default:        if ($t !== '') echo '<div class="rm__dato">' . e($t) . '</div>';
        }
    }
};
?>
<header class="cabecera">
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e($volver) ?>"><?= ico('atras', 13) ?> Volver</a></div>
    <h1>Rótulo <?= e($codigo) ?></h1>
    <div class="cabecera__sub">Hoja A4 · dos rótulos por hoja · córtala por la línea punteada</div>
  </div>
</header>

<div class="tarjeta rotulo-control" id="rotulo-control">
  <div class="rotulo-control__bultos">
    <span class="rotulo-control__k">¿Cuántos bultos?</span>
    <div class="contador-num">
      <button type="button" class="btn btn--linea" id="bultos-menos" aria-label="Un bulto menos">−</button>
      <input type="text" inputmode="numeric" id="bultos" value="1" maxlength="2" aria-label="Cantidad de bultos">
      <button type="button" class="btn btn--linea" id="bultos-mas" aria-label="Un bulto más">+</button>
    </div>
    <span class="mini" id="bultos-hojas">1 rótulo · 1 hoja</span>
  </div>
  <div class="acciones">
    <a class="btn btn--amarillo" id="rotulo-imprimir" target="_blank" rel="noopener" href="<?= e($pdf) ?>"><?= ico('impresora', 16) ?> IMPRIMIR</a>
    <a class="btn btn--linea" id="rotulo-descargar" href="<?= e($pdf . '&descargar=1') ?>">DESCARGAR PDF</a>
  </div>
</div>

<div class="hojas-a4" id="hojas-a4" data-pdf="<?= e($pdf) ?>">
  <template id="rotulo-molde"><div class="rm"><img class="rm__logo" src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka"><?php $pintar($bloques); ?></div></template>
</div>

<script>
(function () {
  var inp = document.getElementById('bultos'), cont = document.getElementById('hojas-a4');
  var molde = document.getElementById('rotulo-molde'), pdf = cont.getAttribute('data-pdf');
  function n() { return Math.max(1, Math.min(50, parseInt(inp.value, 10) || 1)); }
  function pinta() {
    var k = n(); inp.value = String(k);
    Array.prototype.slice.call(cont.querySelectorAll('.hoja-a4')).forEach(function (h) { h.remove(); });
    for (var i = 1; i <= k; i += 2) {
      var hoja = document.createElement('div'); hoja.className = 'hoja-a4';
      [i, i + 1].forEach(function (j) {
        var celda = document.createElement('div'); celda.className = 'hoja-a4__mitad';
        if (j <= k) {
          var r = molde.content.firstElementChild.cloneNode(true);
          if (k > 1) {
            var b = document.createElement('div'); b.className = 'rm__bulto'; b.textContent = 'BULTO ' + j + ' DE ' + k;
            var t = r.querySelector('.rm__titulo'); if (t) t.insertAdjacentElement('afterend', b); else r.insertBefore(b, r.firstChild);
          }
          celda.appendChild(r);
        } else celda.classList.add('hoja-a4__mitad--vacia');
        hoja.appendChild(celda);
      });
      cont.appendChild(hoja);
    }
    var hojas = Math.ceil(k / 2);
    document.getElementById('bultos-hojas').textContent = k + (k === 1 ? ' rótulo' : ' rótulos') + ' · ' + hojas + (hojas === 1 ? ' hoja' : ' hojas');
    var q = k > 1 ? '&bultos=' + k : '';
    document.getElementById('rotulo-imprimir').href = pdf + q;
    document.getElementById('rotulo-descargar').href = pdf + q + '&descargar=1';
  }
  document.getElementById('bultos-menos').addEventListener('click', function () { inp.value = String(n() - 1); pinta(); });
  document.getElementById('bultos-mas').addEventListener('click', function () { inp.value = String(n() + 1); pinta(); });
  inp.addEventListener('input', function () { if (inp.value !== '') pinta(); });
  inp.addEventListener('blur', pinta);
  pinta();
})();
</script>
