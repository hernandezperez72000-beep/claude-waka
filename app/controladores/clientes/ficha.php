<?php
declare(strict_types=1);
seccion_activa('clientes');

$u  = yo();
$id = pedir_int('id', 'get');
if (!$id) cortar(404, 'Falta el cliente');

$c = una('SELECT c.*, ua.nombre AS asesor_nombre, ua.apellidos AS asesor_apellidos,
                 ua.equipo_id AS asesor_equipo, li.valor AS canal, o.nombre AS oficina
            FROM clientes c
            LEFT JOIN usuarios ua    ON ua.id = c.asesor_id
            LEFT JOIN lista_items li ON li.id = c.canal_item_id
            LEFT JOIN oficinas o     ON o.id  = c.oficina_id
           WHERE c.id = ?', [$id]);
if (!$c) cortar(404, 'Ese cliente no existe');

/* Aislamiento en el servidor. Escribir "/clientes/ficha?id=1" a mano tiene que
   devolver 403 si ese cliente no es de quien mira: el menú no es la barrera. */
exigir_ver($c['asesor_id'] !== null ? (int)$c['asesor_id'] : null,
           (int)$c['pais_id'],
           $c['asesor_equipo'] !== null ? (int)$c['asesor_equipo'] : null);

$pedidos = todas(
    "SELECT p.*, e.nombre AS estado_nombre, e.clave AS estado, e.color AS estado_color
       FROM pedidos p JOIN pedido_estados e ON e.id = p.estado_id
      WHERE p.cliente_id = ? ORDER BY p.id DESC LIMIT 50", [$id]);

$direcciones = todas('SELECT * FROM cliente_direcciones WHERE cliente_id = ? AND activo = 1
                       ORDER BY principal DESC, id DESC', [$id]);

$resumen = [
    'comprado' => (int) valor('SELECT COALESCE(SUM(total_centimos),0) FROM pedidos
                                WHERE cliente_id = ? AND anulado_en IS NULL', [$id]),
    'cobrado'  => (int) valor('SELECT COALESCE(SUM(cobrado_centimos),0) FROM pedidos
                                WHERE cliente_id = ? AND anulado_en IS NULL', [$id]),
    'saldo'    => (int) valor('SELECT COALESCE(SUM(total_centimos - cobrado_centimos),0) FROM pedidos
                                WHERE cliente_id = ? AND anulado_en IS NULL', [$id]),
];

pagina('clientes/ficha', [
    'c' => $c, 'pedidos' => $pedidos, 'direcciones' => $direcciones, 'resumen' => $resumen,
    'cashback'  => cashback_saldo($id),
    'por_vencer'=> cashback_por_vencer($id, 30),
    'movimientos' => cashback_movimientos($id, 15),
    'puedo_editar' => puede('clientes.editar') && puedo_editar(
        $c['asesor_id'] !== null ? (int)$c['asesor_id'] : null, (int)$c['pais_id']),
    /* Venderle es escribir sobre la cartera de alguien: el líder ve la ficha
       de los clientes de su equipo pero no le registra pedidos. Se comprueba
       igual en el controlador del pedido; aquí solo se evita ofrecer un botón
       que iba a devolver 403. */
    'puedo_venderle' => puede('pedidos.crear') && puedo_venderle($c),
], ['titulo' => cliente_nombre($c), 'sin_titulo' => true]);
