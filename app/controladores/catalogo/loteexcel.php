<?php
declare(strict_types=1);
seccion_activa(seccion_de_lotes());

/**
 * TRAER LOTES DEL EXCEL (3h): un plus para cargar lo pasado. Se sube el
 * archivo (el Google Sheets descargado como Excel o CSV), se enseñan los
 * contenedores que trae y se elige cuál traer. El lote nace «Por confirmar»
 * y apagado, con lo dudoso marcado para revisar.
 */
if (!preventa_lista()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Traer del Excel', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$errores = [];
$bloques = $_SESSION['lote_excel'] ?? [];
if (!is_array($bloques)) $bloques = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');
    if ($accion === 'leer') {
        $f = $_FILES['archivo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errores[] = 'Elige el archivo.';
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            $errores[] = 'El archivo no llegó completo. Vuelve a intentarlo.';
        } elseif ((int)$f['size'] > 5 * 1024 * 1024) {
            $errores[] = 'El archivo pesa más de 5 MB.';
        } else {
            $bloques = lote_excel_bloques(lote_excel_filas((string)$f['tmp_name'], (string)$f['name']));
            if (!$bloques) $errores[] = 'No encontré ningún contenedor en ese archivo. Tiene que ser el Excel de ingresos de carga (descárgalo como Excel o CSV).';
            $_SESSION['lote_excel'] = $bloques;
        }
    }
    if ($accion === 'traer') {
        $k = (int) pedir_int('bloque');
        if (!isset($bloques[$k])) {
            $errores[] = 'Ese contenedor ya no está. Vuelve a subir el archivo.';
        } else {
            $r = lote_excel_crear($bloques[$k]);
            if ($r['ok']) {
                avisar('ok', 'Lote traído. Revisa las filas marcadas y ponle precios antes de ponerlo a la venta.');
                ir('/stock/lote?id=' . $r['id']);
            }
            $errores[] = $r['error'];
        }
    }
}

pagina('catalogo/loteexcel', ['bloques' => $bloques, 'errores' => $errores],
       ['titulo' => 'Traer del Excel', 'subtitulo' => 'Para cargar los contenedores de antes', 'migaja' => 'Lotes de pre venta']);
