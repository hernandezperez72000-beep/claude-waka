<?php
declare(strict_types=1);

/**
 * LA GUÍA DE REMISIÓN DE LOS ENVÍOS A PROVINCIA (5b).
 *
 * «Tenemos pendiente lo de la guía de remisión para envíos a provincia: se
 * genera automático y almacén tiene el botón» (usuario, 2026-09-29). Y para
 * boleta, factura y guía: «primero una vista previa, y luego un botón de
 * mandar, con algunos campos para modificar, y que quede el registro».
 *
 * El HUB arma la guía con los datos del pedido (destinatario, productos,
 * punto de llegada, la agencia que la lleva) y la enseña para corregir. Al
 * mandarla, NUBEFACT la firma y la manda a SUNAT (guía de remisión remitente,
 * tipo 7). Lo que se cambió queda en la bitácora, campo por campo.
 *
 * Usa la misma tabla y la misma numeración que las boletas
 * (comprobantes_electronicos): un corte de red no produce dos guías.
 */

/** ¿Se puede emitir guías desde aquí? Con NUBEFACT listo y su serie puesta. */
function guia_activa(): bool
{
    return nubefact_activo() && nubefact_serie_valida(nubefact_config()['serie_guia'] ?? '', 'T');
}

/** ¿Este pedido lleva guía? Los envíos fuera de Lima y Callao que ya salieron a despacho. */
function guia_aplica(array $p): bool
{
    if (!empty($p['anulado_en']) || (int)($p['despacho_veces'] ?? 0) === 0) return false;
    if ((string)($p['entrega'] ?? '') !== 'envio' || empty($p['ubigeo_id'])) return false;
    return !ubigeo_es_lima((int)$p['ubigeo_id']);
}

/** Nombre sin tildes ni mayúsculas, para cruzar con los códigos del INEI. */
function ubigeo_llave(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                    'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/**
 * EL CÓDIGO INEI (6 dígitos) de un distrito del HUB. Primero el que alguien
 * ya escribió a mano en una guía (se recuerda), después la lista del INEI.
 */
function ubigeo_codigo(?int $ubigeo_id): string
{
    if (!$ubigeo_id) return '';
    if (columna_existe('ubigeo', 'codigo_inei')) {
        $c = (string) valor('SELECT codigo_inei FROM ubigeo WHERE id = ?', [$ubigeo_id]);
        if (preg_match('/^\d{6}$/', $c)) return $c;
    }
    $u = ubigeo_de($ubigeo_id);
    if (!$u) return '';
    require_once __DIR__ . '/ubigeo_inei.php';
    $k = ubigeo_llave((string)$u['departamento']) . '|' . ubigeo_llave((string)$u['provincia']) . '|' . ubigeo_llave((string)$u['distrito']);
    return (string)(ubigeo_inei_mapa()[$k] ?? '');
}

/** La guía emitida de un pedido (la última), o null. */
function guia_de_pedido(int $pedido_id): ?array
{
    if (!tabla_existe('comprobantes_electronicos')) return null;
    $f = una("SELECT * FROM comprobantes_electronicos WHERE pedido_id = ? AND tipo_cpe = 7 AND estado = 'emitido'
               AND (ambiente = 'produccion' OR ambiente = ?) ORDER BY id DESC LIMIT 1", [$pedido_id, nubefact_ambiente()]);
    return $f ?: null;
}

/** Una guía mandada sin respuesta (se reintenta con el MISMO número). */
function guia_pendiente(int $pedido_id): ?array
{
    if (!tabla_existe('comprobantes_electronicos')) return null;
    $f = una("SELECT * FROM comprobantes_electronicos WHERE pedido_id = ? AND tipo_cpe = 7 AND estado IN ('incierto', 'enviando')
               AND (ambiente = 'produccion' OR ambiente = ?) ORDER BY id DESC LIMIT 1", [$pedido_id, nubefact_ambiente()]);
    return $f ?: null;
}

/** Los motivos de traslado que se ofrecen (código de SUNAT). */
function guia_motivos(): array
{
    return ['01' => 'Venta', '14' => 'Venta sujeta a confirmación del comprador', '13' => 'Otros'];
}

/** El transportista que se usó la última vez con esta agencia (se recuerda). */
function guia_transportista_de(?int $agencia_item_id): array
{
    $j = $agencia_item_id ? json_decode((string) ajuste('guia_transportista_' . $agencia_item_id, ''), true) : null;
    return is_array($j) ? ['ruc' => (string)($j['ruc'] ?? ''), 'nombre' => (string)($j['nombre'] ?? '')] : ['ruc' => '', 'nombre' => ''];
}

/**
 * LO QUE PROPONE EL HUB, para la vista previa. Todo sale del pedido; lo que
 * no se sabe (el RUC de la agencia la primera vez) va vacío para llenarlo.
 */
function guia_borrador(array $p): array
{
    $cli = una('SELECT * FROM clientes WHERE id = ?', [(int)$p['cliente_id']]) ?: [];
    $ubi = ubigeo_de($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null);
    $agencia = pedido_agencia_texto($p, false);
    $tr = guia_transportista_de(!empty($p['agencia_item_id']) ? (int)$p['agencia_item_id'] : null);
    $dest_dir = trim((string)($p['direccion_txt'] ?? ''));
    if (!empty($p['recojo_agencia']) || $dest_dir === '') {
        $dest_dir = trim('Agencia ' . $agencia . (trim((string)($p['sucursal'] ?? '')) !== '' ? ' - ' . $p['sucursal'] : ''));
    }
    $lugar = $ubi ? implode(' - ', array_filter([(string)$ubi['distrito'], (string)$ubi['provincia'], (string)$ubi['departamento']])) : '';
    $peso = (float) str_replace(',', '.', (string) ajuste('guia_peso_bulto', '5'));
    $items = [];
    foreach (pedido_lineas((int)$p['id']) as $l) {
        $mod = trim((string)($l['modelo'] ?? ''));
        $sku = !empty($l['producto_id']) ? (string) valor('SELECT sku FROM productos WHERE id = ?', [(int)$l['producto_id']]) : '';
        $items[] = ['codigo' => $sku, 'descripcion' => trim((string)$l['descripcion'] . ($mod !== '' ? ' - ' . $mod : '')),
                    'cantidad' => (int)$l['cantidad']];
    }
    $vig = nubefact_vigente((int)$p['id']);
    return [
        'dest_tipo_doc'  => (string)($cli['tipo_doc'] ?? 'DNI'),
        'dest_doc'       => (string)($cli['documento'] ?? ''),
        'dest_nombre'    => trim((string)($cli['nombre'] ?? '') . ' ' . (string)($cli['apellidos'] ?? '')),
        'dest_email'     => (string)($cli['email'] ?? ''),
        'fecha_traslado' => date('Y-m-d'),
        'motivo'         => '01',
        'bultos'         => 1,
        'peso'           => $peso > 0 ? $peso : 5.0,
        'transp_ruc'     => $tr['ruc'],
        'transp_nombre'  => $tr['nombre'] !== '' ? $tr['nombre'] : $agencia,
        'partida_ubigeo' => (string) ajuste('guia_partida_ubigeo', ''),
        'partida_dir'    => (string) ajuste('guia_partida_direccion', ''),
        'llegada_ubigeo' => ubigeo_codigo($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null),
        'llegada_dir'    => mb_substr($dest_dir . ($lugar !== '' ? ', ' . $lugar : ''), 0, 150),
        'observaciones'  => 'Pedido ' . (string)$p['codigo'] . ($agencia !== '' ? ' · por ' . $agencia : ''),
        'items'          => $items,
        'doc_rel'        => $vig ? ['tipo' => (int)$vig['tipo_cpe'] === 1 ? '01' : '03', 'serie' => (string)$vig['serie'], 'numero' => (int)$vig['numero']] : null,
    ];
}

/** Lo que llega del formulario de la vista previa, limpio y comprobado. → [ok, error, g] */
function guia_leer_formulario(array $post, array $borrador): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'g' => null];
    $t = fn(string $k, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($post[$k] ?? '')) ?? ''), 0, $n);
    $g = $borrador;
    $g['dest_nombre']    = $t('dest_nombre', 150);
    $g['dest_doc']       = preg_replace('/\D/', '', (string)($post['dest_doc'] ?? '')) ?? '';
    $g['fecha_traslado'] = (string)($post['fecha_traslado'] ?? date('Y-m-d'));
    $g['motivo']         = (string)($post['motivo'] ?? '01');
    $g['bultos']         = (int)($post['bultos'] ?? 1);
    $g['peso']           = (float) str_replace(',', '.', (string)($post['peso'] ?? '0'));
    $g['transp_ruc']     = preg_replace('/\D/', '', (string)($post['transp_ruc'] ?? '')) ?? '';
    $g['transp_nombre']  = $t('transp_nombre', 150);
    $g['partida_ubigeo'] = preg_replace('/\D/', '', (string)($post['partida_ubigeo'] ?? '')) ?? '';
    $g['partida_dir']    = $t('partida_dir', 150);
    $g['llegada_ubigeo'] = preg_replace('/\D/', '', (string)($post['llegada_ubigeo'] ?? '')) ?? '';
    $g['llegada_dir']    = $t('llegada_dir', 150);
    $g['observaciones']  = $t('observaciones', 250);
    $items = [];
    foreach ((array)($post['i_desc'] ?? []) as $k => $d) {
        $d = mb_substr(trim((string)$d), 0, 250);
        $c = (int)($post['i_cant'][$k] ?? 0);
        if ($d === '' && $c === 0) continue;
        if ($d === '') return $mal('Una línea de productos no dice qué es.');
        if ($c <= 0 || $c > 100000) return $mal('La cantidad de «' . $d . '» tiene que ser un número de 1 para arriba.');
        $items[] = ['codigo' => (string)($borrador['items'][$k]['codigo'] ?? ''), 'descripcion' => $d, 'cantidad' => $c];
    }
    $g['items'] = $items;

    if (mb_strlen($g['dest_nombre']) < 3) return $mal('Falta el nombre del destinatario.');
    if (!preg_match('/^\d{8}$|^\d{11}$|^\d{9,12}$/', $g['dest_doc'])) return $mal('El documento del destinatario no parece bien escrito.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['fecha_traslado']) || !strtotime($g['fecha_traslado'])
        || $g['fecha_traslado'] < date('Y-m-d')) return $mal('La fecha del traslado es de hoy en adelante.');
    if (!isset(guia_motivos()[$g['motivo']])) return $mal('Elige el motivo del traslado.');
    if ($g['bultos'] < 1 || $g['bultos'] > 999) return $mal('Los bultos son un número de 1 para arriba.');
    if ($g['peso'] <= 0 || $g['peso'] > 50000) return $mal('Escribe el peso total en kilos.');
    if (!preg_match('/^\d{11}$/', $g['transp_ruc'])) return $mal('Falta el RUC de la agencia que lo lleva (11 números).');
    if (mb_strlen($g['transp_nombre']) < 3) return $mal('Falta la razón social de la agencia que lo lleva.');
    if (!preg_match('/^\d{6}$/', $g['partida_ubigeo'])) return $mal('El ubigeo del punto de partida son 6 números.');
    if (mb_strlen($g['partida_dir']) < 5) return $mal('Falta la dirección del punto de partida.');
    if (!preg_match('/^\d{6}$/', $g['llegada_ubigeo'])) return $mal('El ubigeo del punto de llegada son 6 números.');
    if (mb_strlen($g['llegada_dir']) < 5) return $mal('Falta la dirección del punto de llegada.');
    if (!$items) return $mal('La guía no lleva ningún producto.');
    return ['ok' => true, 'error' => '', 'g' => $g];
}

/** El JSON de «generar_guia» para NUBEFACT. */
function guia_datos(array $p, array $g, string $serie, int $numero): array
{
    $f = fn(string $ymd) => date('d-m-Y', (int) strtotime($ymd));
    $d = [
        'operacion'                         => 'generar_guia',
        'tipo_de_comprobante'               => 7,
        'serie'                             => $serie,
        'numero'                            => $numero,
        'cliente_tipo_de_documento'         => nubefact_tipo_doc((string)$g['dest_tipo_doc']),
        'cliente_numero_de_documento'       => $g['dest_doc'],
        'cliente_denominacion'              => $g['dest_nombre'],
        'cliente_direccion'                 => $g['llegada_dir'],
        'cliente_email'                     => (string)$g['dest_email'],
        'fecha_de_emision'                  => date('d-m-Y'),
        'observaciones'                     => $g['observaciones'],
        'motivo_de_traslado'                => $g['motivo'],
        'peso_bruto_total'                  => number_format((float)$g['peso'], 3, '.', ''),
        'peso_bruto_unidad_de_medida'       => 'KGM',
        'numero_de_bultos'                  => (string)(int)$g['bultos'],
        'tipo_de_transporte'                => '01',              // público: lo lleva la agencia
        'fecha_de_inicio_de_traslado'       => $f($g['fecha_traslado']),
        'transportista_documento_tipo'      => '6',
        'transportista_documento_numero'    => $g['transp_ruc'],
        'transportista_denominacion'        => $g['transp_nombre'],
        'punto_de_partida_ubigeo'           => $g['partida_ubigeo'],
        'punto_de_partida_direccion'        => $g['partida_dir'],
        'punto_de_llegada_ubigeo'           => $g['llegada_ubigeo'],
        'punto_de_llegada_direccion'        => $g['llegada_dir'],
        'enviar_automaticamente_al_cliente' => false,
        'formato_de_pdf'                    => 'A4',
        'items'                             => array_map(fn($i) => ['unidad_de_medida' => 'NIU', 'codigo' => (string)$i['codigo'],
                                                                     'descripcion' => $i['descripcion'], 'cantidad' => (string)(int)$i['cantidad']], $g['items']),
    ];
    if (!empty($g['doc_rel'])) $d['documento_relacionado'] = [$g['doc_rel'] + ['numero' => (int)$g['doc_rel']['numero']]];
    return $d;
}

/**
 * EMITE LA GUÍA con lo que quedó en la vista previa. Lo cambiado respecto de
 * lo que propuso el HUB queda en la bitácora. → ['ok', 'error', 'cpe'?]
 */
function guia_emitir(int $pedido_id, array $post): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!guia_activa()) return $mal('Falta la serie de la guía en Configuración › Facturación electrónica.');
    $p = pedido_de($pedido_id);
    if (!$p) return $mal('Ese pedido no existe.');
    if (!guia_aplica($p)) return $mal('Este pedido no lleva guía de remisión: no es un envío a provincia que ya salió.');
    $borrador = guia_borrador($p);
    $l = guia_leer_formulario($post, $borrador);
    if (!$l['ok']) return $mal($l['error']);
    $g = $l['g'];
    $cfg = nubefact_config();

    $prep = en_transaccion(function () use ($pedido_id, $cfg, $mal) {
        bloquear_fila('pedidos', $pedido_id);
        if (guia_de_pedido($pedido_id)) return $mal('Este pedido ya tiene su guía de remisión.');
        $pend = guia_pendiente($pedido_id);
        if ($pend) {
            if (nubefact_en_marcha($pend)) return $mal('La guía se está emitiendo ahora mismo. Espera unos segundos.');
            actualizar('comprobantes_electronicos', (int)$pend['id'], ['estado' => 'enviando', 'enviado_en' => date('Y-m-d H:i:s')]);
            return ['ok' => true, 'id' => (int)$pend['id'], 'serie' => (string)$pend['serie'], 'numero' => (int)$pend['numero'],
                    'reintento' => true, 'datos' => json_decode((string)($pend['datos_json'] ?? ''), true)];
        }
        $res = nubefact_reservar($pedido_id, $cfg['ambiente'], $cfg['serie_guia'], 7, 0);
        return ['ok' => true, 'id' => $res['id'], 'serie' => $cfg['serie_guia'], 'numero' => $res['numero'], 'reintento' => false];
    });
    if (!$prep['ok']) return $prep;

    /* Un reintento manda LO MISMO que se mandó: con ese número puede existir allá. */
    $datos = is_array($prep['datos'] ?? null) && !empty($prep['datos']['items'])
           ? $prep['datos'] : guia_datos($p, $g, $prep['serie'], $prep['numero']);
    $r = nubefact_enviar_reservado((int)$prep['id'], $datos, $cfg, $prep['reintento']);
    if (!$r['ok']) return $r;

    /* Lo aprendido para la próxima: el transportista de esa agencia y el
       código de un distrito que no estaba en la lista. */
    if (!empty($p['agencia_item_id'])) {
        guardar_ajuste('guia_transportista_' . (int)$p['agencia_item_id'], json_encode(['ruc' => $g['transp_ruc'], 'nombre' => $g['transp_nombre']], JSON_UNESCAPED_UNICODE));
        ajustes_olvidar();
    }
    if (!empty($p['ubigeo_id']) && columna_existe('ubigeo', 'codigo_inei') && $g['llegada_ubigeo'] !== $borrador['llegada_ubigeo']) {
        q('UPDATE ubigeo SET codigo_inei = ? WHERE id = ?', [$g['llegada_ubigeo'], (int)$p['ubigeo_id']]);
    }
    $cambios = [];
    foreach (['dest_nombre', 'dest_doc', 'fecha_traslado', 'motivo', 'bultos', 'peso', 'transp_ruc', 'transp_nombre',
              'partida_ubigeo', 'partida_dir', 'llegada_ubigeo', 'llegada_dir', 'observaciones'] as $k) {
        if ((string)$g[$k] !== (string)$borrador[$k]) $cambios[$k] = ['de' => (string)$borrador[$k], 'a' => (string)$g[$k]];
    }
    if (json_encode($g['items']) !== json_encode($borrador['items'])) $cambios['productos'] = 'corregidos a mano';
    pedido_evento($pedido_id, 'guia', 'Se emitió la guía de remisión ' . $prep['serie'] . '-' . $prep['numero']
        . ($cambios ? ' (con ' . plural(count($cambios), 'dato corregido', 'datos corregidos') . ')' : '')
        . ($cfg['ambiente'] === 'prueba' ? ' · prueba' : ''));
    bitacora('pedido.guia', 'pedido', $pedido_id, ['guia' => $prep['serie'] . '-' . $prep['numero'], 'cambios' => $cambios]);
    return $r;
}

/** Vuelve a preguntar a NUBEFACT por una guía (el PDF puede tardar en estar). */
function guia_consultar(array $cpe): array
{
    $cfg = nubefact_config((string)$cpe['ambiente']);
    $q = nubefact_llamar(['operacion' => 'consultar_guia', 'tipo_de_comprobante' => 7,
                          'serie' => (string)$cpe['serie'], 'numero' => (int)$cpe['numero']], $cfg);
    if (($q['red'] ?? '') !== '' || isset($q['cuerpo']['errors'])) return ['ok' => false, 'error' => nubefact_error_texto($q)];
    return nubefact_anotar_emitido((int)$cpe['id'], $q['cuerpo'] ?? []);
}
