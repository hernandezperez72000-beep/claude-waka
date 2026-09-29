<?php
declare(strict_types=1);

/**
 * Pedidos.
 *
 * Tres tipos de venta y no se mezclan. Viven en tipos_de_venta(), que es de
 * donde salen el formulario, el filtro de la lista y todas las etiquetas:
 *  · ENTREGA INMEDIATA — producto en Perú y publicado en la tienda. Se paga
 *    entero al inicio o con adelanto y saldo contraentrega.
 *  · PRE VENTA — comprado en China y todavía en camino. 10% al separar y el
 *    saldo cuando llega. Aquí NO existe el selector completo/contraentrega.
 *  · LIQUIDACIÓN — saldo de almacén. Sale de su propio stock, nace sin
 *    garantía, y no juega al cashback en NINGUNA de las dos direcciones: ni lo
 *    acredita ni se puede pagar con él, porque el precio ya es el descuento.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  EL FLETE NO ENTRA EN EL TOTAL cuando lo paga el cliente en destino.
 *  En provincia el cliente le paga a la agencia, no a Waka: meterlo dentro
 *  descuadraría el saldo por cobrar y la devolución de una anulación.
 * ══════════════════════════════════════════════════════════════════════════
 */

/* ─────────────────────────  ESTADOS  ───────────────────────── */

function estados_pedido(): array
{
    static $e = null;
    if ($e !== null) return $e;
    $e = [];
    foreach (todas('SELECT * FROM pedido_estados ORDER BY orden, id') as $f) {
        $e[$f['clave']] = $f;
    }
    return $e;
}

function estado_por_clave(string $clave): ?array
{
    return estados_pedido()[$clave] ?? null;
}

function estado_por_id(?int $id): ?array
{
    foreach (estados_pedido() as $e) if ((int)$e['id'] === (int)$id) return $e;
    return null;
}

function estado_clave_de(array $pedido): string
{
    $e = estado_por_id((int)$pedido['estado_id']);
    return (string)($e['clave'] ?? '');
}

/**
 * LOS TIPOS DE VENTA, en un solo sitio.
 *
 * Eran un ternario repetido en seis pantallas —«preventa ? Pre venta : Entrega
 * inmediata»—, y ese ternario tiene una respuesta por defecto: añadir un
 * tercer tipo habría hecho que la liquidación se llamara «Entrega inmediata»
 * en la ficha, en la lista, en la ficha del cliente y en el reporte de pagos,
 * sin un solo error. De aquí salen el formulario, el filtro y todas las
 * etiquetas.
 *
 * El orden es el del formulario.
 */
function tipos_de_venta(): array
{
    return [
        'inmediata' => [
            'nombre'   => 'Entrega inmediata',
            'pista'    => 'Producto en Perú y en stock.',
            'cashback' => true,
        ],
        'preventa' => [
            'nombre'   => 'Pre venta',
            'pista'    => 'Reserva productos al mejor precio.',
            'cashback' => true,
        ],
        /* Liquidación, pedida por el usuario el 2026-09-10. No es un descuento
           ni una etiqueta: sale de su propio stock, no genera cashback y
           tampoco se puede pagar con cashback. */
        'liquidacion' => [
            'nombre'   => 'Liquidación',
            'pista'    => 'Productos con detalles a un precio de liquidación. No aplica garantía.',
            'cashback' => false,
        ],
    ];
}

/**
 * LOS TIPOS QUE SE PUEDEN OFRECER HOY EN ESTA BASE.
 *
 * `pedidos.tipo` es un ENUM, y ensancharlo es una migración: entre subir el
 * ZIP y pasar actualizar.php —o si ese ALTER falló— la columna todavía no
 * admite «liquidacion». Y escribir en un ENUM un valor que no admite NO DA
 * ERROR de PHP: MySQL guarda la cadena vacía. El pedido quedaba contado como
 * entrega inmediata en cuatro pantallas, dando cashback, y sin salir en
 * ninguno de los tres filtros de la lista.
 *
 * Por eso el formulario y el controlador preguntan por AQUÍ, no por
 * tipos_de_venta(): lo que no cabe en la base no se ofrece. tipos_de_venta()
 * sigue conociéndolos a todos, que para eso pinta también los pedidos viejos.
 *
 * En el banco de pruebas los ENUM son VARCHAR, así que ahí caben todos.
 */
function tipos_de_venta_ofrecidos(): array
{
    $todos = array_keys(tipos_de_venta());
    /* La definición se arma desde la MISMA tabla, para que añadir un tipo no
       sea acordarse de tocar otra lista. Que coincida con la migración de
       esquema3.php lo vigila una prueba de código fuente.

       SIN CACHÉ a propósito, aunque sean tres consultas diminutas: una caché
       `static` se queda pegada al proceso, y en este proyecto ya hubo pruebas
       que montan dos bases distintas en el mismo PHP. La respuesta la manda la
       base que hay AHORA. */
    $falta = enum_falta_admitir('pedidos', 'tipo',
                 "ENUM('" . implode("','", $todos) . "')");
    $ok = array_values(array_diff($todos, $falta));

    /* Si no queda ninguno —una columna editada a mano con un ENUM ajeno— se
       ofrecen todos. Un formulario de ventas con cero tipos es una pantalla
       inutilizable en un HUB que antes vendía, y eso es peor que el riesgo que
       esta función evita. */
    if (!$ok) return tipos_de_venta();

    /* array_intersect_key conserva el orden del PRIMER array, que es el del
       formulario. Ordenar por $ok cambiaría el orden de los radios. */
    return array_intersect_key(tipos_de_venta(), array_flip($ok));
}

/** ¿Es un tipo de venta que existe? Lo que llegue de fuera pasa por aquí. */
function tipo_venta_valido(string $tipo): bool
{
    return isset(tipos_de_venta()[$tipo]);
}

/**
 * El nombre del tipo de venta tal como se enseña.
 *
 * Lo que no conoce lo devuelve TAL CUAL, no lo rebautiza: antes esto caía en
 * «Entrega inmediata», así que un tipo roto o de una versión futura salía con
 * un nombre concreto y equivocado en la ficha, en la lista, en la del cliente,
 * en facturar y en el Excel del reporte de pagos. Feo pero honesto es mejor
 * que bonito y falso.
 */
function tipo_venta_texto(string $tipo): string
{
    return (string)(tipos_de_venta()[$tipo]['nombre'] ?? $tipo);
}

/**
 * De qué stock sale lo que se vende.
 *
 * Los tres almacenes son el mismo producto en tres sitios distintos: lo que
 * está en tienda, lo que viene en el contenedor y lo que se está liquidando.
 * Reservar una liquidación contra el stock de tienda dejaría la tienda
 * vendiendo lo que ya no tiene.
 */
function pedido_origen_stock(string $tipo): string
{
    if ($tipo === 'preventa')    return 'preventa';
    if ($tipo === 'liquidacion') return 'liquidacion';
    return 'tienda';
}

/**
 * ¿Esta venta juega al cashback? Vale para las DOS direcciones: ni acredita
 * ni se puede pagar con saldo.
 *
 * «No se puede usar cashback en productos de liquidación» (usuario,
 * 2026-09-10). Y al revés también: un precio de liquidación ya es el descuento,
 * regalar encima el 1% sería descontar dos veces.
 */
function venta_da_cashback(string $tipo): bool
{
    /* LISTA BLANCA, no lista negra. Escrito como «$tipo !== 'liquidacion'»,
       el valor por defecto quedaba del lado caro: un tipo vacío —el ENUM sin
       ensanchar—, un NULL, o un cuarto tipo que mañana tampoco deba dar
       cashback, todos acreditaban el 1% sin que nadie lo pidiera. La regla del
       proyecto es que cuando la respuesta no se sabe, la respuesta es la que
       no cuesta dinero. */
    return (bool)(tipos_de_venta()[$tipo]['cashback'] ?? false);
}

/**
 * EL ESTADO DEL PEDIDO LO MUEVE EL HUB (3j). Lo pidió el usuario el
 * 2026-09-28: «el estado cambiará cuando mande a despacho y cuando despacho
 * entregue». Ya no hay botones para cambiarlo: cada estado sale de un dato que
 * el HUB ya tiene, así que no hay nada que se quede sin marcar.
 *
 *   Venta de stock: Registrado → Pagado (cobrado al 100 %) → En despacho → Entregado
 *   Pre venta:      Reservado → En camino (su lote viaja) → Producto llegó
 *                   → Listo para entrega (el CEO marca el lote) → En despacho → Entregado
 *
 * En la pre venta el dinero se ve en su chip de pago, no en el estado: el
 * estado dice dónde está la mercadería.
 * Anulado no sale de aquí: se llega con pedido_anular(), que pide motivo.
 */
function estado_inicial(string $tipo): string
{
    return $tipo === 'preventa' ? 'reservado' : 'registrado';
}

/**
 * «LA PRE VENTA YA PUEDE SALIR»: todos sus lotes están listos para entrega.
 * Una sola definición, en SQL, para la lista, el contador y la ficha.
 * `$a` es el alias de `pedidos`. Sin la columna (HUB sin actualizar) no frena.
 */
function sql_preventa_lista(string $a = 'pe'): string
{
    if (!columna_existe('lotes', 'listo_en')) return '1 = 1';
    return "($a.tipo <> 'preventa' OR NOT EXISTS (
                SELECT 1 FROM pedido_lineas xpl
                  JOIN lote_lineas xll ON xll.id = xpl.lote_linea_id
                  JOIN lotes xlo ON xlo.id = xll.lote_id
                 WHERE xpl.pedido_id = $a.id AND xlo.listo_en IS NULL))";
}

/**
 * Dónde está la mercadería de unas pre ventas, mirando sus lotes:
 * 'listo' · 'llego' · 'en_camino' · 'reservado', con la fecha aproximada en
 * que estará (la del almacén o, si no, la de llegada; la del lote que más
 * tarda). De golpe para una lista entera: una consulta, no una por fila.
 * → [pedido_id => ['situacion' => .., 'fecha' => 'Y-m-d'|'']]
 */
function preventa_situaciones(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $hay_listo = columna_existe('lotes', 'listo_en');
    $en = implode(',', array_fill(0, count($ids), '?'));
    $filas = todas('SELECT DISTINCT xpl.pedido_id, xlo.id, xlo.estado, xlo.fecha_llegada_est'
                 . (columna_existe('lotes', 'fecha_almacen') ? ', xlo.fecha_almacen' : ', NULL AS fecha_almacen')
                 . ($hay_listo ? ', xlo.listo_en' : ', NULL AS listo_en') . "
                      FROM pedido_lineas xpl
                      JOIN lote_lineas xll ON xll.id = xpl.lote_linea_id
                      JOIN lotes xlo ON xlo.id = xll.lote_id
                     WHERE xpl.pedido_id IN ($en)", $ids);
    $por = [];
    foreach ($filas as $f) $por[(int)$f['pedido_id']][] = $f;
    $out = [];
    foreach ($ids as $id) {
        $lotes = $por[$id] ?? [];
        /* SIN LOTE: la pre venta de cuando no había lotes a la venta (se vendía
           del catálogo). No hay contenedor que esperar: sale como antes de la
           3j, pero su estado es «Reservado», no «Listo para entrega». */
        if (!$lotes) { $out[$id] = ['situacion' => 'sin_lote', 'fecha' => '']; continue; }
        $listos = 0; $llegados = 0; $viajan = 0; $fecha = '';
        foreach ($lotes as $l) {
            if ($hay_listo && $l['listo_en'] !== null) { $listos++; continue; }
            if ((string)$l['estado'] === 'recibido') $llegados++;
            elseif (in_array((string)$l['estado'], ['en_camino', 'en_aduana'], true)) $viajan++;
            $f = (string)($l['fecha_almacen'] ?: ($l['fecha_llegada_est'] ?: ''));
            if ($f !== '' && $f > $fecha) $fecha = $f;
        }
        $n = count($lotes);
        $sit = $listos === $n ? 'listo'
             : ($listos + $llegados === $n ? 'llego'
             : ($viajan + $llegados + $listos > 0 ? 'en_camino' : 'reservado'));
        $out[$id] = ['situacion' => $sit, 'fecha' => $sit === 'listo' ? '' : $fecha];
    }
    return $out;
}

function pedido_preventa_situacion(int $pedido_id): array
{
    return preventa_situaciones([$pedido_id])[$pedido_id] ?? ['situacion' => 'sin_lote', 'fecha' => ''];
}

/**
 * Lo que ve el asesor EN VEZ DE «Mandar» mientras la pre venta no está lista:
 * «Llega aprox. 12/10». '' cuando ya puede salir. $s: de preventa_situaciones().
 */
function preventa_espera_texto(array $s): string
{
    /* Sin la columna (HUB a medio actualizar) no se frena: como antes. */
    if (in_array($s['situacion'], ['listo', 'sin_lote'], true) || !columna_existe('lotes', 'listo_en')) return '';
    if ($s['situacion'] === 'llego') return 'Ya llegó · pronto listo para entrega';
    return $s['fecha'] !== '' ? 'Llega aprox. ' . date('d/m', strtotime($s['fecha'])) : 'Llega pronto · sin fecha todavía';
}

function pedido_preventa_espera_texto(array $p): string
{
    if ((string)($p['tipo'] ?? '') !== 'preventa') return '';
    return preventa_espera_texto(pedido_preventa_situacion((int)$p['id']));
}

/**
 * EL ESTADO QUE LE TOCA a este pedido según sus datos. Una sola definición:
 * la usan pedido_estado_auto() y las pruebas.
 */
function pedido_estado_calculado(array $p): string
{
    if (!empty($p['anulado_en']) || (string)($p['estado'] ?? '') === 'anulado') return 'anulado';
    if (!empty($p['entregado_en'])) return 'entregado';
    if ((int)($p['despacho_veces'] ?? 0) > 0) return 'en_despacho';
    if ((string)$p['tipo'] === 'preventa') {
        $s = pedido_preventa_situacion((int)$p['id'])['situacion'];
        /* Sin la columna del lote listo, «llegó» es lo más lejos que se sabe;
           sin lote, «Reservado» hasta que salga. */
        return $s === 'sin_lote' ? 'reservado' : $s;
    }
    return ((int)$p['total_centimos'] > 0 && pedido_saldo($p) <= 0) ? 'pagado' : 'registrado';
}

/**
 * PONE EL ESTADO QUE LE TOCA, con su línea en la historia del pedido.
 * Se llama después de todo lo que puede moverlo: un pago, un despacho, la
 * llegada o el «listo» del lote, la entrega. Devuelve la clave nueva, o ''
 * si no cambió. Un estado que no existe todavía (HUB sin actualizar) no se pone.
 */
function pedido_estado_auto(int $pedido_id, string $nota = '', bool $con_evento = true): string
{
    $p = pedido_de($pedido_id);
    if (!$p || (string)$p['estado'] === 'anulado') return '';
    $clave = pedido_estado_calculado($p);
    if ($clave === (string)$p['estado'] || $clave === 'anulado') return '';
    $e = estado_por_clave($clave);
    if (!$e) return '';
    actualizar('pedidos', $pedido_id, ['estado_id' => (int)$e['id']]);
    /* Sin evento solo al nacer: el pedido nace ya en su estado. */
    if ($con_evento) {
        pedido_evento($pedido_id, 'estado',
            'Pasó de «' . $p['estado_nombre'] . '» a «' . $e['nombre'] . '»' . ($nota !== '' ? ' · ' . $nota : ''));
        bitacora('pedido.estado', 'pedido', $pedido_id, ['de' => $p['estado'], 'a' => $clave]);
    }
    return $clave;
}

/** Los estados en los que el pedido todavía no salió (para la tarea que anula los denegados). */
function estados_sin_salir(): array
{
    return ['registrado', 'reservado', 'en_camino', 'llego', 'listo'];
}

/**
 * Desde cuándo el HUB mueve los estados solo (la actualización a la 3j). Una
 * pre venta que alguien había pasado a mano a «En camino» no se anulaba sola;
 * para no anular de golpe lo de antes, en los estados nuevos solo cuenta una
 * denegación de después. Sin actualizar, ninguna.
 */
function estados_auto_desde(): string
{
    $d = (string) ajuste('entrega_arranque', '');
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? $d : '9999-12-31 00:00:00';
}

/* ─────────────────────────  LEER  ───────────────────────── */

/** Un pedido con todo lo que hace falta para pintarlo. */
function pedido_de(int $id): ?array
{
    return una(
        "SELECT p.*, e.clave AS estado, e.nombre AS estado_nombre, e.color AS estado_color,
                e.es_final,
                c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
                c.documento AS cliente_documento, c.tipo_doc AS cliente_tipo_doc,
                c.celular AS cliente_celular, c.email AS cliente_email,
                u.nombre AS asesor_nombre, u.apellidos AS asesor_apellidos,
                eq.nombre AS equipo_nombre, o.nombre AS oficina_nombre
           FROM pedidos p
           JOIN pedido_estados e ON e.id = p.estado_id
           JOIN clientes c       ON c.id = p.cliente_id
           JOIN usuarios u       ON u.id = p.asesor_id
           LEFT JOIN equipos eq  ON eq.id = p.equipo_id
           LEFT JOIN oficinas o  ON o.id = p.oficina_id
          WHERE p.id = ?", [$id]
    );
}

function pedido_lineas(int $pedido_id): array
{
    return todas('SELECT * FROM pedido_lineas WHERE pedido_id = ? ORDER BY id', [$pedido_id]);
}

function pedido_eventos(int $pedido_id): array
{
    return todas('SELECT ev.*, u.nombre, u.apellidos FROM pedido_eventos ev
                    LEFT JOIN usuarios u ON u.id = ev.usuario_id
                   WHERE ev.pedido_id = ? ORDER BY ev.id DESC', [$pedido_id]);
}

/** Deja anotado en la ficha quién hizo qué. */
function pedido_evento(int $pedido_id, string $tipo, string $texto): void
{
    try {
        insertar('pedido_eventos', [
            'pedido_id'  => $pedido_id,
            'usuario_id' => $_SESSION['usuario_id'] ?? null,
            'tipo'       => mb_substr($tipo, 0, 40),
            'texto'      => mb_substr($texto, 0, 300),
        ]);
    } catch (Throwable $ex) {
        error_log('[HUB] pedido_evento: ' . $ex->getMessage());
    }
}

/* ─────────────────────────  CÓDIGO  ───────────────────────── */

/**
 * El código visible del pedido: P-00001.
 * Se pone DESPUÉS de insertar, a partir del id, porque un contador aparte se
 * desincroniza en cuanto dos asesores registran a la vez. El id ya es único
 * y lo garantiza la base, no el código PHP.
 */
function pedido_codigo_de_id(int $id): string
{
    return 'P-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/* ─────────────────────────  TOTALES  ───────────────────────── */

/**
 * Vuelve a calcular subtotal, total, cobrado y saldo desde las filas de
 * verdad. Es la ÚNICA función que escribe esas columnas: si cada pantalla
 * hiciera su propia suma, tarde o temprano dos pantallas dirían cifras
 * distintas del mismo pedido — que es exactamente lo que encontró la
 * auditoría de las pantallas.
 */
/**
 * EL FLETE QUE SÍ ENTRA EN EL TOTAL — una sola definición.
 *
 * Solo suma cuando lo cobra Waka ('incluido'). El flete a provincia lo paga el
 * cliente a la agencia en destino: no es dinero de la empresa, y meterlo dentro
 * descuadraría el saldo por cobrar y la devolución de una anulación.
 *
 * Vive aquí y no repetido en cada pantalla porque el total, la base del
 * cashback y el tope de lo que se puede cobrar tienen que leer LO MISMO. Este
 * proyecto ya produjo seis veces dos cifras del mismo dato por escribir la
 * cuenta a mano en una vista.
 */
function pedido_flete_dentro(array $p): int
{
    if ((string)($p['flete_lo_paga'] ?? 'cliente_destino') !== 'incluido') return 0;
    return max(0, (int)($p['flete_centimos'] ?? 0));
}

/**
 * La agencia del pedido, esté en la lista o escrita a mano.
 *
 * UNA definición para las cuatro pantallas que la dicen —la ficha, facturar,
 * despacho y el mensaje de WhatsApp—. Sin esto, la agencia que el asesor
 * escribía porque no estaba en la lista se guardaba en la base y NO LA LEÍA
 * NADIE: al grupo de despacho le llegaba una sucursal de ninguna agencia.
 *
 * La escrita a mano se marca como lo que es: todavía no está autorizada.
 */
function pedido_agencia_texto(array $p, bool $con_aviso = true): string
{
    $id = $p['agencia_item_id'] ?? null;
    if ($id) return lista_texto((int)$id);
    $otra = trim((string)($p['agencia_otra'] ?? ''));
    if ($otra === '') return '';
    return $con_aviso ? $otra . ' (por autorizar)' : $otra;
}

/**
 * Cómo recibe el cliente su pedido, en una frase.
 *
 * UNA sola definición para las tres pantallas que lo dicen —la ficha, la de
 * facturar y el mensaje de WhatsApp—. Antes era un ternario repetido que solo
 * conocía dos casos, «Envío» o «Recoge en oficina», y a quien recogía en la
 * agencia de Trujillo le ponía «Recoge en oficina»: la oficina de Lima, a mil
 * kilómetros.
 */
function pedido_como_recibe(array $p): string
{
    if ((string)($p['entrega'] ?? '') !== 'envio') return 'Recoge en la oficina';
    if (!empty($p['recojo_agencia']))              return 'Recoge en la agencia';
    return ubigeo_es_lima($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null)
        ? 'Se lo llevamos a su dirección'
        : 'La agencia se lo lleva a su dirección';
}

/**
 * LA BASE DEL CASHBACK DE UN PEDIDO — el total menos el envío.
 *
 * «Delivery no es sumado para cashback» (usuario, 2026-09-10): el envío que
 * cobra Waka SÍ entra en el total —el cliente se lo paga a la empresa— pero el
 * 1% se calcula solo sobre la parte de producto.
 */
function pedido_base_cashback(array $p): int
{
    /* Y en liquidación no hay base ninguna. Va AQUÍ, en la función que ya es
       la única definición de «sobre cuánto se calcula», y no en un if dentro
       de cashback_de_pago_en_pedido(): así lo respetan por igual el pago que
       se registra hoy, el saldo que entra dentro de un mes y la reversión de
       los dos al anular. Un if en el sitio de acreditar habría acreditado 0 y
       revertido el 1%. */
    if (!venta_da_cashback((string)($p['tipo'] ?? ''))) return 0;

    return max(0, (int)($p['total_centimos'] ?? 0) - pedido_flete_dentro($p));
}

/* ──────────────────────  LA FECHA DE LA VENTA  ────────────────────── */

/**
 * Qué día ocurrió la venta, para quien NO elige la fecha del pedido.
 *
 * «¿La fecha del pedido no sería la fecha en la que se registra el pedido y
 * estaría de más?» (usuario, 2026-09-11). Sí — salvo por un detalle que costó
 * un fallo: el cliente suele pagar ANTES de que el asesor registre la venta.
 * Si el pedido se clava en hoy, ese pago queda fechado antes que su pedido y
 * `pago_registrar()` lo rechaza con el pedido ya guardado.
 *
 * Así que la venta se fecha el día en que el cliente pagó. Nunca hacia
 * adelante —eso movería la meta de un mes que no ha empezado— y sin fecha de
 * pago, hoy. Hacia atrás no hace falta acotar aquí: lo acota la regla del
 * propio pago (`pago_dias_atras`, 30 días), y encima está la comprobación de
 * los 365 días del formulario.
 */
function fecha_de_venta_por_el_pago(string $fecha_pago): string
{
    $hoy = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago) || !strtotime($fecha_pago)) return $hoy;
    return $fecha_pago < $hoy ? $fecha_pago : $hoy;
}

/* ────────────────  EL ESTADO DEL PAGO DE UN PEDIDO  ──────────────── */

/**
 * EL ESTADO DEL PAGO, EN UNA PALABRA — y manda el PEOR.
 *
 * «Que mande el peor estado, ya que el error es más relevante: es solicitar una
 * acción para resolver eso» (usuario, 2026-09-11). Una pre venta con el
 * adelanto confirmado y el saldo denegado sale DENEGADO, porque lo que hay que
 * hacer con ella es arreglar la denegación.
 *
 * De peor a mejor: denegado · espera · sin_revisar · confirmado · '' (sin pagos).
 *
 * Un denegado deja de contar EN CUANTO EL ASESOR REGISTRA OTRO PAGO — la misma
 * regla que PAGO_TRABADO, que es la que decide la lista de trabados y el aviso.
 * Sin eso, un pedido arreglado hace tres meses seguiría gritando en rojo.
 */
function sql_estado_pago(string $pedido_id = 'p.id'): string
{
    /* EN UN HUB AL QUE NO LE HAN PASADO actualizar.php no existen ni
       `denegado` ni `en_espera`, y esta consulta va DENTRO de la lista de
       pedidos: sin esto, /pedidos se caía entero para Administración,
       Facturación y Dirección, que hasta ahora podían seguir trabajando. Se
       contesta lo único que se puede saber con las columnas que hay. */
    if (!tabla_existe('pagos') || !columna_existe('pagos', 'denegado')
        || !columna_existe('pagos', 'en_espera') || !columna_existe('pagos', 'tipo')) {
        /* Esta rama lleva DOS «?» y la completa lleva CUATRO. Quien llama
           cuenta los suyos —ver pedido_estado_pago()— en vez de dar por hecho
           un número: con cuatro fijos, la ficha del pedido reventaba con
           «Invalid parameter number» justo en el HUB al que le falta la
           migración, que es el HUB que esta rama existe para sostener.
           `pg.tipo` también se comprueba: es de la misma migración. */
        $vivo = "SELECT 1 FROM pagos pg WHERE pg.pedido_id = $pedido_id AND pg.anulado = 0";
        return "CASE"
             . " WHEN EXISTS ($vivo AND pg.verificado = 0) THEN 'sin_revisar'"
             . " WHEN EXISTS ($vivo AND pg.verificado = 1) THEN 'confirmado'"
             . " ELSE '' END";
    }

    $base = "SELECT 1 FROM pagos pg WHERE pg.pedido_id = $pedido_id AND pg.tipo = 'cobro'";
    $sin_resolver = "NOT EXISTS (SELECT 1 FROM pagos px WHERE px.pedido_id = pg.pedido_id"
                  . " AND px.id > pg.id AND px.anulado = 0 AND px.tipo = 'cobro')";
    return "CASE"
         . " WHEN EXISTS ($base AND pg.denegado = 1 AND $sin_resolver) THEN 'denegado'"
         . " WHEN EXISTS ($base AND pg.anulado = 0 AND pg.en_espera = 1) THEN 'espera'"
         . " WHEN EXISTS ($base AND pg.anulado = 0 AND pg.verificado = 0) THEN 'sin_revisar'"
         . " WHEN EXISTS ($base AND pg.anulado = 0 AND pg.verificado = 1) THEN 'confirmado'"
         . " ELSE '' END";
}

/**
 * El estado del pago de ESTE pedido. Si la consulta ya lo trajo —la lista lo
 * hace, para no disparar una consulta por fila— se usa ese; si no, se pregunta.
 */
function pedido_estado_pago(array $p): string
{
    if (array_key_exists('estado_pago', $p)) return (string)$p['estado_pago'];
    if (empty($p['id'])) return '';
    /* Los parámetros se CUENTAN sobre el SQL que toca, no se dan por hechos:
       la rama de respaldo —la del HUB sin migrar— lleva menos «?» que la
       completa, y una lista fija reventaba la ficha justo ahí. */
    $sql = sql_estado_pago('?');
    return (string) valor('SELECT ' . $sql,
                          array_fill(0, substr_count($sql, '?'), (int)$p['id']));
}

/**
 * Cómo se pinta cada estado de pago: el texto, el color del chip y si PIDE UNA
 * ACCIÓN del asesor. Una sola definición para la lista, la ficha y el aviso.
 */
function estado_pago_pinta(string $clave): array
{
    $t = [
        'denegado'    => ['texto' => 'Denegado',               'color' => 'rojo',  'accion' => true],
        'espera'      => ['texto' => 'En espera',              'color' => 'ambar', 'accion' => true],
        'sin_revisar' => ['texto' => 'Esperando confirmación', 'color' => 'gris',  'accion' => false],
        'confirmado'  => ['texto' => 'Confirmado',             'color' => 'verde', 'accion' => false],
    ];
    return $t[$clave] ?? ['texto' => '', 'color' => 'gris', 'accion' => false];
}

/* ──────────────────  EL COMPROBANTE QUE PIDE EL CLIENTE  ────────────── */

/**
 * Lo que el ASESOR dice que el cliente quiere. NO es lo que se emitió: eso es
 * `comprobante_tipo` y lo decide facturación.
 *
 * «Solo será una indicación del asesor, la acción la decide administración o
 * facturación» (usuario, 2026-09-11).
 */
function comprobante_pide_texto(?string $v): string
{
    /* Sale de comprobantes_que_se_solicitan(), que es la lista: escrito aquí
       otra vez, el día que se cambiara una palabra habría dos sitios y solo
       uno se arreglaría. */
    return (string)(comprobantes_que_se_solicitan()[(string)$v]['nombre'] ?? '');
}

/**
 * LOS DOS COMPROBANTES QUE SE PUEDEN SOLICITAR. Y la ÚNICA lista: los textos
 * de arriba, los botones del popup y los del menú de la fila salen de aquí.
 *
 * SON DOS Y NO TRES. Había un «No necesita», y el usuario lo quitó el
 * 2026-09-12: «no pedir nada es no necesita». No hay que marcar lo que no
 * pasa — un pedido sin solicitud es un pedido sin comprobante, y esa es toda
 * la regla.
 */
function comprobantes_que_se_solicitan(): array
{
    return [
        'boleta'  => ['nombre' => 'Boleta',  'pista' => 'Cliente con DNI',
                      'boton'  => 'SOLICITAR BOLETA',  'menu' => 'Solicitar boleta'],
        'factura' => ['nombre' => 'Factura', 'pista' => 'Cliente con RUC',
                      'boton'  => 'SOLICITAR FACTURA', 'menu' => 'Solicitar factura'],
    ];
}

/**
 * EL ASESOR PIDE EL COMPROBANTE. Deja constancia de qué, quién y cuándo.
 *
 * Es un AVISO, no una emisión: quien emite es facturación y decide ella. Por
 * eso se puede mandar en cualquier momento —al terminar la venta o tres días
 * después, con el pago sin confirmar todavía— y por eso se puede cambiar de
 * idea: el cliente que pidió boleta llama y pide factura.
 *
 * `$tipo` vacío RETIRA la solicitud, que es lo mismo que no haberla mandado:
 * esa venta no lleva comprobante.
 *
 * NO toca `comprobante_tipo`: lo que facturación ya emitió no lo borra nadie
 * desde aquí. Si ya está emitido se dice y no se hace nada, porque pedir una
 * boleta de una venta que ya tiene factura no es un aviso, es un lío.
 */
function pedido_solicitar_comprobante(int $pedido_id, string $tipo): array
{
    $tipo = mb_strtolower(trim($tipo));
    if ($tipo !== '' && !isset(comprobantes_que_se_solicitan()[$tipo])) {
        return ['ok' => false, 'error' => 'Solo se puede pedir boleta o factura.'];
    }

    $p = pedido_de($pedido_id);
    if (!$p) return ['ok' => false, 'error' => 'Ese pedido no existe.'];
    if ((string)$p['estado'] === 'anulado') {
        return ['ok' => false, 'error' => 'Este pedido está anulado: no lleva comprobante.'];
    }
    if (!columna_existe('pedidos', 'comprobante_pide')) {
        return ['ok' => false, 'error' => 'Esta opción todavía no está disponible. Avisa a Administración: falta terminar la actualización.'];
    }
    if (trim((string)($p['comprobante_tipo'] ?? '')) !== '') {
        return ['ok' => false, 'error' => 'Facturación ya resolvió el comprobante de esta venta ('
            . comprobante_tipo_texto((string)$p['comprobante_tipo']) . '). Si hay que cambiarlo, '
            . 'se lo dices a facturación: rehacer un comprobante emitido es anularlo en SUNAT.'];
    }

    $antes = (string)($p['comprobante_pide'] ?? '');
    if ($antes === $tipo) {
        return ['ok' => true, 'error' => '', 'sin_cambio' => true];
    }

    $campos = ['comprobante_pide' => $tipo ?: null];
    /* El quién y el cuándo, solo si el HUB los tiene: un HUB a medio actualizar
       tiene que poder seguir avisando aunque no guarde la firma. */
    if (columna_existe('pedidos', 'comprobante_pide_en')) {
        $campos['comprobante_pide_en'] = $tipo !== '' ? date('Y-m-d H:i:s') : null;
    }
    if (columna_existe('pedidos', 'comprobante_pide_por')) {
        $campos['comprobante_pide_por'] = $tipo !== '' ? ($_SESSION['usuario_id'] ?? null) : null;
    }
    actualizar('pedidos', $pedido_id, $campos);

    $texto = $tipo === ''
        ? 'Se retiró la solicitud de comprobante'
        : ($antes === '' ? 'Se solicitó ' . comprobante_pide_texto($tipo)
                         : 'La solicitud cambió de ' . comprobante_pide_texto($antes)
                           . ' a ' . comprobante_pide_texto($tipo));
    pedido_evento($pedido_id, 'comprobante', $texto);
    bitacora('pedido.comprobante_pide', 'pedido', $pedido_id, ['pide' => $tipo]);

    return ['ok' => true, 'error' => '', 'texto' => $texto];
}

/**
 * LAS SOLICITUDES QUE ESPERAN, para la bandeja de facturación.
 *
 * Es una cola de PEDIDOS, no de pagos: el asesor pide el comprobante cuando
 * habla con el cliente, no cuando entra el dinero, así que puede llegar antes
 * de que el pago esté confirmado.
 *
 * NO ES EL AVISO QUE SE QUITÓ. Aquel contaba las ventas SIN comprobante —todas,
 * incluidas las que a propósito no llevan— y por eso nunca bajaba a cero y se
 * aprendía a ignorar. Esta cola solo tiene lo que ALGUIEN PIDIÓ, y se vacía:
 * en cuanto facturación emite o marca que no lleva, la fila desaparece.
 *
 * Con el ámbito de quien mira, como todo lo demás: una cuenta que recibiría
 * 403 al abrir ese pedido no puede verlo aquí de refilón.
 */
const COMPROBANTES_EN_COLA = 100;

/** CUÁNTOS hay en total, para que la cabecera no cuente lo que cupo. Es la
    misma condición que la cola, escrita en un solo sitio y usada por las dos. */
function comprobantes_solicitados_cuantos(?array $u = null): int
{
    if (!columna_existe('pedidos', 'comprobante_pide')) return 0;
    [$sql_ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    return (int) valor(
        "SELECT COUNT(*) FROM pedidos pe
           JOIN pedido_estados e ON e.id = pe.estado_id
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE e.clave <> 'anulado'
            AND pe.comprobante_pide IN ('boleta','factura')
            AND COALESCE(pe.comprobante_tipo, '') = ''
            AND $sql_ambito", $par);
}

function comprobantes_solicitados(?array $u = null, int $tope = COMPROBANTES_EN_COLA): array
{
    if (!columna_existe('pedidos', 'comprobante_pide')) return [];

    $hay_firma = columna_existe('pedidos', 'comprobante_pide_en');
    [$sql_ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id');

    /* Lo que necesita pedido_emision_bloqueo() para decir en la bandeja por qué
       una venta todavía no se puede facturar. Las columnas del envío son de
       migraciones posteriores: si faltan, se traen en blanco y la regla lo
       trata como lo que es, una venta que no salió por envío. */
    $col_envio = (columna_existe('pedidos', 'entrega') ? 'pe.entrega' : "''") . ' AS entrega, '
               . (columna_existe('pedidos', 'ubigeo_id') ? 'pe.ubigeo_id' : 'NULL') . ' AS ubigeo_id, '
               . (columna_existe('pedidos', 'despacho_veces') ? 'pe.despacho_veces' : '0') . ' AS despacho_veces';

    return todas(
        "SELECT pe.id, pe.codigo, pe.comprobante_pide, pe.total_centimos,
                pe.cobrado_centimos, e.clave AS estado, $col_envio,
                " . ($hay_firma ? 'pe.comprobante_pide_en' : 'NULL') . " AS pide_en,
                c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
                c.documento, c.tipo_doc,
                ua.nombre AS asesor_nombre, ua.apellidos AS asesor_apellidos
           FROM pedidos pe
           JOIN pedido_estados e ON e.id = pe.estado_id
           JOIN clientes c       ON c.id = pe.cliente_id
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE e.clave <> 'anulado'
            AND pe.comprobante_pide IN ('boleta','factura')
            AND COALESCE(pe.comprobante_tipo, '') = ''
            AND $sql_ambito
          ORDER BY " . ($hay_firma ? 'pe.comprobante_pide_en' : 'pe.id') . " ASC
          LIMIT " . ((int)$tope + 1),   // una de más: para saber si hay más
        $par
    );
}

/**
 * Y LO QUE SE EMITIÓ DE VERDAD, que es la otra columna y se dice con otras
 * palabras: el asesor indica «no necesita» y facturación anota «no se emite».
 * Son la misma opción vista desde los dos lados del mostrador, y mezclarlas
 * —pasarle `comprobante_tipo` a la función del asesor— hacía que a facturación
 * le contestaran con el vocabulario del asesor.
 */
function comprobante_tipo_texto(?string $v): string
{
    /* Los dos primeros salen de la lista única —son la misma palabra— y solo
       'ninguno' se escribe aquí, porque ese SÍ es vocabulario distinto: el
       asesor no lo pide y facturación lo anota como «no se emite». */
    if ((string)$v === 'ninguno') return 'No se emite';
    return (string)(comprobantes_que_se_solicitan()[(string)$v]['nombre'] ?? '');
}

/* ─────────  LA VENTANA QUE SALE AL DECIDIR SOBRE UN PAGO  ───────── */

/**
 * DE DÓNDE VENÍA QUIEN DECIDIÓ SOBRE UN PAGO, Y A DÓNDE VUELVE.
 *
 * Tres pantallas deciden sobre un pago —la bandeja, la ficha del pedido y la
 * pantalla de facturar— y las tres tienen que recuperar a la persona donde
 * estaba. Una sola definición porque son CUATRO controladores los que la
 * necesitan (validar, espera, denegar y comprobante) y ya divergió una vez:
 * «pedido» significaba la ficha, la pantalla de facturar mandaba «pedido», y
 * confirmar un pago desde facturar te dejaba en la ficha — sin el formulario
 * de serie y número, que es lo único que tiene esa pantalla.
 *
 * Lo que no reconozca vuelve a la bandeja, que es donde se pasa el día quien
 * confirma dinero.
 */
function volver_del_pago(string $token, int $pedido_id): string
{
    return match ($token) {
        'pedido'   => '/pedidos/ficha?id=' . $pedido_id,
        'facturar' => '/pedidos/facturar?id=' . $pedido_id,
        default    => '/pagos/por-validar',
    };
}


/**
 * Qué acaba de pasar con un pago, para pintarlo ENCIMA de la pantalla donde
 * se estaba trabajando.
 *
 * POR QUÉ EXISTE (usuario, 2026-09-11): «al aprobar, dejar en espera o denegar
 * un pago, no quiero que lo lleve a la página de facturar. Que salga igual una
 * ventana como pop up, ahí superpuesta, con el mensaje de la acción que
 * realizó». Antes confirmar un pago te sacaba de la bandeja y te dejaba en
 * otra pantalla, con veinte pagos más esperando detrás: facturación perdía la
 * cola en cada confirmación y tenía que volver a buscar dónde iba.
 *
 * Los tres controladores de pago —validar, espera, denegar— vuelven a la
 * pantalla de origen con ?hecho=… y esto lo lee. Devuelve [] cuando no hay
 * nada que enseñar, y también cuando el pedido no se puede mirar: es una
 * ventana informativa, no una puerta, y no puede enseñar el código de un
 * pedido de otro ámbito por venir escrito en la dirección.
 */
function resultado_de_pago(): array
{
    $hecho = pedir('hecho', 'get');
    if (!in_array($hecho, ['aprobado', 'espera', 'denegado', 'comprobante'], true)) return [];

    /* SOLO A QUIEN DECIDE SOBRE EL DINERO. La marca viaja en la dirección, así
       que el asesor podía llegar a su propia ficha con ?hecho=aprobado y leer
       «Pago aprobado» —sin que nadie hubiera aprobado nada— encima de tres
       botones que le contestan 403. */
    if (!puede('pagos.verificar')) return [];

    $id = pedir_int('ped', 'get');
    $p  = $id ? pedido_de($id) : null;
    if (!$p) return [];

    /* Y NO SOBRE UN PEDIDO ANULADO. La marca sobrevive al botón «atrás» del
       navegador, así que se pintaba «El dinero entró · ¿emites el
       comprobante?» encima de la banda roja de anulado, y emitir lo rechaza
       después. Es el mismo fallo que ya se corrigió en la tapa de «Pedido
       registrado»; esta nació sin heredarlo. */
    if ((string)$p['estado'] === 'anulado') return [];

    $equipo = valor('SELECT equipo_id FROM usuarios WHERE id = ?', [(int)$p['asesor_id']]);
    if (!puedo_ver((int)$p['asesor_id'], (int)$p['pais_id'],
                   $equipo !== null ? (int)$equipo : null)) return [];

    $cli = una('SELECT * FROM clientes WHERE id = ?', [(int)$p['cliente_id']]);

    /* EL CASHBACK QUE ACABA DE GANAR EL CLIENTE. Iba en el aviso verde de
       arriba, que esta ventana TAPA —y `avisos()` lo consume en la misma
       petición—: al cerrar la ventana la noticia ya no estaba en ninguna
       parte. Se pregunta por el movimiento del pedido en vez de arrastrarlo en
       la dirección, que cualquiera puede escribir. */
    $cb = $hecho === 'aprobado' && tabla_existe('cashback_movimientos')
        ? (int) valor("SELECT monto_centimos FROM cashback_movimientos
                        WHERE pedido_id = ? AND tipo = 'acredita'
                        ORDER BY id DESC LIMIT 1", [(int)$p['id']])
        : 0;

    return [
        'hecho'    => $hecho,
        'p'        => $p,
        'cashback' => max(0, $cb),
        /* Lo que YA tiene anotado. Si la venta ya lleva comprobante no se
           vuelve a preguntar: es uno por pedido. */
        'ya_tiene' => trim((string)($p['comprobante_tipo'] ?? '')) !== '',
        /* Y lo que el ASESOR indicó al registrar, que es distinto de lo que
           facturación acabe emitiendo: viene marcado, pero no manda. */
        'pide'     => (string)($p['comprobante_pide'] ?? ''),
        'sugerido' => comprobante_marcado($p, $cli)['tipo'],
    ];
}

/* ──────────────────  EL WHATSAPP DE FACTURACIÓN  ────────────────── */

/**
 * El enlace para escribirle a facturación por un pedido trabado, con el código
 * ya escrito. '' si nadie ha puesto el número en Configuración — y entonces el
 * botón no se pinta: uno que abre un chat con nadie es peor que no tenerlo.
 */
function whatsapp_facturacion_url(array $p, string $texto = ''): string
{
    $num = preg_replace('/\D+/', '', (string) ajuste('whatsapp_facturacion', ''));
    if ($num === '' || strlen($num) < 8) return '';

    $texto = $texto !== '' ? $texto
           : 'Hola, es por el pedido ' . (string)($p['codigo'] ?? '') . '.';
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($texto);
}

/**
 * CUÁNTO FALTA REGISTRAR de una venta que YA SALIÓ a despacho, o 0. La misma
 * definición que el aviso «saldo sin registrar» (sql_saldo_por_registrar):
 * la ficha no puede decir otra cosa que la bandeja que llevó hasta ella.
 */
function pedido_saldo_sin_registrar(int $pedido_id): int
{
    if (!columna_existe('pedidos', 'despacho_en')) return 0;
    /* CON EL MISMO PLAZO que el aviso de Administración (saldo_admin_dias,
       contado desde el despacho o desde el día de entrega acordado): una venta
       contraentrega que sale hoy no «debe» nada todavía (auditoría 3b.6). */
    [$sql_desde, $par_desde] = sql_saldo_desde_hace('pe', saldo_admin_dias());
    return max(0, (int) valor("SELECT pe.total_centimos - pe.cobrado_centimos
                                    - COALESCE((SELECT SUM(sp.monto_centimos) FROM pagos sp
                                                 WHERE sp.pedido_id = pe.id AND sp.anulado = 0
                                                   AND sp.verificado = 0), 0)
                                 FROM pedidos pe WHERE pe.id = ? AND " . sql_saldo_por_registrar('pe')
                              . ' AND ' . $sql_desde,
                              array_merge([$pedido_id], $par_desde), 0));
}

/**
 * ESCRIBIRLE AL ASESOR DE UNA VENTA por WhatsApp (usuario, 2026-09-25):
 * Administración veía «saldo sin registrar» y no tenía cómo preguntarle al
 * asesor sin salir del HUB. Devuelve el enlace, o '' si quien mira ES el
 * asesor o el asesor no tiene celular en su ficha.
 * $p necesita asesor_id, codigo y, si lo trae, asesor_nombre y asesor_celular.
 */
function whatsapp_al_asesor(array $p, int $saldo_sin_registrar = 0): string
{
    $yo = yo();
    if (!$yo || (int)$yo['id'] === (int)($p['asesor_id'] ?? 0)) return '';
    /* El celular del asesor es un dato personal: lo ve quien administra las
       cuentas (Administración, Dirección), no Facturación ni el líder. */
    if (!puede('usuarios.gestionar')) return '';
    if ((string)($p['estado'] ?? '') === 'anulado') return '';
    $cel = array_key_exists('asesor_celular', $p)
         ? (string)$p['asesor_celular']
         : (string) valor('SELECT celular FROM usuarios WHERE id = ?', [(int)($p['asesor_id'] ?? 0)], '');
    if (trim($cel) === '') return '';
    $nombre = primer_nombre((string)($p['asesor_nombre'] ?? ''));
    $hola = 'Hola' . ($nombre !== '' ? ' ' . $nombre : '') . ', ';
    $texto = $saldo_sin_registrar > 0
        ? $hola . 'el pedido ' . (string)$p['codigo'] . ' ya se entregó y falta registrar el saldo de '
          . soles($saldo_sin_registrar) . '. ¿Ya lo cobraste?'
        : $hola . 'te escribo por el pedido ' . (string)$p['codigo'] . '.';
    return enlace_whatsapp($cel, $texto);
}

/* ───────────  RECLAMAR QUE CONFIRMEN UNA VENTA  ─────────── */

/**
 * Cuántos minutos espera el asesor antes de poder reclamar. Editable.
 *
 * Cinco por defecto, y NO uno: con un minuto facturación recibe reclamos de
 * ventas que estaba atendiendo en ese momento, y un aviso que suena cuando no
 * toca se aprende a ignorar — con él, los que sí tocan (usuario, 2026-09-14).
 */
function reclamo_minutos(): int
{
    return max(1, (int) ajuste('reclamo_minutos', 5));
}

/**
 * ¿SE PUEDE YA RECLAMAR ESTA VENTA, Y SI NO, POR QUÉ?
 *
 * Devuelve ['puede', 'faltan', 'desde', 'clave', 'motivo'].
 *
 *  · `clave` y `motivo` NO se calculan aquí: salen de pedido_despacho_motivo(),
 *    que es la única definición de por qué una venta no sale. Tenerlos aquí
 *    otra vez es lo que dejó la venta del voucher denegado esperando para
 *    siempre bajo el cartel equivocado.
 *  · Solo se reclama `sin_revisar`. Si facturación ya lo miró y está esperando
 *    al banco (`espera`), reclamar es meterle prisa por algo que no depende de
 *    ella; si lo denegó, lo que toca es registrar el pago bueno.
 *  · `faltan` solo tiene sentido cuando la venta ES reclamable. En los demás
 *    casos se devuelve -1 y no 0: con 0, la cuenta atrás del navegador leía
 *    «no falta nada» y ENCENDÍA el botón en el mismo instante de cargar la
 *    página, justo en los casos que el servidor iba a rechazar.
 *
 * El reloj corre desde el pago MÁS VIEJO sin revisar, o desde el reclamo
 * anterior si es posterior. Desde el más viejo porque el reclamo dice «mi
 * venta lleva parada media hora»: con el más nuevo, registrar el saldo de una
 * pre venta le quitaba al asesor el derecho a reclamar el adelanto que llevaba
 * una hora esperando.
 *
 * La cuenta atrás se pinta en el navegador, pero quien decide es esta función:
 * un contador de JavaScript lo para cualquiera con la consola abierta.
 */
function pedido_reclamo_estado(array $p): array
{
    $fuera = ['puede' => false, 'faltan' => -1, 'desde' => '', 'clave' => '', 'motivo' => ''];
    if (!columna_existe('pedidos', 'reclamo_veces')) return $fuera;

    $m = pedido_despacho_motivo($p);
    $fuera['clave']  = $m['clave'];
    $fuera['motivo'] = $m['texto'];
    if ($m['clave'] !== 'sin_revisar') return $fuera;

    $viejo_pago = (string) valor("SELECT MIN(creado_en) FROM pagos
                                   WHERE pedido_id = ? AND tipo = 'cobro'
                                     AND anulado = 0 AND verificado = 0
                                     AND en_espera = 0", [(int)$p['id']]);
    $ultimo_rec = (string)($p['reclamo_en'] ?? '');
    $desde = max($viejo_pago, $ultimo_rec);
    if ($desde === '') return $fuera;

    $listo  = strtotime($desde) + reclamo_minutos() * 60;
    $faltan = $listo - time();
    return ['puede' => $faltan <= 0, 'faltan' => max(0, $faltan), 'desde' => $desde,
            'clave' => $m['clave'], 'motivo' => $m['texto']];
}

/**
 * El asesor reclama que le confirmen la venta. Deja constancia y sube la fila
 * en la bandeja de facturación; el aviso por WhatsApp lo manda él desde la
 * pantalla, porque el HUB no puede enviar solo sin una API.
 */
function pedido_reclamar(int $pedido_id): array
{
    /* Leer-sumar-escribir con la fila reservada, como toda escritura de este
       archivo y como su gemela pedido_despacho_marcar(): sin el candado, dos
       toques a la vez leen `veces = 0` los dos, escriben 1 los dos, y dejan dos
       líneas en la bitácora para un contador que dice 1. Ese contador es el
       único dato por el que la columna existe. */
    return en_transaccion(function () use ($pedido_id) {

    bloquear_fila('pedidos', $pedido_id);
    $p = pedido_de($pedido_id);
    if (!$p) return ['ok' => false, 'error' => 'Ese pedido no existe.'];
    if (!columna_existe('pedidos', 'reclamo_veces')) {
        return ['ok' => false, 'error' => 'Esta opción todavía no está disponible. Avisa a Administración: falta terminar la actualización.'];
    }

    /* LA MISMA PUERTA QUE LA PANTALLA, otra vez y aquí dentro. El controlador
       ya llama a exigir_editar(), pero toda la defensa del reclamo vivían esas
       tres líneas: bastaba un segundo llamador —o un despiste— para que
       cualquiera reclamara la venta de otro asesor, o de otro país. Las
       escrituras de este archivo se defienden solas. */
    if (!puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])) {
        return ['ok' => false, 'error' => 'Esta venta no es tuya.'];
    }

    $r = pedido_reclamo_estado($p);
    if (!$r['puede']) {
        return ['ok' => false, 'error' => $r['faltan'] > 0
            ? 'Todavía no: faltan ' . ceil($r['faltan'] / 60) . ' minuto(s). '
              . 'Facturación necesita un rato para mirarlo.'
            : ($r['motivo'] !== '' ? $r['motivo'] : 'Esta venta no está esperando confirmación.')];
    }

    $veces = (int)($p['reclamo_veces'] ?? 0) + 1;
    actualizar('pedidos', $pedido_id, [
        'reclamo_veces' => $veces,
        'reclamo_en'    => date('Y-m-d H:i:s'),
        'reclamo_por'   => $_SESSION['usuario_id'] ?? null,
    ]);
    pedido_evento($pedido_id, 'pago', 'Se reclamó que confirmen el pago'
                  . ($veces > 1 ? ' (van ' . $veces . ')' : ''));
    bitacora('pedido.reclamo', 'pedido', $pedido_id, ['veces' => $veces]);

    return ['ok' => true, 'error' => '', 'veces' => $veces];

    });
}

/**
 * CUÁNTAS VECES SE HA RECLAMADO **ESTE PAGO**, EN SQL.
 *
 * El reclamo se anota en el PEDIDO, pero la bandeja de facturación es por
 * PAGO: sin esta distinción, el adelanto reclamado en marzo dejaba marcado
 * «Reclamado» —y arriba del todo— al saldo que entra en junio, que nadie ha
 * reclamado nunca. Con el tiempo las ventas reclamadas alguna vez copaban la
 * cabecera y el distintivo rojo dejaba de significar nada, que es la misma
 * muerte que tuvo el aviso de comprobantes.
 *
 * La regla es simple: un reclamo habla de los pagos que ya existían cuando se
 * mandó. El chip y el orden salen de AQUÍ los dos.
 *
 * La comparación es ESTRICTA y no «>=» porque las dos fechas se guardan al
 * segundo: el saldo que el asesor registra en el mismo segundo en que reclama
 * el adelanto caía dentro por un empate y nacía marcado. Al revés no puede
 * pasar: entre un pago y su reclamo hay cinco minutos como mínimo.
 */
function sql_reclamo_del_pago(string $ped = 'pe', string $pago = 'pg'): string
{
    if (!columna_existe('pedidos', 'reclamo_veces')) return '0';
    return "CASE WHEN $ped.reclamo_en IS NOT NULL AND $ped.reclamo_en > $pago.creado_en
                 THEN $ped.reclamo_veces ELSE 0 END";
}

/** El mensaje que el asesor le manda a facturación por WhatsApp. */
function reclamo_whatsapp_url(array $p): string
{
    $espera = (int) valor("SELECT COALESCE(SUM(monto_centimos),0) FROM pagos
                            WHERE pedido_id = ? AND tipo = 'cobro'
                              AND anulado = 0 AND verificado = 0", [(int)$p['id']]);
    /* «Está esperando para salir a despacho» solo es verdad mientras no haya
       salido. Con el saldo de una pre venta ya despachada, esa frase le decía
       a facturación algo que no era. */
    $sale = (int)($p['despacho_veces'] ?? 0) === 0;
    $texto = 'Hola, ¿pueden confirmar el pago del pedido ' . (string)$p['codigo'] . '?'
           . ($espera > 0 ? ' Son ' . soles($espera) . '.' : '')
           . ($sale ? ' Está esperando para salir a despacho.'
                    : ' Es el saldo de una venta que ya salió.');
    return whatsapp_facturacion_url($p, $texto);
}

/* ─────────────────────────  LA GARANTÍA  ───────────────────────── */

/**
 * La garantía que viene marcada para un tipo de venta.
 *
 * Dos reglas, las dos editables sin tocar código:
 *  · si algún item de la lista lleva ese tipo de venta escrito en su `extra`,
 *    ese es el suyo — así «Liquidación» nace en «No aplica» y no en doce
 *    meses, que es lo que el asesor tendría que acordarse de cambiar en cada
 *    venta, y el día que se olvide Waka responde un año por un saldo de
 *    almacén;
 *  · si no, el PRIMER item encendido. No hay columna «por defecto» y no hace
 *    falta: el orden de la lista ya se edita en Configuración.
 *
 * Devuelve null si la lista todavía no existe, y entonces el pedido se guarda
 * sin garantía en vez de reventar: un HUB al que no le han pasado
 * actualizar.php tiene que poder seguir vendiendo.
 */
function garantia_por_defecto(string $tipo = '', ?int $pais_id = null): ?int
{
    $items = lista('garantias', $pais_id);
    if (!$items) return null;
    if ($tipo !== '') {
        foreach ($items as $i) {
            if ((string)($i['extra'] ?? '') === $tipo) return (int)$i['id'];
        }
    }
    return (int)$items[0]['id'];
}

/**
 * La garantía de un pedido, tal como se enseña. '' si no se guardó ninguna
 * —los pedidos de antes de esta versión—, y entonces las pantallas no pintan
 * la fila: es más honesto que inventarle doce meses a una venta vieja.
 */
function pedido_garantia_texto(array $p): string
{
    $id = (int)($p['garantia_item_id'] ?? 0);
    return $id ? lista_texto($id) : '';
}

function pedido_recalcular(int $pedido_id): array
{
    $p = una('SELECT * FROM pedidos WHERE id = ?', [$pedido_id]);
    if (!$p) return [];

    $subtotal = (int) valor('SELECT COALESCE(SUM(total_centimos),0) FROM pedido_lineas
                              WHERE pedido_id = ?', [$pedido_id]);

    $flete_dentro = pedido_flete_dentro($p);

    $total = $subtotal - (int)$p['descuento_centimos'] - (int)$p['cashback_usado_centimos']
           + $flete_dentro;
    if ($total < 0) $total = 0;

    // Cobrado = lo que de verdad entró. Las devoluciones son filas con monto
    // negativo, así que restan solas. Un pago sin validar no cuenta.
    $cobrado = (int) valor(
        'SELECT COALESCE(SUM(monto_centimos),0) FROM pagos
          WHERE pedido_id = ? AND anulado = 0 AND verificado = 1', [$pedido_id]
    );

    actualizar('pedidos', $pedido_id, [
        'subtotal_centimos' => $subtotal,
        'total_centimos'    => $total,
        'cobrado_centimos'  => $cobrado,
    ]);

    return ['subtotal' => $subtotal, 'total' => $total, 'cobrado' => $cobrado,
            'saldo' => $total - $cobrado];
}

function pedido_saldo(array $p): int
{
    return (int)$p['total_centimos'] - (int)$p['cobrado_centimos'];
}

/**
 * Lo que falta por cobrar CONTANDO lo ya registrado y todavía sin confirmar.
 *
 * Es distinto de pedido_saldo() y la diferencia costó una auditoría. El saldo
 * de arriba solo descuenta el dinero CONFIRMADO; desde que ningún método cuenta
 * al instante, un pedido recién pagado tiene su saldo entero durante horas.
 * Comprobar contra ese número dejaba registrar el mismo importe una y otra vez
 * —el asesor no está seguro de si guardó, la página va lenta, el cliente
 * reenvía el voucher— y al confirmarlos todos, el pedido acababa cobrado por
 * el triple de su total, con la meta, el podio y el cashback inflados.
 *
 * Los pagos EN ESPERA sí se descuentan aquí: están registrados, y volver a
 * registrar el mismo dinero no es la solución a que el banco tarde.
 */
function pedido_por_registrar(array $p): int
{
    $pendiente = (int) valor(
        'SELECT COALESCE(SUM(monto_centimos),0) FROM pagos
          WHERE pedido_id = ? AND anulado = 0 AND verificado = 0', [(int)$p['id']]
    );
    return max(0, (int)$p['total_centimos'] - (int)$p['cobrado_centimos'] - $pendiente);
}

/**
 * ¿A ESTE PEDIDO LO VA A ANULAR SOLO LA TAREA DE LAS 48 HORAS?
 *
 * La misma condición que `pedidos_anular_denegados()`, dicha por UN pedido.
 * Existe porque la banda roja de la ficha ponía la fecha de anulación en
 * negrita con solo ver un pago denegado, y la tarea exige dos cosas más:
 * que el pedido no haya salido (estados_sin_salir) y que NO le quede ningún
 * pago vivo. Una pre venta con el adelanto confirmado y el saldo denegado leía
 * una fecha que nunca iba a llegar — y una amenaza que no se cumple enseña a
 * no creerse las demás.
 */
function pedido_se_anula_solo(array $p): bool
{
    if (!in_array((string)$p['estado'], estados_sin_salir(), true)) return false;
    if (!columna_existe('pagos', 'denegado')) return false;
    /* La misma condición de fecha que la tarea, para los estados nuevos. */
    if (!in_array((string)$p['estado'], ['registrado', 'reservado'], true)
        && !valor('SELECT 1 FROM pagos WHERE pedido_id = ? AND denegado = 1 AND anulado_en >= ?', [(int)$p['id'], estados_auto_desde()])) return false;
    return (int) valor("SELECT COUNT(*) FROM pagos
                         WHERE pedido_id = ? AND tipo = 'cobro' AND anulado = 0", [(int)$p['id']]) === 0;
}

/** EL PLAZO, en una sola definición: lo usan la tarea que anula y la banda
    roja de la ficha, que es donde el asesor lee la fecha en negrita. */
const HORAS_PARA_ARREGLAR_DENEGADO = 48;

/**
 * Anula los pedidos cuyo pago denegó facturación y nadie arregló en 48 horas.
 *
 * ANULA, NO BORRA, y la diferencia importa. Un pago denegado es justo el caso
 * que después hace falta poder mirar: el cliente dice que pagó, facturación
 * dice que el voucher no cuadra. Borrar el pedido destruiría la única prueba
 * de esa discusión, y además sería la única acción destructiva automática de
 * todo el HUB. Anulado sale de las listas activas, no cuenta para nada y
 * Administración puede revertirlo.
 *
 * Las 48 horas se cuentan desde la ÚLTIMA denegación, no desde la primera: si
 * el asesor registró otro pago y también se lo denegaron, el plazo empieza de
 * nuevo. Y un pedido con dinero registrado esperando confirmación no se toca,
 * por muchas denegaciones que arrastre: alguien ya lo corrigió.
 *
 * Devuelve cuántos anuló.
 */
function pedidos_anular_denegados(int $horas = HORAS_PARA_ARREGLAR_DENEGADO): int
{
    if (!tabla_existe('pagos') || !columna_existe('pagos', 'denegado')) return 0;

    $limite = date('Y-m-d H:i:s', strtotime('-' . max(1, $horas) . ' hours'));

    /* Pedidos vivos, sin un solo pago que cuente ni pendiente de mirar, con al
       menos una denegación y la última ya pasada de plazo. */
    /* SOLO PEDIDOS QUE NO HAN SALIDO. Un pedido entregado no se anula porque
       su pago se denegara: la mercadería está en casa del cliente y lo que
       queda es una deuda que cobrar, no una venta que borrar. Anularlo la hacía
       desaparecer de «por cobrar» y liberaba su stock. */
    $ids = todas(
        "SELECT pe.id
           FROM pedidos pe
           JOIN pedido_estados es ON es.id = pe.estado_id
          WHERE pe.anulado_en IS NULL
            AND (es.clave IN ('registrado', 'reservado')
                 OR (es.clave IN ('" . implode("','", estados_sin_salir()) . "')
                     AND EXISTS (SELECT 1 FROM pagos pd3 WHERE pd3.pedido_id = pe.id AND pd3.denegado = 1
                                    AND pd3.anulado_en >= ?)))
            AND NOT EXISTS (SELECT 1 FROM pagos px
                             WHERE px.pedido_id = pe.id AND px.anulado = 0
                               AND px.tipo = 'cobro')
            AND EXISTS (SELECT 1 FROM pagos pd
                         WHERE pd.pedido_id = pe.id AND pd.denegado = 1
                           AND pd.anulado_en IS NOT NULL AND pd.anulado_en <= ?)
            AND NOT EXISTS (SELECT 1 FROM pagos pn
                             WHERE pn.pedido_id = pe.id AND pn.denegado = 1
                               AND (pn.anulado_en IS NULL OR pn.anulado_en > ?))
          ORDER BY pe.id
          LIMIT 50",
        [estados_auto_desde(), $limite, $limite]
    );

    $n = 0;
    foreach ($ids as $f) {
        $id = (int) $f['id'];

        /* El motivo se busca por PEDIDO y no una vez para el lote: los motivos
           pueden ser de un país, y con el del primero los del otro país
           fallaban en silencio y se reintentaban para siempre. */
        $pais = (int) valor('SELECT pais_id FROM pedidos WHERE id = ?', [$id]);
        $motivo = lista_item_por_texto('motivos_anulacion', 'No pagó en el plazo', $pais)
               ?: lista_item_por_texto('motivos_anulacion', 'Otro motivo', $pais);
        if (!$motivo) {
            error_log('[HUB] anular denegados: sin motivo de anulación para el país ' . $pais);
            continue;
        }

        /* Sin firma (lo anuló el HUB) y con la última mirada dentro de la
           transacción: entre que se hizo la lista y llegamos aquí puede haber
           entrado el pago corregido. */
        $r = pedido_anular_hacer($id, (int)$motivo['id'],
            'Facturación denegó el pago y pasaron ' . $horas
            . ' horas sin que nadie lo corrigiera. Anulado automáticamente.',
            null, true);
        if ($r['ok'] ?? false) $n++;
    }
    return $n;
}

/**
 * Las tareas que no dispara nadie abriendo una pantalla, con su freno.
 *
 * El HUB no tiene un reloj propio: solo hace cosas cuando alguien entra. Por
 * eso esto corre desde `tareas.php` (cron, una vez al día) Y de paso desde la
 * lista de pedidos, como mucho UNA VEZ POR HORA. Así funciona aunque nadie
 * configure el cron, que es la diferencia entre una regla que se cumple y una
 * que espera a que alguien se acuerde.
 *
 * La marca de la última pasada vive en `ajustes`, no en un archivo: el hosting
 * puede tener la carpeta de solo lectura y dos peticiones a la vez escribiendo
 * el mismo archivo se pisan.
 */
function tareas_del_hub(bool $con_freno = false, int $minutos = 60): int
{
    if (!tabla_existe('ajustes')) return 0;

    $clave = 'tareas_ultima';
    if ($con_freno) {
        $ultima = (string) (valor('SELECT valor FROM ajustes WHERE clave = ?', [$clave]) ?? '');
        if ($ultima !== '' && strtotime($ultima) > time() - $minutos * 60) return 0;
    }

    try {
        /* A pelo y no con sembrar_fila(): `ajustes` tiene la CLAVE como clave
           primaria y no tiene columna `id`, así que sembrar_fila() —que
           actualiza por id— lanzaba una excepción que este mismo catch se
           tragaba. Resultado: la marca no se escribía nunca, el barrido no
           corría nunca, y tareas.php salía con código 0 diciendo que todo
           bien. Así lo escribe el resto del HUB (frases.php, semillas.php). */
        $existe = (int) valor('SELECT COUNT(*) FROM ajustes WHERE clave = ?', [$clave]);
        if ($existe) q('UPDATE ajustes SET valor = ? WHERE clave = ?', [date('Y-m-d H:i:s'), $clave]);
        else q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
                VALUES (?, ?, 'texto', 'general',
                        'Cuándo corrieron por última vez las tareas automáticas. No tocar.', 1)",
               [$clave, date('Y-m-d H:i:s')]);

        $n = pedidos_anular_denegados();
        /* Los bonos cuyo período terminó se cierran con sus cifras (5a). */
        if (function_exists('bonos_cerrar_vencidos')) {
            try { bonos_cerrar_vencidos(); } catch (Throwable $ex) { error_log('[HUB] cierre de bonos: ' . $ex->getMessage()); }
        }
        return $n;
    } catch (Throwable $ex) {
        /* Una tarea de fondo NUNCA puede tumbar la pantalla desde la que se
           disparó: el asesor venía a ver sus pedidos. */
        error_log('[HUB] tareas: ' . $ex->getMessage());
        return 0;
    }
}

/**
 * Lo que el cliente ya pagó, desde el punto de vista DEL CLIENTE.
 *
 * Tres cifras distintas y cada una para lo suyo:
 *  · `cobrado_centimos` — lo que facturación ya confirmó. Es lo que suma a la
 *    meta y al podio.
 *  · `pedido_por_registrar()` — lo que falta por registrar. Cuenta lo que está
 *    EN ESPERA como registrado, porque volver a registrar el mismo dinero no
 *    es la solución a que el banco tarde.
 *  · esta — lo que el cliente pagó y nadie ha puesto en duda. Cuenta lo que
 *    espera revisión, pero NO lo que facturación marcó en espera: ahí el banco
 *    dice que ese dinero no llegó, y al cliente hay que seguir pidiéndoselo.
 *
 * Es la que va en los mensajes al cliente. Con `cobrado_centimos` le decíamos
 * «adelanto recibido: S/ 0.00» a alguien que acababa de pagar; con
 * `por_registrar` le callábamos el importe justo cuando facturación había
 * dicho que el dinero no estaba.
 */
function pedido_pagado_cliente(array $p): int
{
    $sin_dudas = (int) valor(
        "SELECT COALESCE(SUM(monto_centimos),0) FROM pagos
          WHERE pedido_id = ? AND anulado = 0 AND verificado = 0
            AND tipo = 'cobro' AND en_espera = 0", [(int)$p['id']]
    );
    return min((int)$p['total_centimos'], (int)$p['cobrado_centimos'] + $sin_dudas);
}

/** Lo que le queda por pagar al cliente, con la cuenta de arriba. */
function pedido_por_cobrar_cliente(array $p): int
{
    return max(0, (int)$p['total_centimos'] - pedido_pagado_cliente($p));
}

/** Dinero registrado que todavía no ha mirado nadie. */
function pedido_pendiente_confirmar(array $p): int
{
    return max(0, (int)$p['total_centimos'] - (int)$p['cobrado_centimos'] - pedido_por_registrar($p));
}

/* ─────────────────────────  ESTADO  ───────────────────────── */

/**
 * Cuando cambia el dinero (un pago, una reversión, un descuento), el estado se
 * vuelve a mirar: una venta cobrada al 100 % pasa a «Pagado», y si se revierte
 * un pago vuelve a «Registrado». Desde la 3j es la misma regla que todo lo
 * demás (pedido_estado_auto): el dinero ya no pisa «En despacho» ni «Entregado».
 */
function pedido_avanzar_si_esta_pagado(int $pedido_id): void
{
    pedido_estado_auto($pedido_id);
}

function pedido_retroceder_si_debe(int $pedido_id): void
{
    pedido_estado_auto($pedido_id);
}

/* ──────────────────  EL COMPROBANTE Y EL DESPACHO  ────────────────── */

/**
 * Qué comprobante le toca a este cliente, para dejarlo ya marcado.
 *
 * No es adivinar: la ficha del cliente ya dice si pide boleta o factura, y una
 * factura sin RUC no se puede rehacer —se anula y se emite otra—, así que el
 * dato tiene que venir de la ficha y no del dedo de quien factura.
 */
function comprobante_sugerido(?array $cliente): string
{
    if (!$cliente) return 'boleta';
    return (string)($cliente['tipo_comprobante'] ?? 'boleta') === 'factura' ? 'factura' : 'boleta';
}

/**
 * CUÁL VIENE MARCADO EN LA PANTALLA DE FACTURAR, y por qué.
 *
 * Manda LO QUE PIDIÓ EL ASESOR, y solo si no pidió nada se mira la ficha del
 * cliente. Es el orden correcto y costó un fallo: la pantalla de facturar se
 * saltaba la solicitud y marcaba lo que decía la ficha, así que a un cliente
 * con RUC que había pedido boleta se le premarcaba FACTURA — con su razón
 * social lista para copiar—, y un clic emitía una factura que va a SUNAT y que
 * solo se arregla anulándola. Al revés igual, y ahí ni el freno del RUC salta.
 *
 * Devuelve también POR QUÉ, para que la pantalla lo diga en vez de premarcar
 * en silencio: nadie tiene que adivinar de dónde salió la marca.
 */
function comprobante_marcado(array $p, ?array $cliente): array
{
    $pide = (string)($p['comprobante_pide'] ?? '');
    if (isset(comprobantes_que_se_solicitan()[$pide])) {
        return ['tipo' => $pide, 'porque' => 'pide', 'texto' => 'lo pidió el asesor'];
    }
    return ['tipo' => comprobante_sugerido($cliente), 'porque' => 'ficha',
            'texto' => 'lo que pide su ficha'];
}

/**
 * Anota que se emitió la boleta o la factura de esta VENTA.
 *
 * Va en el pedido y no en el pago: una pre venta con adelanto y saldo lleva un
 * solo comprobante aunque el dinero entre dos veces (lo decidió el usuario el
 * 2026-09-09). Si ya hay uno anotado, la pantalla lo enseña en vez de volver a
 * preguntar, y para cambiarlo hay que decirlo a propósito.
 *
 * Serie y número son opcionales: Tumifactura se llena a mano y obligar a
 * teclear B001-00001234 en cada venta frena a facturación toda la jornada. Sin
 * ellos el reporte igual sabe que se emitió, que es lo que hace falta para que
 * ninguna venta se quede sin comprobante.
 *
 * `ninguno` es una decisión, no un hueco: significa «esta venta no lleva
 * comprobante», y se distingue de «todavía no se ha emitido» (NULL).
 */
function pedido_comprobante(int $pedido_id, string $tipo, string $serie = '', string $numero = ''): array
{
    $tipo   = mb_strtolower(trim($tipo));
    $serie  = mb_strtoupper(trim($serie));
    $numero = trim($numero);

    if (!in_array($tipo, ['boleta', 'factura', 'ninguno'], true)) {
        return ['ok' => false, 'error' => 'Elige boleta, factura o «no se emite».'];
    }
    if (mb_strlen($serie) > 10 || mb_strlen($numero) > 20) {
        return ['ok' => false, 'error' => 'La serie o el número son demasiado largos. No se guardó nada.'];
    }
    /* Se rechaza en vez de recortar, igual que el N.º de operación: un número
       de comprobante cortado no cuadra con SUNAT, que es su único uso. */
    if ($serie !== '' && !preg_match('/^[A-Z0-9]{1,10}$/', $serie)) {
        return ['ok' => false, 'error' => 'La serie solo lleva letras y números, como B001 o F001.'];
    }
    if ($numero !== '' && !preg_match('/^[0-9]{1,20}$/', $numero)) {
        return ['ok' => false, 'error' => 'El número del comprobante solo lleva dígitos.'];
    }

    /* «NO SE EMITE» BORRA SERIE Y NÚMERO. Antes se guardaban: quien corregía
       una boleta mal anotada marcaba «no se emite» y el pedido quedaba
       diciendo a la vez «esta venta no lleva comprobante» y «B001-00001234».
       El reporte lo contaba como sin comprobante y la ficha enseñaba el
       número: dos pantallas contando cosas distintas del mismo pedido. */
    if ($tipo === 'ninguno') { $serie = ''; $numero = ''; }

    $p = pedido_de($pedido_id);
    if (!$p) return ['ok' => false, 'error' => 'Ese pedido no existe.'];

    /* Una venta anulada no lleva comprobante. Registrar y despachar ya lo
       rechazaban; esto no, así que la pantalla invitaba a emitir en Tumifactura
       la boleta de una venta que ya no existe — y esa boleta sí va a SUNAT. */
        if ((string)$p['estado'] === 'anulado') {
                return ['ok' => false, 'error' => 'Este pedido está anulado: no lleva comprobante. '
            . 'Si ya se emitió uno a mano, anúlalo con su nota de crédito en tu sistema de facturación.'];
    }
    /* Con uno ELECTRÓNICO vigente no se anota nada a mano encima: ese ya está
       en SUNAT, y lo que corrige un comprobante electrónico es su nota de
       crédito, no un cambio en el HUB (parche 2u). */
    if (function_exists('nubefact_vigente') && nubefact_vigente($pedido_id)) {
        return ['ok' => false, 'error' => 'Esta venta ya tiene su comprobante electrónico emitido. '
                                        . 'Para cambiarlo, anula la venta: se emite su nota de crédito.'];
    }
    if (function_exists('nubefact_pendiente') && nubefact_pendiente($pedido_id)) {
        return ['ok' => false, 'error' => 'Esta venta tiene una boleta o factura que se mandó a NUBEFACT sin '
                                        . 'respuesta. Pulsa «Emitir» otra vez para saber si salió.'];
    }

    /* Con el descuento esperando aprobación (3d), el total todavía puede
       cambiar: una boleta anotada ahora podría decir otro importe que la
       venta. «No lleva» sí se puede marcar: no dice ningún importe. */
    if ($tipo !== 'ninguno' && function_exists('pedido_descuento_pendiente') && pedido_descuento_pendiente($p)) {
        return ['ok' => false, 'error' => 'El descuento de esta venta espera que Administración lo apruebe: '
                                        . 'el total todavía puede cambiar. Anótalo cuando respondan.'];
    }

    /* Una factura sin RUC se anula y hay que rehacerla. Mejor no dejar
       anotarla que dejar constancia de algo que SUNAT va a rebotar. */
    if ($tipo === 'factura') {
        $cli = una('SELECT ruc_factura, documento, tipo_doc FROM clientes WHERE id = ?',
                   [(int)$p['cliente_id']]);
        $ruc = trim((string)($cli['ruc_factura'] ?? ''));
        if ($ruc === '' && (string)($cli['tipo_doc'] ?? '') !== 'RUC') {
            return ['ok' => false, 'error' => 'Este cliente no tiene RUC en su ficha. '
                . 'Pídeselo al asesor antes de emitir: una factura sin RUC se anula.'];
        }
    }

    actualizar('pedidos', $pedido_id, [
        'comprobante_tipo'   => $tipo,
        'comprobante_serie'  => $serie ?: null,
        'comprobante_numero' => $numero ?: null,
        'comprobante_por'    => $_SESSION['usuario_id'] ?? null,
        'comprobante_en'     => date('Y-m-d H:i:s'),
    ]);

    $texto = $tipo === 'ninguno'
        ? 'Se marcó que esta venta no lleva comprobante'
        : 'Se emitió ' . $tipo . (trim($serie . '-' . $numero, '-') !== ''
                                  ? ' ' . trim($serie . '-' . $numero, '-') : '');
    pedido_evento($pedido_id, 'comprobante', $texto);
    bitacora('pedido.comprobante', 'pedido', $pedido_id, ['tipo' => $tipo]);
    comprobantes_pendientes_olvidar();

    return ['ok' => true, 'error' => ''];
}

/**
 * Cuántas ventas VIVAS siguen sin comprobante anotado, para quien pregunta.
 *
 * Existe porque «omitir por ahora» solo es una opción honesta si lo omitido se
 * vuelve a ver. La tarjeta del reporte enseña las del rango que se esté
 * mirando —y el rango nace en el mes en curso—, así que una pre venta de hace
 * tres meses se quedaba fuera sin que nada lo dijera: la lista parecía vacía
 * porque el filtro la tapaba, no porque estuviera todo emitido. Este número no
 * lleva rango: es la cola entera.
 *
 * `ninguno` NO cuenta: es una decisión tomada, no un pendiente. Y los pedidos
 * anulados tampoco, que no llevan comprobante.
 *
 * Una sola definición, como todas las cifras del HUB: de aquí salen el aviso
 * de la bandeja y la nota del reporte. Escrita dos veces darían dos números
 * del mismo dato, que es el fallo que más veces ha aparecido en este proyecto.
 */
/**
 * Las ventas de esta persona que YA se pueden mandar a despacho y todavía no
 * se han mandado.
 *
 * Es la contrapartida de la regla «solo sale a despacho con el pago
 * confirmado»: si el HUB frena el envío hasta que facturación confirme, tiene
 * que ser el HUB quien avise cuando ya se puede. Si no, el asesor manda el
 * pedido, choca contra el freno, y **nadie le dice nunca que ya está libre**:
 * la venta se queda en el aire y el cliente llama a los tres días.
 * Lo pidió el usuario el 2026-09-09: *«al asesor le tendría que llegar una
 * notificación tipo pedido confirmado - despacho pendiente»*.
 *
 * Cuenta lo que esta persona puede MANDAR, no lo que puede mirar: el asesor,
 * lo suyo. `ultimo` es la marca de agua para el aviso en vivo — el id más alto
 * de la cola, con el que se distingue «entró uno nuevo» de «hay siete de ayer».
 */
/**
 * EL ÁMBITO DE LO QUE UNO PUEDE MANDAR, que NO es el de lo que puede ver.
 *
 * `filtro_ambito()` contesta «qué registros me tocan a mí» para MIRAR, y con
 * ámbito «equipo» incluye los de todo el equipo. Pero el ámbito «equipo» es de
 * SOLO LECTURA en todo el HUB: el líder ve las ventas de los suyos y no las
 * manda —`pedido_despacho_marcar()` exige `puedo_editar()` y le contesta 403—.
 * Contarlas en su chip y en su aviso le ponía un número que NO PUEDE BAJAR
 * NUNCA, que es exactamente el contador rojo permanente que este archivo
 * rechaza por escrito dos veces. Lo encontró una auditoría el 2026-09-14.
 *
 * Se escribe aquí una sola vez y la usan el contador, el aviso de la lista de
 * pedidos y la propia pantalla «Por mandar a despacho»: si una de las tres
 * contara distinto, tendríamos otra vez dos cifras del mismo dato.
 */
function filtro_ambito_despacho(string $col_asesor, ?string $col_pais = null,
                                ?string $col_equipo = null, ?array $u = null): array
{
    $u ??= yo();
    if (!$u) return ['1 = 0', []];
    if (($u['ambito'] ?? '') === 'equipo') return ["$col_asesor = ?", [(int)$u['id']]];
    return filtro_ambito($col_asesor, $col_pais, $col_equipo, $u);
}

function pedidos_por_despachar_resumen(?array $u = null, bool $olvidar = false): array
{
    static $cache = [];
    $vacio = ['n' => 0, 'ultimo' => 0];
    if ($olvidar) { $cache = []; return $vacio; }

    $u ??= yo();
    if (!$u) return $vacio;
    $llave = (int)$u['id'];
    if (isset($cache[$llave])) return $cache[$llave];

    /* Esto corre en el MARCO —el chip «Por despachar» de cada página—, así que
       una columna que falte aquí no rompe una pantalla: las rompe todas. Sin
       `despacho_veces` no hay nada que contar; se contesta cero, que es la
       forma segura de fallar mientras alguien pasa actualizar.php. */
    if (!tabla_existe('pedidos') || !columna_existe('pedidos', 'despacho_veces')) return $vacio;

    [$ambito, $par] = filtro_ambito_despacho('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);

    $f = una(
        'SELECT COUNT(*) AS n, COALESCE(MAX(pe.id), 0) AS ultimo
           FROM pedidos pe
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE pe.anulado_en IS NULL
            AND pe.despacho_veces = 0
            AND ' . sql_despacho_listo() . '
            AND ' . $ambito, $par);

    return $cache[$llave] = [
        'n'      => (int)($f['n'] ?? 0),
        'ultimo' => (int)($f['ultimo'] ?? 0),
    ];
}

function despacho_pendiente_olvidar(): void
{
    pedidos_por_despachar_resumen(null, true);
}

/**
 * ¿A esta persona le avisamos de los despachos pendientes?
 *
 * Solo a quien tiene que mandarlos, que es el ASESOR. Administración puede
 * hacerlo —es el respaldo cuando el asesor está de baja— pero no se le pinta el
 * contador: un número rojo que no es tarea de uno se deja de mirar en tres
 * días, y con él se dejan de mirar los demás. Es la misma decisión que se tomó
 * con Dirección y los pagos (`le_avisamos_de_pagos`).
 */
function le_avisamos_de_despacho(?array $u = null): bool
{
    $u ??= yo();
    if (!$u) return false;
    if (!puede_el($u, 'pedidos.despachar')) return false;
    return in_array((string)$u['rol'], ['asesor', 'desarrollador'], true);
}

/* ══════════════════════════════════════════════════════════════════════
 * EL SALDO QUE NADIE REGISTRÓ (parche 2s)
 *
 * «Si alguien compra para Lima contraentrega, el cliente adelanta, el asesor
 * registra la venta; luego tiene que registrarse el monto restante para que
 * recién sume a su meta como cobrado. Esto para obligar al asesor a que lo
 * actualice» (usuario, 2026-09-21).
 *
 * La venta ya SALIÓ —se mandó a despacho— y le queda dinero por registrar: el
 * repartidor cobró, pero el voucher de ese cobro no está en el HUB. Al día
 * siguiente del despacho se le avisa al asesor; a los tres días lo ve también
 * Administración. Los dos plazos son ajustes, no números del código.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * La condición en SQL. Es pedido_por_registrar() dicha para una consulta:
 * lo que falta DESPUÉS de contar lo confirmado y lo registrado sin confirmar.
 * Un saldo que ya se subió y espera a facturación no es «por registrar»: el
 * asesor ya hizo su parte. `$alias` es el alias de `pedidos`.
 */
function sql_saldo_por_registrar(string $alias = 'pe'): string
{
    /* UNA DEVOLUCIÓN SACA LA VENTA DEL AVISO. Devolver dinero al cliente baja
       lo cobrado pero no el total, así que la venta parecía deber para
       siempre, y el asesor no puede arreglarlo: registrar ese «cobro» sería
       falso (auditoría del 2s). Una venta con devolución la está manejando
       Administración a mano; no es un saldo olvidado. */
    $sin_devolucion = columna_existe('pagos', 'tipo')
        ? " AND NOT EXISTS (SELECT 1 FROM pagos sd WHERE sd.pedido_id = $alias.id
                              AND sd.tipo = 'devolucion' AND sd.anulado = 0)"
        : '';
    return "$alias.anulado_en IS NULL AND $alias.despacho_veces > 0
            AND $alias.despacho_en IS NOT NULL
            AND $alias.total_centimos - $alias.cobrado_centimos
                - COALESCE((SELECT SUM(sp.monto_centimos) FROM pagos sp
                             WHERE sp.pedido_id = $alias.id AND sp.anulado = 0
                               AND sp.verificado = 0), 0) > 0" . $sin_devolucion;
}

/**
 * Desde cuándo cuenta el plazo: el día del DESPACHO, o el día de ENTREGA
 * acordado si es posterior. Una venta mandada hoy para entregar el viernes no
 * se ha cobrado mañana, y avisar entonces «registra el cobro» es pedir algo
 * que todavía no pasó (auditoría del 2s). Devuelve [sql, parámetros].
 */
function sql_saldo_desde_hace(string $alias, int $dias): array
{
    $tope = date('Y-m-d', strtotime('-' . max(0, $dias) . ' days'));
    $con_entrega = columna_existe('pedidos', 'entrega_fecha')
        ? " AND ($alias.entrega_fecha IS NULL OR $alias.entrega_fecha <= ?)" : '';
    return ["DATE($alias.despacho_en) <= ?" . $con_entrega,
            $con_entrega !== '' ? [$tope, $tope] : [$tope]];
}

/**
 * Las ventas despachadas hace $dias días o más con saldo por registrar.
 *
 * `$solo_suyas`: el aviso del asesor es personal —«tienes…»—, así que un líder
 * ve las suyas y no las de su equipo. Administración mira todo su ámbito.
 * Sin las columnas del despacho —HUB a medio actualizar— devuelve vacío.
 */
function pedidos_saldo_pendiente(?array $u, int $dias, int $tope = 20, bool $solo_suyas = false): array
{
    $u ??= yo();
    if (!$u || !columna_existe('pedidos', 'despacho_en')) return [];

        [$sql_where, $par] = saldo_pendiente_where($u, $dias, $solo_suyas);

    return todas(
        "SELECT pe.id, pe.codigo, pe.despacho_en, pe.total_centimos, pe.cobrado_centimos,
                pe.asesor_id, ua.nombre AS asesor_nombre,
                c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos,
                pe.total_centimos - pe.cobrado_centimos
                  - COALESCE((SELECT SUM(sp.monto_centimos) FROM pagos sp
                               WHERE sp.pedido_id = pe.id AND sp.anulado = 0
                                 AND sp.verificado = 0), 0) AS falta
           FROM pedidos pe
           JOIN clientes c       ON c.id  = pe.cliente_id
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
                    WHERE " . $sql_where . "
          ORDER BY pe.despacho_en ASC
          LIMIT " . (int)$tope,
        $par
    );
}

/** El mismo filtro, en un solo sitio, para la lista y para el conteo. */
function saldo_pendiente_where(array $u, int $dias, bool $solo_suyas): array
{
    [$sql_ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    [$sql_desde, $par_desde] = sql_saldo_desde_hace('pe', $dias);
    $where = [$sql_ambito, sql_saldo_por_registrar('pe'), $sql_desde];
    $par = array_merge($par, $par_desde);
    if ($solo_suyas) { $where[] = 'pe.asesor_id = ?'; $par[] = (int)$u['id']; }
    return [implode(' AND ', $where), $par];
}

/**
 * ¿A ESTA PERSONA se le avisa de los saldos que los asesores no registraron?
 * A Administración, que es quien pregunta. A Dirección no (usuario,
 * 2026-09-28: «no tiene nada que ver con el CEO»): lleva los permisos de
 * gestión pero no persigue saldos. Una sola definición para el aviso de
 * Inicio y para lo que pide la ficha.
 */
function avisa_saldos_sin_registrar(?array $u = null): bool
{
    $u ??= yo();
    return $u !== null && puede_el($u, 'usuarios.gestionar') && ($u['rol'] ?? '') !== 'direccion';
}

/**
 * CUÁNTOS SON, no cuántos cupieron en la lista. Con el tope de la lista la
 * bandeja decía «100» habiendo 340, y el primer día del parche van a aparecer
 * todos los saldos viejos de golpe (auditoría del 2s).
 */
function pedidos_saldo_pendiente_n(?array $u, int $dias, bool $solo_suyas = false): int
{
    $u ??= yo();
    if (!$u || !columna_existe('pedidos', 'despacho_en')) return 0;
    [$sql_where, $par] = saldo_pendiente_where($u, $dias, $solo_suyas);
    return (int) valor("SELECT COUNT(*) FROM pedidos pe
                         LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
                        WHERE " . $sql_where, $par);
}

/** Los dos plazos, editables en Configuración › Ajustes. */
function saldo_aviso_dias(): int  { return max(0, (int) ajuste('saldo_aviso_dias', 1)); }
function saldo_admin_dias(): int  { return max(0, (int) ajuste('saldo_admin_dias', 3)); }

/**
 * Cuándo hay que entregar: fecha, hora o rango, y si se deja en recepción.
 *
 * Lo pidió el usuario el 2026-09-09. Se pregunta al REGISTRAR la venta —que es
 * cuando el cliente lo dice— y se puede corregir al mandar a despacho, porque
 * los clientes reprograman.
 *
 * Todo es opcional: la mayoría de las entregas no tienen hora, y un campo
 * obligatorio que casi siempre va vacío se acaba llenando con basura.
 */
function pedido_entrega_validar(array $d): array
{
    $fecha = trim((string)($d['fecha'] ?? ''));
    $desde = trim((string)($d['hora_desde'] ?? ''));
    $hasta = trim((string)($d['hora_hasta'] ?? ''));

    $hora_ok = fn(string $h) => $h === '' || (bool) preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $h);

        if ($fecha !== '' && !fecha_valida($fecha)) {
        return ['ok' => false, 'error' => 'Esa fecha de entrega no existe.'];
    }
        /* NUNCA EL MISMO DÍA (usuario, 2026-09-20). Cuando se registra la venta la
       ruta de hoy ya está armada, así que «hoy a las 6» es una promesa que
       despacho no puede cumplir; lo más cerca es mañana. La regla vive en el
       validador —no en la pantalla— para que valga igual al registrar la venta
       y al corregir la entrega desde despacho.

       CON UNA EXCEPCIÓN: la fecha que YA ESTABA GUARDADA. Un pedido acordado
       ayer «para hoy» llega a hoy con esa fecha, y es justo el día en que
       despacho lo abre para marcar «dejar en recepción» o corregir la hora. Sin
       la excepción, la única forma de guardar ese cambio era borrar el día
       acordado (auditoría del 2r). Si no se cambia el día, no hay promesa nueva
       que revisar.

       Y el tope son doce meses, no el 31 de diciembre: con el tope de año, un
       31 de diciembre no existía NINGUNA fecha válida —mañana ya era del año
       siguiente— y una pre venta de octubre que llega en febrero tampoco podía
       llevar día de entrega. Doce meses frena igual el dedazo del año. */
    $ya_estaba = trim((string)($d['fecha_actual'] ?? ''));
    if ($fecha !== '' && $fecha !== $ya_estaba) {
        if ($fecha <= date('Y-m-d')) {
            return ['ok' => false, 'error' => 'El día de entrega es a partir de mañana: '
                                            . 'la ruta de hoy ya está armada.'];
        }
        if ($fecha > date('Y-m-d', strtotime('+12 months'))) {
            return ['ok' => false, 'error' => 'Ese día de entrega está a más de un año. Revisa el año.'];
        }
    }
    if (!$hora_ok($desde) || !$hora_ok($hasta)) {
        return ['ok' => false, 'error' => 'La hora de entrega va en formato 24 h, como 15:30.'];
    }
    /* Un rango al revés no se corrige solo: «de 18:00 a 09:00» es casi siempre
       un dedazo, y despacho lo leería como una ventana de quince horas. */
    if ($desde !== '' && $hasta !== '' && $hasta < $desde) {
        return ['ok' => false, 'error' => 'La hora de fin de la entrega es anterior a la de inicio. Revísala.'];
    }
    return ['ok' => true, 'error' => ''];
}

function pedido_entrega_guardar(int $pedido_id, array $d): array
{
    /* La fecha que ya tenía el pedido viaja al validador: la que no cambia no
       se vuelve a juzgar, para que despacho pueda corregir la hora de una
       entrega acordada para hoy sin perder el día. */
    $d['fecha_actual'] = (string) (valor('SELECT entrega_fecha FROM pedidos WHERE id = ?',
                                         [$pedido_id]) ?? '');
    $r = pedido_entrega_validar($d);
    if (!$r['ok']) return $r;

    /* LA VENTANA DE ENTREGA SOLO DONDE ENTREGAMOS NOSOTROS. Fuera de Lima el
       horario lo pone la agencia, y prometerle una hora al cliente en nombre
       de un tercero es una promesa que Waka no puede cumplir. La regla vive
       AQUÍ, en la única función que escribe esos cuatro campos, y no en la
       pantalla de registrar: si viviera allí, la de despacho seguiría dejando
       ponerle día y hora a un pedido a Cusco.

       Y si alguien la pide igual, SE LE DICE. Descartarla en silencio
       devolviendo «ok» era peor que guardarla: el de despacho tecleaba la hora
       con el cliente al teléfono, leía «Anotado, el mensaje ya lo dice» en
       verde, y ni el mensaje lo decía ni la base lo tenía. */
    $p = una('SELECT entrega, ubigeo_id FROM pedidos WHERE id = ?', [$pedido_id]);
    $en_lima = $p && (string)$p['entrega'] === 'envio'
            && ubigeo_es_lima($p['ubigeo_id'] ? (int)$p['ubigeo_id'] : null);
    if (!$en_lima) {
        actualizar('pedidos', $pedido_id, [
            'entrega_fecha' => null, 'entrega_hora_desde' => null,
            'entrega_hora_hasta' => null, 'entrega_recepcion' => 0,
        ]);
        $pedia = trim((string)($d['fecha'] ?? '')) !== ''
              || trim((string)($d['hora_desde'] ?? '')) !== ''
              || trim((string)($d['hora_hasta'] ?? '')) !== ''
              || !empty($d['recepcion']);
        return $pedia
            ? ['ok' => false, 'error' => 'Este pedido no lo entregamos nosotros: el día y la hora '
                                       . 'los pone la agencia. No se puede prometer una hora aquí.']
            : ['ok' => true, 'error' => ''];
    }

    $fecha = trim((string)($d['fecha'] ?? ''));
    $desde = trim((string)($d['hora_desde'] ?? ''));
    $hasta = trim((string)($d['hora_hasta'] ?? ''));

    /* «Hasta las 18:00» sin decir desde cuándo no dice nada útil: se guarda
       como hora única en «desde», que es como lo lee el mensaje. */
    if ($desde === '' && $hasta !== '') { $desde = $hasta; $hasta = ''; }

    actualizar('pedidos', $pedido_id, [
        'entrega_fecha'      => $fecha ?: null,
        'entrega_hora_desde' => $desde ?: null,
        'entrega_hora_hasta' => $hasta ?: null,
        'entrega_recepcion'  => !empty($d['recepcion']) ? 1 : 0,
    ]);
    return ['ok' => true, 'error' => ''];
}

/** La línea que lee despacho: «Jueves 11/09/2026 · de 15:00 a 18:00 · dejar en recepción». */
function pedido_entrega_texto(array $p): string
{
    $partes = [];
    $fecha  = (string)($p['entrega_fecha'] ?? '');
    $desde  = (string)($p['entrega_hora_desde'] ?? '');
    $hasta  = (string)($p['entrega_hora_hasta'] ?? '');

    if ($fecha !== '') $partes[] = fecha_con_dia($fecha);
    if ($desde !== '' && $hasta !== '') $partes[] = 'de ' . $desde . ' a ' . $hasta;
    elseif ($desde !== '')              $partes[] = 'a las ' . $desde;

    if (!empty($p['entrega_recepcion'])) $partes[] = 'DEJAR EN RECEPCIÓN';

    return implode(' · ', $partes);
}

function comprobantes_pendientes(?array $u = null, bool $olvidar = false): int
{
    static $cache = [];
    if ($olvidar) { $cache = []; return 0; }

    $u ??= yo();
    if (!$u) return 0;
    $llave = (int)$u['id'];
    if (isset($cache[$llave])) return $cache[$llave];

    [$ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);

    return $cache[$llave] = (int) valor(
        "SELECT COUNT(*)
           FROM pedidos pe
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE pe.anulado_en IS NULL
            AND (pe.comprobante_tipo IS NULL OR pe.comprobante_tipo = '')
            AND $ambito", $par);
}

function comprobantes_pendientes_olvidar(): void
{
    comprobantes_pendientes(null, true);
}

/**
 * Anota que este pedido se mandó al grupo de despacho.
 *
 * El HUB no manda el WhatsApp —eso lo hace la persona desde su propio
 * teléfono—: lo que hace es armar el mensaje y quedarse con la constancia. Sin
 * ella nadie sabe si un pedido ya salió o se quedó en el aire, y el remedio de
 * la casa para esa duda es mandarlo otra vez.
 *
 * Se cuenta cuántas veces: dos envíos es normal (se corrigió una dirección),
 * cinco es una señal de que algo no está funcionando.
 */
/**
 * LA LÍNEA DE TIEMPO DE UNA VENTA — se calcula, no se toca a mano.
 *
 * Lo pidió el usuario el 2026-09-09: *«poder identificar fácil cuándo subió el
 * pedido, si ya le confirmaron el pago, si ya lo mandó a despacho y cuándo fue
 * entregado, y quiero evitar cambios manuales con revisiones manuales»*.
 *
 * Va APARTE del estado del pedido y no lo sustituye. El estado, en pre venta,
 * dice dónde va el CONTENEDOR —reservado, en camino, llegó— y eso es otro eje:
 * un pedido puede estar pagado Y en camino a la vez, y en un solo estado no
 * caben los dos. Mezclarlos habría roto la pre venta entera.
 *
 * Cada hito sale de un dato que el HUB ya tiene, con su fecha y su nombre. No
 * hay nada que marcar, así que no hay nada que se pueda quedar sin marcar —
 * que es el problema que se quería quitar de encima.
 */
function pedido_hitos(array $p): array
{
    $id  = (int)$p['id'];
    $hoy = [];

    /* Quién y cuándo, de la tabla de eventos: es el único sitio donde queda
       escrito quién apretó qué, y ya lo llena todo el módulo. */
    $ev = function (string $tipo) use ($id) {
        return una('SELECT ev.creado_en, u.nombre
                      FROM pedido_eventos ev
                      LEFT JOIN usuarios u ON u.id = ev.usuario_id
                     WHERE ev.pedido_id = ? AND ev.tipo = ?
                     ORDER BY ev.id DESC LIMIT 1', [$id, $tipo]);
    };

    // 1 · REGISTRADO. Siempre hecho: si no, no habría pedido.
    $hoy[] = [
        'clave'  => 'registrado',
        'titulo' => 'Registrado',
        'hecho'  => true,
        'cuando' => (string)($p['creado_en'] ?? $p['fecha']),
        'quien'  => (string)($p['asesor_nombre'] ?? ''),
        'nota'   => '',
    ];

    // 2 · PAGO. Tres formas de estar: nada, adelanto, completo.
    $total   = (int)$p['total_centimos'];
    $cobrado = (int)$p['cobrado_centimos'];
    $ultimo  = una('SELECT pg.verificado_en, u.nombre
                      FROM pagos pg LEFT JOIN usuarios u ON u.id = pg.verificado_por
                     WHERE pg.pedido_id = ? AND pg.verificado = 1 AND pg.anulado = 0
                     ORDER BY pg.verificado_en DESC, pg.id DESC LIMIT 1', [$id]);
    $completo = $total > 0 && $cobrado >= $total;
    $hoy[] = [
        'clave'  => 'pago',
        'titulo' => $completo ? 'Pago confirmado' : ($cobrado > 0 ? 'Adelanto confirmado' : 'Pago confirmado'),
        'hecho'  => $cobrado > 0,
        'parcial'=> $cobrado > 0 && !$completo,
        'cuando' => (string)($ultimo['verificado_en'] ?? ''),
        'quien'  => (string)($ultimo['nombre'] ?? ''),
        'nota'   => $cobrado > 0 && !$completo
            ? 'faltan ' . soles(max(0, $total - $cobrado))
            : ($cobrado > 0 ? '' : 'facturación todavía no lo confirma'),
    ];

    // 3 · COMPROBANTE. «ninguno» es una decisión tomada, no un hueco.
    $comp = (string)($p['comprobante_tipo'] ?? '');
    $e    = $ev('comprobante');
    $hoy[] = [
        'clave'  => 'comprobante',
        'titulo' => $comp === 'ninguno' ? 'No lleva comprobante'
                  : ($comp !== '' ? ucfirst($comp) . ' emitida' : 'Comprobante'),
        'hecho'  => $comp !== '',
        'cuando' => (string)($e['creado_en'] ?? ''),
        'quien'  => (string)($e['nombre'] ?? ''),
        'nota'   => $comp !== '' && $comp !== 'ninguno' ? pedido_comprobante_numero($p) : '',
    ];

    // 4 · DESPACHO.
    $veces = (int)($p['despacho_veces'] ?? 0);
    $qd    = $p['despacho_por'] ? valor('SELECT nombre FROM usuarios WHERE id = ?', [(int)$p['despacho_por']]) : '';
    $hoy[] = [
        'clave'  => 'despacho',
        'titulo' => 'Mandado a despacho',
        'hecho'  => $veces > 0,
        'cuando' => (string)($p['despacho_en'] ?? ''),
        'quien'  => (string)($qd ?? ''),
        'nota'   => $veces > 1 ? $veces . ' envíos' : ($veces === 0 ? pedido_despacho_bloqueo($p) : ''),
    ];

    // 5 · ENTREGADO (3j): lo marca Almacén con la foto de la entrega.
    $ent_en = (string)($p['entregado_en'] ?? '');
    $entregado = $ent_en !== '' || (string)$p['estado'] === 'entregado';
    $qe = !empty($p['entregado_por']) ? valor('SELECT nombre FROM usuarios WHERE id = ?', [(int)$p['entregado_por']]) : '';
    $hoy[] = [
        'clave'  => 'entregado',
        'titulo' => 'Entregado',
        'hecho'  => $entregado,
        'cuando' => $ent_en,
        'quien'  => (string)($qe ?? ''),
        'nota'   => $entregado ? '' : ($veces > 0 ? 'Almacén lo marca con la foto de la entrega' : ''),
    ];

    return $hoy;
}

/**
 * Guarda el PDF (o la foto) de la boleta o la factura ya emitida.
 *
 * Lo pidió el usuario el 2026-09-09: *«también debería haber una opción para
 * ver la boleta o factura realizada»*. Mientras Tumifactura no tenga API, se
 * emite allí, se descarga el PDF y se sube aquí — el mismo gesto que el
 * voucher. El día que haya API, este mismo campo se llena solo y nadie tiene
 * que aprender nada nuevo.
 *
 * Vive en uploads/comprobantes/, cerrada por .htaccess: una boleta lleva el
 * nombre, el documento y la dirección del cliente y el detalle de lo que
 * compró. Se sirve por /pedidos/comprobante-archivo, que comprueba antes quién
 * pide — igual que los vouchers.
 *
 * No se re-codifica como los vouchers: un PDF de SUNAT se guarda tal cual, y
 * volver a comprimir una boleta la haría ilegible justo cuando hace falta.
 */
function comprobante_guardar_archivo(array $archivo): array
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No se eligió ningún archivo.'];
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'El archivo no llegó completo. Vuelve a intentarlo.'];
    }
    if (($archivo['size'] ?? 0) > VOUCHER_MAX_BYTES) {
        return ['ok' => false, 'error' => 'El comprobante pesa más de 5 MB.'];
    }
    if (!is_uploaded_file((string)($archivo['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'El archivo no llegó por el formulario.'];
    }

    /* Se mira el CONTENIDO, no el nombre: «boleta.pdf» puede ser cualquier
       cosa, y esta carpeta la va a servir el HUB a quien tenga permiso. */
    $cabeza = (string) @file_get_contents($archivo['tmp_name'], false, null, 0, 5);
    $ext    = '';
    if ($cabeza === '%PDF-') {
        $ext = 'pdf';
    } else {
        $info = @getimagesize($archivo['tmp_name']);
        $tipo = $info[2] ?? 0;
        if ($tipo === IMAGETYPE_JPEG) $ext = 'jpg';
        elseif ($tipo === IMAGETYPE_PNG) $ext = 'png';
    }
    if ($ext === '') {
        return ['ok' => false, 'error' => 'El comprobante tiene que ser PDF, JPG o PNG.'];
    }

    $dir = HUB_SUBIDAS . '/comprobantes';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nombre = 'c' . date('Ymd') . '-' . substr(token(8), 0, 12) . '.' . $ext;

    if (!@move_uploaded_file($archivo['tmp_name'], $dir . '/' . $nombre)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el comprobante.'];
    }
    return ['ok' => true, 'archivo' => $nombre];
}

/** «B001-00001234», o vacío si todavía no se anotaron serie y número. */
function pedido_comprobante_numero(array $p): string
{
    return trim((string)($p['comprobante_serie'] ?? '') . '-'
              . (string)($p['comprobante_numero'] ?? ''), '-');
}

/**
 * ¿Este pedido puede salir a despacho?
 *
 * LA REGLA, DICHA POR EL USUARIO EL 2026-09-09:
 * «solo sale un producto a despacho si el pago fue confirmado».
 *
 * Lo que confirma es el dinero que YA ENTRÓ, no el total: la venta con
 * adelanto y saldo contraentrega sale igual, y el mensaje dice cuánto cobrar
 * al entregar. Lo que NO sale es el pedido con un voucher que nadie ha mirado
 * o que facturación dejó en espera — que es exactamente el caso del voucher
 * falso: sin esta puerta, el asesor pega el mensaje en el grupo diez minutos
 * después de registrar el pago y la mercadería sale antes de que nadie abra el
 * banco.
 *
  * Desde el parche 2s (2026-09-21), además: un pedido SIN NINGÚN pago no sale
 * —«no puede salir sin confirmación»— y uno a PROVINCIA solo sale con todo
 * cobrado, porque allí no hay contraentrega.
 *
 * Una sola definición, que usan la pantalla de despacho (para no armar el
 * texto), la ficha (para no ofrecer el botón) y pedido_despacho_marcar() (para
 * no anotar el envío). Escrita tres veces, el botón y la pantalla acabarían
 * discrepando — el fallo que más veces ha aparecido en este proyecto.
 */
/**
 * LA MISMA REGLA DE ARRIBA, EN FORMA DE SQL.
 *
 * `pedido_despacho_bloqueo()` contesta por UN pedido y explica por qué; esto
 * filtra MUCHOS. Son la misma frase dicha dos veces, que es justo el fallo que
 * más se repite en este proyecto, así que:
 *   · se escriben juntas, aquí, para que nadie cambie una sin ver la otra;
 *   · hay una prueba que recorre todos los pedidos y comprueba que las dos
 *     dicen lo mismo (`correr2.php`, auditoría 18).
 * `pe` es el alias obligatorio de la tabla `pedidos` en la consulta que la use.
 */
/**
 * El trozo de SQL que dice si un pedido es de LIMA, en la misma definición que
 * ubigeo_es_lima(): la provincia del distrito es Lima o Callao.
 *
 * Lo pidió el usuario el 2026-09-20 para el filtro Lima / Provincia de la lista
 * de pedidos. Vive aquí y no escrito a mano en la consulta para que el día que
 * Lima metropolitana incluya otra provincia no haya dos listas que discrepen.
 * `$alias` es el alias de la tabla `pedidos` en la consulta que lo use.
 */
function sql_pedido_es_lima(string $alias = 'p'): string
{
    return "EXISTS (SELECT 1 FROM ubigeo ud
                      JOIN ubigeo up ON up.id = ud.padre_id
                     WHERE ud.id = $alias.ubigeo_id
                       AND LOWER(up.nombre) IN ('lima','callao'))";
}

function sql_despacho_listo(string $alias = 'pe'): string
{
    /* `dp.tipo` es de migraciones_modulo2(). Sin ella, esta condición tumbaba
       la lista de pedidos entera — y va pegada a sql_estado_pago() en la misma
       consulta, así que defender una sin la otra no servía de nada. Sin la
       columna, un pago es un cobro: las devoluciones también son del módulo 2. */
    $solo_cobros = columna_existe('pagos', 'tipo') ? " AND dp.tipo = 'cobro'" : '';
    $de_este = "SELECT 1 FROM pagos dp WHERE dp.pedido_id = $alias.id$solo_cobros";

    /* DOS CONDICIONES, NO UNA.
       (1) Que no quede dinero esperando: lo de siempre.
       (2) Y QUE HAYA ENTRADO DINERO DE VERDAD, salvo que nunca se registrara
           ninguno. Faltaba, y era un agujero por el que salía la mercadería:
           denegar un pago lo pone `anulado = 1`, así que un pedido con su
           ÚNICO pago denegado se quedaba sin pagos vivos y pasaba por una venta
           100% contraentrega. El HUB lo mandaba a «Listas para despacho», le
           ponía la estrella en el menú y armaba el mensaje diciendo «lo que ya
           pagó el cliente está confirmado» — con el voucher falso encima de la
           mesa. Y 48 horas después anulaba el pedido, con la mercadería fuera.
                      Lo mismo si el asesor quita su propio pago.
           (Hasta el 2s había aquí una excepción para la venta sin ningún pago;
           se quitó a pedido del usuario: ver el párrafo de abajo.) */
        /* PARCHE 2S · SIN DINERO CONFIRMADO NO SALE, Y PUNTO (usuario, 2026-09-21:
       «solo debe poder enviarse a despacho cuando facturación confirme el
       pago; no puede salir sin confirmación»). Se quita la excepción de la
       venta sin ningún pago: desde el 2h no se puede registrar una así, y la
       que quedara de antes tampoco debe salir sin que nadie haya visto dinero.

       Y A PROVINCIA, SOLO CON TODO COBRADO. Allí no hay contraentrega: la caja
       viaja en una agencia y nadie cobra al entregar. Vale también para la pre
       venta, que se separa con un adelanto y completa el 100% cuando el
       contenedor llega a Lima, antes de salir por agencia. El «cobrado» es el
       CONFIRMADO: un saldo registrado y sin confirmar tampoco deja salir.
       Sin la columna del distrito —HUB a medio actualizar— no se puede saber
       si es provincia, y la condición se omite antes que tumbar la lista. */
    $provincia_con_saldo = '';
    if (columna_existe('pedidos', 'ubigeo_id')) {
        $provincia_con_saldo = " AND NOT ($alias.entrega = 'envio' AND $alias.ubigeo_id IS NOT NULL
                                          AND NOT " . sql_pedido_es_lima($alias) . "
                                          AND $alias.total_centimos - $alias.cobrado_centimos > 0)";
    }
    /* (4) CON UN DESCUENTO ESPERANDO APROBACIÓN NO SALE (3d): lo que
       cobra el repartidor depende de la respuesta de Administración. */
    $sin_desc_pendiente = columna_existe('pedidos', 'descuento_estado')
        ? " AND $alias.descuento_estado <> 'pendiente'" : '';
    /* (5) LA PRE VENTA, SOLO CON SU LOTE LISTO PARA ENTREGA (3j, usuario
       2026-09-28: «una pre venta no se puede mandar a despacho el mismo día,
       tiene que ser solo si ya se confirmó la descarga del contenedor»). */
    return "NOT EXISTS ($de_este AND dp.anulado = 0 AND dp.verificado = 0)
            AND EXISTS ($de_este AND dp.anulado = 0 AND dp.verificado = 1)"
         . $provincia_con_saldo . $sin_desc_pendiente . ' AND ' . sql_preventa_lista($alias);
}
/* ERA UNA CONSTANTE Y AHORA ES UNA FUNCIÓN, por dos motivos. El alias: la
   LISTA de pedidos llama a sus pedidos `p`, y escribir allí la condición a
   mano era una CUARTA redacción de esta misma regla — la vista escondía
   «★ Mandar a despacho» en 22 de las 156 secuencias de pagos posibles donde la
   ficha sí lo ofrecía, entre ellas la venta 100% contraentrega. Y la base: una
   constante se calcula al cargar el archivo, antes de que haya conexión, así
   que no podía preguntar si la columna existe. Se llama `sql_despacho_listo()`
   en todas partes; `DESPACHO_LISTO` ya no existe. */

/**
 * POR QUÉ NO SALE ESTA VENTA — LA ÚNICA DEFINICIÓN, CON NOMBRE Y CON FRASE.
 *
 * Devuelve ['clave' => '', 'texto' => '']. La clave es para el código (quién
 * puede reclamar, qué chip se pinta); el texto es para el asesor. Nacieron
 * separadas —`pedido_despacho_bloqueo()` daba solo la frase— y el reclamo se
 * escribió entonces su PROPIA tercera lectura del estado del pago: la suya
 * decía «está esperando a facturación» también cuando el voucher había sido
 * DENEGADO, así que la venta del voucher falso se quedaba para siempre bajo el
 * cartel «Esperando que facturación confirme», sin decir el motivo, mientras
 * el asesor esperaba una respuesta que ya había llegado (auditoría, 2026-09-14).
 *
  * Claves, en este orden: '' sale · anulado · denegado · sin_pago · espera ·
 * sin_revisar · preventa · saldo_provincia · descuento.
 * Solo `sin_revisar` se puede reclamar: en `espera` facturación ya lo miró y
 * está esperando al banco, y en `denegado` lo que toca es registrar el pago
 * bueno, no insistir.
 */
function pedido_despacho_motivo(array $p): array
{
    $r = fn(string $clave, string $texto) => ['clave' => $clave, 'texto' => $texto];

    if ((string)($p['estado'] ?? '') === 'anulado') {
        return $r('anulado', 'Este pedido está anulado: no se despacha.');
    }

    $f = una(
        'SELECT COALESCE(SUM(CASE WHEN en_espera = 0 THEN monto_centimos ELSE 0 END), 0) AS sin_revisar,
                COALESCE(SUM(CASE WHEN en_espera = 1 THEN monto_centimos ELSE 0 END), 0) AS en_espera
           FROM pagos
          WHERE pedido_id = ? AND anulado = 0 AND verificado = 0 AND tipo = ?',
        [(int)$p['id'], 'cobro']);

    $sin_revisar = (int)($f['sin_revisar'] ?? 0);
    $en_espera   = (int)($f['en_espera'] ?? 0);

        /* LA MISMA SEGUNDA CONDICIÓN que sql_despacho_listo(), dicha en palabras:
       sin dinero confirmado no sale. Desde el 2s, sin excepción: una venta que
       nunca registró ningún pago tampoco sale (usuario, 2026-09-21). */
    if ($sin_revisar === 0 && $en_espera === 0) {
        $hubo   = (int) valor("SELECT COUNT(*) FROM pagos WHERE pedido_id = ? AND tipo = 'cobro'",
                              [(int)$p['id']]);
        $entro  = (int) valor("SELECT COUNT(*) FROM pagos
                                WHERE pedido_id = ? AND tipo = 'cobro'
                                  AND anulado = 0 AND verificado = 1", [(int)$p['id']]);
        if ($hubo > 0 && $entro === 0) {
            return $r('denegado',
                   'De esta venta no entró dinero: el pago se denegó o se quitó. Registra el '
                 . 'pago correcto; cuando facturación lo confirme, podrás mandarlo a despacho.');
        }
        if ($hubo === 0) {
            return $r('sin_pago',
                   'Esta venta no tiene ningún pago. Regístralo; cuando facturación lo '
                 . 'confirme, podrás mandarlo a despacho.');
        }
    }

    if ($en_espera > 0) {
        return $r('espera',
               'Hay ' . soles($en_espera) . ' que facturación está revisando y todavía no '
             . 'aparece en el banco. Hasta que se aclare, este pedido no sale.');
    }
        if ($sin_revisar > 0) {
        return $r('sin_revisar',
               'Hay ' . soles($sin_revisar) . ' registrado que facturación aún no ha confirmado. '
             . 'En cuanto lo confirmen, podrás mandarlo a despacho.');
    }
    /* La quinta condición de sql_despacho_listo(), dicha en palabras (3j).
       Después de lo del pago —un pago denegado o sin confirmar se dice y se
       puede reclamar aunque el lote siga en el mar— y antes del saldo de
       provincia: mientras la mercadería no está, completar el pago no apura. */
    if ((int)($p['despacho_veces'] ?? 0) === 0 && ($espera = pedido_preventa_espera_texto($p)) !== '') {
        return $r('preventa', $espera);
    }
    /* A PROVINCIA, SOLO CON TODO COBRADO — la misma tercera condición que
       sql_despacho_listo(). Incluye la pre venta: se completa el 100% cuando el
       contenedor llega, y recién sale por agencia. */
    if (pedido_es_provincia($p) && pedido_saldo($p) > 0) {
        return $r('saldo_provincia',
               'A provincia se cobra todo antes de despachar: faltan '
             . soles(pedido_saldo($p)) . '. Registra el pago; cuando facturación lo confirme, '
             . 'podrás mandarlo.');
    }
    /* La cuarta condición de sql_despacho_listo(), dicha en palabras. */
    if (pedido_descuento_pendiente($p)) {
        return $r('descuento',
               'El descuento de esta venta pasa del tope y espera que Administración lo apruebe. '
             . 'Cuando respondan, podrás mandarlo.');
    }
    return $r('', '');
}

/**
 * ¿Va a provincia? Un envío cuyo distrito no es de Lima. Un recojo en oficina
 * no es provincia aunque el cliente viva en Puno: lo entrega Waka en su local.
 * Es la misma definición que el SQL de sql_despacho_listo().
 */
function pedido_es_provincia(array $p): bool
{
    if ((string)($p['entrega'] ?? '') !== 'envio') return false;
    $ubi = $p['ubigeo_id'] ?? null;
    if (!$ubi) return false;
    return !ubigeo_es_lima((int)$ubi);
}

/**
 * POR QUÉ NO SE PUEDE EMITIR TODAVÍA EL COMPROBANTE DE ESTA VENTA.
 *
 * Devuelve '' cuando sí se puede. UNA SOLA DEFINICIÓN: la usan la pantalla de
 * facturar, la ventana que sale al confirmar un pago y nubefact_emitir(), que
 * es la que de verdad manda.
 *
 * LA REGLA (usuario, 2026-09-22): con el TOTAL cobrado. La excepción es la
 * contraentrega de Lima —la venta sale a despacho con un adelanto y el resto
 * se cobra al entregar—: ahí el cliente necesita su boleta dentro de la caja,
 * así que se puede emitir desde que la venta se manda a despacho. A provincia
 * no hay contraentrega, así que allí no hay excepción.
 */
function pedido_emision_bloqueo(array $p): string
{
    if ((string)$p['estado'] === 'anulado') return 'Este pedido está anulado: no lleva comprobante.';
    /* Con el descuento esperando, el total todavía puede cambiar (3d). */
    if (pedido_descuento_pendiente($p)) {
        return 'El descuento de esta venta espera que Administración lo apruebe: el total todavía puede cambiar.';
    }
    /* Con el costo del envío por poner, el total todavía va a cambiar (3j). */
    if (pedido_flete_falta($p)) {
        return 'Falta poner el costo del envío: el total todavía va a cambiar.';
    }
    /* Una venta que suma cero la para nubefact_emitir() con su propia frase, que
       dice lo que pasa de verdad; aquí diría «no hay ningún pago confirmado». */
    if ((int)$p['total_centimos'] <= 0) return '';
    if ((int)$p['cobrado_centimos'] <= 0) {
        return 'Todavía no hay ningún pago confirmado en esta venta. Confírmalo primero.';
    }
    $falta = (int)$p['total_centimos'] - (int)$p['cobrado_centimos'];
    if ($falta <= 0) return '';
    /* LA EXCEPCIÓN, escrita como se dice: un ENVÍO a Lima que ya salió. No vale
       para un recojo en oficina —allí el cliente viene y paga— ni para
       provincia, donde nadie cobra al entregar. */
    if ((string)($p['entrega'] ?? '') === 'envio' && !pedido_es_provincia($p)
        && (int)($p['despacho_veces'] ?? 0) > 0) {
        return '';
    }
    return 'Falta cobrar ' . soles($falta) . ' de esta venta. El comprobante se emite con el total '
         . 'cobrado; si es un envío a Lima con contraentrega, se puede emitir en cuanto se mande '
         . 'a despacho.';
}

/**
 * QUÉ HAY QUE HACER AHORA CON ESTA VENTA — la única definición.
 *
 * Lo pidió el usuario el 2026-09-23: «si hay alguna acción que realizar, que
 * aparezca la advertencia grande en la parte superior». Devuelve
 * ['texto', 'boton', 'ruta', 'tono'] o [] cuando no hay nada pendiente PARA
 * QUIEN MIRA: el asesor ve «mándalo a despacho» y facturación ve «confirma el
 * pago», porque cada uno solo puede hacer lo suyo y un aviso que uno no puede
 * atender se aprende a ignorar.
 *
 * El orden es el del trabajo real: primero el dinero, después la mercadería y
 * al final el papel.
 */
function pedido_accion_pendiente(array $p): array
{
    if ((string)$p['estado'] === 'anulado') return [];
    $id   = (int)$p['id'];
    $nada = [];

    $falta_registrar = pedido_por_registrar($p);
    $esperando = $falta_registrar === 0 && pedido_saldo($p) > 0;

    /* 0 · Administración: un descuento pasó del tope y espera su respuesta
       (3d). La ficha pinta aquí los dos botones (`descuento` => true). */
    $desc_espera = function_exists('pedido_descuento_pendiente') && pedido_descuento_pendiente($p);
    if ($desc_espera && puede_aprobar_descuentos()) {
        $m = (int)($p['descuento_pedido_centimos'] ?? 0);
        return ['texto' => 'Descuento de ' . soles($m) . ' (' . descuento_pct_texto($m, (int)$p['subtotal_centimos'])
                         . ') que pasa del tope: «' . (string)($p['descuento_motivo'] ?? '') . '». ¿Lo apruebas?',
                'boton' => '', 'ruta' => '', 'tono' => 'amarillo', 'descuento' => true];
    }

    /* 1 · Facturación: hay dinero esperando que alguien lo mire. */
    if (puede('pagos.verificar')) {
        $sin_revisar = (int) valor("SELECT COUNT(*) FROM pagos WHERE pedido_id = ? AND anulado = 0
                                      AND verificado = 0" . (columna_existe('pagos', 'en_espera') ? ' AND en_espera = 0' : ''),
                                   [$id]);
        if ($sin_revisar > 0) {
            return ['texto' => 'Confirma el pago de esta venta.',
                    'boton' => 'REVISAR EL PAGO', 'ruta' => '/pedidos/facturar?id=' . $id, 'tono' => 'amarillo'];
        }
    }

    /* PUEDE MIRAR NO ES PUEDE HACER. El líder de equipo LEE las ventas de los
       suyos y lleva los permisos de asesor: sin esto se le decía «mándalo a
       despacho» sobre la venta de otro, y la pantalla de despacho le
       contestaba «solo lectura» (auditoría del 3a.1). */
    $mio = puedo_editar((int)$p['asesor_id'], (int)$p['pais_id']);

    /* 2 · El asesor: la venta está libre y todavía no salió. */
    if ($mio && puede('pedidos.despachar') && (int)($p['despacho_veces'] ?? 0) === 0
        && pedido_despacho_bloqueo($p) === '') {
        return ['texto' => 'Manda este pedido a despacho ahora.',
                'boton' => 'MANDAR A DESPACHO', 'ruta' => '/pedidos/despacho?id=' . $id, 'tono' => 'amarillo',
                'despacho' => true];
    }

    /* 2b · Quien NO es el asesor (Administración, que llega del aviso «saldo
       sin registrar»): ya salió, el asesor no registró el saldo, y lo que
       toca es preguntarle (usuario, 2026-09-25). */
    if ((int)(yo()['id'] ?? 0) !== (int)$p['asesor_id'] && avisa_saldos_sin_registrar()) {
        $sin_reg = pedido_saldo_sin_registrar($id);
        if ($sin_reg > 0) {
            $quien = primer_nombre((string)($p['asesor_nombre'] ?? '')) ?: 'el asesor';
            $wa = whatsapp_al_asesor($p, $sin_reg);
            if ($wa !== '') {
                return ['texto' => 'Ya se entregó y falta registrar ' . soles($sin_reg) . '. Pregúntale a ' . $quien . '.',
                        'boton' => 'ESCRIBIR A ' . mb_strtoupper($quien) . ' POR WHATSAPP', 'ruta' => $wa,
                        'externo' => true, 'tono' => 'amarillo'];
            }
            return ['texto' => 'Ya se entregó y falta registrar ' . soles($sin_reg) . '. '
                             . $quien . ' no tiene celular en su ficha.',
                    /* Solo si de verdad puede editar esa cuenta: la de otro
                       Administración o de Dirección le daría un 403. */
                    'boton' => in_array((string) valor('SELECT r.clave FROM usuarios us JOIN roles r ON r.id = us.rol_id WHERE us.id = ?',
                                                        [(int)$p['asesor_id']], ''), roles_que_puedo_dar(), true)
                               ? 'PONER SU CELULAR' : '',
                    'ruta' => '/usuarios/editar?id=' . (int)$p['asesor_id'], 'tono' => 'amarillo'];
        }
    }

    /* 3 · El asesor: ya salió y falta cobrar lo que queda. */
    if ($mio && puede('pagos.registrar') && $falta_registrar > 0 && (int)($p['despacho_veces'] ?? 0) > 0) {
        return ['texto' => 'Falta ' . soles($falta_registrar) . ' por registrar de esta venta.',
                'boton' => 'REGISTRAR EL PAGO', 'ruta' => '/pedidos/ficha?id=' . $id . '#pagos', 'tono' => 'amarillo'];
    }

    /* 4 · Facturación: cobrada y sin comprobante. */
    if (puede('pagos.verificar') && !$esperando
        && trim((string)($p['comprobante_tipo'] ?? '')) === ''
        && function_exists('pedido_emision_bloqueo') && pedido_emision_bloqueo($p) === '') {
        return ['texto' => 'Esta venta todavía no tiene su comprobante.',
                'boton' => 'EMITIR COMPROBANTE', 'ruta' => '/pedidos/facturar?id=' . $id, 'tono' => 'amarillo'];
    }

    /* 4b · El asesor, con su descuento esperando a Administración. */
    if ($desc_espera && $mio) {
        return ['texto' => 'El descuento espera que Administración lo apruebe. Te avisamos cuando respondan; '
                         . 'hasta entonces la venta no sale a despacho.',
                'boton' => '', 'ruta' => '', 'tono' => 'gris'];
    }

    /* 5 · El asesor, cuando el dinero está en manos de facturación: no hay
       nada que pulsar, pero sí algo que saber. */
    if ($esperando && !puede('pagos.verificar') && $mio) {
        return ['texto' => 'Facturación está revisando el pago. Te avisamos aquí cuando lo confirmen.',
                'boton' => '', 'ruta' => '', 'tono' => 'gris'];
    }

    return $nada;
}

/** La frase sola, que es lo que pintan las pantallas de siempre. */
function pedido_despacho_bloqueo(array $p): string
{
    return pedido_despacho_motivo($p)['texto'];
}

/**
 * ¿FALTA EL COSTO DEL ENVÍO? (3j) En la pre venta, con un tipo de envío que
 * cobra (p. ej. «Envío con costo en Lima»), el costo es opcional al vender:
 * se pone después desde el pedido o, como muy tarde, al mandarlo a despacho.
 * Una sola definición: la usan la ficha, el botón de despacho y la emisión.
 */
function pedido_flete_falta(array $p): bool
{
    if ((string)($p['estado'] ?? '') === 'anulado' || !empty($p['anulado_en'])) return false;
    /* Lo que quedó GUARDADO al vender, no la lista de hoy: si mañana alguien
       enciende «cobra flete» en un tipo de envío, las ventas viejas no pasan a
       deber un costo; y si lo apaga, la pre venta pendiente no lo pierde. Al
       vender, «incluido» quiere decir que el tipo de envío cobraba. */
    return (string)($p['tipo'] ?? '') === 'preventa'
        && (string)($p['entrega'] ?? '') === 'envio'
        && (string)($p['flete_lo_paga'] ?? '') === 'incluido'
        && (int)($p['flete_centimos'] ?? 0) <= 0;
}

/**
 * PONE EL COSTO DEL ENVÍO que quedó pendiente (3j). Entra en el total —lo
 * cobra Waka— y el saldo se recalcula. → ['ok', 'error']
 * Dentro de una transacción con el pedido bloqueado: el asesor puede estar
 * registrando un pago en la otra pestaña.
 */
function pedido_flete_poner(int $pedido_id, int $centimos): array
{
    return en_transaccion(function () use ($pedido_id, $centimos) {
        bloquear_fila('pedidos', $pedido_id);
        $p = pedido_de($pedido_id);
        if (!$p) return ['ok' => false, 'error' => 'Ese pedido no existe.'];
        if (!puedo_editar((int)$p['asesor_id'], (int)$p['pais_id'])) {
            return ['ok' => false, 'error' => 'Este pedido no es tuyo: solo lo puedes mirar.'];
        }
        if (!pedido_flete_falta($p)) return ['ok' => false, 'error' => 'Este pedido ya tiene el costo del envío.'];
        if ($centimos <= 0 || !dinero_razonable($centimos)
            || !dinero_razonable((int)$p['subtotal_centimos'] + $centimos)) {
            return ['ok' => false, 'error' => 'Escribe cuánto se le cobra al cliente por el envío.'];
        }
        actualizar('pedidos', $pedido_id, ['flete_centimos' => $centimos, 'flete_lo_paga' => 'incluido']);
        pedido_recalcular($pedido_id);
        pedido_evento($pedido_id, 'flete', 'Se puso el costo del envío: ' . soles($centimos));
        bitacora('pedido.flete', 'pedido', $pedido_id, ['centimos' => $centimos]);
        pedido_estado_auto($pedido_id);
        return ['ok' => true, 'error' => ''];
    });
}

function pedido_despacho_marcar(int $pedido_id, bool $solo_primera = false, ?int $flete = null): array
{
    // Leer-sumar-escribir con la fila reservada, como toda escritura de este
    // archivo: sin el candado, dos clics a la vez cuentan uno.
    // $solo_primera (3h.1): el botón de un clic. Si ya salió, no cuenta otra
    // vez: un doble toque no es «lo volví a mandar».
    return en_transaccion(function () use ($pedido_id, $solo_primera, $flete) {

    bloquear_fila('pedidos', $pedido_id);
    /* LA PRE VENTA: sus lotes, también con candado (3j), ANTES de leer nada
       más: si el CEO deshace «listo para entrega» en ese mismo instante, uno
       de los dos espera, y lo que se lee después ya es lo de verdad. */
    if (!bd_es_sqlite() && columna_existe('lotes', 'listo_en')
        && (string) valor('SELECT tipo FROM pedidos WHERE id = ?', [$pedido_id]) === 'preventa') {
        todas('SELECT xlo.id, xlo.listo_en FROM lotes xlo WHERE xlo.id IN (SELECT xll.lote_id FROM pedido_lineas xpl
                 JOIN lote_lineas xll ON xll.id = xpl.lote_linea_id WHERE xpl.pedido_id = ?) ORDER BY xlo.id FOR UPDATE', [$pedido_id]);
    }
    $p = pedido_de($pedido_id);
    if (!$p) return ['ok' => false, 'error' => 'Ese pedido no existe.'];

    /* La misma puerta que la pantalla, y aquí dentro del candado: entre que se
       pinta el botón y se pulsa, el asesor pudo registrar otro pago en la otra
       pestaña. */
    if ($bloqueo = pedido_despacho_bloqueo($p)) {
        return ['ok' => false, 'error' => $bloqueo];
    }
    /* EL COSTO DEL ENVÍO QUE QUEDÓ PENDIENTE (3j): como muy tarde, aquí. */
    if (pedido_flete_falta($p) && !($solo_primera && (int)($p['despacho_veces'] ?? 0) > 0)) {
        if ($flete === null || $flete <= 0) {
            return ['ok' => false, 'error' => 'Falta el costo del envío. Escríbelo y vuelve a mandar.', 'campo' => 'flete'];
        }
        $rf = pedido_flete_poner($pedido_id, $flete);
        if (!$rf['ok']) return $rf + ['campo' => 'flete'];
        $p = pedido_de($pedido_id);
        /* Con el costo dentro, el total cambió: se mira la puerta otra vez. */
        if ($bloqueo = pedido_despacho_bloqueo($p)) {
            return ['ok' => false, 'error' => 'Guardamos el costo del envío, pero todavía no sale. ' . $bloqueo];
        }
    }
    if ($solo_primera && (int)($p['despacho_veces'] ?? 0) > 0) {
        return ['ok' => true, 'error' => '', 'veces' => (int)$p['despacho_veces'], 'ya' => true];
    }

    $veces = (int)($p['despacho_veces'] ?? 0) + 1;
    $cambio = [
        'despacho_por'   => $_SESSION['usuario_id'] ?? null,
        'despacho_en'    => date('Y-m-d H:i:s'),
        'despacho_veces' => $veces,
    ];
    /* LA PRIMERA SALIDA (3i): la garantía cuenta desde aquí, y volver a
       mandarlo no la reinicia. */
    if (columna_existe('pedidos', 'despacho_primero_en') && empty($p['despacho_primero_en'])) {
        $cambio['despacho_primero_en'] = $cambio['despacho_en'];
    }
    actualizar('pedidos', $pedido_id, $cambio);
    pedido_evento($pedido_id, 'despacho',
        $veces === 1 ? 'Se mandó a despacho'
                     : 'Se volvió a mandar a despacho (' . $veces . '.ª vez)');
    bitacora('pedido.despacho', 'pedido', $pedido_id, ['veces' => $veces]);
    pedido_estado_auto($pedido_id);

    return ['ok' => true, 'error' => '', 'veces' => $veces];
    });
}

/* ─────────────────────────  ANULAR  ───────────────────────── */

/**
 * Anula un pedido. Devuelve ['ok'=>bool, 'error'=>string, 'devolver'=>int].
 *
 * Lo que se deshace, y por qué en este orden:
 *  1. Cada pago YA VALIDADO se revierte con una fila negativa fechada HOY.
 *     No se despinta el original: la meta del mes pasado no puede cambiar
 *     sola porque hoy se anule algo. La reversión resta del mes en que ocurre.
 *  2. El cashback se revierte PAGO POR PAGO, por lo que de verdad entró.
 *     De un pedido de S/2,180 del que solo se cobraron S/500 se revierten
 *     S/5.00 —el 1% del pago—, jamás S/21.80.
 *  3. El saldo de cashback que el cliente usó en este pedido se le devuelve.
 *  4. Se libera el stock reservado.
 *
 * «Por devolver» es lo que la empresa tiene que devolverle al cliente: el
 * dinero cobrado. El flete de provincia NO entra: ese se lo pagó a la agencia.
 */
function pedido_anular(int $pedido_id, int $motivo_item_id, string $nota = ''): array
{
    /* Anular apaga los pagos pendientes del pedido con un UPDATE directo, sin
       pasar por pago_anular(). Eso escribe pagos, así que las cifras cacheadas
       de «pagos por confirmar» tienen que refrescarse igual: si el pedido no
       tenía ningún pago validado, pago_devolver() no llega a correr y nadie
       las tiraría. En una petición web lo tapa la redirección; en el banco de
       pruebas y en un script de mantenimiento, no. */
    return con_pagos_frescos(fn() => pedido_anular_hacer($pedido_id, $motivo_item_id, $nota));
}

/**
 * $quien: quién firma la anulación. null significa «lo hizo el HUB», y es lo
 * que usa el barrido de los pagos denegados: eso lo dispara cualquiera que
 * abra la lista de pedidos, y firmar con la sesión dejaba pedidos de otros
 * asesores —de otro país incluso— «anulados por» quien solo estaba mirando.
 * Se pasa como parámetro y no tocando $_SESSION: vaciar el superglobal deja
 * una ventana en la que un fatal o un tiempo agotado guardaría la sesión sin
 * usuario, y el asesor aparecería de golpe en la pantalla de entrar.
 *
 * $exigir_sin_cobros: no anular si entretanto ha entrado un pago vivo. Se
 * comprueba DENTRO de la transacción, con la fila ya reservada, que es donde
 * la comprobación vale algo.
 */
function pedido_anular_hacer(int $pedido_id, int $motivo_item_id, string $nota = '',
                             ?int $quien = 0, bool $exigir_sin_cobros = false): array
{
    /* 0 significa «la sesión», que es lo de siempre; null es «el HUB». */
    if ($quien === 0) $quien = $_SESSION['usuario_id'] ?? null;
    $p = pedido_de($pedido_id);
    if (!$p) return ['ok' => false, 'error' => 'Ese pedido no existe.', 'devolver' => 0];
    if ((string)$p['estado'] === 'anulado') {
        return ['ok' => false, 'error' => 'Este pedido ya estaba anulado.', 'devolver' => 0];
    }
    if (!lista_valida('motivos_anulacion', $motivo_item_id, (int)$p['pais_id'])) {
        return ['ok' => false, 'error' => 'Elige uno de los motivos de la lista.', 'devolver' => 0];
    }

    $anulado = estado_por_clave('anulado');
    if (!$anulado) return ['ok' => false, 'error' => 'Falta el estado «Anulado».', 'devolver' => 0];

    $devolver = en_transaccion(function () use ($p, $pedido_id, $motivo_item_id, $nota,
                                                $anulado, $quien, $exigir_sin_cobros) {

        /* El pedido se reserva ANTES de leer nada, y se vuelve a mirar si ya
           está anulado con la fila ya reservada.
           Sin esto, dos peticiones casi a la vez (un doble clic en «Anular»,
           dos pestañas) leían las dos que el pedido estaba vivo, las dos
           anotaban la devolución del mismo pago y el cobrado del mes bajaba el
           doble. La comprobación de fuera de la transacción no vale: para
           cuando esta empieza, ya es vieja.
           Y el orden importa: en todo el HUB se reserva primero `pedidos` y
           después `pagos`. Dos caminos que los tomen al revés se quedan
           esperándose el uno al otro. */
        bloquear_fila('pedidos', $pedido_id);

        $ahora = (int) valor('SELECT estado_id FROM pedidos WHERE id = ?', [$pedido_id]);
        if ($ahora === (int)$anulado['id']) return -1;      // otra petición se adelantó
        /* UNA VENTA CON BOLETA O FACTURA ELECTRÓNICA VIGENTE NO SE ANULA SIN SU
           NOTA DE CRÉDITO (parche 2u), ni con una que se mandó sin respuesta:
           puede estar viva en SUNAT. El controlador de anular emite la nota
           primero, así que para él esto ya no salta; salta para quien llegue
           por otro camino —la tarea que anula los pagos denegados a las 48
           horas—: esa venta la mira una persona. Se mira AQUÍ, con la fila
           reservada, porque emitir también la reserva: si facturación emitió
           mientras tanto, se ve. */
        if (function_exists('nubefact_vigente') && nubefact_vigente($pedido_id)) return -2;
        if (function_exists('nubefact_pendiente') && nubefact_pendiente($pedido_id)) return -3;

        /* Y con la fila ya reservada, la última mirada: entre que el barrido
           hizo su lista y llegó aquí puede haber entrado el pago corregido, que
           es justo el pedido que alguien estaba salvando. */
        if ($exigir_sin_cobros) {
            $vivo = (int) valor("SELECT COUNT(*) FROM pagos
                                  WHERE pedido_id = ? AND anulado = 0 AND tipo = 'cobro'",
                                [$pedido_id]);
            if ($vivo > 0) return -1;
        }

        /* Lo que hay que devolverle al cliente es lo que QUEDA cobrado ahora
           mismo, no la suma de los pagos que se van a revertir.
           No es lo mismo: si Administración ya le devolvió un pago la semana
           pasada, ese pago sigue en la tabla pero su devolución ya está
           anotada en negativo, así que `cobrado_centimos` ya lo descontó.
           Sumando pago a pago, la ficha mandaba devolver por segunda vez un
           dinero que la empresa ya había devuelto. */
        $devolver = max(0, (int) valor('SELECT cobrado_centimos FROM pedidos WHERE id = ?',
                                       [$pedido_id]));

        // ORDER BY id: dos anulaciones a la vez toman los candados de los
        // pagos en el mismo orden y no se quedan esperándose.
        foreach (todas('SELECT * FROM pagos WHERE pedido_id = ? AND anulado = 0 ORDER BY id',
                       [$pedido_id]) as $pago) {
            if ((int)$pago['verificado'] !== 1) {
                // Nunca contó para nada: se apaga y ya está.
                $apagar = [
                    'anulado'        => 1,
                    'anulado_por'    => $quien,
                    'anulado_en'     => date('Y-m-d H:i:s'),
                    'anulado_motivo' => 'Se anuló el pedido',
                ];
                /* Y SE LIMPIA «EN ESPERA». Una fila apagada y en espera a la
                   vez se contradice a sí misma, y pago_anular() lo tiene escrito
                   como imposible; este UPDATE, que no pasa por ahí, las dejaba.
                   Hoy no cambia ninguna cifra —todo consulta anulado = 0
                   primero— y por eso justamente conviene arreglarlo ahora: un
                   dato que se contradice acaba leyéndose el día que alguien
                   escriba la consulta que no mira el anulado. */
                if (columna_existe('pagos', 'en_espera')) {
                    $apagar['en_espera']  = 0;
                    $apagar['espera_nota'] = null;
                }
                actualizar('pagos', (int)$pago['id'], $apagar);
                continue;
            }
            if ((string)($pago['tipo'] ?? 'cobro') !== 'cobro') continue;   // ya es una devolución

            // Si ya estaba devuelto, pago_devolver() lo dice y no hace nada.
            // El importe no se toca aquí: sale de cobrado_centimos, arriba.
            pago_devolver((int)$pago['id'], 'Se anuló el pedido');
        }

        cashback_devolver_uso($pedido_id);
        /* Lo que la venta descontó de la web, vuelve (3e). Aquí solo se
           apunta; se manda a la tienda al terminar, fuera de la transacción. */
        stock_venta_devolver($pedido_id);
        /* Los repuestos que salieron del almacén del HUB, vuelven (3i). */
        if (function_exists('stock_hub_devolver')) stock_hub_devolver('pedido', $pedido_id, 'anulacion');
        /* Y sus garantías que no salieron se anulan con él (3i). */
        if (function_exists('garantias_al_anular_pedido')) garantias_al_anular_pedido($pedido_id);

        actualizar('pedidos', $pedido_id, [
            'estado_id'              => (int)$anulado['id'],
            'anulado_motivo_item_id' => $motivo_item_id,
            'anulado_nota'           => mb_substr($nota, 0, 300),
            'anulado_por'            => $quien,
            'anulado_en'             => date('Y-m-d H:i:s'),
            'por_devolver_centimos'  => $devolver,
        ]);
        pedido_recalcular($pedido_id);
        /* Una pre venta de un lote que YA LLEGÓ: lo que vuelve al lote vuelve
           al almacén (3i). Después de marcarla anulada: la cuenta mira las vivas. */
        if (function_exists('stock_hub_lote_al_dia') && columna_existe('pedido_lineas', 'lote_linea_id')) {
            foreach (todas("SELECT DISTINCT ll.lote_id FROM pedido_lineas pl JOIN lote_lineas ll ON ll.id = pl.lote_linea_id
                             JOIN lotes l ON l.id = ll.lote_id WHERE pl.pedido_id = ? AND l.estado = 'recibido' ORDER BY ll.lote_id", [$pedido_id]) as $lx) {
                bloquear_fila('lotes', (int)$lx['lote_id']);
                stock_hub_lote_al_dia((int)$lx['lote_id']);
            }
        }
        return $devolver;
    });

    if ($devolver === -1) {
        return ['ok' => false, 'error' => 'Este pedido ya estaba anulado.', 'devolver' => 0];
    }
    if ($devolver === -2) {
        return ['ok' => false, 'error' => 'Esta venta tiene un comprobante electrónico emitido: '
                                        . 'para anularla hay que emitir su nota de crédito.', 'devolver' => 0];
    }
    if ($devolver === -3) {
        return ['ok' => false, 'error' => 'Esta venta tiene una boleta o factura que se mandó sin respuesta: '
                                        . 'primero hay que saber si salió.', 'devolver' => 0];
    }

    /* La devolución del stock sale YA, si la tienda responde. Si no, la cola
       la reintenta sola. Nunca tumba la anulación, que ya está hecha. */
    /* El barrido de los pagos denegados ($quien null) anula muchas de una vez
       mientras alguien espera su lista de pedidos: lo suyo lo mueve la cola. */
    if ($quien !== null) {
        try { stock_cola_procesar($pedido_id); }
        catch (Throwable $ex) { error_log('[HUB stock] al anular: ' . $ex->getMessage()); }
        /* Lo que sus garantías devuelven a la web, también ya (3i). */
        if (function_exists('garantias_listo') && garantias_listo()) {
            garantias_mandar_devoluciones(array_column(todas('SELECT id FROM garantias WHERE pedido_id = ?', [$pedido_id]), 'id'));
        }
    }

    pedido_evento($pedido_id, 'anulacion',
        'Anulado · ' . lista_texto($motivo_item_id) . ($nota ? ' · ' . $nota : '')
        . ($devolver > 0 ? ' · por devolver ' . soles($devolver) : ''));
    bitacora('pedido.anular', 'pedido', $pedido_id,
             ['motivo' => lista_texto($motivo_item_id), 'devolver' => $devolver]);

    return ['ok' => true, 'error' => '', 'devolver' => $devolver];
}

/**
 * QUÉ LLEVA CADA VENTA, de muchas de una vez: [pedido_id => [líneas]]. Una
 * sola consulta para toda la lista. La usan la lista de pedidos y la bandeja
 * de pagos (3f), con la misma pieza de pantalla (pedidos/lineas_corto).
 */
function pedidos_lineas_de(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $en = implode(',', array_fill(0, count($ids), '?'));
    $col_modelo = columna_existe('pedido_lineas', 'modelo') ? 'l.modelo' : "''";
    $out = [];
    foreach (todas("SELECT l.pedido_id, l.descripcion, l.cantidad, $col_modelo AS modelo
                      FROM pedido_lineas l WHERE l.pedido_id IN ($en) ORDER BY l.id", $ids) as $l) {
        $out[(int)$l['pedido_id']][] = $l;
    }
    return $out;
}

/* EL STOCK de lo vendido vive en la web y se mueve desde stock_venta.php
   (3e): stock_venta_preparar() al registrar y stock_venta_devolver() al
   anular. Las viejas pedido_reservar_stock()/pedido_liberar_stock() escribían
   en la tabla `stock`, que nadie lee: se quitaron para que haya UNA regla. */
