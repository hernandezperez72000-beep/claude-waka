<?php
declare(strict_types=1);
seccion_activa('stock');

/**
 * UN PRODUCTO: sus datos, sus modelos o colores y sus tramos de precio, todo
 * en la misma pantalla. Se separó en tres al principio y era peor: dar de alta
 * un producto obligaba a pasar por tres pantallas para dejarlo vendible.
 */
$id = pedir_int('id', 'get') ?: pedir_int('id');
$p  = $id ? producto_de($id) : null;
if ($id && !$p) cortar(404, 'Ese producto no existe');

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion', 'post', 'producto');
    /* PONER PRECIOS ES SU PROPIO PERMISO. Estaba declarado desde el módulo 2 y
       no lo comprobaba nadie: quitarle a alguien «Poner los niveles de precio»
       no le quitaba nada. */
    exigir(match ($accion) { 'precios' => 'precios.editar', 'web_stock', 'hub_stock' => 'stock.ajustar', default => 'catalogo.gestionar' });

    /* REPUESTO DE (3i): el código de su máquina; vacío = no es repuesto. */
    if ($accion === 'repuesto' && $id) {
        $cod = trim((string) pedir('maquina'));
        $maq = $cod !== '' ? maquina_por_codigo($cod) : null;
        if ($cod !== '' && !$maq) {
            $errores[] = 'No hay ninguna máquina con el código ' . mb_strtoupper($cod) . '.';
        } else {
            $r = repuesto_colgar($id, $maq ? (int)$maq['id'] : null);
            if (!$r['ok']) $errores[] = $r['error'];
            else { avisar('ok', $maq ? 'Ahora es repuesto de ' . $maq['nombre'] . '.' : 'Ya no es un repuesto.'); ir('/stock/producto?id=' . $id); }
        }
    }

    /* EL STOCK DEL ALMACÉN de un repuesto que no está en la web (3i). */
    if ($accion === 'hub_stock' && $id) {
        $r = stock_hub_poner($id, pedir_int('cantidad') ?? -1, (string) pedir('motivo'));
        if (!$r['ok']) $errores[] = $r['error'];
        else { avisar('ok', 'Stock del almacén guardado.'); ir('/stock/producto?id=' . $id); }
    }

    /* EN LA TIENDA Y CON EL CONECTOR: MANDA EL HUB (3c). Cambiar aquí el
       nombre o el código de un producto enlazado lo cambia también en la web,
       y si la web no lo acepta, aquí tampoco se guarda. */
    $enlazado = $p && !empty($p['woo_id']) && conector_listo();

    if ($accion === 'producto' && $id && $enlazado) {
        $nombre_n = trim((string) pedir('nombre'));
        $sku_n    = mb_strtoupper(trim((string) pedir('sku')));
        if ($sku_n === '') $sku_n = (string)$p['sku'];
        $toca_web = $nombre_n !== (string)$p['nombre'] || $sku_n !== (string)$p['sku'];
        if ($toca_web) {
            try {
                if (!tienda_web_puede('datos')) {
                    throw new DomainException('Este producto está en la tienda: tu cuenta no cambia su nombre ni su código.');
                }
                if (!sku_valido($sku_n)) throw new DomainException('Ese código no vale: letras, números y guiones, de 3 a 60.');
                /* La web borra lo que va entre «<» y «>»: se avisa antes. */
                if (preg_match('/[<>]/', $nombre_n)) throw new DomainException('En la tienda el nombre no puede llevar «<» ni «>».');
                if (valor('SELECT id FROM productos WHERE sku = ? AND id <> ?', [$sku_n, $id])
                    || valor('SELECT id FROM variantes WHERE sku = ?', [$sku_n])) {
                    throw new DomainException('Ya hay otro producto con el código ' . $sku_n . '.');
                }
                /* Aquí primero (todo lo que se valida aquí) y la web después,
                   dentro de la misma transacción: si la web dice que no, lo de
                   aquí se deshace. */
                en_transaccion(function () use ($id, $p, $nombre_n, $sku_n) {
                    $r = producto_guardar($id, [
                        /* La categoría de uno de la web la manda la web (3f). */
                        'nombre' => $nombre_n, 'categoria' => (string)($p['categoria'] ?? ''),
                        'garantia_item_id' => pedir_int('garantia_item_id'),
                        'activo' => pedir('activo') !== '' ? 1 : 0,
                    ]);
                    if (!$r['ok']) throw new DomainException($r['error']);
                    if ($sku_n !== (string)$p['sku']) {
                        actualizar('productos', $id, ['sku' => $sku_n]);
                        bitacora('catalogo.codigo', 'producto', $id, ['antes' => $p['sku'], 'ahora' => $sku_n]);
                    }
                    $w = tienda_web_mandar_datos((int)$p['woo_id'],
                                                 $nombre_n !== (string)$p['nombre'] ? $nombre_n : null,
                                                 $sku_n !== (string)$p['sku'] ? $sku_n : null);
                    if (!$w['ok']) throw new DomainException($w['error']);
                    /* Aquí queda lo mismo que guardó la web. */
                    if ($nombre_n !== (string)$p['nombre'] && $w['nombre'] !== '' && $w['nombre'] !== $nombre_n) {
                        actualizar('productos', $id, ['nombre' => $w['nombre']]);
                        $GLOBALS['__nombre_web'] = $w['nombre'];
                    }
                    bitacora('tienda.datos', 'producto', $id, ['nombre' => [$p['nombre'], $w['nombre']], 'sku' => [$p['sku'], $sku_n]]);
                });
                avisar('ok', 'Producto guardado, también en la web.'
                    . (isset($GLOBALS['__nombre_web']) ? ' La web lo guardó como «' . $GLOBALS['__nombre_web'] . '».' : ''));
                ir('/stock/producto?id=' . $id);
            } catch (DomainException $ex) {
                $errores[] = $ex->getMessage();
                $accion = '';                    // no se guarda nada aquí
            } catch (Throwable $ex) {
                if (!es_choque_de_unico($ex)) throw $ex;
                $errores[] = 'Otro producto acaba de quedarse con ese código. Inténtalo otra vez.';
                $accion = '';
            }
        }
    }

    if ($accion === 'web_stock' && $id) {
        /* Una cantidad vacía o rara NO es cero: se rechaza (pedir_int da null). */
        $r = tienda_web_poner_stock($id, (int) pedir_int('variante_id'), pedir_int('cantidad') ?? -1, (string) pedir('motivo'));
        if (!$r['ok']) $errores[] = $r['error'];
        else { avisar('ok', 'Stock cambiado en la web.'); ir('/stock/producto?id=' . $id); }
    }

    if ($accion === 'producto') {
        $r = producto_guardar($id ?: null, [
            'nombre'           => pedir('nombre'),
            /* En uno de la web la categoría la manda la web: un POST a mano no
               la cambia (auditoría del 3f). */
            'categoria'        => $p && !empty($p['woo_id']) ? (string)($p['categoria'] ?? '') : pedir('categoria'),
            'sku'              => pedir('sku'),
            'garantia_item_id' => pedir_int('garantia_item_id'),
            'activo'           => pedir('activo') !== '' ? 1 : 0,
        ]);
        if (!$r['ok']) { $errores[] = $r['error']; $id = $id ?: 0; }
        else {
            avisar('ok', $id ? 'Producto guardado.' : 'Producto creado. Ponle su precio para poder venderlo.');
            ir('/stock/producto?id=' . $r['id']);
        }
    }

    /* LOS MODELOS Y COLORES SE CREAN EN LA WEB (usuario, 2026-09-26): «si se
       va a cambiar en la web, ¿para qué tener algo que no usaremos en el
       HUB?». Aquí ya no se añaden, en ningún producto: llegan al traer la
       tienda. Un color que solo existiera aquí no se podría descontar. */
    if ($accion === 'variante' && $id) {
        $errores[] = 'Los modelos y colores se crean en la web y llegan al pulsar «Traer de la tienda».';
        $accion = '';
    }

    if ($accion === 'variante_estado' && $id) {
        /* Que la variante sea DE ESTE producto, y que venga: sin esto, un POST
           a mano apagaba el color de otro producto —o reventaba sin id. */
        $vid = (int) pedir_int('variante_id');
        if ($vid && valor('SELECT id FROM variantes WHERE id = ? AND producto_id = ?', [$vid, $id])) {
            variante_encender($vid, pedir('encender') === '1');
        }
        ir('/stock/producto?id=' . $id);
    }

    if ($accion === 'precios' && $id) {
        $filas = [];
        $desde = (array)($_POST['t_desde'] ?? []);
        foreach ($desde as $i => $d) {
            $filas[] = ['desde' => $d, 'hasta' => $_POST['t_hasta'][$i] ?? '',
                        'precio' => $_POST['t_precio'][$i] ?? '', 'alias' => $_POST['t_alias'][$i] ?? ''];
        }
        /* EL PRECIO DE 1 UNIDAD VA A LA WEB (3c). Todo o nada: si la web no
           lo acepta, los tramos de aquí tampoco cambian. */
        $antes_web = $enlazado ? tienda_web_precios_de($id) : [];
        try {
            $r = en_transaccion(function () use ($id, $filas, $enlazado, $antes_web) {
                $r = precios_guardar($id, null, $filas);
                if (!$r['ok']) throw new DomainException($r['error']);
                if ($enlazado && tienda_web_precios_de($id) !== $antes_web) {
                    if (!tienda_web_puede('datos')) {
                        throw new DomainException('Este producto está en la tienda: tu cuenta no cambia su precio de 1 unidad.');
                    }
                    $w = tienda_web_mandar_precio($id, $antes_web);
                    if (!$w['ok']) throw new DomainException($w['error']);
                    $r['web'] = true;
                }
                return $r;
            });
            avisar('ok', !empty($r['web']) ? 'Precios guardados, también en la web.' : 'Precios guardados.');
            ir('/stock/producto?id=' . $id);
        } catch (DomainException $ex) {
            $errores[] = $ex->getMessage();
        }
    }
    $p = $id ? producto_de($id) : null;
}

pagina('catalogo/producto', [
    'p'           => $p,
    'id'          => $id,
    'variantes'   => $id ? producto_variantes($id, true) : [],
    'stock'       => $id ? (stock_web_de([$id])[$id] ?? null) : null,
    /* 3c: ¿se cambia la web desde aquí? Los datos y el stock por separado (3g). */
    'web'         => $p && !empty($p['woo_id']) && tienda_web_puede('datos'),
    'puedo_stock' => $p && !empty($p['woo_id']) && tienda_web_puede('stock'),
    'puedo_precios' => puede('precios.editar'),
    'tramos'      => $id ? producto_tramos($id, null) : [],
    'garantias'   => lista('garantias'),
    /* 3i: su máquina, sus repuestos y el stock del almacén del HUB. */
    'maquina'     => $p && repuestos_listo() ? repuesto_maquina($p) : null,
    'repuestos'   => $id && repuestos_listo() && empty($p['padre_id']) ? garantia_con_stock(repuestos_de([$id])) : [],
    'en_hub'      => $p && repuestos_listo() && stock_hub_aplica($p),
    'hub_hay'     => $p && repuestos_listo() && stock_hub_aplica($p) ? (stock_hub_de([$id])[$id] ?? 0) : 0,
    'hub_movs'    => $p && repuestos_listo() && stock_hub_aplica($p) ? stock_hub_movimientos($id, 8) : [],
    'puedo_hub'   => puede('stock.ajustar'),
    'errores'     => $errores,
    'puedo_tocar' => puede('catalogo.gestionar'),
], ['titulo' => $p ? (string)$p['nombre'] : 'Nuevo producto', 'sin_titulo' => true]);
