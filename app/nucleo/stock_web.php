<?php
declare(strict_types=1);

/**
 * EL STOCK DE LA WEB (3b.5).
 *
 * La mercadería de entrega inmediata vive en compraenwaka: la cantidad que
 * vale es la de la web. Aquí se guarda una FOTO de esa cantidad —lo que dijo
 * la tienda la última vez que se leyó— y se enseña con su hora, para que nadie
 * confunda «hay 5» con «había 5 hace tres horas».
 *
 * Todavía no se descuenta nada al vender: escribir en la web llega con el
 * plugin propio (3c). Hasta entonces el stock solo se VE.
 *
 * Una fila por producto (variante_id = 0) y una por cada color o modelo.
 */

/**
 * Guarda el stock de lo leído de la tienda. Reemplaza TODA la foto de una vez
 * —la tienda es de un solo país—: lo que ya no está publicado deja de enseñar
 * una cantidad vieja. Solo se llama con una lectura completa (tienda_leer() no
 * devuelve lecturas a medias).
 *
 * `$leido_en` es CUÁNDO se leyó la tienda, no cuándo se guarda: al guardar una
 * lectura de las 9:00 a las 11:30, la foto es de las 9:00. Y si ya hay una
 * foto más nueva (el cron leyó a las 11:00), no se pisa con la vieja.
 * Una lectura de antes de la 3b.5 no trae stock: no se toca nada.
 *
 * Devuelve cuántos productos quedaron con stock.
 */
function stock_web_guardar(array $filas, int $pais_id, ?string $leido_en = null): int
{
    if (!tabla_existe('stock_web') || !$pais_id) return 0;
    if (!array_filter($filas, fn($f) => array_key_exists('stock', $f))) return 0;
    $leido_en ??= date('Y-m-d H:i:s');
    /* La hora de la última lectura COMPLETA, no la de la fila más nueva: una
       venta o un cambio de stock desde aquí apuntan su producto con la hora de
       ahora, y comparando con eso una lectura lenta se daba entera por vieja
       (auditoría del 3e). Sin marca (antes del 3e), la fila más nueva. */
    $actual = (string) (valor("SELECT valor FROM ajustes WHERE clave = 'stock_web_lectura'") ?? '');
    if ($actual === '') $actual = (string) valor('SELECT MAX(leido_en) FROM stock_web', [], '');
    /* Hay una foto más nueva: no se pisa. Solo se completan los productos
       que no tienen ninguna (los que se acaban de crear al guardar). */
    $solo_faltan = $actual !== '' && $actual > $leido_en;

    $prod = [];
    foreach (todas('SELECT id, woo_id FROM productos
                     WHERE woo_id IS NOT NULL AND (pais_id = ? OR pais_id IS NULL) ORDER BY id', [$pais_id]) as $p) {
        $prod[(int)$p['woo_id']][] = (int)$p['id'];
    }
    $var = [];
    if ($prod) {
        $ids = array_merge(...array_values($prod));
        $en = implode(',', array_fill(0, count($ids), '?'));
        foreach (todas("SELECT id, producto_id, woo_id FROM variantes WHERE woo_id IS NOT NULL AND producto_id IN ($en)", $ids) as $v) {
            $var[(int)$v['producto_id']][(int)$v['woo_id']] = (int)$v['id'];
        }
    }

    $filas_st = [];
    foreach ($filas as $f) {
        foreach ($prod[(int)($f['woo_id'] ?? 0)] ?? [] as $pid) {
            if (is_array($f['stock'] ?? null)) $filas_st[] = [$pid, 0, $f['stock']];
            foreach ($f['variaciones'] ?? [] as $fv) {
                $vid = $var[$pid][(int)($fv['woo_id'] ?? 0)] ?? 0;
                if ($vid && is_array($fv['stock'] ?? null)) $filas_st[] = [$pid, $vid, $fv['stock']];
            }
        }
    }

    $hechos = [];
    en_transaccion(function () use ($filas_st, $leido_en, $solo_faltan, &$hechos) {
        $ya = [];
        if ($solo_faltan) {
            foreach (todas('SELECT DISTINCT producto_id FROM stock_web') as $x) $ya[(int)$x['producto_id']] = true;
        } else {
            /* Las filas que una venta o un cambio desde la ficha apuntaron
               DESPUÉS de que empezara esta lectura dicen algo más nuevo: se
               quedan (verificación del 3e). El resto se reemplaza. */
            foreach (todas('SELECT producto_id, variante_id FROM stock_web WHERE leido_en > ?', [$leido_en]) as $x) {
                $hechos[(int)$x['producto_id'] . '-' . (int)$x['variante_id']] = (int)$x['producto_id'];
            }
            q('DELETE FROM stock_web WHERE leido_en <= ?', [$leido_en]);
        }
        foreach ($filas_st as [$pid, $vid, $st]) {
            if (isset($ya[$pid])) continue;                             // ese ya tiene una foto más nueva
            if (isset($hechos[$pid . '-' . $vid])) continue;          // el mismo producto, dos veces en la lectura
            insertar('stock_web', ['producto_id' => $pid, 'variante_id' => $vid,
                                   'cantidad' => $st['cantidad'], 'estado' => (string)$st['estado'],
                                   'compartido' => !empty($st['compartido']) ? 1 : 0, 'leido_en' => $leido_en]);
            $hechos[$pid . '-' . $vid] = $pid;
        }
        if (!$solo_faltan) {
            if (function_exists('guardar_ajuste_tecnico')) {
                guardar_ajuste_tecnico('stock_web_lectura', $leido_en, 'Cuándo se leyó entero el stock de la web. No tocar.');
            }
        }
    });
    return count(array_unique(array_values($hechos)));
}

/**
 * ACTUALIZAR EL STOCK: lee la tienda y guarda solo el stock. No crea ni cambia
 * productos: para eso está «Traer de la tienda».
 * → ['ok', 'error', 'productos' => cuántos quedaron con stock]
 */
function stock_web_actualizar(?int $pais = null): array
{
    /* Por cron no hay nadie dentro: se lee para el país de la tienda. */
    $pais ??= catalogo_pais();
    if (!$pais) return ['ok' => false, 'error' => 'No se sabe de qué país es.', 'productos' => 0];
    if (!tabla_existe('stock_web')) return ['ok' => false, 'error' => 'Falta terminar la actualización.', 'productos' => 0];
    /* La hora a la que EMPIEZA la lectura (verificación del 3e): una venta
       que se apunte mientras se leen las páginas de la tienda es más nueva
       que lo leído, y no se pisa. */
    $inicio = date('Y-m-d H:i:s');
    $r = tienda_leer($pais);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'productos' => 0];
    bd_despertar();
    try {
        $n = stock_web_guardar($r['filas'], $pais, $inicio);
    } catch (Throwable $ex) {
        error_log('[HUB stock web] ' . get_class($ex) . ': ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'No se pudo guardar el stock. Vuelve a intentarlo.', 'productos' => 0];
    }
    bitacora('catalogo.stock_web', 'pais', $pais, ['productos' => $n]);
    return ['ok' => true, 'error' => '', 'productos' => $n];
}

/**
 * EL STOCK DE VARIOS PRODUCTOS de una vez: [producto_id => resumen]. Una sola
 * definición de cuánto hay: la usan el catálogo, la ficha y el buscador del
 * pedido. El resumen:
 *   'cantidad'  → unidades (suma de los colores que cuentan), o null si la web
 *                 no cuenta unidades de este producto;
 *   'estado'    → 'instock' | 'outofstock' | 'onbackorder';
 *   'variantes' → [variante_id => ['cantidad', 'estado']];
 *   'leido_en'  → cuándo se leyó.
 * Un producto sin fila no sale: de ese no se sabe nada.
 */
function stock_web_de(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !tabla_existe('stock_web')) return [];
    /* El stock es el de la tienda, y la tienda es de un país: a quien mira
       desde otro no se le enseña (un producto común a los dos países salía con
       las unidades de la web del otro). */
    if (!tienda_es_de_mi_pais()) return [];
    $en = implode(',', array_fill(0, count($ids), '?'));
    $crudo = [];
    /* Solo los colores ENCENDIDOS: el total tiene que poder cuadrarse con lo
       que el asesor puede elegir (auditoría del 3b.5). */
    foreach (todas("SELECT sw.producto_id, sw.variante_id, sw.cantidad, sw.estado, sw.compartido, sw.leido_en
                      FROM stock_web sw
                      LEFT JOIN variantes v ON v.id = sw.variante_id
                     WHERE sw.producto_id IN ($en) AND (sw.variante_id = 0 OR v.activo = 1)
                     ORDER BY sw.producto_id, sw.variante_id", $ids) as $f) {
        $crudo[(int)$f['producto_id']][(int)$f['variante_id']] = $f;
    }
    $num = fn($f) => $f['cantidad'] === null ? null : max(0, (int)$f['cantidad']);
    $out = [];
    foreach ($crudo as $pid => $filas) {
        $vars = array_filter($filas, fn($k) => $k > 0, ARRAY_FILTER_USE_KEY);
        $leido = max(array_map(fn($f) => (string)$f['leido_en'], $filas));
        if ($vars) {
            /* Con colores: los que llevan sus propias unidades se suman; los
               que comparten las del producto cuentan UNA vez, con el número
               del producto. */
            $propios = array_filter($vars, fn($f) => (int)$f['compartido'] === 0);
            $comp    = array_filter($vars, fn($f) => (int)$f['compartido'] === 1);
            $partes = [];
            foreach ($propios as $f) if ($num($f) !== null) $partes[] = $num($f);
            if ($comp) {
                $c0 = $num(reset($comp));
                if ($c0 !== null) $partes[] = $c0;
            }
            $cant = $partes ? array_sum($partes) : null;
            $hay = array_filter($vars, fn($f) => stock_web_hay($num($f), (string)$f['estado']));
            $bajo = array_filter($vars, fn($f) => (string)$f['estado'] === 'onbackorder');
            $estado = $hay ? 'instock' : ($bajo ? 'onbackorder' : 'outofstock');
        } elseif (isset($filas[0])) {
            $cant = $num($filas[0]);
            $estado = stock_web_hay($cant, (string)$filas[0]['estado']) ? 'instock'
                    : ((string)$filas[0]['estado'] === 'onbackorder' ? 'onbackorder' : 'outofstock');
        } else {
            continue;
        }
        $out[$pid] = [
            'cantidad'  => $cant,
            'estado'    => $estado,
            'variantes' => array_map(fn($f) => ['cantidad' => $num($f), 'estado' => (string)$f['estado'],
                                                'leido_en' => (string)$f['leido_en']], $vars),
            'leido_en'  => $leido,
        ];
    }
    return $out;
}

/** ¿Hay para vender? Con cantidad, si pasa de cero; sin cantidad, lo que diga la web. */
function stock_web_hay(?int $cantidad, string $estado): bool
{
    if ($cantidad !== null) return $cantidad > 0;
    return $estado === 'instock';
}

/**
 * ¿AGOTADO para vender? (3e) Solo si se sabe: sin foto no se frena, y lo que la
 * web vende bajo pedido tampoco. La usa el buscador del pedido para no dejar
 * elegirlo; la venta la frena, además, la tienda al descontar.
 */
function stock_web_agotado(?array $s): bool
{
    if (!$s) return false;
    /* Una foto vieja no frena: puede que ya hayan repuesto. Frena la tienda
       al descontar. */
    $leido = (string)($s['leido_en'] ?? '');
    if ($leido !== '' && strtotime($leido) < time() - STOCK_WEB_HORAS * 3600) return false;
    $c = $s['cantidad'] ?? null;
    $e = (string)($s['estado'] ?? 'instock');
    return !stock_web_hay($c === null ? null : (int)$c, $e) && $e !== 'onbackorder';
}

/**
 * EL STOCK EN PALABRAS, para el asesor. Una sola forma de decirlo en todas
 * las pantallas: «12 en stock», «Agotado», «Hay stock», «Bajo pedido».
 * → ['texto', 'tono' => 'verde'|'ambar'|'rojo']
 */
function stock_web_texto(?array $s): array
{
    if (!$s) return ['texto' => '', 'tono' => ''];
    $c = $s['cantidad'] ?? null;
    $e = (string)($s['estado'] ?? 'instock');
    if (stock_web_hay($c === null ? null : (int)$c, $e)) {
        $r = $c === null ? ['texto' => 'Hay stock', 'tono' => 'verde']
                         : ['texto' => (int)$c . ' en stock', 'tono' => (int)$c <= stock_web_poco() ? 'ambar' : 'verde'];
    } else {
        $r = $e === 'onbackorder' ? ['texto' => 'Bajo pedido', 'tono' => 'ambar'] : ['texto' => 'Agotado', 'tono' => 'rojo'];
    }
    /* UNA CANTIDAD VIEJA SE DICE VIEJA: si hace más de STOCK_WEB_HORAS que no
       se lee, lleva su antigüedad y sale en gris. Si el cron se para, nadie
       vende con el stock de la semana pasada creyendo que es el de hoy. */
    $leido = (string)($s['leido_en'] ?? '');
    if ($leido !== '' && strtotime($leido) < time() - STOCK_WEB_HORAS * 3600) {
        $r = ['texto' => $r['texto'] . ' (' . hace($leido) . ')', 'tono' => 'gris'];
    }
    return $r;
}

/** Horas tras las que el stock leído de la web se enseña como viejo. */
const STOCK_WEB_HORAS = 6;

/** Desde cuántas unidades se avisa en ámbar de que queda poco. Se cambia en Configuración. */
function stock_web_poco(): int
{
    return max(0, (int) ajuste('stock_poco', 3));
}

/** Cuándo se leyó por última vez el stock de mi país, o '' si nunca. */
function stock_web_leido_en(int $pais_id): string
{
    if (!tabla_existe('stock_web')) return '';
    if (!tienda_es_de_mi_pais(null, $pais_id)) return '';
    return (string) valor('SELECT MAX(leido_en) FROM stock_web', [], '');
}
