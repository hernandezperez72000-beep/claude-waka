<?php
declare(strict_types=1);
seccion_activa('config');

$u = yo();
$busca  = pedir('q', 'get');
$filtro = pedir('f', 'get', 'activos');

$where = [];
$par   = [];

// Administración se queda en su país. Dirección cruza países.
if (!cruza_paises($u)) {
    $where[] = 'us.pais_id = ?';
    $par[]   = $u['pais_id'];
}
if ($filtro === 'activos')   $where[] = 'us.activo = 1';
if ($filtro === 'apagados')  $where[] = 'us.activo = 0';
if ($filtro === 'sin_equipo') { $where[] = "us.equipo_id IS NULL AND r.clave = 'asesor'"; }
if ($busca !== '') {
    $where[] = '(us.nombre LIKE ? OR us.apellidos LIKE ? OR us.email LIKE ?)';
    $like = '%' . like_seguro($busca) . '%';
    array_push($par, $like, $like, $like);
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$usuarios = todas(
    "SELECT us.*, r.clave AS rol, r.nombre AS rol_nombre,
            o.nombre AS oficina, eq.nombre AS equipo, p.nombre AS pais
       FROM usuarios us
       JOIN roles r ON r.id = us.rol_id
       LEFT JOIN oficinas o  ON o.id  = us.oficina_id
       LEFT JOIN equipos eq  ON eq.id = us.equipo_id
       LEFT JOIN paises p    ON p.id  = us.pais_id
       $sql_where
      ORDER BY us.activo DESC, r.orden ASC, us.nombre ASC",
    $par
);

// La meta general es de un país. Si quien mira cruza países, no se pinta una
// sola cifra que sería falsa para todos menos para uno.
// La meta general es de un país: si quien mira cruza países, no se pinta una
// sola cifra que sería falsa para todos menos para uno.
$meta_general = cruza_paises($u) ? null : meta_general((int)$u['pais_id'], date('Y-m'));

$resumen = [
    'total'      => count($usuarios),
    'asesores'   => count(array_filter($usuarios, fn($x) => $x['rol'] === 'asesor' && (int)$x['activo'] === 1)),
    // Se cuenta sobre lo que se está listando, no sobre el país de quien mira:
    // si Dirección ve cuatro países, el contador tiene que hablar de los cuatro.
    'sin_equipo' => count(array_filter($usuarios,
        fn($x) => $x['rol'] === 'asesor' && !$x['equipo_id'] && (int)$x['activo'] === 1)),
    'apagados'   => count(array_filter($usuarios, fn($x) => (int)$x['activo'] === 0)),
];

pagina('usuarios/lista', [
    'usuarios' => $usuarios,
    'busca'    => $busca,
    'filtro'   => $filtro,
    'resumen'  => $resumen,
    'meta_general' => $meta_general,
], ['titulo' => 'Usuarios y roles', 'sin_titulo' => true]);
