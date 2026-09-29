<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * CONFIGURACIÓN › METAS (módulo 5). La meta mensual general del país (hoy
 * S/ 30,000) y, por asesor, la excepción. Se mide por lo COBRADO. Cambiar la
 * general vale desde este mes; los meses pasados siguen con la suya.
 */
$u = yo();
$pais = (int)$u['pais_id'];
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');
    if ($accion === 'general') {
        $c = a_centimos((string) pedir('monto'));
        $r = $c === null ? ['ok' => false, 'error' => 'Escribe la meta en soles.'] : meta_general_poner($pais, $c);
        if ($r['ok']) { avisar('ok', 'La meta general queda en ' . soles_corto($c) . ' desde este mes.'); ir('/configuracion/metas'); }
        $errores[] = $r['error'];
    }
    if ($accion === 'asesor') {
        $id = (int) pedir_int('id');
        $as = bono_asesores($pais)[$id] ?? null;
        if (!$as) cortar(404, 'Ese asesor no está en tu país');
        $txt = trim((string) pedir('monto'));
        $c = $txt === '' ? null : a_centimos($txt);
        if ($txt !== '' && ($c === null || $c < 0 || !dinero_razonable($c))) $errores[] = 'La meta de ' . primer_nombre((string)$as['nombre']) . ' no se entiende.';
        else {
            actualizar('usuarios', $id, ['meta_mensual_centimos' => $c]);
            bitacora('meta.asesor', 'usuario', $id, ['centimos' => $c]);
            avisar('ok', $c === null ? primer_nombre((string)$as['nombre']) . ' vuelve a la meta general.' : 'Guardado.');
            ir('/configuracion/metas#meta-' . $id);
        }
    }
}
$mes = date('Y-m');
$filas = [];
foreach (bono_asesores($pais) as $a) {
    $x = una('SELECT id, pais_id, meta_mensual_centimos FROM usuarios WHERE id = ?', [(int)$a['id']]);
    $filas[] = $a + ['propia' => $x['meta_mensual_centimos'], 'meta' => meta_de($x, $mes), 'cobrado' => cobrado_del_mes((int)$a['id'], $mes)];
}
pagina('bonos/metas', ['general' => meta_general($pais, $mes), 'filas' => $filas, 'errores' => $errores],
       ['titulo' => 'Metas por asesor', 'subtitulo' => 'Lo cobrado en el mes, no lo vendido']);
