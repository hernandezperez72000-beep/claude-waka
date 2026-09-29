<?php
declare(strict_types=1);
seccion_activa('pedidos');

$id = pedir_int('id', 'get');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'],
           $equipo_asesor !== null ? (int)$equipo_asesor : null);

/* Igual que en /pedidos/despacho: el mensaje de una venta anulada no se arma.
   Estas plantillas son las que se pegan en los grupos, y una confirmación de
   venta de un pedido que ya no existe se lee como una venta que sí existe. */
if ((string)$p['estado'] === 'anulado') {
    cortar(410, 'Este pedido está anulado',
                'No se envían mensajes de una venta anulada. Si el cliente la retoma, regístrala otra vez.');
}

$claves = plantillas_del_pedido($p);
$clave  = pedir('p', 'get') ?: (string)array_key_first($claves);
if (!isset($claves[$clave])) cortar(404, 'Esa plantilla no existe');

$texto = mensaje_de_pedido($id, $clave);

pagina('pedidos/mensaje', [
    'p' => $p, 'claves' => $claves, 'clave' => $clave, 'texto' => $texto,
    'wa' => enlace_whatsapp((string)$p['cliente_celular'], $texto),
], ['titulo' => 'Mensaje de WhatsApp', 'migaja' => (string)$p['codigo']]);
