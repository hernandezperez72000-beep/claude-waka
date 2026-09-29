<?php
declare(strict_types=1);

/**
 * LA VENTA DESCUENTA EL STOCK DE LA WEB (3e).
 *
 * Decidido por el usuario (tarjetas del 2026-09-25):
 *   · con «Control de stock» encendido, una venta de ENTREGA INMEDIATA
 *     descuenta de compraenwaka AL REGISTRARSE; si se anula, vuelve;
 *   · sin stock en la web, NO DEJA VENDER;
 *   · si la tienda no responde, se mira el ÚLTIMO STOCK LEÍDO: si dice que
 *     hay, la venta se registra y el descuento queda en una COLA que se
 *     reintenta sola; si dice agotado, no deja;
 *   · si la cola llega tarde y la web ya no tiene (se vendió allá mientras
 *     tanto), DESCUENTA IGUAL —la web queda en negativo y sale agotada— y se
 *     avisa a Administración;
 *   · la pre venta y la liquidación no tocan la web: tienen su propio stock.
 *
 * Todo lo que se mueve en la web pasa por la cola `stock_web_cola`, también
 * lo que salió bien a la primera (queda como «hecho»): es el registro de qué
 * se descontó y qué se devolvió, pedido por pedido. Cada fila lleva su clave
 * de repetición: el conector la recuerda, así que repetir una petición que se
 * cortó a medias nunca descuenta dos veces.
 *
 * Una devolución espera a su descuento (`depende_de`): no se devuelve lo que
 * todavía no se sabe si se quitó.
 */

/** Cuántas filas de la cola se mueven en una pasada. */
const STOCK_COLA_TOPE = 10;

/** ¿Está encendido el control de stock? Una sola definición. */
function control_stock(): bool
{
    return (bool) ajuste('control_stock', false);
}

/**
 * ¿ESTA venta descuenta de la web? Solo la entrega inmediata, con el control
 * encendido, en el país de la tienda.
 */
function venta_descuenta_web(string $tipo, int $pais_id): bool
{
    if (!control_stock() || $tipo !== 'inmediata') return false;
    if (!tabla_existe('stock_web_cola')) return false;
    $cfg = tienda_config();
    return $cfg['ruta'] !== '' && tienda_es_de_mi_pais($cfg, $pais_id);
}

/**
 * LO QUE LA VENTA MUEVE EN LA WEB, sacado de sus líneas. Solo las que están
 * enlazadas con la tienda; lo demás (fuera de catálogo, un producto que no
 * está en la web) se vende sin tocarla.
 * → [['woo_id', 'variacion_id', 'cantidad' (negativa), 'producto_id',
 *     'variante_id', 'nombre'], …] — el mismo producto y color, sumado.
 */
function stock_venta_movimientos(array $lineas): array
{
    $out = [];
    foreach ($lineas as $l) {
        $pid = (int)($l['producto_id'] ?? 0);
        $vid = (int)($l['variante_id'] ?? 0);
        $c   = (int)($l['cantidad'] ?? 0);
        if (!$pid || $c <= 0) continue;
        $p = una('SELECT id, nombre, woo_id FROM productos WHERE id = ?', [$pid]);
        if (!$p || empty($p['woo_id'])) continue;
        $var_woo = 0;
        $nombre = (string)$p['nombre'];
        if ($vid) {
            $v = una('SELECT * FROM variantes WHERE id = ? AND producto_id = ?', [$vid, $pid]);
            if (!$v) continue;
            $nombre .= ' — ' . variante_nombre($v);
            if (!empty($v['woo_id'])) {
                $var_woo = (int)$v['woo_id'];
            } elseif (valor('SELECT 1 FROM variantes WHERE producto_id = ? AND woo_id IS NOT NULL', [$pid])) {
                /* El producto tiene colores en la web, pero este no está
                   enlazado: no se sabe de cuál descontar. Se vende sin tocarla. */
                continue;
            }
        }
        $k = $p['woo_id'] . '-' . $var_woo;
        if (isset($out[$k])) { $out[$k]['cantidad'] -= $c; continue; }
        $out[$k] = ['woo_id' => (int)$p['woo_id'], 'variacion_id' => $var_woo, 'cantidad' => -$c,
                    'producto_id' => $pid, 'variante_id' => $vid, 'nombre' => $nombre];
    }
    return array_values($out);
}

/** Lo que viaja a la tienda: solo los tres números de cada movimiento. */
function stock_venta_cuerpo(array $movs): array
{
    return array_map(fn($m) => ['woo_id' => (int)$m['woo_id'], 'variacion_id' => (int)$m['variacion_id'],
                                'cantidad' => (int)$m['cantidad']], $movs);
}

/** «2 × Silla Gamer — Negro · 1 × Mesa» para las pantallas. */
function stock_venta_detalle(array $movs): string
{
    return mb_substr(implode(' · ', array_map(fn($m) => abs((int)$m['cantidad']) . ' × ' . $m['nombre'], $movs)), 0, 500);
}

/**
 * ¿QUÉ FALTA SEGÚN EL ÚLTIMO STOCK LEÍDO? Se usa cuando la tienda no responde.
 * Se cuenta como la tienda: un color con unidades propias por su lado; un
 * color que comparte las del producto, junto con el producto. De lo que no
 * hay foto no se sabe nada, y no frena.
 *
 * A lo que dice la foto se le RESTA lo que la cola todavía tiene que
 * descontar: la foto es siempre lo que dijo la web, y lo vendido con la tienda
 * caída todavía no está allá. Se resta aquí, al mirar, y no en la foto: cada
 * lectura, cada venta y cada cambio desde la ficha escriben en la foto el
 * número de la web, y una resta guardada en la foto se perdía con cualquiera
 * de ellos (auditoría y verificación del 3e). Si alguna de las que esperan ya
 * se había descontado allá (se perdió la respuesta), frena de más; nunca vende
 * de más.
 * → [['nombre', 'hay', 'pide'], …]
 */
function stock_venta_falta_segun_foto(array $movs): array
{
    if (!$movs || !tabla_existe('stock_web')) return [];
    /* De qué cuenta de unidades sale un producto y color: la del color, o la
       del producto si el color comparte (o no tiene foto propia). */
    $cuenta = function (int $pid, int $vid): ?array {
        if ($vid) {
            $f = una('SELECT * FROM stock_web WHERE producto_id = ? AND variante_id = ?', [$pid, $vid]);
            if ($f) return [(int)$f['compartido'] ? 'p' . $pid : 'v' . $vid, $f];
        }
        $f = una('SELECT * FROM stock_web WHERE producto_id = ? AND variante_id = 0', [$pid]);
        return $f ? ['p' . $pid, $f] : null;
    };
    $pide = []; $info = [];
    foreach ($movs as $m) {
        $c = $cuenta((int)$m['producto_id'], (int)$m['variante_id']);
        if (!$c) continue;
        [$k, $f] = $c;
        $pide[$k] = ($pide[$k] ?? 0) + abs((int)$m['cantidad']);
        $info[$k] ??= ['fila' => $f, 'nombre' => (int)$f['compartido'] || !(int)$m['variante_id']
                                                ? (string)explode(' — ', $m['nombre'])[0] : $m['nombre']];
    }
    $espera = [];
    if ($pide) {
        foreach (stock_cola_por_salir() as $q) {
            foreach (json_decode((string)$q['movimientos'], true) ?: [] as $m) {
                $c = $cuenta((int)($m['producto_id'] ?? 0), (int)($m['variante_id'] ?? 0));
                if ($c && isset($pide[$c[0]])) $espera[$c[0]] = ($espera[$c[0]] ?? 0) + abs((int)$m['cantidad']);
            }
        }
    }
    $faltan = [];
    foreach ($pide as $k => $n) {
        $f = $info[$k]['fila'];
        $estado = (string)$f['estado'];
        if ($estado === 'onbackorder') continue;                  // la web vende bajo pedido
        if ($f['cantidad'] === null) {
            if ($estado === 'outofstock') $faltan[] = ['nombre' => $info[$k]['nombre'], 'hay' => 0, 'pide' => $n];
            continue;
        }
        $hay = max(0, (int)$f['cantidad'] - ($espera[$k] ?? 0));
        if ($hay < $n) $faltan[] = ['nombre' => $info[$k]['nombre'], 'hay' => $hay, 'pide' => $n];
    }
    return $faltan;
}

/**
 * LO QUE FALTA, EN PALABRAS PARA EL ASESOR. Una sola forma de decirlo.
 * $faltan: [['nombre', 'hay', 'pide'], …]
 */
function stock_venta_falta_texto(array $faltan): string
{
    $partes = [];
    foreach ($faltan as $f) {
        $partes[] = (int)$f['hay'] <= 0
            ? '«' . $f['nombre'] . '» está agotado'
            : '«' . $f['nombre'] . '»: quedan ' . (int)$f['hay'] . ' y pides ' . (int)$f['pide'];
    }
    return 'No hay stock suficiente. ' . implode('. ', $partes) . '.';
}

/**
 * Lo que dijo la tienda al negar por falta de stock, puesto con los nombres
 * de la venta. La tienda contesta con el número de lo que lleva el stock (el
 * color, o el producto si los colores comparten).
 */
function stock_venta_faltan_de_la_tienda(array $faltan_web, array $movs): array
{
    $out = [];
    foreach ($faltan_web as $f) {
        $id = (int)($f['id'] ?? 0);
        $nombre = '';
        foreach ($movs as $m) {
            if ((int)$m['variacion_id'] === $id) { $nombre = $m['nombre']; break; }
        }
        if ($nombre === '') {
            foreach ($movs as $m) {
                if ((int)$m['woo_id'] === $id) { $nombre = (string)explode(' — ', $m['nombre'])[0]; break; }
            }
        }
        $out[] = ['nombre' => $nombre !== '' ? $nombre : 'un producto', 'hay' => (int)($f['hay'] ?? 0),
                  'pide' => (int)($f['pide'] ?? 0)];
    }
    return $out;
}

/**
 * LLAMA A LA TIENDA con una fila de la cola (o con lo que va a ser una).
 * → ['r', 'faltan', 'quedan', 'error'], con 'r':
 *   'hecho'     la tienda lo hizo (o ya lo había hecho: misma clave);
 *   'sin_stock' no había, y no se movió nada;
 *   'no_existe' un producto de la venta ya no está en la web; no se movió nada;
 *   'rechazo'   la tienda no deja entrar al conector (clave, complemento de
 *               seguridad, plugin apagado); no se movió nada;
 *   'caida'     no contestó, o falló por dentro: NO SE SABE si se movió.
 */
function stock_venta_llamar(string $clave, array $cuerpo, bool $exigir, int $segundos = 15): array
{
    $mal = fn(string $r, string $e) => ['r' => $r, 'faltan' => [], 'quedan' => [], 'error' => $e];
    /* Sin clave del conector no sale nada: se sabe seguro que no se movió. */
    if (!conector_config()['listo']) return $mal('rechazo', 'Falta poner la clave del conector en Configuración › Tienda.');
    $r = conector_llamar('POST', '/stock/mover',
                         ['clave' => $clave, 'movimientos' => $cuerpo, 'exigir' => $exigir], $segundos);
    if ($r['ok']) return ['r' => 'hecho', 'faltan' => [], 'quedan' => (array)($r['datos']['quedan'] ?? []), 'error' => ''];
    $cod = (string)($r['datos']['codigo'] ?? '');
    if ($r['http'] === 409 && $cod === 'sin_stock') {
        return ['r' => 'sin_stock', 'faltan' => (array)($r['datos']['faltan'] ?? []), 'quedan' => [], 'error' => $r['error']];
    }
    if ($r['http'] === 0 || $r['http'] >= 500) return $mal('caida', $r['error']);
    if ($r['http'] === 404 && $cod === 'no_existe') return $mal('no_existe', $r['error']);
    return $mal('rechazo', $r['error']);
}

/**
 * APUNTA EN LA FOTO DEL STOCK lo que la tienda dice que quedó, para que el
 * asesor vea al momento el número nuevo. Solo corrige filas que ya existen.
 * $quedan: [['id' (de la web), 'stock', 'estado'], …]
 */
function stock_web_apuntar_quedan(array $quedan): void
{
    if (!$quedan || !tabla_existe('stock_web')) return;
    $ahora = date('Y-m-d H:i:s');
    foreach ($quedan as $x) {
        $id = (int)($x['id'] ?? 0);
        if (!$id) continue;
        $cant = array_key_exists('stock', $x) && $x['stock'] !== null ? (int)$x['stock'] : null;
        $est  = in_array($x['estado'] ?? '', ['instock', 'outofstock', 'onbackorder'], true) ? (string)$x['estado'] : null;
        foreach (todas('SELECT id, producto_id FROM variantes WHERE woo_id = ?', [$id]) as $v) {
            q('UPDATE stock_web SET cantidad = ?, estado = COALESCE(?, estado), leido_en = ?
                WHERE producto_id = ? AND variante_id = ?', [$cant, $est, $ahora, (int)$v['producto_id'], (int)$v['id']]);
            /* Un color que comparte: todos los que comparten dicen lo mismo, y el producto también. */
            if (valor('SELECT compartido FROM stock_web WHERE producto_id = ? AND variante_id = ?',
                      [(int)$v['producto_id'], (int)$v['id']])) {
                q('UPDATE stock_web SET cantidad = ?, estado = COALESCE(?, estado), leido_en = ?
                    WHERE producto_id = ? AND (compartido = 1 OR variante_id = 0)', [$cant, $est, $ahora, (int)$v['producto_id']]);
            }
        }
        foreach (todas('SELECT id FROM productos WHERE woo_id = ?', [$id]) as $p) {
            q('UPDATE stock_web SET cantidad = ?, estado = COALESCE(?, estado), leido_en = ?
                WHERE producto_id = ? AND (variante_id = 0 OR compartido = 1)', [$cant, $est, $ahora, (int)$p['id']]);
        }
    }
}

/* ─────────────────────────  AL REGISTRAR LA VENTA  ───────────────────────── */

/**
 * ANTES DE GUARDAR LA VENTA: descuenta de la web, o dice por qué no se puede.
 * Se llama con todo lo demás ya validado, justo antes de la transacción.
 * → ['ok', 'error', 'estado' => ''|'hecho'|'pendiente', 'clave', 'movs', 'aviso']
 *   estado '' = esta venta no toca la web.
 */
function stock_venta_preparar(string $tipo, int $pais_id, array $lineas): array
{
    $nada = ['ok' => true, 'error' => '', 'estado' => '', 'clave' => '', 'movs' => [], 'aviso' => ''];
    if (!venta_descuenta_web($tipo, $pais_id)) return $nada;
    $movs = stock_venta_movimientos($lineas);
    if (!$movs) return $nada;

    $clave = 'v' . bin2hex(random_bytes(12));
    $r = stock_venta_llamar($clave, stock_venta_cuerpo($movs), true);
    if ($r['r'] === 'hecho') {
        try { stock_web_apuntar_quedan($r['quedan']); } catch (Throwable $ex) { error_log('[HUB stock] ' . $ex->getMessage()); }
        return ['ok' => true, 'error' => '', 'estado' => 'hecho', 'clave' => $clave, 'movs' => $movs, 'aviso' => ''];
    }
    $no = fn(string $e) => ['ok' => false, 'error' => $e, 'estado' => '', 'clave' => '', 'movs' => [], 'aviso' => ''];
    if ($r['r'] === 'sin_stock') return $no(stock_venta_falta_texto(stock_venta_faltan_de_la_tienda($r['faltan'], $movs)));
    /* Un producto enlazado que ya no está en la web: no se sabe de dónde
       descontar. Se para y lo arregla Administración (la tienda no movió nada). */
    if ($r['r'] === 'no_existe') return $no('Uno de los productos ya no está en la web. Avisa a Administración para que lo revise.');

    /* LA TIENDA NO RESPONDIÓ (o no deja entrar al conector). Se mira el último
       stock leído. */
    $faltan = stock_venta_falta_segun_foto($movs);
    if ($faltan) {
        /* No se vende. Si la tienda no contestó, puede que la petición sí
           llegara y allá ya se descontara: queda en la cola para repetirla
           (con la misma clave no descuenta dos veces) y devolverlo. Si la
           tienda la rechazó, seguro que no movió nada. */
        if ($r['r'] === 'caida') stock_venta_deshacer(['estado' => 'pendiente', 'clave' => $clave, 'movs' => $movs], $r['error']);
        return $no(stock_venta_falta_texto($faltan));
    }
    return ['ok' => true, 'error' => '', 'estado' => 'pendiente', 'clave' => $clave, 'movs' => $movs,
            'aviso' => $r['r'] === 'rechazo'
                ? 'Venta registrada. El stock de la web se descontará cuando Administración revise la conexión con la tienda.'
                : 'La tienda no respondió. El stock de la web se descontará solo en unos minutos.',
            'fallo' => $r['error'],
            /* La tienda no deja entrar al conector: eso no se arregla solo. */
            'revisar' => $r['r'] === 'rechazo'];
}

/**
 * DENTRO DE LA TRANSACCIÓN DE LA VENTA: apunta el descuento en la cola, ya
 * con el número del pedido.
 */
function stock_venta_apuntar(int $pedido_id, array $prep): void
{
    if (($prep['estado'] ?? '') === '') return;
    stock_cola_nueva($pedido_id, 'descuento', $prep['clave'], $prep['movs'], true, $prep['estado'], null,
                     (string)($prep['fallo'] ?? ''), !empty($prep['revisar']));
}

/**
 * LA VENTA NO SE GUARDÓ después de descontar (o de intentarlo): se devuelve.
 * Queda como una pareja sin pedido —el descuento y su devolución— para que la
 * cola la resuelva igual que cualquier otra.
 */
function stock_venta_deshacer(array $prep, string $error = ''): void
{
    if (($prep['estado'] ?? '') === '' || !$prep['movs']) return;
    try {
        $d = stock_cola_nueva(null, 'descuento', $prep['clave'], $prep['movs'], true, $prep['estado'], null, $error);
        $v = stock_cola_nueva(null, 'devolucion', 'd' . bin2hex(random_bytes(12)), $prep['movs'], false, 'pendiente', $d);
        /* Si allá se descontó seguro, se devuelve ya. Si no se sabe (la
           tienda no contestó), no se vuelve a llamar ahora: el asesor está
           esperando la respuesta y la tienda acaba de fallar. Lo hace la cola. */
        if ($prep['estado'] === 'hecho') stock_cola_procesar(null, 2, [$v]);
    } catch (Throwable $ex) {
        error_log('[HUB stock] no se pudo apuntar la devolución de una venta que no se guardó: ' . $ex->getMessage()
                  . ' · clave ' . $prep['clave']);
    }
}

/* ─────────────────────────  AL ANULAR  ───────────────────────── */

/**
 * DENTRO DE LA TRANSACCIÓN DE ANULAR: si esta venta descontó (o está por
 * descontar), se apunta su devolución. Si nunca tocó la web —se vendió con el
 * control apagado—, no hay nada que devolver.
 */
function stock_venta_devolver(int $pedido_id): void
{
    if (!tabla_existe('stock_web_cola')) return;
    $d = una("SELECT * FROM stock_web_cola WHERE pedido_id = ? AND tipo = 'descuento' AND estado <> 'cancelado'", [$pedido_id]);
    if (!$d) return;
    if (valor("SELECT 1 FROM stock_web_cola WHERE pedido_id = ? AND tipo = 'devolucion'", [$pedido_id])) return;
    $movs = json_decode((string)$d['movimientos'], true) ?: [];
    /* La devolución va a la MISMA tienda que el descuento: si entretanto se
       cambió la dirección, la cola no la manda a la nueva. */
    stock_cola_nueva($pedido_id, 'devolucion', 'd' . bin2hex(random_bytes(12)), $movs, false, 'pendiente', (int)$d['id'],
                     '', false, (string)$d['tienda']);
}

/**
 * UNA GARANTÍA ANULADA (3i): si al aprobarla se descontó de la web (un
 * repuesto que está en la tienda), se apunta su devolución. Como la de una
 * venta anulada, pero colgada de la garantía y no de un pedido.
 */
function stock_garantia_devolver(int $garantia_id): void
{
    if (!tabla_existe('stock_web_cola') || !columna_existe('stock_web_cola', 'garantia_id')) return;
    $d = una("SELECT * FROM stock_web_cola WHERE garantia_id = ? AND tipo = 'descuento' AND estado <> 'cancelado'", [$garantia_id]);
    if (!$d) return;
    if (valor("SELECT 1 FROM stock_web_cola WHERE garantia_id = ? AND tipo = 'devolucion'", [$garantia_id])) return;
    $movs = json_decode((string)$d['movimientos'], true) ?: [];
    $v = stock_cola_nueva(null, 'devolucion', 'd' . bin2hex(random_bytes(12)), $movs, false, 'pendiente', (int)$d['id'],
                          '', false, (string)$d['tienda']);
    q('UPDATE stock_web_cola SET garantia_id = ? WHERE id = ?', [$garantia_id, $v]);
}

/* ─────────────────────────  LA COLA  ───────────────────────── */

/**
 * Una fila nueva en la cola. Los movimientos se guardan con su nombre (para
 * las pantallas); a la tienda viajan solo los números, con el signo que toca:
 * negativo para descontar, positivo para devolver.
 */
function stock_cola_nueva(?int $pedido_id, string $tipo, string $clave, array $movs, bool $exigir,
                          string $estado, ?int $depende_de, string $error = '', bool $revisar = false,
                          ?string $tienda = null): int
{
    $signo = $tipo === 'devolucion' ? 1 : -1;
    $movs = array_map(function ($m) use ($signo) { $m['cantidad'] = $signo * abs((int)$m['cantidad']); return $m; }, $movs);
    $ahora = date('Y-m-d H:i:s');
    return insertar('stock_web_cola', [
        'pedido_id'   => $pedido_id,
        'tipo'        => $tipo,
        'clave'       => $clave,
        'movimientos' => json_encode(array_values($movs), JSON_UNESCAPED_UNICODE),
        'detalle'     => stock_venta_detalle($movs),
        'exigir'      => $exigir ? 1 : 0,
        'depende_de'  => $depende_de,
        'estado'      => $estado,
        'intentos'    => $estado === 'pendiente' && $error !== '' ? 1 : 0,
        'ultimo_error'=> $error !== '' ? mb_substr($error, 0, 300) : null,
        'proximo_en'  => $estado === 'pendiente' && $error !== '' ? date('Y-m-d H:i:s', time() + 60) : $ahora,
        'revisar'     => $revisar ? 1 : 0,
        /* De QUÉ tienda: si mañana se cambia la dirección, lo de esta no se
           manda a la otra. */
        'tienda'      => mb_substr($tienda ?? tienda_config()['ruta'], 0, 190),
        'hecho_en'    => $estado === 'hecho' ? $ahora : null,
        'creado_en'   => $ahora,
    ]);
}

/** Minutos hasta el siguiente intento, según cuántos van. */
function stock_cola_espera(int $intentos): int
{
    $t = [1, 2, 5, 10, 20, 30, 60];
    return $t[max(0, min(count($t) - 1, $intentos - 1))];
}

/**
 * MUEVE LA COLA: intenta las filas pendientes que ya tocan. Devuelve cuántas
 * quedaron resueltas (hechas, o canceladas porque no hacía falta mover nada).
 * $pedido_id: solo las de ese pedido, toquen o no (al anular).
 * $ids: solo esas filas, toquen o no (el botón «Reintentar»).
 *
 * Cada fila se manda UNA vez por pasada, y si la tienda no contesta la pasada
 * se para: con la tienda caída, seguir llamando solo hace esperar a quien abrió
 * la pantalla (auditoría del 3e).
 */
function stock_cola_procesar(?int $pedido_id = null, int $tope = STOCK_COLA_TOPE, array $ids = []): int
{
    if (!tabla_existe('stock_web_cola')) return 0;
    $ahora = date('Y-m-d H:i:s');
    if ($ids) {
        $en = implode(',', array_fill(0, count($ids), '?'));
        $filas = todas("SELECT * FROM stock_web_cola WHERE estado = 'pendiente' AND id IN ($en) ORDER BY proximo_en, id", array_map('intval', $ids));
    } elseif ($pedido_id !== null) {
        $filas = todas("SELECT * FROM stock_web_cola WHERE estado = 'pendiente' AND pedido_id = ? ORDER BY id", [$pedido_id]);
    } else {
        /* Sin las devoluciones que todavía esperan a su descuento: ocuparían
           sitio en cada pasada sin poder hacer nada. */
        $filas = todas("SELECT c.* FROM stock_web_cola c
                         WHERE c.estado = 'pendiente' AND c.proximo_en <= ?
                           AND (c.depende_de IS NULL
                                OR NOT EXISTS (SELECT 1 FROM stock_web_cola m
                                                WHERE m.id = c.depende_de AND m.estado = 'pendiente'))
                         ORDER BY c.id LIMIT " . max(1, min(100, $tope)), [$ahora]);
    }
    $hechas = 0;
    $intentadas = [];
    $uno = function (int $id, ?array $f = null) use (&$intentadas, &$hechas): string {
        if (isset($intentadas[$id])) return 'ya';
        $intentadas[$id] = true;
        $r = stock_cola_uno($id, $f);
        if ($r === 'hecho') $hechas++;
        return $r;
    };
    foreach ($filas as $f) {
        if (isset($intentadas[(int)$f['id']])) continue;
        /* Una devolución espera a su descuento: si el descuento se canceló
           (no se movió nada), la devolución tampoco tiene nada que hacer. */
        if ($f['depende_de']) {
            $madre = (string) valor('SELECT estado FROM stock_web_cola WHERE id = ?', [(int)$f['depende_de']], '');
            if ($madre === 'pendiente') {
                /* Al anular o con «Reintentar», se intenta antes el descuento
                   (una vez). En la pasada normal no: el descuento tiene su
                   propia espera entre intentos. */
                if ($pedido_id === null && !$ids) continue;
                if ($uno((int)$f['depende_de']) === 'caida') break;
                $madre = (string) valor('SELECT estado FROM stock_web_cola WHERE id = ?', [(int)$f['depende_de']], '');
                if ($madre === 'pendiente') continue;
            }
            if ($madre === 'cancelado' || $madre === '') {
                $hechas += q("UPDATE stock_web_cola SET estado = 'cancelado', hecho_en = ?, ultimo_error = NULL,
                                     nota = 'No hacía falta: no se había descontado nada' WHERE id = ? AND estado = 'pendiente'",
                             [$ahora, (int)$f['id']])->rowCount();
                $intentadas[(int)$f['id']] = true;
                continue;
            }
        }
        $r = $uno((int)$f['id'], $f);
        if ($r === 'caida') break;
        /* Un descuento recién hecho con su devolución esperando (una venta que
           se anuló, o que no llegó a guardarse): la devolución va YA, para que
           la web no se quede un rato con menos de lo que hay. */
        if ($r === 'hecho' && $f['tipo'] === 'descuento') {
            foreach (todas("SELECT * FROM stock_web_cola WHERE depende_de = ? AND estado = 'pendiente'", [(int)$f['id']]) as $h) {
                if ($uno((int)$h['id'], $h) === 'caida') break 2;
            }
        }
    }
    return $hechas;
}

/**
 * UNA fila de la cola. → 'hecho' (resuelta: hecha o cancelada), 'espera'
 * (sigue pendiente), 'caida' (la tienda no contestó) o 'nada' (no se tomó).
 *
 * Se «toma» con un UPDATE que solo gana uno, comparando con los intentos que
 * vio la lista: dos pasadas a la vez (el cron y alguien abriendo la lista de
 * pedidos) no la mandan dos veces. Aunque la mandaran, la clave de repetición
 * lo pararía en la tienda; esto ahorra la llamada y la línea doble en la
 * bitácora.
 */
function stock_cola_uno(int $id, ?array $f = null): string
{
    $f ??= una("SELECT * FROM stock_web_cola WHERE id = ? AND estado = 'pendiente'", [$id]);
    if (!$f || $f['estado'] !== 'pendiente') return 'nada';
    $intentos = (int)$f['intentos'] + 1;
    /* Solo el banco de pruebas: otra pasada se adelanta justo aquí. */
    if (isset($GLOBALS['__stock_cola_antes_de_tomar'])) ($GLOBALS['__stock_cola_antes_de_tomar'])($id);
    $tomada = q("UPDATE stock_web_cola SET intentos = ?, proximo_en = ?
                  WHERE id = ? AND estado = 'pendiente' AND intentos = ?",
                [$intentos, date('Y-m-d H:i:s', time() + 60 * stock_cola_espera($intentos)), $id, (int)$f['intentos']])->rowCount();
    if ($tomada !== 1) return 'nada';
    /* Lo de la fila puede haber cambiado entre la lista y la toma (el forzado). */
    $f = una('SELECT * FROM stock_web_cola WHERE id = ?', [$id]) ?? $f;

    $movs   = json_decode((string)$f['movimientos'], true) ?: [];
    $cuerpo = stock_venta_cuerpo($movs);
    $ahora  = date('Y-m-d H:i:s');
    /* ES DE OTRA TIENDA (se cambió la dirección después): no se manda. */
    if ((string)($f['tienda'] ?? '') !== '' && (string)$f['tienda'] !== tienda_config()['ruta']) {
        q("UPDATE stock_web_cola SET estado = 'cancelado', revisar = 1, hecho_en = ?, ultimo_error = NULL,
                 nota = 'Era de la tienda de antes (se cambió la dirección): revisa este stock a mano.'
            WHERE id = ? AND estado = 'pendiente'", [$ahora, $id]);
        return 'hecho';
    }
    /* YA SE DECIDIÓ DESCONTAR SIN STOCK: se repite ESA petición, siempre la
       misma. Volver a la primera (la que exige stock) podía descontar dos
       veces si la tienda repuso entre medias (auditoría del 3e). */
    $r = (int)$f['forzado']
        ? stock_venta_llamar((string)$f['clave'] . '-f', $cuerpo, false)
        : stock_venta_llamar((string)$f['clave'], $cuerpo, (bool)$f['exigir']);

    if ($r['r'] === 'sin_stock') {
        $ped = $f['pedido_id'] ? pedido_de((int)$f['pedido_id']) : null;
        /* Nada se movió. Si ya no hay venta (no se guardó, o se anuló
           mientras tanto), no hay nada que descontar: se cancela, y con ella
           su devolución. */
        if (!$ped || (string)$ped['estado'] === 'anulado') {
            q("UPDATE stock_web_cola SET estado = 'cancelado', hecho_en = ?, ultimo_error = NULL,
                      nota = 'La venta no siguió: no se movió nada' WHERE id = ? AND estado = 'pendiente'", [$ahora, $id]);
            q("UPDATE stock_web_cola SET estado = 'cancelado', hecho_en = ?, ultimo_error = NULL,
                      nota = 'No hacía falta: no se había descontado nada' WHERE depende_de = ? AND estado = 'pendiente'",
              [$ahora, $id]);
            return 'hecho';
        }
        /* LA VENTA EXISTE Y LA WEB YA NO TIENE: se descuenta igual (decisión
           del usuario) y se avisa a Administración. Se APUNTA ANTES de mandar
           que desde ahora va forzada: si esta respuesta también se pierde, el
           siguiente intento repite esta misma petición. */
        $faltan = stock_venta_faltan_de_la_tienda($r['faltan'], $movs);
        $marcada = q("UPDATE stock_web_cola SET forzado = 1, negativo = ? WHERE id = ? AND estado = 'pendiente'",
                     [mb_substr(stock_venta_falta_texto($faltan), 0, 500), $id])->rowCount();
        if ($marcada !== 1) return 'nada';
        $f['forzado'] = 1;
        $r = stock_venta_llamar((string)$f['clave'] . '-f', $cuerpo, false);
    }
    if ($r['r'] === 'hecho') {
        try { stock_web_apuntar_quedan($r['quedan']); } catch (Throwable $ex) { error_log('[HUB stock] ' . $ex->getMessage()); }
        stock_cola_marcar_hecho($f);
        return 'hecho';
    }
    /* Lo que no se arregla solo (un producto que ya no está en la web, la
       tienda que no deja entrar al conector) se marca para Administración. */
    q('UPDATE stock_web_cola SET ultimo_error = ?, revisar = ? WHERE id = ? AND estado = ?',
      [mb_substr($r['error'], 0, 300), $r['r'] === 'caida' ? (int)$f['revisar'] : 1, $id, 'pendiente']);
    return $r['r'] === 'caida' ? 'caida' : 'espera';
}

/**
 * LA TIENDA LO HIZO: la fila queda hecha. Solo si seguía pendiente: si
 * mientras la petición iba y venía Administración la dio por resuelta, no se
 * pisa en silencio (auditoría del 3e):
 *   · la resolvió como «no hacía falta» → la tienda sí lo movió: queda hecha,
 *     para revisar, y su devolución (si la cancelaron con ella) vuelve a la cola;
 *   · la resolvió como «lo hice a mano» → la tienda lo hizo OTRA vez: para revisar.
 */
function stock_cola_marcar_hecho(array $f): void
{
    $id = (int)$f['id'];
    $ahora = date('Y-m-d H:i:s');
    $n = q("UPDATE stock_web_cola SET estado = 'hecho', hecho_en = ?, ultimo_error = NULL, revisar = ?
             WHERE id = ? AND estado = 'pendiente'", [$ahora, (int)$f['forzado'] ? 1 : 0, $id])->rowCount();
    $ped = $f['pedido_id'] !== null ? (int)$f['pedido_id'] : null;
    if ($n === 1) {
        if ((int)$f['forzado']) bitacora('tienda.stock_negativo', 'pedido', $ped, ['cola' => $id, 'negativo' => $f['negativo'] ?? '']);
        else bitacora($f['tipo'] === 'devolucion' ? 'tienda.stock_devuelto' : 'tienda.stock_descontado', 'pedido', $ped, ['cola' => $id]);
        return;
    }
    $c = una('SELECT * FROM stock_web_cola WHERE id = ?', [$id]);
    if (!$c || $c['resuelto_por'] === null) return;           // la hizo otra pasada: nada que decir
    if ($c['estado'] === 'cancelado') {
        q("UPDATE stock_web_cola SET estado = 'hecho', hecho_en = ?, revisar = 1, nota = ? WHERE id = ?",
          [$ahora, mb_substr('La tienda sí lo movió después de darlo por resuelto. ' . (string)$c['nota'], 0, 200), $id]);
        q("UPDATE stock_web_cola SET estado = 'pendiente', hecho_en = NULL, nota = NULL, resuelto_por = NULL, proximo_en = ?
            WHERE depende_de = ? AND estado = 'cancelado' AND resuelto_por IS NOT NULL", [$ahora, $id]);
        /* Y si la venta se anuló mientras tanto sin devolución (el descuento
           estaba cancelado), ahora sí hay que devolver. */
        if ($ped !== null && (string)(pedido_de($ped)['estado'] ?? '') === 'anulado') {
            stock_venta_devolver($ped);
        }
    } else {
        q('UPDATE stock_web_cola SET revisar = 1, nota = ? WHERE id = ?',
          [mb_substr('La tienda también lo movió después de hacerlo a mano: revisa el stock. ' . (string)$c['nota'], 0, 200), $id]);
    }
    bitacora('tienda.stock_tras_resolver', 'pedido', $ped, ['cola' => $id]);
}

/**
 * DE PASO: la cola se mueve también cuando alguien abre la lista de pedidos,
 * como mucho cada 2 minutos y pocas filas, por si el cron no está puesto.
 * Nunca tumba la pantalla desde la que se llama.
 */
function stock_cola_de_paso(): void
{
    try {
        if (!tabla_existe('stock_web_cola')) return;
        if (!valor("SELECT 1 FROM stock_web_cola WHERE estado = 'pendiente' AND proximo_en <= ?", [date('Y-m-d H:i:s')])) return;
        $ultima = (string) (valor("SELECT valor FROM ajustes WHERE clave = 'stock_cola_ultima'") ?? '');
        if ($ultima !== '' && strtotime($ultima) > time() - 120) return;
        guardar_ajuste_tecnico('stock_cola_ultima', date('Y-m-d H:i:s'));
        stock_cola_procesar(null, 3);
    } catch (Throwable $ex) {
        error_log('[HUB stock] cola de paso: ' . $ex->getMessage());
    }
}

/** Un ajuste interno, sin pasar por la bitácora (se escribe cada 2 minutos). */
function guardar_ajuste_tecnico(string $clave, string $valor,
                                string $descripcion = 'Cuándo se movió por última vez el stock pendiente. No tocar.'): void
{
    if (valor('SELECT 1 FROM ajustes WHERE clave = ?', [$clave])) {
        q('UPDATE ajustes SET valor = ? WHERE clave = ?', [$valor, $clave]);
    } else {
        q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
           VALUES (?, ?, 'texto', 'stock', ?, 1)", [$clave, $valor, $descripcion]);
    }
}

/* ─────────────────────────  LO QUE VE CADA UNO  ───────────────────────── */

/** Las filas que Administración tiene que mirar: atascadas o que dejaron la web en negativo. */
function stock_cola_atencion(): int
{
    if (!tabla_existe('stock_web_cola') || !tienda_es_de_mi_pais()) return 0;
    return (int) valor("SELECT COUNT(*) FROM stock_web_cola
                         WHERE (estado = 'pendiente' AND (intentos >= 3 OR creado_en <= ?)) OR revisar = 1",
                       [date('Y-m-d H:i:s', time() - 15 * 60)]);
    /* revisar = 1 en una pendiente: no se arregla sola (clave del conector,
       producto que ya no está). En una hecha: dejó la web en negativo, o la
       tienda lo movió después de darlo por resuelto. */
}

/**
 * LOS DESCUENTOS QUE DE VERDAD VAN A SALIR DE LA WEB: los pendientes de esta
 * tienda, sin los que llevan ya su devolución apuntada (una venta que se
 * anuló o que no llegó a guardarse: lo uno anula lo otro). Una sola
 * definición: la usan la comprobación con la tienda caída y la ficha.
 */
function stock_cola_por_salir(): array
{
    if (!tabla_existe('stock_web_cola')) return [];
    return todas("SELECT c.movimientos FROM stock_web_cola c
                   WHERE c.estado = 'pendiente' AND c.tipo = 'descuento'
                     AND (c.tienda = '' OR c.tienda = ?)
                     AND NOT EXISTS (SELECT 1 FROM stock_web_cola d WHERE d.depende_de = c.id AND d.estado <> 'cancelado')",
                 [tienda_config()['ruta']]);
}

/** Cuántas unidades de un producto esperan en la cola para descontarse de la web. */
function stock_cola_por_descontar(int $producto_id): int
{
    $n = 0;
    foreach (stock_cola_por_salir() as $q) {
        foreach (json_decode((string)$q['movimientos'], true) ?: [] as $m) {
            if ((int)($m['producto_id'] ?? 0) === $producto_id) $n += abs((int)$m['cantidad']);
        }
    }
    return $n;
}

/** Todas las pendientes, para la tarjeta de Configuración. */
function stock_cola_pendientes_n(): int
{
    if (!tabla_existe('stock_web_cola')) return 0;
    return (int) valor("SELECT COUNT(*) FROM stock_web_cola WHERE estado = 'pendiente'");
}

/**
 * CÓMO ESTÁ EL STOCK DE LA WEB DE UN PEDIDO, en palabras. null = no tocó la web.
 * → ['texto', 'tono' => 'verde'|'ambar'|'rojo'|'gris']
 */
function stock_venta_estado(int $pedido_id): ?array
{
    if (!tabla_existe('stock_web_cola')) return null;
    $d = una("SELECT * FROM stock_web_cola WHERE pedido_id = ? AND tipo = 'descuento'", [$pedido_id]);
    if (!$d) return null;
    $v = una("SELECT * FROM stock_web_cola WHERE pedido_id = ? AND tipo = 'devolucion'", [$pedido_id]);
    if ($v) {
        if ($v['estado'] === 'hecho')     return ['texto' => 'Stock devuelto a la web', 'tono' => 'gris'];
        if ($v['estado'] === 'cancelado') {
            return $d['estado'] === 'hecho'
                ? ['texto' => 'No se devolvió a la web: lo resolvió Administración', 'tono' => 'gris']
                : ['texto' => 'No se movió el stock de la web', 'tono' => 'gris'];
        }
        return ['texto' => 'Stock por devolver a la web: se reintenta solo', 'tono' => 'ambar'];
    }
    if ($d['estado'] === 'hecho') {
        return $d['negativo'] && (int)$d['forzado'] ? ['texto' => 'Descontado de la web, que ya no tenía: avisamos a Administración', 'tono' => 'rojo']
                              : ['texto' => 'Descontado del stock de la web', 'tono' => 'verde'];
    }
    if ($d['estado'] === 'cancelado') return ['texto' => 'No se movió el stock de la web', 'tono' => 'gris'];
    return ['texto' => 'Stock por descontar de la web: se reintenta solo', 'tono' => 'ambar'];
}

/**
 * EL ERROR DE LA COLA, para Administración: lo que dijo la tienda, o qué falta.
 */
function stock_cola_error_texto(array $f): string
{
    $e = trim((string)($f['ultimo_error'] ?? ''));
    if ($e !== '') return $e;
    if ($f['depende_de'] && (string) valor('SELECT estado FROM stock_web_cola WHERE id = ?', [(int)$f['depende_de']], '') === 'pendiente') {
        return 'Espera a que se descuente primero.';
    }
    return 'Todavía no se ha intentado.';
}

/** Se cambió la dirección de la tienda: lo que esperaba era de la otra. */
function stock_cola_otra_tienda(): void
{
    if (!tabla_existe('stock_web_cola')) return;
    q("UPDATE stock_web_cola SET estado = 'cancelado', revisar = 1, hecho_en = ?,
             nota = 'Se cambió la dirección de la tienda antes de moverlo: revisa este stock a mano.'
        WHERE estado = 'pendiente'", [date('Y-m-d H:i:s')]);
}

/** Administración ya vio que la web quedó en negativo. */
function stock_cola_visto(int $id): void
{
    q('UPDATE stock_web_cola SET revisar = 0 WHERE id = ?', [$id]);
}

/**
 * DAR POR RESUELTA una fila pendiente, a mano. Dos casos, y no dan lo mismo
 * (auditoría del 3e):
 *   · $a_mano = true: Administración lo hizo a mano en la web. Queda HECHA:
 *     si la venta se anula después, su devolución se hace sola.
 *   · $a_mano = false: no hace falta hacerlo. Queda cancelada, y si es un
 *     descuento, su devolución tampoco se hace (no se movió nada).
 */
function stock_cola_resolver(int $id, bool $a_mano, string $nota): array
{
    $nota = trim(mb_substr($nota, 0, 150));
    if ($nota === '') return ['ok' => false, 'error' => 'Escribe qué pasó: «lo corregí en la web», «era de prueba»…'];
    $f = una("SELECT * FROM stock_web_cola WHERE id = ? AND estado = 'pendiente'", [$id]);
    if (!$f) return ['ok' => false, 'error' => 'Ese movimiento ya no está pendiente.'];
    $u = yo();
    $quien = $u ? (int)$u['id'] : null;
    $ahora = date('Y-m-d H:i:s');
    $n = q("UPDATE stock_web_cola SET estado = ?, hecho_en = ?, nota = ?, resuelto_por = ?, revisar = 0, ultimo_error = NULL
             WHERE id = ? AND estado = 'pendiente'",
           [$a_mano ? 'hecho' : 'cancelado', $ahora, $nota, $quien, $id])->rowCount();
    if ($n !== 1) return ['ok' => false, 'error' => 'Ese movimiento ya no está pendiente.'];
    if (!$a_mano) {
        q("UPDATE stock_web_cola SET estado = 'cancelado', hecho_en = ?, nota = ?, resuelto_por = ?
            WHERE depende_de = ? AND estado = 'pendiente'",
          [$ahora, mb_substr('No se descontó: ' . $nota, 0, 200), $quien, $id]);
    }
    bitacora('tienda.stock_resuelto', 'pedido', $f['pedido_id'] !== null ? (int)$f['pedido_id'] : null,
             ['cola' => $id, 'a_mano' => $a_mano, 'nota' => $nota]);
    return ['ok' => true, 'error' => ''];
}
