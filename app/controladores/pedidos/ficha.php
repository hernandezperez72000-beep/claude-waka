<?php
declare(strict_types=1);
seccion_activa('pedidos');

$u  = yo();
$id = pedir_int('id', 'get');
if (!$id) cortar(404, 'Falta el pedido');

$p = pedido_de($id);
if (!$p) cortar(404, 'Ese pedido no existe');

/* El equipo para el ámbito sale del asesor de HOY, no de la columna histórica
   del pedido: un líder tiene que ver lo de los suyos de ahora. */
$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'],
           $equipo_asesor !== null ? (int)$equipo_asesor : null);

/* Y esta es la otra comprobación, la de ESCRIBIR. El ámbito «equipo» es de
   solo lectura: corregir ventas ajenas es como se arman las disputas de meta
   a fin de mes. */
$puedo_tocar = puedo_editar((int)$p['asesor_id'], (int)$p['pais_id']);

pagina('pedidos/ficha', [
    'p'        => $p,
    'lineas'   => $lineas_f = pedido_lineas($id),
    /* La miniatura de cada producto vendido, en una sola consulta. */
    'fotos_lineas' => producto_fotos(array_map(fn($l) => (int)($l['producto_id'] ?? 0), $lineas_f)),
    'pagos'    => pagos_del_pedido($id),
    /* Cómo quedó el stock de la web con esta venta (3e). */
    'stock_web' => stock_venta_estado($id),
    'eventos'  => pedido_eventos($id),
    // Un pedido anulado no ofrece mensajes: /pedidos/mensaje los corta igual,
    // y un chip que lleva a un 410 enseña a desconfiar de los demás chips.
    'plantillas' => (string)$p['estado'] === 'anulado' ? [] : plantillas_del_pedido($p),
    'metodos'  => lista('metodos_pago', (int)$p['pais_id']),
    'motivos'  => lista('motivos_anulacion', (int)$p['pais_id']),
    'cashback' => cashback_saldo((int)$p['cliente_id']),
    'ubigeo'   => ubigeo_texto($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null),
    'puedo_tocar' => $puedo_tocar,
    'nuevo'    => pedir('nuevo', 'get') === '1',
    /* La racha de la venta que se acaba de registrar (5a), una sola vez. */
    'racha_venta' => (function () use ($id) {
        $r = $_SESSION['racha_venta'] ?? null;
        if (!$r || (int)($r['pedido'] ?? 0) !== $id || pedir('nuevo', 'get') !== '1') return null;
        unset($_SESSION['racha_venta']);
        return $r;
    })(),
    /* Cuál de los dos botones de «solicitar comprobante» viene destacado en la
       ventana de «Pedido registrado»: el que dice la ficha del cliente. */
    /* De comprobante_marcado(), la misma que usa la pantalla de facturar: hoy
       las dos contestan igual en el único caso en que esta se usa —cuando no
       hay solicitud—, pero dos definiciones del mismo «cuál viene marcado»
       acaban divergiendo, y esa ya costó una factura emitida de más. */
    'comprobante_ini' => comprobante_marcado($p,
        una('SELECT * FROM clientes WHERE id = ?', [(int)$p['cliente_id']]))['tipo'],
    /* La ventana de «qué acaba de pasar con el pago»: quien confirma desde la
       ficha se queda en la ficha. Ver resultado_de_pago(). */
    'resultado' => resultado_de_pago(),
    /* LA GARANTÍA (3i): hasta cuándo vale, las que ya se pidieron y si se
       puede pedir otra desde aquí. */
    'vigencia'      => garantias_listo() ? pedido_garantia_vigencia($p) : null,
    'garantias_ped' => garantias_del_pedido($id),
    'gar_no'        => garantias_listo() ? garantia_se_puede_pedir($p) : 'no',
], ['titulo' => (string)$p['codigo'], 'sin_titulo' => true]);
