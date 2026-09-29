<?php
declare(strict_types=1);
/* EL ESTADO YA NO SE CAMBIA A MANO (3j): lo mueve el HUB — «En despacho» al
   mandarlo, «Entregado» cuando Almacén sube la foto de la entrega. Esta
   dirección queda para que un enlace o un formulario viejo no dé un error: se
   comprueba igual quién pide, no cambia nada y lo dice. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

avisar('error', 'El estado cambia solo: al mandarlo a despacho y cuando se entrega.');
ir('/pedidos/ficha?id=' . $id);
