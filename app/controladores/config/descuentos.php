<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * Configuración › Descuentos (3d).
 *
 * Si el descuento pide aprobación (3h.1, apagado de fábrica) y dos cosas más:
 *   · el TOPE en %: hasta ahí la venta sale sola; por encima espera a que
 *     Administración lo apruebe (descuento_pasa_tope());
 *   · si en la misma venta se puede usar cashback Y hacer descuento. Ese
 *     ajuste existía desde el módulo 2 sin pantalla y sin que nadie lo
 *     mirara; ahora lo mira descuento_problema().
 */
$errores = [];
$tope = descuento_tope_pct();
$con_cb = descuento_con_cashback();
$pide = descuento_pide_aprobacion();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!puede('listas.gestionar')) cortar(403, 'Esto no es para tu perfil');
    $txt = trim(str_replace(['%', ' '], '', pedir('tope')));
    if ($txt === '' || !preg_match('/^\d{1,3}$/', $txt) || (int)$txt > 100) {
        $errores[] = 'El tope es un número entero del 0 al 100. Con 0, todo descuento pide aprobación.';
        $tope = $txt;
    } else {
        guardar_ajuste('descuento_tope_pct', (string)(int)$txt);
        guardar_ajuste('cashback_con_descuento', pedir('con_cashback') === '1' ? '1' : '0');
        guardar_ajuste('descuento_pide_aprobacion', pedir('pide') === '1' ? '1' : '0');
        ajustes_olvidar();
        avisar('ok', pedir('pide') === '1'
            ? 'Guardado. Los asesores pueden descontar hasta el ' . (int)$txt . ' % sin pedir aprobación.'
            : 'Guardado. Los descuentos no piden aprobación: quedan anotados con su motivo.');
        ir('/configuracion/descuentos');
    }
}

pagina('config/descuentos', [
    'tope' => $tope, 'con_cb' => $con_cb, 'pide' => $pide, 'errores' => $errores,
    'puedo_tocar' => puede('listas.gestionar'),
], ['titulo' => 'Descuentos', 'migaja' => 'Configuración', 'sin_titulo' => true]);
