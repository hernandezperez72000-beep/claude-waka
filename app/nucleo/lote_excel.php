<?php
declare(strict_types=1);

/**
 * TRAER LOTES DEL EXCEL «INGRESOS DE CARGA» (3h). Es un plus para lo pasado:
 * lo principal es el formulario del lote (usuario, 2026-09-27).
 *
 * El archivo trae VARIOS contenedores, cada uno así:
 *   «2 | INFORMACIÓN DE PRODUCTOS | X1 CTN 40HQ»        ← abre el bloque
 *   CODIGO | (vacía) | PRODUCTO | Cantidad | Colores / Modelo / Detalle
 *   …filas…
 *   (vacía) (vacía) (vacía) 2177                          ← total
 *   AGENCIA DETALLE · BL · FECHA DE SALIDA · FACTURA · FECHA DE LLEGADA PERU ·
 *   Fecha Almacen · Almacen · Encargados                  ← el pie
 * Se lee lo que se pueda y lo dudoso queda marcado «Revisa» en el lote.
 * Nada sale a la venta: el lote nace «Por confirmar» y apagado.
 */

/** Las filas del archivo (CSV o XLSX) como listas de textos. [] si no se puede leer. */
function lote_excel_filas(string $ruta, string $nombre): array
{
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $cabeza = (string) @file_get_contents($ruta, false, null, 0, 4);
    if ($ext === 'xlsx' || str_starts_with($cabeza, "PK\x03\x04")) return lote_excel_xlsx($ruta);
    return lote_excel_csv($ruta);
}

function lote_excel_csv(string $ruta): array
{
    $txt = (string) @file_get_contents($ruta);
    if ($txt === '') return [];
    if (str_starts_with($txt, "\xEF\xBB\xBF")) $txt = substr($txt, 3);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $primera = strtok($txt, "\n") ?: '';
    $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    $h = fopen('php://temp', 'r+');
    fwrite($h, $txt);
    rewind($h);
    $out = [];
    while (($f = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        $out[] = array_map(fn($x) => trim((string)$x), $f);
        if (count($out) > 5000) break;
    }
    fclose($h);
    return $out;
}

/** Lee la PRIMERA hoja de un .xlsx sin librerías (ZipArchive + XML). */
function lote_excel_xlsx(string $ruta): array
{
    if (!class_exists('ZipArchive')) return [];
    $z = new ZipArchive();
    if ($z->open($ruta) !== true) return [];
    $comunes = [];
    $st_ss = $z->statName('xl/sharedStrings.xml');
    if ($st_ss && (int)$st_ss['size'] > 40 * 1024 * 1024) { $z->close(); return []; }
    $ss = $st_ss ? $z->getFromName('xl/sharedStrings.xml') : false;
    if ($ss !== false) {
        $x = @simplexml_load_string($ss);
        if ($x) foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            else foreach ($si->r as $r) $t .= (string)$r->t;
            $comunes[] = $t;
        }
    }
    /* La primera hoja del libro, por su relación (no siempre es sheet1.xml). */
    $hoja = 'xl/worksheets/sheet1.xml';
    $wb = @simplexml_load_string((string) $z->getFromName('xl/workbook.xml'));
    $rels = @simplexml_load_string((string) $z->getFromName('xl/_rels/workbook.xml.rels'));
    if ($wb && $rels && isset($wb->sheets->sheet[0])) {
        $rid = (string) $wb->sheets->sheet[0]->attributes('r', true)['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) { $hoja = 'xl/' . ltrim(str_replace('../', '', (string)$rel['Target']), '/'); break; }
        }
    }
    /* Un .xlsx de 5 MB puede descomprimir a cientos: se mira antes. */
    $st = $z->statName($hoja);
    if (!$st || (int)$st['size'] > 40 * 1024 * 1024) { $z->close(); return []; }
    $xml = $z->getFromName($hoja);
    $z->close();
    if ($xml === false) return [];
    $x = @simplexml_load_string($xml);
    if (!$x || !isset($x->sheetData)) return [];
    $out = [];
    foreach ($x->sheetData->row as $row) {
        $fila = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
            } else {
                $col = count($fila);
            }
            $t = (string)$c['t'];
            if ($t === 's') $v = $comunes[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
            else $v = (string)$c->v;
            $fila[$col] = trim($v);
        }
        if ($fila) {
            /* Las columnas que importan son las primeras: se cortan en 30. */
            $fila = array_filter($fila, fn($k) => $k < 30, ARRAY_FILTER_USE_KEY);
            $max = $fila ? max(array_keys($fila)) : -1;
            $llena = [];
            for ($i = 0; $i <= $max; $i++) $llena[] = $fila[$i] ?? '';
            $out[] = $llena;
        } else {
            $out[] = [];
        }
        if (count($out) > 5000) break;
    }
    return $out;
}

/** Sin tildes ni mayúsculas, para reconocer las etiquetas. */
function lote_excel_llano(string $t): string
{
    return lista_texto_llano($t);
}

/** Una fecha del Excel: «16/07/2026», «26/09» (sin año), o el número de serie de Excel. */
function lote_excel_fecha(string $t, ?string $referencia = null): ?string
{
    $t = trim($t);
    if ($t === '') return null;
    if (preg_match('/^\d{5}(\.\d+)?$/', $t)) {                       // serie de Excel
        return gmdate('Y-m-d', (int) round(((float)$t - 25569) * 86400));   // la serie es un día, no una hora: sin zona
    }
    if (preg_match('~^(\d{1,2})/(\d{1,2})$~', $t, $m)) {             // sin año: se completa
        $anio = (int) date('Y', strtotime($referencia ?? date('Y-m-d')));
        $f = sprintf('%04d-%02d-%02d', $anio, $m[2], $m[1]);
        if (!checkdate((int)$m[2], (int)$m[1], $anio)) return null;
        if ($referencia && $f < $referencia) $f = sprintf('%04d-%02d-%02d', $anio + 1, $m[2], $m[1]);
        return $f;
    }
    $f = lote_fecha($t);
    return is_string($f) ? $f : null;
}

/**
 * Los contenedores del archivo, ya interpretados.
 * → [['nombre', 'numero', 'viaje' => [...lote_guardar], 'filas' => [[codigo, nombre, modelo, unidades,
 *     nuevo, revisa, aviso, texto]], 'unidades', 'total_excel', 'avisos' => [...] ], …]
 */
function lote_excel_bloques(array $filas): array
{
    $bloques = [];
    $b = null;
    $cols = null;
    $etiquetas = [
        'agencia detalle' => 'agencia_ref', 'bl' => 'bl', 'fecha de salida' => 'fecha_salida', 'factura' => 'factura',
        'fecha de llegada peru' => 'fecha_llegada_est', 'fecha de llegada' => 'fecha_llegada_est',
        'fecha almacen' => 'fecha_almacen', 'almacen' => 'almacen', 'encargados' => 'encargados',
    ];
    $cerrar = function () use (&$b, &$bloques) { if ($b) { $bloques[] = $b; } $b = null; };
    foreach ($filas as $f) {
        $celdas = array_values(array_map('strval', $f));
        $unida = implode(' | ', array_filter($celdas, fn($x) => $x !== ''));
        $llana = lote_excel_llano($unida);
        if ($unida === '') continue;
        /* Abre un contenedor. */
        if (str_contains($llana, 'informacion de productos')) {
            $cerrar();
            $num = '';
            foreach ($celdas as $c) if (preg_match('/^\d{1,3}$/', trim($c))) { $num = trim($c); break; }
            $tras = '';
            if (preg_match('~informaci[oó]n de productos\s*\|?\s*(.*)$~iu', $unida, $m)) $tras = trim($m[1], " |\t");
            $nombre = trim(($num !== '' ? 'Contenedor ' . $num : 'Contenedor') . ($tras !== '' ? ' · ' . $tras : ''));
            $b = ['nombre' => mb_substr($nombre, 0, 140), 'numero' => $num, 'viaje' => ['nombre' => mb_substr($nombre, 0, 140)],
                  'filas' => [], 'unidades' => 0, 'total_excel' => null, 'avisos' => [], 'notas' => [], 'pie' => false];
            $cols = null;
            continue;
        }
        if (!$b) continue;
        /* La cabecera de columnas. */
        if (!$cols && in_array('codigo', array_map('lote_excel_llano', $celdas), true)) {
            $cols = ['codigo' => null, 'producto' => null, 'cantidad' => null, 'colores' => null];
            foreach ($celdas as $i => $c) {
                $l = lote_excel_llano($c);
                if ($l === 'codigo') $cols['codigo'] = $i;
                elseif ($l === 'producto') $cols['producto'] = $i;
                elseif ($l === 'cantidad') $cols['cantidad'] = $i;
                elseif (str_starts_with($l, 'colores')) $cols['colores'] = $i;
            }
            continue;
        }
        /* El pie: «ETIQUETA · valor · (extras)». */
        $primera = ''; $ip = -1;
        foreach ($celdas as $i => $c) if ($c !== '') { $primera = $c; $ip = $i; break; }
        $clave = $etiquetas[lote_excel_llano($primera)] ?? null;
        if ($clave) {
            $b['pie'] = true;
            $resto = array_values(array_filter(array_slice($celdas, $ip + 1), fn($x) => $x !== ''));
            $valor = $resto[0] ?? '';
            if ($clave === 'encargados') { if ($valor !== '' && stripos($valor, 'confirmar') === false) $b['notas'][] = 'Encargados: ' . $valor; }
            elseif (in_array($clave, ['fecha_salida', 'fecha_llegada_est', 'fecha_almacen'], true)) $b['viaje'][$clave] = $valor;
            else $b['viaje'][$clave] = $valor;
            foreach (array_slice($resto, 1) as $extra) {
                $le = lote_excel_llano($extra);
                if (preg_match('/^canal (verde|amarillo|rojo)$/', $le, $m)) $b['viaje']['canal'] = $m[1];
                elseif ($le !== '' && $le !== 'por confirmar') $b['notas'][] = $extra;
            }
            continue;
        }
        if ($b['pie'] || !$cols) continue;
        $cod = $cols['codigo'] !== null ? trim((string)($celdas[$cols['codigo']] ?? '')) : '';
        $nom = $cols['producto'] !== null ? trim((string)($celdas[$cols['producto']] ?? '')) : '';
        $can = $cols['cantidad'] !== null ? trim((string)($celdas[$cols['cantidad']] ?? '')) : '';
        $col = $cols['colores'] !== null ? trim((string)($celdas[$cols['colores']] ?? '')) : '';
        /* La fila del total: sin código ni producto, con un número. */
        if ($cod === '' && $nom === '') {
            $n = preg_replace('/\D/', '', $can !== '' ? $can : $unida);
            if ($n !== '') $b['total_excel'] = (int)$n;
            continue;
        }
        /* «12», «12.0» o «1,000»: la parte entera, sin separadores de miles. */
        $und = 0;
        if (preg_match('/^\s*(\d{1,3}(?:[.,]\d{3})+|\d+)(?:[.,]\d+)?\s*$/', $can, $mu)) $und = (int) preg_replace('/\D/', '', $mu[1]);
        elseif (preg_match('/(\d+)/', $can, $mu)) $und = (int) $mu[1];
        $aviso = [];
        $revisa = false;
        /* Dos códigos en una celda: a revisar. */
        $codigos = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $cod) ?: [])));
        $codigo = mb_strtoupper($codigos[0] ?? '');
        /* Dos códigos en una celda: no se adivina cuál; queda sin código. */
        if (count($codigos) > 1) {
            $revisa = true; $aviso[] = 'Trae dos códigos (' . implode(' y ', $codigos) . '): elige uno'; $codigo = '';
            if ($nom === '') $nom = 'Sin nombre (' . implode(' y ', $codigos) . ')';
        }
        if ($codigo !== '' && !sku_valido($codigo)) { $aviso[] = 'El código «' . $codigo . '» no vale'; $codigo = ''; $revisa = true; }
        /* «| NUEVO» en el nombre: es una marca, no parte del nombre. */
        $nuevo = 0;
        if (preg_match('~\|\s*nuevo\s*$~iu', $nom)) { $nuevo = 1; $nom = trim(preg_replace('~\|\s*nuevo\s*$~iu', '', $nom) ?? $nom); }
        $ln = lote_excel_llano($nom);
        if ($und <= 0) { $revisa = true; $aviso[] = 'Sin unidades'; $und = 0; }
        $partes = [['modelo' => '', 'unidades' => $und]];
        $maquina = '';
        if (str_contains($ln, 'repuesto') || preg_match('/\baccesorios?\b/', $ln)) {
            /* REPUESTOS Y ACCESORIOS (3i): traen el código de SU MÁQUINA, así
               que no se enlazan por código (serían la máquina): el código pasa
               a «Repuesto de» y la fila se parte PIEZA POR PIEZA. El HUB
               propone y el CEO confirma: todo nace «Revisa» (tarjeta del
               2026-09-28). */
            $revisa = true; $aviso[] = 'Repuesto: revisa las piezas, sus unidades y la máquina';
            $maquina = $codigo;
            $codigo = '';
            $det = trim($col);
            $kit = function_exists('repuesto_partir_kit') ? repuesto_partir_kit($det, $und) : [];
            if ($kit) {
                $partes = array_map(fn($pz) => ['nombre' => $pz['nombre'], 'modelo' => '', 'unidades' => (int)$pz['unidades']], $kit);
            } else {
                $partes = [['modelo' => '', 'unidades' => $und]];
            }
        } else {
            $pc = preventa_partir_colores($col, max(1, $und));
            if ($pc['estado'] === 'listo') $partes = $pc['partes'];
            elseif ($pc['estado'] === 'revisa') { $revisa = true; $aviso[] = 'Los colores no suman las unidades'; }
        }
        foreach ($partes as $pa) {
            $b['filas'][] = ['codigo' => $codigo, 'nombre' => mb_substr((string)($pa['nombre'] ?? $nom), 0, 180), 'modelo' => mb_substr((string)$pa['modelo'], 0, 120),
                             'unidades' => (int)$pa['unidades'], 'nuevo' => $nuevo, 'revisa' => $revisa,
                             'aviso' => implode(' · ', $aviso), 'texto' => $col, 'maquina' => $maquina];
        }
        $b['unidades'] += $und;
    }
    $cerrar();
    foreach ($bloques as &$x) {
        if ($x['total_excel'] !== null && $x['total_excel'] !== $x['unidades']) {
            $x['avisos'][] = 'El total del Excel dice ' . $x['total_excel'] . ' y las filas suman ' . $x['unidades'] . '.';
        }
        $sal = lote_excel_fecha((string)($x['viaje']['fecha_salida'] ?? ''));
        $x['viaje']['fecha_salida'] = $sal ?? '';
        $lle_txt = (string)($x['viaje']['fecha_llegada_est'] ?? '');
        $lle = lote_excel_fecha($lle_txt, $sal);
        if ($lle && preg_match('~^\d{1,2}/\d{1,2}$~', trim($lle_txt))) {
            $x['avisos'][] = 'La llegada venía sin año («' . trim($lle_txt) . '»): se puso ' . date('d/m/Y', strtotime($lle)) . '.';
        }
        $x['viaje']['fecha_llegada_est'] = $lle ?? '';
        $x['viaje']['fecha_almacen'] = lote_excel_fecha((string)($x['viaje']['fecha_almacen'] ?? '')) ?? '';
        if ($x['notas']) $x['viaje']['notas'] = mb_substr(implode(' · ', array_unique($x['notas'])), 0, 500);
        $x['revisar'] = count(array_filter($x['filas'], fn($f) => $f['revisa']));
    }
    unset($x);
    return array_values(array_filter($bloques, fn($x) => $x['filas']));
}

/**
 * Crea el lote de un contenedor leído: el viaje y sus filas, en «Por
 * confirmar» y apagado. Las filas dudosas quedan marcadas «Revisa».
 * → ['ok', 'error', 'id']
 */
function lote_excel_crear(array $bloque): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0];
    $u = yo();
    /* El mismo contenedor ya traído: mismo nombre y misma salida. */
    if (valor('SELECT id FROM lotes WHERE nombre = ? AND pais_id = ? AND ' . (($bloque['viaje']['fecha_salida'] ?? '') !== '' ? 'fecha_salida = ?' : 'fecha_salida IS NULL'),
              array_merge([$bloque['nombre'], (int)$u['pais_id']], ($bloque['viaje']['fecha_salida'] ?? '') !== '' ? [$bloque['viaje']['fecha_salida']] : []))) {
        return $mal('Ya hay un lote «' . $bloque['nombre'] . '». Ábrelo desde la lista de lotes.');
    }
    $r = lote_guardar(null, $bloque['viaje']);
    if (!$r['ok']) return $mal($r['error']);
    $id = (int)$r['id'];
    /* El mismo producto y color dos veces en el contenedor (mismo código, o
       mismo nombre sin código): se suman y se marca para revisar. */
    /* Una fila sin código con el nombre de otra que sí lo trae es el mismo
       producto: toma su código (si no, al guardar serían «el mismo dos veces»). */
    $cod_por_nombre = [];
    foreach ($bloque['filas'] as $f) if ($f['codigo'] !== '') $cod_por_nombre[lista_texto_llano($f['nombre'])] ??= $f['codigo'];
    foreach ($bloque['filas'] as &$f) {
        $f['maquina'] = (string)($f['maquina'] ?? '');
        /* Un repuesto no toma el código de otra fila: sería el de otro producto. */
        if ($f['codigo'] === '' && $f['maquina'] === '' && isset($cod_por_nombre[lista_texto_llano($f['nombre'])])) $f['codigo'] = $cod_por_nombre[lista_texto_llano($f['nombre'])];
    }
    unset($f);
    $juntas = [];
    foreach ($bloque['filas'] as $f) {
        /* Una pieza de repuesto se compara como la compara el guardado
           («Botones» y «Botón» de la misma máquina son la misma): si no, el
           guardado las ve dos veces y el contenedor entero no entra. */
        $nom_k = $f['maquina'] !== '' && function_exists('repuesto_clave_nombre') ? repuesto_clave_nombre($f['nombre']) : lista_texto_llano($f['nombre']);
        $k = ($f['codigo'] !== '' ? $f['codigo'] : 'n:' . $nom_k) . '|' . variante_clave((string)$f['modelo'], '') . '|' . $f['maquina'];
        if (isset($juntas[$k])) {
            $juntas[$k]['unidades'] += (int)$f['unidades'];
            $juntas[$k]['revisa'] = true;
            $juntas[$k]['aviso'] = trim($juntas[$k]['aviso'] . ' · Venía en dos filas: se sumaron', ' ·');
        } else {
            $juntas[$k] = $f;
        }
    }
    $bloque['filas'] = array_values($juntas);
    $filas = array_map(fn($f) => ['codigo' => $f['codigo'], 'nombre' => $f['nombre'] !== '' ? $f['nombre'] : $f['codigo'],
                                  'modelo' => $f['modelo'], 'unidades' => (string) max(1, (int)$f['unidades']), 'nuevo' => $f['nuevo'],
                                  'maquina' => $f['maquina']],
                        $bloque['filas']);
    /* Las filas se guardan con la misma función del formulario: una sola regla. */
    /* Ya viene partida por colores: no se vuelve a partir (si no, los avisos
       caerían en otras filas). */
    $g = lote_filas_guardar($id, $filas, false);
    if (!$g['ok']) {
        q('DELETE FROM lotes WHERE id = ?', [$id]);
        return $mal($g['error']);
    }
    /* Lo dudoso, marcado para revisar, con lo que decía el Excel. */
    $guardadas = lote_filas($id);
    foreach ($bloque['filas'] as $k => $f) {
        if (!isset($guardadas[$k])) break;
        $cambio = ['texto_origen' => mb_substr((string)$f['texto'], 0, 300) ?: null];
        if ($f['revisa'] || (int)$f['unidades'] <= 0) {
            $cambio['resuelto'] = 0;
            /* Sin pisar lo que ya dijo el guardado (la máquina que no está). */
            $ya_aviso = (string)($guardadas[$k]['aviso'] ?? '');
            $cambio['aviso'] = mb_substr(trim($f['aviso'] . ($ya_aviso !== '' ? ' · ' . $ya_aviso : ''), ' ·'), 0, 200);
        }
        actualizar('lote_lineas', (int)$guardadas[$k]['id'], $cambio);
    }
    actualizar('lotes', $id, ['archivo_origen' => 'Excel de ingresos de carga',
                              'filas_con_aviso' => (int) valor('SELECT COUNT(*) FROM lote_lineas WHERE lote_id = ? AND resuelto = 0', [$id])]);
    bitacora('lote.excel', 'lote', $id, ['filas' => count($filas)]);
    return ['ok' => true, 'error' => '', 'id' => $id];
}
