<?php
declare(strict_types=1);

/**
 * RACHAS (módulo 5) — TRES CONTADORES, NO DOS (rachas_de_venta.md).
 *
 *   racha del día   · clientes DISTINTOS a los que vendió HOY. Vuelve a cero a
 *                     medianoche. Es la de x2, x3, x4…
 *   días seguidos   · cuántos días seguidos vendió (al menos a un cliente),
 *                     domingos incluidos. Se corta con un día sin vender.
 *   clientes de la semana · el de Sin Freno. Vive en bonos.php
 *                     (bono_clientes): no es ninguno de los dos de arriba.
 *
 * Clientes distintos, no pedidos: partir una venta en dos no sube nada.
 * Anular un pedido baja el contador: se cuenta en vivo, no se guarda.
 * Los multiplicadores son reconocimiento: no multiplican dinero.
 */

/** La escalera del día: nombre de cada nivel (editable en Configuración). */
function racha_niveles(): array
{
    $j = json_decode((string) ajuste('racha_nombres', ''), true);
    $def = [2 => 'Calentando', 3 => 'En racha', 4 => 'Encendido', 5 => 'Imparable'];
    if (!is_array($j)) return $def;
    $out = [];
    foreach ($def as $k => $v) $out[$k] = trim((string)($j[$k] ?? $j[(string)$k] ?? '')) ?: $v;
    return $out;
}

/** El nombre de un nivel (5 o más → el de 5). '' por debajo de 2. */
function racha_nombre(int $n): string
{
    if ($n < 2) return '';
    $nv = racha_niveles();
    return $nv[min(5, $n)] ?? '';
}

/**
 * LA RACHA DEL DÍA de varios asesores: clientes distintos con una venta
 * registrada ese día y no anulada (de cualquier tipo). → [usuario_id => n]
 */
function racha_dia_de(array $usuarios, ?string $fecha = null): array
{
    $fecha ??= date('Y-m-d');
    $out = array_fill_keys($usuarios, 0);
    if (!$usuarios) return $out;
    $en = implode(',', array_fill(0, count($usuarios), '?'));
    foreach (todas("SELECT asesor_id, COUNT(DISTINCT cliente_id) AS n FROM pedidos
                     WHERE anulado_en IS NULL AND creado_en >= ? AND creado_en < ? AND asesor_id IN ($en)
                     GROUP BY asesor_id",
                   array_merge([$fecha . ' 00:00:00', date('Y-m-d', strtotime($fecha . ' +1 day')) . ' 00:00:00'], $usuarios)) as $f) {
        $out[(int)$f['asesor_id']] = (int)$f['n'];
    }
    return $out;
}

function racha_dia(int $usuario_id, ?string $fecha = null): int
{
    return racha_dia_de([$usuario_id], $fecha)[$usuario_id] ?? 0;
}

/**
 * DÍAS SEGUIDOS VENDIENDO, contando los siete días. Si hoy todavía no vendió,
 * la racha de ayer sigue viva hasta que termine el día.
 * → ['dias' => n, 'hoy' => bool (hoy ya cuenta), 'mejor' => n, 'semana' => [fecha => bool] (los 7 últimos)]
 * Guarda la mejor marca en `rachas` (tipo 'dias').
 */
function racha_dias(int $usuario_id, ?string $hoy = null, bool $guardar = true): array
{
    $hoy ??= date('Y-m-d');
    $desde = date('Y-m-d', strtotime($hoy . ' -400 days'));
    $dias = [];
    foreach (todas('SELECT DISTINCT DATE(creado_en) AS d FROM pedidos
                     WHERE asesor_id = ? AND anulado_en IS NULL AND creado_en >= ? AND creado_en < ?',
                   [$usuario_id, $desde . ' 00:00:00', date('Y-m-d', strtotime($hoy . ' +1 day')) . ' 00:00:00']) as $f) {
        $dias[(string)$f['d']] = true;
    }
    $vendio_hoy = isset($dias[$hoy]);
    $n = 0;
    $d = $vendio_hoy ? $hoy : date('Y-m-d', strtotime($hoy . ' -1 day'));
    while (isset($dias[$d])) { $n++; $d = date('Y-m-d', strtotime($d . ' -1 day')); }
    $semana = [];
    for ($i = 6; $i >= 0; $i--) { $f = date('Y-m-d', strtotime($hoy . " -$i days")); $semana[$f] = isset($dias[$f]); }

    $mejor = $n;
    if ($guardar && tabla_existe('rachas')) {
        $g = una("SELECT valor, mejor, ultima_fecha FROM rachas WHERE usuario_id = ? AND tipo = 'dias'", [$usuario_id]);
        $mejor = max($n, (int)($g['mejor'] ?? 0));
        if (!$g) {
            try { insertar('rachas', ['usuario_id' => $usuario_id, 'tipo' => 'dias', 'valor' => $n, 'mejor' => $mejor, 'ultima_fecha' => $hoy]); }
            catch (Throwable $ex) { /* otro la creó a la vez */ }
        } elseif ((int)$g['valor'] !== $n || (int)$g['mejor'] !== $mejor || (string)$g['ultima_fecha'] !== $hoy) {
            q("UPDATE rachas SET valor = ?, mejor = ?, ultima_fecha = ? WHERE usuario_id = ? AND tipo = 'dias'", [$n, $mejor, $hoy, $usuario_id]);
        }
    }
    return ['dias' => $n, 'hoy' => $vendio_hoy, 'mejor' => $mejor, 'semana' => $semana];
}

/** La mejor racha del día que tuvo (más clientes distintos en un día), del último año. */
function racha_mejor_dia(int $usuario_id, ?string $antes_de = null): int
{
    $antes_de ??= date('Y-m-d');
    return (int) valor('SELECT COALESCE(MAX(n), 0) FROM (
                            SELECT COUNT(DISTINCT cliente_id) AS n FROM pedidos
                             WHERE asesor_id = ? AND anulado_en IS NULL AND creado_en >= ? AND creado_en < ?
                             GROUP BY DATE(creado_en)) x',
                        [$usuario_id, date('Y-m-d', strtotime($antes_de . ' -365 days')) . ' 00:00:00', $antes_de . ' 00:00:00']);
}

/** ¿Se avisa de rachas? Administración puede apagarlos para todos; cada uno, para sí. */
function rachas_avisos_encendidos(): bool
{
    return (string) ajuste('racha_avisos', '1') === '1';
}

/**
 * LA VENTA QUE SE ACABA DE REGISTRAR: la racha de hoy y, si toca, el aviso al
 * equipo. Las cuatro reglas de disparo (rachas_de_venta.md):
 *   1. solo desde x3 (configurable);
 *   2. solo si es la marca más alta del día en su oficina;
 *   3. un récord propio siempre avisa;
 *   4. al que está en racha no se le avisa de su propia racha.
 * → ['n' => clientes de hoy, 'nombre' => …, 'dias' => días seguidos, 'record' => bool]
 */
function racha_tras_venta(int $usuario_id): array
{
    $u = una('SELECT id, pais_id, oficina_id, rol_id FROM usuarios WHERE id = ?', [$usuario_id]);
    if (!$u) return ['n' => 0, 'nombre' => '', 'dias' => 0, 'record' => false];
    $hoy = date('Y-m-d');
    $n = racha_dia($usuario_id, $hoy);
    $dias = racha_dias($usuario_id, $hoy);
    /* Un récord es romper una marca que ya existía: el primer día de alguien
       nuevo no es «rompió su récord». */
    $antes = racha_mejor_dia($usuario_id, $hoy);
    $umbral = max(2, (int) ajuste('racha_aviso_desde', 3));
    /* Récord: desde el mismo umbral que los avisos (x3 de fábrica). */
    $record = $n >= $umbral && $antes >= 2 && $n > $antes;
    if (tabla_existe('racha_avisos') && rachas_avisos_encendidos()) {
        $ya_mio = (int) valor('SELECT COALESCE(MAX(nivel), 0) FROM racha_avisos WHERE usuario_id = ? AND fecha = ?', [$usuario_id, $hoy]);
        if ($n > $ya_mio) {
            /* La marca de la oficina cuenta TODOS los avisos del día, récords incluidos. */
            $max_ofi = (int) valor('SELECT COALESCE(MAX(nivel), 0) FROM racha_avisos WHERE pais_id = ? AND fecha = ?'
                                   . ($u['oficina_id'] ? ' AND oficina_id = ?' : ' AND oficina_id IS NULL'),
                                   array_merge([(int)$u['pais_id'], $hoy], $u['oficina_id'] ? [(int)$u['oficina_id']] : []));
            $tipo = null;
            if ($record) $tipo = 'record';
            elseif ($n >= $umbral && $n > $max_ofi) $tipo = 'dia';
            if ($tipo) {
                insertar('racha_avisos', ['pais_id' => (int)$u['pais_id'], 'oficina_id' => $u['oficina_id'] ?: null,
                                          'usuario_id' => $usuario_id, 'nivel' => $n, 'tipo' => $tipo, 'fecha' => $hoy,
                                          'creado_en' => date('Y-m-d H:i:s')]);
            }
        }
    }
    return ['n' => $n, 'nombre' => racha_nombre($n), 'dias' => $dias['dias'], 'record' => $record];
}

/**
 * Los avisos de racha de los DEMÁS desde un id (el sondeo del asesor). Del
 * mismo país, de hoy; nunca los propios.
 * → ['ultimo' => id, 'avisos' => [['nombre','nivel','texto']]]
 */
function racha_avisos_desde(array $u, int $desde): array
{
    $ultimo = (int) valor('SELECT COALESCE(MAX(id), 0) FROM racha_avisos WHERE pais_id = ? AND fecha = ?', [(int)$u['pais_id'], date('Y-m-d')]);
    /* $desde < 0: la primera vuelta, solo se toma la marca. Con 0 (la página se
       abrió sin avisos hoy) el primero del día sí se enseña. */
    if ($desde < 0 || $ultimo <= $desde || !rachas_avisos_encendidos() || (int)($u['avisos_rachas'] ?? 1) !== 1) {
        return ['ultimo' => max($desde, $ultimo), 'avisos' => []];
    }
    $out = [];
    foreach (todas('SELECT ra.nivel, ra.tipo, us.nombre FROM racha_avisos ra JOIN usuarios us ON us.id = ra.usuario_id
                     WHERE ra.pais_id = ? AND ra.id > ? AND ra.usuario_id <> ? AND ra.fecha = ?
                     ORDER BY ra.id DESC LIMIT 3', [(int)$u['pais_id'], $desde, (int)$u['id'], date('Y-m-d')]) as $a) {
        $nom = primer_nombre((string)$a['nombre']);
        $out[] = ['nombre' => $nom, 'nivel' => (int)$a['nivel'],
                  'texto' => $a['tipo'] === 'record' ? $nom . ' rompió su récord: x' . (int)$a['nivel']
                                                     : $nom . ' está en racha x' . (int)$a['nivel']];
    }
    return ['ultimo' => $ultimo, 'avisos' => $out];
}

/**
 * «EN RACHA AHORA»: los asesores del país con racha de x2 para arriba hoy,
 * el más encendido arriba. → [['id','nombre','n','nivel']]
 */
function en_racha_ahora(int $pais_id): array
{
    $as = function_exists('bono_asesores') ? bono_asesores($pais_id) : [];
    $r = racha_dia_de(array_keys($as));
    arsort($r);
    $out = [];
    foreach ($r as $uid => $n) {
        if ($n < 2) break;
        $out[] = ['id' => $uid, 'nombre' => trim((string)$as[$uid]['nombre'] . ' ' . (string)$as[$uid]['apellidos']), 'n' => $n, 'nivel' => racha_nombre($n)];
    }
    return $out;
}

/**
 * ¿AYER SE CORTÓ UNA RACHA? Si ayer no vendió y hasta anteayer llevaba N días
 * seguidos (N ≥ 3), devuelve N. Si no, 0. Es la tarjeta «Se cortó tu racha»
 * de la pila de novedades: se ve solo el día siguiente.
 */
function racha_cortada_ayer(int $usuario_id, ?string $hoy = null): int
{
    $hoy ??= date('Y-m-d');
    $ayer = date('Y-m-d', strtotime($hoy . ' -1 day'));
    $r = racha_dias($usuario_id, $ayer, false);   // mirar, sin reescribir la racha de hoy
    if ($r['hoy']) return 0;                   // ayer sí vendió
    return $r['dias'] >= 3 ? $r['dias'] : 0;   // la racha que venía hasta anteayer
}
