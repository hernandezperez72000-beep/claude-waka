<?php
declare(strict_types=1);
seccion_activa('garantias');

/**
 * UNA GARANTÍA (3i): lo que pidió el asesor (piezas, motivo, fotos) y, para
 * Administración, añadir o quitar piezas, aprobarla o no, y anularla si
 * todavía no salió. La regla vive en nucleo/garantias.php.
 */
$id = (int) (pedir_int('id', 'get') ?: pedir_int('id'));
$g = $id ? garantia_de($id) : null;
if (!$g) cortar(404, 'Esa garantía no existe');
exigir_ver((int)$g['asesor_id'], (int)$g['pais_id'], $g['asesor_equipo'] !== null ? (int)$g['asesor_equipo'] : null);
$resuelvo = garantia_puedo_resolver($g);
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir('garantias.aprobar');
    if (!$resuelvo) cortar(403, 'Esto no es para tu perfil');
    $accion = pedir('accion');
    $r = match ($accion) {
        'anadir'  => garantia_anadir_pieza($id, (int) pedir_int('producto_id'), (int) (pedir_int('cantidad') ?? 0)),
        'quitar'  => garantia_quitar_pieza($id, (int) pedir_int('linea_id')),
        'aprobar' => garantia_aprobar($id, (string) pedir('nota')),
        'denegar' => garantia_denegar($id, (string) pedir('nota')),
        'anular'  => garantia_anular($id, (string) pedir('nota')),
        default   => ['ok' => false, 'error' => 'No se entiende qué hacer.'],
    };
    if ($r['ok']) {
        avisar('ok', match ($accion) {
            'aprobar' => 'Garantía aprobada: pasa a «Por alistar».', 'denegar' => 'Listo: no se aprobó. El asesor lo ve con tu motivo.',
            'anular'  => 'Garantía anulada: las piezas vuelven al stock.', default => 'Guardado.',
        });
        if (($r['aviso'] ?? '') !== '') avisar('info', $r['aviso']);
        ir('/garantias/ver?id=' . $id);
    }
    $errores[] = $r['error'];
    $g = garantia_de($id);
}

$p = pedido_de((int)$g['pedido_id']);
$lineas = garantia_con_stock(garantia_lineas($id), (int)$g['pais_id']);
pagina('garantias/ver', [
    'g'        => $g,
    'p'        => $p,
    'vigencia' => $p ? pedido_garantia_vigencia($p) : null,
    'lineas'   => $lineas,
    'fotos'    => garantia_fotos($id),
    'resuelvo' => $resuelvo,
    'todos'    => $resuelvo && $g['estado'] === 'pedida' ? repuestos_todos() : [],
    'sugeridas'=> $resuelvo && $g['estado'] === 'pedida' ? pedido_garantia_piezas((int)$g['pedido_id'], (int)$g['pais_id']) : [],
    'errores'  => $errores,
], ['titulo' => garantia_codigo($id), 'sin_titulo' => true]);
