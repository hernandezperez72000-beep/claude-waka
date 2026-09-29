<?php
declare(strict_types=1);

/**
 * Las plantillas de WhatsApp.
 *
 * Hoy el asesor escribe a mano el mensaje al grupo de facturación cada vez.
 * El HUB lo arma lleno y él solo pega. Las plantillas se editan desde
 * Configuración: el texto NO vive en el código, porque el día que cambien el
 * formato del grupo nadie va a querer llamar al programador.
 *
 * Regla: si una variable no tiene valor, se sustituye por vacío y se limpia
 * la línea entera. Un mensaje con «Distrito: {distrito}» pegado en un grupo
 * de facturación es peor que no tener plantilla.
 */

function plantilla(string $clave): ?array
{
    return una('SELECT * FROM plantillas_whatsapp WHERE clave = ? AND activo = 1', [$clave]);
}

/**
 * Sustituye {variables} y tira las líneas que se quedaron sin dato.
 *
 * La regla: si la línea traía variables y TODAS salieron vacías, la línea se
 * cae entera. Así «✅ Dirección: {direccion}» desaparece en un pedido de
 * recojo en oficina, y «Cobrar {saldo}» desaparece en un pedido ya cobrado —
 * pero «📍 ENVIAR ARMADO», que no lleva variables, se queda siempre.
 *
 * OJO CON EL /u AL PARTIR LAS LÍNEAS. Sin él, \R también casa con el byte
 * 0x85, que va dentro de ✅ (E2 9C 85) y de casi todos los emojis de las
 * plantillas reales: el mensaje salía partido a la mitad de un emoji y con
 * caracteres rotos. Lo pilló una prueba antes de que lo viera un cliente.
 */
function plantilla_render(string $texto, array $vars): string
{
    $lineas = preg_split('/\R/u', $texto) ?: [];
    $out = [];
    foreach ($lineas as $linea) {
        $total = 0; $vacias = 0;
        $render = preg_replace_callback('/\{([a-z_]+)\}/u', function ($m) use ($vars, &$total, &$vacias) {
            $total++;
            $v = trim((string)($vars[$m[1]] ?? ''));
            if ($v === '') $vacias++;
            return $v;
        }, $linea);
        if ($total > 0 && $vacias === $total) continue;
        $out[] = rtrim((string)$render);
    }
    // Nunca más de un renglón vacío seguido: quedan feos al pegar en WhatsApp.
    $limpio = preg_replace("/\n{3,}/u", "\n\n", implode("\n", $out));
    return trim((string)$limpio);
}

/**
 * Todas las variables de un pedido, listas para cualquier plantilla.
 * Se arma una sola vez aquí para que dos plantillas nunca digan cosas
 * distintas del mismo pedido.
 */
function variables_de_pedido(int $pedido_id): array
{
    $p = pedido_de($pedido_id);
    if (!$p) return [];

        $lineas = pedido_lineas($pedido_id);
    $detalle = [];
    /* DOS LISTAS DEL MISMO PEDIDO, y a propósito:
       · {detalle} lleva el precio y es la que ve el CLIENTE;
       · {productos} no lo lleva y es la que va al grupo de DESPACHO — «que no
         salga el precio del producto, puede confundir a despacho» (usuario,
         2026-09-20).
       El modelo y el color van en su propia línea, que se cae sola cuando
       nadie los escribió. */
    $productos = [];
    $modelos   = [];
    foreach ($lineas as $l) {
        $detalle[] = '✅ ' . (int)$l['cantidad'] . ' ' . $l['descripcion']
                   . ' — ' . soles((int)$l['total_centimos']);
        $productos[] = '✅ ' . (int)$l['cantidad'] . ' ' . $l['descripcion'];
        $mod = trim((string)($l['modelo'] ?? ''));
        if ($mod !== '') $modelos[] = $mod;
    }
    /* EL DESCUENTO (3d), para que las líneas que lee el cliente sumen lo
       mismo que su total. */
    if ((int)($p['cashback_usado_centimos'] ?? 0) > 0) {
        $detalle[] = '✅ Cashback Waka — − ' . soles((int)$p['cashback_usado_centimos']);
    }
    if ((int)($p['descuento_centimos'] ?? 0) > 0) {
        $detalle[] = '✅ Descuento — − ' . soles((int)$p['descuento_centimos']);
    }

    $ubi     = ubigeo_de($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null);
    $asesor  = trim((string)$p['asesor_nombre'] . ' ' . (string)$p['asesor_apellidos']);
    $cliente = trim((string)$p['cliente_nombre'] . ' ' . (string)$p['cliente_apellidos']);

    return [
        'codigo'     => (string)$p['codigo'],
        'cliente'    => $cliente,
        'nombre'     => primer_nombre($cliente),
        'documento'  => (string)$p['cliente_documento'],
        'tipo_doc'   => (string)$p['cliente_tipo_doc'],
        'celular'    => (string)$p['cliente_celular'],
        'correo'     => (string)$p['cliente_email'],
        'asesor'     => $asesor,
        'fecha'      => fecha_corta((string)($p['fecha'] ?: $p['creado_en'])),
        'total'      => soles((int)$p['total_centimos']),
        /* AL CLIENTE SE LE HABLA DE LO QUE PAGÓ, no de lo que facturación ya
           confirmó. Desde que ningún método cuenta al instante, TODO pedido
           recién registrado tiene su dinero sin confirmar: el mensaje que el
           asesor manda un segundo después decía «Adelanto recibido: S/ 0.00» y
           le pedía el total entero a alguien que acababa de pagar.
           Pero tampoco vale «lo que falta por registrar»: eso cuenta como
           pagado el dinero que facturación marcó EN ESPERA —el banco dice que
           no llegó—, y entonces «Datos para el pago» salía sin importe y
           «Cobrar …» desaparecía justo del pedido que hay que cobrar.
           pedido_pagado_cliente() es la cifra del cliente: lo que pagó y nadie
           ha puesto en duda. */
        'cobrado'    => soles(pedido_pagado_cliente($p)),
        'saldo'      => pedido_por_cobrar_cliente($p) > 0
                        ? soles(pedido_por_cobrar_cliente($p)) : '',
                'detalle'    => implode("\n", $detalle),
        'productos'  => implode("\n", $productos),
        'modelos'    => implode(' · ', $modelos),
        'producto'   => $lineas ? (string)$lineas[0]['descripcion'] : '',
        'direccion'  => (string)($p['direccion_txt'] ?? ''),
        'referencia' => (string)($p['referencia_txt'] ?? ''),
        /* Con su capital: el grupo de despacho y la agencia hablan de «La
           Merced», no de «Chanchamayo». */
        'distrito'   => (string)($ubi['distrito'] ?? '')
                      . (!empty($ubi['capital']) ? ' (' . (string)$ubi['capital'] . ')' : ''),
                'provincia'  => (string)($ubi['provincia'] ?? ''),
        'departamento' => (string)($ubi['departamento'] ?? ''),
        /* EL DESTINO EN UNA SOLA LÍNEA: «Puno - Puno - Puno» (usuario,
           2026-09-20). Tres renglones para decir un sitio es lo que hacía que
           el mensaje a provincia ocupara media pantalla del celular. Se arma
           aquí, no en la plantilla, para que las dos digan lo mismo. */
        'destino_linea' => implode(' - ', array_values(array_filter([
            (string)($ubi['distrito'] ?? ''),
            (string)($ubi['provincia'] ?? ''),
            (string)($ubi['departamento'] ?? ''),
        ], fn($x) => trim($x) !== ''))),
        /* Con la escrita a mano incluida: si no, a despacho le llegaba una
           sucursal de ninguna agencia. Sin el «(por autorizar)», que es cosa
           nuestra y no del grupo. */
        'agencia'    => pedido_agencia_texto($p, false),
        'sucursal'   => (string)($p['sucursal'] ?? ''),
        'armado'     => (int)$p['envio_armado'] === 1 ? '📍 ENVIAR ARMADO' : '',
        'flete'      => (int)$p['flete_centimos'] > 0 ? soles((int)$p['flete_centimos']) : '',
        /* La línea del flete NO puede ser fija en la plantilla. Decía siempre
           «lo paga el cliente en destino», y desde que el flete puede ir DENTRO
           del total ese mensaje le pedía al cliente que pagara dos veces: una
           en el «Cobrar» y otra en la agencia. Las líneas cuyas variables salen
           vacías desaparecen solas, así que aquí se decide qué frase toca. */
        'nota_flete' => (int)$p['flete_centimos'] <= 0
            ? ''
            : (pedido_flete_dentro($p) > 0
                ? '📦 Incluye ' . soles((int)$p['flete_centimos']) . ' de envío'
                : '📦 El flete lo paga el cliente en destino'),
        'nota'       => (string)($p['nota'] ?? ''),
        'estado'     => (string)$p['estado_nombre'],
        'recibe'     => (string)($p['recibe'] ?? ''),
        /* Despacho tiene que saber si el cliente PASA por la agencia o si la
           agencia se lo lleva: son dos trabajos distintos, y el mensaje solo
           traía la agencia y la sucursal, iguales en los dos casos. */
        'como_recibe'=> pedido_como_recibe($p),
        /* EL MAPA. Se pedía en el formulario desde el principio y NO VIAJABA A
           NINGUNA PARTE: quien reparte recibía la dirección escrita a mano y
           nada más. Lo encontró el usuario el 2026-09-11. En provincia y en
           recojo sale vacío, y entonces su línea se cae sola. */
        'mapa'       => (string)($p['gps'] ?? ''),
        /* La garantía que se le prometió a ESTE cliente. Vacía en los pedidos
           de antes de esta versión, y entonces su línea se cae sola: es mejor
           que mandarle al grupo una garantía inventada. */
        'garantia'   => pedido_garantia_texto($p),
        'pago_estado'=> pedido_frase_de_cobro($p),
        'entrega_cuando'=> pedido_entrega_texto($p),
        'comprobante'=> pedido_comprobante_texto($p),
    ];
}

/**
 * La línea que despacho necesita leer en un segundo: ¿cobro algo al entregar?
 *
 * Ojo con la trampa: `cobrado_centimos` solo cuenta el dinero CONFIRMADO. Un
 * pago que el cliente ya hizo y que facturación todavía no confirmó dejaría el
 * mensaje diciendo «cobrar S/ 899» — y el repartidor se lo cobraría dos veces.
 * Por eso se mira también lo registrado sin confirmar y se dice tal cual, que
 * es distinto de las dos cosas: está pagado, pero nadie lo ha verificado aún.
 */
function pedido_frase_de_cobro(array $p): string
{
    $total   = (int)$p['total_centimos'];
    $cobrado = (int)$p['cobrado_centimos'];
    $saldo   = max(0, $total - $cobrado);
    if ($saldo <= 0) return '💰 COBRADO AL 100% · no cobrar nada';

    /* Desde el 2026-09-09 estas dos ramas son una RED, no el camino normal: un
       pedido con dinero registrado sin confirmar ya no llega a armar mensaje
       —pedido_despacho_bloqueo() corta antes—, así que en la práctica solo se
       usan la primera («cobrado al 100%») y la última («cobrar X al entregar»).
       Se quedan porque el día que alguien afloje la regla, los dos errores
       contrarios siguen costando dinero:
       · Decir «cobrar» de un pago que el cliente ya hizo → se le cobra dos veces.
       · Decir «no cobrar» de un pago que facturación ya miró y anotó que NO
         está en el banco → la mercadería sale gratis. */
    $sin_revisar = (int) valor(
        'SELECT COALESCE(SUM(monto_centimos),0) FROM pagos
          WHERE pedido_id = ? AND anulado = 0 AND verificado = 0 AND en_espera = 0',
        [(int)$p['id']]);
    $en_espera = (int) valor(
        'SELECT COALESCE(SUM(monto_centimos),0) FROM pagos
          WHERE pedido_id = ? AND anulado = 0 AND verificado = 0 AND en_espera = 1',
        [(int)$p['id']]);

    if ($en_espera > 0) {
        return '⚠️ Hay ' . soles($en_espera) . ' que facturación está revisando: todavía no '
             . 'aparece en el banco. NO entregues sin preguntarle a facturación.';
    }
    if ($sin_revisar >= $saldo) {
        return '💰 PAGADO, pendiente de confirmación · NO cobrar al entregar';
    }
    $falta = $saldo - $sin_revisar;
    return $cobrado > 0 || $sin_revisar > 0
        ? '💰 Adelanto recibido · COBRAR ' . soles($falta) . ' AL ENTREGAR'
        : '💰 COBRAR ' . soles($falta) . ' AL ENTREGAR';
}

/** «Boleta B001-123» · «Factura F001-45» · «' '» si todavía no se emitió. */
function pedido_comprobante_texto(array $p): string
{
    $tipo = (string)($p['comprobante_tipo'] ?? '');
    if ($tipo === '' || $tipo === 'ninguno') return '';

    $num = pedido_comprobante_numero($p);   // su función, no una cuarta copia
    return ucfirst($tipo) . ($num !== '' ? ' ' . $num : '');
}

/**
 * Qué plantilla toca para este pedido cuando se acaba de registrar.
 * Pre venta tiene la suya porque el mensaje habla de separar, no de despachar.
 */
function plantilla_de_venta(array $pedido): string
{
    if ((string)$pedido['tipo'] === 'preventa') return 'venta_preventa';
    // Un recojo en oficina no tiene distrito, y sin esta línea caía en la
    // plantilla de provincia: el grupo de facturación recibía «Confirmación de
    // Venta a PROVINCIA» de alguien que va a pasar por la tienda.
    if ((string)($pedido['entrega'] ?? '') === 'recojo') return 'venta_lima';
    return ubigeo_es_lima($pedido['ubigeo_id'] ? (int)$pedido['ubigeo_id'] : null)
         ? 'venta_lima' : 'venta_provincia';
}

/**
 * Qué mensaje de despacho toca: el de Lima o el de provincia.
 *
 * Son dos trabajos distintos y desde el 2026-09-20 son dos plantillas: a
 * provincia el destino va en una línea, no hay mapa que abrir y no se habla de
 * dinero, porque allá se paga todo antes de que la caja salga. Un recojo en
 * oficina se queda con el de Lima: lo entrega Waka.
 */
function plantilla_de_despacho(array $pedido): string
{
        /* LA MISMA DEFINICIÓN QUE LA REGLA DE DESPACHO: pedido_es_provincia().
       Con la suya propia, un envío viejo sin distrito salía con saldo (para la
       regla no era provincia) pero con el mensaje de provincia, que no habla
       de dinero: el repartidor no se enteraba de que tenía que cobrar. */
    if (!pedido_es_provincia($pedido)) return 'despacho';
    /* Si el HUB todavía no tiene la plantilla de provincia —le falta el último
       paso de la actualización— se usa la de siempre antes que devolver un
       mensaje vacío al grupo. */
    return plantilla('despacho_provincia') ? 'despacho_provincia' : 'despacho';
}

/** El texto listo para pegar. Devuelve '' si esa plantilla está apagada. */
function mensaje_de_pedido(int $pedido_id, string $clave): string
{
    $pl = plantilla($clave);
    if (!$pl) return '';
    return plantilla_render((string)$pl['texto'], variables_de_pedido($pedido_id));
}

/** Las plantillas que tiene sentido ofrecer en la ficha de un pedido. */
function plantillas_del_pedido(array $pedido): array
{
    $claves = [plantilla_de_venta($pedido), 'datos_pago'];
    if ((int)$pedido['cobrado_centimos'] > 0) $claves[] = 'pago_recibido';
    if ((string)$pedido['tipo'] === 'preventa' && in_array((string)$pedido['estado'], ['llego','listo','pagado'], true)) {
        $claves[] = 'lote_llego';
    }
    $out = [];
    foreach (array_unique($claves) as $c) {
        if ($pl = plantilla($c)) $out[$c] = $pl['nombre'];
    }
    return $out;
}

/** Enlace wa.me con el mensaje ya metido, si el cliente tiene celular. */
function enlace_whatsapp(string $celular, string $texto): string
{
    $n = preg_replace('/\D+/', '', $celular) ?? '';
    if ($n === '') return '';
    if (strlen($n) === 9) $n = '51' . $n;                 // celular peruano sin prefijo
    return 'https://wa.me/' . $n . '?text=' . rawurlencode($texto);
}
