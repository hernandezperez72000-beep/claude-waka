<?php
declare(strict_types=1);

/**
 * Alta rápida de cliente desde el formulario de pedido. Responde JSON.
 *
 * Existe porque el asesor tiene al cliente delante: irse a Clientes, crear la
 * ficha y volver es un minuto muerto en plena atención.
 *
 * Lo que NO es: una versión recortada. Usa las mismas tres funciones que la
 * pantalla de Clientes (`cliente_datos_del_post`, `cliente_validar`,
 * `cliente_crear`), así que pide lo mismo, rechaza lo mismo y detecta los
 * mismos duplicados. Si pidiera menos, en dos semanas habría dos calidades de
 * ficha según por dónde entró el cliente.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

$u       = yo();
$pais_id = (int)$u['pais_id'];

$d = cliente_datos_del_post($pais_id);
$revision = cliente_validar($d, $pais_id, null);

if ($revision['errores']) {
    /* Si esa persona YA está registrada Y la ficha es visible para quien
       pregunta, se devuelve para poder ofrecer "úsalo" en vez de dejar al
       asesor atascado delante del cliente. Si NO es suya, se avisa de que
       existe pero no se dice ni el id ni el nombre: si no, este formulario
       sería un buscador de la cartera del asesor de al lado.
       Y `usable` decide si se pinta el botón: ofrecer una ficha que el
       servidor va a rechazar acaba en un 403 con el pedido ya tecleado. */
    $rep = $revision['repetido'];
    $ver = $rep && cliente_puedo_ver_ficha($rep);
    json([
        'ok'      => false,
        'errores' => $revision['errores'],
        'existe'  => $ver ? [
            'id'     => (int)$rep['id'],
            'texto'  => trim((string)$rep['nombre'] . ' ' . (string)$rep['apellidos']),
            'usable' => cliente_puedo_venderle($rep),
            /* Su saldo de cashback, igual que lo manda el buscador. Sin él, al
               reutilizar una ficha que ya existía el formulario decía «Tiene
               S/ 0.00» de un cliente que sí tiene saldo, y el asesor no le
               ofrecía el descuento. */
            'cashback_centimos' => cashback_saldo((int)$rep['id']),
        ] : null,
    ], 422);
}

$id = cliente_crear($d, $u);
if (!$id) {
    // Otra petición se adelantó por milésimas y creó esa misma ficha.
    json(['ok' => false, 'existe' => null,
          'errores' => ['Esa persona acaba de quedar registrada. Búscala en el buscador de arriba.']], 422);
}

json([
    'ok'      => true,
    'cliente' => [
        'id'    => $id,
        'texto' => trim((string)$d['nombre'] . ' ' . (string)$d['apellidos']),
        'sub'   => $d['tipo_doc'] . ' ' . $d['documento']
                 . ($d['celular'] ? ' · ' . $d['celular'] : ''),
        // Un cliente recién creado no tiene cashback, pero se devuelve el dato
        // igual para que la pantalla no tenga dos caminos distintos.
        'cashback_centimos' => 0,
    ],
]);
