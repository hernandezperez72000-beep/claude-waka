<?php
declare(strict_types=1);
seccion_activa(seccion_de_lotes());

/**
 * UN LOTE DE PRE VENTA (3h): el viaje, los productos, sus precios, el
 * interruptor de venta y el estado de la carga, en una pantalla.
 * «Lo principal es el formulario» (usuario, 2026-09-27): el Excel es un plus
 * para lo pasado (/stock/lote/excel).
 */
if (!preventa_lista()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Lote de pre venta', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$id = (int) (pedir_int('id', 'get') ?: pedir_int('id'));
$lote = $id ? lote_de($id) : null;
if ($id && !$lote) cortar(404, 'Ese lote no existe');
$nuevo = !$lote;
if ($nuevo && !puede('lotes.gestionar')) cortar(403, 'Esto no es para tu perfil');
$errores = [];
$abrir = '';
$post_filas = null;     // lo escrito, si el guardado de los productos rebotó

/* DESPUÉS DE CADA ACCIÓN, AL SIGUIENTE PASO (3j): la pantalla vuelve
   mirando el cuadro que toca, que además se ve resaltado. */
$al_siguiente = function (int $lid): string {
    $a = lote_siguiente_paso(lote_de($lid))['ancla'];
    return '/stock/lote?id=' . $lid . ($a !== '' ? '#' . $a : '');
};
/* LOS PRECIOS SE GUARDAN SIN RECARGAR (3j): la pantalla los manda por detrás
   y recibe esto. Guardar uno no reinicia la página ni lo demás escrito. */
$ajax = pedir('ajax') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir('lotes.gestionar');
    $accion = pedir('accion');
    if ($accion === 'viaje') {
        $r = lote_guardar($lote ? $id : null, $_POST);
        if ($r['ok']) {
            avisar('ok', $lote ? 'Guardado.' : 'Lote creado. Ahora pon sus productos.');
            ir($al_siguiente((int)$r['id']));
        }
        $errores[] = $r['error'];
        $abrir = 'viaje';
    }
    if ($lote && $accion === 'filas') {
        $filas = [];
        foreach ((array)($_POST['f_nombre'] ?? []) as $i => $x) {
            $filas[] = ['id' => $_POST['f_id'][$i] ?? 0, 'codigo' => $_POST['f_codigo'][$i] ?? '', 'nombre' => $x,
                        'modelo' => $_POST['f_modelo'][$i] ?? '', 'unidades' => $_POST['f_unidades'][$i] ?? '',
                        'maquina' => $_POST['f_maquina'][$i] ?? '',
                        'nuevo' => in_array((string)$i, array_map('strval', (array)($_POST['f_nuevo'] ?? [])), true),
                        'revisado' => in_array((string)$i, array_map('strval', (array)($_POST['f_ok'] ?? [])), true)];
        }
        $r = lote_filas_guardar($id, $filas);
        if ($r['ok']) {
            avisar('ok', 'Productos guardados.' . ($r['avisos'] ? ' ' . implode(' ', $r['avisos']) : ''));
            ir($al_siguiente($id));
        }
        $errores[] = $r['error'];
        $abrir = 'productos';
        /* Lo escrito no se pierde: se vuelve a enseñar tal cual. */
        $de_bd = [];
        foreach (lote_filas($id) as $fb) $de_bd[(int)$fb['id']] = $fb;
        $post_filas = [];
        foreach ($filas as $fp) {
            $b = $de_bd[(int)$fp['id']] ?? null;
            /* Una fila nueva en blanco no se repinta: volvía con «0» unidades y
               el siguiente guardado decía «Fila 6: falta el producto». */
            if (!$b && trim((string)$fp['codigo']) === '' && trim((string)$fp['nombre']) === ''
                && trim((string)$fp['modelo']) === '' && in_array(trim((string)$fp['unidades']), ['', '0'], true)) continue;
            $post_filas[] = ['id' => (int)$fp['id'], 'codigo' => (string)$fp['codigo'], 'producto_nombre' => (string)$fp['nombre'],
                             'modelo' => (string)$fp['modelo'], 'unidades' => (string)$fp['unidades'], 'nuevo' => $fp['nuevo'] ? 1 : 0,
                             'maquina' => (string)$fp['maquina'], 'maquina_nombre' => $b['maquina_nombre'] ?? '', 'es_repuesto' => trim((string)$fp['maquina']) !== '',
                             'vendidas' => $b ? (int)$b['vendidas'] : 0, 'quedan' => $b ? (int)$b['quedan'] : 1,
                             'resuelto' => $b ? (int)$b['resuelto'] : 1, 'aviso' => $b['aviso'] ?? null, 'texto_origen' => $b['texto_origen'] ?? null,
                             'producto_sku' => ''];
        }
    }
    if ($lote && $accion === 'precios') {
        $pid = (int) pedir_int('producto_id');
        if (!valor('SELECT 1 FROM lote_lineas WHERE lote_id = ? AND producto_id = ?', [$id, $pid])) cortar(404, 'Ese producto no está en el lote');
        $filas = [];
        foreach ((array)($_POST['t_desde'] ?? []) as $i => $dd) {
            $filas[] = ['desde' => $dd, 'hasta' => $_POST['t_hasta'][$i] ?? '', 'precio' => $_POST['t_precio'][$i] ?? '', 'alias' => $_POST['t_alias'][$i] ?? ''];
        }
        $r = precios_guardar($pid, null, $filas, $id);
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            $sig = lote_siguiente_paso(lote_de($id));
            $c = lote_completitud($id);
            echo json_encode(['ok' => $r['ok'], 'error' => $r['ok'] ? '' : $r['error'], 'producto' => $pid,
                              'sin' => (int)$c['sin'], 'con' => (int)$c['con'],
                              'siguiente' => $sig], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($r['ok']) { avisar('ok', 'Precios guardados.'); ir($al_siguiente($id)); }
        $errores[] = $r['error'];
        $abrir = 'precio-' . $pid;
    }
    if ($lote && $accion === 'interruptor') {
        $r = lote_interruptor($id, pedir('encender') === '1');
        if ($r['ok']) {
            avisar('ok', pedir('encender') === '1' ? 'Lote a la venta.' : 'Lote apagado: los asesores ya no lo ven.');
            if ($r['aviso'] !== '') avisar('info', $r['aviso']);
            ir($al_siguiente($id));
        }
        $errores[] = $r['error'];
        $abrir = 'form-interruptor';
    }
    if ($lote && $accion === 'estado') {
        $r = lote_estado_cambiar($id, (string) pedir('estado'));
        if ($r['ok']) {
            avisar('ok', 'Estado de la carga: ' . (lote_estados()[(string) pedir('estado')] ?? '') . '.'
                 . ($r['pedidos'] > 0 ? ' ' . plural($r['pedidos'], 'pedido pasó', 'pedidos pasaron') . ' a «' . (string)(estado_por_clave(match ((string) pedir('estado')) { 'recibido' => 'llego', 'en_camino', 'en_aduana' => 'en_camino', default => 'reservado' })['nombre'] ?? '') . '».' : '')
                 . (($r['repuestos'] ?? 0) > 0 ? ' ' . plural((int)$r['repuestos'], 'repuesto entró', 'repuestos entraron') . ' al almacén.' : ''));
            ir($al_siguiente($id));
        }
        $errores[] = $r['error'];
        $abrir = 'form-estado';
    }
    /* LISTO PARA ENTREGA (3j): el CEO dice que el contenedor está descargado
       y se puede repartir. Desde ahí, a los asesores les sale «Mandar». */
    if ($lote && $accion === 'listo') {
        $r = lote_listo_marcar($id);
        if ($r['ok']) {
            avisar('ok', 'Listo para entrega.' . ($r['pedidos'] > 0 ? ' ' . plural($r['pedidos'], 'pedido ya se puede', 'pedidos ya se pueden') . ' mandar a despacho.' : ''));
            ir($al_siguiente($id));
        }
        $errores[] = $r['error'];
        $abrir = 'form-listo';
    }
    if ($lote && $accion === 'listo_deshacer') {
        $r = lote_listo_deshacer($id);
        if ($r['ok']) { avisar('ok', 'Deshecho: sus pedidos vuelven a esperar.'); ir($al_siguiente($id)); }
        $errores[] = $r['error'];
        $abrir = 'lote-listo';
    }
    $lote = $id ? lote_de($id) : null;
}

$pais = $lote ? (int)$lote['pais_id'] : (int)yo()['pais_id'];
pagina('catalogo/lote', [
    'lote'      => $lote,
    'filas'     => $post_filas ?? ($lote ? lote_filas($id) : []),
    'productos' => $lote ? lote_productos($id) : [],
    'viaje'     => $lote ? lote_travesia($lote) : null,
    'errores'   => $errores,
    'abrir'     => $abrir,
    'puedo'     => puede('lotes.gestionar'),
    'paso'      => lote_siguiente_paso($lote),
    'con_repuestos' => function_exists('repuestos_listo') && repuestos_listo(),
    'maquinas'  => $lote && function_exists('repuestos_listo') && repuestos_listo() && puede('lotes.gestionar')
                   ? maquinas_para_elegir($id, $pais) : ['lote' => [], 'catalogo' => []],
    'agencias'  => lista('agencias_carga', $pais),
    'responsables' => todas("SELECT us.id, us.nombre, us.apellidos FROM usuarios us JOIN roles r ON r.id = us.rol_id
                              WHERE us.activo = 1 AND us.pais_id = ? AND r.clave IN ('direccion','administracion','almacen')
                              ORDER BY us.nombre", [$pais]),
], ['titulo' => $lote ? (string)$lote['nombre'] : 'Nuevo lote', 'sin_titulo' => true]);
