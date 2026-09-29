<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * LISTAS DE NOMBRES POR PAÍS, una pantalla para las dos (3g y 3h):
 *   · Configuración › Equipo de despacho: los que salen en «¿Quién lo alistó?».
 *   · Configuración › Agencias de carga: las que traen los contenedores.
 * Cada país tiene los suyos. No se borran —lo viejo seguiría diciendo su
 * nombre—: se apagan.
 */
$LISTAS_SIMPLES = [
    '/configuracion/equipo-despacho' => ['clave' => 'equipo_despacho', 'titulo' => 'Equipo de despacho',
        'sub' => 'Quién alista los pedidos en el almacén', 'uso' => 'Salen en «¿Quién lo alistó?»',
        'hueco' => 'Nombre de quien alista', 'vacio' => 'Todavía no hay nadie. Añade a quien prepara los pedidos.',
        'ayuda' => ['Almacén, al terminar de preparar un pedido, pulsa ALISTADO, elige quién lo alistó y sube la foto de lo que va en la caja.'],
        'usado' => ' ya alistó pedidos: su nombre no se cambia. Añade el nuevo y apaga este.',
        'anadido' => ' ya sale al marcar un pedido como alistado.'],
    '/configuracion/agencias-carga' => ['clave' => 'agencias_carga', 'titulo' => 'Agencias de carga',
        'sub' => 'Quién trae los contenedores', 'uso' => 'Salen al llenar un lote de pre venta',
        'hueco' => 'Nombre de la agencia', 'vacio' => 'Todavía no hay ninguna. Añade la agencia que trae los contenedores.',
        'ayuda' => ['Al llenar un lote de pre venta se elige su agencia de carga de esta lista.'],
        'usado' => ' ya trae lotes: su nombre no se cambia. Añade el nuevo y apaga este.',
        'anadido' => ' ya sale al llenar un lote.'],
];
$cfg = $LISTAS_SIMPLES[$ruta] ?? null;
if (!$cfg) cortar(404, 'Esta página no existe');
$u = yo();
$pais = (int)$u['pais_id'];
$lista_id = (int) valor('SELECT id FROM listas WHERE clave = ?', [$cfg['clave']]);
if (!$lista_id) cortar(404, 'Falta terminar la actualización');
$errores = [];

$de_mi_pais = fn(int $id) => una('SELECT * FROM lista_items WHERE id = ? AND lista_id = ? AND pais_id = ?',
                                 [$id, $lista_id, $pais]);
/* «José» y «jose» son el mismo nombre. Se compara aquí, en PHP, y no con
   LOWER() de la base: MariaDB y el banco de pruebas no tratan igual las
   tildes. → la fila que ya lo tiene, o null. */
$llano = fn(string $v) => lista_texto_llano($v);
$repetido = function (string $v, int $sin = 0) use ($lista_id, $pais, $llano): ?array {
    foreach (todas('SELECT * FROM lista_items WHERE lista_id = ? AND (pais_id = ? OR pais_id IS NULL) AND id <> ?', [$lista_id, $pais, $sin]) as $f) {
        if ($llano((string)$f['valor']) === $llano($v)) return $f;
    }
    return null;
};
$ya_esta = fn(array $f) => (int)$f['activo'] === 1 ? 'Ya está en la lista.' : 'Ya está en la lista, apagado: enciéndelo.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');
    $nombre = trim(preg_replace('/\s+/', ' ', (string) pedir('nombre')) ?? '');
    if ($accion === 'anadir') {
        if ($nombre === '' || mb_strlen($nombre) > 60) $errores[] = 'Escribe el nombre (hasta 60 letras).';
        elseif ($r0 = $repetido($nombre)) $errores[] = $ya_esta($r0);
        else {
            $orden = (int) valor('SELECT COALESCE(MAX(orden), 0) FROM lista_items WHERE lista_id = ?', [$lista_id]) + 10;
            $nid = insertar('lista_items', ['lista_id' => $lista_id, 'pais_id' => $pais, 'valor' => $nombre, 'orden' => $orden]);
            bitacora('lista.anadir', 'lista_item', $nid, ['lista' => $cfg['clave'], 'valor' => $nombre]);
            avisar('ok', $nombre . $cfg['anadido']);
            ir($ruta);
        }
    }
    $id = (int) pedir_int('id');
    $fila = $id ? $de_mi_pais($id) : null;
    if (in_array($accion, ['renombrar', 'estado'], true) && !$fila) cortar(404, 'Ese nombre no está en tu lista');
    if ($accion === 'renombrar') {
        if ($nombre === '' || mb_strlen($nombre) > 60) $errores[] = 'Escribe el nombre (hasta 60 letras).';
        elseif ($r0 = $repetido($nombre, $id)) $errores[] = $ya_esta($r0);
        /* El nombre de quien YA alistó pedidos no se cambia: esos pedidos
           dirían otro nombre. Se añade el nuevo y se apaga este. */
        elseif (lista_usos($id) > 0 && $nombre !== (string)$fila['valor']) {
            $errores[] = (string)$fila['valor'] . $cfg['usado'];
        } else {
            actualizar('lista_items', $id, ['valor' => $nombre]);
            bitacora('lista.editar', 'lista_item', $id, ['antes' => $fila['valor'], 'ahora' => $nombre]);
            avisar('ok', 'Guardado.');
            ir($ruta);
        }
    }
    if ($accion === 'estado') {
        $encender = pedir('encender') === '1';
        actualizar('lista_items', $id, ['activo' => $encender ? 1 : 0]);
        bitacora('lista.estado', 'lista_item', $id, ['valor' => $fila['valor'], 'activo' => $encender]);
        avisar('ok', $encender ? 'Vuelve a salir en la lista.' : 'Ya no sale en la lista. Lo de antes lo sigue diciendo.');
        ir($ruta);
    }
}

pagina('config/listasimple', [
    'filas'   => todas('SELECT * FROM lista_items WHERE lista_id = ? AND pais_id = ? ORDER BY activo DESC, orden, valor',
                       [$lista_id, $pais]),
    'errores' => $errores,
    'cfg'     => $cfg,
], ['titulo' => $cfg['titulo'], 'subtitulo' => $cfg['sub'], 'migaja' => 'Configuración']);
