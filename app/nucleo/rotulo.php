<?php
declare(strict_types=1);

/**
 * EL RÓTULO DE ENVÍO, EN PDF (parche 2r).
 *
 * «Añadir la creación de un rótulo profesional al mandar a despacho, que se
 * pueda descargar en pdf y lo manden al grupo de WhatsApp» (usuario,
 * 2026-09-20). Media hoja A5, con el logo, para pegar en la caja.
 *
 * POR QUÉ ESTÁ ESCRITO A MANO Y NO CON UNA LIBRERÍA
 * En banahosting no hay composer ni forma cómoda de meter dependencias, y un
 * rótulo es texto en una hoja: cuatro objetos de PDF y un flujo de contenido.
 * Traer una librería entera —y mantenerla actualizada— para dibujar diez
 * líneas era más frágil que estas doscientas.
 *
 * LO QUE SE ADMITE: texto en Helvetica (normal y negrita), líneas, y una
 * imagen JPEG para el logo. Nada más, y no hace falta nada más.
 */

/** Un número de PDF: punto decimal siempre, y sin notación científica. */
function pdf_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
}

/**
 * El texto, como lo quiere un PDF con WinAnsiEncoding.
 * Las tildes y la ñ del castellano viven en CP1252; lo que no quepa ahí se
 * sustituye antes que romper el archivo entero.
 */
function pdf_texto(string $t): string
{
    $t = str_replace(['—', '–', '·', '«', '»', '“', '”', '’'],
                     ['-', '-', '-', '"', '"', '"', '"', "'"], $t);
    $cp = @mb_convert_encoding($t, 'Windows-1252', 'UTF-8');
    if ($cp === false) $cp = $t;
    return strtr($cp, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
}

/**
 * Cuánto ocupa un texto, aproximado.
 * Helvetica no es monoespaciada y meter su tabla de anchos entera aquí sería
 * más código que el resto del archivo. Con 0.52 em de media —0.56 en negrita—
 * el cálculo se queda del lado seguro: el rótulo parte una línea de más antes
 * que salirse del papel.
 */
function pdf_ancho(string $t, float $tam, bool $negrita = false): float
{
    return mb_strlen($t) * $tam * ($negrita ? 0.56 : 0.52);
}

/** Parte un texto en líneas que caben en $ancho puntos. */
function pdf_partir(string $t, float $ancho, float $tam, bool $negrita = false): array
{
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    if ($t === '') return [];
    $out = [];
    $linea = '';
    foreach (explode(' ', $t) as $pal) {
        $prueba = $linea === '' ? $pal : $linea . ' ' . $pal;
        if (pdf_ancho($prueba, $tam, $negrita) <= $ancho) { $linea = $prueba; continue; }
        if ($linea !== '') $out[] = $linea;
        /* Una palabra sola más larga que la línea —un enlace, un código— se
           corta por donde toque en vez de desbordar el papel. */
        while (pdf_ancho($pal, $tam, $negrita) > $ancho && mb_strlen($pal) > 1) {
            $cabe = max(1, (int) floor($ancho / ($tam * ($negrita ? 0.56 : 0.52))));
            $out[] = mb_substr($pal, 0, $cabe);
            $pal = mb_substr($pal, $cabe);
        }
        $linea = $pal;
    }
    if ($linea !== '') $out[] = $linea;
    return $out;
}

/**
 * El PDF entero, en una cadena.
 *
 * $bloques es lo que se pinta, en orden. Cada uno:
 *   ['tipo' => 'titulo'|'clave'|'dato'|'linea'|'espacio', 'texto' => '...']
 * $logo_jpg es el logo ya en JPEG (bytes) o null.
 */
function pdf_hoja(array $bloques, ?array $logo = null): string
{
    /* A5 vertical: 420 × 595 puntos. Márgenes de 34, que es lo que deja una
       impresora doméstica sin recortar. */
    $ancho_hoja = 420.0; $alto_hoja = 595.0; $margen = 34.0;
    $util = $ancho_hoja - 2 * $margen;
    $y = $alto_hoja - $margen;
    $c = [];

    if ($logo && ($logo['alto'] ?? 0) > 0) {
        $w = 120.0;
        $h = $w * ((float)$logo['alto'] / max(1.0, (float)$logo['ancho']));
        $y -= $h;
        $c[] = 'q ' . pdf_num($w) . ' 0 0 ' . pdf_num($h) . ' '
             . pdf_num($margen) . ' ' . pdf_num($y) . ' cm /Im1 Do Q';
        $y -= 14;
    }

    foreach ($bloques as $b) {
        $tipo  = (string)($b['tipo'] ?? 'dato');
        $texto = trim((string)($b['texto'] ?? ''));

        if ($tipo === 'espacio') { $y -= (float)($b['alto'] ?? 8); continue; }
        if ($tipo === 'linea') {
            $y -= 6;
            $c[] = '0.75 w 0.8 0.8 0.8 RG ' . pdf_num($margen) . ' ' . pdf_num($y) . ' m '
                 . pdf_num($ancho_hoja - $margen) . ' ' . pdf_num($y) . ' l S';
            $y -= 10;
            continue;
        }
        if ($texto === '') continue;

        /* Tamaños grandes a propósito: esto se lee a un metro, pegado a una
           caja, en un almacén con poca luz y a veces con la etiqueta torcida. */
        [$tam, $negrita, $gris, $salto] = match ($tipo) {
            'titulo' => [21.0, true,  false, 26.0],
            'clave'  => [9.5,  true,  true,  13.0],
            'grande' => [19.0, true,  false, 24.0],
            default  => [14.0, false, false, 18.0],
        };
        $fuente = $negrita ? '/F2' : '/F1';
        foreach (pdf_partir($texto, $util, $tam, $negrita) as $linea) {
            $y -= $salto;
            if ($y < $margen) break 2;   // no se escribe fuera del papel
            $c[] = 'BT ' . $fuente . ' ' . pdf_num($tam) . ' Tf '
                 . ($gris ? '0.45 0.45 0.45 rg ' : '0 0 0 rg ')
                 . pdf_num($margen) . ' ' . pdf_num($y) . ' Td ('
                 . pdf_texto($linea) . ') Tj ET';
        }
    }

    $flujo = implode("\n", $c);

    /* ── Los objetos ───────────────────────────────────────────────── */
    $obj = [];
    $obj[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $obj[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $recursos = "/Font << /F1 5 0 R /F2 6 0 R >>"
              . ($logo ? " /XObject << /Im1 7 0 R >>" : '');
    $obj[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
            . pdf_num($ancho_hoja) . ' ' . pdf_num($alto_hoja) . "] "
            . "/Resources << $recursos >> /Contents 4 0 R >>";
    $obj[4] = "<< /Length " . strlen($flujo) . " >>\nstream\n$flujo\nendstream";
    $obj[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $obj[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
    if ($logo) {
        $obj[7] = "<< /Type /XObject /Subtype /Image /Width " . (int)$logo['ancho']
                . " /Height " . (int)$logo['alto']
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length "
                . strlen((string)$logo['jpg']) . " >>\nstream\n" . $logo['jpg'] . "\nendstream";
    }

    $pdf = "%PDF-1.4\n";
    $pos = [];
    foreach ($obj as $n => $cuerpo) {
        $pos[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$cuerpo\nendobj\n";
    }
    $inicio = strlen($pdf);
    $total  = count($obj) + 1;
    $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
    foreach ($obj as $n => $_) $pdf .= sprintf("%010d 00000 n \n", $pos[$n]);
    $pdf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$inicio\n%%EOF";
    return $pdf;
}

/** El logo de Waka, en JPEG, para meterlo en el PDF. Null si no se puede. */
function rotulo_logo(): ?array
{
    $ruta = HUB_RAIZ . '/assets/img/logo-waka.png';
        /* Las DOS funciones: hay GD compilado sin JPEG, y ahí `imagejpeg()` mata
       la descarga entera en vez de dar un rótulo sin logo. */
    if (!is_file($ruta) || !function_exists('imagecreatefrompng')
        || !function_exists('imagejpeg')) return null;
    $png = @imagecreatefrompng($ruta);
    if (!$png) return null;
    $w = imagesx($png); $h = imagesy($png);
    /* Fondo blanco: el logo tiene transparencia y en un PDF sin canal alfa
       eso sale negro. */
    $lienzo = imagecreatetruecolor($w, $h);
    imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
    imagecopy($lienzo, $png, 0, 0, 0, 0, $w, $h);
    ob_start();
    imagejpeg($lienzo, null, 92);
    $jpg = (string) ob_get_clean();
    imagedestroy($png); imagedestroy($lienzo);
    return $jpg === '' ? null : ['jpg' => $jpg, 'ancho' => $w, 'alto' => $h];
}

/**
 * El rótulo de un pedido: lo que hay que leer en el almacén y en la agencia.
 *
 * Lleva lo mismo que el mensaje del grupo, menos el dinero: un rótulo pegado a
 * una caja lo lee quien la carga, quien la recibe en la agencia y a veces el
 * vecino del cliente. El precio no es asunto de ninguno de los tres.
 */
function rotulo_de_pedido(int $pedido_id, ?int $garantia_id = null): ?string
{
    $p = pedido_de($pedido_id);
    if (!$p) return null;
    /* LA GARANTÍA (3i) sale con el rótulo de su pedido: mismo cliente, misma
       dirección; el contenido son sus piezas. */
    $gar = null;
    if ($garantia_id) {
        $gar = una('SELECT * FROM garantias WHERE id = ? AND pedido_id = ?', [$garantia_id, $pedido_id]);
        if (!$gar) return null;
    }

    $ubi = ubigeo_de($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null);
    $es_lima = ubigeo_es_lima($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null);
    $cliente = trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos']);

    $destino = implode(' - ', array_values(array_filter([
        (string)($ubi['distrito'] ?? ''),
        (string)($ubi['provincia'] ?? ''),
        (string)($ubi['departamento'] ?? ''),
    ], fn($x) => trim($x) !== '')));

        /* CABE O SE DICE. La hoja es una y no hay segunda: con doce productos, lo
       que sobraba desaparecía sin avisar y lo último que se perdía era el
       remite (auditoría del 2r). Se enseñan ocho y se dice cuántos faltan, que
       es lo que manda a quien carga a mirar el pedido. */
    $todas = $gar ? array_map(fn($l) => ['cantidad' => $l['cantidad'], 'descripcion' => $l['nombre'], 'modelo' => ''],
                              garantia_lineas((int)$gar['id']))
                  : pedido_lineas($pedido_id);
    $TOPE_LINEAS = 8;
    $productos = [];
    foreach (array_slice($todas, 0, $TOPE_LINEAS) as $l) {
        $mod = trim((string)($l['modelo'] ?? ''));
        $productos[] = (int)$l['cantidad'] . ' ' . (string)$l['descripcion']
                     . ($mod !== '' ? ' - ' . $mod : '');
    }
    if (count($todas) > $TOPE_LINEAS) {
        $productos[] = 'y ' . (count($todas) - $TOPE_LINEAS) . ' producto(s) más · ver el pedido';
    }

    $b = [];
    $b[] = ['tipo' => 'titulo', 'texto' => 'RÓTULO DE ENVÍO'];
    $b[] = ['tipo' => 'clave',  'texto' => 'PEDIDO ' . (string)$p['codigo']
                                          . ($gar ? ' · GARANTÍA ' . garantia_codigo((int)$gar['id']) . ' · SIN COBRO'
                                                  : ' · ' . fecha_corta((string)($p['fecha'] ?: $p['creado_en'])))];
    $b[] = ['tipo' => 'linea'];

    $b[] = ['tipo' => 'clave',  'texto' => 'DESTINATARIO'];
    $b[] = ['tipo' => 'grande', 'texto' => $cliente];
    $b[] = ['tipo' => 'dato',   'texto' => (string)$p['cliente_tipo_doc'] . ': '
                                          . (string)$p['cliente_documento']];
    $b[] = ['tipo' => 'dato',   'texto' => 'Celular: ' . (string)$p['cliente_celular']];
    if (trim((string)($p['recibe'] ?? '')) !== '') {
        $b[] = ['tipo' => 'dato', 'texto' => 'Recibe: ' . (string)$p['recibe']];
    }
    $b[] = ['tipo' => 'linea'];

    $b[] = ['tipo' => 'clave',  'texto' => 'DESTINO'];
    if ((string)$p['entrega'] === 'recojo') {
        $b[] = ['tipo' => 'grande', 'texto' => 'RECOGE EN OFICINA'];
    } elseif ($es_lima) {
        $b[] = ['tipo' => 'grande', 'texto' => $destino];
        $b[] = ['tipo' => 'dato',   'texto' => (string)($p['direccion_txt'] ?? '')];
        if (trim((string)($p['referencia_txt'] ?? '')) !== '') {
            $b[] = ['tipo' => 'dato', 'texto' => 'Ref.: ' . (string)$p['referencia_txt']];
        }
        if (pedido_entrega_texto($p) !== '') {
            $b[] = ['tipo' => 'dato', 'texto' => 'Entregar: ' . pedido_entrega_texto($p)];
        }
    } else {
        $b[] = ['tipo' => 'grande', 'texto' => $destino];
        $agencia = trim(pedido_agencia_texto($p, false) . ' - ' . (string)($p['sucursal'] ?? ''), ' -');
        if ($agencia !== '') $b[] = ['tipo' => 'dato', 'texto' => 'Agencia: ' . $agencia];
        $b[] = ['tipo' => 'dato', 'texto' => pedido_como_recibe($p)];
    }
    $b[] = ['tipo' => 'linea'];

    $b[] = ['tipo' => 'clave', 'texto' => 'CONTENIDO'];
    foreach ($productos as $linea) $b[] = ['tipo' => 'dato', 'texto' => $linea];
    if (!$gar && (int)$p['envio_armado'] === 1) $b[] = ['tipo' => 'grande', 'texto' => 'ENVIAR ARMADO'];
        if (!$gar && trim((string)($p['nota'] ?? '')) !== '') {
        /* La nota admite 300 caracteres y el papel no: se recorta aquí, con
           puntos suspensivos, en vez de desaparecer por abajo. */
        $nota = trim((string)$p['nota']);
        if (mb_strlen($nota) > 160) $nota = mb_substr($nota, 0, 157) . '...';
        $b[] = ['tipo' => 'espacio', 'alto' => 4];
        $b[] = ['tipo' => 'clave', 'texto' => 'NOTA'];
        $b[] = ['tipo' => 'dato',  'texto' => $nota];
    }
    $b[] = ['tipo' => 'linea'];
    $b[] = ['tipo' => 'clave', 'texto' => 'REMITE: WAKA IMPORTACIONES · ASESOR '
                                        . mb_strtoupper(primer_nombre(trim((string)$p['asesor_nombre'])))];

    return pdf_hoja($b, rotulo_logo());
}
