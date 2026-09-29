<?php
declare(strict_types=1);
seccion_activa('pedidos');

$u = yo();

/* Cuántos pedidos caben en una pantalla. Con más, se avisa y se pide afinar la
   búsqueda: una lista que corta en silencio enseña una cifra que no es. */
const PEDIDOS_EN_LISTA = 300;

/* El HUB no tiene reloj propio: solo hace cosas cuando alguien entra. Las
   tareas de fondo —hoy, anular los pedidos cuyo pago denegó facturación y
   nadie arregló en 48 horas— se disparan aquí, con freno de una hora, además
   del cron de tareas.php. Así la regla se cumple aunque nadie configure el
   cron. Nunca puede tumbar la pantalla: se traga sus propios errores. */
tareas_del_hub(true);
/* La cola del stock de la web, de paso, por si el cron no está puesto (3e). */
stock_cola_de_paso();

$q      = pedir('q', 'get');
/* «TODOS» POR DEFECTO. Entraba en «Con saldo» y escondía media lista sin
   decirlo —en la prueba del usuario, 4 pedidos de 7—: una pantalla que se
   llama «Pedidos» y enseña algunos es una pantalla que miente. Lo pidió el
   usuario el 2026-09-11. */
$ver    = pedir('v', 'get', 'todos');
$tipo   = pedir('t', 'get', '');

/* Mismo aparato de ámbito que en clientes, y por el mismo motivo: el líder de
   equipo LEE los pedidos de los suyos, pero escribir se comprueba aparte. */
[$sql_ambito, $par] = filtro_ambito('p.asesor_id', 'p.pais_id', 'ua.equipo_id');
$where = [$sql_ambito];

if ($ver === 'abiertos')  $where[] = "e.clave <> 'anulado' AND (p.total_centimos - p.cobrado_centimos) > 0";
if ($ver === 'anulados')  $where[] = "e.clave = 'anulado'";
if ($ver === 'mios')      { $where[] = 'p.asesor_id = ?'; $par[] = (int)$u['id']; }
/* «DE HOY» ES LO QUE SE REGISTRÓ HOY, no lo que está FECHADO hoy. Desde que la
   fecha del pedido la marca la del pago, el caso más común del negocio —el
   cliente yapeó anoche, el asesor registra la venta esta mañana— nace fechado
   ayer, y la venta que el asesor acababa de hacer no salía en su propio filtro
   «De hoy». Aquí se pregunta por la actividad del día; para el dinero por
   periodo está el reporte, que sí va por la fecha de la venta. */
if ($ver === 'hoy')       { $where[] = 'DATE(p.creado_en) = ?'; $par[] = date('Y-m-d'); }
/* Las ventas entregadas con saldo por registrar: a donde lleva el aviso de
   Administración. Sin las columnas del despacho no hay nada que buscar. */
if ($ver === 'porregistrar') {
    if (!columna_existe('pedidos', 'despacho_en')) {
        $where[] = '1 = 0';
    } else {
        /* EL MISMO PLAZO QUE EL AVISO DE QUIEN MIRA: la bandeja de
           Administración dice «3 saldos sin registrar» contando desde el
           tercer día, y la lista a la que lleva tiene que traer esos tres, no
           también los de ayer (auditoría del 2s). */
        $dias_lista = puede('usuarios.gestionar') ? saldo_admin_dias() : saldo_aviso_dias();
        [$sql_desde, $par_desde] = sql_saldo_desde_hace('p', $dias_lista);
        $where[] = sql_saldo_por_registrar('p') . ' AND ' . $sql_desde;
        $par = array_merge($par, $par_desde);
    }
}
/* ── DOS PESTAÑAS: STOCK Y PRE VENTA (3j, usuario 2026-09-28: «las ventas de
   pre venta deben aparecer en un bloque separado a las ventas de stock»).
   Cada una con sus filtros: en pre venta, el lote y si ya está lista para
   entrega o sigue en camino. */
$tab = pedir('tab', 'get', 'stock') === 'preventa' || $tipo === 'preventa' ? 'preventa' : 'stock';
$where_tab = $tab === 'preventa' ? "p.tipo = 'preventa'" : "p.tipo <> 'preventa'";
$lote_f = (int) pedir_int('lo', 'get');
$pv_f = (string) pedir('pv', 'get', '');
if (!in_array($pv_f, ['listo', 'camino'], true)) $pv_f = '';
if ($tab === 'preventa') {
    $tipo = '';
    if ($lote_f) {
        $where[] = 'EXISTS (SELECT 1 FROM pedido_lineas lpl JOIN lote_lineas lll ON lll.id = lpl.lote_linea_id
                             WHERE lpl.pedido_id = p.id AND lll.lote_id = ?)';
        $par[] = $lote_f;
    }
    if ($pv_f === 'listo')  $where[] = "p.anulado_en IS NULL AND p.despacho_veces = 0 AND " . sql_preventa_lista('p');
    if ($pv_f === 'camino') $where[] = "p.anulado_en IS NULL AND NOT " . sql_preventa_lista('p');
} else {
    $lote_f = 0; $pv_f = '';
    if ($tipo === 'preventa') $tipo = '';
}
if (tipo_venta_valido($tipo)) { $where[] = 'p.tipo = ?'; $par[] = $tipo; }

/* ── POR ASESOR Y POR ESTADO (usuario, 2026-09-23) ───────────────────
   Dos filtros que ya se podían mirar de uno en uno abriendo pedidos, y que
   son las dos preguntas de Administración: «¿qué tiene Fulano?» y «¿qué está
   en camino?». El asesor solo se ofrece a quien ve más de una cartera: para
   el asesor, «Míos» ya es su filtro. */
$asesor_f = (int) pedir_int('a', 'get');
$estado_f = (string) pedir('es', 'get', '');
$ve_otras_carteras = $u['ambito'] !== 'propio';
if (!$ve_otras_carteras) $asesor_f = 0;
if ($asesor_f) { $where[] = 'p.asesor_id = ?'; $par[] = $asesor_f; }
$estados_lista = todas('SELECT clave, nombre FROM pedido_estados ORDER BY orden, id');
/* Cada pestaña, con los estados de su camino. */
$de_tab = $tab === 'preventa'
    ? ['reservado', 'en_camino', 'llego', 'listo', 'en_despacho', 'alistado', 'entregado', 'anulado']
    : ['registrado', 'pagado', 'en_despacho', 'alistado', 'entregado', 'anulado'];
$estados_lista = array_values(array_filter($estados_lista, fn($x) => in_array((string)$x['clave'], $de_tab, true)));
$claves_estado = array_column($estados_lista, 'clave');
if ($estado_f !== '' && in_array($estado_f, $claves_estado, true)) {
    $where[] = 'e.clave = ?';
    $par[] = $estado_f;
} else {
    $estado_f = '';
}

/* ── LIMA O PROVINCIA (usuario, 2026-09-20) ──────────────────────────
   Son dos operaciones distintas —una la reparte Waka, la otra va por agencia—
   y hasta ahora había que abrirlas una por una para saber cuál era cuál.
   Un recojo en oficina no es ninguna de las dos: no tiene distrito, así que se
   queda fuera de los dos filtros en vez de colarse en «Lima». */
$zona = pedir('z', 'get', '');
if (!in_array($zona, ['lima', 'provincia'], true)) $zona = '';
/* La columna del distrito es de las migraciones, no del CREATE TABLE: sin ella
   —un HUB al que le falta el último paso— este filtro tumbaba la lista entera,
   que es justo lo que el resto de la consulta se cuida de no hacer. Sin
   columna no hay filtro, y los enlaces tampoco se pintan. */
$hay_zona = columna_existe('pedidos', 'ubigeo_id');
if (!$hay_zona) $zona = '';
if ($zona !== '') {
    $where[] = "p.ubigeo_id IS NOT NULL AND " . ($zona === 'lima' ? '' : 'NOT ')
             . sql_pedido_es_lima('p');
}

if ($q !== '') {
    $where[] = '(p.codigo LIKE ? OR c.nombre LIKE ? OR c.apellidos LIKE ? OR c.documento LIKE ?)';
    $like = '%' . like_seguro($q) . '%';
    array_push($par, $like, $like, $like, $like);
}

/* El saldo sin registrar de cada fila, con EL MISMO plazo que el aviso de
   Administración (pedido_saldo_sin_registrar): el ★ del menú no puede
   adelantarse a la bandeja. Sus parámetros van delante de los del WHERE. */
$par_wa = [];
$sql_desde_wa = '1 = 1';
if (columna_existe('pedidos', 'despacho_en')) {
    [$sql_desde_wa, $par_wa] = sql_saldo_desde_hace('p', saldo_admin_dias());
}
$pedidos = todas(
    /* El estado del pago viene EN LA MISMA CONSULTA y no preguntando por
       cada fila: con 300 pedidos eso eran 300 consultas más. Sale del mismo
       sitio que el de la ficha —sql_estado_pago()—, para que las dos pantallas
       no puedan decir cosas distintas del mismo pedido. */
    "SELECT p.*, e.clave AS estado, e.nombre AS estado_nombre, e.color AS estado_color,
            c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos, c.documento,
            ua.nombre AS asesor_nombre, ua.apellidos AS asesor_apellidos, ua.celular AS asesor_celular,
            (" . sql_estado_pago('p.id') . ") AS estado_pago,
            " . (columna_existe('pedidos', 'despacho_en')
                 ? "(CASE WHEN " . sql_saldo_por_registrar('p') . " AND " . $sql_desde_wa . "
                         THEN p.total_centimos - p.cobrado_centimos
                              - COALESCE((SELECT SUM(sp.monto_centimos) FROM pagos sp
                                           WHERE sp.pedido_id = p.id AND sp.anulado = 0 AND sp.verificado = 0), 0)
                         ELSE 0 END)"
                 : '0') . " AS saldo_sin_registrar,
            (CASE WHEN " . sql_despacho_listo('p') . " THEN 1 ELSE 0 END) AS despacho_listo
       FROM pedidos p
       JOIN pedido_estados e ON e.id = p.estado_id
       JOIN clientes c       ON c.id = p.cliente_id
       LEFT JOIN usuarios ua ON ua.id = p.asesor_id
      WHERE " . implode(' AND ', array_merge([$where_tab], $where)) . "
      ORDER BY p.id DESC LIMIT " . (PEDIDOS_EN_LISTA + 1),
    array_merge($par_wa, $par)
);
/* Cuántas ventas vivas hay en cada pestaña, de las que uno puede ver (sin los
   filtros): el número de la otra dice si vale la pena cambiar. */
[$amb_n, $par_n] = filtro_ambito('p.asesor_id', 'p.pais_id', 'ua.equipo_id');
$tab_n = ['stock' => 0, 'preventa' => 0];
foreach (todas("SELECT CASE WHEN p.tipo = 'preventa' THEN 'preventa' ELSE 'stock' END AS t, COUNT(*) AS n
                  FROM pedidos p LEFT JOIN usuarios ua ON ua.id = p.asesor_id
                 WHERE $amb_n AND p.anulado_en IS NULL GROUP BY 1", $par_n) as $tn) {
    $tab_n[(string)$tn['t']] = (int)$tn['n'];
}
/* La pre venta que todavía no puede salir dice cuándo llega (en vez de «Mandar»). */
$esperas = [];
if ($tab === 'preventa') {
    $ids_pv = array_map(fn($x) => (int)$x['id'], array_filter($pedidos, fn($x) => (int)($x['despacho_veces'] ?? 0) === 0 && $x['anulado_en'] === null));
    foreach (preventa_situaciones($ids_pv) as $pid_e => $sit_e) $esperas[$pid_e] = preventa_espera_texto($sit_e);
}
$lotes_filtro = $tab === 'preventa'
    ? todas("SELECT DISTINCT l.id, l.nombre, l.codigo FROM lotes l
               JOIN lote_lineas ll ON ll.lote_id = l.id JOIN pedido_lineas pl ON pl.lote_linea_id = ll.id
              WHERE " . (cruza_paises($u) ? '1 = 1' : 'l.pais_id = ' . (int)$u['pais_id']) . " ORDER BY l.id DESC LIMIT 60")
    : [];

/* SE PIDE UNA FILA DE MÁS para saber si había más, y se dice. La consulta
   cortaba en 300 y la cabecera contaba lo que cupo: con 320 pedidos, «300
   pedidos · S/ X por cobrar» era mentira y nada en pantalla lo decía. El
   reporte de pagos sí avisa; esta pantalla no lo hacía. */
$recortada = count($pedidos) > PEDIDOS_EN_LISTA;
if ($recortada) array_pop($pedidos);

/* QUÉ LLEVA CADA VENTA (usuario, 2026-09-23). En una sola consulta para toda
   la lista, no una por fila: con 300 pedidos serían 300 consultas más. */
$lineas_de = pedidos_lineas_de(array_map(fn($x) => (int)$x['id'], $pedidos));

$total_saldo = 0;
/* SIN LOS ANULADOS. Al anular, `cobrado_centimos` baja a 0 y `total_centimos`
   se conserva, así que cada venta anulada sumaba su total entero al «por
   cobrar» — y desde que el filtro por defecto es «Todos», salían todas. La
   cabecera decía S/ 2,615 donde el Inicio decía S/ 2,236, y la diferencia era
   exactamente el pedido anulado. Las otras seis cifras de «por cobrar» del HUB
   ya los excluyen; esta era la séptima y la que estaba mal. */
foreach ($pedidos as $p) {
    if ((string)$p['estado'] === 'anulado') continue;
    $total_saldo += max(0, (int)$p['total_centimos'] - (int)$p['cobrado_centimos']);
}

/* Pagos esperando validación: solo lo ve quien los puede validar, y SALE DE
   pagos_por_validar_resumen(). Estaba escrito a mano aquí, con el país a pelo
   y sin el ámbito, así que en la misma pantalla el chip del menú decía 2 y el
   aviso de arriba decía 6 — y a una cuenta de ámbito estrecho le prometía seis
   pagos que su bandeja no le iba a enseñar. Es el fallo que más se ha repetido
   en este proyecto y siempre nace igual: una consulta escrita a mano al lado
   de la función que ya define esa cifra. */
$por_validar = puede('pagos.verificar')
    ? pagos_por_validar_resumen($u)['sin_revisar']
    : 0;

/* VENTAS LISTAS PARA MANDAR A DESPACHO. Sale de contador_de('despacho'), que
   es el MISMO número del chip del menú y de la pantalla «Por mandar a
   despacho»: escrita aquí a mano, esta cifra y la del menú acabarían diciendo
   cosas distintas en la misma pantalla, que es el fallo que más veces ha
   mordido a este proyecto.
   El aviso existe porque el asesor pasa el día en «Pedidos» y no en el menú:
   cuando facturación le confirma un pago, la venta queda libre y nadie se lo
   dice donde está mirando (usuario, 2026-09-14). */
$listas_despacho = puede('pedidos.despachar') ? contador_de('despacho') : 0;

/* La lista de asesores del filtro: los del ámbito de quien mira, no todos. */
$asesores_filtro = [];
if ($ve_otras_carteras) {
    [$amb_as, $par_as] = filtro_ambito('us.id', 'us.pais_id', 'us.equipo_id');
    $asesores_filtro = todas("SELECT us.id, us.nombre, us.apellidos FROM usuarios us
                               JOIN roles r ON r.id = us.rol_id AND r.clave = 'asesor'
                              WHERE us.activo = 1 AND $amb_as ORDER BY us.nombre", $par_as);
}

pagina('pedidos/lista', [
            'pedidos' => $pedidos, 'q' => $q, 'ver' => $ver, 'tipo' => $tipo,
    'lineas_de' => $lineas_de,
    'asesor_f' => $asesor_f, 'estado_f' => $estado_f,
    'asesores_filtro' => $asesores_filtro, 'estados_lista' => $estados_lista,
    'zona' => $zona, 'hay_zona' => $hay_zona,
    'tab' => $tab, 'tab_n' => $tab_n, 'lote_f' => $lote_f, 'pv_f' => $pv_f,
    'esperas' => $esperas, 'lotes_filtro' => $lotes_filtro,
    'total_saldo' => $total_saldo, 'por_validar' => $por_validar, 'recortada' => $recortada,
    'puede_anular' => puede('pedidos.anular'),
    'puede_facturar' => puede('pagos.verificar'),
    /* Mandar al grupo de despacho es tarea del asesor: Facturación y Dirección
       NO lo tienen. Sin esto el menú de la fila se lo ofrecía igual —y la
       estrella se enciende justo en la fila que facturación acaba de
       confirmar—, para contestarles «Esto no es para tu perfil». */
    'puede_despachar' => puede('pedidos.despachar'),
    'listas_despacho' => $listas_despacho,
    /* Se pregunta UNA vez y no por fila: en un HUB sin actualizar.php la
       columna no existe y el menú no ofrece pedir nada. */
    'hay_pide' => columna_existe('pedidos', 'comprobante_pide'),
], ['titulo' => 'Pedidos', 'sin_titulo' => true]);
