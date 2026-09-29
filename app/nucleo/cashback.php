<?php
declare(strict_types=1);

/**
 * Cashback Waka.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LA REGLA QUE YA HIZO TROPEZAR AL PROYECTO DOS VECES:
 *  EL CASHBACK NACE DEL **PAGO**, NUNCA DEL PEDIDO.
 *
 *  Una pre venta de S/20,000 con el 10% adelantado acredita S/20.00 hoy, no
 *  S/200. Los otros S/180 nacen el día que entre el saldo. Y al anular ese
 *  pedido se revierte lo que se acreditó por los pagos que de verdad
 *  entraron — no el 1% del total del pedido.
 *
 *  Por eso NINGUNA función de este archivo acepta un pedido_id para acreditar.
 *  Se acredita por pago_id y punto.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Es un libro de movimientos, no un saldo guardado. Nada se borra ni se
 * reescribe: una anulación entra como línea negativa.
 */

/** El porcentaje configurado. Editable desde Configuración. */
function cashback_porcentaje(): int
{
    return max(0, min(100, (int) ajuste('cashback_porcentaje', 1)));
}

/** Meses que dura el saldo antes de vencer. */
function cashback_meses(): int
{
    return max(1, (int) ajuste('cashback_meses', 6));
}

/** Mínimo acumulado para poder usarlo (en céntimos). */
function cashback_minimo(): int
{
    return max(0, (int) ajuste('cashback_minimo_centimos', 1000));   // S/ 10
}

/**
 * Lo que le toca a un pago.
 * Se trunca a céntimos enteros, nunca se redondea hacia arriba y nunca pasa
 * por float: 1% de S/1,450.80 son 1,450 céntimos, no 1,450.8.
 */
function cashback_de_pago_centimos(int $monto_centimos): int
{
    if ($monto_centimos <= 0) return 0;
    return intdiv($monto_centimos * cashback_porcentaje(), 100);
}

/**
 * LO QUE LE TOCA A UN PAGO DENTRO DE SU PEDIDO — el envío no cuenta.
 *
 * «Delivery no es sumado para cashback» (usuario, 2026-09-10). El envío que
 * cobra Waka sí entra en el total, pero no genera cashback.
 *
 * El filo está en que el cashback nace del PAGO y el cliente paga producto y
 * envío en un solo monto: con un pago parcial hay que saber qué parte de ese
 * dinero era envío. Se reparte EN PROPORCIÓN — producto S/1,000 + envío S/50,
 * paga S/500 → el 95.2% es producto → S/4.76 — porque es el único reparto que
 * se deshace bien: revertir ese pago quita exactamente lo que acreditó.
 * Cobrar el envío «al final» cuadra igual solo mientras nada se revierta, y
 * aquí se revierte.
 *
 * Se trunca hacia abajo y nunca pasa por float, como todo el dinero del HUB.
 */
function cashback_de_pago_en_pedido(int $monto_centimos, array $pedido): int
{
    if ($monto_centimos <= 0) return 0;

    $total = (int)($pedido['total_centimos'] ?? 0);
    $base  = pedido_base_cashback($pedido);

    /* Sin parte de producto no hay cashback. Va PRIMERO: escrito después, un
       pedido cuyo producto vale 0 caía por la rama de «no hay flete» y
       acreditaba el 1% entero — el valor por defecto al revés. Hoy no se puede
       llegar ahí, pero es una trampa cargada para el día que se pueda editar
       un descuento. */
    if ($base <= 0) return 0;
    /* El caso normal: no hay envío cobrado y la base ES el total. Se atiende
       aparte para no meter una multiplicación de dos importes grandes donde no
       hace ninguna falta. */
    if ($base >= $total) return cashback_de_pago_centimos($monto_centimos);

    /* La parte de ese pago que es producto, y sobre ella el porcentaje. */
    $parte_producto = intdiv($monto_centimos * $base, $total);
    return cashback_de_pago_centimos($parte_producto);
}

/* ─────────────────────────  SALDO  ───────────────────────── */

/**
 * Lo que el cliente puede usar HOY.
 *
 * Son dos límites y manda el más chico:
 *  · el libro (suma de todos los movimientos), y
 *  · lo que queda vivo en los bloques que todavía no han vencido.
 * El segundo hace falta porque el proceso diario de vencimiento puede ir
 * retrasado, y nadie debe poder gastar un saldo que ya venció solo porque el
 * cron no ha pasado. El primero hace falta porque, si se revirtió un pago
 * cuyo cashback ya se había gastado, el libro queda en negativo y ese negativo
 * tiene que absorberse antes de volver a dar saldo.
 */
function cashback_saldo(int $cliente_id): int
{
    $libro = (int) valor('SELECT COALESCE(SUM(monto_centimos),0) FROM cashback_movimientos
                           WHERE cliente_id = ?', [$cliente_id]);

    $vivos = (int) valor(
        "SELECT COALESCE(SUM(monto_centimos - consumido_centimos),0)
           FROM cashback_movimientos
          WHERE cliente_id = ? AND tipo = 'acredita'
            AND (vence_en IS NULL OR vence_en >= ?)",
        [$cliente_id, date('Y-m-d')]
    );

    return max(0, min($libro, $vivos));
}

/** La suma cruda del libro. Puede ser negativa; solo la mira Administración. */
function cashback_libro(int $cliente_id): int
{
    return (int) valor('SELECT COALESCE(SUM(monto_centimos),0) FROM cashback_movimientos
                         WHERE cliente_id = ?', [$cliente_id]);
}

/** Lo que vence dentro de $dias días, para el aviso al asesor. */
function cashback_por_vencer(int $cliente_id, int $dias = 30): int
{
    return (int) valor(
        "SELECT COALESCE(SUM(monto_centimos - consumido_centimos),0)
           FROM cashback_movimientos
          WHERE cliente_id = ? AND tipo = 'acredita'
            AND vence_en >= ? AND vence_en <= ?",
        [$cliente_id, date('Y-m-d'), date('Y-m-d', strtotime("+$dias days"))]
    );
}

/** La parte de abajo de la fracción del tope. Viaja también al JavaScript de
    la pantalla, para que el 3 no esté escrito dos veces: el porcentaje y el
    mínimo del cashback ya viajaban así. */
const CASHBACK_TOPE_PARTES = 3;

/**
 * El tope por pedido: la TERCERA PARTE del total ANTES del descuento.
 *
 * Sobre el bruto y no sobre el ya descontado porque si no el cálculo se muerde
 * la cola: el descuento cambiaría la base que lo determina.
 * Dicho al revés, que es como se usa vendiendo: el pedido tiene que valer al
 * menos tres veces lo que se quiere descontar.
 */
function cashback_tope_del_pedido(int $total_bruto_centimos): int
{
    return max(0, intdiv($total_bruto_centimos, CASHBACK_TOPE_PARTES));
}

/**
 * Cuánto se puede aplicar de verdad en este pedido.
 * Devuelve 0 si el saldo no llega al mínimo: por debajo de S/10 no se usa.
 * Si el pedido es más chico que el saldo, entra la parte que cabe y el resto
 * se queda acumulado — no se pierde nada.
 */
function cashback_aplicable(int $cliente_id, int $total_bruto_centimos): int
{
    $saldo = cashback_saldo($cliente_id);
    if ($saldo < cashback_minimo()) return 0;
    return max(0, min($saldo, cashback_tope_del_pedido($total_bruto_centimos)));
}

/* ─────────────────────────  ACREDITAR  ───────────────────────── */

/**
 * Acredita el 1% de UN PAGO. Se llama cuando el pago pasa a contar como
 * cobrado: al registrarlo si el método cuenta al instante, o al validarlo si
 * había que confirmarlo en el banco.
 *
 * Es idempotente: si ese pago ya acreditó, no vuelve a hacerlo. Sin esto,
 * validar dos veces (dos pestañas abiertas, un doble clic) regalaría el doble.
 *
 * La fecha de vencimiento se calcula desde la fecha DEL PAGO, no de hoy, y se
 * guarda en la fila: cambiar el ajuste después no altera lo ya acreditado.
 */
function cashback_acreditar_pago(int $pago_id): int
{
    /* ═══════════════════════════════════════════════════════════════════
       `pagos` Y `pedidos` TIENEN LOS DOS UNA COLUMNA `tipo`, Y NO SIGNIFICAN
       LO MISMO: la del pago es 'cobro' o 'devolucion'; la del pedido es el
       tipo de venta. Con `p.*` en la consulta gana la del pago, así que
       pasarle esta fila a una función que espera un PEDIDO le hace leer
       'cobro' donde busca 'liquidacion' — y la liquidación acreditaba
       cashback igual, sin un solo error. Lo encontró una prueba.
       Por eso el tipo del pedido viene con otro nombre y abajo se arma un
       array con la forma de un pedido, en vez de reusar el del pago.
       ═══════════════════════════════════════════════════════════════════ */
    $pago = una('SELECT p.*, pe.cliente_id, pe.tipo AS pedido_tipo, pe.total_centimos,
                        pe.flete_centimos, pe.flete_lo_paga
                   FROM pagos p
                   JOIN pedidos pe ON pe.id = p.pedido_id WHERE p.id = ?', [$pago_id]);
    if (!$pago) return 0;
    if ((int)$pago['anulado'] === 1) return 0;
    if ((string)($pago['tipo'] ?? 'cobro') !== 'cobro') return 0;   // una devolución no acredita
    if ((int)$pago['monto_centimos'] <= 0) return 0;

    $ya = valor("SELECT id FROM cashback_movimientos
                  WHERE pago_id = ? AND tipo = 'acredita'", [$pago_id]);
    if ($ya) return 0;

    /* El pedido, con forma de pedido. Ver el aviso de arriba. */
    $del_pedido = [
        'tipo'           => (string)($pago['pedido_tipo'] ?? ''),
        'total_centimos' => (int)$pago['total_centimos'],
        'flete_centimos' => (int)$pago['flete_centimos'],
        'flete_lo_paga'  => (string)$pago['flete_lo_paga'],
    ];

    $monto = cashback_de_pago_en_pedido((int)$pago['monto_centimos'], $del_pedido);
    if ($monto <= 0) return 0;

    $vence = date('Y-m-d', strtotime((string)$pago['fecha'] . ' +' . cashback_meses() . ' months'));

    /* El «¿ya acreditó?» de arriba es una lectura, y entre esa lectura y este
       INSERT cabe otra petición: dos pestañas abiertas en la bandeja de
       validación, dos clics casi a la vez, y el cliente se lleva el cashback
       dos veces. Quien lo impide de verdad es el índice único (pago_id, tipo)
       de la base; aquí solo se recoge el choque y se sigue como si nada,
       porque el otro clic ya hizo el trabajo. */
    try {
        insertar('cashback_movimientos', [
            'cliente_id'     => (int)$pago['cliente_id'],
            'tipo'           => 'acredita',
            'monto_centimos' => $monto,
            'pago_id'        => $pago_id,
            'pedido_id'      => (int)$pago['pedido_id'],
            'vence_en'       => $vence,
            'motivo'         => pedido_flete_dentro($del_pedido) > 0
                                ? cashback_porcentaje() . '% de ' . soles((int)$pago['monto_centimos'])
                                  . ' (sin el envío)'
                                : cashback_porcentaje() . '% de ' . soles((int)$pago['monto_centimos']),
            'usuario_id'     => $_SESSION['usuario_id'] ?? null,
        ]);
    } catch (PDOException $ex) {
        if (!es_choque_de_unico($ex)) throw $ex;
        return 0;
    }
    return $monto;
}

/**
 * Deshace el cashback de un pago: al anularlo, al devolverlo o al quitarle la
 * validación. Solo toca lo de ESE pago; los demás pagos del pedido siguen.
 *
 * Si el cliente ya se gastó parte de ese cashback, el libro queda en negativo
 * a propósito: es una deuda real y se absorbe con los cashbacks siguientes.
 * Lo contrario —dejarlo en cero— sería regalarle dinero por anular.
 */
function cashback_revertir_pago(int $pago_id, string $motivo = ''): int
{
    $bloque = una("SELECT * FROM cashback_movimientos
                    WHERE pago_id = ? AND tipo = 'acredita'", [$pago_id]);
    if (!$bloque) return 0;

    $ya = valor("SELECT id FROM cashback_movimientos
                  WHERE pago_id = ? AND tipo = 'revierte'", [$pago_id]);
    if ($ya) return 0;                                   // no se revierte dos veces

    $monto = (int)$bloque['monto_centimos'];

    /* Lo que se puede revertir es lo que ese bloque todavía pesa en el libro.
       Si ya venció, su fila «vence» ya lo restó: revertirlo entero otra vez
       le dejaba al cliente una deuda fantasma que se comía sus cashbacks
       siguientes, sin que nada en pantalla lo explicara. Lo encontró la
       segunda auditoría. */
    $vencido = abs((int) valor("SELECT COALESCE(SUM(monto_centimos),0) FROM cashback_movimientos
                                 WHERE bloque_id = ? AND tipo = 'vence'", [(int)$bloque['id']]));
    $revertir = max(0, $monto - $vencido);

    /* Y «lo que ya se había usado» es lo que se gastó de verdad en pedidos, no
       la columna de consumido — que el vencimiento también usa para marcar. */
    $gastado = abs((int) valor("SELECT COALESCE(SUM(monto_centimos),0) FROM cashback_movimientos
                                 WHERE bloque_id = ? AND tipo = 'usa'", [(int)$bloque['id']]));

    if ($revertir <= 0) {
        // No queda nada que quitar, pero el bloque no puede seguir vivo.
        actualizar('cashback_movimientos', (int)$bloque['id'], ['consumido_centimos' => $monto]);
        return 0;
    }

    try {
        insertar('cashback_movimientos', [
            'cliente_id'     => (int)$bloque['cliente_id'],
            'tipo'           => 'revierte',
            'monto_centimos' => -$revertir,
            'pago_id'        => $pago_id,
            'pedido_id'      => $bloque['pedido_id'],
            'bloque_id'      => (int)$bloque['id'],
            'motivo'         => ($motivo ?: 'Pago revertido')
                              . ($gastado > 0 ? ' · ya se había usado ' . soles($gastado) : ''),
            'usuario_id'     => $_SESSION['usuario_id'] ?? null,
        ]);
    } catch (PDOException $ex) {
        if (!es_choque_de_unico($ex)) throw $ex;
        return 0;                                     // otra petición ya lo revirtió
    }

    // El bloque deja de estar disponible: se marca como consumido entero.
    actualizar('cashback_movimientos', (int)$bloque['id'], ['consumido_centimos' => $monto]);

    return $revertir;
}

/* ─────────────────────────  USAR  ───────────────────────── */

/**
 * Aplica saldo a un pedido. Consume FIFO: primero el bloque más antiguo, o el
 * vencimiento calcularía mal (se gastaría el saldo nuevo y vencería el viejo).
 * Devuelve lo que de verdad se aplicó, que puede ser menos de lo pedido.
 */
function cashback_usar(int $cliente_id, int $pedido_id, int $monto_centimos): int
{
    if ($monto_centimos <= 0) return 0;
    $disponible = cashback_saldo($cliente_id);
    $usar = min($monto_centimos, $disponible);
    if ($usar <= 0) return 0;

    $queda = $usar;
    $bloques = todas(
        "SELECT * FROM cashback_movimientos
          WHERE cliente_id = ? AND tipo = 'acredita'
            AND (vence_en IS NULL OR vence_en >= ?)
            AND monto_centimos > consumido_centimos
          ORDER BY vence_en ASC, id ASC",
        [$cliente_id, date('Y-m-d')]
    );
    foreach ($bloques as $b) {
        if ($queda <= 0) break;
        $libre = (int)$b['monto_centimos'] - (int)$b['consumido_centimos'];
        $toma  = min($libre, $queda);
        actualizar('cashback_movimientos', (int)$b['id'],
                   ['consumido_centimos' => (int)$b['consumido_centimos'] + $toma]);

        /* Una fila «usa» POR BLOQUE, con el bloque anotado. Es lo que permite
           devolver a su sitio exacto si el pedido se anula: con una sola fila
           por pedido no había forma de saber de dónde había salido cada sol, y
           la devolución liberaba el bloque equivocado. */
        insertar('cashback_movimientos', [
            'cliente_id'     => $cliente_id,
            'tipo'           => 'usa',
            'monto_centimos' => -$toma,
            'pedido_id'      => $pedido_id,
            'bloque_id'      => (int)$b['id'],
            'motivo'         => 'Descuento aplicado en el pedido',
            'usuario_id'     => $_SESSION['usuario_id'] ?? null,
        ]);
        $queda -= $toma;
    }
    return $usar - $queda;
}

/**
 * Devuelve al cliente el saldo que había usado en un pedido que se anula.
 *
 * Vuelve a SU bloque, no a cualquiera: cada fila «usa» sabe de qué bloque
 * salió. Así el saldo devuelto conserva su fecha de vencimiento original —
 * darle seis meses nuevos sería regalarle vigencia por anular — y el consumo
 * de otros pedidos, que siguen vivos, no se toca.
 */
function cashback_devolver_uso(int $pedido_id): int
{
    $usos = todas("SELECT * FROM cashback_movimientos
                    WHERE pedido_id = ? AND tipo = 'usa'", [$pedido_id]);
    if (!$usos) return 0;

    $ya = valor("SELECT id FROM cashback_movimientos
                  WHERE pedido_id = ? AND tipo = 'ajuste' AND motivo LIKE 'Devolución por anulación%'",
                [$pedido_id]);
    if ($ya) return 0;

    $total = 0;
    $cliente_id = (int)$usos[0]['cliente_id'];

    $huerfano = 0;
    foreach ($usos as $u) {
        $monto = abs((int)$u['monto_centimos']);
        if ($monto <= 0) continue;
        $total += $monto;

        if (empty($u['bloque_id'])) { $huerfano += $monto; continue; }

        $b = una('SELECT * FROM cashback_movimientos WHERE id = ?', [(int)$u['bloque_id']]);
        if (!$b) { $huerfano += $monto; continue; }

        /* Un bloque VENCIDO no se resucita: soltarle el consumo lo devolvería
           a la vida y el cliente se gastaría un saldo muerto. El importe entra
           igual en el ajuste, así que el libro cuadra; lo que no vuelve es la
           vigencia, que es justo lo que no debe volver. */
        if ($b['vence_en'] && (string)$b['vence_en'] < date('Y-m-d')) continue;

        $suelta = min((int)$b['consumido_centimos'], $monto);
        if ($suelta > 0) {
            actualizar('cashback_movimientos', (int)$b['id'],
                       ['consumido_centimos' => (int)$b['consumido_centimos'] - $suelta]);
        }
    }

    /* Filas «usa» de antes de que existiera bloque_id: no se sabe de qué
       bloque salieron. Se sueltan por el camino de vuelta (el bloque que
       vence más tarde primero) en vez de dejar al cliente con un reintegro
       que ve en pantalla y no puede gastar. */
    if ($huerfano > 0) {
        foreach (todas("SELECT * FROM cashback_movimientos
                         WHERE cliente_id = ? AND tipo = 'acredita' AND consumido_centimos > 0
                           AND (vence_en IS NULL OR vence_en >= ?)
                         ORDER BY vence_en DESC, id DESC", [$cliente_id, date('Y-m-d')]) as $b) {
            if ($huerfano <= 0) break;
            $suelta = min((int)$b['consumido_centimos'], $huerfano);
            actualizar('cashback_movimientos', (int)$b['id'],
                       ['consumido_centimos' => (int)$b['consumido_centimos'] - $suelta]);
            $huerfano -= $suelta;
        }
    }

    if ($total <= 0) return 0;

    insertar('cashback_movimientos', [
        'cliente_id'     => $cliente_id,
        'tipo'           => 'ajuste',
        'monto_centimos' => $total,
        'pedido_id'      => $pedido_id,
        'motivo'         => 'Devolución por anulación del pedido',
        'usuario_id'     => $_SESSION['usuario_id'] ?? null,
    ]);
    return $total;
}

/* ─────────────────────────  VENCIMIENTO  ───────────────────────── */

/**
 * Anota como vencido el saldo cumplido. Lo llamará el proceso diario del
 * módulo de Cashback; mientras tanto se llama al registrar un pago, para que
 * el libro de los clientes activos no se quede atrás.
 * No hace falta para que el saldo sea correcto —cashback_saldo() ya excluye
 * los bloques vencidos—, pero sí para que el libro cuadre a la vista.
 */
function cashback_vencer_pendientes(?int $cliente_id = null): int
{
    $par = [date('Y-m-d')];
    $sql = "SELECT * FROM cashback_movimientos
             WHERE tipo = 'acredita' AND vence_en IS NOT NULL AND vence_en < ?
               AND monto_centimos > consumido_centimos";
    if ($cliente_id) { $sql .= ' AND cliente_id = ?'; $par[] = $cliente_id; }
    $sql .= ' ORDER BY id LIMIT 500';

    $n = 0;
    foreach (todas($sql, $par) as $b) {
        $resto = (int)$b['monto_centimos'] - (int)$b['consumido_centimos'];
        if ($resto <= 0) continue;

        /* OJO: esta fila va atada al BLOQUE (bloque_id), NO al pago.
           Atarla al pago la hacía chocar con el candado de «un pago, un
           movimiento de cada tipo»: si un bloque se vencía, se liberaba al
           anular un pedido y volvía a vencer, el segundo vencimiento
           reventaba con un error de clave duplicada… y como esto se llama
           al registrar CUALQUIER pago de ese cliente, ese cliente no podía
           volver a pagar nunca. Lo encontró la segunda auditoría.
           El try es el cinturón: si dos peticiones vencen el mismo bloque a
           la vez, la segunda no tiene nada que hacer. */
        try {
            insertar('cashback_movimientos', [
                'cliente_id'     => (int)$b['cliente_id'],
                'tipo'           => 'vence',
                'monto_centimos' => -$resto,
                'pedido_id'      => $b['pedido_id'],
                'bloque_id'      => (int)$b['id'],
                'motivo'         => 'Venció el ' . fecha_corta((string)$b['vence_en']),
            ]);
        } catch (PDOException $ex) {
            if (!es_choque_de_unico($ex)) throw $ex;
        }
        // Se marca consumido pase lo que pase, para no reintentarlo en bucle.
        actualizar('cashback_movimientos', (int)$b['id'],
                   ['consumido_centimos' => (int)$b['monto_centimos']]);
        $n++;
    }
    return $n;
}

/** Los movimientos de un cliente, del más nuevo al más viejo. */
function cashback_movimientos(int $cliente_id, int $limite = 40): array
{
    return todas('SELECT c.*, p.codigo AS pedido_codigo
                    FROM cashback_movimientos c
                    LEFT JOIN pedidos p ON p.id = c.pedido_id
                   WHERE c.cliente_id = ?
                   ORDER BY c.id DESC LIMIT ' . (int)$limite, [$cliente_id]);
}
