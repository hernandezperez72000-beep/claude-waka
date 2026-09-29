<?php
declare(strict_types=1);

/**
 * Descargar una tabla a Excel.
 *
 * Se escribe un .xlsx de verdad, no un CSV renombrado: en un Excel en español
 * un CSV separado por comas cae todo en la primera columna, y el usuario ve un
 * archivo roto sin saber por qué. Un .xlsx es un ZIP con tres XML dentro; con
 * la extensión zip —que trae cualquier cPanel— sale en cincuenta líneas y sin
 * meter una librería de 4 MB en el hosting.
 *
 * Si el servidor no tuviera zip, se cae a CSV con BOM y separador de punto y
 * coma, que es el que entiende el Excel en español. Nunca se queda sin
 * descargar nada.
 */

/**
 * $columnas: [['t'=>'Título','k'=>'clave','tipo'=>'texto|numero|dinero|fecha'], ...]
 * $filas:    array de arrays asociativos.
 * El dinero llega en CÉNTIMOS y sale como número con dos decimales, para que
 * en Excel se pueda sumar. Un texto "S/ 1,234.50" no se suma.
 */
function descargar_hoja(string $nombre, array $columnas, array $filas): never
{
    $archivo = preg_replace('/[^A-Za-z0-9_\-]/', '-', $nombre) . '-' . date('Ymd');

    if (class_exists('ZipArchive')) {
        $xlsx = hoja_xlsx($columnas, $filas);
        if ($xlsx !== '') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $archivo . '.xlsx"');
            header('Content-Length: ' . strlen($xlsx));
            header('X-Content-Type-Options: nosniff');
            echo $xlsx;
            exit;
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";                                   // BOM: Excel lo necesita para las tildes
    $salida = fopen('php://output', 'w');
    fputcsv($salida, array_column($columnas, 't'), ';', '"', '\\');
    foreach ($filas as $f) {
        $linea = [];
        /* En el CSV la fecha va escrita (AAAA-MM-DD) y no como el número de
           serie de Excel: aquí no hay estilos que lo interpreten y saldría un
           «46273» donde debería decir una fecha. */
        foreach ($columnas as $c) $linea[] = hoja_valor_texto($c, $f);
        fputcsv($salida, $linea, ';', '"', '\\');
    }
    fclose($salida);
    exit;
}

/**
 * El valor de una celda ya convertido.
 *
 * Las fechas salen como el NÚMERO de serie de Excel, no como texto. Escritas
 * como texto se ven bien y luego no ordenan, no filtran por mes y no alimentan
 * una tabla dinámica — y la fecha es justo la columna por la que se cruza el
 * reporte con el extracto del banco.
 */
function hoja_valor(array $col, array $fila): string
{
    $v = $fila[$col['k']] ?? '';
    return match ($col['tipo'] ?? 'texto') {
        'dinero' => number_format(((int)$v) / 100, 2, '.', ''),
        'numero' => (string)(int)$v,
        'fecha'  => hoja_fecha_serie((string)$v),
        default  => (string)$v,
    };
}

/**
 * Una fecha en el número de serie de Excel: días desde el 1900-01-00.
 * Devuelve '' si no se entiende, y entonces la celda se queda vacía en vez de
 * escribir un cero que en pantalla sale como «00/01/1900».
 */
function hoja_fecha_serie(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    $t = strtotime($v);
    if ($t === false) return '';

    // 25569 = días entre 1900-01-00 (el cero de Excel) y 1970-01-01.
    $dias = (int) floor($t / 86400) + 25569;
    return $dias > 0 ? (string)$dias : '';
}

/** Igual que hoja_valor(), pero para el CSV: todo legible, nada de series. */
function hoja_valor_texto(array $col, array $fila): string
{
    if (($col['tipo'] ?? '') === 'fecha') {
        $v = trim((string)($fila[$col['k']] ?? ''));
        if ($v === '') return '';
        $t = strtotime($v);
        return $t === false ? '' : date('Y-m-d', $t);
    }
    return hoja_valor($col, $fila);
}

/** ¿Esta columna se escribe como número en el Excel? */
function hoja_es_numero(array $col): bool
{
    return in_array($col['tipo'] ?? 'texto', ['dinero', 'numero', 'fecha'], true);
}

/** Arma el .xlsx en memoria. Devuelve '' si no se pudo. */
function hoja_xlsx(array $columnas, array $filas): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'wk');
    if ($tmp === false) return '';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return ''; }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
      . '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
      . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheets><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets></workbook>');

    // Dos estilos: uno para la cabecera en negrita y otro con dos decimales
    // para el dinero, que es lo que la gente va a sumar en la hoja.
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/>'
      . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
      . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
      . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
      . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
      . '<fill><patternFill patternType="gray125"/></fill></fills>'
      . '<borders count="1"><border/></borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="4">'
      . '<xf xfId="0"/>'                                        // 0 normal
      . '<xf xfId="0" fontId="1" applyFont="1"/>'               // 1 cabecera
      . '<xf xfId="0" numFmtId="164" applyNumberFormat="1"/>'   // 2 dinero
      . '<xf xfId="0" numFmtId="165" applyNumberFormat="1"/>'   // 3 fecha
      . '</cellXfs>'
      . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
      . '</styleSheet>');

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    $xml .= '<row r="1">';
    foreach ($columnas as $i => $c) {
        $xml .= '<c r="' . hoja_celda($i, 1) . '" t="inlineStr" s="1"><is><t xml:space="preserve">'
              . hoja_escapa((string)$c['t']) . '</t></is></c>';
    }
    $xml .= '</row>';

    $n = 1;
    foreach ($filas as $f) {
        $n++;
        $xml .= '<row r="' . $n . '">';
        foreach ($columnas as $i => $c) {
            $ref = hoja_celda($i, $n);
            $v   = hoja_valor($c, $f);
            if (hoja_es_numero($c)) {
                /* Una fecha vacía se deja vacía: un 0 con formato de fecha se
                   lee «00/01/1900» y parece un dato, no un hueco. */
                if ($v === '' && ($c['tipo'] ?? '') === 'fecha') continue;
                $estilo = match ($c['tipo'] ?? '') {
                    'dinero' => ' s="2"',
                    'fecha'  => ' s="3"',
                    default  => '',
                };
                $xml .= '<c r="' . $ref . '"' . $estilo . '><v>' . ($v === '' ? '0' : $v) . '</v></c>';
            } else {
                if ($v === '') { continue; }
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                      . hoja_escapa($v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    $bytes = (string) @file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/** 0,1 → "A1"; 26,3 → "AA3" */
function hoja_celda(int $col, int $fila): string
{
    $letras = '';
    $n = $col + 1;
    while ($n > 0) {
        $r = ($n - 1) % 26;
        $letras = chr(65 + $r) . $letras;
        $n = intdiv($n - 1, 26);
    }
    return $letras . $fila;
}

/** Escapa para XML y quita los caracteres de control que rompen el archivo. */
function hoja_escapa(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
