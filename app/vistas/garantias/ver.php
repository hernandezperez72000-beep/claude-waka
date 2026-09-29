<?php /* UNA GARANTÍA (3i). Ver nucleo/garantias.php. */
$est = (string)$g['estado'];
$chip = match ($est) { 'pedida' => 'ambar', 'aprobada' => 'verde', 'denegada' => 'rojo', default => 'gris' };
$pedida = $est === 'pedida'; ?>
<header class="cabecera">
  <div class="crece">
    <div class="cabecera__sub"><a href="<?= e(url('/garantias')) ?>">Garantías</a></div>
    <h1><?= e(garantia_codigo((int)$g['id'])) ?> <span class="chip chip--<?= $chip ?>" id="garantia-estado"><?= e(garantia_estados()[$est] ?? '') ?></span>
      <?php if ((int)$g['fuera_plazo'] === 1): ?><span class="chip chip--rojo" id="fuera-plazo">Fuera de plazo</span><?php endif; ?></h1>
    <div class="cabecera__sub"><?= e(trim((string)$g['cliente_nombre'] . ' ' . (string)$g['cliente_apellidos'])) ?>
      · <a href="<?= e(url('/pedidos/ficha?id=' . (int)$g['pedido_id'])) ?>" style="text-decoration:underline"><?= e((string)$g['pedido_codigo']) ?></a></div>
  </div>
</header>

<?php if ($errores): ?>
  <div class="aviso aviso--rojo" style="margin-bottom:14px" id="garantia-error">
    <span><?= ico('alerta',17) ?></span>
    <span><?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?></span>
  </div>
<?php endif; ?>

<?php if ($est === 'denegada' || $est === 'anulada'): ?>
  <div class="aviso aviso--<?= $est === 'denegada' ? 'rojo' : 'gris' ?>" style="margin-bottom:14px">
    <span><?= ico('alerta',17) ?></span>
    <span><strong><?= $est === 'denegada' ? 'No se aprobó' : 'Se anuló' ?>.</strong> <?= e((string)$g['nota']) ?></span>
  </div>
<?php elseif ($est === 'aprobada'): ?>
  <div class="aviso aviso--<?= $g['alistado_en'] ? 'gris' : 'amarillo' ?>" style="margin-bottom:14px" id="garantia-salida">
    <span><?= ico('caja',17) ?></span>
    <span><?php if ($g['alistado_en']): ?><strong>Salió del almacén.</strong> Alistada por <?= e(lista_texto((int)$g['alistado_quien_id'])) ?> · <?= e(fecha_hora((string)$g['alistado_en'])) ?>
        · <a href="<?= e(url('/garantias/foto?g=' . (int)$g['id'] . '&alistado=1')) ?>" target="_blank" rel="noopener" style="text-decoration:underline">VER FOTO</a>
      <?php else: ?><strong>Aprobada: está en «Por alistar».</strong> Sale sin cobrar nada<?= $p ? ', como su pedido: ' . e(mb_strtolower(pedido_como_recibe($p))) : '' ?>.<?php endif; ?>
      <?php if (trim((string)$g['nota']) !== ''): ?><br>Nota: <?= e((string)$g['nota']) ?><?php endif; ?></span>
  </div>
<?php endif; ?>

<div class="rejilla rejilla--panel">
  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="tarjeta" id="garantia-piezas">
      <div class="tarjeta__cab"><h2>Piezas</h2><span class="mini">Salen sin cobrar</span></div>
      <?php if (!$lineas): ?><p class="mini" style="margin:0">El asesor no sabe qué pieza es.<?= $resuelvo && $pedida ? ' Añádela abajo.' : '' ?></p><?php endif; ?>
      <?php foreach ($lineas as $l): ?>
        <div class="fila">
          <span class="fila__crece"><span class="fila__t"><?= (int)$l['cantidad'] ?> × <?= e((string)$l['nombre']) ?></span>
            <span class="fila__s"><?= e((string)$l['sku']) ?><?= ($l['maquina_nombre'] ?? '') !== '' ? ' · de ' . e((string)$l['maquina_nombre']) : '' ?><?= (int)$l['anadida'] === 1 ? ' · la añadió Administración' : '' ?></span></span>
          <?php if ($pedida && $l['stock']['texto'] !== ''): ?><span class="chip chip--<?= e($l['stock']['tono']) ?>"><?= e($l['stock']['texto']) ?></span><?php endif; ?>
          <?php if ($resuelvo && $pedida): ?>
            <form method="post" style="display:inline">
              <?= campo_csrf() ?>
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <input type="hidden" name="accion" value="quitar">
              <input type="hidden" name="linea_id" value="<?= (int)$l['id'] ?>">
              <button class="chip chip--suave" type="submit" style="cursor:pointer">Quitar</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($resuelvo && $pedida && $todos): ?>
        <form method="post" class="form" style="border-top:1px solid var(--linea);padding-top:10px;margin-top:10px" id="anadir-pieza">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <input type="hidden" name="accion" value="anadir">
          <div class="form__fila">
            <label>Añadir otra pieza
              <select name="producto_id" required>
                <option value="">Elige el repuesto</option>
                <?php $ids_sug = array_map(fn($x) => (int)$x['id'], $sugeridas); ?>
                <?php if ($sugeridas): ?><optgroup label="De las máquinas de este pedido">
                  <?php foreach ($sugeridas as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e((string)$r['nombre']) ?> · <?= e((string)$r['maquina_nombre']) ?></option><?php endforeach; ?>
                </optgroup><?php endif; ?>
                <optgroup label="Todos los repuestos">
                  <?php foreach ($todos as $r): if (in_array((int)$r['id'], $ids_sug, true)) continue; ?>
                    <option value="<?= (int)$r['id'] ?>"><?= e((string)$r['nombre']) ?> · <?= e((string)$r['maquina_nombre']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              </select>
            </label>
            <label>Cuántas <input type="number" name="cantidad" min="1" max="20" value="1" inputmode="numeric" style="max-width:90px"></label>
          </div>
          <div class="acciones"><button class="btn btn--linea btn--chico" type="submit"><?= ico('mas',15) ?> AÑADIR</button></div>
        </form>
      <?php endif; ?>
    </div>

    <div class="tarjeta">
      <div class="tarjeta__cab"><h2>Qué falla</h2></div>
      <p style="margin:0 0 10px"><?= e((string)$g['motivo']) ?></p>
      <div class="garantia-fotos">
        <?php foreach ($fotos as $k => $f): ?>
          <a href="<?= e(url('/garantias/foto?id=' . (int)$f['id'])) ?>" target="_blank" rel="noopener" class="chip chip--ver">Foto <?= $k + 1 ?></a>
        <?php endforeach; ?>
      </div>
      <p class="mini" style="margin:10px 0 0">Pedida por <?= e((string)($g['pedida_por_nombre'] ?? '')) ?> · <?= e(fecha_hora((string)$g['pedida_en'])) ?>
        · asesor <?= e(trim((string)$g['asesor_nombre'] . ' ' . (string)$g['asesor_apellidos'])) ?></p>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:12px">
    <?php if ($vigencia): ?>
      <div class="tarjeta" id="garantia-vigencia">
        <div class="tarjeta__cab"><h2>Vigencia</h2>
          <span class="chip chip--largo chip--<?= e(garantia_vigencia_tono($vigencia['estado'])) ?>"><?= e($vigencia['texto']) ?></span></div>
        <p class="mini" style="margin:0"><?= e($vigencia['detalle']) ?></p>
        <?php if ((int)$g['fuera_plazo'] === 1): ?>
          <p class="mini" style="margin:6px 0 0"><strong>Se pidió fuera de plazo</strong><?= $g['vence_en'] ? ': venció el ' . e(fecha_corta((string)$g['vence_en'])) : '' ?>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($resuelvo && $pedida): ?>
      <div class="tarjeta" id="garantia-decidir">
        <div class="tarjeta__cab"><h2>¿Se aprueba?</h2></div>
        <form method="post" class="form" style="margin-bottom:12px">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <input type="hidden" name="accion" value="aprobar">
          <label>Nota para Almacén <input type="text" name="nota" maxlength="300" placeholder="Opcional"></label>
          <p class="mini" style="margin:0 0 8px">Al aprobarla, las piezas salen del stock y pasan a «Por alistar».</p>
          <button class="btn btn--amarillo btn--ancho" type="submit" <?= $lineas ? '' : 'disabled' ?>>APROBAR</button>
        </form>
        <form method="post" class="form" style="border-top:1px solid var(--linea);padding-top:12px">
          <?= campo_csrf() ?>
          <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <input type="hidden" name="accion" value="denegar">
          <label>Por qué no <input type="text" name="nota" maxlength="300" required placeholder="El asesor se lo dice al cliente"></label>
          <button class="btn btn--linea btn--ancho" type="submit">NO APROBAR</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($est === 'aprobada' && !$g['alistado_en']): ?>
      <div class="tarjeta">
        <div class="acciones">
          <?php if (puede('pedidos.alistar') || $resuelvo): ?>
            <a class="btn btn--linea btn--chico" href="<?= e(url('/garantias/rotulo?id=' . (int)$g['id'] . '&vista=1')) ?>"><?= ico('impresora',14) ?> RÓTULO</a>
          <?php endif; ?>
          <?php if (puede('pedidos.alistar')): ?>
            <a class="btn btn--linea btn--chico" href="<?= e(url('/pedidos/por-alistar#garantia-' . (int)$g['id'])) ?>">Por alistar</a>
          <?php endif; ?>
        </div>
        <?php if ($resuelvo): ?>
          <form method="post" class="form" style="border-top:1px solid var(--linea);padding-top:12px;margin-top:12px" id="garantia-anular">
            <?= campo_csrf() ?>
            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
            <input type="hidden" name="accion" value="anular">
            <label>Anularla (las piezas vuelven al stock) <input type="text" name="nota" maxlength="300" required placeholder="Por qué"></label>
            <button class="btn btn--rojo btn--chico" type="submit" data-confirmar="Anular esta garantía. Las piezas vuelven al stock. ¿Seguimos?">ANULAR</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
