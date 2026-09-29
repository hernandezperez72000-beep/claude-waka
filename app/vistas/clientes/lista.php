<?php
$puede_crear = puede('clientes.crear');
$solo_miro   = !$puede_crear && puede('clientes.ver');
?>
<header class="cabecera">
  <div>
    <h1>Clientes</h1>
    <div class="cabecera__sub">
      <?= plural($resumen['total'], 'ficha', 'fichas') ?>
      <?php if ($resumen['sin_correo']): ?>
        · <?= plural($resumen['sin_correo'], 'sin correo', 'sin correo') ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="cabecera__acciones">
    <?php if ($solo_miro): ?>
      <span class="chip chip--gris"><?= ico('ojo',13) ?> Solo miras</span>
    <?php endif; ?>
    <?php if ($puede_crear): ?>
      <a class="btn btn--negro" href="<?= e(url('/clientes/nuevo')) ?>"><?= ico('mas',16) ?> NUEVO CLIENTE</a>
    <?php endif; ?>
  </div>
</header>

<?php if ($resumen['en_cola']): ?>
  <div class="aviso aviso--gris" style="margin-bottom:14px">
    <span><?= ico('reloj',17) ?></span>
    <span><strong><?= plural($resumen['en_cola'],'cliente esperando','clientes esperando') ?>
      su cuenta en compraenwaka.</strong>
      Se crearán en cuanto la tienda esté conectada. Nadie pierde su cashback.</span>
  </div>
<?php endif; ?>

<form method="get" class="filtros" style="align-items:center">
  <?php foreach ([['todos','Todos'],['mios','Míos'],['sin_correo','Sin correo'],['sin_tienda','Sin cuenta en la tienda']] as [$k,$t]): ?>
    <a class="<?= $ver === $k ? 'on' : '' ?>"
       href="?v=<?= e($k) ?><?= $q ? '&q=' . urlencode($q) : '' ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
  <span style="flex-grow:1"></span>
  <input type="hidden" name="v" value="<?= e($ver) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Documento, nombre, celular o correo"
         style="border:1.5px solid var(--linea);background:var(--tarjeta);border-radius:999px;padding:9px 16px;font-size:12.5px;min-width:250px">
</form>

<?php if (!$clientes): ?>
  <div class="tarjeta">
    <?php parte('inicio/vacio', [
      'ico' => 'CL', 'marca' => $puede_crear,
      'titulo' => $q !== '' ? 'Nadie coincide con lo que buscas' : 'Todavía no hay clientes',
      'texto'  => $q !== ''
          ? 'Prueba con el número de documento, que es lo único que no cambia nunca.'
          : ($puede_crear
              ? 'La ficha la creas tú al vender. Con ella tendrá su cuenta en compraenwaka.'
              : 'Cuando los asesores registren sus ventas, sus clientes aparecerán aquí.'),
      'boton'  => $q !== '' ? 'QUITAR LA BÚSQUEDA' : ($puede_crear ? 'NUEVO CLIENTE' : ''),
      'ruta'   => $q !== '' ? '/clientes' : '/clientes/nuevo',
    ]); ?>
  </div>
<?php else: ?>
  <div class="tabla__caja">
    <table class="tabla">
      <thead>
        <tr>
          <th>Cliente</th><th>Documento</th><th>Contacto</th><th>Asesor</th>
          <th class="der">Pedidos</th><th class="der">Saldo por cobrar</th>
          <th class="der">Cashback</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($clientes as $c): ?>
        <?php $saldo_cb = $saldos[(int)$c['id']] ?? 0; ?>
        <tr class="<?= (int)$c['activo'] === 0 ? 'apagada' : '' ?>">
          <td class="principal">
            <a href="<?= e(url('/clientes/ficha?id=' . (int)$c['id'])) ?>" style="color:inherit">
              <div class="fila__t recorta"><?= e(cliente_nombre($c)) ?></div>
              <div class="fila__s recorta">
                <?php if (trim((string)$c['email']) === ''): ?>
                  <span class="chip chip--ambar">Sin correo</span>
                <?php else: ?>
                  <?= e($c['email']) ?>
                <?php endif; ?>
              </div>
            </a>
          </td>
          <td data-k="Documento">
            <span class="mini"><?= e($c['tipo_doc']) ?></span><br><?= e($c['documento']) ?>
          </td>
          <td data-k="Contacto"><?= e($c['celular'] ?: '—') ?></td>
          <td data-k="Asesor">
            <span class="mini"><?= e(trim((string)$c['asesor_nombre'] . ' ' . (string)$c['asesor_apellidos']) ?: '—') ?></span>
          </td>
          <td class="der num" data-k="Pedidos"><?= (int)$c['pedidos'] ?></td>
          <td class="der num" data-k="Saldo por cobrar">
            <?= (int)$c['saldo'] > 0 ? '<strong>' . e(soles((int)$c['saldo'])) . '</strong>' : '<span class="muted">—</span>' ?>
          </td>
          <td class="der num" data-k="Cashback">
            <?= $saldo_cb > 0 ? e(soles($saldo_cb)) : '<span class="muted">—</span>' ?>
          </td>
          <td class="der">
            <a class="chip chip--linea" href="<?= e(url('/clientes/ficha?id=' . (int)$c['id'])) ?>">Ver ficha</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="mini" style="margin-top:12px">
    <strong>El cashback que ves es el que se puede usar hoy.</strong> Se usa desde S/ 10 y
    hasta la tercera parte del pedido.
  </p>
<?php endif; ?>
