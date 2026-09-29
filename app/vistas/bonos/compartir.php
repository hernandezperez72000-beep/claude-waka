<?php /* COMPARTIR MI LOGRO (módulo 5, logros_y_compartir.md). No se comparte
         una captura: se dibuja una imagen de historia (1080 × 1920) con el
         logo, el logro, el nombre y la fecha. COMPARTIR abre el menú del
         teléfono (ahí se elige Instagram, WhatsApp…); GUARDAR IMAGEN la deja
         en la galería. Los montos en soles NO van, salvo que la persona los
         encienda (y Administración puede bloquearlo). Nunca van comisiones,
         metas, clientes ni montos de otros. Se usa con botones que llevan
         `data-logro` (JSON: titulo, dato, monto, fecha, nombre). */
$montos_bloqueado = !empty($montos_bloqueado); ?>
<dialog class="emergente logro" id="logro" aria-labelledby="logro-t">
  <div class="emergente__cab" id="logro-t"><?= ico('trofeo', 20) ?> <strong>Compartir mi logro</strong></div>
  <canvas id="logro-lienzo" width="1080" height="1920" class="logro__lienzo" aria-label="Vista previa de la imagen"></canvas>
  <?php if (!$montos_bloqueado): ?>
    <label class="check"><input type="checkbox" id="logro-montos"> Incluir los montos en soles</label>
  <?php endif; ?>
  <div class="acciones">
    <button type="button" class="btn btn--amarillo" id="logro-compartir">COMPARTIR</button>
    <a class="btn btn--linea" id="logro-guardar" download="mi-logro-waka.png" href="#">GUARDAR IMAGEN</a>
    <button type="button" class="btn btn--linea" id="logro-cerrar">CERRAR</button>
  </div>
  <p class="mini" style="margin:6px 0 0">Se abre el menú de tu teléfono. Ahí eliges Instagram, WhatsApp o lo que uses.</p>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('logro');
  if (!dlg || dlg.dataset.listo) return;
  dlg.dataset.listo = '1';
  var lienzo = document.getElementById('logro-lienzo'), ctx = lienzo.getContext('2d');
  var montos = document.getElementById('logro-montos'), actual = null, archivo = null, vuelta = 0;
  var logo = new Image();
  logo.src = <?= json_encode(url('assets/img/logo-waka.png')) ?>;
  function partir(txt, ancho) {
    var palabras = String(txt || '').split(' '), lineas = [], l = '';
    palabras.forEach(function (p) {
      var prueba = l ? l + ' ' + p : p;
      if (ctx.measureText(prueba).width > ancho && l) { lineas.push(l); l = p; } else l = prueba;
    });
    if (l) lineas.push(l);
    return lineas;
  }
  function dibujar() {
    if (!actual) return;
    var W = 1080, H = 1920;
    ctx.fillStyle = '#000'; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#FAD91A'; ctx.fillRect(0, 0, W, 18); ctx.fillRect(0, H - 18, W, 18);
    if (logo.complete && logo.naturalWidth) {
      var lw = 420, lh = lw * logo.naturalHeight / logo.naturalWidth;
      if (typeof ctx.filter === 'string') {
        ctx.save(); ctx.filter = 'invert(1)'; ctx.drawImage(logo, (W - lw) / 2, 150, lw, lh); ctx.restore();
      } else {
        /* Safari viejo no invierte: el logo va sobre una franja blanca. */
        ctx.fillStyle = '#fff'; ctx.fillRect((W - lw) / 2 - 40, 120, lw + 80, lh + 60);
        ctx.drawImage(logo, (W - lw) / 2, 150, lw, lh);
      }
    }
    ctx.textAlign = 'center';
    ctx.fillStyle = '#FAD91A';
    ctx.font = '800 108px Poppins, system-ui, sans-serif';
    var y = 640;
    partir(actual.titulo, 900).forEach(function (l) { ctx.fillText(l, W / 2, y); y += 124; });
    ctx.fillStyle = '#fff';
    ctx.font = '600 58px Poppins, system-ui, sans-serif';
    y += 40;
    partir(actual.dato, 900).forEach(function (l) { ctx.fillText(l, W / 2, y); y += 72; });
    if (montos && montos.checked && actual.monto) {
      y += 60; ctx.fillStyle = '#FAD91A'; ctx.font = '800 130px Poppins, system-ui, sans-serif';
      ctx.fillText(actual.monto, W / 2, y); y += 40;
    }
    ctx.fillStyle = '#fff'; ctx.font = '700 76px Poppins, system-ui, sans-serif';
    ctx.fillText(actual.nombre || '', W / 2, 1560);
    ctx.fillStyle = '#bbb'; ctx.font = '500 44px Poppins, system-ui, sans-serif';
    ctx.fillText(actual.fecha || '', W / 2, 1640);
    ctx.fillText('Waka Importaciones', W / 2, 1780);
    try { document.getElementById('logro-guardar').href = lienzo.toDataURL('image/png'); } catch (e) {}
    /* El archivo se prepara YA: el menú del teléfono solo se abre si se pide
       en el mismo toque, sin esperar a nada (en iPhone, si no, no abre). */
    archivo = null;
    var esta = ++vuelta;   // solo vale el archivo del ÚLTIMO dibujo (con o sin montos)
    try {
      lienzo.toBlob(function (blob) {
        if (esta === vuelta && blob && window.File) archivo = new File([blob], 'mi-logro-waka.png', {type: 'image/png'});
      }, 'image/png');
    } catch (e) {}
  }
  logo.onload = dibujar;
  if (montos) montos.addEventListener('change', dibujar);
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-logro]'); if (!b) return;
    try { actual = JSON.parse(b.getAttribute('data-logro')); } catch (e) { return; }
    if (montos) montos.checked = false;
    dibujar();
    if (dlg.showModal) dlg.showModal();
  });
  document.getElementById('logro-cerrar').addEventListener('click', function () { dlg.close(); });
  var guardar = document.getElementById('logro-guardar');
  document.getElementById('logro-compartir').addEventListener('click', function () {
    if (archivo && navigator.canShare && navigator.canShare({files: [archivo]})) {
      navigator.share({files: [archivo], title: actual ? actual.titulo : 'Mi logro'}).catch(function (e) {
        /* Cerrar el menú no es un error; si el teléfono no dejó, se guarda la imagen. */
        if (!e || e.name !== 'AbortError') guardar.click();
      });
    } else {
      guardar.click();
    }
  });
})();
</script>
