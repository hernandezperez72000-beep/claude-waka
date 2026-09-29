<?php
declare(strict_types=1);
seccion_activa('reportes');

/**
 * Reporte de PAGOS INGRESADOS.
 *
 * Es un reporte aparte del de ventas, y a propósito: uno cuenta lo que se
 * vendió y este cuenta el dinero que entró. Casi nunca coinciden —una pre
 * venta de S/20,000 vende hoy y cobra en tres meses— y mezclarlos es como se
 * acaban comparando cifras que no hablan de lo mismo.
 *
 * Este es el que manda para la meta, el podio y los bonos.
 */

$u = yo();

/* El tope del detalle. Se nombra para poder DECIRLO en pantalla: la nota
   prometía «el Excel los trae todos» mientras la consulta cortaba en 20 000
   sin avisar. Una promesa incondicional con un corte mudo detrás es peor que
   un número grande escrito. */
defined('REPORTE_PAGOS_TOPE') || define('REPORTE_PAGOS_TOPE', 20000);

$desde = pedir('desde', 'get') ?: date('Y-m-01');
$hasta = pedir('hasta', 'get') ?: date('Y-m-d');
/* Bien formada no es lo mismo que válida: «2026-13-45» pasa el patrón y luego
   deja la cabecera diciendo «0 pagos del — al —». La comprobación vive en
   ayudas.php (fecha_valida) porque ya la necesitan dos pantallas. */
$fecha_ok = static fn(string $f): bool => fecha_valida($f);
if (!$fecha_ok($desde)) $desde = date('Y-m-01');
if (!$fecha_ok($hasta)) $hasta = date('Y-m-d');
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

$agrupar = pedir('g', 'get', 'dia');
if (!in_array($agrupar, ['dia','semana','mes'], true)) $agrupar = 'dia';

$estado  = pedir('e', 'get', 'validados');
/* «denegados» es NUEVO y no sobra al lado de «rechazados» (usuario,
   2026-09-11): «serviría para saber si hay un asesor con muchos pagos
   denegados y podríamos estar más pendientes de ese asesor». «Rechazados»
   mezcla dos cosas muy distintas —lo que facturación denegó porque el voucher
   no estaba en el banco y lo que el propio asesor quitó porque se equivocó al
   anotarlo—, y mezcladas no se puede ver ese patrón: el asesor cuidadoso que
   corrige sus propios errores sale igual de mal que el que manda vouchers que
   no existen. */
if (!in_array($estado, ['validados','pendientes','rechazados','denegados','todos'], true)) $estado = 'validados';
$metodo  = pedir_int('m', 'get');
$asesor  = pedir_int('a', 'get');

/* «rechazados» son los pagos APAGADOS: los que facturación denegó por voucher
   falso y los que el asesor quitó porque los anotó mal. Antes no salían en
   ningún sitio —el WHERE forzaba `anulado = 0`— y son justo los que hay que
   poder repasar a fin de mes. */
$where = ['pg.fecha >= ?', 'pg.fecha <= ?'];
$par   = [$desde, $hasta];

/* El país aísla, y el ÁMBITO también. Iba solo por país, así que una cuenta
   que recibe 403 al abrir la ficha de un pedido veía aquí el nombre, el
   documento y el dinero de ese mismo cliente. Se usa el mismo filtro que la
   lista de pedidos y que la bandeja de pagos: una sola definición de «lo que
   esta persona puede mirar». */
[$sql_ambito, $par_ambito] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id');
$where[] = $sql_ambito;
$par     = array_merge($par, $par_ambito);

/* EL DINERO NO SE SUMA ENTRE PAÍSES. Para quien cruza —Dirección y
   Desarrollador— filtro_ambito() no pone condición de país, así que el
   «Ingresado» salía de sumar soles peruanos con pesos mexicanos bajo un solo
   símbolo: un número que no es ninguna cifra real. Se elige un país, y por
   defecto el suyo. Quien no cruza no ve el desplegable: su país ya está fijado
   por el ámbito. */
$paises_reporte = cruza_paises($u)
    ? todas('SELECT id, nombre FROM paises WHERE activo = 1 ORDER BY nombre')
    : [];
$pais_reporte = (int)$u['pais_id'];
if ($paises_reporte) {
    $pedido_pais = pedir_int('p', 'get');
    $ids = array_map('intval', array_column($paises_reporte, 'id'));
    if ($pedido_pais && in_array($pedido_pais, $ids, true)) $pais_reporte = $pedido_pais;
    $where[] = 'pe.pais_id = ?';
    $par[]   = $pais_reporte;

    /* Y el símbolo, del país ELEGIDO y no del de quien mira. El selector
       promete «cada uno cobra en su moneda» y sin esto el CEO peruano leía los
       pesos mexicanos con un S/ delante: un número que parece real y no lo es,
       que es justo lo que la regla de no sumar países existe para impedir. */
    simbolo_moneda_de_pais($pais_reporte);
}

/* HASTA AQUÍ, LO QUE NO DEPENDE DEL ESTADO: fechas, ámbito y país. Se guarda
   una copia antes de aplicar el filtro de estado porque el recuento de
   denegados por asesor —el de abajo— tiene que poder contar TAMBIÉN los pagos
   que sí salieron bien: «muchos denegados» solo significa algo al lado de
   cuántos registró esa persona. Con el filtro de estado puesto, el
   denominador sería siempre igual al numerador. */
/* El ASESOR y el MÉTODO sí entran en la base: no son filtros de ESTADO. Si el
   recuento de abajo los ignorara, quien elige un asesor en el desplegable
   vería el detalle reducido a esa persona y encima la tarjeta «Denegados por
   asesor» listando a los diecinueve — dos poblaciones, una encima de la otra,
   en la pantalla cuya única pregunta es «¿hay alguien a quien mirar?». */
if ($metodo) { $where[] = 'pg.metodo_item_id = ?'; $par[] = $metodo; }
if ($asesor) { $where[] = 'pe.asesor_id = ?';      $par[] = $asesor; }

$where_base = $where;
$par_base   = $par;

/* «TODOS» TRAE TODOS, incluidos los apagados. Decía «Todos» y listaba 4 de 7:
   los denegados y los quitados solo salían en sus dos opciones propias, y nada
   en pantalla avisaba de que faltaban. */
if ($estado !== 'todos') {
    $where[] = in_array($estado, ['rechazados','denegados'], true) ? 'pg.anulado = 1' : 'pg.anulado = 0';
}
if ($estado === 'denegados')  $where[] = 'pg.denegado = 1';
if ($estado === 'validados')  $where[] = 'pg.verificado = 1';
if ($estado === 'pendientes') $where[] = 'pg.verificado = 0';

/* QUIÉN ACUMULA DENEGADOS. Solo se pregunta cuando se está mirando esa lista:
   es una pantalla para responder «¿hay alguien a quien mirar de cerca?», no
   un ranking permanente de sospechosos.
   Se cuentan solo los COBROS: una devolución no la registra el asesor.
   Se ordena por cuántos, y se enseña también cuántos registró en total, que
   es lo que separa al que manda muchos vouchers de los que uno falló del que
   manda tres y dos no existen. */
$denegados_por_asesor = $estado === 'denegados' ? todas(
    'SELECT ua.id, ua.nombre, ua.apellidos,
            SUM(CASE WHEN pg.anulado = 1 AND pg.denegado = 1 THEN 1 ELSE 0 END) AS denegados,
            SUM(CASE WHEN pg.anulado = 1 AND pg.denegado = 1 THEN pg.monto_centimos ELSE 0 END) AS monto,
            COUNT(*) AS registrados
       FROM pagos pg
       JOIN pedidos pe        ON pe.id = pg.pedido_id
       LEFT JOIN usuarios ua  ON ua.id = pe.asesor_id
      WHERE ' . implode(' AND ', $where_base) . "
        AND pg.tipo = 'cobro'
      GROUP BY ua.id, ua.nombre, ua.apellidos
     HAVING denegados > 0
      ORDER BY denegados DESC, monto DESC", $par_base) : [];

$filas = todas(
    'SELECT pg.id, pg.fecha, pg.monto_centimos, pg.operacion, pg.verificado, pg.tipo,
            pg.voucher, pg.en_espera, pg.denegado, pg.anulado, pg.anulado_motivo, pg.reverso_clase,
            pe.id AS pedido_id, pe.codigo, pe.tipo AS tipo_venta,
            pe.comprobante_tipo, pe.comprobante_serie, pe.comprobante_numero,
            c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos, c.documento,
            li.valor AS metodo,
            ua.nombre AS asesor_nombre, ua.apellidos AS asesor_apellidos,
            o.nombre AS oficina
       FROM pagos pg
       JOIN pedidos pe          ON pe.id = pg.pedido_id
       JOIN clientes c          ON c.id  = pe.cliente_id
       LEFT JOIN lista_items li ON li.id = pg.metodo_item_id
       LEFT JOIN usuarios ua    ON ua.id = pe.asesor_id
       LEFT JOIN oficinas o     ON o.id  = pe.oficina_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY pg.fecha DESC, pg.id DESC
      LIMIT ' . REPORTE_PAGOS_TOPE, $par);

/* Las tres tarjetas de arriba cuentan DINERO DE VERDAD, pase lo que pase con
   el filtro de estado. Y «dinero de verdad» es PAGO_CUENTA y nada más: anulado
   = 0 Y verificado = 1, la misma definición que mide la meta, el podio y los
   bonos. Al sacarlas del filtro se quitó también `verificado = 1` y la tarjeta
   pasó a sumar pagos sin confirmar: decía S/ 7,760 mientras su propio desglose,
   diez centímetros más abajo, sumaba S/ 3,047 — y el desglose era el bueno. */
$where_real = array_values(array_filter($where,
    fn($c) => !in_array($c, ['pg.anulado = 1', 'pg.denegado = 1',
                             'pg.verificado = 1', 'pg.verificado = 0'], true)));
$where_real[] = 'pg.anulado = 0';
$where_real[] = 'pg.verificado = 1';

/* EL DESGLOSE ES DEL DINERO DE VERDAD, no de lo que pida el filtro de estado.
   Es el desglose de la tarjeta «Ingresado» que tiene justo encima, y sacándolo
   de las filas filtradas decía una cosa mientras la tarjeta decía otra —el
   fallo que este proyecto arrastra—. El filtro de estado gobierna EL DETALLE
   de abajo, que es una lista de pagos; estas dos cajas son dinero cobrado, la
   misma cifra que mide la meta. */
$filas_dinero = todas(
    'SELECT pg.fecha, pg.monto_centimos, li.valor AS metodo
       FROM pagos pg
       JOIN pedidos pe          ON pe.id = pg.pedido_id
       LEFT JOIN lista_items li ON li.id = pg.metodo_item_id
       LEFT JOIN usuarios ua    ON ua.id = pe.asesor_id
      WHERE ' . implode(' AND ', $where_real) . '
      ORDER BY pg.fecha DESC LIMIT ' . REPORTE_PAGOS_TOPE, $par);

/* Se agrupa en PHP y no con DATE_FORMAT: así la misma consulta corre igual en
   MySQL y en el banco de pruebas, y no hay dos versiones del reporte. */
$grupos = [];
$por_metodo = [];
$total = 0; $pendiente = 0; $devuelto = 0;

foreach ($filas_dinero as $f) {
    $t = strtotime((string)$f['fecha']);
    $clave = match ($agrupar) {
        'mes'    => date('Y-m', $t),
        'semana' => date('o-\WW', $t),
        default  => date('Y-m-d', $t),
    };
    $etiqueta = match ($agrupar) {
        'mes'    => mes_en_letras(date('Y-m', $t)),
        'semana' => 'Semana del ' . date('d/m', strtotime('monday this week', $t)),
        default  => fecha_corta((string)$f['fecha']),
    };
    if (!isset($grupos[$clave])) {
        $grupos[$clave] = ['etiqueta' => $etiqueta, 'monto' => 0, 'n' => 0, 'orden' => $clave];
    }
    $grupos[$clave]['monto'] += (int)$f['monto_centimos'];
    $grupos[$clave]['n']++;

    $m = (string)($f['metodo'] ?: 'Sin método');
    $por_metodo[$m] = ($por_metodo[$m] ?? 0) + (int)$f['monto_centimos'];

    $total += (int)$f['monto_centimos'];
}
krsort($grupos);
arsort($por_metodo);

/* Lo que espera confirmación se pregunta APARTE, sin el filtro de estado.
   Salía de recorrer las filas ya filtradas, y como el filtro nace en
   «validados», la tarjeta solo podía decir «S/ 0 · nada pendiente» — a quince
   centímetros del chip del menú que decía 212. Un contador que solo puede
   valer cero enseña a no creerle a los demás. */
$where_pend = $where;
$par_pend   = $par;
/* «pg.denegado = 1» va en esta lista igual que los demás trozos del filtro de
   estado. Sin él, con «Solo denegados» las tres tarjetas de dinero pedían
   `denegado = 1 AND anulado = 0 AND verificado = 1`, que no puede casar con
   nada: la pantalla contestaba «Ingresado S/ 0.00 · Esperando validación
   S/ 0.00» en un mes en que sí entró dinero. */
foreach (['pg.verificado = 1', 'pg.verificado = 0', 'pg.anulado = 1', 'pg.denegado = 1'] as $quitar) {
    $i = array_search($quitar, $where_pend, true);
    if ($i !== false) unset($where_pend[$i]);
}
$where_pend[] = 'pg.verificado = 0';
$where_pend[] = 'pg.anulado = 0';
$pendiente = (int) valor(
    'SELECT COALESCE(SUM(pg.monto_centimos),0)
       FROM pagos pg
       JOIN pedidos pe       ON pe.id = pg.pedido_id
       LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
      WHERE ' . implode(' AND ', $where_pend), array_values($par_pend));

/* Y el total de verdad, con un agregado y no sumando las filas traídas: el
   detalle corta en REPORTE_PAGOS_TOPE, y sumar solo lo que cupo hacía que la
   cifra grande de arriba se quedara corta sin decirlo. */


$resumen_real = una(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(pg.monto_centimos),0) AS monto,
            COALESCE(SUM(CASE WHEN pg.tipo = 'devolucion'
                              THEN -pg.monto_centimos ELSE 0 END),0) AS devuelto
       FROM pagos pg
       JOIN pedidos pe       ON pe.id = pg.pedido_id
       LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
      WHERE " . implode(' AND ', $where_real), $par);
$total          = (int)($resumen_real['monto'] ?? 0);
/* CUÁNTOS pagos componen ese «Ingresado». La tarjeta enseñaba al lado
   $todas_las_filas, que cuenta lo que pide el FILTRO DE ESTADO: con «Denegados
   y quitados» decía «Ingresado S/ 3,047.00 · 5 pagos», y esos 5 son justo los
   que NO ingresaron y no aportan un céntimo a esa cifra. Una tarjeta no puede
   sumar una población y contar otra. */
$n_real         = (int)($resumen_real['n'] ?? 0);
/* «Devuelto» también sale del agregado y no del recorrido de $filas. Se quedó
   fuera en la corrección anterior y el aviso de al lado juraba que las tres
   cifras contaban todo: pasadas las REPORTE_PAGOS_TOPE filas, una devolución
   vieja desaparecía de la tarjeta mientras la pantalla decía que no. */
$devuelto       = (int)($resumen_real['devuelto'] ?? 0);
/* Cuántas filas hay DE LAS QUE PIDE EL FILTRO, no de las que cuentan dinero:
   este número se compara con las filas traídas para decidir si hubo recorte, y
   con dos poblaciones distintas decía «se muestran 2 de 9» sin haber recortado
   nada, pidiéndole a la persona que acortara un rango que estaba bien. */
$pagos_en_rango = (int) valor(
    'SELECT COUNT(*)
       FROM pagos pg
       JOIN pedidos pe       ON pe.id = pg.pedido_id
       LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
      WHERE ' . implode(' AND ', $where), $par);
$recortado      = $pagos_en_rango > count($filas);

/* ── VENTAS SIN COMPROBANTE ───────────────────────────────────────
   Se cuentan sobre PEDIDOS y no sobre pagos, y esa es toda la diferencia.
   Colgado del listado de pagos, el filtro no podía cumplir lo que prometía:
   una pre venta que todavía no ha adelantado nada no tiene ni un pago, así que
   no aparecía nunca; y con el estado por defecto —«solo validados»— la lista
   salía VACÍA justo cuando importa, porque una venta recién hecha todavía no
   tiene ningún pago confirmado. Es decir: marcabas la casilla, no veías nada y
   concluías que estaba todo emitido.
   Va por la FECHA DEL PEDIDO, que es la de la venta. */
$where_pedidos = ['pe.anulado_en IS NULL', 'pe.fecha >= ?', 'pe.fecha <= ?', $sql_ambito];
$par_pedidos   = array_merge([$desde, $hasta], $par_ambito);
if ($paises_reporte) { $where_pedidos[] = 'pe.pais_id = ?'; $par_pedidos[] = $pais_reporte; }
if ($asesor)         { $where_pedidos[] = 'pe.asesor_id = ?'; $par_pedidos[] = $asesor; }
$where_pedidos[] = "(pe.comprobante_tipo IS NULL OR pe.comprobante_tipo = '')";

$sin_comprobante = todas(
    'SELECT pe.id, pe.codigo, pe.fecha, pe.total_centimos,
            c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
            ua.nombre AS asesor_nombre
       FROM pedidos pe
       JOIN clientes c       ON c.id  = pe.cliente_id
       LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
      WHERE ' . implode(' AND ', $where_pedidos) . '
      ORDER BY pe.fecha ASC, pe.id ASC LIMIT 100', $par_pedidos);

$n_sin_comprobante = (int) valor(
    'SELECT COUNT(*) FROM pedidos pe
       LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
      WHERE ' . implode(' AND ', $where_pedidos), $par_pedidos);

/* La MISMA población sin el corte de fechas. El rango nace en el mes en curso,
   así que una pre venta de hace tres meses que se dejó en «omitir por ahora»
   no salía y la tarjeta parecía decir que estaba todo emitido — el filtro la
   tapaba, no es que no existiera. No son dos cifras del mismo dato: son la
   misma cuenta con y sin rango, y la pantalla dice cuál es cuál. */
$where_todo = array_values(array_filter($where_pedidos,
    fn($w) => $w !== 'pe.fecha >= ?' && $w !== 'pe.fecha <= ?'));
$par_todo   = array_slice($par_pedidos, 2);

$n_sin_comprobante_todo = (int) valor(
    'SELECT COUNT(*) FROM pedidos pe
       LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
      WHERE ' . implode(' AND ', $where_todo), $par_todo);

/* ── Descarga a Excel ─────────────────────────────────────────────
   Se exporta el DETALLE, no el resumen: quien pide el archivo lo quiere para
   cruzarlo con el extracto del banco. El monto sale como número, no como
   texto «S/ 379.00», para que en Excel se pueda sumar. */
if (pedir('excel', 'get') === '1') {
    exigir('reportes.exportar');
    require_once HUB_APP . '/nucleo/hoja.php';

    $datos = [];
    foreach ($filas as $f) {
        $datos[] = [
            'fecha'    => $f['fecha'],
            'pedido'   => $f['codigo'],
            'cliente'  => trim((string)$f['cliente_nombre'] . ' ' . (string)$f['cliente_apellidos']),
            'documento'=> $f['documento'],
            'banco'    => $f['metodo'] ?: '',
            'operacion'=> $f['operacion'] ?: '',
            'monto'    => (int)$f['monto_centimos'],
            'estado'   => (string)$f['tipo'] === 'devolucion'
                          ? ((string)($f['reverso_clase'] ?? '') === 'error'
                             ? 'Confirmación deshecha' : 'Devuelto al cliente')
                        : ((int)($f['denegado'] ?? 0) === 1 ? 'Denegado'
                        : ((int)($f['anulado'] ?? 0) === 1 ? 'Quitado'
                        : ((int)$f['verificado'] === 1 ? 'Validado'
                        : ((int)($f['en_espera'] ?? 0) === 1 ? 'En espera' : 'Pendiente')))),
            'motivo'   => (string)($f['anulado_motivo'] ?? ''),
            'venta'    => tipo_venta_texto((string)$f['tipo_venta']),
            'asesor'   => trim((string)$f['asesor_nombre'] . ' ' . (string)$f['asesor_apellidos']),
            'oficina'  => $f['oficina'] ?: '',
            'voucher'  => $f['voucher'] ? 'Sí' : 'No',
            'comprobante' => pedido_comprobante_texto($f) ?: ((string)($f['comprobante_tipo'] ?? '') === 'ninguno'
                             ? 'No lleva' : 'Sin emitir'),
        ];
    }
    bitacora('reporte.pagos.excel', 'reporte', null, ['desde' => $desde, 'hasta' => $hasta, 'filas' => count($datos)]);

    descargar_hoja('pagos-' . $desde . '-a-' . $hasta, [
        ['t' => 'Fecha',            'k' => 'fecha',     'tipo' => 'fecha'],
        ['t' => 'Pedido',           'k' => 'pedido',    'tipo' => 'texto'],
        ['t' => 'Cliente',          'k' => 'cliente',   'tipo' => 'texto'],
        ['t' => 'Documento',        'k' => 'documento', 'tipo' => 'texto'],
        ['t' => 'Banco / método',   'k' => 'banco',     'tipo' => 'texto'],
        ['t' => 'N.º de operación', 'k' => 'operacion', 'tipo' => 'texto'],
        ['t' => 'Monto',            'k' => 'monto',     'tipo' => 'dinero'],
        ['t' => 'Estado',           'k' => 'estado',    'tipo' => 'texto'],
        ['t' => 'Motivo',           'k' => 'motivo',    'tipo' => 'texto'],
        ['t' => 'Tipo de venta',    'k' => 'venta',     'tipo' => 'texto'],
        ['t' => 'Asesor',           'k' => 'asesor',    'tipo' => 'texto'],
        ['t' => 'Oficina',          'k' => 'oficina',   'tipo' => 'texto'],
        ['t' => 'Voucher',          'k' => 'voucher',   'tipo' => 'texto'],
        ['t' => 'Comprobante',      'k' => 'comprobante','tipo' => 'texto'],
    ], $datos);
}

function mes_en_letras(string $periodo): string
{
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto',
              'setiembre','octubre','noviembre','diciembre'];
    [$a, $m] = explode('-', $periodo);
    return ucfirst($meses[(int)$m - 1]) . ' ' . $a;
}

pagina('reportes/pagos', [
    'filas' => array_slice($filas, 0, 200),
    'todas_las_filas' => $pagos_en_rango,
    'traidas'         => count($filas),
    'recortado'       => $recortado,
    'grupos' => $grupos, 'por_metodo' => $por_metodo,
    'total' => $total, 'pendiente' => $pendiente, 'devuelto' => $devuelto,
    'desde' => $desde, 'hasta' => $hasta, 'agrupar' => $agrupar,
    'estado' => $estado, 'metodo' => $metodo, 'asesor' => $asesor, 'n_real' => $n_real,
    'denegados_por_asesor' => $denegados_por_asesor,
    'sin_comprobante' => $sin_comprobante, 'n_sin_comprobante' => $n_sin_comprobante,
    'n_sin_comprobante_todo' => $n_sin_comprobante_todo,
    'paises_reporte' => $paises_reporte, 'pais_reporte' => $pais_reporte,
    'metodos' => lista('metodos_pago', (int)$u['pais_id']),
    'asesores' => todas("SELECT us.id, us.nombre, us.apellidos FROM usuarios us
                           JOIN roles r ON r.id = us.rol_id AND r.clave = 'asesor'
                          WHERE us.activo = 1"
                        . (cruza_paises($u) ? '' : ' AND us.pais_id = ' . (int)$u['pais_id'])
                        . ' ORDER BY us.nombre'),
], ['titulo' => 'Pagos ingresados', 'migaja' => 'Reportes',
    'subtitulo' => 'El dinero que entró. Es otro reporte que el de ventas, y casi nunca coinciden']);
