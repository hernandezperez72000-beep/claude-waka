<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * Configuración › Facturación electrónica (NUBEFACT). Parche 2u.
 *
 * Aquí Administración pega la RUTA y el TOKEN que le da NUBEFACT, y las series.
 * El token es una llave: se guarda, pero no se vuelve a enseñar entero — solo
 * sus cuatro últimos caracteres, para saber cuál está puesto. Para cambiarlo
 * se pega uno nuevo; dejar el campo vacío conserva el que había.
 *
 * Dos ambientes: PRUEBA (el de demostración de NUBEFACT, no va a SUNAT) y
 * PRODUCCIÓN. Se emite con el que esté marcado en uso.
 */
$errores = [];
$amb_edit = pedir('amb', 'post', '') ?: pedir('amb', 'get', nubefact_ambiente());
if (!isset(nubefact_ambientes()[$amb_edit])) $amb_edit = 'prueba';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!puede('listas.gestionar')) cortar(403, 'Esto no es para tu perfil');
    $accion = pedir('accion');

    if ($accion === 'usar') {
        $nuevo = pedir('ambiente');
        if (!isset(nubefact_ambientes()[$nuevo])) {
            $errores[] = 'Elige prueba o producción.';
        } elseif (!nubefact_config($nuevo)['listo']) {
            $errores[] = 'Antes de usar ' . mb_strtolower(nubefact_ambientes()[$nuevo])
                       . ', completa su ruta, su token y las series de boleta y factura.';
        } else {
            /* AL PASAR A PRODUCCIÓN, LAS BOLETAS DE PRUEBA DEJAN DE CONTAR. No
               fueron a SUNAT: la venta vuelve a quedar sin comprobante, para
               emitirle el de verdad. Solo se toca la constancia que coincide
               con una de prueba; una anotada a mano no. */
            if ($nuevo === 'produccion' && tabla_existe('comprobantes_electronicos')) {
                $n_prueba = 0;
                foreach (todas("SELECT pedido_id, serie, numero FROM comprobantes_electronicos
                                 WHERE ambiente = 'prueba' AND tipo_cpe IN (1, 2) AND estado = 'emitido'") as $cp) {
                    $n_prueba += q("UPDATE pedidos SET comprobante_tipo = NULL, comprobante_serie = NULL,
                                           comprobante_numero = NULL, comprobante_por = NULL, comprobante_en = NULL
                                     WHERE id = ? AND comprobante_serie = ? AND comprobante_numero = ?",
                                   [(int)$cp['pedido_id'], (string)$cp['serie'], (string)(int)$cp['numero']])->rowCount();
                }
                if ($n_prueba) bitacora('nubefact.produccion', 'ajuste', null, ['ventas_sin_boleta_de_prueba' => $n_prueba]);
                comprobantes_pendientes_olvidar();
            }
            guardar_ajuste('nubefact_ambiente', $nuevo);
            ajustes_olvidar();
            avisar('ok', 'Ahora se emite en ' . mb_strtolower(nubefact_ambientes()[$nuevo]) . '.');
            ir('/configuracion/facturacion?amb=' . $nuevo);
        }
    }

    if ($accion === 'guardar') {
        $pre = 'nubefact_' . $amb_edit . '_';
        $ruta  = trim((string) pedir('ruta'));
        $token = trim((string) pedir('token'));
        $series = [];
        foreach (['serie_boleta' => 'B', 'serie_factura' => 'F',
                  'serie_nc_boleta' => 'B', 'serie_nc_factura' => 'F'] as $campo => $ini) {
            $v = mb_strtoupper(trim((string) pedir($campo)));
            if ($v !== '' && !nubefact_serie_valida($v, $ini)) {
                $errores[] = 'La serie «' . $v . '» no vale: son 4 caracteres y empieza por ' . $ini . '.';
            }
            $series[$campo] = $v;
        }
        if ($ruta !== '' && !preg_match('#^https://\S+$#', $ruta)) {
            $errores[] = 'La ruta empieza por https:// y se copia entera desde tu cuenta de NUBEFACT.';
        }
        if ($token !== '' && (mb_strlen($token) < 20 || preg_match('/\s/', $token))) {
            $errores[] = 'Ese token no parece completo. Cópialo otra vez desde NUBEFACT.';
        }
        /* Los «próximos números»: solo hacia adelante. Bajar el siguiente de una
           serie ya usada volvería a emitir números que existen. */
        $siguientes = [];
        foreach (tabla_existe('nubefact_correlativos')
                 ? ['serie_boleta' => 2, 'serie_factura' => 1, 'serie_nc_boleta' => 3, 'serie_nc_factura' => 3] : []
                 as $campo => $tipo) {
            $s = $series[$campo];
            $n = (string) pedir('sig_' . $campo);
            if ($s === '' || $n === '') continue;
            if (!ctype_digit($n) || (int)$n < 1 || (int)$n > 99999999) {
                $errores[] = 'El próximo número de ' . $s . ' tiene que ser un número entre 1 y 99999999.';
                continue;
            }
            $siguientes[] = [$tipo, $s, (int)$n];
        }

        if (!$errores) {
            if ($ruta !== '')  guardar_ajuste($pre . 'ruta', $ruta);
            if ($token !== '') guardar_ajuste($pre . 'token', $token);
            foreach ($series as $campo => $v) guardar_ajuste($pre . $campo, $v);
            foreach ($siguientes as [$tipo, $s, $n]) {
                $fila = nubefact_correlativo($amb_edit, $tipo, $s);
                if ($n > (int)$fila['siguiente']) {
                    q('UPDATE nubefact_correlativos SET siguiente = ? WHERE id = ?', [$n, (int)$fila['id']]);
                }
            }
            ajustes_olvidar();
            avisar('ok', 'Guardado.');
            ir('/configuracion/facturacion?amb=' . $amb_edit);
        }
    }

    if ($accion === 'probar') {
        $r = nubefact_probar($amb_edit);
        avisar($r['ok'] ? 'ok' : 'error', $r['texto']);
        ir('/configuracion/facturacion?amb=' . $amb_edit);
    }
}

$cfg = nubefact_config($amb_edit);
$sig = [];
if (tabla_existe('nubefact_correlativos')) {
    foreach (['serie_boleta' => 2, 'serie_factura' => 1, 'serie_nc_boleta' => 3, 'serie_nc_factura' => 3]
             as $campo => $tipo) {
        $s = $cfg[$campo];
        $sig[$campo] = $s !== ''
            ? (int)(valor('SELECT siguiente FROM nubefact_correlativos WHERE ambiente = ? AND tipo_cpe = ? AND serie = ?',
                          [$amb_edit, $tipo, $s]) ?? 1)
            : 1;
    }
}

pagina('config/facturacion', [
    'cfg'         => $cfg,
    'amb_edit'    => $amb_edit,
    'en_uso'      => nubefact_ambiente(),
    'sig'         => $sig,
    'errores'     => $errores,
    'hay_tablas'  => tabla_existe('comprobantes_electronicos'),
    'puedo_tocar' => puede('listas.gestionar'),
], ['titulo' => 'Facturación electrónica', 'migaja' => 'Configuración', 'sin_titulo' => true]);
