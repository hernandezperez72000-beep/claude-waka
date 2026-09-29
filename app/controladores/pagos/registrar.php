<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

$pedido_id = pedir_int('pedido_id');
$p = $pedido_id ? pedido_de($pedido_id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

/* Registrar un pago es ESCRIBIR sobre el pedido de alguien: el ámbito
   «equipo» no alcanza. Un líder no registra pagos de los suyos. */
exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

/* EL ARCHIVO PRIMERO, ANTES QUE NINGUNA VALIDACIÓN QUE PUEDA IRSE.
   Esta ruta no repinta el formulario: vuelve a la ficha con un aviso. Cada
   `ir()` que haya por encima se lleva el voucher por delante, porque $_FILES
   muere con la petición. La primera versión de este parche dejó el `ir()` del
   monto encima y el comentario decía que no — el asesor volvía a la ficha
   creyendo que solo tenía que corregir la cifra. Lo pilló la auditoría.
   El contexto ata el archivo a ESTE pedido: ver voucher_del_formulario(). */
/* LOS DOS ARCHIVOS ANTES QUE NINGÚN ir(): si el voucher no vale y se volvía
   en ese momento, la foto del DNI —buena— se perdía (auditoría del 3f). */
$rv = voucher_del_formulario('pedido:' . $pedido_id);
$rd = voucher_del_formulario('pedido:' . $pedido_id, 'foto_dni');
$metodo_elegido = pedir_int('metodo_item_id');
/* Al volver a la ficha, el método que eligió sigue elegido: así la foto del
   DNI sale a la vista si ese método la pide. */
$a_ficha = fn() => ir('/pedidos/ficha?id=' . $pedido_id . ($metodo_elegido ? '&metodo=' . $metodo_elegido : '') . '#pagos');
if (!$rv['ok'] || !$rd['ok']) {
    if (!$rv['ok']) avisar('error', $rv['error']);
    if (!$rd['ok']) avisar('error', str_contains($rd['error'], 'DNI') ? $rd['error'] : 'Foto del DNI: ' . $rd['error']);
    $a_ficha();
}
$voucher  = $rv['archivo'];
$foto_dni = $rd['archivo'];
/* Lo que quedó adjunto, dicho entero cuando hay que volver. */
$siguen = implode(' y ', array_filter([$voucher ? 'tu voucher' : '', $foto_dni ? 'la foto del DNI' : '']));
$siguen = $siguen !== '' ? ' ' . ucfirst($siguen) . ($voucher && $foto_dni ? ' siguen adjuntos.' : ' sigue adjunto.') : '';

$monto  = a_centimos(pedir('monto'));
$metodo = pedir_int('metodo_item_id');

if ($monto === null) {
    avisar('error', 'El monto no se entiende. Escríbelo así: 379 o 379.50' . $siguen);
    $a_ficha();
}

/* EL CONCEPTO, EN TODO PAGO QUE NO SEA EL PRIMERO (usuario, 2026-09-21). El
   primero se registra con la venta y ya se sabe qué es; los que vienen después
   —el saldo que cobró el repartidor, el delivery— necesitan decirlo, o
   facturación ve dos vouchers del mismo pedido sin saber cuál es cuál.
   Se cuentan TODOS los cobros, también los denegados: un pago que se vuelve a
   subir después de una denegación también es «otro pago». */
$concepto = trim((string) pedir('concepto'));
$previos  = (int) valor("SELECT COUNT(*) FROM pagos WHERE pedido_id = ?"
                        . (columna_existe('pagos', 'tipo') ? " AND tipo = 'cobro'" : ''),
                        [$pedido_id]);
if ($previos > 0 && $concepto === '' && columna_existe('pagos', 'concepto')) {
        avisar('error', 'Escribe el concepto de este pago: por ejemplo «saldo contraentrega».' . $siguen);
    $a_ficha();
}

$r = pago_registrar($pedido_id, [
    'monto_centimos' => $monto,
    'metodo_item_id' => $metodo,
    'fecha'          => pedir('fecha') ?: date('Y-m-d'),
    /* El N.º de operación solo lo escribe quien confirma pagos: ese número
       sirve para cuadrar contra el extracto del banco y el asesor lo copiaría
       de una captura con el cliente esperando, que es donde se cuela un dígito
       cambiado. La ficha ya no le enseña el campo, pero un POST a mano sí lo
       traía, y la bandeja lo precargaba delante de Facturación como si lo
       hubiera puesto ella. Esconder un campo no es quitarlo. */
    'operacion'      => puede('pagos.verificar') ? pedir('operacion') : '',
        'voucher'        => $voucher,
    'foto_dni'       => metodo_pide_dni($metodo) ? $foto_dni : null,
    'concepto'       => $concepto,
]);
$ctx_dni = voucher_contexto('pedido:' . $pedido_id, 'foto_dni');

if (!$r['ok']) {
    avisar('error', $r['error']);
    $a_ficha();
} elseif (!empty($r['pendiente'])) {
    voucher_pendiente_confirmar('pedido:' . $pedido_id);
    if (metodo_pide_dni($metodo)) voucher_pendiente_confirmar($ctx_dni); else voucher_pendiente_olvidar($ctx_dni);
            /* «Suma a tu meta» es para quien tiene meta: Facturación y Administración
       también registran pagos, y a ellas les basta saber que quedó pendiente. */
    avisar('ok', (string)(yo()['rol'] ?? '') === 'asesor'
        ? 'Pago anotado. Suma a tu meta cuando facturación lo confirme.'
        : 'Pago anotado. Queda pendiente de confirmación.');
} else {
    voucher_pendiente_confirmar('pedido:' . $pedido_id);
    if (metodo_pide_dni($metodo)) voucher_pendiente_confirmar($ctx_dni); else voucher_pendiente_olvidar($ctx_dni);
    avisar('ok', 'Pago de ' . soles($monto) . ' registrado.'
        . ($r['cashback'] > 0 ? ' El cliente ganó ' . soles($r['cashback']) . ' de Cashback Waka.' : ''));
}
ir('/pedidos/ficha?id=' . $pedido_id);
