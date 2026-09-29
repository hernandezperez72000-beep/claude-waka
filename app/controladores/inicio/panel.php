<?php
declare(strict_types=1);
seccion_activa('inicio');

$u = yo();
$hoy = date('Y-m-d');
$mes = date('Y-m');

/** Panel del asesor: su día. Panel de admin/dirección: la bandeja. */
$es_asesor = $u['rol'] === 'asesor';
/* Y el de quien ni vende ni lleva las cifras (3g: Almacén y Marketing): su
   bandeja y sus secciones. Por permiso: las cifras de dinero del panel de
   Administración son las de Reportes, y quien no ve Reportes no las ve aquí. */
$es_trabajo = !$es_asesor && !puede_el($u, 'reportes.ver');

$datos = [
    'saludo' => saludo_para($u),
    'fecha'  => fecha_larga(),
    'linea'  => linea_de_datos($u),
    'frase'  => frase_del_dia($u['rol'], franja_del_dia())['texto'] ?? '',
];

if ($es_trabajo) {
    $atajos = array_values(array_filter(menu_de($u), fn($m) => $m['clave'] !== 'inicio'));
    $datos += [
        'bandeja' => bandeja($u),
        'cumplen' => cumplen_hoy($u),
        'atajos'  => $atajos,
        /* Lo que cada uno tiene que mirar hoy, en una línea por sección. */
        'por_alistar'  => puede_el($u, 'pedidos.alistar') ? alistar_pendientes_n($u) : null,
        'sin_precio'   => puede_el($u, 'precios.editar') ? catalogo_sin_precio() : null,
    ];
} elseif ($es_asesor) {
    // Todo se ordena por dinero COBRADO: la misma cifra que la barra de meta.
    // La definición vive en pagos.php y solo ahí: un pago cuenta cuando está
    // VALIDADO. Escrita a mano aquí, una transferencia sin confirmar movería
    // la meta y el podio, que es justo lo que la validación existe para evitar.
    $cobrado_mes = cobrado_del_mes((int)$u['id'], $mes);
    $meta = meta_de($u, $mes);

    $datos += [
        'cobrado'   => $cobrado_mes,
        // Lo que registró y todavía nadie ha confirmado. No suma a la meta,
        // pero se le enseña: si no, "vendí y el HUB no me lo contó".
        'pendiente' => pendiente_del_mes((int)$u['id'], $mes),
        /* Ventas suyas con el pago ya confirmado que todavía no salieron. Va
           en el panel además del chip del menú porque es la primera pantalla
           que ve al entrar, y porque el chip dice cuántas pero no cuáles. */
        'por_despachar' => le_avisamos_de_despacho($u)
            ? pedidos_por_despachar_resumen($u)['n'] : 0,
        /* Los pagos suyos que facturación paró, CON su motivo. La lista y no
           solo el número: un aviso que dice «hay un problema» sin decir cuál
           obliga a entrar a buscarlo, y entonces no avisa, estorba. */
                'trabados'      => le_avisamos_de_trabados($u) ? pagos_trabados_lista($u, 8) : [],
        /* Sus ventas ya despachadas con saldo por registrar: el repartidor
           cobró y el voucher no está en el HUB. Desde el día siguiente al
           despacho (usuario, 2026-09-21). */
                'saldos'        => pedidos_saldo_pendiente($u, saldo_aviso_dias(), 8, true),
        'saldos_n'      => pedidos_saldo_pendiente_n($u, saldo_aviso_dias(), true),
        /* Sus garantías resueltas esta semana (3i): aprobadas o no, con el motivo. */
        'garantias_res' => garantias_resueltas_recientes($u, 7, 5),
        'meta'      => $meta,
        'avance'    => $meta > 0 ? min(100, (int) round($cobrado_mes * 100 / $meta)) : 0,
        'por_cobrar'=> (int) valor(
            'SELECT COALESCE(SUM(total_centimos - cobrado_centimos),0) FROM pedidos
              WHERE asesor_id = ? AND anulado_en IS NULL', [$u['id']]),
        /* «PEDIDOS DE HOY» ES ACTIVIDAD: lo que se registró hoy, no lo que
           está fechado hoy. Desde que la fecha del pedido la marca la del
           pago, la venta de un cliente que yapeó anoche nace fechada ayer y
           el contador no subía aunque el asesor acabara de hacerla. La misma
           regla que el filtro «De hoy» de /pedidos. */
        'pedidos_hoy' => (int) valor(
            'SELECT COUNT(*) FROM pedidos
              WHERE asesor_id = ? AND DATE(creado_en) = ? AND anulado_en IS NULL',
            [$u['id'], $hoy]),
        'clientes'  => (int) valor('SELECT COUNT(*) FROM clientes WHERE asesor_id = ? AND activo = 1', [$u['id']]),
        /* 5a · LA RACHA DE VERDAD: clientes distintos de hoy y días seguidos. */
        'racha_hoy' => racha_dia((int)$u['id']),
        'racha'     => racha_dias((int)$u['id']),
        /* La pila de novedades (una sola vez cada una) y Wakaciones, aparte. */
        'novedades' => (function () use ($u) { bonos_cerrar_de_paso(); return novedades_de($u); })(),
        'wakaciones'=> novedad_wakaciones($u),
        'lobos_titulo' => (string) ajuste('los_lobos_titulo', 'Los lobos del mes'),
        'montos_bloqueado' => (string) ajuste('logro_montos_bloqueado', '0') === '1',
        'podio'     => podio($u['pais_id'], $mes, 5),
        'mi_puesto' => mi_puesto($u, $mes),
    ];
} else {
    /* Dirección y Desarrollador cruzan países, y su cabecera lo dice: «Perú ·
       todos los países». Las cifras tienen que decir lo mismo. Estaban todas
       filtradas por el país de la cuenta, así que el CEO leía «Vendido hoy»
       creyendo que era la empresa y era solo Perú — y al lado, la línea del
       saludo, que sí cruza, daba otro número de las mismas visitas. */
    $cruza     = cruza_paises($u);
    $solo_pais = $cruza ? null : (int)$u['pais_id'];
    $y_pais    = $cruza ? '' : ' AND pais_id = ?';
    $par_pais  = $cruza ? [] : [(int)$u['pais_id']];

    /* EL DINERO NO CRUZA PAÍSES, y no es un descuido: Perú cobra en soles y
       México en pesos. Sumarlos da un número con símbolo de sol que no es
       ninguna cifra real, y es peor que no enseñar nada porque parece que sí.
       Lo que se cuenta en unidades —clientes, visitas, lotes— sí cruza.
       Cada tarjeta dice de dónde es su cifra; ver los rótulos en la vista. */
    $pais_dinero = (int)$u['pais_id'];

    $datos += [
        'bandeja'   => bandeja($u),
        /* Quién cumple años hoy: lo único que el HUB hace con la fecha de
           nacimiento que se pide al dar de alta la cuenta. */
        'cumplen'   => cumplen_hoy($u),
        'cruza_paises' => $cruza,
        'pais_dinero'  => $u['pais'] ?? 'Perú',
        /* Y lo VENDIDO hoy, igual: es la actividad del día del país. El dinero
           por periodo —el que sí va por la fecha de la venta— está en el
           reporte de pagos, que es donde se mira a propósito. */
        'vendido_hoy' => (int) valor(
            'SELECT COALESCE(SUM(total_centimos),0) FROM pedidos
              WHERE DATE(creado_en) = ? AND anulado_en IS NULL AND pais_id = ?',
            [$hoy, $pais_dinero]),
        'cobrado_hoy' => cobrado_del_pais($pais_dinero, $hoy, $hoy),
        'por_cobrar' => (int) valor(
            'SELECT COALESCE(SUM(total_centimos - cobrado_centimos),0) FROM pedidos
              WHERE anulado_en IS NULL AND pais_id = ?', [$pais_dinero]),
        'clientes_semana' => (int) valor(
            'SELECT COUNT(*) FROM clientes WHERE creado_en >= DATE_SUB(NOW(), INTERVAL 7 DAY)' . $y_pais,
            $par_pais),
        /* El ranking de equipos y el podio sí son por país: un equipo pertenece
           a un país y las metas se fijan por país. Mezclarlos sería comparar
           soles con pesos. Por eso llevan su rótulo en la pantalla. */
        'equipos'   => ranking_equipos($u['pais_id'], $mes),
        'top'       => podio($u['pais_id'], $mes, 5),
        // La bitácora es el rastro de lo que hace TODO el mundo. Se la queda
        // quien gobierna las cuentas, no quien confirma pagos.
        've_bitacora' => puede('usuarios.ver'),
        // Recepción, solo si la puede ver. Facturación no la ve: pintarle la
        // tarjeta le daba dos botones que contestan 403, y un botón que no
        // funciona enseña a desconfiar de todos los demás.
        've_recepcion' => puede('recepcion.ver'),
        'visitas'   => puede('recepcion.ver') ? todas(
            "SELECT v.*, o.nombre AS oficina, us.nombre AS asesor, p.nombre AS pais
               FROM visitas v
               JOIN oficinas o ON o.id = v.oficina_id
               LEFT JOIN paises p ON p.id = o.pais_id
               LEFT JOIN usuarios us ON us.id = v.asesor_id
              WHERE v.estado = 'esperando'" . ($cruza ? '' : ' AND o.pais_id = ?') . "
              ORDER BY v.creado_en ASC LIMIT 4", $cruza ? [] : [(int)$u['pais_id']]) : [],
        'bitacora'  => bitacora_reciente(5, $solo_pais),
    ];
}

/* ─────────────  Ayudas del panel  ───────────── */

function fecha_larga(): string
{
    $dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto',
              'setiembre','octubre','noviembre','diciembre'];
    $t = time();
    return ucfirst($dias[(int)date('w',$t)]) . ' ' . (int)date('j',$t) . ' de '
         . $meses[(int)date('n',$t)-1] . ' · ' . hora_am_pm($t);
}


/**
 * El podio y el ranking de equipos salen de pagos.php, que es donde vive la
 * definición de «dinero cobrado». Aquí solo se envuelven para no repetirla.
 *
 * Ojo con lo que estas consultas NO filtran: pedidos anulados. Al anular se
 * anota una fila negativa con la fecha de HOY, así que el mes en que entró el
 * dinero conserva su cifra y baja el mes de la reversión. Descontar el pedido
 * entero cambiaría el podio del mes pasado sin que nadie lo pidiera.
 */
function podio(int $pais_id, string $periodo, int $limite): array
{
    return cobrado_por_asesor($pais_id, $periodo, $limite);
}

function mi_puesto(array $u, string $periodo): array
{
    $todos = podio((int)$u['pais_id'], $periodo, 100);
    $total = count($todos);
    foreach ($todos as $i => $f) {
        if ((int)$f['id'] === (int)$u['id']) return ['puesto' => $i + 1, 'de' => $total];
    }
    return ['puesto' => 0, 'de' => $total];
}

function ranking_equipos(int $pais_id, string $periodo): array
{
    return cobrado_por_equipo($pais_id, $periodo);
}

/**
 * Lo que espera por esta persona hoy. Es una bandeja, no un tablero.
 *
 * Cada línea se pinta solo si quien mira PUEDE resolverla, y esa decisión ya
 * está tomada en contadores(): lo que allí vale cero, aquí no existe. Va por
 * permiso y no por lista de roles porque con una lista, cada rol nuevo obliga
 * a acordarse de este sitio y olvidarse no da error — solo deja una bandeja
 * vacía o una línea que al pulsarla contesta 403.
 */
function bandeja(array $u): array
{
    $c = contadores();
    $b = [];

    // Lo primero, lo que congela el dinero de todos: los pagos sin confirmar.
    if (!empty($c['pagos'])) {
        $b[] = ['n'=>$c['pagos'], 'texto'=>plural($c['pagos'],'pago sin revisar','pagos sin revisar', false),
                'urgente'=>true, 'ruta'=>'/pagos/por-validar'];
    }
    /* Las garantías que esperan aprobación (3i): la pieza no sale hasta que
       Administración diga que sí. */
    if (!empty($c['garantias'])) $b[] = ['n'=>$c['garantias'], 'texto'=>plural($c['garantias'],'garantía por aprobar','garantías por aprobar', false),
                                         'urgente'=>true, 'ruta'=>'/garantias?e=pedida'];
    if (!empty($c['errores']))  $b[] = ['n'=>$c['errores'], 'texto'=>plural($c['errores'],'error reportado','errores reportados', false), 'urgente'=>true,  'ruta'=>'/configuracion'];
    if (!empty($c['claves']))   $b[] = ['n'=>$c['claves'],  'texto'=>plural($c['claves'],'solicitud de contraseña','solicitudes de contraseña', false), 'urgente'=>true, 'ruta'=>'/configuracion'];
    if (!empty($c['lotes']))    $b[] = ['n'=>$c['lotes'],   'texto'=>plural($c['lotes'],'lote sin precios','lotes sin precios', false), 'urgente'=>false, 'ruta'=>'/stock'];
    /* Lo que las ventas no pudieron mover en la web (3e). */
    if (!empty($c['cola']))     $b[] = ['n'=>$c['cola'],    'texto'=>plural($c['cola'],'cambio de stock por mover en la web','cambios de stock por mover en la web', false), 'urgente'=>false, 'ruta'=>'/stock/pendientes'];
        if (!empty($c['recepcion']))$b[] = ['n'=>$c['recepcion'],'texto'=>plural($c['recepcion'],'visita en recepción','visitas en recepción', false), 'urgente'=>false, 'ruta'=>'/recepcion'];

    /* LOS PREMIOS DE BONOS POR PAGAR (5a): Administración los paga y los marca. */
    if (puede_el($u, 'bonos.pagar') && function_exists('bonos_listo') && bonos_listo()) {
        bonos_cerrar_de_paso();
        $pp = count(bonos_por_pagar((int)$u['pais_id']));
        if ($pp > 0) $b[] = ['n' => $pp, 'texto' => plural($pp, 'premio de bono por pagar', 'premios de bonos por pagar', false),
                             'urgente' => false, 'ruta' => '/bonos#por-pagar'];
    }

    /* LOS SALDOS QUE LOS ASESORES NO REGISTRARON, pasados los días del ajuste
       (tres, de entrada). Lo ve Administración para poder preguntar: el asesor
       ya tuvo su aviso desde el día siguiente (usuario, 2026-09-21). */
    if (avisa_saldos_sin_registrar($u)) {
        $sin_reg = pedidos_saldo_pendiente_n($u, saldo_admin_dias());
        if ($sin_reg > 0) {
            $b[] = ['n' => $sin_reg,
                    'texto' => plural($sin_reg, 'saldo sin registrar', 'saldos sin registrar', false),
                    'urgente' => true, 'ruta' => '/pedidos?v=porregistrar'];
        }
    }

    return $b;
}

pagina($es_trabajo ? 'inicio/trabajo' : ($es_asesor ? 'inicio/asesor' : 'inicio/admin'), $datos);
