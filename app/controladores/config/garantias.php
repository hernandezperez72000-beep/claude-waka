<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * CONFIGURACIÓN › GARANTÍAS (3i): lo que se promete en cada venta y cuántos
 * meses dura. La PRIMERA de la lista es la que viene marcada; «Liquidación»
 * tiene la suya. Una garantía ya usada en ventas no cambia de nombre ni de
 * meses (esas ventas siguen diciendo lo que se les prometió): se crea otra y
 * se apaga esta.
 */
$lista_id = (int) valor("SELECT id FROM listas WHERE clave = 'garantias'");
if (!$lista_id || !columna_existe('lista_items', 'meses')) cortar(404, 'Falta terminar la actualización');
$u = yo();
$pais = (int)$u['pais_id'];
$errores = [];

$mia = fn(int $id) => una('SELECT * FROM lista_items WHERE id = ? AND lista_id = ? AND (pais_id IS NULL OR pais_id = ?)', [$id, $lista_id, $pais]);
$repetido = function (string $v, int $sin = 0) use ($lista_id, $pais): bool {
    foreach (todas('SELECT valor FROM lista_items WHERE lista_id = ? AND (pais_id IS NULL OR pais_id = ?) AND id <> ?', [$lista_id, $pais, $sin]) as $f) {
        if (lista_texto_llano((string)$f['valor']) === lista_texto_llano($v)) return true;
    }
    return false;
};
$meses_ok = fn($m) => $m !== null && $m >= 0 && $m <= 120;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');
    $nombre = trim(preg_replace('/\s+/', ' ', (string) pedir('nombre')) ?? '');
    $meses = pedir_int('meses');
    if ($accion === 'anadir') {
        if ($nombre === '' || mb_strlen($nombre) > 60) $errores[] = 'Escribe el nombre (hasta 60 letras).';
        elseif (!$meses_ok($meses)) $errores[] = 'Los meses van de 0 a 120 (0 = sin garantía).';
        elseif ($repetido($nombre)) $errores[] = 'Ya está en la lista.';
        else {
            $orden = (int) valor('SELECT COALESCE(MAX(orden), 0) FROM lista_items WHERE lista_id = ?', [$lista_id]) + 10;
            $nid = insertar('lista_items', ['lista_id' => $lista_id, 'pais_id' => $pais, 'valor' => $nombre, 'orden' => $orden, 'meses' => $meses]);
            bitacora('lista.anadir', 'lista_item', $nid, ['lista' => 'garantias', 'valor' => $nombre, 'meses' => $meses]);
            avisar('ok', $nombre . ' ya se puede elegir en la venta y en el producto.');
            ir('/configuracion/garantias');
        }
    }
    $id = (int) pedir_int('id');
    $fila = $id ? $mia($id) : null;
    if (in_array($accion, ['guardar', 'estado', 'primera'], true) && !$fila) cortar(404, 'Esa garantía no está en tu lista');
    if ($accion === 'guardar') {
        $usada = lista_usos($id) > 0;
        if ($nombre === '' || mb_strlen($nombre) > 60) $errores[] = 'Escribe el nombre (hasta 60 letras).';
        elseif (!$meses_ok($meses)) $errores[] = 'Los meses van de 0 a 120 (0 = sin garantía).';
        elseif ($repetido($nombre, $id)) $errores[] = 'Ya está en la lista.';
        elseif ($usada && ($nombre !== (string)$fila['valor'] || ($fila['meses'] !== null && $meses !== (int)$fila['meses']))) {
            $errores[] = '«' . $fila['valor'] . '» ya se prometió en ventas: no cambia. Crea otra y apaga esta.';
        } else {
            actualizar('lista_items', $id, ['valor' => $nombre, 'meses' => $meses]);
            bitacora('lista.editar', 'lista_item', $id, ['antes' => [$fila['valor'], $fila['meses']], 'ahora' => [$nombre, $meses]]);
            avisar('ok', 'Guardado.');
            ir('/configuracion/garantias');
        }
    }
    if ($accion === 'estado') {
        $encender = pedir('encender') === '1';
        actualizar('lista_items', $id, ['activo' => $encender ? 1 : 0]);
        bitacora('lista.estado', 'lista_item', $id, ['valor' => $fila['valor'], 'activo' => $encender]);
        avisar('ok', $encender ? 'Vuelve a salir en la lista.' : 'Ya no sale en la lista. Las ventas de antes la siguen diciendo.');
        ir('/configuracion/garantias');
    }
    /* LA QUE VIENE MARCADA es la primera de la lista: se sube arriba. */
    if ($accion === 'primera') {
        $min = (int) valor('SELECT COALESCE(MIN(orden), 0) FROM lista_items WHERE lista_id = ?', [$lista_id]);
        actualizar('lista_items', $id, ['orden' => $min - 10]);
        bitacora('lista.orden', 'lista_item', $id, ['valor' => $fila['valor'], 'primera' => true]);
        avisar('ok', $fila['valor'] . ' viene marcada en las ventas nuevas.');
        ir('/configuracion/garantias');
    }
}

$filas = todas('SELECT * FROM lista_items WHERE lista_id = ? AND (pais_id IS NULL OR pais_id = ?) ORDER BY activo DESC, orden, valor', [$lista_id, $pais]);
foreach ($filas as &$f) $f['usos'] = lista_usos((int)$f['id']);
unset($f);
pagina('config/garantias', ['filas' => $filas, 'errores' => $errores],
       ['titulo' => 'Garantías', 'subtitulo' => 'Lo que se promete en cada venta y cuánto dura', 'migaja' => 'Configuración']);
