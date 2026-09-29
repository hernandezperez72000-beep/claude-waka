<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * Configuración › Tienda (módulo 3b). La dirección de compraenwaka y las dos
 * claves de SOLO LECTURA de WooCommerce.
 *
 * La clave secreta es una llave, como el token de NUBEFACT: se guarda, pero no
 * se vuelve a enseñar entera —solo sus cuatro últimos caracteres—. Para
 * cambiarla se pega otra; dejar el campo vacío conserva la que había.
 */
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');

    if ($accion === 'guardar') {
        $ruta_txt = trim((string) pedir('ruta'));
        $key      = trim((string) pedir('key'));
        $secret   = trim((string) pedir('secret'));
        $ruta     = tienda_ruta_limpia($ruta_txt);

        if ($ruta_txt !== '' && $ruta === '') {
            $errores[] = 'La dirección empieza por https:// — por ejemplo https://compraenwaka.com';
        }
        if ($key !== '' && !tienda_clave_valida($key, 'ck')) {
            $errores[] = 'La clave del cliente empieza por ck_. Cópiala otra vez desde WooCommerce.';
        }
        if ($secret !== '' && !tienda_clave_valida($secret, 'cs')) {
            $errores[] = 'La clave secreta empieza por cs_. Cópiala otra vez desde WooCommerce.';
        }
        $antes = tienda_config();
        /* LA TIENDA ES DEL PAÍS QUE LA CONECTÓ. Otro país no la toca: bastaba
           con abrir esta pantalla y pulsar GUARDAR (la clave del cliente viene
           escrita) para quedarse con ella (verificación de los arreglos). */
        if (!tienda_es_de_mi_pais($antes)) {
            $errores[] = 'La tienda la conectó otro país: solo se cambia desde allí.';
        }
        /* CAMBIAR LA DIRECCIÓN PIDE LAS DOS CLAVES OTRA VEZ, siempre que haya
           una clave guardada —también si no había dirección, o si la de antes
           no valía—. Si no, bastaba con poner otra dirección y pulsar «Probar»
           para que la clave secreta viajara a esa otra web. */
        if ($ruta !== '' && $ruta !== $antes['ruta'] && $antes['secret'] !== '' && ($key === '' || $secret === '')) {
            $errores[] = 'Al cambiar la dirección, pega otra vez las dos claves.';
        }
        if (!$errores) {
            if ($ruta_txt !== '') guardar_ajuste('woo_url', $ruta);
            if ($key !== '')      guardar_ajuste('woo_key', $key);
            if ($secret !== '')   guardar_ajuste('woo_secret', $secret);
            /* Si cambió algo, la prueba de antes ya no dice nada de lo nuevo. */
            $cambio = ($ruta_txt !== '' && $ruta !== $antes['ruta']) || ($key !== '' && $key !== $antes['key'])
                   || ($secret !== '' && $secret !== $antes['secret']);
            if ($cambio) guardar_ajuste('woo_probada_en', '');
            /* OTRA DIRECCIÓN, OTRO CONECTOR: la clave del conector no viaja a
               una web distinta. Hay que generar y pegar una nueva. */
            if ($ruta_txt !== '' && $ruta !== $antes['ruta'] && conector_config()['clave'] !== '') {
                guardar_ajuste('conector_clave', '');
                guardar_ajuste('conector_probado_en', '');
            }
            /* OTRA TIENDA, OTRO STOCK (3e): el control se apaga —sin conector
               cada venta se quedaría esperando— y lo que esperaba en la cola
               era de la tienda de antes: no se manda a la nueva. Queda para
               que Administración lo revise. */
            if ($ruta_txt !== '' && $ruta !== $antes['ruta'] && $antes['ruta'] !== '') {
                if (control_stock()) guardar_ajuste('control_stock', '0');
                stock_cola_otra_tienda();
            }
            /* La tienda queda como de TU país cuando la conectas o la cambias, no
               por pulsar GUARDAR sin tocar nada. */
            if ($cambio || $antes['pais'] === 0) guardar_ajuste('woo_pais', (string) catalogo_pais());
            ajustes_olvidar();
            avisar('ok', 'Guardado.');
            ir('/configuracion/tienda');
        }
    }

    /* EL CONECTOR (3c): la clave que genera el plugin en WordPress. Como la
       secreta de WooCommerce: se pega, se guarda y no se vuelve a enseñar. */
    if ($accion === 'conector') {
        $clave = strtolower(trim((string) pedir('conector_clave')));
        if (!tienda_es_de_mi_pais()) {
            $errores[] = 'La tienda la conectó otro país: solo se cambia desde allí.';
        } elseif (tienda_config()['ruta'] === '') {
            $errores[] = 'Primero guarda la dirección de la tienda.';
        } elseif ($clave !== '' && !conector_clave_valida($clave)) {
            $errores[] = 'Esa no es la clave del conector. Cópiala otra vez desde WooCommerce › HUB Waka.';
        } elseif ($clave !== '') {
            guardar_ajuste('conector_clave', $clave);
            guardar_ajuste('conector_probado_en', '');
            ajustes_olvidar();
            $r = conector_probar();
            if ($r['ok']) { guardar_ajuste('conector_probado_en', date('Y-m-d H:i:s')); ajustes_olvidar(); }
            avisar($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Guardado. ' . $r['texto'] : 'Guardado, pero: ' . $r['texto']);
            ir('/configuracion/tienda');
        } else {
            ir('/configuracion/tienda');
        }
    }

    if ($accion === 'probar_conector') {
        $r = tienda_es_de_mi_pais() ? conector_probar() : ['ok' => false, 'texto' => 'La tienda la conectó otro país.'];
        guardar_ajuste('conector_probado_en', $r['ok'] ? date('Y-m-d H:i:s') : '');
        ajustes_olvidar();
        avisar($r['ok'] ? 'ok' : 'error', $r['texto']);
        ir('/configuracion/tienda');
    }

    /* EL CONTROL DE STOCK (3e). Encenderlo pide el conector funcionando: sin
       él, cada venta se quedaría esperando a descontarse. Apagarlo, siempre. */
    if ($accion === 'control') {
        $encender = pedir('valor') === '1';
        if (!tienda_es_de_mi_pais()) {
            $errores[] = 'La tienda la conectó otro país: solo se cambia desde allí.';
        } elseif ($encender && !conector_listo()) {
            $errores[] = 'Antes de encenderlo, pon la clave del conector y pruébalo.';
        } else {
            $r = $encender ? conector_probar() : ['ok' => true, 'texto' => ''];
            if (!$r['ok']) {
                $errores[] = 'No se encendió: ' . $r['texto'];
            } else {
                guardar_ajuste('control_stock', $encender ? '1' : '0');
                if ($encender) guardar_ajuste('conector_probado_en', date('Y-m-d H:i:s'));
                ajustes_olvidar();
                bitacora($encender ? 'tienda.control_on' : 'tienda.control_off', 'pais', catalogo_pais(), []);
                avisar('ok', $encender ? 'Control de stock encendido: las ventas descuentan de la web.'
                                       : 'Control de stock apagado: las ventas ya no tocan la web.');
                ir('/configuracion/tienda');
            }
        }
    }

    /* PRODUCTOS ESCRITOS A MANO (3e): mientras el catálogo se llena, el
       asesor puede escribir uno que no está. Esa línea no descuenta la web,
       así que el día que el catálogo esté completo se apaga desde aquí. */
    if ($accion === 'libre') {
        guardar_ajuste('catalogo_libre', pedir('valor') === '1' ? '1' : '0');
        ajustes_olvidar();
        avisar('ok', catalogo_libre() ? 'Se pueden vender productos escritos a mano.'
                                      : 'Solo se venden productos del catálogo.');
        ir('/configuracion/tienda');
    }

    /* Desde cuántas unidades el stock sale en ámbar, «quedan pocas» (3b.5). */
    if ($accion === 'poco') {
        $n = trim((string) pedir('stock_poco'));
        if (!tienda_es_de_mi_pais()) {
            $errores[] = 'La tienda la conectó otro país: solo se cambia desde allí.';
        } elseif (!preg_match('/^\d{1,4}$/', $n)) {
            $errores[] = 'Escribe un número de unidades, por ejemplo 3.';
        } else {
            guardar_ajuste('stock_poco', (string)(int)$n);
            ajustes_olvidar();
            avisar('ok', 'Guardado.');
            ir('/configuracion/tienda');
        }
    }

    if ($accion === 'probar') {
        $r = tienda_probar();
        avisar($r['ok'] ? 'ok' : 'error', $r['texto']);
        ir('/configuracion/tienda');
    }
}

$cfg = tienda_config();
$pais_tienda = $cfg['pais'] ? (string) valor('SELECT nombre FROM paises WHERE id = ?', [$cfg['pais']]) : '';
pagina('config/tienda', [
    'pais_tienda' => $pais_tienda,
    'otro_pais'   => !tienda_es_de_mi_pais($cfg),
    'cfg'      => $cfg,
    'ruta_vista' => (string) ajuste('woo_url', ''),
    'probada'  => (string) ajuste('woo_probada_en', ''),
    'errores'  => $errores,
    'stock_poco' => stock_web_poco(),
    'conector'   => conector_config(),
    'conector_probado' => (string) ajuste('conector_probado_en', ''),
    'puedo_importar' => puede('catalogo.gestionar'),
    'control'        => control_stock(),
    'libre'          => catalogo_libre(),
    'conector_listo' => conector_listo(),
    'cola_n'         => stock_cola_pendientes_n(),
    'puedo_cola'     => puede('tienda.cola'),
], ['titulo' => 'Tienda', 'migaja' => 'Configuración', 'sin_titulo' => true]);
