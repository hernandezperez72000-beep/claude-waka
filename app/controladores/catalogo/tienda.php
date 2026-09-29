<?php
declare(strict_types=1);
seccion_activa('stock');

/**
 * Stock y pre venta › Traer de la tienda (módulo 3b).
 *
 * Dos pasos, y nada se guarda en el primero:
 *   1. LEER LA TIENDA: se trae el catálogo publicado y se enseña qué pasaría
 *      —cuántos productos se crean, cuántos se actualizan, cuáles no entran y
 *      por qué—.
 *   2. GUARDAR: se guarda exactamente eso. Si el catálogo de aquí cambió entre
 *      medias, se niega y pide leer otra vez.
 */
$pais = catalogo_pais();
$falta_actualizar = !tabla_existe('tienda_lecturas');

/* La pantalla pide las fotos con fetch: sin la actualización, que lo diga
   en su idioma y no con una página entera que el fetch no sabe leer. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $falta_actualizar
    && str_starts_with(trim((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
    json(['ok' => false, 'error' => 'Falta terminar la actualización.', 'traidas' => 0, 'malas' => 0, 'quedan' => 0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$falta_actualizar) {
    $accion = pedir('accion');

    if ($accion === 'leer') {
        try {
            $r = tienda_leer();
        } catch (Throwable $ex) {
            error_log('[HUB tienda] ' . get_class($ex) . ': ' . $ex->getMessage() . ' en ' . $ex->getFile() . ':' . $ex->getLine());
            $r = ['ok' => false, 'error' => 'No se pudo leer la tienda. Vuelve a intentarlo en un momento.'];
        }
        if (!$r['ok']) {
            avisar('error', $r['error']);
            ir('/stock/tienda');
        }
        try {
            /* Leer la tienda lleva su rato: la base de datos puede haber colgado. */
            bd_despertar();
            $id = tienda_lectura_nueva($r['filas'], $pais);
        } catch (Throwable $ex) {
            error_log('[HUB tienda] ' . get_class($ex) . ': ' . $ex->getMessage());
            avisar('error', 'No se pudo apuntar lo leído de la tienda. Vuelve a intentarlo en un momento.');
            ir('/stock/tienda');
        }
        ir('/stock/tienda?l=' . $id);
    }

    /* LAS FOTOS QUE FALTAN, de 40 en 40, sin volver a leer (3b.5). La
       pantalla lo pide una y otra vez por detrás y enseña cuántas van; sin
       JavaScript, es un botón normal que trae una tanda y vuelve. */
    if ($accion === 'fotos') {
        try {
            $r = tienda_traer_fotos($pais);
        } catch (Throwable $ex) {
            error_log('[HUB tienda fotos] ' . get_class($ex) . ': ' . $ex->getMessage());
            $r = ['ok' => false, 'error' => 'No se pudieron traer las fotos. Vuelve a intentarlo.',
                  'traidas' => 0, 'malas' => 0, 'quedan' => 0];
        }
        /* La pantalla lo pide con fetch y «Accept: application/json»; un
           formulario normal (sin JavaScript) pide HTML y vuelve con un aviso. */
        if (str_starts_with(trim((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) json($r);
        if (!$r['ok']) avisar('error', $r['error']);
        else avisar('ok', $r['traidas'] + $r['malas'] === 0 && $r['quedan'] === 0
            ? 'Ya están todas las fotos.'
            : plural((int)$r['traidas'], 'foto nueva', 'fotos nuevas')
              . ($r['malas'] ? ' (' . $r['malas'] . ' no ' . ($r['malas'] === 1 ? 'sirve' : 'sirven') . ')' : '')
              . ($r['quedan'] ? ' · faltan ' . $r['quedan'] : '') . '.');
        ir('/stock/tienda');
    }

    /* LO QUE LA WEB DICE DISTINTO (3c): manda el HUB, pero se decide aquí,
       producto a producto. «Usar el de la web» trae el nombre o el código de
       allá; «Corregir la web» manda allá lo de aquí. */
    if ($accion === 'usar_web' || $accion === 'corregir_web') {
        $lid = (int) pedir_int('lectura');
        $woo = (int) pedir_int('woo');
        $l   = $lid ? tienda_lectura($lid) : null;
        $item = null;
        $limpia = false;
        if ($l && $l['estado'] === 'leida') {
            $plan_ahora = tienda_plan($l['filas'], $pais);
            $limpia = $plan_ahora['resumen'] == $l['resumen'];
            foreach ($plan_ahora['productos'] as $x) {
                if ((int)$x['fila']['woo_id'] === $woo && ($x['difiere'] ?? []) && $x['id']) { $item = $x; break; }
            }
        }
        if (!$item) {
            avisar('error', 'Esa diferencia ya no está. Vuelve a leer la tienda.');
            ir('/stock/tienda' . ($lid ? '?l=' . $lid : ''));
        }
        $pid = (int)$item['id'];
        $d = $item['difiere'];
        $fila_web = null;
        foreach ($l['filas'] as $f0) if ((int)$f0['woo_id'] === $woo) { $fila_web = $f0; break; }

        if ($accion === 'usar_web') {
            /* Traer lo de la web a un producto de la tienda es cambiarlo: lo
               hace quien cambia la tienda. Y con una lectura de ANTES del
               último cambio hecho aquí, no: devolvería el nombre viejo. */
            if (!tienda_web_puede('datos')) { avisar('error', 'No puedes cambiar productos de la tienda desde aquí.'); ir('/stock/tienda?l=' . $lid); }
            if (valor("SELECT 1 FROM bitacora WHERE entidad = 'producto' AND entidad_id = ? AND creado_en > ?
                          AND accion IN ('catalogo.editar', 'catalogo.codigo', 'tienda.datos', 'tienda.corregir', 'catalogo.usar_web')",
                      [$pid, (string)$l['creado_en']])) {
                avisar('error', 'Este producto cambió aquí después de leer la tienda. Vuelve a leerla.');
                ir('/stock/tienda?l=' . $lid);
            }
            $cambio = [];
            if (isset($d['nombre'])) $cambio['nombre'] = (string)($fila_web['nombre'] ?? '');
            if (isset($d['codigo'])) {
                $sku_web = (string)($fila_web['sku'] ?? '');
                if (!sku_valido($sku_web)) { avisar('error', 'El código de la web no vale aquí: ' . $sku_web . '.'); ir('/stock/tienda?l=' . $lid); }
                if (valor('SELECT id FROM productos WHERE sku = ? AND id <> ?', [$sku_web, $pid])
                    || valor('SELECT id FROM variantes WHERE sku = ?', [$sku_web])) {
                    avisar('error', 'El código de la web (' . $sku_web . ') ya lo tiene otro producto aquí.');
                    ir('/stock/tienda?l=' . $lid);
                }
                $cambio['sku'] = $sku_web;
            }
            if (!$cambio) { avisar('error', 'El precio se cambia aquí, en los tramos del producto.'); ir('/stock/tienda?l=' . $lid); }
            $antes_p = producto_de($pid);
            actualizar('productos', $pid, $cambio);
            bitacora('catalogo.usar_web', 'producto', $pid, ['antes' => array_intersect_key((array)$antes_p, $cambio), 'ahora' => $cambio]);
            tienda_lectura_retocar($lid, $pais, $limpia);
            avisar('ok', 'Listo: aquí queda como en la web.');
            ir('/stock/tienda?l=' . $lid);
        }

        /* Corregir la web: manda lo de aquí. */
        if (!tienda_web_puede('datos')) { avisar('error', 'No puedes cambiar la tienda desde aquí.'); ir('/stock/tienda?l=' . $lid); }
        $p = producto_de($pid);
        if (isset($d['nombre']) || isset($d['codigo'])) {
            $r = tienda_web_mandar_datos($woo, isset($d['nombre']) ? (string)$p['nombre'] : null,
                                         isset($d['codigo']) ? (string)$p['sku'] : null);
            if (!$r['ok']) { avisar('error', $r['error']); ir('/stock/tienda?l=' . $lid); }
            /* Lo que quedó de verdad allá, también aquí. */
            if (isset($d['nombre']) && $r['nombre'] !== '' && $r['nombre'] !== (string)$p['nombre']) {
                actualizar('productos', $pid, ['nombre' => $r['nombre']]);
                $p['nombre'] = $r['nombre'];
            }
        }
        if (isset($d['precio'])) {
            $r = tienda_web_mandar_precio($pid);
            if (!$r['ok']) { avisar('error', $r['error']); ir('/stock/tienda?l=' . $lid); }
        }
        bitacora('tienda.corregir', 'producto', $pid, ['que' => array_keys($d)]);
        /* La lectura dice ahora lo que quedó en la web. */
        $precios_web = [];
        foreach (tienda_web_precios_de($pid) as $x) $precios_web[(int)$x['variacion_id']] = (int)$x['precio'];
        tienda_lectura_retocar($lid, $pais, $limpia, function (array $filas) use ($woo, $p, $precios_web) {
            foreach ($filas as &$f) {
                if ((int)$f['woo_id'] !== $woo) continue;
                $f['nombre'] = (string)$p['nombre'];
                $f['sku'] = (string)$p['sku'];
                $f['sku_crudo'] = (string)$p['sku'];
                if (isset($precios_web[0])) $f['precio'] = $precios_web[0];
                foreach ($f['variaciones'] as &$v) {
                    if (isset($precios_web[(int)$v['woo_id']])) $v['precio'] = $precios_web[(int)$v['woo_id']];
                }
                unset($v);
            }
            unset($f);
            return $filas;
        });
        avisar('ok', 'Listo: la web ya dice lo mismo que aquí.');
        ir('/stock/tienda?l=' . $lid);
    }

    if ($accion === 'guardar') {
        $id = (int) pedir_int('lectura');
        try {
            $r = tienda_importar($id);
        } catch (Throwable $ex) {
            error_log('[HUB tienda] ' . get_class($ex) . ': ' . $ex->getMessage() . ' en ' . $ex->getFile() . ':' . $ex->getLine());
            $r = ['ok' => false, 'error' => 'No se pudo guardar. Vuelve a leer la tienda y guarda otra vez.', 'resumen' => []];
        }
        if (!$r['ok']) {
            avisar('error', $r['error']);
            ir('/stock/tienda' . ($id ? '?l=' . $id : ''));
        }
        $s = $r['resumen'];
        $partes = [];
        if ($s['crear'])      $partes[] = plural((int)$s['crear'], 'producto nuevo', 'productos nuevos');
        if ($s['actualizar']) $partes[] = plural((int)$s['actualizar'], 'actualizado', 'actualizados');
        if ($s['var_crear'])  $partes[] = plural((int)$s['var_crear'], 'color o modelo nuevo', 'colores o modelos nuevos');
        if ($s['precio_web'] ?? 0) $partes[] = plural((int)$s['precio_web'], 'con el precio de la web', 'con el precio de la web');
        if ($s['sin_precio'] ?? 0) $partes[] = plural((int)$s['sin_precio'], 'nuevo sin precio', 'nuevos sin precio');
        if ($s['fotos'] ?? 0) {
            $ok_f   = (int)($s['fotos_ok'] ?? 0);
            $malas_f = (int)($s['fotos_malas'] ?? 0);
            $faltan = (int)$s['fotos'] - $ok_f - $malas_f;
            $partes[] = plural($ok_f, 'foto', 'fotos')
                      . ($malas_f ? ' (' . $malas_f . ' no ' . ($malas_f === 1 ? 'sirve' : 'sirven') . ': revísalas en la tienda)' : '')
                      . ($faltan > 0 ? ' — faltan ' . $faltan . ': tráelas con «Traer las fotos que faltan» en Traer de la tienda' : '');
        }
        avisar('ok', $partes ? 'Guardado en el catálogo: ' . implode(', ', $partes) . '.'
                             : 'El catálogo ya estaba al día.');
        /* A la lista de los que no tienen precio solo si quedó alguno así: los
           que traen el de la web ya se pueden vender. */
        ir(($s['sin_precio'] ?? 0) ? '/stock?v=sin_precio' : '/stock');
    }
}

$lectura = null;
$plan    = null;
if (!$falta_actualizar && ($lid = pedir_int('l', 'get'))) {
    $lectura = tienda_lectura($lid);
    if ($lectura) {
        /* Se enseña el plan recalculado ahora. Si ya no coincide con el que se
           apuntó al leer, el guardado lo va a negar: se avisa desde ya. */
        $plan = tienda_plan($lectura['filas'], $pais);
    }
}

/* Cuántas fotos faltan por traer, sin leer la tienda: solo cuando no se está
   enseñando una lectura (ahí la vista previa ya las cuenta). */
$fotos_faltan = (!$falta_actualizar && !$lectura && tienda_es_de_mi_pais()) ? count(tienda_fotos_pendientes($pais)) : 0;

pagina('catalogo/tienda', [
    'fotos_faltan' => $fotos_faltan,
    'cfg'       => tienda_config(),
    'otro_pais' => !tienda_es_de_mi_pais(),
    'lectura'   => $lectura,
    'plan'      => $plan,
    'cambio'    => $lectura && $plan && $plan['resumen'] != $lectura['resumen'],
    'falta_actualizar' => $falta_actualizar,
    'puedo_config' => puede('listas.gestionar'),
], ['titulo' => 'Traer de la tienda', 'migaja' => 'Stock y pre venta', 'sin_titulo' => true]);
