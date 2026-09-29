<?php
declare(strict_types=1);

/**
 * ¿Entró algún pago nuevo por confirmar? Responde JSON.
 *
 * Lo pregunta la pantalla cada pocos segundos para que facturación se entere
 * en el momento, sin recargar nada. Es a propósito lo más barato posible —una
 * sola consulta que cuenta y toma el id más alto— porque se llama muchas veces
 * al día: en un hosting compartido, una conexión abierta por persona (SSE,
 * websockets) se come los procesos disponibles y tumba el sitio para todos.
 *
 * Devuelve exactamente lo mismo que ya pinta el menú. No enseña ni un dato del
 * pago: solo cuántos hay, cuánto suman y cuál es el más nuevo.
 */
$u = yo();
$r = pagos_por_validar_resumen($u);

/* `desde` es el último id que ya había visto la pantalla. Sirve para poder
   decir «entró UN pago» y no «hay 7 pagos»: son cosas distintas, y confundirlas
   hace que el aviso mienta cada vez que quedan pendientes de antes.
   No filtra nada que la persona no pudiera ver igual: son sus mismos pagos,
   con su país y su ámbito, los mismos que le lista la bandeja. */
$desde  = max(0, (int) pedir_int('desde', 'get', 0));
$nuevos = pagos_nuevos_desde($desde, $u);

/* Los reclamos van en el MISMO sondeo: una petición por persona, no dos. */
$rec = reclamos_nuevos_desde(max(0, (int) pedir_int('rdesde', 'get', 0)), $u);
/* Y los descuentos que pasaron del tope (3d), con la MISMA marca de agua:
   los dos son líneas de la bitácora, así que no hace falta otra. */
/* Con la MISMA marca de arriba como tope: leída dos veces, una línea escrita
   entre las dos lecturas se contaba ahora y otra vez en la vuelta siguiente. */
$rdesde_d = max(0, (int) pedir_int('rdesde', 'get', 0));
$marca_d  = max((int)$rec['ultimo'], $rdesde_d);
$desc = puede_aprobar_descuentos($u) ? descuentos_pedidos_desde($rdesde_d, $marca_d, $u)
                                     : ['n' => 0, 'codigo' => '', 'asesor' => ''];

json([
    'ok'     => true,
    // `n` es lo que pinta el título de la pestaña, así que es lo MISMO que el
    // chip del menú: lo que espera una mirada. Lo ya revisado y esperando al
    // banco viaja aparte, por si algún día hace falta.
    'n'         => $r['sin_revisar'],
    'en_espera' => $r['en_espera'],
    'pendiente' => $r['n'],     // todo el dinero sin confirmar, revisado o no
    'nuevos'    => $nuevos,     // cuántos entraron desde la última mirada
    'ultimo'    => $r['ultimo'],
    'monto'     => soles($r['monto']),
    'reclamos'        => $rec['n'],
    'reclamo_ultimo'  => $rec['ultimo'],
    'reclamo_codigo'  => $rec['codigo'],
    'reclamo_asesor'  => $rec['asesor'],
    'descuentos'      => $desc['n'],
    'descuento_ultimo'=> $marca_d,
    'descuento_codigo'=> $desc['codigo'],
    'descuento_asesor'=> $desc['asesor'],
]);
