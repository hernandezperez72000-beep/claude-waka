<?php
declare(strict_types=1);
seccion_activa('pedidos');

/**
 * PEDIR UNA GARANTÍA desde el pedido original (3i): qué pieza, por qué y la
 * foto de la falla (una o varias). La aprueba Administración. Vencida también
 * se puede pedir: se dice, y decide Administración.
 */
if (!garantias_listo()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Pedir garantía', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$pid = (int) (pedir_int('pedido', 'get') ?: pedir_int('pedido'));
$p = $pid ? pedido_de($pid) : null;
if (!$p) cortar(404, 'Ese pedido no existe');
$equipo_asesor = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
exigir_ver((int)$p['asesor_id'], (int)$p['pais_id'], $equipo_asesor !== null ? (int)$equipo_asesor : null);
if (($no = garantia_se_puede_pedir($p)) !== '') cortar(409, 'No se puede pedir garantía', $no);

$ctx = 'garantia-' . $pid;
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $piezas = [];
    foreach ((array)($_POST['pieza'] ?? []) as $k => $n) {
        if (preg_match('/^\d+$/', (string)$k) && preg_match('/^\d{1,3}$/', trim((string)$n))) $piezas[(int)$k] = (int)$n;
    }
    $fotos = [];
    for ($k = 1; $k <= GARANTIA_FOTOS_MAX; $k++) {
        $f = voucher_del_formulario($ctx, 'falla_' . $k);
        if (!$f['ok']) { $errores[] = 'Foto ' . $k . ': ' . $f['error']; continue; }
        if ($f['archivo']) $fotos[] = $f['archivo'];
    }
    if (!$errores) {
        $r = garantia_pedir($pid, $piezas, (string) pedir('motivo'), $fotos, pedir('sin_pieza') === '1');
        if ($r['ok']) {
            for ($k = 1; $k <= GARANTIA_FOTOS_MAX; $k++) voucher_pendiente_confirmar(voucher_contexto($ctx, 'falla_' . $k));
            avisar('ok', 'Garantía ' . garantia_codigo((int)$r['id']) . ' pedida. Administración la revisa.');
            ir('/garantias/ver?id=' . (int)$r['id']);
        }
        $errores[] = $r['error'];
    }
}

$pend = [];
for ($k = 1; $k <= GARANTIA_FOTOS_MAX; $k++) $pend[$k] = voucher_pendiente(voucher_contexto($ctx, 'falla_' . $k));

pagina('garantias/pedir', [
    'p'        => $p,
    'vigencia' => pedido_garantia_vigencia($p),
    'piezas'   => pedido_garantia_piezas($pid, (int)$p['pais_id']),
    'lineas'   => pedido_lineas($pid),
    'antes'    => garantias_del_pedido($pid),
    'errores'  => $errores,
    'pend'     => $pend,
], ['titulo' => 'Pedir garantía', 'sin_titulo' => true]);
