<?php
declare(strict_types=1);
seccion_activa('clientes');

$u    = yo();
$id   = pedir_int('id', 'get');
$fila = null;

if ($id) {
    $fila = una('SELECT * FROM clientes WHERE id = ?', [$id]);
    if (!$fila) cortar(404, 'Ese cliente no existe');
    // Dos comprobaciones separadas, y esta es la de ESCRIBIR: el ámbito
    // "equipo" deja ver los clientes de los suyos, pero no corregirlos.
    exigir('clientes.editar');
    exigir_editar((int)$fila['asesor_id'], (int)$fila['pais_id']);
} else {
    exigir('clientes.crear');
}

$pais_id = $id ? (int)$fila['pais_id'] : (int)$u['pais_id'];

$canales = lista('canales', $pais_id);

$errores = [];
$aviso_repetido = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Leer, validar y crear viven en app/nucleo/clientes.php, no aquí: el alta
    // rápida de dentro del pedido usa exactamente las mismas tres funciones.
    $d = cliente_datos_del_post($pais_id);
    $revision = cliente_validar($d, $pais_id, $id);
    $errores        = $revision['errores'];
    $aviso_repetido = $revision['repetido'];

    if (!$errores) {
        if ($id) {
            actualizar('clientes', $id, $d);
            bitacora('cliente.editar', 'cliente', $id, ['documento' => $d['documento']]);
            avisar('ok', 'La ficha de ' . primer_nombre($d['nombre']) . ' quedó guardada.');
            ir('/clientes/ficha?id=' . $id);
        } else {
            $nuevo = cliente_crear($d, $u);
            avisar('ok', primer_nombre($d['nombre']) . ' quedó registrado. Ya puedes hacerle un pedido.');
            ir('/clientes/ficha?id=' . $nuevo . '&nuevo=1');
        }
    }
    $fila = array_merge((array)$fila, $d);
}

pagina('clientes/editar', [
    'fila' => $fila, 'id' => $id, 'canales' => $canales,
    'errores' => $errores,
    'repetido' => $aviso_repetido,
], ['titulo' => $id ? 'Editar cliente' : 'Nuevo cliente',
    'migaja'  => 'Clientes',
    'subtitulo' => $id ? '' : 'Con su documento y correo tendrá su cuenta en compraenwaka']);
