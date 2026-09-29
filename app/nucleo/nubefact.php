<?php
declare(strict_types=1);

/**
 * BOLETAS, FACTURAS Y NOTAS DE CRÉDITO CON NUBEFACT (parche 2u).
 *
 * Lo pidió el usuario el 2026-09-21, con estas decisiones:
 *   · Facturación pulsa «Emitir»: el HUB arma el comprobante con los datos de
 *     la venta y NUBEFACT lo firma, lo manda a SUNAT y devuelve el PDF.
 *   · Se puede emitir en cuanto hay ALGÚN pago confirmado (pre venta con
 *     adelanto, Lima contraentrega), igual que hasta ahora a mano.
 *   · El Cashback Waka usado va como DESCUENTO: el comprobante dice lo que el
 *     cliente pagó de su bolsillo.
 *   · NUBEFACT le manda el PDF al correo del cliente. Formato A4.
 *   · Anular una venta con comprobante emite su NOTA DE CRÉDITO, y es todo o
 *     nada: si la nota no sale, la venta no se anula.
 *
 * La especificación es la oficial de NUBEFACT (JSON, «generar_comprobante» y
 * «consultar_comprobante»). El token y la ruta los pega Administración en
 * Configuración › Facturación electrónica; nunca viajan por el chat ni se
 * escriben en el código.
 *
 * LO MÁS DELICADO ES EL NÚMERO. NUBEFACT no numera: el número lo pone quien
 * emite. Por eso:
 *   1. El número se RESERVA en una transacción corta y queda anotado en
 *      `comprobantes_electronicos` con estado «enviando» antes de llamar.
 *   2. Si NUBEFACT contesta con un error de validación, el comprobante no
 *      existe allá: la fila pasa a «descartado» y el número se reusa en la
 *      siguiente emisión de esa serie, para no dejar huecos.
 *   3. Si la llamada se corta sin respuesta, no se sabe si allá existe: la fila
 *      queda «incierto», y el siguiente intento de la MISMA venta reenvía el
 *      MISMO número. Si NUBEFACT dice que ya existe (código 23), se consulta y
 *      se adopta. Así un corte de red nunca produce dos boletas por una venta.
 */

/* ───────────────────────── Configuración ───────────────────────── */

/** Los dos ambientes que existen. */
function nubefact_ambientes(): array
{
    return ['prueba' => 'Prueba', 'produccion' => 'Producción'];
}

function nubefact_ambiente(): string
{
    $a = (string) ajuste('nubefact_ambiente', 'prueba');
    return isset(nubefact_ambientes()[$a]) ? $a : 'prueba';
}

/** La configuración de un ambiente (por defecto, el que está en uso). */
function nubefact_config(?string $amb = null): array
{
    $amb ??= nubefact_ambiente();
    $k = fn(string $c) => trim((string) ajuste('nubefact_' . $amb . '_' . $c, ''));
    $cfg = [
        'ambiente'         => $amb,
        'ruta'             => $k('ruta'),
        'token'            => $k('token'),
        'serie_boleta'     => mb_strtoupper($k('serie_boleta')),
        'serie_factura'    => mb_strtoupper($k('serie_factura')),
        'serie_nc_boleta'  => mb_strtoupper($k('serie_nc_boleta')),
        'serie_nc_factura' => mb_strtoupper($k('serie_nc_factura')),
        /* 5b · la guía de remisión remitente (empieza con T). No hace falta
           para emitir boletas: sin ella, solo no sale el botón de la guía. */
        'serie_guia'       => mb_strtoupper($k('serie_guia')),
    ];
    $cfg['listo'] = $cfg['ruta'] !== '' && $cfg['token'] !== ''
                 && nubefact_serie_valida($cfg['serie_boleta'], 'B')
                 && nubefact_serie_valida($cfg['serie_factura'], 'F')
                 /* Y las de nota de crédito: sin ellas, anular una venta
                    facturada fallaría el día que haga falta. */
                 && nubefact_serie_valida($cfg['serie_nc_boleta'], 'B')
                 && nubefact_serie_valida($cfg['serie_nc_factura'], 'F');
    return $cfg;
}

/**
 * ¿Se emite desde el HUB? Solo con la configuración completa Y con las tablas
 * del parche: sin ellas, la pantalla de facturar sigue con el formulario de
 * siempre, para anotar un comprobante emitido a mano.
 */
function nubefact_activo(): bool
{
    if (!tabla_existe('comprobantes_electronicos') || !tabla_existe('nubefact_correlativos')) return false;
    return nubefact_config()['listo'];
}

/**
 * Una serie de SUNAT: cuatro caracteres, la primera F (facturas y sus notas) o
 * B (boletas y las suyas). NUBEFACT la rechaza si no, así que se dice antes.
 */
function nubefact_serie_valida(string $serie, string $inicial): bool
{
    return (bool) preg_match('/^' . $inicial . '[A-Z0-9]{3}$/', $serie);
}

/** El token enseñado sin enseñarlo: «•••• 3f9a». */
function nubefact_token_tapado(string $token): string
{
    return llave_tapada($token);
}

/* ───────────────────────── La llamada ───────────────────────── */

/**
 * Cómo se habla con NUBEFACT. Por defecto, cURL.
 *
 * Es una función aparte, y se puede sustituir con `$GLOBALS['__nubefact_transporte']`,
 * por la misma razón que existe `$GLOBALS['__pdo_instalacion']`: el banco de
 * pruebas no puede llamar a NUBEFACT de verdad cada vez que corre, y tiene que
 * poder simular un corte de red o una respuesta de error.
 *
 * Devuelve ['http' => int, 'cuerpo' => ?array, 'red' => string]. `red` no vacío
 * significa que NO HUBO RESPUESTA: no se sabe qué pasó allá.
 */
function nubefact_transporte(): callable
{
    if (isset($GLOBALS['__nubefact_transporte']) && is_callable($GLOBALS['__nubefact_transporte'])) {
        return $GLOBALS['__nubefact_transporte'];
    }
    return function (string $ruta, string $token, array $datos, string $auth): array {
        if (!function_exists('curl_init')) {
            return ['http' => 0, 'cuerpo' => null, 'red' => 'Este servidor no tiene cURL.'];
        }
        $cabecera = $auth === 'token' ? 'Authorization: Token token="' . $token . '"'
                                      : 'Authorization: ' . $token;
        $ch = curl_init($ruta);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($datos, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [$cabecera, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 40,
        ]);
        $txt  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($txt === false || $txt === '') {
            return ['http' => $http, 'cuerpo' => null, 'red' => $err !== '' ? $err : 'Sin respuesta'];
        }
        $j = json_decode((string)$txt, true);
        if (!is_array($j)) {
            return ['http' => $http, 'cuerpo' => null, 'red' => 'Respuesta que no se entiende'];
        }
        return ['http' => $http, 'cuerpo' => $j, 'red' => ''];
    };
}

/** Llama a NUBEFACT con la configuración del ambiente en uso. */
function nubefact_llamar(array $datos, ?array $cfg = null): array
{
    $cfg ??= nubefact_config();
    $auth = (string) ajuste('nubefact_auth', 'directo') === 'token' ? 'token' : 'directo';
    return (nubefact_transporte())($cfg['ruta'], $cfg['token'], $datos, $auth);
}

/** El error de NUBEFACT dicho para facturación, no para un programador. */
function nubefact_error_texto(array $r): string
{
    if (($r['red'] ?? '') !== '') {
                return 'No hubo respuesta de NUBEFACT. Vuelve a intentarlo en un momento: '
             . 'antes de emitir otra vez se comprueba si llegó, así que no se duplica.';
    }
    $c = (int)($r['cuerpo']['codigo'] ?? 0);
    $msg = trim((string)($r['cuerpo']['errors'] ?? ''));
    $porque = match ($c) {
        10 => 'El token de NUBEFACT no es válido. Revísalo en Configuración › Facturación electrónica.',
        11 => 'La ruta de NUBEFACT no es válida. Revísala en Configuración › Facturación electrónica.',
        22 => 'NUBEFACT dice que el comprobante se envió fuera de plazo.',
        23 => 'Ese número de comprobante ya existe en NUBEFACT.',
        24 => 'Ese comprobante no existe en NUBEFACT.',
        50, 51 => 'La cuenta de NUBEFACT está suspendida.',
        default => 'NUBEFACT no lo aceptó.',
    };
    return $porque . ($msg !== '' && !in_array($c, [10, 11, 50, 51], true) ? ' «' . $msg . '»' : '');
}

/* ───────────────────────── Los importes ───────────────────────── */

/** Céntimos a «123.45», que es como los quiere NUBEFACT. */
function nubefact_monto(int $centimos): string
{
    return number_format($centimos / 100, 2, '.', '');
}

/**
 * LAS LÍNEAS Y LOS TOTALES DEL COMPROBANTE, a partir de la venta.
 *
 * Función pura —no toca la base— para poder probar la aritmética sola.
 * $lineas: [['descripcion','cantidad','precio_unit_centimos','total_centimos', 'sku'?, 'modelo'?]].
 * $flete: lo que va DENTRO del total (0 si lo paga el cliente en destino).
 * $descuento: lo que se descuenta del total, CON IGV: el cashback usado y el
 * descuento del pedido.
 *
 * Los precios del HUB ya incluyen IGV (ajuste `precios_incluyen_igv`). La base
 * de cada línea sale de desglose_igv() —la MISMA función que usa la pantalla de
 * facturar— y el IGV es la diferencia, así que base + IGV es el total exacto
 * al céntimo, línea a línea. El total del comprobante termina siendo el total
 * del pedido, que es lo que el cliente paga.
 */
function nubefact_items(array $lineas, int $flete, int $descuento, int $pct_igv = 18): array
{
    $items = [];
    $sum_base = 0; $sum_igv = 0; $sum_total = 0;

    $agregar = function (string $desc, string $codigo, float|int $cant, int $total, string $unidad)
               use (&$items, &$sum_base, &$sum_igv, &$sum_total, $pct_igv) {
        $d = desglose_igv($total, $pct_igv, true);
        $cant = max(1, (int)$cant);
        $items[] = [
            'unidad_de_medida' => $unidad,
            'codigo'           => mb_substr($codigo, 0, 250),
            'descripcion'      => mb_substr($desc, 0, 250),
            'cantidad'         => $cant,
            /* Sin redondear a dos decimales: NUBEFACT admite diez, y cortar el
               unitario desviaría la base de una línea de varias unidades. */
            'valor_unitario'   => rtrim(rtrim(number_format($d['base'] / 100 / $cant, 10, '.', ''), '0'), '.'),
            'precio_unitario'  => rtrim(rtrim(number_format($total / 100 / $cant, 10, '.', ''), '0'), '.'),
            'descuento'        => '',
            'subtotal'         => nubefact_monto($d['base']),
            'tipo_de_igv'      => 1,                    // gravado, operación onerosa
            'igv'              => nubefact_monto($d['igv']),
            'total'            => nubefact_monto($total),
            'anticipo_regularizacion' => false,
        ];
        $sum_base += $d['base']; $sum_igv += $d['igv']; $sum_total += $total;
    };

    foreach ($lineas as $l) {
        $desc = trim((string)$l['descripcion']);
        $mod  = trim((string)($l['modelo'] ?? ''));
        if ($mod !== '') $desc .= ' - ' . $mod;
        $agregar($desc, (string)($l['sku'] ?? ''), (int)$l['cantidad'], (int)$l['total_centimos'], 'NIU');
    }
    if ($flete > 0) $agregar('SERVICIO DE ENVÍO', '', 1, $flete, 'ZZ');

    /* EL DESCUENTO GLOBAL VA SIN IGV (así lo pide NUBEFACT): se parte con la
       misma función, y los totales se calculan restando, para que base + IGV
       siga siendo el total exacto. */
    $descuento = max(0, min($descuento, $sum_total));
    $d_desc = desglose_igv($descuento, $pct_igv, true);

    $total   = $sum_total - $descuento;
    $gravada = $sum_base - $d_desc['base'];
    return [
        'items'            => $items,
        'descuento_global' => $d_desc['base'],
        'total_gravada'    => $gravada,
        'total_igv'        => $total - $gravada,
        'total'            => $total,
    ];
}

/** El tipo de documento del cliente, en el código de SUNAT. */
function nubefact_tipo_doc(string $tipo_doc): string
{
    return match (mb_strtoupper(trim($tipo_doc))) {
        'RUC' => '6',
        'DNI' => '1',
        'CE'  => '4',
        'PAS' => '7',
        default => '-',
    };
}

/**
 * EL CLIENTE TAL COMO VA EN EL COMPROBANTE, o el motivo por el que no puede ir.
 * Devuelve ['ok', 'error', 'tipo_doc', 'doc', 'nombre', 'direccion', 'email'].
 */
function nubefact_cliente(array $cli, string $tipo, array $p): array
{
    $nombre = trim((string)$cli['nombre'] . ' ' . (string)($cli['apellidos'] ?? ''));
    $email  = trim((string)($cli['email'] ?? ''));

    if ($tipo === 'factura') {
        $ruc = trim((string)($cli['ruc_factura'] ?? ''));
        if ($ruc === '' && (string)($cli['tipo_doc'] ?? '') === 'RUC') $ruc = trim((string)$cli['documento']);
        if (!preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) {
            return ['ok' => false, 'error' => 'Este cliente no tiene un RUC válido en su ficha. '
                                              . 'Una factura sin RUC no se puede emitir.'];
        }
        $razon = trim((string)($cli['razon_social'] ?? ''));
        if ($razon === '') $razon = $nombre;
        $dir = trim((string)($cli['direccion_fiscal'] ?? ''));
        if ($dir === '') {
            return ['ok' => false, 'error' => 'Falta la dirección fiscal del cliente. Complétala en su ficha '
                                              . '(datos de factura) y vuelve a emitir.'];
        }
        return ['ok' => true, 'error' => '', 'tipo_doc' => '6', 'doc' => $ruc,
                'nombre' => mb_substr($razon, 0, 100), 'direccion' => mb_substr($dir, 0, 100),
                'email' => $email];
    }

    /* Boleta: el documento de la persona. La dirección no es obligatoria en
       una boleta; se manda la del pedido si la hay y un guion si no. */
    $dir = trim((string)($p['direccion_txt'] ?? ''));
    return ['ok' => true, 'error' => '', 'tipo_doc' => nubefact_tipo_doc((string)$cli['tipo_doc']),
            'doc' => trim((string)$cli['documento']), 'nombre' => mb_substr($nombre, 0, 100),
            'direccion' => mb_substr($dir !== '' ? $dir : '-', 0, 100), 'email' => $email];
}

/** El JSON de «generar_comprobante» para una boleta, factura o nota de crédito. */
function nubefact_datos(array $p, array $cliente_cpe, int $tipo_cpe, string $serie, int $numero,
                        array $montos, array $extra = []): array
{
    return array_merge([
        'operacion'                   => 'generar_comprobante',
        'tipo_de_comprobante'         => $tipo_cpe,
        'serie'                       => $serie,
        'numero'                      => $numero,
        'sunat_transaction'           => 1,
        'cliente_tipo_de_documento'   => $cliente_cpe['tipo_doc'],
        'cliente_numero_de_documento' => $cliente_cpe['doc'],
        'cliente_denominacion'        => $cliente_cpe['nombre'],
        'cliente_direccion'           => $cliente_cpe['direccion'],
        'cliente_email'               => $cliente_cpe['email'],
        'fecha_de_emision'            => date('d-m-Y'),
        'moneda'                      => 1,
        'porcentaje_de_igv'           => number_format(max(0, (int) ajuste('igv_porcentaje', 18)), 2, '.', ''),
        'descuento_global'            => $montos['descuento_global'] > 0 ? nubefact_monto($montos['descuento_global']) : '',
        'total_descuento'             => $montos['descuento_global'] > 0 ? nubefact_monto($montos['descuento_global']) : '',
        'total_gravada'               => nubefact_monto($montos['total_gravada']),
        'total_igv'                   => nubefact_monto($montos['total_igv']),
        'total'                       => nubefact_monto($montos['total']),
        'observaciones'               => 'Pedido ' . (string)$p['codigo'],
        'enviar_automaticamente_a_la_sunat' => true,
        /* El PDF al correo del cliente (usuario, 2026-09-21). Todos los
           clientes tienen correo: es obligatorio en su ficha. */
        'enviar_automaticamente_al_cliente' => $cliente_cpe['email'] !== '',
        'codigo_unico'                => mb_substr('WAKA-' . (string)$p['codigo'] . '-' . $tipo_cpe, 0, 20),
        'formato_de_pdf'              => 'A4',
        'items'                       => $montos['items'],
    ], $extra);
}

/* ───────────────────────── Reservar el número ───────────────────────── */

/** La fila del correlativo de una serie; la crea en 1 si no existía. */
function nubefact_correlativo(string $amb, int $tipo_cpe, string $serie): array
{
    $sql = 'SELECT id, siguiente FROM nubefact_correlativos WHERE ambiente = ? AND tipo_cpe = ? AND serie = ?';
    $fila = una($sql, [$amb, $tipo_cpe, $serie]);
    if (!$fila) {
        insertar('nubefact_correlativos', ['ambiente' => $amb, 'tipo_cpe' => $tipo_cpe,
                                           'serie' => $serie, 'siguiente' => 1]);
        $fila = una($sql, [$amb, $tipo_cpe, $serie]);
    }
    return $fila;
}

/**
 * El número que le toca a una serie, y su fila «enviando», en una sola
 * transacción. Primero se reusa un número DESCARTADO de esa serie (uno que
 * NUBEFACT rechazó y por tanto no existe allá): así no quedan huecos.
 */
function nubefact_reservar(int $pedido_id, string $amb, string $serie, int $tipo_cpe,
                           int $total, ?int $modifica_id = null): array
{
    return en_transaccion(function () use ($pedido_id, $amb, $serie, $tipo_cpe, $total, $modifica_id) {
                $fila = nubefact_correlativo($amb, $tipo_cpe, $serie);
        bloquear_fila('nubefact_correlativos', (int)$fila['id']);

                $libre = una("SELECT id, numero FROM comprobantes_electronicos
                       WHERE ambiente = ? AND tipo_cpe = ? AND serie = ? AND estado = 'descartado'
                       ORDER BY numero LIMIT 1", [$amb, $tipo_cpe, $serie]);
        if ($libre) {
            $numero = (int)$libre['numero'];
            actualizar('comprobantes_electronicos', (int)$libre['id'], [
                'pedido_id' => $pedido_id, 'tipo_cpe' => $tipo_cpe, 'estado' => 'enviando',
                'enviado_en' => date('Y-m-d H:i:s'), 'datos_json' => null,
                'modifica_id' => $modifica_id, 'total_centimos' => $total,
                'codigo_error' => null, 'error_texto' => null,
                'emitido_por' => $_SESSION['usuario_id'] ?? null,
            ]);
            return ['id' => (int)$libre['id'], 'numero' => $numero];
        }

        $numero = (int) valor('SELECT siguiente FROM nubefact_correlativos WHERE id = ?', [(int)$fila['id']]);
        q('UPDATE nubefact_correlativos SET siguiente = siguiente + 1 WHERE id = ?', [(int)$fila['id']]);
        $id = insertar('comprobantes_electronicos', [
            'pedido_id' => $pedido_id, 'ambiente' => $amb, 'tipo_cpe' => $tipo_cpe,
            'serie' => $serie, 'numero' => $numero, 'modifica_id' => $modifica_id,
            'estado' => 'enviando', 'total_centimos' => $total, 'enviado_en' => date('Y-m-d H:i:s'),
            'emitido_por' => $_SESSION['usuario_id'] ?? null,
        ]);
        return ['id' => $id, 'numero' => $numero];
    });
}

/**
 * Manda un comprobante ya reservado y anota lo que pasó. Si NUBEFACT dice que
 * el número ya existe —un intento anterior sí llegó—, se consulta y se adopta.
 */
function nubefact_enviar_reservado(int $cpe_id, array $datos, ?array $cfg = null, bool $reintento = false): array
{
    /* Lo que se manda queda guardado: la nota de crédito copia EXACTAMENTE
       esto, aunque después cambien el IGV, el cliente o el pedido. */
    actualizar('comprobantes_electronicos', $cpe_id, [
        'datos_json' => json_encode($datos, JSON_UNESCAPED_UNICODE),
        'enviado_en' => date('Y-m-d H:i:s'),
    ]);
    $r = nubefact_llamar($datos, $cfg);

    /* Una respuesta sin «errors» pero sin el comprobante (un 500 de su lado,
       una página de mantenimiento) NO es un comprobante emitido: no se sabe
       qué pasó allá, igual que si no hubiera contestado. */
    if (($r['red'] ?? '') === '' && !isset($r['cuerpo']['errors']) && !nubefact_respuesta_buena($r, $datos)) {
        $r['red'] = 'Respuesta sin comprobante (HTTP ' . (int)($r['http'] ?? 0) . ')';
    }

    if (($r['red'] ?? '') !== '') {
        actualizar('comprobantes_electronicos', $cpe_id, ['estado' => 'incierto',
            'error_texto' => mb_substr((string)$r['red'], 0, 500)]);
        return ['ok' => false, 'error' => nubefact_error_texto($r)];
    }

    $c = $r['cuerpo'] ?? [];
    if (isset($c['errors'])) {
        if ((int)($c['codigo'] ?? 0) === 23) {
            /* «YA EXISTE». Solo puede ser nuestro si ESTE número ya se mandó
               antes y no supimos la respuesta. Un número recién reservado que
               ya existe es de otro comprobante —emitido en NUBEFACT a mano—, y
               adoptarlo sería darle a esta venta la boleta de otro cliente. */
            if (!$reintento) return nubefact_numero_ocupado($cpe_id, $datos);
            $q = nubefact_llamar(['operacion' => (int)$datos['tipo_de_comprobante'] === 7 ? 'consultar_guia' : 'consultar_comprobante',
                                  'tipo_de_comprobante' => $datos['tipo_de_comprobante'],
                                  'serie' => $datos['serie'], 'numero' => $datos['numero']], $cfg);
            if (($q['red'] ?? '') === '' && !isset($q['cuerpo']['errors'])) {
                if (!nubefact_es_el_mismo($q['cuerpo'] ?? [], $datos)) {
                    return nubefact_numero_ocupado($cpe_id, $datos);
                }
                return nubefact_anotar_emitido($cpe_id, $q['cuerpo']);
            }
            actualizar('comprobantes_electronicos', $cpe_id, ['estado' => 'incierto',
                'error_texto' => 'Ya existía en NUBEFACT y no se pudo consultar']);
            return ['ok' => false, 'error' => nubefact_error_texto($q)];
        }
        /* NUBEFACT lo rechazó: allá no existe. El número queda libre para el
           siguiente comprobante de esa serie. */
        actualizar('comprobantes_electronicos', $cpe_id, [
            'estado' => 'descartado', 'codigo_error' => (int)($c['codigo'] ?? 0),
            'error_texto' => mb_substr((string)$c['errors'], 0, 500)]);
        return ['ok' => false, 'error' => nubefact_error_texto($r)];
    }
    return nubefact_anotar_emitido($cpe_id, $c);
}

/** ¿La respuesta trae de verdad el comprobante que se mandó? */
function nubefact_respuesta_buena(array $r, array $datos): bool
{
    $http = (int)($r['http'] ?? 0);
    if ($http !== 0 && ($http < 200 || $http > 299)) return false;
    $c = $r['cuerpo'] ?? [];
    if (!is_array($c)) return false;
    if (!empty($c['enlace']) || !empty($c['enlace_del_pdf'])) return true;
    return (string)($c['serie'] ?? '') === (string)$datos['serie']
        && (int)($c['numero'] ?? 0) === (int)$datos['numero'];
}

/**
 * ¿El comprobante que NUBEFACT tiene con ese número es el que mandamos?
 * Se mira en la cadena del QR (RUC|tipo|serie|número|IGV|total|fecha|tipo
 * doc|doc cliente|…): mismo total y mismo documento del cliente. Si NUBEFACT
 * no la manda, se acepta — el número se reservó para esta venta y se envió
 * antes, que es lo que ya se comprobó para llegar aquí.
 */
function nubefact_es_el_mismo(array $c, array $datos): bool
{
    $qr = trim((string)($c['cadena_para_codigo_qr'] ?? ''));
    if ($qr === '' || !isset($datos['total'])) return true;   // la guía no lleva total
    $x = explode('|', $qr);
    if (count($x) < 9) return true;
    $total_ok = abs((float)$x[5] - (float)$datos['total']) < 0.005;
    $doc_ok   = trim($x[8]) === trim((string)$datos['cliente_numero_de_documento']);
    return $total_ok && $doc_ok;
}

/**
 * El número ya lo usa OTRO comprobante en NUBEFACT. La fila queda como
 * «conflicto»: no es de esta venta, no cuenta como pendiente ni se vuelve a
 * ofrecer (una «descartada» sí se reusaría), y queda la constancia. La serie
 * ya quedó en el siguiente al reservarlo.
 */
function nubefact_numero_ocupado(int $cpe_id, array $datos): array
{
    actualizar('comprobantes_electronicos', $cpe_id, ['estado' => 'conflicto', 'codigo_error' => 23,
        'error_texto' => 'El número ya lo usa otro comprobante en NUBEFACT']);
    return ['ok' => false, 'error' => 'El número ' . $datos['serie'] . '-' . (int)$datos['numero']
        . ' ya está usado en NUBEFACT por otro comprobante. En Configuración › Facturación electrónica, '
        . 'pon como próximo número el que sigue al último que emitiste allá, y vuelve a emitir.'];
}

/** Guarda la respuesta buena: enlaces, aceptación de SUNAT. */
function nubefact_anotar_emitido(int $cpe_id, array $c): array
{
    actualizar('comprobantes_electronicos', $cpe_id, [
        'estado'            => 'emitido',
        'enlace_pdf'        => mb_substr((string)($c['enlace_del_pdf'] ?? ''), 0, 255) ?: null,
        'enlace_xml'        => mb_substr((string)($c['enlace_del_xml'] ?? ''), 0, 255) ?: null,
        'enlace_cdr'        => mb_substr((string)($c['enlace_del_cdr'] ?? ''), 0, 255) ?: null,
        'aceptada_sunat'    => array_key_exists('aceptada_por_sunat', $c) ? (!empty($c['aceptada_por_sunat']) ? 1 : 0) : null,
        'sunat_descripcion' => mb_substr((string)($c['sunat_description'] ?? ''), 0, 255) ?: null,
        'codigo_error'      => null,
        'error_texto'       => null,
    ]);
    return ['ok' => true, 'error' => '', 'cpe' => una('SELECT * FROM comprobantes_electronicos WHERE id = ?', [$cpe_id])];
}

/* ───────────────────────── Lo que se pregunta ───────────────────────── */

/**
 * La boleta o factura VIGENTE de un pedido: emitida y sin nota de crédito que
 * la anule. Null si no hay.
 */
function nubefact_vigente(int $pedido_id): ?array
{
    if (!tabla_existe('comprobantes_electronicos')) return null;
    /* Las de PRODUCCIÓN cuentan siempre; las de prueba, solo mientras se está
       en prueba. Una de prueba no fue a SUNAT y no puede tapar la real; una
       real no deja de existir porque alguien vuelva a poner el modo prueba. */
    $f = una("SELECT c.* FROM comprobantes_electronicos c
               WHERE c.pedido_id = ? AND c.tipo_cpe IN (1, 2) AND c.estado = 'emitido'
                 AND (c.ambiente = 'produccion' OR c.ambiente = ?)
                 AND NOT EXISTS (SELECT 1 FROM comprobantes_electronicos n
                                  WHERE n.modifica_id = c.id AND n.tipo_cpe = 3
                                    AND n.estado = 'emitido')
               ORDER BY c.id DESC LIMIT 1", [$pedido_id, nubefact_ambiente()]);
    return $f ?: null;
}

/**
 * Una boleta o factura que se mandó y de la que NO SE SABE si salió
 * («incierto», o «enviando» ahora mismo). Mientras la haya, la venta no se
 * anula ni se anota a mano: puede estar viva en SUNAT.
 */
function nubefact_pendiente(int $pedido_id): ?array
{
    if (!tabla_existe('comprobantes_electronicos')) return null;
    $f = una("SELECT * FROM comprobantes_electronicos
               WHERE pedido_id = ? AND tipo_cpe IN (1, 2) AND estado IN ('incierto', 'enviando')
                 AND (ambiente = 'produccion' OR ambiente = ?) ORDER BY id DESC LIMIT 1", [$pedido_id, nubefact_ambiente()]);
    return $f ?: null;
}

/** ¿Hay un envío en marcha ahora mismo? (menos de dos minutos) */
function nubefact_en_marcha(array $cpe): bool
{
    return (string)$cpe['estado'] === 'enviando'
        && !empty($cpe['enviado_en']) && strtotime((string)$cpe['enviado_en']) > time() - 120;
}

/** Lo que pregunta el botón EMITIR antes de mandar. Una sola definición para las dos pantallas. */
function nubefact_confirmar_texto(string $nombre, int $total): string
{
    return 'Emitir ' . mb_strtolower($nombre) . ' por ' . soles($total) . '. '
         . (nubefact_ambiente() === 'produccion' ? 'Va a SUNAT.' : 'Es de prueba: no va a SUNAT.') . ' ¿Seguimos?';
}

/** Todos los comprobantes electrónicos de un pedido, para la ficha. */
function nubefact_del_pedido(int $pedido_id): array
{
    if (!tabla_existe('comprobantes_electronicos')) return [];
    return todas("SELECT * FROM comprobantes_electronicos
                   WHERE pedido_id = ? AND estado = 'emitido' ORDER BY id", [$pedido_id]);
}

/** «Boleta B001-123», «Nota de crédito BC01-4». */
function nubefact_nombre(array $cpe): string
{
    $t = match ((int)$cpe['tipo_cpe']) { 1 => 'Factura', 2 => 'Boleta', 3 => 'Nota de crédito', 7 => 'Guía de remisión', default => 'Comprobante' };
    return $t . ' ' . $cpe['serie'] . '-' . (int)$cpe['numero'];
}

/* ───────────────────────── Emitir ───────────────────────── */

/**
 * EMITE LA BOLETA O LA FACTURA DE UNA VENTA.
 *
 * $tipo: 'boleta' o 'factura'. Devuelve ['ok', 'error', 'cpe'?].
 */
function nubefact_emitir(int $pedido_id, string $tipo, array $ed = []): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!in_array($tipo, ['boleta', 'factura'], true)) return $mal('Elige boleta o factura.');
    if (!nubefact_activo()) {
        return $mal('La facturación electrónica no está configurada. Administración la configura en '
                  . 'Configuración › Facturación electrónica.');
    }

    $p = pedido_de($pedido_id);
    if (!$p) return $mal('Ese pedido no existe.');
    /* Si se puede emitir o no se mira UNA vez, con la venta ya reservada, más
       abajo: entre pintar la pantalla y pulsar el botón se puede devolver un
       pago o anular la venta. */
    $cfg = nubefact_config();
    $cli = una('SELECT * FROM clientes WHERE id = ?', [(int)$p['cliente_id']]);
    if (!$cli) return $mal('El cliente de esta venta no existe.');
    /* Con uno pendiente de otro tipo, lo primero es decir eso: pedirle un RUC
       para una factura que no se va a emitir mandaría a arreglar lo que no es. */
    $pend0 = nubefact_pendiente($pedido_id);
    if ($pend0 && ((int)$pend0['tipo_cpe'] === 1 ? 'factura' : 'boleta') !== $tipo) {
        $tp = (int)$pend0['tipo_cpe'] === 1 ? 'factura' : 'boleta';
        return $mal('Primero hay que terminar la ' . $tp . ' ' . $pend0['serie'] . '-' . (int)$pend0['numero']
                  . ', que se mandó y no tuvo respuesta: pulsa «Emitir ' . $tp . '».');
    }
    $ccpe = nubefact_cliente($cli, $tipo, $p);
    if (!$ccpe['ok']) return $mal($ccpe['error']);

    $montos = nubefact_items(pedido_lineas($pedido_id), pedido_flete_dentro($p),
                             (int)($p['descuento_centimos'] ?? 0) + (int)$p['cashback_usado_centimos'],
                             max(0, (int) ajuste('igv_porcentaje', 18)));
    if ($montos['total'] <= 0) return $mal('Esta venta suma cero: no hay nada que facturar.');
    /* LO QUE FACTURACIÓN CORRIGIÓ EN LA VISTA PREVIA (5b): el nombre, la
       dirección, el correo, la descripción de cada línea y la observación.
       Los montos NO: el comprobante tiene que decir lo que el cliente pagó. */
    $cambios = [];
    if ($ed) {
        $ap = nubefact_aplicar_ediciones($ccpe, $montos, $ed, 'Pedido ' . (string)$p['codigo']);
        if (!$ap['ok']) return $mal($ap['error']);
        [$ccpe, $montos, $cambios, $obs] = [$ap['cliente'], $ap['montos'], $ap['cambios'], $ap['observaciones']];
    }
    /* El comprobante tiene que decir lo mismo que el pedido. Si algún día el
       total se calcula distinto en los dos sitios, se para aquí y no en SUNAT. */
    if ($montos['total'] !== (int)$p['total_centimos']) {
        return $mal('El total del comprobante (' . soles($montos['total']) . ') no cuadra con el del pedido ('
                  . soles((int)$p['total_centimos']) . '). Avisa a Administración antes de emitir.');
    }
    $tipo_cpe = $tipo === 'factura' ? 1 : 2;

    /* LO QUE DECIDE SI SE EMITE Y CON QUÉ NÚMERO, CON LA VENTA BLOQUEADA. Dos
       clics, dos pestañas o dos personas de facturación a la vez llegan aquí
       en fila: la segunda ve lo que hizo la primera y no reserva otro número
       — ni una factura encima de la boleta. */
    $prep = en_transaccion(function () use ($pedido_id, $tipo, $tipo_cpe, $cfg, $montos, $mal) {
        bloquear_fila('pedidos', $pedido_id);
        $p = pedido_de($pedido_id);
        if (!$p) return $mal('Ese pedido ya no existe.');
        /* La misma regla, ya con la venta reservada: entre que se pintó la
           pantalla y se pulsó pudo anularse o devolverse un pago. */
        if ($bl = pedido_emision_bloqueo($p)) return $mal($bl);
        if ((int)$p['total_centimos'] !== $montos['total']) {
            return $mal('La venta cambió mientras tanto. Vuelve a abrirla y emite otra vez.');
        }
        if (nubefact_vigente($pedido_id)) {
            return $mal('Esta venta ya tiene su comprobante emitido.');
        }
        /* Uno anotado a mano también cuenta: la venta ya tiene comprobante, y
           emitir otro sería declarar dos veces la misma venta. */
        if (in_array((string)($p['comprobante_tipo'] ?? ''), ['boleta', 'factura'], true)) {
            return $mal('Esta venta ya tiene un comprobante anotado a mano. Si hay que cambiarlo, '
                      . 'corrígelo desde «Corregirlo».');
        }

        /* UN INTENTO ANTERIOR SIN RESPUESTA: se reintenta ESE, con su número y
           su tipo. Si está saliendo ahora mismo, se espera. */
        $pend = nubefact_pendiente($pedido_id);
        if ($pend) {
            if (nubefact_en_marcha($pend)) {
                return $mal('Este comprobante se está emitiendo ahora mismo. Espera unos segundos y mira el pedido.');
            }
            if ((string)$pend['ambiente'] !== $cfg['ambiente']) {
                return $mal('La ' . mb_strtolower(nubefact_nombre($pend)) . ' se mandó en producción y no tuvo '
                          . 'respuesta. Vuelve a producción para terminarla.');
            }
            $tipo_pend = (int)$pend['tipo_cpe'] === 1 ? 'factura' : 'boleta';
            if ($tipo_pend !== $tipo) {
                return $mal('Primero hay que terminar la ' . $tipo_pend . ' ' . $pend['serie'] . '-' . (int)$pend['numero']
                          . ', que se mandó y no tuvo respuesta: pulsa «Emitir ' . $tipo_pend . '».');
            }
            actualizar('comprobantes_electronicos', (int)$pend['id'],
                       ['estado' => 'enviando', 'enviado_en' => date('Y-m-d H:i:s')]);
            /* Se reenvía LO MISMO que se mandó, no los datos de hoy: si
               entretanto se corrigió el cliente, lo que NUBEFACT tenga con ese
               número hay que compararlo con lo enviado. */
            return ['ok' => true, 'p' => $p, 'id' => (int)$pend['id'], 'serie' => (string)$pend['serie'],
                    'numero' => (int)$pend['numero'], 'reintento' => true,
                    'datos' => json_decode((string)($pend['datos_json'] ?? ''), true)];
        }
        $serie = $tipo === 'factura' ? $cfg['serie_factura'] : $cfg['serie_boleta'];
        $res = nubefact_reservar($pedido_id, $cfg['ambiente'], $serie, $tipo_cpe, $montos['total']);
        return ['ok' => true, 'p' => $p, 'id' => $res['id'], 'serie' => $serie,
                'numero' => $res['numero'], 'reintento' => false];
    });
    if (!$prep['ok']) return $prep;
    $p      = $prep['p'];
    $cpe_id = $prep['id'];
    $serie  = $prep['serie'];
    $numero = $prep['numero'];

    $datos = is_array($prep['datos'] ?? null) && !empty($prep['datos']['items'])
           ? $prep['datos']
           : nubefact_datos($p, $ccpe, $tipo_cpe, $serie, $numero, $montos, isset($obs) ? ['observaciones' => $obs] : []);
    $r = nubefact_enviar_reservado($cpe_id, $datos, $cfg, $prep['reintento']);
    if (!$r['ok']) return $r;

    /* La constancia de siempre, para que la ficha, la cola de comprobantes y
       los reportes sigan leyendo lo mismo que leían. */
    $nombre_tipo = $tipo_cpe === 1 ? 'factura' : 'boleta';
    actualizar('pedidos', $pedido_id, [
        'comprobante_tipo'   => $nombre_tipo,
        'comprobante_serie'  => $serie,
        'comprobante_numero' => (string)$numero,
        'comprobante_por'    => $_SESSION['usuario_id'] ?? null,
        'comprobante_en'     => date('Y-m-d H:i:s'),
    ]);
    pedido_evento($pedido_id, 'comprobante', 'Se emitió ' . $nombre_tipo . ' ' . $serie . '-' . $numero
        . ($cambios ? ' (con ' . plural(count($cambios), 'dato corregido', 'datos corregidos') . ')' : '')
        . ($cfg['ambiente'] === 'prueba' ? ' (prueba)' : ''));
    bitacora('pedido.comprobante', 'pedido', $pedido_id, ['tipo' => $nombre_tipo, 'electronico' => 1] + ($cambios ? ['cambios' => $cambios] : []));
    comprobantes_pendientes_olvidar();
    return $r;
}

/**
 * LAS CORRECCIONES DE LA VISTA PREVIA (5b). $ed: nombre, direccion, email,
 * observaciones, desc[k]. Solo texto: los montos no se tocan.
 * → ['ok', 'error', 'cliente', 'montos', 'cambios', 'observaciones']
 */
function nubefact_aplicar_ediciones(array $ccpe, array $montos, array $ed, string $obs_def): array
{
    $cambios = [];
    $t = fn($v, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, $n);
    foreach (['nombre' => 100, 'direccion' => 100] as $k => $n) {
        if (!array_key_exists($k, $ed)) continue;
        $v = $t($ed[$k], $n);
        if ($v === '' && $k === 'nombre') return ['ok' => false, 'error' => 'El nombre del cliente no puede quedar vacío.'];
        if ($v === '') $v = '-';
        if ($v !== (string)$ccpe[$k]) { $cambios[$k] = ['de' => (string)$ccpe[$k], 'a' => $v]; $ccpe[$k] = $v; }
    }
    if (array_key_exists('email', $ed)) {
        $v = $t($ed['email'], 120);
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'El correo no está bien escrito.'];
        if ($v !== (string)$ccpe['email']) { $cambios['email'] = ['de' => (string)$ccpe['email'], 'a' => $v]; $ccpe['email'] = $v; }
    }
    foreach ((array)($ed['desc'] ?? []) as $k => $d) {
        $k = (int)$k;
        if (!isset($montos['items'][$k])) continue;
        $d = $t($d, 250);
        if ($d === '') return ['ok' => false, 'error' => 'La descripción de una línea no puede quedar vacía.'];
        if ($d !== (string)$montos['items'][$k]['descripcion']) {
            $cambios['linea_' . ($k + 1)] = ['de' => (string)$montos['items'][$k]['descripcion'], 'a' => $d];
            $montos['items'][$k]['descripcion'] = $d;
        }
    }
    $obs = array_key_exists('observaciones', $ed) ? $t($ed['observaciones'], 250) : $obs_def;
    if ($obs !== $obs_def) $cambios['observaciones'] = ['de' => $obs_def, 'a' => $obs];
    return ['ok' => true, 'error' => '', 'cliente' => $ccpe, 'montos' => $montos, 'cambios' => $cambios, 'observaciones' => $obs];
}

/**
 * LO QUE SE VA A EMITIR, SIN EMITIRLO (la vista previa, 5b).
 * → ['ok', 'error', 'p', 'cliente', 'montos', 'serie', 'tipo_cpe']
 */
function nubefact_previa(int $pedido_id, string $tipo): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    if (!in_array($tipo, ['boleta', 'factura'], true)) return $mal('Elige boleta o factura.');
    $p = pedido_de($pedido_id);
    if (!$p) return $mal('Ese pedido no existe.');
    $cli = una('SELECT * FROM clientes WHERE id = ?', [(int)$p['cliente_id']]);
    if (!$cli) return $mal('El cliente de esta venta no existe.');
    $ccpe = nubefact_cliente($cli, $tipo, $p);
    if (!$ccpe['ok']) return $mal($ccpe['error']);
    $montos = nubefact_items(pedido_lineas($pedido_id), pedido_flete_dentro($p),
                             (int)($p['descuento_centimos'] ?? 0) + (int)$p['cashback_usado_centimos'],
                             max(0, (int) ajuste('igv_porcentaje', 18)));
    $cfg = nubefact_config();
    return ['ok' => true, 'error' => '', 'p' => $p, 'cliente' => $ccpe, 'montos' => $montos,
            'serie' => $tipo === 'factura' ? $cfg['serie_factura'] : $cfg['serie_boleta'], 'tipo_cpe' => $tipo === 'factura' ? 1 : 2,
            'bloqueo' => pedido_emision_bloqueo($p)];
}

/**
 * LA NOTA DE CRÉDITO QUE ANULA EL COMPROBANTE DE UNA VENTA.
 *
 * Lleva las mismas líneas que el comprobante original, así que lo anula
 * entero. El tipo de nota sale del `extra` del motivo de anulación (código de
 * SUNAT); si el motivo no lo trae, es el 1: «anulación de la operación».
 */
function nubefact_nota_credito(int $pedido_id, ?int $motivo_item_id, string $motivo_txt): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    $orig = nubefact_vigente($pedido_id);
    if (!$orig) return ['ok' => true, 'error' => '', 'cpe' => null];   // no hay nada que anular

    /* Se emite en el ambiente del original, que es el que está en uso:
       nubefact_vigente() solo devuelve los de ese. */
    $cfg = nubefact_config((string)$orig['ambiente']);
    if ($cfg['ruta'] === '' || $cfg['token'] === '') {
        return $mal('Falta la configuración de NUBEFACT para anular ese comprobante.');
    }
    $serie = (int)$orig['tipo_cpe'] === 1 ? $cfg['serie_nc_factura'] : $cfg['serie_nc_boleta'];
    if (!nubefact_serie_valida($serie, (int)$orig['tipo_cpe'] === 1 ? 'F' : 'B')) {
        return $mal('Falta la serie de las notas de crédito en Configuración › Facturación electrónica.');
    }

    $p = pedido_de($pedido_id);

    $cod = 1;
    if ($motivo_item_id) {
        $it = lista_item($motivo_item_id);
        $x  = (int) trim((string)($it['extra'] ?? ''));
        if ($x >= 1 && $x <= 13) $cod = $x;
    }

    /* LA NOTA COPIA EL COMPROBANTE TAL COMO SE MANDÓ: mismo cliente, mismas
       líneas, mismos importes. Rehacerla con los datos de hoy la dejaría
       distinta si entretanto cambió el IGV, el cliente o el pedido, y una nota
       que no cuadra con su boleta no la anula entera. */
    $base = json_decode((string)($orig['datos_json'] ?? ''), true);
    if (!is_array($base) || empty($base['items'])) {
        /* Un comprobante adoptado sin lo enviado guardado: se rehace, pero
           solo si da el mismo total que el original. */
        $cli  = una('SELECT * FROM clientes WHERE id = ?', [(int)$p['cliente_id']]);
        $ccpe = nubefact_cliente($cli, (int)$orig['tipo_cpe'] === 1 ? 'factura' : 'boleta', $p);
        if (!$ccpe['ok']) return $mal($ccpe['error']);
        $montos = nubefact_items(pedido_lineas($pedido_id), pedido_flete_dentro($p),
                                 (int)($p['descuento_centimos'] ?? 0) + (int)$p['cashback_usado_centimos'],
                                 max(0, (int) ajuste('igv_porcentaje', 18)));
        if ($montos['total'] !== (int)$orig['total_centimos']) {
            return $mal('La venta ya no suma lo mismo que su ' . mb_strtolower(nubefact_nombre($orig))
                      . '. Emite la nota de crédito desde tu cuenta de NUBEFACT y avisa a Administración.');
        }
        $base = nubefact_datos($p, $ccpe, 3, $serie, 0, $montos);
    }

    /* Con la venta bloqueada, como al emitir: dos anulaciones a la vez no
       sacan dos notas. */
    $prep = en_transaccion(function () use ($pedido_id, $orig, $cfg, $serie, $mal) {
        bloquear_fila('pedidos', $pedido_id);
        $ya = una("SELECT * FROM comprobantes_electronicos
                    WHERE modifica_id = ? AND tipo_cpe = 3 AND estado IN ('incierto', 'enviando', 'emitido')
                    ORDER BY id DESC LIMIT 1", [(int)$orig['id']]);
        if ($ya && (string)$ya['estado'] === 'emitido') {
            return ['ok' => true, 'hecha' => $ya];
        }
        if ($ya) {
            if (nubefact_en_marcha($ya)) {
                return $mal('La nota de crédito se está emitiendo ahora mismo. Espera unos segundos y mira el pedido.');
            }
            actualizar('comprobantes_electronicos', (int)$ya['id'],
                       ['estado' => 'enviando', 'enviado_en' => date('Y-m-d H:i:s')]);
            return ['ok' => true, 'id' => (int)$ya['id'], 'serie' => (string)$ya['serie'],
                    'numero' => (int)$ya['numero'], 'reintento' => true,
                    'datos' => json_decode((string)($ya['datos_json'] ?? ''), true)];
        }
        $res = nubefact_reservar($pedido_id, $cfg['ambiente'], $serie, 3, (int)$orig['total_centimos'], (int)$orig['id']);
        return ['ok' => true, 'id' => $res['id'], 'serie' => $serie, 'numero' => $res['numero'], 'reintento' => false];
    });
    if (!$prep['ok']) return $prep;
    if (!empty($prep['hecha'])) return ['ok' => true, 'error' => '', 'cpe' => $prep['hecha']];
    $cpe_id = $prep['id'];
    $serie  = $prep['serie'];
    $numero = $prep['numero'];

    $datos = array_merge($base, [
        'operacion'                        => 'generar_comprobante',
        'tipo_de_comprobante'              => 3,
        'serie'                            => $serie,
        'numero'                           => $numero,
        'fecha_de_emision'                 => date('d-m-Y'),
        'codigo_unico'                     => mb_substr('WAKA-' . (string)$p['codigo'] . '-3', 0, 20),
        'documento_que_se_modifica_tipo'   => (int)$orig['tipo_cpe'],
        'documento_que_se_modifica_serie'  => (string)$orig['serie'],
        'documento_que_se_modifica_numero' => (int)$orig['numero'],
        'tipo_de_nota_de_credito'          => $cod,
        'observaciones' => mb_substr('Anula ' . $orig['serie'] . '-' . (int)$orig['numero']
                                     . ' · ' . trim($motivo_txt), 0, 250),
    ]);

    if (is_array($prep['datos'] ?? null) && !empty($prep['datos']['items'])) $datos = $prep['datos'];
    /* La nota se manda con la configuración del ambiente del ORIGINAL. */
    $r = nubefact_enviar_reservado($cpe_id, $datos, $cfg, $prep['reintento']);
    if (!$r['ok']) return $r;

    pedido_evento($pedido_id, 'comprobante', 'Se emitió la nota de crédito ' . $serie . '-' . $numero
        . ' que anula ' . $orig['serie'] . '-' . (int)$orig['numero']);
    bitacora('pedido.comprobante', 'pedido', $pedido_id, ['nota_credito' => 1]);
    return $r;
}

/* ───────────────────────── Probar la conexión ───────────────────────── */

/**
 * ¿Llegamos a NUBEFACT y nos reconoce? Se pregunta por un comprobante que no
 * existe: si contesta «no existe» (24), la ruta y el token están bien.
 * Prueba las dos formas de mandar el token que usa NUBEFACT y se queda con la
 * que funcione.
 */
function nubefact_probar(?string $amb = null): array
{
    $cfg = nubefact_config($amb);
    if ($cfg['ruta'] === '' || $cfg['token'] === '') {
        return ['ok' => false, 'texto' => 'Falta la ruta o el token.'];
    }
    $serie = nubefact_serie_valida($cfg['serie_boleta'], 'B') ? $cfg['serie_boleta'] : 'B001';
    $datos = ['operacion' => 'consultar_comprobante', 'tipo_de_comprobante' => 2,
              'serie' => $serie, 'numero' => 99999999];
    foreach (['directo', 'token'] as $auth) {
        $r = (nubefact_transporte())($cfg['ruta'], $cfg['token'], $datos, $auth);
        if (($r['red'] ?? '') !== '') {
                        return ['ok' => false, 'texto' => 'No se pudo conectar con NUBEFACT. Revisa que la ruta esté '
                                          . 'copiada entera y vuelve a probar en un momento.'];
        }
        $c = (int)($r['cuerpo']['codigo'] ?? 0);
        if ($c === 10) continue;                  // token no aceptado así: se prueba la otra forma
        if ($c === 11) return ['ok' => false, 'texto' => 'La ruta no es válida. Cópiala otra vez desde NUBEFACT.'];
        if (in_array($c, [50, 51], true)) return ['ok' => false, 'texto' => 'La cuenta de NUBEFACT está suspendida.'];
        guardar_ajuste('nubefact_auth', $auth);
        ajustes_olvidar();
        return ['ok' => true, 'texto' => 'Conexión correcta con NUBEFACT (' . nubefact_ambientes()[$cfg['ambiente']] . ').'];
    }
    return ['ok' => false, 'texto' => 'NUBEFACT no acepta ese token. Cópialo otra vez desde tu cuenta.'];
}
