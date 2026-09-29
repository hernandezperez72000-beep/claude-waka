<?php
declare(strict_types=1);
seccion_activa('despacho');

/**
 * «Mis ventas que faltan mandar a despacho.»
 *
 * Existe por lo que pidió el usuario el 2026-09-09: si el HUB frena el envío
 * hasta que facturación confirme el pago, tiene que ser el HUB quien avise
 * cuando ya se puede. Sin esta pantalla, el asesor choca una vez contra el
 * freno y nadie le dice nunca que quedó libre: la venta se queda en el aire y
 * el cliente llama a los tres días.
 *
 * El contador del menú y esta lista salen de la MISMA definición
 * (pedidos_por_despachar_resumen para el número, sql_despacho_listo() para las
 * filas), y hay una prueba que comprueba que cuadran. Dos consultas parecidas
 * pero no iguales son la forma en que este proyecto ha producido, seis veces,
 * dos cifras del mismo dato en la misma pantalla.
 */
$u = yo();

/* SIN LA COLUMNA, ESTA PANTALLA NO PUEDE CONTESTAR — y desde el parche 2n es
   una de las cinco pestañas fijas del celular del asesor, así que un 500 aquí
   es un 500 que se encuentra solo. El resumen del menú ya se defendía; esta
   pantalla no, y daba la página de «Algo se rompió», sin menú ni barra
   (auditoría, 2026-09-14). Se avisa de lo que pasa y se sigue. */
if (!columna_existe('pedidos', 'despacho_veces')) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Por mandar a despacho',
        'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}

/* El ámbito de lo que uno puede MANDAR, no el de lo que puede ver: el líder de
   equipo ve las ventas de los suyos y no las despacha. Ver
   filtro_ambito_despacho(). */
[$sql_ambito, $par] = filtro_ambito_despacho('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);

$resumen = pedidos_por_despachar_resumen($u);
$tope    = 300;

$pedidos = todas(
    'SELECT pe.id, pe.codigo, pe.fecha, pe.total_centimos, pe.cobrado_centimos,
            /* Para el costo del envío pendiente de la pre venta (3j). */
            pe.tipo, pe.tipo_envio_item_id, pe.flete_centimos, pe.flete_lo_paga, pe.despacho_veces, pe.anulado_en,
            pe.entrega, pe.entrega_fecha, pe.entrega_hora_desde, pe.entrega_hora_hasta,
            pe.entrega_recepcion, pe.comprobante_tipo,
            c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
            ua.nombre AS asesor_nombre
       FROM pedidos pe
       JOIN clientes c        ON c.id  = pe.cliente_id
       LEFT JOIN usuarios ua  ON ua.id = pe.asesor_id
      WHERE pe.anulado_en IS NULL
        AND pe.despacho_veces = 0
        AND ' . sql_despacho_listo() . '
        AND ' . $sql_ambito . '
      /* Los que tienen fecha de entrega comprometida primero, y dentro de
         ellos el más cercano: es el orden en que aprieta el reloj. Los que no
         tienen fecha van después, por venta más antigua — llevan más tiempo
         esperando. */
      ORDER BY CASE WHEN pe.entrega_fecha IS NULL THEN 1 ELSE 0 END,
               pe.entrega_fecha ASC, pe.fecha ASC, pe.id ASC
      LIMIT ' . (int)$tope, $par);

/* Los que están frenados porque falta confirmar el pago se enseñan aparte y
   se dice por qué: si no, el asesor cuenta sus ventas del día, ve menos de las
   que hizo y concluye que el HUB perdió una. */
$hay_reclamo = columna_existe('pedidos', 'reclamo_veces');
$tope_frenados = 100;
$sql_frenados = 'FROM pedidos pe
       JOIN clientes c        ON c.id  = pe.cliente_id
       LEFT JOIN usuarios ua  ON ua.id = pe.asesor_id
      WHERE pe.anulado_en IS NULL
        AND pe.despacho_veces = 0
        AND NOT (' . sql_despacho_listo() . ')
        AND ' . $sql_ambito;

/* CUÁNTOS HAY, no cuántos cupieron. `count($frenados)` sobre una consulta con
   LIMIT decía «100» habiendo 340, sin avisar de nada — la misma enfermedad de
   dos cifras del mismo dato que la lista de arriba ya resolvió con su
   «Se muestran X de Y». */
$frenados_total = (int) valor('SELECT COUNT(*) ' . $sql_frenados, $par);

$frenados = todas(
        'SELECT pe.id, pe.codigo, pe.fecha, pe.total_centimos, pe.estado_id,
            pe.despacho_veces, pe.asesor_id, pe.pais_id, pe.tipo,
            /* Lo que necesita pedido_despacho_motivo() para decir «falta cobrar
               el total» a provincia: sin estas tres, esa venta salía como «Sin
               confirmar» y el asesor esperaba a facturación (auditoría 2s). */
            pe.entrega, pe.cobrado_centimos,
            /* Y el descuento que espera aprobación (3d): sin él, esa venta
               salía como «Sin confirmar». */
            ' . (columna_existe('pedidos', 'descuento_estado')
                 ? 'pe.descuento_estado' : "'' AS descuento_estado") . ',
            ' . (columna_existe('pedidos', 'ubigeo_id') ? 'pe.ubigeo_id' : 'NULL AS ubigeo_id') . ',
            es.clave AS estado,
            ' . ($hay_reclamo ? 'pe.reclamo_veces, pe.reclamo_en'
                              : '0 AS reclamo_veces, NULL AS reclamo_en') . ',
            c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
       ' . str_replace('FROM pedidos pe',
                       'FROM pedidos pe JOIN pedido_estados es ON es.id = pe.estado_id',
                       $sql_frenados) . '
      /* La pre venta que espera su lote va al final (3j): son muchas a la vez
         y no pueden tapar a las que esperan un pago. */
      ORDER BY CASE WHEN ' . sql_preventa_lista('pe') . ' THEN 0 ELSE 1 END, pe.fecha DESC, pe.id DESC LIMIT ' . (int)$tope_frenados, $par);

/* El estado del reclamo, fila por fila: si se puede pulsar, cuánto falta y —lo
   que antes no se decía— POR QUÉ no se puede. La venta cuyo voucher denegaron
   se quedaba bajo el cartel «Esperando que facturación confirme» sin motivo, y
   el asesor esperaba una respuesta que ya había llegado.
   `estado` viene de la consulta y ya no se inventa aquí: escribir
   'registrado' a mano era una redacción más de «este pedido no está anulado»,
   y el día que las dos marcas dejen de ir a la par nadie lo notaría. */
$reclamo_id = pedir_int('wa', 'get');
$wa = '';
foreach ($frenados as &$fr) {
    $fr['reclamo'] = pedido_reclamo_estado($fr);
    /* Al de solo lectura —el líder de equipo, que ve las ventas de los suyos—
       no se le pinta el botón: pulsarlo le daba un 403 a pantalla completa y
       perdía la lista. */
    $fr['puede_tocar'] = puedo_editar((int)$fr['asesor_id'], (int)$fr['pais_id']);
    /* El WhatsApp se arma solo para el que se acaba de reclamar: es el único
       que se usa, y armarlo para los cien costaba cien consultas de más. */
    if ($reclamo_id && (int)$fr['id'] === $reclamo_id) $wa = reclamo_whatsapp_url($fr);
}
unset($fr);

/* ENVIADOS (3j): lo que ya mandó, con filtros y todas las fotos. */
$env_f = enviados_filtros($_GET);
$enviados = pedidos_enviados($env_f, $u, 100);
$env_ids = array_map(fn($x) => (int)$x['id'], $enviados);

pagina('pedidos/pordespachar', [
    'enviados'   => $enviados,
    'env_f'      => $env_f,
    'env_lineas' => pedidos_lineas_de($env_ids),
    'env_vouch'  => pedidos_vouchers_de($env_ids),
    'hay_zona'   => columna_existe('pedidos', 'ubigeo_id'),
    'pedidos'  => $pedidos,
    'frenados' => $frenados,
    'frenados_total' => $frenados_total,
    'tope_frenados'  => $tope_frenados,
    'cuantos'  => (int)$resumen['n'],
    'tope'     => $tope,
    'minutos_reclamo' => reclamo_minutos(),
    /* Qué pedido se acaba de reclamar, para pintarle el botón de WhatsApp
       arriba: es el aviso que de verdad le llega a facturación. */
    'reclamado' => $reclamo_id,
    'reclamado_wa' => $wa,
], ['titulo' => 'Por mandar a despacho',
    'subtitulo' => 'Ventas con el pago confirmado que todavía no salieron']);
