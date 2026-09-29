<?php
declare(strict_types=1);
seccion_activa('pagos');

$u = yo();

/**
 * La bandeja de pagos por confirmar.
 *
 * Dos cosas que costaron caro y por eso están escritas así:
 *
 * 1) EL ORDEN. Iba de la fecha más VIEJA a la más nueva y cortaba en 200.
 *    Como esta bandeja recibe ya el 100% de los pagos de los 19 asesores —
 *    ningún método cuenta al instante—, un voucher falso que nadie se atreve a
 *    borrar se queda clavado arriba y empuja fuera de la lista todo lo que
 *    entra hoy. El aviso decía «entró un pago», facturación pulsaba «Verlos» y
 *    el pago no estaba. Ahora se listan los más NUEVOS primero, que es el
 *    trabajo real: confirmar en el momento.
 *
 * 2) EL ÁMBITO. Antes solo se miraba el país, así que una cuenta con el ámbito
 *    estrecho confirmaba pagos cuya ficha, voucher y pantalla de facturar le
 *    contestaban 403 — confirmaba dinero sin poder mirarlo. Que el ámbito de
 *    quien confirma se fije en el alta (ver ambito_forzado_de_rol) no basta:
 *    un UPDATE a mano o un respaldo viejo lo vuelven a abrir. Aquí la bandeja
 *    se queda vacía, que es la forma segura de fallar.
 */
[$sql_ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id');
$where = ['pg.verificado = 0', 'pg.anulado = 0', $sql_ambito];

$tope = 300;   // variable y no const: un controlador no se declara dos veces, pero tampoco hace falta arriesgarlo

$hay_reclamo = columna_existe('pedidos', 'reclamo_veces');
$pagos = todas(
        'SELECT pg.*, pe.codigo, pe.pais_id, pe.total_centimos, pe.cobrado_centimos,
            ' . sql_pago_numero('pg') . ' AS numero,
            ' . sql_reclamo_del_pago() . ' AS reclamo_veces,
            ' . ($hay_reclamo ? 'pe.reclamo_en' : 'NULL') . ' AS reclamo_en,
            c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
            li.valor AS metodo, ua.nombre AS asesor_nombre
       FROM pagos pg
       JOIN pedidos pe        ON pe.id = pg.pedido_id
       JOIN clientes c        ON c.id  = pe.cliente_id
       LEFT JOIN usuarios ua  ON ua.id = pe.asesor_id
       LEFT JOIN lista_items li ON li.id = pg.metodo_item_id
      WHERE ' . implode(' AND ', $where) . '
      /* LO RECLAMADO PRIMERO. Dentro de cada grupo, lo más nuevo arriba, que
         es como estaba: confirmar en el momento es el trabajo real. Un pago
         que el asesor ya reclamó lleva parado más de lo que debería, y verlo
         el último es la forma de que siga parado. */
      ORDER BY (' . sql_reclamo_del_pago() . ') > 0 DESC, pg.id DESC
      LIMIT ' . (int)$tope, $par);

/* La cabecera cuenta TODO lo que espera, no lo que cupo en la página. Y sale
   de pagos_por_validar_resumen(), que es de donde salen el chip del menú, el
   aviso en vivo y la bandeja del panel: escrita aquí a mano, la cabecera y el
   chip acababan contando poblaciones parecidas pero no iguales, y la persona
   se queda mirando dos números que no cuadran sin saber a cuál creerle. */
$resumen = pagos_por_validar_resumen($u);

/* Dos grupos, y el orden importa: arriba lo que NADIE ha mirado —el trabajo
   del día— y abajo lo ya revisado que espera al banco. Mezclados, la fila que
   ya se atendió el lunes vuelve a pedir atención el martes, el miércoles y el
   jueves, y a la semana la bandeja entera se lee por encima. */
$sin_revisar = array_values(array_filter($pagos, fn($x) => (int)$x['en_espera'] === 0));
$en_espera   = array_values(array_filter($pagos, fn($x) => (int)$x['en_espera'] === 1));

/* LO QUE EL ASESOR PIDIÓ. No es el aviso que se quitó, y la diferencia importa:
   aquel contaba TODAS las ventas sin comprobante —incluidas las que a propósito
   no llevan—, así que nunca bajaba a cero y se aprendía a ignorar. Esta cola
   solo tiene lo que alguien pidió a mano, y se vacía: en cuanto se emite o se
   marca que no lleva, la fila desaparece. Lo pidió el usuario el 2026-09-12:
   «que aparezca en dos lugares», y este es el lado de facturación.
   La cola de «ventas sin comprobante» de siempre sigue en Reportes › Pagos
   ingresados, que es donde se mira a propósito. */
$solicitados = comprobantes_solicitados($u);
/* Se pide una fila DE MÁS para saber si hay más, y se dice. Sin esto la
   cabecera contaba lo que cupo: con 120 solicitudes decía «100» —mentira— y
   las 20 más nuevas no salían en ninguna pantalla. Es el mismo aparato que la
   lista de pedidos y el resto de esta misma bandeja. */
$mas_solicitados = count($solicitados) > COMPROBANTES_EN_COLA;
if ($mas_solicitados) array_pop($solicitados);

/* ── LO QUE YA SE VALIDÓ (usuario, 2026-09-23) ───────────────────────
   La bandeja enseñaba solo lo que falta por mirar, así que no había ningún
   sitio donde ver lo que uno acaba de confirmar —ni de comprobar si al pago de
   ayer le salió su boleta—. Va debajo, con su filtro de fechas, asesor y
   comprobante, y arranca en HOY: lo de hoy es lo que se revisa. */
$v_desde = (string) pedir('vd', 'get', date('Y-m-d'));
$v_hasta = (string) pedir('vh', 'get', date('Y-m-d'));
if (!fecha_valida($v_desde)) $v_desde = date('Y-m-d');
if (!fecha_valida($v_hasta)) $v_hasta = date('Y-m-d');
if ($v_desde > $v_hasta) [$v_desde, $v_hasta] = [$v_hasta, $v_desde];
$v_asesor = (int) pedir_int('va', 'get');
$v_comp   = (string) pedir('vc', 'get', '');
if (!in_array($v_comp, ['boleta', 'factura', 'ninguno', 'sin'], true)) $v_comp = '';

[$amb_v, $par_v] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'uav.equipo_id');
$w_v = ['pg.verificado = 1', 'pg.anulado = 0', $amb_v,
        'DATE(COALESCE(pg.verificado_en, pg.creado_en)) BETWEEN ? AND ?'];
/* SOLO COBROS. Una devolución también queda «verificada», con su monto en
   negativo: colada aquí, la lista enseñaba «S/ -500.00» como si alguien
   hubiera validado eso, y la suma de arriba se los restaba en silencio
   (auditoría del 3a.1). */
if (columna_existe('pagos', 'tipo')) $w_v[] = "pg.tipo = 'cobro'";
$par_v = array_merge($par_v, [$v_desde, $v_hasta]);
if ($v_asesor) { $w_v[] = 'pe.asesor_id = ?'; $par_v[] = $v_asesor; }
if ($v_comp === 'sin') {
    $w_v[] = "COALESCE(pe.comprobante_tipo, '') = ''";
} elseif ($v_comp !== '') {
    $w_v[] = 'pe.comprobante_tipo = ?';
    $par_v[] = $v_comp;
}
$validados = todas(
    'SELECT pg.*, pe.codigo, pe.comprobante_tipo, pe.comprobante_serie, pe.comprobante_numero,
            c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
            li.valor AS metodo, uav.nombre AS asesor_nombre, uav.apellidos AS asesor_apellidos
       FROM pagos pg
       JOIN pedidos pe        ON pe.id = pg.pedido_id
       JOIN clientes c        ON c.id  = pe.cliente_id
       LEFT JOIN usuarios uav ON uav.id = pe.asesor_id
       LEFT JOIN lista_items li ON li.id = pg.metodo_item_id
      WHERE ' . implode(' AND ', $w_v) . '
      ORDER BY pg.id DESC LIMIT 100', $par_v);
$v_total = 0;
foreach ($validados as $vv) $v_total += (int)$vv['monto_centimos'];

[$amb_av, $par_av] = filtro_ambito('us.id', 'us.pais_id', 'us.equipo_id');
$asesores_v = todas("SELECT us.id, us.nombre, us.apellidos FROM usuarios us
                       JOIN roles r ON r.id = us.rol_id AND r.clave = 'asesor'
                      WHERE us.activo = 1 AND $amb_av ORDER BY us.nombre", $par_av);

/* QUÉ SE ESTÁ VENDIENDO (usuario, 2026-09-26): cada pago dice qué lleva su
   venta, y «VER VENTA» abre el resumen encima, sin salir de la bandeja. */
$lineas_de = pedidos_lineas_de(array_merge(array_column($pagos, 'pedido_id'), array_column($validados, 'pedido_id')));

/* LA VENTANA DEL RESUMEN. Como la del resultado del pago: la pinta el
   servidor (?ver=ID) y se cierra con un enlace a esta misma pantalla, con
   los filtros de Validados intactos. Solo si esta persona puede ver la venta. */
$ver = null;
$ver_id = (int) pedir_int('ver', 'get');
if ($ver_id && ($vp = pedido_de($ver_id))) {
    $eq_v = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$vp['asesor_id']]);
    if (puedo_ver((int)$vp['asesor_id'], (int)$vp['pais_id'], $eq_v !== null ? (int)$eq_v : null)) {
        $lin_v = pedido_lineas($ver_id);
        $ver = ['p' => $vp, 'lineas' => $lin_v, 'pagos' => pagos_del_pedido($ver_id),
                'fotos' => producto_fotos(array_map(fn($l) => (int)($l['producto_id'] ?? 0), $lin_v)),
                'stock_web' => stock_venta_estado($ver_id)];
    }
}
/* Sin la ventana del pago que se acaba de decidir (hecho/ped/val): cerrar el
   resumen no puede volver a abrir aquella (auditoría del 3f). */
$q_sin_ver = array_diff_key($_GET, array_flip(['ver', 'hecho', 'ped', 'val']));
$url_bandeja = url('/pagos/por-validar') . ($q_sin_ver ? '?' . http_build_query($q_sin_ver) : '');
/* Para los enlaces «VER VENTA»: la dirección de ahora, con ?ver= al final. */
$url_ver = fn(int $pid) => url('/pagos/por-validar') . '?' . http_build_query($q_sin_ver + ['ver' => $pid]);

pagina('pagos/porvalidar', [
    'lineas_de' => $lineas_de, 'ver' => $ver, 'url_bandeja' => $url_bandeja, 'url_ver' => $url_ver,
    'pagos'   => $pagos,
    'validados' => $validados, 'v_total' => $v_total,
    'v_desde' => $v_desde, 'v_hasta' => $v_hasta, 'v_asesor' => $v_asesor, 'v_comp' => $v_comp,
    'asesores_v' => $asesores_v,
    /* El que se acaba de confirmar, para pintarlo en verde. */
    'recien' => (int) pedir_int('val', 'get'),
    'sin_revisar' => $sin_revisar,
    'en_espera'   => $en_espera,
    'total'   => (int)$resumen['monto'],
    'cuantos' => (int)$resumen['n'],
    'n_sin_revisar' => (int)$resumen['sin_revisar'],
    'n_en_espera'   => (int)$resumen['en_espera'],
    'monto_espera'  => (int)$resumen['monto_espera'],
    'tope'    => $tope,
    /* Lo que acaba de pasar con un pago, para pintarlo ENCIMA de esta misma
       pantalla en vez de llevarse a nadie a otra. Ver resultado_de_pago(). */
    'resultado' => resultado_de_pago(),
    'solicitados' => $solicitados, 'mas_solicitados' => $mas_solicitados,
    'n_solicitados' => $mas_solicitados ? comprobantes_solicitados_cuantos($u) : count($solicitados),
], ['titulo' => 'Pagos por validar',
    'subtitulo' => 'Hasta que se confirmen, no suman a ninguna meta ni generan cashback']);
