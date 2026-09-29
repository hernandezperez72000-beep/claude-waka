<?php /* LA PILA DE NOVEDADES AL ABRIR (módulo 5, avisos_al_abrir.md). Una sola
         pila deslizable: primero lo que cerró, después lo de hoy. Nunca dos
         encima. Una sola X, que cierra toda la pila; los puntos dicen cuántas
         quedan; el botón amarillo avanza y en la última dice «A cazar».
         Lo visto se guarda en el servidor (novedades_vistas), una vez.
         WAKACIONES va sola, a pantalla completa, y ese día la pila espera. */
$novedades = $novedades ?? [];
$wakaciones = $wakaciones ?? null;
if (!$novedades && !$wakaciones) return;
$claves = $wakaciones ? [$wakaciones['clave']] : array_column($novedades, 'clave');
?>
<?php if ($wakaciones): ?>
  <div class="wakaciones" id="wakaciones" role="dialog" aria-modal="true" aria-labelledby="wak-t">
    <div class="wakaciones__escena" aria-hidden="true">
      <div class="wakaciones__sol"></div><div class="wakaciones__isla"></div><div class="wakaciones__palma"></div><div class="wakaciones__ola"></div>
    </div>
    <img class="wakaciones__logo" src="<?= e(url('assets/img/logo-waka.png')) ?>" alt="Waka">
    <h1 class="wakaciones__t" id="wak-t">GANASTE UN VIAJE<?= $wakaciones['destino'] !== '' ? ' A ' . e(mb_strtoupper($wakaciones['destino'])) : '' ?></h1>
    <span class="chip chip--negro">Wakaciones</span>
    <div class="wakaciones__trofeo">
      <div class="wakaciones__monto"><?= e(soles_corto((int)$wakaciones['monto'])) ?></div>
      <div class="wakaciones__seis">EN SEIS MESES</div>
      <div class="wakaciones__orgullo">1.º de <?= (int)$wakaciones['de'] ?> · Nadie en Waka llegó más lejos.</div>
    </div>
    <p class="wakaciones__premio"><?= e($wakaciones['premio'] !== '' ? $wakaciones['premio'] : 'Para dos, todo incluido') ?>. Lleva a quien quieras. No diremos nada.</p>
    <div class="acciones" style="justify-content:center">
      <button type="button" class="btn btn--negro logro-compartir" data-logro="<?= e(json_encode(['titulo' => 'Gané un viaje' . ($wakaciones['destino'] !== '' ? ' a ' . $wakaciones['destino'] : ''),
          'dato' => '1.º de ' . (int)$wakaciones['de'] . ' · Nadie en Waka llegó más lejos.', 'monto' => soles_corto((int)$wakaciones['monto']),
          'fecha' => 'Wakaciones · ' . date('Y'), 'nombre' => (string)$wakaciones['nombre']], JSON_UNESCAPED_UNICODE)) ?>">COMPARTIR</button>
      <button type="button" class="btn btn--linea pila-cerrar">CERRAR</button>
    </div>
  </div>
<?php else: ?>
  <div class="pila-nov" id="pila-novedades" role="dialog" aria-modal="true" aria-label="Novedades">
    <div class="pila-nov__caja">
      <div class="pila-nov__cab">
        <div class="pila-nov__puntos" aria-hidden="true"><?php foreach ($novedades as $i => $n): ?><span class="<?= $i === 0 ? 'on' : '' ?>"></span><?php endforeach; ?></div>
        <button type="button" class="pila-nov__x pila-cerrar" aria-label="Cerrar las novedades">×</button>
      </div>
      <div class="pila-nov__tira" id="pila-tira">
        <?php foreach ($novedades as $i => $n): ?>
          <section class="pila-nov__t pila-nov__t--<?= e($n['tono']) ?>" data-i="<?= $i ?>">
            <div class="pila-nov__sub"><?= e((string)$n['sub']) ?></div>
            <h2 class="pila-nov__tit"><?= e((string)$n['titulo']) ?></h2>
            <?php foreach ($n['lineas'] as $l): ?><p class="pila-nov__l"><?= e((string)$l) ?></p><?php endforeach; ?>
            <?php if (!empty($n['logro'])): ?>
              <button type="button" class="btn btn--linea btn--chico logro-compartir" data-logro="<?= e(json_encode($n['logro'], JSON_UNESCAPED_UNICODE)) ?>">Compartir</button>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
      <div class="acciones" style="justify-content:flex-end;margin:0">
        <button type="button" class="btn btn--amarillo" id="pila-sig"><?= count($novedades) > 1 ? 'SIGUIENTE' : 'A CAZAR' ?></button>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php parte('bonos/compartir', ['montos_bloqueado' => $montos_bloqueado ?? false]); ?>
<script>
(function () {
  var caja = document.getElementById('wakaciones') || document.getElementById('pila-novedades');
  if (!caja) return;
  var claves = <?= json_encode(array_values($claves)) ?>, marcado = false;
  function marcar() {
    if (marcado) return; marcado = true;
    var d = new FormData();
    d.append('_t', <?= json_encode(csrf()) ?>);
    claves.forEach(function (c) { d.append('claves[]', c); });
    /* La respuesta se lee entera: sin leerla, la petición queda abierta. */
    try { fetch(<?= json_encode(url('/novedades/vista')) ?>, {method: 'POST', body: d, credentials: 'same-origin'})
            .then(function (r) { return r.text(); }).catch(function () {}); } catch (e) {}
  }
  function cerrar() { marcar(); caja.remove(); document.documentElement.classList.remove('con-pila'); }
  document.documentElement.classList.add('con-pila');
  /* Se da por vista al mostrarla: una sola vez, aunque cierre el navegador. */
  marcar();
  caja.querySelectorAll('.pila-cerrar').forEach(function (b) { b.addEventListener('click', cerrar); });
  var tira = document.getElementById('pila-tira'), sig = document.getElementById('pila-sig');
  if (!tira || !sig) return;
  var n = tira.children.length, puntos = caja.querySelectorAll('.pila-nov__puntos span');
  function actual() { return Math.round(tira.scrollLeft / Math.max(1, tira.clientWidth)); }
  function pintar() {
    var i = actual();
    puntos.forEach(function (p, k) { p.classList.toggle('on', k === i); });
    sig.textContent = i >= n - 1 ? 'A CAZAR' : 'SIGUIENTE';
  }
  tira.addEventListener('scroll', function () { window.requestAnimationFrame(pintar); });
  sig.addEventListener('click', function () {
    var i = actual();
    if (i >= n - 1) { cerrar(); return; }
    tira.scrollTo({left: (i + 1) * tira.clientWidth, behavior: 'smooth'});
  });
})();
</script>
