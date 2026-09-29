<?php
declare(strict_types=1);

/**
 * BONOS (módulo 5) — UN MOTOR, NO SIETE BONOS.
 *
 * Los siete bonos de Waka tienen la misma forma: período × métrica ×
 * condición × premio (bonos_comerciales.md). Cada uno es una FILA de `bonos`
 * con sus reglas en JSON, editables desde Configuración › Bonos; uno nuevo es
 * una fila más, no un desarrollo.
 *
 * Las formas (lo único que vive en código):
 *   niveles   · La Yapa: cada asesor tiene su nivel de cuota; gana el bono del
 *               nivel más alto que alcance (desde el suyo), + Rápido + Dúo.
 *   escalera  · Cacería del Día: tramos por cantidad; gana el más alto que pase.
 *   puestos   · Alfa de la Semana / de la Quincena / Wakaciones: clasifica con
 *               un mínimo y gana por puesto; tramos que cobra todo el que llegue.
 *   primeros  · Sin Freno: los primeros en llegar a N clientes distintos.
 *   equipo    · La Manada: cada integrante un mínimo y el equipo junto otro.
 *
 * REGLAS QUE MANDAN (decisiones del usuario, ver decisiones-5a.md):
 *   · Todo se mide por DINERO COBRADO (pagos confirmados, por la fecha del pago).
 *   · Una «gestión» es una venta de stock registrada ese día; al cerrar solo
 *     cuentan las que Facturación confirmó y no se anularon.
 *   · Un período cerrado guarda sus reglas y sus cifras: no se recalcula.
 *   · Los bonos nacen apagados; cuentan desde el período en que se encienden.
 *   · La comisión no existe en la v1.
 *
 * El dinero, siempre en céntimos.
 */

/* ─────────────────────────  LOS BONOS DE FÁBRICA  ───────────────────────── */

/** ¿El HUB ya tiene las tablas y columnas del módulo 5? */
function bonos_listo(): bool
{
    return tabla_existe('bonos') && columna_existe('bonos', 'reglas') && columna_existe('bonos', 'cerrar_desde') && tabla_existe('bono_cierres')
        && tabla_existe('usuario_niveles') && tabla_existe('caceria_dias');
}

/** Las frases de estado de «Mis bonos» (elegidas por el usuario el 2026-09-04). */
const BONO_FRASE_POCO   = 'Dale con todo';
const BONO_FRASE_RITMO  = 'No pierdas el ritmo';
const BONO_FRASE_HECHO  = 'Ya la hiciste';
const BONO_FRASE_FUERA  = 'Ya fue';

/**
 * Los siete bonos tal como los dejó el cuadro del 2026-09-04, para sembrarlos
 * APAGADOS en cada país. Después, todo se cambia en Configuración.
 */
function bonos_de_fabrica(): array
{
    $sol = fn(float $s) => (int) round($s * 100);
    return [
        ['tipo' => 'yapa', 'nombre' => 'La Yapa Quincenal', 'anterior' => 'Bono Cuota Quincenal', 'periodo' => 'quincena', 'orden' => 10,
         'reglas' => ['forma' => 'niveles', 'metrica' => 'cobrado', 'sin_preventa' => false, 'minimo' => 0,
                      'niveles' => [
                          ['nombre' => 'Arranque', 'cuota' => $sol(20000), 'premio' => $sol(240)],
                          ['nombre' => 'Impulso',  'cuota' => $sol(25000), 'premio' => $sol(280)],
                          ['nombre' => 'Firme',    'cuota' => $sol(30000), 'premio' => $sol(330)],
                          ['nombre' => 'Fuerte',   'cuota' => $sol(35000), 'premio' => $sol(360)],
                          ['nombre' => 'Élite',    'cuota' => $sol(40000), 'premio' => $sol(400)],
                          ['nombre' => 'Máster',   'cuota' => $sol(50000), 'premio' => $sol(500)],
                          ['nombre' => 'Leyenda',  'cuota' => $sol(70000), 'premio' => $sol(600)],
                      ],
                      'rapido_dias' => 5, 'rapido_premio' => $sol(50), 'duo_premio' => $sol(100)]],
        ['tipo' => 'caceria', 'nombre' => 'Cacería del Día', 'anterior' => 'Bono Diario', 'periodo' => 'dia', 'orden' => 20,
         'reglas' => ['forma' => 'escalera', 'metrica' => 'gestiones', 'sin_preventa' => true, 'minimo' => 0,
                      'escalera' => [['desde' => 5, 'premio' => $sol(50)], ['desde' => 10, 'premio' => $sol(100)],
                                     ['desde' => 15, 'premio' => $sol(150)], ['desde' => 20, 'premio' => $sol(200)]]]],
        ['tipo' => 'alfa_semana', 'nombre' => 'Alfa de la Semana', 'anterior' => 'Bono Top Semanal', 'periodo' => 'semana', 'orden' => 30,
         'reglas' => ['forma' => 'puestos', 'metrica' => 'cobrado', 'sin_preventa' => true, 'minimo' => $sol(200),
                      'clasifica_monto' => $sol(27000), 'clasifica_ops' => 12,
                      'premios' => [$sol(900), $sol(600), 0],
                      'tramos' => [['desde' => $sol(43000), 'extra' => $sol(400)], ['desde' => $sol(57000), 'extra' => $sol(400)]]]],
        ['tipo' => 'sin_freno', 'nombre' => 'Sin Freno', 'anterior' => 'Bono Rápido Semanal', 'periodo' => 'semana', 'orden' => 40,
         'reglas' => ['forma' => 'primeros', 'metrica' => 'clientes', 'sin_preventa' => true, 'minimo' => $sol(200),
                      'meta' => 22, 'premios' => [$sol(300), $sol(150)]]],
        ['tipo' => 'alfa_quincena', 'nombre' => 'Alfa de la Quincena', 'anterior' => 'Bono Top Quincenal', 'periodo' => 'quincena', 'orden' => 50,
         'reglas' => ['forma' => 'puestos', 'metrica' => 'cobrado', 'sin_preventa' => false, 'minimo' => 0,
                      'clasifica_monto' => $sol(25000), 'clasifica_ops' => 0,
                      'premios' => [$sol(500), $sol(300)], 'tramos' => []]],
        ['tipo' => 'manada', 'nombre' => 'La Manada', 'anterior' => 'Bono Grupal', 'periodo' => 'quincena', 'orden' => 60,
         'reglas' => ['forma' => 'equipo', 'metrica' => 'cobrado', 'sin_preventa' => false, 'minimo' => 0,
                      'min_integrante' => $sol(15000), 'min_equipo' => $sol(140000), 'premio_integrante' => $sol(500)]],
        ['tipo' => 'wakaciones', 'nombre' => 'Wakaciones', 'anterior' => 'Bono Viaje Semestral', 'periodo' => 'semestre', 'orden' => 70,
         'reglas' => ['forma' => 'puestos', 'metrica' => 'cobrado', 'sin_preventa' => false, 'minimo' => 0,
                      'clasifica_monto' => $sol(500000), 'clasifica_ops' => 0, 'premios' => [0], 'tramos' => [],
                      'destino' => 'Cancún', 'premio_texto' => 'Un viaje para dos, todo incluido']],
    ];
}

/**
 * Siembra los bonos de fábrica en cada país que no los tenga, APAGADOS
 * (decisión del 2026-09-29). Nunca pisa lo que Administración cambió.
 * Los niveles de cuota que ya tenían los asesores pasan a su historia.
 */
function bonos_sembrar(): string
{
    if (!bonos_listo()) return '';
    /* La lista vieja de antes del módulo 5 (sin país ni reglas) se retira:
       nunca se usó y confundiría el cierre. Si algo la tocara, se queda. */
    q("DELETE FROM bonos WHERE (tipo IS NULL OR tipo = '') AND pais_id IS NULL
        AND id NOT IN (SELECT bono_id FROM bono_resultados) AND id NOT IN (SELECT bono_id FROM bono_cierres)");
    $n = 0;
    foreach (todas('SELECT id, codigo FROM paises') as $pa) {
        foreach (bonos_de_fabrica() as $b) {
            if (valor('SELECT 1 FROM bonos WHERE pais_id = ? AND tipo = ?', [(int)$pa['id'], $b['tipo']])) continue;
            $clave = $b['tipo'] . '-' . strtolower((string)$pa['codigo']);
            if (valor('SELECT 1 FROM bonos WHERE clave = ?', [$clave])) continue;
            insertar('bonos', [
                'clave' => $clave, 'tipo' => $b['tipo'], 'pais_id' => (int)$pa['id'],
                'nombre' => $b['nombre'], 'nombre_anterior' => $b['anterior'], 'mostrar_anterior' => 0,
                'periodo' => $b['periodo'], 'metrica' => $b['reglas']['metrica'],
                'reglas' => json_encode($b['reglas'], JSON_UNESCAPED_UNICODE),
                'individual' => $b['tipo'] === 'manada' ? 0 : 1, 'activo' => 0, 'orden' => $b['orden'],
            ]);
            $n++;
        }
    }
    /* Los niveles que ya estaban puestos en la ficha valen desde siempre. */
    $m = 0;
    if (columna_existe('usuarios', 'nivel_cuota')) {
        foreach (todas('SELECT id, nivel_cuota FROM usuarios WHERE nivel_cuota IS NOT NULL AND nivel_cuota > 0') as $uu) {
            if (valor('SELECT 1 FROM usuario_niveles WHERE usuario_id = ?', [(int)$uu['id']])) continue;
            insertar('usuario_niveles', ['usuario_id' => (int)$uu['id'], 'desde' => '2000-01-01', 'nivel' => (int)$uu['nivel_cuota']]);
            $m++;
        }
    }
    $partes = [];
    if ($n) $partes[] = "$n bono(s) creados APAGADOS: revísalos y enciéndelos en Configuración › Bonos";
    if ($m) $partes[] = "$m nivel(es) de cuota pasan a su historia";
    return implode('; ', $partes);
}

/* ─────────────────────────  LEER UN BONO  ───────────────────────── */

/** Un bono con sus reglas ya leídas. */
function bono_armar(array $b): array
{
    $b['reglas'] = json_decode((string)($b['reglas'] ?? ''), true) ?: [];
    $b['forma'] = (string)($b['reglas']['forma'] ?? '');
    return $b;
}

function bono_de(int $id): ?array
{
    if (!bonos_listo()) return null;
    $b = una('SELECT * FROM bonos WHERE id = ?', [$id]);
    return $b ? bono_armar($b) : null;
}

/** Los bonos de un país, en su orden. $solo_activos: los encendidos. */
function bonos_del_pais(int $pais_id, bool $solo_activos = false): array
{
    if (!bonos_listo()) return [];
    return array_map('bono_armar', todas('SELECT * FROM bonos WHERE pais_id = ?' . ($solo_activos ? ' AND activo = 1' : '')
                                          . ' ORDER BY orden, id', [$pais_id]));
}

/** El bono de un tipo en un país (p. ej. la Yapa de Perú), o null. */
function bono_tipo(int $pais_id, string $tipo): ?array
{
    if (!bonos_listo()) return null;
    $b = una('SELECT * FROM bonos WHERE pais_id = ? AND tipo = ? ORDER BY id LIMIT 1', [$pais_id, $tipo]);
    return $b ? bono_armar($b) : null;
}

/** El nombre que se enseña: con el anterior entre paréntesis si así se pidió. */
function bono_nombre(array $b): string
{
    $n = (string)$b['nombre'];
    if ((int)($b['mostrar_anterior'] ?? 0) === 1 && trim((string)($b['nombre_anterior'] ?? '')) !== '') {
        $n .= ' (' . trim((string)$b['nombre_anterior']) . ')';
    }
    return $n;
}

/* ─────────────────────────  LOS PERÍODOS  ───────────────────────── */

/**
 * El período que contiene una fecha → [inicio, fin] (AAAA-MM-DD).
 * Semana de lunes a domingo; quincena 1–15 y 16–fin de mes; semestre
 * enero–junio y julio–diciembre.
 */
function bono_periodo(string $periodo, string $fecha): array
{
    $t = strtotime($fecha);
    $y = (int) date('Y', $t); $mo = (int) date('n', $t); $d = (int) date('j', $t);
    switch ($periodo) {
        case 'dia':
            return [date('Y-m-d', $t), date('Y-m-d', $t)];
        case 'semana':
            $ini = strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t);
            return [date('Y-m-d', $ini), date('Y-m-d', strtotime('+6 days', $ini))];
        case 'quincena':
            $m = sprintf('%04d-%02d', $y, $mo);
            return $d <= 15 ? [$m . '-01', $m . '-15'] : [$m . '-16', date('Y-m-t', $t)];
        case 'mes':
            return [date('Y-m-01', $t), date('Y-m-t', $t)];
        case 'semestre':
            return $mo <= 6 ? [$y . '-01-01', $y . '-06-30'] : [$y . '-07-01', $y . '-12-31'];
    }
    return [date('Y-m-d', $t), date('Y-m-d', $t)];
}

/** El período siguiente a uno que termina en $fin. */
function bono_periodo_siguiente(string $periodo, string $fin): array
{
    return bono_periodo($periodo, date('Y-m-d', strtotime($fin . ' +1 day')));
}

/** «16 al 30 de setiembre», «lunes 22 al domingo 28», «hoy»… para la pantalla. */
function bono_periodo_texto(string $periodo, string $inicio, string $fin): string
{
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
    $di = (int) date('j', strtotime($inicio)); $df = (int) date('j', strtotime($fin));
    $mi = $meses[(int) date('n', strtotime($inicio))]; $mf = $meses[(int) date('n', strtotime($fin))];
    if ($periodo === 'dia') return $inicio === date('Y-m-d') ? 'hoy' : $di . ' de ' . $mi;
    if ($periodo === 'semestre') return ((int) date('n', strtotime($inicio)) === 1 ? 'enero a junio ' : 'julio a diciembre ') . date('Y', strtotime($inicio));
    return $mi === $mf ? "del $di al $df de $mf" : "del $di de $mi al $df de $mf";
}

/** Días que quedan del período contando hoy (0 si ya terminó). */
function bono_dias_quedan(string $fin, ?string $hoy = null): int
{
    $hoy ??= date('Y-m-d');
    if ($hoy > $fin) return 0;
    return (int) round((strtotime($fin) - strtotime($hoy)) / 86400) + 1;
}

/* ─────────────────────────  QUIÉNES PARTICIPAN  ───────────────────────── */

/**
 * Los asesores que compiten en un país: rol asesor, activos. Dirección,
 * Administración y Desarrollador no venden ni compiten.
 * → [id => ['id','nombre','apellidos','foto','equipo_id','oficina_id']]
 */
function bono_asesores(int $pais_id): array
{
    $out = [];
    foreach (todas("SELECT u.id, u.nombre, u.apellidos, u.foto, u.equipo_id, u.oficina_id
                      FROM usuarios u JOIN roles r ON r.id = u.rol_id AND r.clave = 'asesor'
                     WHERE u.pais_id = ? AND u.activo = 1 ORDER BY u.nombre, u.apellidos", [$pais_id]) as $f) {
        $out[(int)$f['id']] = $f;
    }
    return $out;
}

/**
 * El nivel de cuota (1..N) de un asesor en una fecha, de su historia; 0 = sin
 * nivel. Un cambio rige desde la quincena siguiente (usuario_nivel_poner).
 */
function nivel_de(int $usuario_id, string $fecha): int
{
    if (!tabla_existe('usuario_niveles')) {
        return (int) valor('SELECT COALESCE(nivel_cuota, 0) FROM usuarios WHERE id = ?', [$usuario_id]);
    }
    $n = valor('SELECT nivel FROM usuario_niveles WHERE usuario_id = ? AND desde <= ? ORDER BY desde DESC LIMIT 1', [$usuario_id, $fecha]);
    return (int)($n ?? 0);
}

/**
 * CAMBIA EL NIVEL de un asesor. Rige desde la quincena SIGUIENTE (la que va
 * no cambia a mitad de camino). Si todavía no tenía nivel, desde la que va:
 * al arrancar, todos nacen «sin nivel» y no pueden esperar dos semanas.
 * → la fecha desde la que vale.
 */
function usuario_nivel_poner(int $usuario_id, int $nivel): string
{
    $hoy = date('Y-m-d');
    $actual = nivel_de($usuario_id, $hoy);
    $nivel = max(0, $nivel);
    if ($nivel === $actual) {
        /* Si había un cambio programado a otro nivel, se deshace. */
        q('DELETE FROM usuario_niveles WHERE usuario_id = ? AND desde > ?', [$usuario_id, $hoy]);
        actualizar('usuarios', $usuario_id, ['nivel_cuota' => $nivel ?: null]);
        return $hoy;
    }
    [$ini] = bono_periodo('quincena', $hoy);
    $desde = $actual === 0 ? $ini : bono_periodo_siguiente('quincena', bono_periodo('quincena', $hoy)[1])[0];
    q('DELETE FROM usuario_niveles WHERE usuario_id = ? AND desde >= ?', [$usuario_id, $desde]);
    insertar('usuario_niveles', ['usuario_id' => $usuario_id, 'desde' => $desde, 'nivel' => $nivel ?: null,
                                 'creado_por' => $_SESSION['usuario_id'] ?? null]);
    actualizar('usuarios', $usuario_id, ['nivel_cuota' => $nivel ?: null]);
    bitacora('usuario.nivel', 'usuario', $usuario_id, ['nivel' => $nivel, 'desde' => $desde]);
    return $desde;
}

/** Los niveles de cuota del país (los de su Yapa): [n => ['nombre','cuota','premio']], desde 1. */
function niveles_de_cuota(int $pais_id): array
{
    $y = bono_tipo($pais_id, 'yapa');
    $out = [];
    foreach ((array)($y['reglas']['niveles'] ?? []) as $i => $nv) $out[$i + 1] = $nv;
    return $out;
}

/** «Nivel Élite · Meta #5», o «Sin nivel». */
function nivel_texto(int $pais_id, int $nivel): string
{
    $nv = niveles_de_cuota($pais_id)[$nivel] ?? null;
    return $nv ? 'Nivel ' . $nv['nombre'] . ' · Meta #' . $nivel : 'Sin nivel';
}

/* ─────────────────────────  LAS MÉTRICAS  ───────────────────────── */

/**
 * LA OPERACIÓN QUE CUENTA para un bono (una sola definición, en SQL):
 * pedido no anulado, con dinero confirmado, del tipo y monto que pida el bono.
 * `$a` es el alias de `pedidos`.
 */
function sql_bono_operacion(string $a, array $r): string
{
    $s = "$a.anulado_en IS NULL AND $a.cobrado_centimos > 0";
    if (!empty($r['sin_preventa'])) $s .= " AND $a.tipo <> 'preventa'";
    if ((int)($r['minimo'] ?? 0) > 0) $s .= " AND $a.total_centimos > " . (int)$r['minimo'];
    return $s;
}

/**
 * COBRADO por asesor en un rango (por la fecha del pago, solo lo confirmado;
 * las devoluciones restan). Con los filtros del bono.
 * → [usuario_id => céntimos]
 */
function bono_cobrado(array $usuarios, string $desde, string $hasta, array $r): array
{
    $out = array_fill_keys($usuarios, 0);
    if (!$usuarios) return $out;
    $en = implode(',', array_fill(0, count($usuarios), '?'));
    $f = '';
    if (!empty($r['sin_preventa'])) $f .= " AND pe.tipo <> 'preventa'";
    if ((int)($r['minimo'] ?? 0) > 0) $f .= ' AND pe.total_centimos > ' . (int)$r['minimo'];
    foreach (todas("SELECT pe.asesor_id, COALESCE(SUM(p.monto_centimos), 0) AS c
                      FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
                     WHERE " . PAGO_CUENTA . " AND p.fecha >= ? AND p.fecha <= ? AND pe.asesor_id IN ($en)$f
                     GROUP BY pe.asesor_id", array_merge([$desde, $hasta], $usuarios)) as $x) {
        $out[(int)$x['asesor_id']] = (int)$x['c'];
    }
    return $out;
}

/**
 * OPERACIONES: pedidos distintos con un cobro confirmado en el rango, con los
 * filtros del bono (Alfa de la Semana pide 12). → [usuario_id => n]
 */
function bono_operaciones(array $usuarios, string $desde, string $hasta, array $r): array
{
    $out = array_fill_keys($usuarios, 0);
    if (!$usuarios) return $out;
    $en = implode(',', array_fill(0, count($usuarios), '?'));
    foreach (todas("SELECT pe.asesor_id, COUNT(DISTINCT pe.id) AS n
                      FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
                     WHERE " . PAGO_CUENTA . " AND p.monto_centimos > 0 AND p.fecha >= ? AND p.fecha <= ?
                       AND " . sql_bono_operacion('pe', $r) . " AND pe.asesor_id IN ($en)
                     GROUP BY pe.asesor_id", array_merge([$desde, $hasta], $usuarios)) as $x) {
        $out[(int)$x['asesor_id']] = (int)$x['n'];
    }
    return $out;
}

/**
 * GESTIONES (Cacería del Día): ventas REGISTRADAS en el rango. Las que ya
 * cuentan (confirmadas, sin anular) y las que todavía esperan a Facturación,
 * aparte: el asesor ve las dos; al cerrar solo cuentan las primeras.
 * → [usuario_id => ['cuentan' => n, 'esperan' => n]]
 */
function bono_gestiones(array $usuarios, string $desde, string $hasta, array $r): array
{
    $out = [];
    foreach ($usuarios as $uid) $out[$uid] = ['cuentan' => 0, 'esperan' => 0];
    if (!$usuarios) return $out;
    $en = implode(',', array_fill(0, count($usuarios), '?'));
    $f = !empty($r['sin_preventa']) ? " AND pe.tipo <> 'preventa'" : '';
    if ((int)($r['minimo'] ?? 0) > 0) $f .= ' AND pe.total_centimos > ' . (int)$r['minimo'];
    foreach (todas("SELECT pe.asesor_id,
                           SUM(CASE WHEN pe.cobrado_centimos > 0 THEN 1 ELSE 0 END) AS cuentan,
                           SUM(CASE WHEN pe.cobrado_centimos > 0 THEN 0 ELSE 1 END) AS esperan
                      FROM pedidos pe
                     WHERE pe.anulado_en IS NULL AND pe.creado_en >= ? AND pe.creado_en < ? AND pe.asesor_id IN ($en)$f
                     GROUP BY pe.asesor_id",
                   array_merge([$desde . ' 00:00:00', date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'], $usuarios)) as $x) {
        $out[(int)$x['asesor_id']] = ['cuentan' => (int)$x['cuentan'], 'esperan' => (int)$x['esperan']];
    }
    return $out;
}

/**
 * CLIENTES DISTINTOS en el rango (Sin Freno), con CUÁNDO llegó cada asesor a
 * la meta N (para saber quién llegó primero). Es el TERCER contador: no es la
 * racha del día ni la de días seguidos (rachas_de_venta.md).
 * → [usuario_id => ['n' => clientes, 'llego_en' => fecha-hora | null]]
 */
function bono_clientes(array $usuarios, string $desde, string $hasta, array $r, int $meta = 0): array
{
    $out = [];
    foreach ($usuarios as $uid) $out[$uid] = ['n' => 0, 'llego_en' => null];
    if (!$usuarios) return $out;
    $en = implode(',', array_fill(0, count($usuarios), '?'));
    /* El primer momento en que cada asesor le vendió a cada cliente. */
    $filas = todas("SELECT pe.asesor_id, pe.cliente_id, MIN(pe.creado_en) AS primero
                      FROM pedidos pe
                     WHERE " . sql_bono_operacion('pe', $r) . " AND pe.creado_en >= ? AND pe.creado_en < ? AND pe.asesor_id IN ($en)
                     GROUP BY pe.asesor_id, pe.cliente_id
                     ORDER BY pe.asesor_id, primero",
                   array_merge([$desde . ' 00:00:00', date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'], $usuarios));
    foreach ($filas as $f) {
        $u = (int)$f['asesor_id'];
        $out[$u]['n']++;
        if ($meta > 0 && $out[$u]['n'] === $meta) $out[$u]['llego_en'] = (string)$f['primero'];
    }
    return $out;
}

/* ─────────────────────────  EVALUAR UN PERÍODO  ───────────────────────── */

/**
 * La frase de estado de una tarjeta que va por un UMBRAL (no por puesto).
 * $pct: avance 0..100; $cerrado: el período ya terminó.
 */
function bono_frase(bool $logrado, float $pct, bool $sin_tiempo): string
{
    if ($logrado) return BONO_FRASE_HECHO;
    if ($sin_tiempo) return BONO_FRASE_FUERA;
    return $pct >= (int) ajuste('bonos_poco_pct', 70) ? BONO_FRASE_POCO : BONO_FRASE_RITMO;
}

/** «Vas 2.º · puede cambiar»: el de puesto nunca dice «ya la hiciste». */
function bono_frase_puesto(int $puesto, bool $cerrado = false): string
{
    return ($cerrado ? 'Quedaste ' : 'Vas ') . $puesto . '.º' . ($cerrado ? '' : ' · puede cambiar');
}

/**
 * Los destinatarios de la Cacería de un día: todos, los de una oficina o los
 * de un equipo. $destino: 'todos' | 'oficina:ID' | 'equipo:ID'.
 */
function caceria_destinatarios(array $asesores, string $destino): array
{
    if ($destino === 'todos' || $destino === '') return array_keys($asesores);
    [$que, $id] = array_pad(explode(':', $destino, 2), 2, '0');
    $id = (int)$id;
    return array_keys(array_filter($asesores, fn($a) => $que === 'oficina' ? (int)$a['oficina_id'] === $id
                                                                             : ($que === 'equipo' ? (int)$a['equipo_id'] === $id : false)));
}

/** La Cacería lanzada un día en un país, o null. */
function caceria_de(int $pais_id, string $fecha): ?array
{
    if (!tabla_existe('caceria_dias')) return null;
    $c = una('SELECT * FROM caceria_dias WHERE pais_id = ? AND fecha = ?', [$pais_id, $fecha]);
    if (!$c) return null;
    $c['escalera'] = json_decode((string)($c['escalera'] ?? ''), true) ?: [];
    return $c;
}

/**
 * EVALÚA UN BONO EN UN PERÍODO. Es la única cuenta: la usan «Mis bonos», el
 * panel de Dirección, el modo team y el cierre. Con $hoy se sabe si el
 * período ya terminó.
 *
 * → ['filas' => [clave => fila], 'orden' => [claves por puesto], 'ganadores' => n,
 *    'se_paga' => céntimos, 'lanzado' => bool (solo Cacería)]
 * Cada fila: usuario_id|equipo_id, nombre, valor, extra, objetivo, pct, puesto,
 * gana, premio, frase, texto (lo que lleva), falta (lo que le falta), siguiente.
 */
function bono_evaluar(array $bono, string $inicio, string $fin, ?string $hoy = null, ?array $reglas = null): array
{
    $hoy ??= date('Y-m-d');
    $r = $reglas ?? $bono['reglas'];
    $pais = (int)$bono['pais_id'];
    $cerrado = $hoy > $fin;
    $asesores = bono_asesores($pais);
    $ids = array_keys($asesores);
    $out = ['filas' => [], 'orden' => [], 'ganadores' => 0, 'se_paga' => 0, 'lanzado' => true];
    $forma = (string)($r['forma'] ?? '');
    $nombre = fn(int $id) => trim((string)($asesores[$id]['nombre'] ?? '') . ' ' . (string)($asesores[$id]['apellidos'] ?? ''));
    $hasta_hoy = min($fin, $hoy);

    if ($forma === 'escalera') {
        /* LA CACERÍA DEL DÍA: solo existe el día que se lanzó, para quien se lanzó. */
        $cac = $bono['tipo'] === 'caceria' ? caceria_de($pais, $inicio) : null;
        if ($bono['tipo'] === 'caceria' && !$cac) { $out['lanzado'] = false; return $out; }
        $esc = $cac && $cac['escalera'] ? $cac['escalera'] : (array)($r['escalera'] ?? []);
        usort($esc, fn($a, $b) => (int)$a['desde'] <=> (int)$b['desde']);
        $ids = $cac ? caceria_destinatarios($asesores, (string)$cac['destino']) : $ids;
        $g = bono_gestiones($ids, $inicio, $fin, $r);
        foreach ($ids as $uid) {
            $n = (int)$g[$uid]['cuentan'];
            $premio = 0; $sig = null;
            foreach ($esc as $e) { if ($n >= (int)$e['desde']) $premio = (int)$e['premio']; elseif (!$sig) $sig = $e; }
            $primero = (int)($esc[0]['desde'] ?? 1);
            $obj = $sig ? (int)$sig['desde'] : (int)(end($esc)['desde'] ?? 1);
            $fila = ['usuario_id' => $uid, 'nombre' => $nombre($uid), 'valor' => $n, 'extra' => (int)$g[$uid]['esperan'],
                     'objetivo' => $obj, 'pct' => $obj > 0 ? min(100, $n * 100 / $obj) : 0, 'puesto' => null,
                     'gana' => $premio > 0, 'premio' => $premio,
                     'frase' => bono_frase($premio > 0, $primero > 0 ? $n * 100 / $primero : 0, $cerrado),
                     'texto' => plural($n, 'gestión', 'gestiones') . ($g[$uid]['esperan'] > 0 && !$cerrado ? ' · ' . $g[$uid]['esperan'] . ' esperan que confirmen el pago' : ''),
                     'falta' => $sig ? 'Te faltan ' . ((int)$sig['desde'] - $n) . ' para ' . soles_corto((int)$sig['premio']) : '',
                     'siguiente' => $sig];
            $out['filas'][$uid] = $fila;
        }
    } elseif ($forma === 'niveles') {
        /* LA YAPA: el nivel de cada uno al EMPEZAR la quincena. Gana el bono del
           nivel más alto que alcance, desde el suyo (los niveles no son techos). */
        $niv = array_values((array)($r['niveles'] ?? []));
        $c = bono_cobrado($ids, $inicio, $hasta_hoy, $r);
        $rd = (int)($r['rapido_dias'] ?? 0);
        $c_rapido = $rd > 0 ? bono_cobrado($ids, $inicio, min($hasta_hoy, date('Y-m-d', strtotime($inicio . ' +' . ($rd - 1) . ' days'))), $r) : [];
        foreach ($ids as $uid) {
            $nv = nivel_de($uid, $inicio);
            if ($nv <= 0 || !isset($niv[$nv - 1])) {
                $out['filas'][$uid] = ['usuario_id' => $uid, 'nombre' => $nombre($uid), 'valor' => (int)$c[$uid], 'extra' => 0, 'objetivo' => 0,
                    'pct' => 0, 'puesto' => null, 'gana' => false, 'premio' => 0, 'nivel' => 0, 'frase' => '',
                    'texto' => 'Sin nivel todavía', 'falta' => 'Pide a Administración que te ponga tu nivel.', 'siguiente' => null, 'sin_nivel' => true];
                continue;
            }
            $v = (int)$c[$uid];
            $cuota = (int)$niv[$nv - 1]['cuota'];
            $gan_i = null;
            for ($i = count($niv) - 1; $i >= $nv - 1; $i--) { if ($v >= (int)$niv[$i]['cuota']) { $gan_i = $i; break; } }
            $premio = $gan_i !== null ? (int)$niv[$gan_i]['premio'] : 0;
            $prox_i = $gan_i !== null ? $gan_i + 1 : $nv - 1;
            $sig = $niv[$prox_i] ?? null;
            $rapido = $rd > 0 && (int)($c_rapido[$uid] ?? 0) >= $cuota;
            $extra_rapido = $rapido ? (int)($r['rapido_premio'] ?? 0) : 0;
            $out['filas'][$uid] = [
                'usuario_id' => $uid, 'nombre' => $nombre($uid), 'valor' => $v, 'extra' => 0, 'objetivo' => $cuota,
                'pct' => $cuota > 0 ? min(100, $v * 100 / $cuota) : 0, 'puesto' => null,
                'gana' => $premio > 0, 'premio' => $premio + $extra_rapido, 'nivel' => $nv, 'cumplio' => $gan_i !== null,
                'rapido' => $rapido, 'rapido_hasta' => $rd > 0 ? date('Y-m-d', strtotime($inicio . ' +' . ($rd - 1) . ' days')) : '',
                'frase' => bono_frase($gan_i !== null, $cuota > 0 ? $v * 100 / $cuota : 0, $cerrado),
                'texto' => 'Nivel ' . $niv[$nv - 1]['nombre'] . ' · Meta #' . $nv . ' · ' . soles_corto($v) . ' de ' . soles_corto($cuota),
                'falta' => $sig ? ($gan_i !== null
                        ? 'Si llegas a ' . soles_corto((int)$sig['cuota']) . ' el bono sube de ' . soles_corto($premio) . ' a ' . soles_corto((int)$sig['premio'])
                        : 'Te faltan ' . soles_corto(max(0, (int)$sig['cuota'] - $v)) . ' para ' . soles_corto((int)$sig['premio']))
                    : '',
                'siguiente' => $sig ? ['nombre' => $sig['nombre'], 'cuota' => (int)$sig['cuota'], 'premio' => (int)$sig['premio']] : null,
            ];
        }
        /* EL DÚO: cumplió su cuota en las DOS quincenas del mes. Se ve y se
           paga en la segunda. */
        if ((int)($r['duo_premio'] ?? 0) > 0 && (int) date('j', strtotime($inicio)) === 16) {
            $ini1 = substr($inicio, 0, 8) . '01';
            $prev = [];
            $cierre1 = una('SELECT id FROM bono_cierres WHERE bono_id = ? AND periodo_inicio = ?', [(int)$bono['id'], $ini1]);
            if (!isset($bono['id']) || !bono_periodo_cuenta($bono, $ini1)) {
                /* La 1.ª quincena no contó (el bono estaba apagado): sin Dúo. */
            } elseif ($cierre1) {
                foreach (todas('SELECT usuario_id, detalle FROM bono_resultados WHERE bono_id = ? AND periodo_inicio = ?', [(int)$bono['id'], $ini1]) as $pr) {
                    $prev[(int)$pr['usuario_id']] = !empty(json_decode((string)$pr['detalle'], true)['cumplio']);
                }
            } else {
                $ev1 = bono_evaluar($bono, $ini1, substr($inicio, 0, 8) . '15', $hoy, $r);
                foreach ($ev1['filas'] as $k => $f1) $prev[$k] = !empty($f1['cumplio']);
            }
            foreach ($out['filas'] as $uid => &$f) {
                $f['duo'] = !empty($f['cumplio']) && !empty($prev[$uid]);
                if ($f['duo']) $f['premio'] += (int)$r['duo_premio'];
            }
            unset($f);
        }
    } elseif ($forma === 'puestos') {
        /* POR PUESTO (Alfa de la Semana, Alfa de la Quincena, Wakaciones):
           clasifica con el mínimo (monto y operaciones), gana por puesto, y
           los tramos los cobra todo el que llegue (decisión del 2026-09-29). */
        $c = bono_cobrado($ids, $inicio, $hasta_hoy, $r);
        $ops = (int)($r['clasifica_ops'] ?? 0) > 0 ? bono_operaciones($ids, $inicio, $hasta_hoy, $r) : [];
        $cm = (int)($r['clasifica_monto'] ?? 0); $co = (int)($r['clasifica_ops'] ?? 0);
        $premios = array_values(array_map('intval', (array)($r['premios'] ?? [])));
        $tramos = (array)($r['tramos'] ?? []);
        usort($tramos, fn($a, $b) => (int)$a['desde'] <=> (int)$b['desde']);
        $clasifican = [];
        foreach ($ids as $uid) {
            $v = (int)$c[$uid]; $o = (int)($ops[$uid] ?? 0);
            $clasifica = $v >= $cm && $o >= $co && $v > 0;
            if ($clasifica) $clasifican[$uid] = $v;
            $out['filas'][$uid] = ['usuario_id' => $uid, 'nombre' => $nombre($uid), 'valor' => $v, 'extra' => $o,
                'objetivo' => $cm, 'pct' => $cm > 0 ? min(100, $v * 100 / $cm) : 100, 'puesto' => null, 'clasifica' => $clasifica,
                'gana' => false, 'premio' => 0, 'frase' => '', 'texto' => soles_corto($v) . ($co > 0 ? ' · ' . plural($o, 'operación', 'operaciones') : ''),
                'falta' => '', 'siguiente' => null];
        }
        arsort($clasifican);
        $puesto = 0; $anterior = null; $i = 0;
        foreach ($clasifican as $uid => $v) {
            $i++;
            if ($v !== $anterior) { $puesto = $i; $anterior = $v; }
            $f = &$out['filas'][$uid];
            $f['puesto'] = $puesto;
            $p = $premios[$puesto - 1] ?? 0;
            $extra = 0; $sig_t = null;
            foreach ($tramos as $t) { if ($v >= (int)$t['desde']) $extra += (int)$t['extra']; elseif (!$sig_t) $sig_t = $t; }
            $f['premio'] = $p + $extra;
            $f['gana'] = $puesto <= count($premios) || $extra > 0;
            $f['frase'] = bono_frase_puesto($puesto, $cerrado);
            if ($sig_t) $f['falta'] = 'Si llegas a ' . soles_corto((int)$sig_t['desde']) . ' sumas ' . soles_corto((int)$sig_t['extra']);
            elseif ($puesto > 1) {
                $delante = array_values($clasifican)[$puesto - 2] ?? $v;
                $f['falta'] = 'Te separan ' . soles_corto(max(0, $delante - $v)) . ' del ' . ($puesto - 1) . '.º';
            }
            unset($f);
        }
        foreach ($out['filas'] as $uid => &$f) {
            if (!empty($f['clasifica'])) continue;
            $falta = [];
            if ($f['valor'] < $cm) $falta[] = soles_corto($cm - $f['valor']);
            if ((int)$f['extra'] < $co) $falta[] = plural($co - (int)$f['extra'], 'operación', 'operaciones');
            $f['falta'] = $falta ? 'Para clasificar te faltan ' . implode(' y ', $falta) : 'Para clasificar te falta tu primera venta';
            $f['frase'] = bono_frase(false, (float)$f['pct'], $cerrado);
        }
        unset($f);
        $out['orden'] = array_keys($clasifican);
    } elseif ($forma === 'primeros') {
        /* SIN FRENO: los primeros en llegar a N clientes distintos. */
        $meta = max(1, (int)($r['meta'] ?? 1));
        $premios = array_values(array_map('intval', (array)($r['premios'] ?? [])));
        $cl = bono_clientes($ids, $inicio, $hasta_hoy, $r, $meta);
        $llegaron = [];
        foreach ($cl as $uid => $x) if ($x['llego_en'] !== null) $llegaron[$uid] = $x['llego_en'];
        asort($llegaron);
        $puestos = [];
        $k = 0; foreach ($llegaron as $uid => $cuando) $puestos[$uid] = ++$k;
        $llenos = count($llegaron) >= count($premios);
        foreach ($ids as $uid) {
            $n = (int)$cl[$uid]['n'];
            $pu = $puestos[$uid] ?? null;
            $gana = $pu !== null && $pu <= count($premios);
            $out['filas'][$uid] = ['usuario_id' => $uid, 'nombre' => $nombre($uid), 'valor' => $n, 'extra' => 0, 'objetivo' => $meta,
                'pct' => min(100, $n * 100 / $meta), 'puesto' => $pu, 'gana' => $gana, 'premio' => $gana ? ($premios[$pu - 1] ?? 0) : 0,
                'llego_en' => $cl[$uid]['llego_en'],
                /* Llegar entre los primeros ya no se lo quita nadie: el que llega
                   después, llega después. */
                'frase' => $gana ? BONO_FRASE_HECHO : (($pu !== null || $llenos) ? BONO_FRASE_FUERA : bono_frase(false, $n * 100 / $meta, $cerrado)),
                'texto' => $n . ' de ' . $meta . ' clientes',
                'falta' => $pu === null && !$llenos ? 'Te faltan ' . ($meta - $n) : ($gana ? 'Llegaste ' . $pu . '.º' : 'Ya llegaron ' . count($premios) . ' antes'),
                'siguiente' => null];
        }
        $out['orden'] = array_keys($llegaron);
    } elseif ($forma === 'equipo') {
        /* LA MANADA: dos condiciones, no tres. Cada integrante un mínimo y el
           equipo junto más de otro. Un solo equipo ganador: el de más cobrado. */
        $c = bono_cobrado($ids, $inicio, $hasta_hoy, $r);
        $mi = (int)($r['min_integrante'] ?? 0); $me = (int)($r['min_equipo'] ?? 0);
        $equipos = todas('SELECT id, nombre FROM equipos WHERE pais_id = ? AND activo = 1 ORDER BY nombre', [$pais]);
        $cumplen = [];
        foreach ($equipos as $eq) {
            $miembros = array_values(array_filter($ids, fn($u) => (int)$asesores[$u]['equipo_id'] === (int)$eq['id']));
            if (!$miembros) continue;
            $total = 0; $faltan = [];
            foreach ($miembros as $u) { $total += (int)$c[$u]; if ((int)$c[$u] < $mi) $faltan[$u] = $mi - (int)$c[$u]; }
            $ok = !$faltan && $total > $me;
            if ($ok) $cumplen[(int)$eq['id']] = $total;
            $out['filas']['e' . $eq['id']] = ['equipo_id' => (int)$eq['id'], 'nombre' => (string)$eq['nombre'], 'valor' => $total, 'extra' => count($miembros),
                'objetivo' => $me, 'pct' => $me > 0 ? min(100, $total * 100 / $me) : 0, 'puesto' => null, 'gana' => false, 'premio' => 0,
                'miembros' => array_map(fn($u) => ['usuario_id' => $u, 'nombre' => $nombre($u), 'valor' => (int)$c[$u], 'falta' => $faltan[$u] ?? 0], $miembros),
                'cumple' => $ok, 'frase' => '', 'texto' => soles_corto($total) . ' de ' . soles_corto($me),
                'falta' => $faltan ? (count($faltan) === 1 ? 'A ' . $nombre((int)array_key_first($faltan)) . ' le faltan ' . soles_corto(reset($faltan)) . ' del mínimo'
                                                            : count($faltan) . ' integrantes no llegan al mínimo')
                                   : ($total > $me ? '' : 'Al equipo le faltan ' . soles_corto($me - $total + 1)),
                'siguiente' => null];
        }
        arsort($cumplen);
        $pu = 0;
        foreach ($cumplen as $eid => $tot) {
            $pu++;
            $f = &$out['filas']['e' . $eid];
            $f['puesto'] = $pu;
            $f['gana'] = $pu === 1;
            $f['premio'] = $pu === 1 ? (int)($r['premio_integrante'] ?? 0) * (int)$f['extra'] : 0;
            $f['frase'] = ($cerrado ? 'Quedaron ' : 'Van ') . $pu . '.º' . ($cerrado ? '' : ' · puede cambiar');
            unset($f);
        }
        foreach ($out['filas'] as &$f) {
            if ($f['frase'] === '') $f['frase'] = bono_frase(false, (float)$f['pct'], $cerrado);
        }
        unset($f);
        $out['orden'] = array_map(fn($e) => 'e' . $e, array_keys($cumplen));
    }

    foreach ($out['filas'] as $f) {
        if (!empty($f['gana']) && (int)$f['premio'] >= 0) { $out['ganadores']++; $out['se_paga'] += (int)$f['premio']; }
    }
    return $out;
}

/* ─────────────────────────  EL CIERRE  ───────────────────────── */

/** Horas de gracia tras el fin del período antes de cerrarlo: Facturación confirma lo de ayer. */
function bonos_gracia_horas(): int
{
    return max(0, (int) ajuste('bonos_cierre_horas', 12));
}

/**
 * CIERRA LOS PERÍODOS VENCIDOS de los bonos encendidos: guarda el resultado
 * de cada uno con las reglas con que se calculó. Se puede llamar mil veces:
 * un período ya cerrado no se toca (candado único bono + inicio).
 * Solo desde el período en que se encendió el bono. → cuántos períodos cerró.
 */
function bonos_cerrar_vencidos(?string $ahora = null): int
{
    if (!bonos_listo()) return 0;
    $ahora ??= date('Y-m-d H:i:s');
    $gracia = bonos_gracia_horas() * 3600;
    $n = 0;
    /* Los apagados también: lo que ya terminó antes de apagarlos se cierra y se paga. */
    foreach (array_map('bono_armar', todas('SELECT * FROM bonos WHERE activo_desde IS NOT NULL')) as $b) {
      /* Un bono con un problema no frena el cierre de los demás. */
      try {
        $per = (string)$b['periodo'];
        $ini = bono_cursor($b);
        /* Apagado antes de que existiera «apagado_desde»: desde el cursor no cuenta. */
        if ((int)$b['activo'] !== 1 && empty($b['apagado_desde'])) {
            $b['apagado_desde'] = $ini;
            actualizar('bonos', (int)$b['id'], ['apagado_desde' => $ini]);
        }
        [$ini, $fin] = bono_periodo($per, $ini);
        $inicial = $ini;
        $vueltas = 0;
        while ($vueltas++ < 400) {
            if (!empty($b['apagado_desde']) && $ini >= (string)$b['apagado_desde']) break;   // apagado: lo de después no cuenta
            $pausa = bono_pausa_de($b, $ini);
            if ($pausa !== null) { [$ini, $fin] = bono_periodo($per, $pausa); continue; }  // se salta la pausa entera
            if (strtotime($fin . ' +1 day') + $gracia > strtotime($ahora)) break;         // todavía no toca
            if (bono_cerrar($b, $ini, $fin, $ahora)) $n++;
            [$ini, $fin] = bono_periodo_siguiente($per, $fin);
        }
        if ($ini !== $inicial || empty($b['cerrar_desde'])) {
            actualizar('bonos', (int)$b['id'], ['cerrar_desde' => $ini]);
            /* Las reglas de antes de un cambio ya no hacen falta cuando se cerró
               todo lo que regían. Solo si nadie las tocó mientras tanto. */
            $quedan = array_values(array_filter(bono_reglas_antes($b), fn($x) => (string)$x['hasta'] > $ini));
            if (count($quedan) !== count(bono_reglas_antes($b))) {
                q('UPDATE bonos SET reglas_antes = ? WHERE id = ? AND reglas_antes = ?',
                  [$quedan ? json_encode($quedan, JSON_UNESCAPED_UNICODE) : null, (int)$b['id'], (string)$b['reglas_antes']]);
            }
        }
      } catch (Throwable $ex) {
        error_log('[HUB] cierre del bono ' . (int)$b['id'] . ': ' . $ex->getMessage());
      }
    }
    return $n;
}

/** El inicio del próximo período por cerrar (el cursor, o el de los bonos de antes del cursor). */
function bono_cursor(array $b): string
{
    $per = (string)$b['periodo'];
    if (!empty($b['cerrar_desde'])) return (string)$b['cerrar_desde'];
    $ult = valor('SELECT MAX(periodo_fin) FROM bono_cierres WHERE bono_id = ?', [(int)$b['id']]);
    return $ult ? bono_periodo_siguiente($per, (string)$ult)[0] : bono_periodo($per, (string)$b['activo_desde'])[0];
}

/**
 * ¿Un período de un bono CUENTA? Desde que se encendió por primera vez, y no
 * si cae en una pausa (estuvo apagado) ni después de apagarlo.
 */
function bono_periodo_cuenta(array $b, string $ini): bool
{
    if (empty($b['activo_desde'])) return false;
    $per = (string)$b['periodo'];
    if ($ini < bono_periodo($per, (string)$b['activo_desde'])[0]) return false;
    if (!empty($b['apagado_desde']) && $ini >= (string)$b['apagado_desde']) return false;
    return bono_pausa_de($b, $ini) === null;
}

/** Si $ini cae en una pausa del bono, la fecha en que termina (el período que vuelve a contar); si no, null. */
function bono_pausa_de(array $b, string $ini): ?string
{
    foreach ((array)(json_decode((string)($b['pausas'] ?? ''), true) ?: []) as $p) {
        if (is_array($p) && count($p) === 2 && $ini >= (string)$p[0] && $ini < (string)$p[1]) return (string)$p[1];
    }
    return null;
}

/**
 * Las reglas con que se cierra un período: las que regían cuando TERMINÓ.
 * Si se cambiaron durante las horas de espera, las de antes siguen guardadas.
 */
function bono_reglas_de_periodo(array $b, string $ini): array
{
    foreach (bono_reglas_antes($b) as $ra) {
        if ($ini < (string)$ra['hasta']) return $ra['reglas'];
    }
    return $b['reglas'];
}

/** Las reglas guardadas antes de cada cambio: [['hasta' => inicio del período en que se cambió, 'reglas' => …]], de la más vieja a la más nueva. */
function bono_reglas_antes(array $b): array
{
    $l = json_decode((string)($b['reglas_antes'] ?? ''), true);
    if (!is_array($l)) return [];
    $l = array_values(array_filter($l, fn($x) => is_array($x) && isset($x['hasta'], $x['reglas']) && is_array($x['reglas'])));
    usort($l, fn($x, $y) => strcmp((string)$x['hasta'], (string)$y['hasta']));
    return $l;
}

/**
 * De paso, al abrir el Inicio: cierra lo vencido como mucho cada 10 minutos
 * (el HUB no tiene reloj propio; el cron de tareas.php lo hace también).
 * Nunca tumba la pantalla.
 */
function bonos_cerrar_de_paso(): void
{
    if (!bonos_listo()) return;
    try {
        $ult = (string) ajuste('bonos_cierre_ultimo', '');
        if ($ult !== '' && strtotime($ult) > time() - 600) return;
        guardar_ajuste_tecnico('bonos_cierre_ultimo', date('Y-m-d H:i:s'), 'Cuándo se miraron por última vez los bonos por cerrar. No tocar.');
        ajustes_olvidar();
        bonos_cerrar_vencidos();
    } catch (Throwable $ex) {
        error_log('[HUB] cierre de bonos: ' . $ex->getMessage());
    }
}

/**
 * Cierra UN período de un bono. En una transacción: o se guarda el cierre con
 * todos sus resultados, o nada. → true si lo cerró ahora.
 */
function bono_cerrar(array $b, string $ini, string $fin, ?string $ahora = null): bool
{
    $ahora ??= date('Y-m-d H:i:s');
    return (bool) en_transaccion(function () use ($b, $ini, $fin, $ahora) {
        if (valor('SELECT 1 FROM bono_cierres WHERE bono_id = ? AND periodo_inicio = ?', [(int)$b['id'], $ini])) return false;
        $reglas = bono_reglas_de_periodo($b, $ini);
        $ev = bono_evaluar($b, $ini, $fin, date('Y-m-d', strtotime($fin . ' +1 day')), $reglas);
        if ($b['tipo'] === 'caceria' && ($cac = caceria_de((int)$b['pais_id'], $ini))) {
            $reglas['escalera_del_dia'] = $cac['escalera'];
            $reglas['titulo'] = $cac['titulo'];
        }
        try {
            insertar('bono_cierres', ['bono_id' => (int)$b['id'], 'periodo_inicio' => $ini, 'periodo_fin' => $fin,
                                      'reglas' => json_encode($reglas, JSON_UNESCAPED_UNICODE), 'cerrado_en' => $ahora]);
        } catch (Throwable $ex) {
            return false;       // otro lo cerró en ese mismo instante
        }
        /* LA MANADA SE PAGA POR INTEGRANTE: una fila por persona del equipo
           (con su cobrado y el premio de cada uno), y la del equipo con su
           total, sin premio (para que nada se pague dos veces). */
        if ((string)($reglas['forma'] ?? $b['forma']) === 'equipo') {
            $pi = (int)($reglas['premio_integrante'] ?? 0);
            foreach ($ev['filas'] as $f) {
                $gana = !empty($f['gana']);
                insertar('bono_resultados', [
                    'bono_id' => (int)$b['id'], 'pais_id' => (int)$b['pais_id'], 'usuario_id' => 0, 'equipo_id' => (int)$f['equipo_id'],
                    'periodo_inicio' => $ini, 'periodo_fin' => $fin, 'valor' => (int)$f['valor'], 'ganado' => $gana ? 1 : 0,
                    'premio_centimos' => 0, 'puesto' => $f['puesto'] ?? null,
                    'detalle' => json_encode(array_intersect_key($f, array_flip(['nombre', 'texto', 'falta', 'miembros', 'cumple'])), JSON_UNESCAPED_UNICODE),
                    'pagado' => 0, 'cerrado_en' => $ahora,
                ]);
                foreach ((array)($f['miembros'] ?? []) as $m) {
                    insertar('bono_resultados', [
                        'bono_id' => (int)$b['id'], 'pais_id' => (int)$b['pais_id'], 'usuario_id' => (int)$m['usuario_id'], 'equipo_id' => (int)$f['equipo_id'],
                        'periodo_inicio' => $ini, 'periodo_fin' => $fin, 'valor' => (int)$m['valor'], 'ganado' => $gana ? 1 : 0,
                        'premio_centimos' => $gana ? $pi : 0, 'puesto' => $f['puesto'] ?? null,
                        'detalle' => json_encode(['nombre' => $m['nombre'], 'equipo' => $f['nombre'], 'texto' => 'Tu equipo: ' . $f['texto'],
                                                  'falta' => $f['falta'], 'equipo_valor' => (int)$f['valor']], JSON_UNESCAPED_UNICODE),
                        'pagado' => 0, 'cerrado_en' => $ahora,
                    ]);
                }
            }
            return true;
        }
        foreach ($ev['filas'] as $f) {
            /* Se guarda a quien participó: con algo que contar o con premio. */
            if ((int)$f['valor'] <= 0 && empty($f['gana'])) continue;
            $detalle = array_intersect_key($f, array_flip(['nombre', 'texto', 'falta', 'extra', 'objetivo', 'nivel', 'cumplio',
                                                            'rapido', 'duo', 'miembros', 'siguiente', 'clasifica', 'llego_en']));
            insertar('bono_resultados', [
                'bono_id' => (int)$b['id'], 'pais_id' => (int)$b['pais_id'],
                'usuario_id' => (int)($f['usuario_id'] ?? 0), 'equipo_id' => (int)($f['equipo_id'] ?? 0),
                'periodo_inicio' => $ini, 'periodo_fin' => $fin,
                'valor' => (int)$f['valor'], 'ganado' => !empty($f['gana']) ? 1 : 0,
                'premio_centimos' => !empty($f['gana']) ? (int)$f['premio'] : 0,
                'puesto' => $f['puesto'] ?? null,
                'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'pagado' => 0, 'cerrado_en' => $ahora,
            ]);
        }
        return true;
    });
}

/* ─────────────────────────  PAGAR  ───────────────────────── */

/**
 * Los premios ganados y sin pagar (Administración los paga y los marca).
 * La Manada se paga por integrante: una fila por persona.
 * → filas con bono, período, a quién y cuánto.
 */
function bonos_por_pagar(int $pais_id): array
{
    if (!bonos_listo()) return [];
    $out = [];
    foreach (todas("SELECT br.*, b.nombre AS bono_nombre, b.tipo, b.reglas AS bono_reglas,
                           u.nombre AS u_nombre, u.apellidos AS u_apellidos, eq.nombre AS eq_nombre
                      FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id
                      LEFT JOIN usuarios u ON u.id = br.usuario_id
                      LEFT JOIN equipos eq ON eq.id = br.equipo_id
                     WHERE br.pais_id = ? AND br.ganado = 1 AND br.pagado = 0 AND br.premio_centimos > 0
                     ORDER BY br.periodo_fin, b.orden, br.puesto, br.id", [$pais_id]) as $f) {
        $out[] = $f;
    }
    return $out;
}

/** Marca pagado un premio. → ['ok', 'error'] */
function bono_resultado_pagar(int $resultado_id): array
{
    $u = yo();
    $f = una('SELECT br.*, b.pais_id AS bono_pais FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id WHERE br.id = ?', [$resultado_id]);
    if (!$f || !$u || (!cruza_paises($u) && (int)$f['bono_pais'] !== (int)$u['pais_id'])) return ['ok' => false, 'error' => 'Ese premio no existe.'];
    if ((int)$f['ganado'] !== 1 || (int)$f['premio_centimos'] <= 0) return ['ok' => false, 'error' => 'Ese resultado no tiene premio.'];
    $st = q('UPDATE bono_resultados SET pagado = 1, pagado_en = ?, pagado_por = ? WHERE id = ? AND pagado = 0',
            [date('Y-m-d H:i:s'), (int)$u['id'], $resultado_id]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'Ese premio ya estaba pagado.'];
    bitacora('bono.pagado', 'bono_resultado', $resultado_id, ['premio' => (int)$f['premio_centimos']]);
    return ['ok' => true, 'error' => ''];
}

/** Deshace «pagado» (se marcó por error). Solo el mismo día. */
function bono_resultado_despagar(int $resultado_id): array
{
    $u = yo();
    $f = una('SELECT br.*, b.pais_id AS bono_pais FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id WHERE br.id = ?', [$resultado_id]);
    if (!$f || !$u || (!cruza_paises($u) && (int)$f['bono_pais'] !== (int)$u['pais_id'])) return ['ok' => false, 'error' => 'Ese premio no existe.'];
    $st = q('UPDATE bono_resultados SET pagado = 0, pagado_en = NULL, pagado_por = NULL WHERE id = ? AND pagado = 1 AND pagado_en >= ?',
            [$resultado_id, date('Y-m-d') . ' 00:00:00']);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'Solo se deshace el mismo día.'];
    bitacora('bono.despagado', 'bono_resultado', $resultado_id, []);
    return ['ok' => true, 'error' => ''];
}

/** Lo pagado en un mes, por bono: [bono_nombre => céntimos]. */
function bonos_pagado_mes(int $pais_id, string $mes): array
{
    if (!bonos_listo()) return [];
    $out = [];
    foreach (todas('SELECT b.nombre, SUM(br.premio_centimos) AS t FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id
                     WHERE br.pais_id = ? AND br.pagado = 1 AND br.pagado_en >= ? AND br.pagado_en <= ?
                     GROUP BY b.id, b.nombre ORDER BY MIN(b.orden)', [$pais_id, $mes . '-01 00:00:00', date('Y-m-t', strtotime($mes . '-01')) . ' 23:59:59']) as $f) {
        $out[(string)$f['nombre']] = (int)$f['t'];
    }
    return $out;
}

/* ─────────────────────────  ENCENDER Y GUARDAR  ───────────────────────── */

/**
 * Enciende o apaga un bono.
 *  · La primera vez cuenta desde el período que va.
 *  · Al apagarlo, el período que va deja de contar; lo que ya terminó se
 *    cierra y se paga igual (aunque esté en sus horas de espera).
 *  · Al volver a encenderlo, lo de en medio queda como PAUSA: no se paga.
 * Encender lo que ya está encendido no hace nada (una segunda pestaña).
 */
function bono_encender(int $bono_id, bool $on): array
{
    $b = bono_de($bono_id);
    $u = yo();
    if (!$b || !$u || (!cruza_paises($u) && (int)$b['pais_id'] !== (int)$u['pais_id'])) return ['ok' => false, 'error' => 'Ese bono no existe.'];
    if ((int)$b['activo'] === ($on ? 1 : 0)) return ['ok' => true, 'error' => ''];
    $per = (string)$b['periodo'];
    [$ini_hoy] = bono_periodo($per, date('Y-m-d'));
    if ($on) {
        $cambio = ['activo' => 1, 'apagado_desde' => null];
        if (empty($b['activo_desde'])) {
            $cambio['activo_desde'] = date('Y-m-d');
            $cambio['cerrar_desde'] = $ini_hoy;
        } elseif (!empty($b['apagado_desde']) && (string)$b['apagado_desde'] < $ini_hoy) {
            $pausas = json_decode((string)($b['pausas'] ?? ''), true) ?: [];
            $pausas[] = [(string)$b['apagado_desde'], $ini_hoy];
            $cambio['pausas'] = json_encode(array_slice($pausas, -200));
        }
    } else {
        $cambio = ['activo' => 0, 'apagado_desde' => $ini_hoy];
    }
    actualizar('bonos', $bono_id, $cambio);
    bitacora($on ? 'bono.encender' : 'bono.apagar', 'bono', $bono_id, []);
    return ['ok' => true, 'error' => ''];
}

/**
 * Guarda los datos de un bono desde Configuración: nombre, nombre anterior y
 * sus reglas (según su forma). Los montos llegan en soles como texto.
 * Lo ya cerrado no cambia: cada cierre guardó sus reglas.
 * → ['ok', 'error']
 */
function bono_guardar(int $bono_id, array $d): array
{
    $b = bono_de($bono_id);
    $u = yo();
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!$b || !$u || (!cruza_paises($u) && (int)$b['pais_id'] !== (int)$u['pais_id'])) return $mal('Ese bono no existe.');
    $nombre = trim((string)($d['nombre'] ?? ''));
    if (mb_strlen($nombre) < 2) return $mal('Ponle un nombre al bono.');
    $cent = function ($x) { $c = a_centimos((string)$x); return $c === null ? null : $c; };
    $ent = fn($x) => ctype_digit(trim((string)$x)) ? (int) trim((string)$x) : null;
    $r = $b['reglas'];
    $r['sin_preventa'] = !empty($d['sin_preventa']);
    $min = trim((string)($d['minimo'] ?? '')) === '' ? 0 : $cent($d['minimo']);
    if ($min === null || $min < 0) return $mal('El monto mínimo por venta no se entiende.');
    $r['minimo'] = $min;

    switch ($b['forma']) {
        case 'niveles':
            $niv = [];
            foreach ((array)($d['n_nombre'] ?? []) as $i => $nn) {
                $nn = trim((string)$nn);
                $cu = $cent($d['n_cuota'][$i] ?? ''); $pr = $cent($d['n_premio'][$i] ?? '');
                if ($nn === '' && trim((string)($d['n_cuota'][$i] ?? '')) === '') continue;
                if ($nn === '') return $mal('Nivel ' . ($i + 1) . ': falta el nombre.');
                if ($cu === null || $cu <= 0) return $mal('Nivel «' . $nn . '»: la cuota no se entiende.');
                if ($pr === null || $pr < 0) return $mal('Nivel «' . $nn . '»: el bono no se entiende.');
                if ($niv && $cu <= $niv[count($niv) - 1]['cuota']) return $mal('Nivel «' . $nn . '»: cada nivel pide más que el anterior.');
                $niv[] = ['nombre' => mb_substr($nn, 0, 30), 'cuota' => $cu, 'premio' => $pr];
            }
            if (!$niv) return $mal('Pon al menos un nivel.');
            $r['niveles'] = $niv;
            $r['rapido_dias'] = max(0, (int)($ent($d['rapido_dias'] ?? '0') ?? 0));
            $r['rapido_premio'] = $cent($d['rapido_premio'] ?? '0') ?? 0;
            $r['duo_premio'] = $cent($d['duo_premio'] ?? '0') ?? 0;
            break;
        case 'escalera':
            $esc = bono_escalera_de_form($d);
            if (is_string($esc)) return $mal($esc);
            $r['escalera'] = $esc;
            break;
        case 'puestos':
            $cm = $cent($d['clasifica_monto'] ?? '');
            if ($cm === null || $cm < 0) return $mal('El monto para clasificar no se entiende.');
            $r['clasifica_monto'] = $cm;
            $r['clasifica_ops'] = max(0, (int)($ent($d['clasifica_ops'] ?? '0') ?? 0));
            $pr = [];
            foreach ((array)($d['premios'] ?? []) as $x) {
                if (trim((string)$x) === '') continue;
                $c = $cent($x);
                if ($c === null || $c < 0) return $mal('Un premio por puesto no se entiende.');
                $pr[] = $c;
            }
            if (!$pr) return $mal('Pon el premio de al menos un puesto.');
            $r['premios'] = $pr;
            $tr = [];
            foreach ((array)($d['t_desde'] ?? []) as $i => $x) {
                if (trim((string)$x) === '' && trim((string)($d['t_extra'][$i] ?? '')) === '') continue;
                $de = $cent($x); $ex = $cent($d['t_extra'][$i] ?? '');
                if ($de === null || $de <= 0 || $ex === null || $ex < 0) return $mal('Un tramo no se entiende.');
                $tr[] = ['desde' => $de, 'extra' => $ex];
            }
            usort($tr, fn($a, $b2) => $a['desde'] <=> $b2['desde']);
            $r['tramos'] = $tr;
            if ($b['tipo'] === 'wakaciones' || array_key_exists('destino', $r)) {
                $r['destino'] = mb_substr(trim((string)($d['destino'] ?? '')), 0, 60);
                $r['premio_texto'] = mb_substr(trim((string)($d['premio_texto'] ?? '')), 0, 120);
            }
            break;
        case 'primeros':
            $m = $ent($d['meta'] ?? '');
            if (!$m) return $mal('¿A cuántos clientes hay que llegar?');
            $r['meta'] = $m;
            $pr = [];
            foreach ((array)($d['premios'] ?? []) as $x) {
                if (trim((string)$x) === '') continue;
                $c = $cent($x);
                if ($c === null || $c < 0) return $mal('Un premio por puesto no se entiende.');
                $pr[] = $c;
            }
            if (!$pr) return $mal('Pon el premio de al menos un puesto.');
            $r['premios'] = $pr;
            break;
        case 'equipo':
            foreach (['min_integrante' => 'El mínimo de cada integrante', 'min_equipo' => 'El monto del equipo', 'premio_integrante' => 'El premio por integrante'] as $k => $t) {
                $c = $cent($d[$k] ?? '');
                if ($c === null || $c < 0) return $mal($t . ' no se entiende.');
                $r[$k] = $c;
            }
            break;
    }
    /* Si un período ya terminó y espera su cierre, se cierra con las reglas
       que regían entonces: se guardan (la primera vez, que son las de verdad). */
    $cambio_reglas = [];
    [$ini_hoy] = bono_periodo((string)$b['periodo'], date('Y-m-d'));
    if (!empty($b['activo_desde']) && bono_cursor($b) < $ini_hoy) {
        $lista = bono_reglas_antes($b);
        $ultima = $lista ? end($lista) : null;
        if (!$ultima || (string)$ultima['hasta'] !== $ini_hoy) {
            $lista[] = ['hasta' => $ini_hoy, 'reglas' => $b['reglas']];
            $cambio_reglas['reglas_antes'] = json_encode(array_values($lista), JSON_UNESCAPED_UNICODE);
        }
    }
    actualizar('bonos', $bono_id, $cambio_reglas + [
        'nombre' => mb_substr($nombre, 0, 80),
        'nombre_anterior' => mb_substr(trim((string)($d['nombre_anterior'] ?? '')), 0, 80) ?: null,
        'mostrar_anterior' => !empty($d['mostrar_anterior']) ? 1 : 0,
        'reglas' => json_encode($r, JSON_UNESCAPED_UNICODE),
    ]);
    bitacora('bono.guardar', 'bono', $bono_id, []);
    return ['ok' => true, 'error' => ''];
}

/** La escalera de un formulario (e_desde[], e_premio[]) → lista ordenada, o el error. */
function bono_escalera_de_form(array $d): array|string
{
    $esc = [];
    foreach ((array)($d['e_desde'] ?? []) as $i => $x) {
        if (trim((string)$x) === '' && trim((string)($d['e_premio'][$i] ?? '')) === '') continue;
        $de = ctype_digit(trim((string)$x)) ? (int) trim((string)$x) : 0;
        $pr = a_centimos((string)($d['e_premio'][$i] ?? ''));
        if ($de <= 0) return 'Un escalón no dice desde cuántas gestiones.';
        if ($pr === null || $pr < 0) return 'Un escalón no tiene el premio bien escrito.';
        $esc[] = ['desde' => $de, 'premio' => $pr];
    }
    if (!$esc) return 'Pon al menos un escalón.';
    usort($esc, fn($a, $b) => $a['desde'] <=> $b['desde']);
    for ($i = 1; $i < count($esc); $i++) {
        if ($esc[$i]['desde'] === $esc[$i - 1]['desde']) return 'Dos escalones empiezan en ' . $esc[$i]['desde'] . '.';
    }
    return $esc;
}

/* ─────────────────────────  LA CACERÍA DEL DÍA  ───────────────────────── */

/**
 * LANZA LA CACERÍA DE HOY (Dirección o Administración). Una vez al día por
 * país; después se pueden corregir el título y la frase, pero no la escalera
 * ni a quién le llega (ya hay quien está cazando con esas reglas).
 * → ['ok', 'error', 'nueva' => bool]
 */
function caceria_lanzar(int $pais_id, array $d): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'nueva' => false];
    $b = bono_tipo($pais_id, 'caceria');
    if (!$b) return $mal('Falta terminar la actualización.');
    if ((int)$b['activo'] !== 1) return $mal('La Cacería del Día está apagada. Enciéndela en Configuración › Bonos.');
    $titulo = trim((string)($d['titulo'] ?? ''));
    if (mb_strlen($titulo) < 3) return $mal('Ponle un título a la Cacería de hoy.');
    $frase = mb_substr(trim((string)($d['frase'] ?? '')), 0, 240);
    $hoy = date('Y-m-d');
    $ya = caceria_de($pais_id, $hoy);
    if ($ya) {
        actualizar('caceria_dias', (int)$ya['id'], ['titulo' => mb_substr($titulo, 0, 120), 'frase' => $frase, 'editada_en' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'error' => '', 'nueva' => false];
    }
    $esc = bono_escalera_de_form($d);
    if (is_string($esc)) return $mal($esc);
    $destino = (string)($d['destino'] ?? 'todos');
    if ($destino !== 'todos') {
        [$que, $id] = array_pad(explode(':', $destino, 2), 2, '0');
        $ok = ($que === 'oficina' && valor('SELECT 1 FROM oficinas WHERE id = ? AND pais_id = ?', [(int)$id, $pais_id]))
           || ($que === 'equipo' && valor('SELECT 1 FROM equipos WHERE id = ? AND pais_id = ?', [(int)$id, $pais_id]));
        if (!$ok) return $mal('Elige a quién le llega de la lista.');
        $destino = $que . ':' . (int)$id;
    }
    try {
        insertar('caceria_dias', ['pais_id' => $pais_id, 'fecha' => $hoy, 'titulo' => mb_substr($titulo, 0, 120), 'frase' => $frase,
                                  'escalera' => json_encode($esc), 'destino' => $destino,
                                  'lanzada_por' => $_SESSION['usuario_id'] ?? null, 'lanzada_en' => date('Y-m-d H:i:s')]);
    } catch (Throwable $ex) {
        return $mal('La Cacería de hoy ya se lanzó.');
    }
    bitacora('caceria.lanzar', 'bono', (int)$b['id'], ['destino' => $destino]);
    return ['ok' => true, 'error' => '', 'nueva' => true];
}

/** «Para todos», «Lima», «Equipo A»… */
function caceria_destino_texto(string $destino): string
{
    if ($destino === 'todos' || $destino === '') return 'Para todos';
    [$que, $id] = array_pad(explode(':', $destino, 2), 2, '0');
    if ($que === 'oficina') return 'Oficina ' . (string) valor('SELECT nombre FROM oficinas WHERE id = ?', [(int)$id], '');
    if ($que === 'equipo') return (string) (valor('SELECT nombre FROM equipos WHERE id = ?', [(int)$id], '') ?: 'Un equipo');
    return 'Para todos';
}

/* ─────────────────────────  LO QUE VE CADA ASESOR  ───────────────────────── */

/**
 * «MIS BONOS»: cada bono encendido de su país con su fila del período en
 * curso. → [['bono', 'inicio', 'fin', 'fila', 'ev']]
 */
function mis_bonos(array $u, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $out = [];
    foreach (bonos_del_pais((int)$u['pais_id'], true) as $b) {
        [$ini, $fin] = bono_periodo((string)$b['periodo'], $hoy);
        $ev = bono_evaluar($b, $ini, $fin, $hoy);
        $fila = null;
        if ($b['forma'] === 'equipo') {
            $fila = $ev['filas']['e' . (int)($u['equipo_id'] ?? 0)] ?? null;
        } else {
            $fila = $ev['filas'][(int)$u['id']] ?? null;
        }
        $out[] = ['bono' => $b, 'inicio' => $ini, 'fin' => $fin, 'fila' => $fila, 'ev' => $ev];
    }
    return $out;
}

/**
 * «MÉTELE GARRA»: la franja de arriba de Mis bonos. Cuánto le falta para su
 * cuota de la Yapa, los días que quedan y cuántas ventas de su ticket son.
 * → ['falta', 'dias', 'ventas', 'texto'] o [] si no aplica.
 */
function metele_garra(array $u, array $mis): array
{
    foreach ($mis as $m) {
        if ($m['bono']['forma'] !== 'niveles' || !$m['fila'] || !empty($m['fila']['sin_nivel'])) continue;
        $f = $m['fila'];
        $objetivo = !empty($f['cumplio']) && $f['siguiente'] ? (int)$f['siguiente']['cuota'] : (int)$f['objetivo'];
        $falta = max(0, $objetivo - (int)$f['valor']);
        if ($falta <= 0) return [];
        $dias = bono_dias_quedan($m['fin']);
        if ($dias <= 0) return [];
        $ticket = (int) valor('SELECT COALESCE(AVG(total_centimos), 0) FROM pedidos
                                WHERE asesor_id = ? AND anulado_en IS NULL AND creado_en >= ?',
                              [(int)$u['id'], date('Y-m-d', strtotime('-90 days'))]);
        $ventas = $ticket > 0 ? (int) ceil($falta / $ticket) : 0;
        return ['falta' => $falta, 'dias' => $dias, 'ventas' => $ventas,
                'texto' => 'Te faltan ' . soles_corto($falta) . ' y ' . plural($dias, 'día', 'días') . '.'
                         . ($ventas > 0 ? ' Son ' . plural($ventas, 'venta', 'ventas') . ' de tu ticket.' : '')];
    }
    return [];
}

/** La regla del bono en una línea, para las listas («Cada semana · clasifica con S/27,000 y 12 operaciones…»). */
function bono_resumen(array $b): string
{
    $r = $b['reglas'];
    $per = ['dia' => 'Cada día', 'semana' => 'Cada semana', 'quincena' => 'Cada quincena', 'mes' => 'Cada mes', 'semestre' => 'Cada semestre'][$b['periodo']] ?? '';
    $filtro = (!empty($r['sin_preventa']) ? ' · sin pre venta' : '') . ((int)($r['minimo'] ?? 0) > 0 ? ' · ventas de más de ' . soles_corto((int)$r['minimo']) : '');
    switch ($b['forma']) {
        case 'niveles':
            $n = (array)($r['niveles'] ?? []);
            $t = $n ? plural(count($n), 'nivel', 'niveles') . ' de ' . soles_corto((int)$n[0]['cuota']) . ' a ' . soles_corto((int)end($n)['cuota']) : 'sin niveles';
            if ((int)($r['rapido_premio'] ?? 0) > 0) $t .= ' · +' . soles_corto((int)$r['rapido_premio']) . ' si llega en ' . (int)$r['rapido_dias'] . ' días';
            if ((int)($r['duo_premio'] ?? 0) > 0) $t .= ' · +' . soles_corto((int)$r['duo_premio']) . ' si cumple las dos quincenas';
            return "$per · $t$filtro";
        case 'escalera':
            $e = (array)($r['escalera'] ?? []);
            return "$per que se lance · " . implode(' · ', array_map(fn($x) => (int)$x['desde'] . ' → ' . soles_corto((int)$x['premio']), $e)) . $filtro;
        case 'puestos':
            $t = 'clasifica con ' . soles_corto((int)($r['clasifica_monto'] ?? 0)) . ((int)($r['clasifica_ops'] ?? 0) > 0 ? ' y ' . (int)$r['clasifica_ops'] . ' operaciones' : '');
            $p = array_values(array_filter((array)($r['premios'] ?? []), fn($x) => (int)$x > 0));
            if ($p) $t .= ' · ' . implode(' / ', array_map('soles_corto', $p));
            elseif (!empty($r['premio_texto'])) $t .= ' · ' . $r['premio_texto'];
            foreach ((array)($r['tramos'] ?? []) as $tr) $t .= ' · +' . soles_corto((int)$tr['extra']) . ' desde ' . soles_corto((int)$tr['desde']);
            return "$per · $t$filtro";
        case 'primeros':
            return "$per · los primeros en llegar a " . (int)($r['meta'] ?? 0) . ' clientes distintos · '
                 . implode(' / ', array_map('soles_corto', (array)($r['premios'] ?? []))) . $filtro;
        case 'equipo':
            return "$per · cada integrante " . soles_corto((int)($r['min_integrante'] ?? 0)) . ' y el equipo más de ' . soles_corto((int)($r['min_equipo'] ?? 0))
                 . ' · ' . soles_corto((int)($r['premio_integrante'] ?? 0)) . ' por integrante' . $filtro;
    }
    return $per;
}

/**
 * «COMPARTIR MI LOGRO» de un resultado ganado (fila de bono_resultados con
 * bono_nombre, tipo y periodo). El dato NO lleva soles: los montos solo van
 * si la persona los enciende (van aparte, en 'monto').
 */
function bono_logro(array $x, string $nombre): array
{
    $d = json_decode((string)($x['detalle'] ?? ''), true) ?: [];
    $pu = (int)($x['puesto'] ?? 0);
    $cual = ['semana' => 'de la semana', 'quincena' => 'de la quincena', 'semestre' => 'del semestre', 'dia' => 'del día'][(string)($x['periodo'] ?? '')] ?? '';
    switch ((string)($x['tipo'] ?? '')) {
        case 'yapa':
            $dato = 'Cumplí mi meta ' . $cual . (!empty($d['rapido']) ? ' · en tiempo récord' : '') . (!empty($d['duo']) ? ' · las dos quincenas' : '');
            break;
        case 'caceria':
            $dato = plural((int)$x['valor'], 'gestión', 'gestiones') . ' en un día';
            break;
        case 'sin_freno':
            $dato = 'Llegué ' . ($pu ?: 1) . '.º a ' . (int)($d['objetivo'] ?? 0) . ' clientes';
            break;
        case 'manada':
            $dato = 'Mi equipo ganó ' . $cual;
            break;
        default:
            $dato = $pu > 0 ? 'Quedé ' . $pu . '.º ' . $cual : 'Lo logré';
    }
    return ['titulo' => (string)($x['bono_nombre'] ?? ''), 'dato' => trim($dato),
            'monto' => (int)($x['premio_centimos'] ?? 0) > 0 ? soles_corto((int)$x['premio_centimos']) : '',
            'fecha' => bono_periodo_texto((string)$x['periodo'], (string)$x['periodo_inicio'], (string)$x['periodo_fin']),
            'nombre' => $nombre];
}

/* ─────────────────────────  LA PILA DE NOVEDADES  ───────────────────────── */

/** Marca una novedad como vista (una vez; se guarda en el servidor). → true si era nueva. */
function novedad_vista(int $usuario_id, string $clave): bool
{
    if (!tabla_existe('novedades_vistas')) return false;
    if (valor('SELECT 1 FROM novedades_vistas WHERE usuario_id = ? AND clave = ?', [$usuario_id, $clave])) return false;
    try { insertar('novedades_vistas', ['usuario_id' => $usuario_id, 'clave' => $clave, 'visto_en' => date('Y-m-d H:i:s')]); }
    catch (Throwable $ex) { return false; }
    return true;
}

/**
 * LO QUE SALTA AL ABRIR LA APP, EN UNA SOLA PILA (avisos_al_abrir.md).
 * Orden fijo: primero lo que cerró (resultados de bonos, una racha cortada),
 * después lo de hoy (la Cacería). Cada tarjeta, una sola vez. Wakaciones va
 * aparte, a pantalla completa (novedad_wakaciones).
 * → [['clave','tono' (verde|gris|amarillo),'titulo','lineas' => [..],'logro' => data|null]]
 */
function novedades_de(array $u, ?string $hoy = null): array
{
    if (!bonos_listo() || !tabla_existe('novedades_vistas')) return [];
    $hoy ??= date('Y-m-d');
    $uid = (int)$u['id'];
    $vistas = array_flip(array_column(todas('SELECT clave FROM novedades_vistas WHERE usuario_id = ? AND visto_en >= ?',
                                             [$uid, date('Y-m-d', strtotime($hoy . ' -30 days'))]), 'clave'));
    $out = [];
    /* 1 · Lo que cerró (los últimos 7 días), el más viejo primero. */
    foreach (todas("SELECT br.*, b.nombre AS bono_nombre, b.tipo, b.periodo FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id
                     WHERE br.usuario_id = ? AND b.tipo <> 'wakaciones' AND br.cerrado_en >= ?
                     ORDER BY br.periodo_fin, b.orden, br.id", [$uid, date('Y-m-d', strtotime($hoy . ' -7 days')) . ' 00:00:00']) as $x) {
        $clave = 'res-' . (int)$x['id'];
        if (isset($vistas[$clave])) continue;
        $d = json_decode((string)$x['detalle'], true) ?: [];
        $gano = (int)$x['ganado'] === 1;
        $cuando = bono_periodo_texto((string)$x['periodo'], (string)$x['periodo_inicio'], (string)$x['periodo_fin']);
        $lineas = array_values(array_filter([(string)($d['texto'] ?? ''), (string)($d['falta'] ?? '')]));
        $out[] = ['clave' => $clave, 'tono' => $gano ? 'verde' : 'gris',
                  'titulo' => $gano ? ((int)$x['premio_centimos'] > 0 ? 'Ganaste ' . soles_corto((int)$x['premio_centimos']) : 'Quedaste ' . (int)$x['puesto'] . '.º')
                                     : 'Esta vez no llegó',
                  'sub' => (string)$x['bono_nombre'] . ' · ' . $cuando, 'lineas' => $lineas,
                  'logro' => $gano ? bono_logro($x, (string)($u['nombre'] ?? '')) : null];
    }
    /* 2 · Una racha que se cortó ayer. */
    if (function_exists('racha_cortada_ayer') && ($n = racha_cortada_ayer($uid, $hoy)) > 0) {
        $clave = 'rota-' . date('Y-m-d', strtotime($hoy . ' -1 day'));
        if (!isset($vistas[$clave])) {
            $mejor = (int) valor("SELECT mejor FROM rachas WHERE usuario_id = ? AND tipo = 'dias'", [$uid]);
            $out[] = ['clave' => $clave, 'tono' => 'gris', 'titulo' => 'Se cortó tu racha de ' . $n . ' días',
                      'sub' => 'Ayer no hubo venta', 'lineas' => [$mejor > 0 ? 'Tu mejor marca: ' . plural($mejor, 'día', 'días') . '. Hoy empieza otra.' : 'Hoy empieza otra.'],
                      'logro' => null];
        }
    }
    /* 3 · Lo de hoy: la Cacería, si es para él. */
    $cb = bono_tipo((int)$u['pais_id'], 'caceria');
    $cac = $cb && (int)$cb['activo'] === 1 ? caceria_de((int)$u['pais_id'], $hoy) : null;
    if ($cac && in_array($uid, caceria_destinatarios(bono_asesores((int)$u['pais_id']), (string)$cac['destino']), true)) {
        $clave = 'cac-' . $hoy;
        if (!isset($vistas[$clave])) {
            $esc = $cac['escalera'];
            $out[] = ['clave' => $clave, 'tono' => 'amarillo', 'titulo' => (string)$cac['titulo'], 'sub' => bono_nombre($cb) . ' · hoy',
                      'lineas' => array_values(array_filter([(string)$cac['frase'], 'Hoy arrancas de cero.',
                                   implode(' · ', array_map(fn($e) => (int)$e['desde'] . ' → ' . soles_corto((int)$e['premio']), $esc))])),
                      'logro' => null, 'caceria' => true];
        }
    }
    return $out;
}

/**
 * WAKACIONES: si ganó el viaje del semestre y todavía no lo vio. Va sola, a
 * pantalla completa, fuera de la pila. → ['clave','destino','premio','monto','puesto','de','nombre'] o null.
 */
function novedad_wakaciones(array $u): ?array
{
    if (!bonos_listo() || !tabla_existe('novedades_vistas')) return null;
    /* El destino es el del CIERRE (sus reglas congeladas), no el de hoy. */
    $x = una("SELECT br.*, COALESCE(bc.reglas, b.reglas) AS reglas FROM bono_resultados br JOIN bonos b ON b.id = br.bono_id
               LEFT JOIN bono_cierres bc ON bc.bono_id = br.bono_id AND bc.periodo_inicio = br.periodo_inicio
               WHERE br.usuario_id = ? AND b.tipo = 'wakaciones' AND br.ganado = 1 AND br.puesto = 1
               ORDER BY br.periodo_fin DESC LIMIT 1", [(int)$u['id']]);
    if (!$x) return null;
    $clave = 'wak-' . (int)$x['id'];
    if (valor('SELECT 1 FROM novedades_vistas WHERE usuario_id = ? AND clave = ?', [(int)$u['id'], $clave])) return null;
    $r = json_decode((string)$x['reglas'], true) ?: [];
    $de = count(bono_asesores((int)$u['pais_id']));
    return ['clave' => $clave, 'destino' => (string)($r['destino'] ?? ''), 'premio' => (string)($r['premio_texto'] ?? ''),
            'monto' => (int)$x['valor'], 'puesto' => 1, 'de' => $de, 'nombre' => (string)$u['nombre']];
}

/* ─────────────────────────  LAS FRASES (editables)  ───────────────────────── */

/**
 * Las frases de un grupo (una por línea en Configuración › Bonos):
 * 'caceria' · 'team_primero' · 'team_medio' · 'team_ultimo'.
 */
function bono_frases(string $grupo): array
{
    $def = [
        'caceria'      => ['Cada venta cuenta. Sal a buscarla.', 'Cinco para empezar, veinte para la historia.',
                           'El día es largo y la escalera, corta.', 'Hoy se caza en grupo. ¿Quién llega primero?'],
        'team_primero' => ['Nadie nos alcanza.', 'Arriba y con hambre.'],
        'team_medio'   => ['¿Nos van a ganar?', 'Estamos a nada. Métele garra.'],
        'team_ultimo'  => ['Todavía hay partido.', 'Hoy empieza la remontada.'],
    ];
    $txt = (string) ajuste('frases_' . $grupo, '');
    $l = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $txt) ?: [])));
    return $l ?: ($def[$grupo] ?? []);
}

/** Una frase del grupo, la misma todo el día (cambia de un día a otro). */
function bono_frase_del_dia(string $grupo, ?string $hoy = null): string
{
    $f = bono_frases($grupo);
    if (!$f) return '';
    return $f[crc32(($hoy ?? date('Y-m-d')) . $grupo) % count($f)];
}

/* ─────────────────────────  MODO TEAM  ───────────────────────── */

/**
 * EL RANKING DE LOS EQUIPOS DEL MES (modo_team_y_marca.md), por lo cobrado.
 * REGLA DE VISIBILIDAD, en el servidor: de tu equipo ves a las personas; de
 * los otros, solo el total. $ver_equipo: el equipo cuyas personas se piden
 * (el propio del asesor; Administración y Dirección pueden elegir cualquiera).
 * → ['equipos' => [...], 'personas' => [...], 'mio' => fila|null, 'frase', 'dato', 'para_pasar']
 */
function modo_team(int $pais_id, string $mes, ?int $ver_equipo, ?int $mi_id = null): array
{
    $asesores = bono_asesores($pais_id);
    $cob = bono_cobrado(array_keys($asesores), $mes . '-01', fin_de_mes($mes), []);
    /* La meta de cada uno, una sola vez (la página la pide para el equipo y para las personas). */
    $metas = [];
    $meta_u = function (int $id) use (&$metas, $mes) {
        return $metas[$id] ??= meta_de(una('SELECT id, pais_id, meta_mensual_centimos FROM usuarios WHERE id = ?', [$id]), $mes);
    };
    $equipos = [];
    foreach (todas('SELECT eq.id, eq.nombre, eq.lider_usuario_id, ul.nombre AS lider_nombre FROM equipos eq
                     LEFT JOIN usuarios ul ON ul.id = eq.lider_usuario_id
                     WHERE eq.pais_id = ? AND eq.activo = 1 ORDER BY eq.nombre', [$pais_id]) as $eq) {
        $miembros = array_filter($asesores, fn($a) => (int)$a['equipo_id'] === (int)$eq['id']);
        if (!$miembros) continue;
        $total = 0; $meta = 0;
        foreach ($miembros as $a) {
            $total += (int)$cob[(int)$a['id']];
            $meta += $meta_u((int)$a['id']);
        }
        $equipos[] = ['id' => (int)$eq['id'], 'nombre' => (string)$eq['nombre'], 'lider' => (string)($eq['lider_nombre'] ?? ''),
                      'gente' => count($miembros), 'cobrado' => $total, 'meta' => $meta];
    }
    usort($equipos, fn($a, $b) => [$b['cobrado'], $a['nombre']] <=> [$a['cobrado'], $b['nombre']]);
    foreach ($equipos as $i => &$e) $e['puesto'] = $i + 1;
    unset($e);

    $personas = [];
    if ($ver_equipo) {
        $ids = array_keys(array_filter($asesores, fn($a) => (int)$a['equipo_id'] === $ver_equipo));
        $act = [];
        if ($ids) {
            $en = implode(',', array_fill(0, count($ids), '?'));
            foreach (todas("SELECT asesor_id, COUNT(*) AS n, COALESCE(SUM(total_centimos), 0) AS v FROM pedidos
                             WHERE anulado_en IS NULL AND creado_en >= ? AND creado_en <= ? AND asesor_id IN ($en) GROUP BY asesor_id",
                           array_merge([$mes . '-01 00:00:00', fin_de_mes($mes) . ' 23:59:59'], $ids)) as $x) {
                $act[(int)$x['asesor_id']] = $x;
            }
        }
        foreach ($ids as $id) {
            $meta = $meta_u($id);
            $personas[] = ['id' => $id, 'nombre' => trim((string)$asesores[$id]['nombre'] . ' ' . (string)$asesores[$id]['apellidos']),
                           'foto' => $asesores[$id]['foto'] ?? null,
                           'cobrado' => (int)$cob[$id], 'pedidos' => (int)($act[$id]['n'] ?? 0), 'vendido' => (int)($act[$id]['v'] ?? 0),
                           'meta' => $meta, 'avance' => $meta > 0 ? min(999, (int) round((int)$cob[$id] * 100 / $meta)) : 0,
                           'yo' => $mi_id !== null && $id === $mi_id];
        }
        usort($personas, fn($a, $b) => [$b['cobrado'], $a['nombre']] <=> [$a['cobrado'], $b['nombre']]);
        foreach ($personas as $i => &$p) $p['puesto'] = $i + 1;
        unset($p);
    }
    $mio = null;
    foreach ($equipos as $e) if ($e['id'] === (int)$ver_equipo) $mio = $e;
    $frase = ''; $dato = ''; $para_pasar = '';
    if ($mio && count($equipos) > 1) {
        if ($mio['puesto'] === 1) {
            $frase = bono_frase_del_dia('team_primero');
            $seg = $equipos[1];
            $dato = 'Le sacan ' . soles_corto($mio['cobrado'] - $seg['cobrado']) . ' a ' . $seg['nombre'] . '.';
        } else {
            $frase = bono_frase_del_dia($mio['puesto'] === count($equipos) ? 'team_ultimo' : 'team_medio');
            $primero = $equipos[0];
            $falta = $primero['cobrado'] - $mio['cobrado'];
            $dato = $primero['nombre'] . ' va primero y les saca ' . soles_corto($falta) . '.';
            /* «Si cierran 4 pedidos de S/3,200 pasan al primero», con el ticket
               promedio real del equipo en el mes. */
            $ped = array_sum(array_column($personas, 'pedidos'));
            $ticket = $ped > 0 ? (int) round(array_sum(array_column($personas, 'vendido')) / $ped) : 0;
            if ($ticket > 0) {
                $n = (int) floor($falta / $ticket) + 1;
                $para_pasar = 'Si cierran ' . plural($n, 'pedido', 'pedidos') . ' de ' . soles_corto($ticket) . ', pasan al primero.';
            }
        }
    }
    return ['equipos' => $equipos, 'personas' => $personas, 'mio' => $mio, 'frase' => $frase, 'dato' => $dato, 'para_pasar' => $para_pasar];
}
