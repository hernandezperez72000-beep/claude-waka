<?php /* Configuración › Notificaciones (5b): el aviso general. A la izquierda
         se escribe; a la derecha se ve cómo queda en el celular y en la
         computadora, mientras se escribe. */
$v = fn(string $k) => e((string)($_POST[$k] ?? '')); ?>
<header class="cabecera">
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e(url('/configuracion')) ?>">Configuración</a></div>
    <h1>Notificaciones</h1>
    <div class="cabecera__sub">Un aviso para todos: al celular (push) y en una ventana al entrar</div>
  </div>
  <div class="cabecera__acciones">
    <span class="chip chip--linea" title="Personas con el push activado en al menos un equipo"><?= ico('campana', 13) ?>
      <?= (int)$con_push ?> de <?= (int)$activos ?> con el celular activado</span>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="notif-error">
    <span><?= ico('alerta',17) ?></span><span><?= e($errores[0]) ?></span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel notif-conf">
  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Nuevo aviso</h2></div>
    <form method="post" class="form" enctype="multipart/form-data" id="form-aviso">
      <?= campo_csrf() ?>
      <label>Título
        <input type="text" name="titulo" id="aviso-titulo" maxlength="<?= NOTIF_TITULO_MAX ?>" required value="<?= $v('titulo') ?>"
               placeholder="Llegó el contenedor 3">
        <span class="ayuda contador" data-para="aviso-titulo">Máximo <?= NOTIF_TITULO_MAX ?> caracteres: es lo que se lee entero en el celular.</span>
      </label>
      <label>Texto
        <textarea name="texto" id="aviso-texto" rows="3" maxlength="<?= NOTIF_TEXTO_MAX ?>" required
                  placeholder="Mañana a las 9 a. m. hay reunión en la oficina de Lima. No faltes."><?= $v('texto') ?></textarea>
        <span class="ayuda contador" data-para="aviso-texto">Máximo <?= NOTIF_TEXTO_MAX ?> caracteres. En el celular se ven unas dos líneas; el resto, al abrirla.</span>
      </label>
      <label>Imagen <span class="mini">(opcional)</span>
        <input type="file" name="imagen" id="aviso-imagen" accept="image/jpeg,image/png,image/webp">
        <span class="ayuda"><strong>1200 × 600 px</strong> (horizontal, 2:1), JPG, PNG o WebP, <strong>menos de 400 KB</strong>.
          Se ajusta sola a ese ancho. En Android sale grande debajo del texto; en iPhone y en la computadora, en la ventana al entrar.</span>
      </label>
      <div class="form__fila">
        <label>¿A quién le llega?
          <select name="para">
            <?php foreach ($audiencias as $k => $t): ?>
              <option value="<?= e($k) ?>" <?= (string)($_POST['para'] ?? 'todos') === $k ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Al tocarla, abre <span class="mini">(opcional)</span>
          <input type="text" name="enlace" maxlength="120" value="<?= $v('enlace') ?>" placeholder="/preventa">
        </label>
      </div>
      <span class="ayuda">Llega una sola vez a cada persona. A ti no te sale: lo mandaste tú.</span>
      <div class="acciones">
        <button class="btn btn--amarillo" type="submit" data-confirmar="¿Mandar este aviso ahora? No se puede deshacer.">MANDAR EL AVISO</button>
      </div>
    </form>
  </div>

  <div>
    <div class="tarjeta" style="margin-bottom:12px">
      <div class="tarjeta__cab"><h2>Así se ve</h2><span class="mini">Mientras escribes</span></div>
      <div class="notif-muestra">
        <div class="notif-muestra__k">En el celular</div>
        <div class="notif-cel">
          <img class="notif-cel__ico" src="<?= e(url('assets/img/icono-192.png')) ?>" alt="">
          <div class="notif-cel__t"><span class="notif-cel__app">HUB Waka · ahora</span>
            <strong id="muestra-titulo">Título del aviso</strong>
            <span id="muestra-texto">El texto del aviso sale aquí.</span></div>
        </div>
        <img class="notif-cel__img" id="muestra-img" alt="" hidden>
        <div class="notif-muestra__k" style="margin-top:14px">Al entrar, en la computadora</div>
        <div class="notif-pc">
          <img class="emergente__img" id="muestra-img2" alt="" hidden>
          <div class="notif-pc__c"><span class="emergente__chip">Aviso</span>
            <strong class="emergente__titulo" id="muestra-titulo2">Título del aviso</strong>
            <p class="emergente__txt" id="muestra-texto2">El texto del aviso sale aquí.</p>
            <span class="btn btn--amarillo btn--chico">ENTENDIDO</span></div>
        </div>
      </div>
    </div>

    <div class="tarjeta" id="avisos-mandados">
      <div class="tarjeta__cab"><h2>Mandados</h2><span class="mini">Los últimos 20</span></div>
      <?php if (!$recientes): ?>
        <p class="mini" style="margin:0">Todavía no se mandó ningún aviso.</p>
      <?php endif; ?>
      <?php foreach ($recientes as $n): ?>
        <div class="fila" style="padding:10px 0">
          <span class="fila__crece">
            <span class="fila__t"><?= e((string)$n['titulo']) ?></span>
            <span class="fila__s"><?= e((string)$n['texto']) ?></span>
            <span class="fila__s"><?= e(fecha_hora((string)$n['creado_en'])) ?> · <?= e(primer_nombre((string)($n['por_nombre'] ?? ''))) ?></span>
          </span>
          <span class="chip chip--gris" title="Personas que ya lo vieron en el HUB"><?= plural((int)$n['vistas'], 'lo vio', 'lo vieron') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
/* La muestra se pinta mientras se escribe; los contadores dicen cuánto queda. */
(function () {
  var t = document.getElementById('aviso-titulo'), x = document.getElementById('aviso-texto'), im = document.getElementById('aviso-imagen');
  function pinta() {
    var tt = t.value.trim() || 'Título del aviso', xx = x.value.trim() || 'El texto del aviso sale aquí.';
    ['muestra-titulo', 'muestra-titulo2'].forEach(function (id) { document.getElementById(id).textContent = tt; });
    ['muestra-texto', 'muestra-texto2'].forEach(function (id) { document.getElementById(id).textContent = xx; });
    document.querySelectorAll('.contador').forEach(function (c) {
      var el = document.getElementById(c.getAttribute('data-para'));
      var max = parseInt(el.getAttribute('maxlength'), 10), n = el.value.length;
      c.setAttribute('data-quedan', (max - n) + ' de ' + max);
    });
  }
  t.addEventListener('input', pinta); x.addEventListener('input', pinta); pinta();
  im.addEventListener('change', function () {
    var f = im.files && im.files[0];
    ['muestra-img', 'muestra-img2'].forEach(function (id) {
      var i = document.getElementById(id);
      if (!f) { i.hidden = true; i.removeAttribute('src'); return; }
      i.src = URL.createObjectURL(f); i.hidden = false;
    });
    if (f && f.size > 400 * 1024) {
      var a = im.parentNode.querySelector('.ayuda');
      if (a) a.innerHTML = '<strong>Esta imagen pesa ' + Math.round(f.size / 1024) + ' KB.</strong> Se va a comprimir; para que se vea nítida, mejor una de menos de 400 KB y 1200 × 600 px.';
    }
  });
})();
</script>
