<?php /* Configuración › Tienda (módulo 3b). */ ?>
<header class="cabecera">
  <div>
    <div class="cabecera__sub"><a href="<?= e(url('/configuracion')) ?>">Configuración</a></div>
    <h1>Tienda</h1>
    <div class="cabecera__sub">La conexión con compraenwaka</div>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<?php if ($otro_pais): ?>
  <div class="aviso aviso--amarillo" style="margin-bottom:14px" id="aviso-otro-pais">
    <span><?= ico('alerta',17) ?></span>
    <span>Esta tienda la conectó <?= e($pais_tienda) ?>. Solo se cambia desde allí.</span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Datos de la tienda</h2>
      <?php if ($probada !== '' && $cfg['listo']): ?>
        <span class="chip chip--verde" id="tienda-estado">Conectada</span>
      <?php elseif ($cfg['listo']): ?>
        <span class="chip chip--ambar" id="tienda-estado">Sin probar</span>
      <?php else: ?>
        <span class="chip chip--gris" id="tienda-estado">Sin conectar</span>
      <?php endif; ?>
    </div>
    <form method="post" class="form" id="form-tienda">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="guardar">
      <label>Dirección de la tienda
        <input type="url" name="ruta" value="<?= e($ruta_vista) ?>" placeholder="https://compraenwaka.com"
               autocomplete="off">
      </label>
      <label>Clave del cliente
        <input type="text" name="key" value="<?= e($cfg['key']) ?>" placeholder="ck_…"
               autocomplete="off" spellcheck="false">
      </label>
      <label>Clave secreta
        <?php /* La secreta NO se repinta: se guarda y se enseñan sus cuatro
                 últimos caracteres. Dejar el campo vacío conserva la que hay. */ ?>
        <input type="password" name="secret" value="" autocomplete="new-password" spellcheck="false"
               placeholder="<?= $cfg['secret'] !== '' ? e(llave_tapada($cfg['secret'])) . ' · pega otra para cambiarla' : 'cs_…' ?>">
        <span class="ayuda">En WooCommerce: Ajustes › Avanzado › API REST, con permiso de solo lectura.
          Se guarda aquí y no se vuelve a mostrar.</span>
      </label>
      <?php if (!$otro_pais): ?>
        <div class="acciones" style="margin-top:12px">
          <button class="btn btn--negro" type="submit">GUARDAR</button>
        </div>
      <?php endif; ?>
    </form>
    <?php if ($cfg['listo'] && !$otro_pais): ?>
      <form method="post" style="margin-top:10px" id="form-probar">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="probar">
        <button class="btn btn--linea" type="submit">PROBAR LA CONEXIÓN</button>
      </form>
    <?php endif; ?>
  </div>

  <?php /* EL CONECTOR (3c): para CAMBIAR la web desde aquí. Las claves de
           arriba solo leen; el conector solo deja cambiar stock, precio,
           nombre y código. */ ?>
  <?php if ($cfg['listo'] && !$otro_pais): ?>
  <div class="tarjeta" id="tarjeta-conector">
    <div class="tarjeta__cab"><h2>Conector</h2>
      <?php if ($conector['clave'] !== '' && $conector_probado !== ''): ?>
        <span class="chip chip--verde" id="conector-estado">Funciona</span>
      <?php elseif ($conector['clave'] !== ''): ?>
        <span class="chip chip--ambar" id="conector-estado">Sin probar</span>
      <?php else: ?>
        <span class="chip chip--gris" id="conector-estado">Sin poner</span>
      <?php endif; ?>
    </div>
    <p class="mini" style="margin:0 0 8px">Para cambiar desde aquí el stock, el precio, el nombre y el código de la web.</p>
    <ol class="mini" style="margin:0 0 10px;padding-left:18px">
      <li><a href="<?= e(url('assets/descargas/waka-hub-conector.zip')) ?>" download id="conector-descargar" style="text-decoration:underline">Descarga el conector</a>.</li>
      <li>En WordPress: «Plugins» › «Añadir nuevo» › «Subir plugin», y actívalo.</li>
      <li>En WooCommerce › HUB Waka pulsa GENERAR CLAVE y pégala aquí.</li>
    </ol>
    <form method="post" class="form" id="form-conector">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="conector">
      <label>Clave del conector
        <input type="password" name="conector_clave" value="" autocomplete="new-password" spellcheck="false"
               placeholder="<?= $conector['clave'] !== '' ? e(llave_tapada($conector['clave'])) . ' · pega otra para cambiarla' : 'La que da WooCommerce › HUB Waka' ?>">
      </label>
      <div class="acciones" style="margin-top:10px"><button class="btn btn--negro" type="submit">GUARDAR</button></div>
    </form>
    <?php if ($conector['clave'] !== ''): ?>
      <form method="post" style="margin-top:10px" id="form-probar-conector">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="probar_conector">
        <button class="btn btn--linea" type="submit">PROBAR EL CONECTOR</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* EL CONTROL DE STOCK (3e): las ventas descuentan de la web. */ ?>
  <?php if ($cfg['listo'] && !$otro_pais): ?>
  <div class="tarjeta" id="tarjeta-control">
    <div class="tarjeta__cab"><h2>Control de stock</h2>
      <span class="chip <?= $control ? 'chip--verde' : 'chip--gris' ?>" id="control-estado"><?= $control ? 'Encendido' : 'Apagado' ?></span>
    </div>
    <?php if ($control): ?>
      <p class="mini" style="margin:0 0 8px">Cada venta de entrega inmediata descuenta de la web al registrarse
        y vuelve si se anula. Lo agotado no se puede vender.</p>
    <?php else: ?>
      <p class="mini" style="margin:0 0 8px">Las ventas no tocan el stock de la web. Apágalo mientras cargas
        o corriges el inventario, o si la tienda se cae.</p>
    <?php endif; ?>
    <?php if ($control && !$conector_listo): ?>
      <div class="aviso aviso--rojo" style="margin:0 0 8px" id="control-sin-conector">
        <span><?= ico('alerta',17) ?></span>
        <span>Falta el conector: lo vendido espera para descontarse hasta que lo pongas.</span>
      </div>
    <?php endif; ?>
    <?php if ($cola_n > 0): ?>
      <p class="mini" style="margin:0 0 8px" id="control-cola"><strong><?= (int)$cola_n ?></strong>
        <?= $cola_n === 1 ? 'movimiento espera' : 'movimientos esperan' ?> a la tienda.
</p>
      <?php if ($puedo_cola): ?><p style="margin:0 0 10px"><a class="btn btn--linea btn--chico" href="<?= e(url('/stock/pendientes')) ?>" id="control-ver-cola">VER LO QUE ESPERA</a></p><?php endif; ?>
    <?php endif; ?>
    <form method="post" id="form-control">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="control">
      <input type="hidden" name="valor" value="<?= $control ? '0' : '1' ?>">
      <button class="btn <?= $control ? 'btn--linea' : 'btn--negro' ?>" type="submit"><?= $control ? 'APAGAR' : 'ENCENDER' ?></button>
      <?php if (!$control && !$conector_listo): ?>
        <span class="ayuda" style="display:block;margin-top:6px">Antes hay que poner y probar el conector.</span>
      <?php endif; ?>
    </form>
    <form method="post" id="form-libre" style="margin-top:12px;border-top:1px solid var(--linea);padding-top:10px">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="libre">
      <input type="hidden" name="valor" value="<?= $libre ? '0' : '1' ?>">
      <p class="mini" style="margin:0 0 8px" id="libre-estado"><?= $libre
        ? 'Se pueden vender productos escritos a mano (que no están en el catálogo). Esos no descuentan de la web.'
        : 'Solo se venden productos del catálogo.' ?></p>
      <button class="btn btn--linea btn--chico" type="submit"><?= $libre ? 'SOLO DEL CATÁLOGO' : 'DEJAR ESCRIBIR A MANO' ?></button>
    </form>
  </div>
  <?php endif; ?>

  <div class="tarjeta">
    <div class="tarjeta__cab"><h2>Cómo queda</h2></div>
    <p class="mini" style="margin:0 0 8px">Con estas claves solo se <strong>lee</strong> la tienda.
      Para cambiarla desde aquí está el conector.</p>
    <p class="mini" style="margin:0 0 8px">Los productos se traen desde
      <?php if ($puedo_importar): ?><a href="<?= e(url('/stock/tienda')) ?>" style="text-decoration:underline">Stock y pre venta › Traer de la tienda</a><?php else: ?>Stock y pre venta › Traer de la tienda<?php endif; ?>.</p>
    <p class="mini" style="margin:0 0 8px">Un producto nuevo entra con el precio normal de la web como único
      tramo. Después, los precios por cantidad y la garantía se ponen aquí: la web no los cambia.</p>
    <?php if (!$otro_pais): ?>
    <form method="post" class="form" id="form-poco" style="margin:8px 0 10px;border-top:1px solid var(--linea);padding-top:10px">
      <?= campo_csrf() ?>
      <input type="hidden" name="accion" value="poco">
      <label>Avisar «quedan pocas» desde
        <input type="number" name="stock_poco" min="0" max="9999" inputmode="numeric" value="<?= (int)$stock_poco ?>" style="max-width:110px">
        <span class="ayuda">Unidades. Con esa cantidad o menos, el stock de la web sale en ámbar.</span>
      </label>
      <div class="acciones"><button class="btn btn--linea btn--chico" type="submit">GUARDAR</button></div>
    </form>
    <?php endif; ?>
    <?php if ($pais_tienda !== ''): ?>
      <p class="mini" style="margin:0" id="pais-tienda">La tienda es de <?= e($pais_tienda) ?>: solo se trae desde allí.</p>
    <?php endif; ?>
  </div>
</div>
