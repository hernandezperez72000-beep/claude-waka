<div class="rejilla rejilla--panel">
  <div class="tarjeta">
    <div class="tarjeta__cab"><h2><?= e($claves[$clave]) ?></h2></div>

    <div class="filtros" style="margin-bottom:12px">
      <?php foreach ($claves as $k => $nombre): ?>
        <a class="<?= $k === $clave ? 'on' : '' ?>"
           href="<?= e(url('/pedidos/mensaje?id=' . (int)$p['id'] . '&p=' . urlencode($k))) ?>"><?= e($nombre) ?></a>
      <?php endforeach; ?>
    </div>

    <?php /* 3d: con el descuento esperando, el total que dice el mensaje todavía
             puede cambiar. Se avisa, no se esconde: el asesor decide. */ ?>
    <?php if (pedido_descuento_pendiente($p)): ?>
      <div class="aviso aviso--amarillo" id="aviso-msg-descuento" style="margin-bottom:12px">
        <span><?= ico('alerta',17) ?></span>
        <span>El descuento de esta venta <strong>todavía no está aprobado</strong>. Si Administración lo
          rechaza, el total sube. Mejor espera su respuesta antes de mandarle el total al cliente.</span>
      </div>
    <?php endif; ?>
    <textarea id="msg" rows="18" style="width:100%;font:13px/1.7 ui-monospace,Menlo,Consolas,monospace;
      border:1.5px solid var(--linea);background:var(--campo);color:var(--tx);border-radius:12px;padding:14px"><?= e($texto) ?></textarea>

    <div class="acciones" style="margin-top:12px">
      <button type="button" class="btn btn--amarillo" id="copiar">COPIAR EL MENSAJE</button>
      <?php if ($wa): ?>
        <a class="btn btn--linea" href="<?= e($wa) ?>" target="_blank" rel="noopener">
          Abrir WhatsApp del cliente</a>
      <?php endif; ?>
      <a class="btn btn--linea" href="<?= e(url('/pedidos/ficha?id=' . (int)$p['id'])) ?>">Volver al pedido</a>
    </div>

    <p class="mini" style="margin-top:12px">
      Puedes editarlo antes de copiar; los cambios no se guardan.
      Las líneas sin dato (como la dirección en un recojo) no salen.
    </p>
  </div>
</div>

<script>
document.getElementById('copiar').addEventListener('click', function () {
  var t = document.getElementById('msg');
  var b = this, antes = b.textContent;
  function ok(m) { b.textContent = m; setTimeout(function () { b.textContent = antes; }, 1800); }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(t.value).then(function () { ok('COPIADO'); })
      .catch(function () { t.select(); ok('SELECCIONADO, COPIA CON CTRL+C'); });
  } else { t.select(); ok('SELECCIONADO, COPIA CON CTRL+C'); }
});
</script>
