<?php
declare(strict_types=1);
seccion_activa('clientes');

$u   = yo();
$q   = pedir('q', 'get');
$ver = pedir('v', 'get', 'todos');

/* El ámbito se aplica en el SERVIDOR, en la misma capa que el país. Esconder
   el menú no es seguridad: pedir un cliente ajeno tiene que devolver 403.
   El equipo se mira por el equipo que HOY tiene el asesor (ua.equipo_id), no
   por uno copiado en el cliente: un líder tiene que ver a los suyos de ahora. */
[$sql_ambito, $par] = filtro_ambito('c.asesor_id', 'c.pais_id', 'ua.equipo_id');
$where = [$sql_ambito];

if ($ver === 'mios')     { $where[] = 'c.asesor_id = ?'; $par[] = (int)$u['id']; }
if ($ver === 'sin_correo') $where[] = "(c.email IS NULL OR c.email = '')";
if ($ver === 'sin_tienda') $where[] = "c.estado_tienda IN ('sin_cuenta','en_cola','error')";

if ($q !== '') {
    $where[] = '(c.documento LIKE ? OR c.nombre LIKE ? OR c.apellidos LIKE ?
                 OR c.celular LIKE ? OR c.email LIKE ?)';
    // Los comodines se escapan: buscar «_» tiene que buscar un guion bajo,
    // no «cualquier carácter». No hay inyección (todo va parametrizado), pero
    // sí resultados que nadie pidió.
    $like = '%' . like_seguro($q) . '%';
    array_push($par, $like, $like, $like, $like, $like);
}

$sql_where = 'WHERE ' . implode(' AND ', $where);

$clientes = todas(
    "SELECT c.*, ua.nombre AS asesor_nombre, ua.apellidos AS asesor_apellidos,
            (SELECT COUNT(*) FROM pedidos p
              WHERE p.cliente_id = c.id AND p.anulado_en IS NULL) AS pedidos,
            (SELECT COALESCE(SUM(p.total_centimos - p.cobrado_centimos),0) FROM pedidos p
              WHERE p.cliente_id = c.id AND p.anulado_en IS NULL) AS saldo,
            (SELECT MAX(p.fecha) FROM pedidos p WHERE p.cliente_id = c.id) AS ultima_compra
       FROM clientes c
       LEFT JOIN usuarios ua ON ua.id = c.asesor_id
       $sql_where
      ORDER BY c.activo DESC, c.creado_en DESC
      LIMIT 300",
    $par
);

/* El saldo de cashback se pide de una vez para los que se están pintando, no
   uno por fila: con 300 clientes serían 300 consultas para una columna. */
$saldos = [];
if ($clientes) {
    $ids = array_column($clientes, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (todas(
        "SELECT cliente_id, SUM(monto_centimos - consumido_centimos) AS vivo
           FROM cashback_movimientos
          WHERE tipo = 'acredita' AND cliente_id IN ($ph)
            AND (vence_en IS NULL OR vence_en >= ?)
          GROUP BY cliente_id", array_merge($ids, [date('Y-m-d')])) as $f) {
        $saldos[(int)$f['cliente_id']] = max(0, (int)$f['vivo']);
    }
}

$resumen = [
    'total'      => count($clientes),
    'sin_correo' => count(array_filter($clientes, fn($c) => trim((string)$c['email']) === '')),
    'en_cola'    => tienda_en_cola((int)$u['pais_id']),
];

pagina('clientes/lista', [
    'clientes' => $clientes, 'saldos' => $saldos, 'q' => $q,
    'ver' => $ver, 'resumen' => $resumen,
], ['titulo' => 'Clientes', 'sin_titulo' => true]);
