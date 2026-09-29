<?php
declare(strict_types=1);

/**
 * CAMBIAR LA WEB DESDE EL HUB (3c).
 *
 * Decidido por el usuario el 2026-09-25, en tarjetas:
 *   · se escribe con el plugin propio (conector.php), no con claves de
 *     escritura de WooCommerce;
 *   · el precio que va a la web es el de 1 UNIDAD (el primer tramo); los
 *     tramos por cantidad se quedan en el HUB;
 *   · con el conector puesto MANDA EL HUB en nombre, código y precio: al leer
 *     la tienda, lo que se cambió allá se enseña para decidir, no se aplica;
 *   · el stock se escribe poniendo LA CANTIDAD QUE HAY, con un motivo.
 *
 * Quién (3g): el STOCK lo pone quien tiene «stock.ajustar» (Almacén y
 * Administración); el NOMBRE, el CÓDIGO y el PRECIO, quien tiene
 * «tienda.datos» (Marketing y Administración).
 *
 * Todo o nada: si la web no acepta el cambio, aquí tampoco se guarda.
 */

/**
 * ¿Puede ESTA persona cambiar la web desde aquí?
 * $que = 'stock' (la cantidad) o 'datos' (nombre, código y precio). Sin valor
 * por defecto a propósito: cada sitio que escribe en la web dice qué escribe.
 */
function tienda_web_puede(string $que): bool
{
    $permiso = match ($que) { 'stock' => 'stock.ajustar', 'datos' => 'tienda.datos', default => '' };
    return $permiso !== '' && puede($permiso) && conector_listo();
}

/** Un precio en céntimos como lo escribe WooCommerce: «1299.90». */
function precio_para_web(int $centimos): string
{
    return number_format($centimos / 100, 2, '.', '');
}

/**
 * Lo que la web tiene que llevar de precio para este producto: el de 1 unidad
 * de cada color enlazado, o el del producto si no tiene colores.
 * → [['woo_id', 'variacion_id', 'precio' (céntimos)], …]
 */
function tienda_web_precios_de(int $producto_id): array
{
    $p = producto_de($producto_id);
    if (!$p || empty($p['woo_id'])) return [];
    $out = [];
    $vars = todas('SELECT id, woo_id FROM variantes WHERE producto_id = ? AND activo = 1 AND woo_id IS NOT NULL ORDER BY id', [$producto_id]);
    /* Uno con colores en la web lleva el precio en cada color: si aquí están
       todos apagados, no hay a dónde mandarlo (el producto no tiene precio). */
    if (!$vars && valor('SELECT 1 FROM variantes WHERE producto_id = ? AND woo_id IS NOT NULL', [$producto_id])) return [];
    if ($vars) {
        foreach ($vars as $v) {
            $c = precio_de_variante($producto_id, (int)$v['id'], 1);
            if ($c !== null) $out[] = ['woo_id' => (int)$p['woo_id'], 'variacion_id' => (int)$v['woo_id'], 'precio' => $c];
        }
    } else {
        $c = precio_de_variante($producto_id, null, 1);
        if ($c !== null) $out[] = ['woo_id' => (int)$p['woo_id'], 'variacion_id' => 0, 'precio' => $c];
    }
    return $out;
}

/**
 * MANDA A LA WEB el precio de 1 unidad. Un color por petición: si alguno
 * falla se dice cuál, y los demás quedan cambiados (la web no deja cambiar
 * varios de una vez).
 * → ['ok', 'error', 'cambiados']
 */
function tienda_web_mandar_precio(int $producto_id, ?array $antes = null): array
{
    /* Solo los que cambian, y TODOS EN UNA PETICIÓN: la web comprueba todos
       antes de cambiar ninguno. Uno por uno, si fallaba el quinto color, los
       cuatro primeros quedaban cambiados allá y aquí no (auditoría del 3c). */
    $ya = [];
    foreach ($antes ?? [] as $x) $ya[$x['woo_id'] . '-' . $x['variacion_id']] = $x['precio'];
    $cambios = [];
    foreach (tienda_web_precios_de($producto_id) as $x) {
        if ($antes !== null && ($ya[$x['woo_id'] . '-' . $x['variacion_id']] ?? null) === $x['precio']) continue;
        $cambios[] = ['woo_id' => $x['woo_id'], 'variacion_id' => $x['variacion_id'], 'precio' => precio_para_web($x['precio'])];
    }
    if (!$cambios) return ['ok' => true, 'error' => '', 'cambiados' => 0];
    $r = conector_llamar('POST', '/lote', ['cambios' => $cambios], 30);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'cambiados' => $r['ok'] ? count($cambios) : 0];
}

/**
 * MANDA A LA WEB el nombre y/o el código del producto (null = no se toca).
 * → ['ok', 'error', 'nombre', 'sku'] con lo que de verdad quedó allá: la web
 * limpia el nombre a su manera (espacios dobles, etiquetas) y aquí se guarda
 * lo mismo, o la próxima lectura diría que son distintos para siempre.
 */
function tienda_web_mandar_datos(int $woo_id, ?string $nombre, ?string $sku): array
{
    $d = ['woo_id' => $woo_id];
    if ($nombre !== null) $d['nombre'] = $nombre;
    if ($sku !== null) $d['sku'] = $sku;
    $r = conector_llamar('POST', '/producto', $d);
    $pr = (array)($r['datos']['producto'] ?? []);
    /* Leído con la MISMA función que al leer la tienda (WordPress guarda «&»
       como «&amp;»): si no, aquí quedaría «Mesa &amp; Silla». */
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'nombre' => isset($pr['nombre']) ? tienda_texto($pr['nombre'], 180) : ($nombre ?? ''),
            'sku' => (string)($pr['sku'] ?? ($sku ?? ''))];
}

/**
 * PONE EL STOCK en la web: la cantidad que hay de verdad, con su motivo. Se
 * apunta en la foto del stock (stock_web) con la hora de ahora y en la
 * bitácora con el antes, el después y quién.
 *
 * Un color que COMPARTE las unidades del producto no tiene stock propio: se
 * pone el del producto (si no, al escribirle uno se partiría del resto).
 * → ['ok', 'error']
 */
function tienda_web_poner_stock(int $producto_id, int $variante_id, int $cantidad, string $motivo): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!tienda_web_puede('stock')) return $mal('No puedes cambiar la tienda desde aquí.');
    $motivo = trim(mb_substr($motivo, 0, 120));
    if ($motivo === '') return $mal('Escribe el motivo: «conteo», «llegó mercadería», «dañados»…');
    if ($cantidad < 0 || $cantidad > 999999) return $mal('La cantidad va de 0 para arriba.');
    $p = producto_de($producto_id);
    if (!$p || empty($p['woo_id'])) return $mal('Ese producto no está enlazado con la tienda.');

    $var_woo = 0;
    if ($variante_id) {
        $v = una('SELECT id, woo_id FROM variantes WHERE id = ? AND producto_id = ?', [$variante_id, $producto_id]);
        if (!$v || empty($v['woo_id'])) return $mal('Ese color no está enlazado con la tienda.');
        $comp = tabla_existe('stock_web') ? (int) valor('SELECT compartido FROM stock_web WHERE producto_id = ? AND variante_id = ?',
                                                     [$producto_id, $variante_id], 0) : 0;
        if ($comp) $variante_id = 0; else $var_woo = (int)$v['woo_id'];
    }
    $antes = stock_web_de([$producto_id])[$producto_id] ?? null;
    $antes_n = $variante_id ? ($antes['variantes'][$variante_id]['cantidad'] ?? null) : ($antes['cantidad'] ?? null);

    $r = conector_llamar('POST', '/producto', ['woo_id' => (int)$p['woo_id'], 'variacion_id' => $var_woo, 'stock' => $cantidad]);
    if (!$r['ok']) return $mal($r['error']);

    $pr = (array)($r['datos']['producto'] ?? []);
    /* La web dice que ese color usa las unidades del producto: se apunta en el producto. */
    $vid_pedido = $variante_id;
    if (!empty($pr['en_padre'])) $variante_id = 0;
    if (tabla_existe('stock_web')) {
        $queda  = array_key_exists('stock', $pr) && $pr['stock'] !== null ? (int)$pr['stock'] : $cantidad;
        $estado = in_array($pr['estado'] ?? '', ['instock', 'outofstock', 'onbackorder'], true)
                ? (string)$pr['estado'] : ($queda > 0 ? 'instock' : 'outofstock');
        $ahora  = date('Y-m-d H:i:s');
        en_transaccion(function () use ($producto_id, $variante_id, $vid_pedido, $queda, $estado, $ahora) {
            if ($vid_pedido !== $variante_id) {
                /* El color, apuntado como que usa las unidades del producto. */
                q('DELETE FROM stock_web WHERE producto_id = ? AND variante_id = ?', [$producto_id, $vid_pedido]);
                insertar('stock_web', ['producto_id' => $producto_id, 'variante_id' => $vid_pedido, 'cantidad' => $queda,
                                       'estado' => $estado, 'compartido' => 1, 'leido_en' => $ahora]);
            }
            q('DELETE FROM stock_web WHERE producto_id = ? AND variante_id = ?', [$producto_id, $variante_id]);
            insertar('stock_web', ['producto_id' => $producto_id, 'variante_id' => $variante_id, 'cantidad' => $queda,
                                   'estado' => $estado, 'compartido' => 0, 'leido_en' => $ahora]);
            /* Los colores que comparten las unidades del producto dicen lo mismo que él. */
            if ($variante_id === 0) {
                q('UPDATE stock_web SET cantidad = ?, estado = ?, leido_en = ? WHERE producto_id = ? AND compartido = 1',
                  [$queda, $estado, $ahora, $producto_id]);
            }
        });
    }
    bitacora('tienda.stock', 'producto', $producto_id,
             ['variante' => $variante_id, 'antes' => $antes_n, 'ahora' => $cantidad, 'motivo' => $motivo]);
    return ['ok' => true, 'error' => ''];
}
