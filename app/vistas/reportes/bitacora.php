<div class="tarjeta" style="margin-bottom:12px">
  <form method="get" class="form">
    <div class="form__fila">
      <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
      <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
      <label>Qué
        <select name="que">
          <option value="">Todo</option>
          <?php foreach ($tipos as $k => $n): ?>
            <option value="<?= e($k) ?>" <?= $que === $k ? 'selected' : '' ?>><?= e($n) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Quién
        <select name="quien">
          <option value="">Todos</option>
          <?php foreach ($personas as $pe): ?>
            <option value="<?= (int)$pe['id'] ?>" <?= (int)$quien === (int)$pe['id'] ? 'selected' : '' ?>>
              <?= e(trim((string)$pe['nombre'] . ' ' . (string)$pe['apellidos'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="acciones" style="margin-top:12px">
      <button class="btn btn--negro" type="submit">VER</button>
      <a class="btn btn--linea" href="<?= e(url('/reportes/bitacora')) ?>">Últimos 7 días</a>
    </div>
  </form>
</div>

<?php if (!$filas): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => '·', 'titulo' => 'Nada en este rango',
      'texto' => 'Nadie tocó nada de lo que pediste entre esas dos fechas. '
               . 'Prueba a abrir el rango o a quitar los filtros.',
    ]); ?>
  </div>
<?php else: ?>
  <div class="tarjeta">
    <div class="tarjeta__cab">
      <h2><?= (int)$cuantas ?> <?= $cuantas === 1 ? 'movimiento' : 'movimientos' ?></h2>
      <span class="mini">Del <?= e(fecha_corta($desde)) ?> al <?= e(fecha_corta($hasta)) ?></span>
    </div>
    <div class="tabla__caja">
      <table class="tabla">
        <thead><tr><th>Cuándo</th><th>Quién</th><th>Qué hizo</th><th>Sobre qué</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $b): ?>
          <?php $suj = bitacora_sujeto($b); ?>
          <tr>
            <td class="mini" data-k="Cuándo"><?= e(fecha_hora($b['creado_en'])) ?></td>
            <td data-k="Quién">
              <?= e($b['nombre'] ? trim((string)$b['nombre'] . ' ' . (string)$b['apellidos']) : (str_starts_with((string)$b['accion'], 'acceso.') ? 'Sin sesión' : 'Automático')) ?>
              <?php if ($b['rol_nombre']): ?>
                <div class="fila__s"><?= e($b['rol_nombre']) ?></div>
              <?php endif; ?>
            </td>
            <td class="principal" data-k="Qué hizo"><?= e(bitacora_frase($b)) ?></td>
            <td data-k="Sobre qué">
              <?php if ($suj['texto'] === ''): ?>
                <span class="mini">—</span>
              <?php elseif ($suj['ruta'] !== ''): ?>
                <a href="<?= e(url($suj['ruta'])) ?>" style="text-decoration:underline"><?= e($suj['texto']) ?></a>
              <?php else: ?>
                <?= e($suj['texto']) ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($cuantas > count($filas)): ?>
      <?php /* Si no cabe todo se dice, con su número: una lista que se corta en
               silencio es peor que una lista larga — es la lección que costó
               dos auditorías en la bandeja de pagos. */ ?>
      <p class="mini" style="margin:10px 0 0">Se muestran los <?= count($filas) ?> más
        recientes de <?= (int)$cuantas ?>. Acorta el rango o filtra por persona para verlos todos.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
