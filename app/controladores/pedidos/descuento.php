<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * APROBAR O RECHAZAR UN DESCUENTO QUE PASÓ DEL TOPE (3d).
 *
 * La regla entera vive en descuento_resolver(): quién puede, el candado de la
 * fila, el total que se recalcula y el estado del cobro que sigue al total.
 * Aquí solo se lee el botón y se vuelve a donde estaba la persona.
 */
$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

$accion = pedir('accion');
if (!in_array($accion, ['aprobar', 'rechazar'], true)) cortar(400, 'No se entiende qué hacer con el descuento');
$aprueba = $accion === 'aprobar';
$r = descuento_resolver($id, $aprueba, pedir('nota'));

if (!$r['ok']) {
    avisar('error', $r['error']);
} else {
    avisar('ok', $aprueba
        ? 'Descuento aprobado. ' . primer_nombre((string)$p['asesor_nombre']) . ' ya lo ve en su venta.'
        : 'Descuento rechazado. El total volvió a subir y ' . primer_nombre((string)$p['asesor_nombre'])
          . ' lo ve en su venta con tu nota.');
}
/* Desde la lista vuelve a la lista: suele haber más de uno. */
ir(pedir('volver') === 'lista' ? '/pedidos/descuentos' : '/pedidos/ficha?id=' . $id);
