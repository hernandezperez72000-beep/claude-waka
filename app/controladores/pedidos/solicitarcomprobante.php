<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cortar(404, 'Esta página no existe');

/**
 * EL ASESOR PIDE EL COMPROBANTE.
 *
 * «Tiene que poder avisarse luego, y que aparezca en dos lugares: en el popup
 * que sale después de realizar el pedido, y en la página de pedidos, en el
 * menú de los tres puntitos» (usuario, 2026-09-12).
 *
 * Es un AVISO a facturación, no una emisión: la regla y la constancia viven en
 * pedido_solicitar_comprobante(). Aquí solo se comprueba quién puede mandarlo
 * y a dónde vuelve.
 */
$id = pedir_int('id');
$p  = $id ? pedido_de($id) : null;
if (!$p) cortar(404, 'Ese pedido no existe');

/* La MISMA puerta que editar el pedido: pedir la boleta de una venta es hablar
   por esa venta, y eso es de quien la hizo (o de Administración). El ámbito
   «equipo» es de solo lectura, así que el líder ve la venta de los suyos y no
   pide por ellos — igual que no puede anularla. */
exigir_editar((int)$p['asesor_id'], (int)$p['pais_id']);

$r = pedido_solicitar_comprobante($id, pedir('tipo'));

if (!$r['ok']) {
    avisar('error', $r['error']);
    /* SI FALLÓ, NO SE LE MANDA AL INICIO. «inicio» es el botón de la ventana
       de «Pedido registrado» y se le devuelve a trabajar porque ya no queda
       nada que hacer con esa venta; cuando sí queda algo —facturación emitió
       el comprobante entre que se pintó la ventana y se pulsó el botón— el
       asesor tiene que ver la ficha de la que habla el aviso, no salir
       despedido a la pantalla principal con un error rojo y sin vuelta. */
    ir('/pedidos/ficha?id=' . $id);
} elseif (empty($r['sin_cambio'])) {
    /* El QUÉ pasó lo dice la función, que es la que lo sabe: rearmarlo aquí
       desde el POST en crudo dejaba el aviso sin nombre cuando llegaba
       «BOLETA» en mayúsculas —la función normaliza, el aviso no—. */
    avisar('ok', $r['texto'] . '.' . (pedir('tipo') === ''
        ? ' Esta venta queda sin comprobante.'
        : ' Le sale a facturación en su bandeja; tú no tienes que escribirles.'));
}

/* A dónde vuelve. */
ir(match (pedir('volver')) {
    /* «inicio» es el botón de la ventana de «Pedido registrado». Pedir el
       comprobante es lo último que el asesor hace con esa venta, así que se le
       devuelve a trabajar en vez de dejarlo mirando una ficha que ya no tiene
       nada que decirle (usuario, 2026-09-14). Es el mismo destino que
       «ESPERAR CONFIRMACIÓN», el botón de al lado. */
    'inicio' => '/inicio',
    /* «nuevo» vuelve a la ventana con la marca puesta. Se queda para el día que
       haga falta encadenar algo más ahí; hoy no lo usa ninguna pantalla. */
    'nuevo' => '/pedidos/ficha?id=' . $id . '&nuevo=1',
    /* Con sus filtros: la lista tiene cuatro —búsqueda, vista, tipo y zona— y
       volver solo con la búsqueda devolvía a «Todos», arriba del todo. */
    'lista' => '/pedidos' . (function () {
        $qs = array_filter(['tab' => pedir('tab') === 'preventa' ? 'preventa' : '', 'q' => pedir('q'), 'v' => pedir('v'), 't' => pedir('t'),
                            'z' => pedir('z'), 'a' => pedir('a'), 'es' => pedir('es'), 'lo' => pedir('lo'), 'pv' => pedir('pv')],
                           fn($x) => (string)$x !== '');
        return $qs ? '?' . http_build_query($qs) : '';
    })(),
    default => '/pedidos/ficha?id=' . $id,
});
