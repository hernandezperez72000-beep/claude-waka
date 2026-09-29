<?php
declare(strict_types=1);

/**
 * EL RÓTULO DE ENVÍO EN PDF, para pegar en la caja (parche 2r).
 *
 * Lo pidió el usuario el 2026-09-20: media A5 con el logo, que se descarga al
 * mandar a despacho y se manda al grupo de WhatsApp.
 *
 * Las mismas dos llaves que la pantalla de despacho: quien no puede ver ese
 * pedido tampoco puede sacarle el rótulo, y un pedido anulado no lleva
 * rótulo — una caja con rótulo es una caja que sale.
 */
$id = pedir_int('id', 'get') ?: pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'],
           $equipo_asesor !== null ? (int)$equipo_asesor : null);

if ((string)$p['estado'] === 'anulado') {
    cortar(410, 'Este pedido está anulado',
                'Una venta anulada no se despacha, así que no lleva rótulo.');
}

/* Y TAMPOCO SI LA VENTA ESTÁ FRENADA. Es la misma puerta que la pantalla de
   despacho: un rótulo impreso es una caja preparada para salir, y una caja
   preparada sale. Sin esto, el pedido con el pago sin confirmar no se podía
   mandar al grupo pero sí se podía rotular (auditoría del 2r). */
/* Salvo que la caja YA HAYA SALIDO: entonces el rótulo es para reimprimir la
   etiqueta de algo que está en camino, y frenarlo porque el asesor acaba de
   subir el saldo no protege nada (auditoría del 2s). */
if ((int)($p['despacho_veces'] ?? 0) === 0 && ($bloqueo = pedido_despacho_bloqueo($p))) {
    cortar(409, 'Todavía no sale a despacho', $bloqueo);
}

/* ALMACÉN (3h.1) rotula lo que ya le mandaron a despacho, no cualquier venta:
   el rótulo lleva el nombre, la dirección y el celular del cliente. */
if (!puede('pedidos.despachar') && (int)($p['despacho_veces'] ?? 0) === 0) {
    cortar(409, 'Todavía no sale a despacho', 'El rótulo sale cuando el asesor lo manda a despacho.');
}

/* LA VISTA PREVIA (usuario, 2026-09-29): «que no se descargue de manera
   directa, que se abra una previsualización, y que se pueda elegir la
   cantidad de bultos». Con ?vista=1 sale la pantalla; sin ella, el PDF. */
if (pedir('vista', 'get') === '1') {
    $bl = rotulo_bloques((int)$p['id']);
    if ($bl === null) cortar(404, 'Ese pedido no existe');
    seccion_activa(puede('pedidos.alistar') && !puede('pedidos.crear') ? 'alistar' : 'despacho');
    pagina('pedidos/rotulo_vista', ['bloques' => $bl, 'codigo' => (string)$p['codigo'],
        'pdf' => url('/pedidos/rotulo?id=' . (int)$p['id']),
        'volver' => volver_de_donde_vino(url(puede('pedidos.ver') ? '/pedidos/ficha?id=' . (int)$p['id'] : '/pedidos/por-alistar'))],
        ['titulo' => 'Rótulo ' . $p['codigo'], 'sin_titulo' => true]);
    return;
}
$bultos = max(1, min(50, (int) pedir_int('bultos', 'get', 1)));
$pdf = rotulo_de_pedido((int)$p['id'], null, $bultos);
if ($pdf === null) cortar(404, 'Ese pedido no existe');

/* Con el código del pedido por nombre: en el grupo de WhatsApp aparecen diez
   rótulos al día y «documento.pdf» no le sirve a nadie. Se abre en el
   navegador para imprimir; con ?descargar=1, se descarga. */
$nombre = 'rotulo-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string)$p['codigo']) . ($bultos > 1 ? '-' . $bultos . 'bultos' : '') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (pedir('descargar', 'get') === '1' ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
