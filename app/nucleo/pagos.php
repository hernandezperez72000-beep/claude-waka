<?php
declare(strict_types=1);

/**
 * Pagos.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LA META SE MIDE POR DINERO **COBRADO**, NO POR VENDIDO.
 *
 *  Una pre venta de S/20,000 con el 10% adelantado suma S/2,000 al mes del
 *  adelanto. Los S/18,000 suman al mes en que entren de verdad. Por eso el
 *  pedido no sabe sumar a la meta: suman los PAGOS, y cada uno al mes de SU
 *  fecha. Toda cifra de dinero cobrado del HUB sale de este archivo — panel,
 *  podio, ranking de equipos, bonos y reportes. Una sola definición, o dos
 *  pantallas acaban enseñando números distintos del mismo mes.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Y un pago solo cuenta cuando está CONFIRMADO, sin excepciones: hoy ningún
 * método suma al instante. Lo decidió el usuario el 2026-09-08, porque hay
 * clientes que mandan vouchers falsos y un pago que suma sin que nadie lo mire
 * es dinero inventado en el podio y cashback regalado. Quien confirma es
 * Facturación —y también Administración y Dirección—, y la fecha que cuenta es
 * la DEL PAGO, no la del día en que se confirmó.
 *
 * El interruptor «cuenta al instante» sigue existiendo por método, pero nace
 * apagado y todavía no hay pantalla para encenderlo —las listas editables son
 * del módulo 3—. Ver metodo_al_instante() en listas.php.
 */

const VOUCHER_MAX_BYTES = 5 * 1024 * 1024;

/**
 * El trozo de WHERE que define «dinero que entró de verdad».
 * Se escribe una vez y se pega en todas las consultas de dinero cobrado.
 *
 * Ojo con lo que NO lleva: no filtra por pedido anulado. Al anular se mete una
 * fila NEGATIVA fechada hoy, así que el mes viejo conserva su cifra y el mes
 * de la reversión es el que baja. Filtrar por «pedido no anulado» borraría
 * ventas del mes pasado sin avisar, que es justo lo que no puede pasar.
 */
const PAGO_CUENTA = 'p.anulado = 0 AND p.verificado = 1';

/* ─────────────────────────  CUÁNTO SE COBRÓ  ───────────────────────── */

/** Lo cobrado por un asesor en un mes (AAAA-MM). Es la barra de meta. */
function cobrado_del_mes(int $usuario_id, string $periodo): int
{
    return (int) valor(
        'SELECT COALESCE(SUM(p.monto_centimos),0)
           FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
          WHERE pe.asesor_id = ? AND ' . PAGO_CUENTA . '
            AND p.fecha >= ? AND p.fecha <= ?',
        [$usuario_id, $periodo . '-01', fin_de_mes($periodo)]
    );
}

/**
 * Lo que ese asesor cobró este mes pero TODAVÍA no está confirmado.
 *
 * No suma a la meta —para eso está PAGO_CUENTA— pero hay que enseñárselo. Si
 * un asesor vende, registra el pago y su barra no se mueve, lo que hace no es
 * esperar: es escribir diciendo que el HUB no le contó la venta. Ver la cifra
 * al lado, con su nombre, convierte un fallo aparente en una espera entendible.
 */
function pendiente_del_mes(int $usuario_id, string $periodo): int
{
    return (int) valor(
        'SELECT COALESCE(SUM(p.monto_centimos),0)
           FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
          WHERE pe.asesor_id = ? AND p.anulado = 0 AND p.verificado = 0
            AND p.fecha >= ? AND p.fecha <= ?',
        [$usuario_id, $periodo . '-01', fin_de_mes($periodo)]
    );
}

/** Lo cobrado entre dos fechas. `null` = todos los países, que es lo que ve Dirección. */
function cobrado_del_pais(?int $pais_id, string $desde, string $hasta): int
{
    $sql = 'SELECT COALESCE(SUM(p.monto_centimos),0)
              FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
             WHERE ' . PAGO_CUENTA . ' AND p.fecha >= ? AND p.fecha <= ?';
    return $pais_id === null
        ? (int) valor($sql, [$desde, $hasta])
        : (int) valor($sql . ' AND pe.pais_id = ?', [$desde, $hasta, $pais_id]);
}

/** El podio: asesores de un país ordenados por lo cobrado en el mes. */
function cobrado_por_asesor(int $pais_id, string $periodo, int $limite = 100): array
{
    return todas(
        "SELECT u.id, u.nombre, u.apellidos, u.foto, eq.nombre AS equipo,
                COALESCE((SELECT SUM(p.monto_centimos)
                            FROM pagos p JOIN pedidos pe ON pe.id = p.pedido_id
                           WHERE pe.asesor_id = u.id AND " . PAGO_CUENTA . "
                             AND p.fecha >= ? AND p.fecha <= ?), 0) AS cobrado
           FROM usuarios u
           JOIN roles r ON r.id = u.rol_id AND r.clave = 'asesor'
           LEFT JOIN equipos eq ON eq.id = u.equipo_id
          WHERE u.pais_id = ? AND u.activo = 1
          ORDER BY cobrado DESC, u.nombre ASC
          LIMIT " . (int)$limite,
        [$periodo . '-01', fin_de_mes($periodo), $pais_id]
    );
}

/** Los tres equipos, ordenados por lo cobrado. Nunca por lo vendido. */
function cobrado_por_equipo(int $pais_id, string $periodo): array
{
    return todas(
        "SELECT eq.id, eq.nombre,
                (SELECT COUNT(*) FROM usuarios x WHERE x.equipo_id = eq.id AND x.activo = 1) AS gente,
                COALESCE((SELECT SUM(p.monto_centimos)
                            FROM pagos p
                            JOIN pedidos pe ON pe.id = p.pedido_id
                            JOIN usuarios u ON u.id = pe.asesor_id
                           WHERE u.equipo_id = eq.id AND " . PAGO_CUENTA . "
                             AND p.fecha >= ? AND p.fecha <= ?), 0) AS cobrado
           FROM equipos eq
          WHERE eq.pais_id = ? AND eq.activo = 1
          ORDER BY cobrado DESC, eq.nombre",
        [$periodo . '-01', fin_de_mes($periodo), $pais_id]
    );
}

/** Último día del mes de un periodo AAAA-MM, como AAAA-MM-DD. */
function fin_de_mes(string $periodo): string
{
    return date('Y-m-t', strtotime($periodo . '-01'));
}

/**
 * Cuántos pagos esperan confirmación de ESTA persona, y cuál es el pago más
 * nuevo que alcanza a ver.
 *
 * Es la ÚNICA definición de «pagos por confirmar» del HUB: de aquí salen el
 * chip del menú, el aviso en vivo, la línea del saludo, la bandeja del panel y
 * la cabecera de la propia bandeja. Cuando la bandeja aplicaba el ámbito y
 * esta función solo el país, el chip decía 351 y la bandeja contestaba «no hay
 * nada esperando»: dos cifras del mismo dato en la misma pantalla, que es el
 * fallo que esta función existe para que no pueda ocurrir.
 *
 * El `ultimo` es lo que permite avisar sin recargar: la pantalla se queda con
 * ese número y pregunta cada pocos segundos; si el de ahora es mayor, es que
 * pasó algo. Comparar el CONTADOR no serviría — si entra uno y se confirma
 * otro, el total no se mueve y el aviso no saltaría.
 *
 * Y `ultimo` sale de TODOS los pagos, no solo de los que esperan. Salía de los
 * pendientes, así que con la bandeja vacía —justo como queda facturación al
 * terminar una tanda— valía 0, y desde 0 no se puede decir cuántos entraron.
 * Es una marca de agua del reloj, no un contador: sube aunque la bandeja se
 * vacíe. Quien avisa de verdad es `nuevos`, no esta marca.
 */
function pagos_por_validar_resumen(?array $u = null, bool $olvidar = false): array
{
    /* Cacheado por petición y por persona: en una sola página lo preguntan el
       marco (para el aviso vivo), el menú (para el chip), la línea del saludo y
       la bandeja. Eran cuatro agregados idénticos sobre la tabla que más crece
       del HUB. Por persona, porque el banco de pruebas cambia de cuenta a
       media pasada y la respuesta depende de quién pregunta. */
    static $cache = [];
    $vacio = ['n'=>0, 'sin_revisar'=>0, 'en_espera'=>0, 'monto'=>0, 'monto_espera'=>0, 'ultimo'=>0];
    if ($olvidar) { $cache = []; return $vacio; }

    $u ??= yo();
    if (!$u) return $vacio;
    $llave = (int)$u['id'];
    if (isset($cache[$llave])) return $cache[$llave];

    /* EL HUB SIN actualizar.php NO SE CAE POR ESTO. Esta función corre en el
       MARCO, o sea en cada render de cada página, así que una columna que
       falte aquí no rompe una pantalla: las rompe todas, y para todos los
       roles — ni siquiera se puede aterrizar después de entrar. Sin las
       columnas, se contesta cero: es la forma segura de fallar. */
    if (!tabla_existe('pagos') || !columna_existe('pagos', 'en_espera')) return $vacio;

    [$ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);

    /* Tres cifras de una sola consulta, y con nombre cada una:
         n           → todo lo que espera confirmación (el dinero pendiente)
         sin_revisar → lo que NADIE ha mirado todavía. Es el trabajo del día,
                       y es lo que cuenta el chip del menú y el aviso vivo.
         en_espera   → lo ya revisado que espera al banco.
       Se devuelven las tres para que ninguna pantalla tenga que sumar por su
       cuenta: en cuanto una lo hace, aparecen dos números del mismo dato. */
    $f = una("SELECT COUNT(*) AS n,
                     COALESCE(SUM(p.monto_centimos), 0) AS monto,
                     COALESCE(SUM(CASE WHEN p.en_espera = 1 THEN 1 ELSE 0 END), 0) AS en_espera,
                     COALESCE(SUM(CASE WHEN p.en_espera = 1 THEN p.monto_centimos ELSE 0 END), 0) AS monto_espera
                FROM pagos p
                JOIN pedidos pe       ON pe.id = p.pedido_id
                LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
               WHERE p.verificado = 0 AND p.anulado = 0 AND $ambito", $par);

    /* La marca de agua, aparte y sin el filtro de «pendiente»: el id más alto
       que esta persona alcanza a ver, se haya confirmado o no. */
    $ultimo = (int) valor(
        "SELECT COALESCE(MAX(p.id), 0)
           FROM pagos p
           JOIN pedidos pe       ON pe.id = p.pedido_id
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE $ambito", $par);

    $n      = (int)($f['n'] ?? 0);
    $espera = (int)($f['en_espera'] ?? 0);

    return $cache[$llave] = [
        'n'            => $n,
        'sin_revisar'  => max(0, $n - $espera),
        'en_espera'    => $espera,
        'monto'        => (int)($f['monto'] ?? 0),
        'monto_espera' => (int)($f['monto_espera'] ?? 0),
        'ultimo'       => $ultimo,
    ];
}

/**
 * Tira esa caché. La llama todo lo que escribe un pago.
 *
 * En una petición web sobra —una petición, una foto— pero el banco de pruebas
 * y los scripts de mantenimiento registran y consultan en el mismo proceso, y
 * ahí una cifra congelada al principio del script es una prueba que pasa
 * midiendo lo que había antes.
 */
function pagos_resumen_olvidar(): void
{
    pagos_por_validar_resumen(null, true);
    /* Y los contadores del menú, que sacan de aquí su número de pagos: vaciar
       uno y dejar el otro devolvía dos cifras distintas de lo mismo, que es
       justo lo que estas funciones existen para evitar. */
    if (function_exists('contadores_olvidar')) contadores_olvidar();
}

/**
 * Corre una escritura de pagos y tira las cachés DESPUÉS, pase lo que pase.
 *
 * Antes se vaciaban al ENTRAR. Funcionaba de milagro: como nada dentro volvía
 * a preguntar el resumen, la caché quedaba vacía al salir. Pero el día que
 * alguien meta una lectura entre el vaciado y el commit, se cachearía el valor
 * de ANTES de la escritura y sobreviviría a ella. Con `finally` la cifra
 * también se refresca cuando la transacción revienta.
 */
function con_pagos_frescos(callable $fn): array
{
    try {
        return $fn();
    } finally {
        pagos_resumen_olvidar();
    }
}

/** Cuántos pagos por confirmar, de los que esta persona ve, entraron tras ese id. */
function pagos_nuevos_desde(int $desde, ?array $u = null): int
{
    $u ??= yo();
    if (!$u) return 0;

    [$ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    array_unshift($par, $desde);

    return (int) valor(
        "SELECT COUNT(*) FROM pagos p
           JOIN pedidos pe       ON pe.id = p.pedido_id
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE p.verificado = 0 AND p.anulado = 0 AND p.en_espera = 0
            AND p.id > ? AND $ambito", $par);
}

/**
 * LOS RECLAMOS NUEVOS (3b.5). Cuando el asesor reclama que le confirmen una
 * venta, a Facturación y Administración les suena el aviso igual que cuando
 * entra un pago: el reclamo solo subía la fila en la bandeja, en silencio, y
 * lo veía quien ya estuviera mirando.
 *
 * La marca de agua es el id más alto de la bitácora —cualquier línea—: se lee
 * por la clave, sin recorrer la tabla, que es la que más crece. Un reclamo es
 * una línea `pedido.reclamo` de la bitácora (la escribe pedido_reclamar()).
 *
 * Solo cuentan los reclamos de ventas que SIGUEN esperando un pago sin revisar
 * y que la persona puede ver (su país y su ámbito, como la bandeja): si
 * facturación confirmó en el mismo minuto, ya no hay nada que avisar.
 *
 * → ['ultimo' => marca de agua, 'n' => cuántos entraron, 'codigo' => el del
 *    más nuevo, 'asesor' => quién lo reclamó]
 */
function reclamos_marca(): int
{
    return (int) valor('SELECT COALESCE(MAX(id), 0) FROM bitacora');
}

function reclamos_nuevos_desde(int $desde, ?array $u = null): array
{
    $vacio = ['ultimo' => $desde, 'n' => 0, 'codigo' => '', 'asesor' => ''];
    $u ??= yo();
    if (!$u || !le_avisamos_de_pagos($u) || !columna_existe('pagos', 'en_espera')) return $vacio;
    $marca = reclamos_marca();
    $vacio['ultimo'] = $marca;
    if ($desde <= 0 || $marca <= $desde) return $vacio;

    [$ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);
    $filas = todas("SELECT b.id, pe.codigo, ub.nombre AS asesor
                      FROM bitacora b
                      JOIN pedidos pe       ON pe.id = b.entidad_id
                      LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
                      LEFT JOIN usuarios ub ON ub.id = b.usuario_id
                     WHERE b.id > ? AND b.id <= ? AND b.accion = 'pedido.reclamo' AND b.entidad = 'pedido'
                       AND EXISTS (SELECT 1 FROM pagos p WHERE p.pedido_id = pe.id
                                      AND p.verificado = 0 AND p.anulado = 0 AND p.en_espera = 0)
                       AND $ambito
                     ORDER BY b.id DESC", array_merge([$desde, $marca], $par));
    if (!$filas) return $vacio;
    return ['ultimo' => $marca, 'n' => count($filas),
            'codigo' => (string)$filas[0]['codigo'], 'asesor' => (string)($filas[0]['asesor'] ?? '')];
}

/**
 * EL NÚMERO DE UN PAGO DENTRO DE SU PEDIDO — 1.er pago, 2.º pago…
 *
 * Lo pidió el usuario el 2026-09-21. No se guarda: se CUENTA, por orden de
 * registro y solo entre los cobros (una devolución no es «el 3.er pago»). Los
 * pagos denegados o quitados cuentan igual, para que el número de un pago no
 * cambie porque otro se cayó: «el 2.º pago» tiene que ser el mismo el lunes y
 * el viernes. `$alias` es el alias de la tabla `pagos` en la consulta.
 */
function sql_pago_numero(string $alias = 'pg'): string
{
    $solo_cobros = columna_existe('pagos', 'tipo') ? " AND pn.tipo = 'cobro'" : '';
    return "(SELECT COUNT(*) FROM pagos pn
              WHERE pn.pedido_id = $alias.pedido_id AND pn.id <= $alias.id$solo_cobros)";
}

/** «1.er pago», «2.º pago», «3.er pago»… */
function pago_ordinal(int $n): string
{
    if ($n <= 0) return 'Pago';
    return $n . (in_array($n, [1, 3], true) ? '.er' : '.º') . ' pago';
}

/** Lo que se lee de un pago en una fila: «2.º pago · Saldo contraentrega». */
function pago_rotulo(array $pg): string
{
    if ((string)($pg['tipo'] ?? 'cobro') === 'devolucion') return 'Devolución';
    $txt = pago_ordinal((int)($pg['numero'] ?? 0));
    $c = trim((string)($pg['concepto'] ?? ''));
    return $c !== '' ? $txt . ' · ' . $c : $txt;
}

/** Los pagos de un pedido, con el nombre del método y su número. */
function pagos_del_pedido(int $pedido_id): array
{
    return todas(
        'SELECT pg.*, li.valor AS metodo, u.nombre AS registro_nombre,
                ' . sql_pago_numero('pg') . ' AS numero,
                (SELECT COUNT(*) FROM pagos r
                  WHERE r.revierte_pago_id = pg.id AND r.anulado = 0) AS ya_revertido
           FROM pagos pg
           LEFT JOIN lista_items li ON li.id = pg.metodo_item_id
           LEFT JOIN usuarios u     ON u.id  = pg.registrado_por
          WHERE pg.pedido_id = ? ORDER BY pg.id', [$pedido_id]
    );
}

/* ─────────────────────────  REGISTRAR  ───────────────────────── */

/**
 * ¿LE PASA ALGO A LA FECHA DE ESTE PAGO? Devuelve el aviso, o '' si está bien.
 *
 * LAS CUATRO REGLAS DE LA FECHA, EN UN SOLO SITIO. Existen porque la fecha
 * decide a qué mes suma el dinero: hacia adelante movería la meta de un mes que
 * no empezó, y hacia atrás cambia el podio, la barra y los bonos de un período
 * ya cerrado y pagado. Quien valida pagos (Administración) sí puede corregir
 * hacia atrás: para eso está.
 *
 * Y ESTÁN AQUÍ, Y NO DENTRO DE pago_registrar(), porque hacen falta en DOS
 * momentos. `pago_registrar()` corre con el pedido YA GUARDADO —la venta nueva
 * se guarda primero y el pago se anota después—, así que una fecha mala dejaba
 * un pedido a cero que nadie podía arreglar: la ficha aplica esta misma regla
 * y vuelve a rechazarlo, y no hay pantalla para cambiar la fecha del pedido.
 * El formulario de nueva venta las comprueba ANTES de guardar nada. Escrita
 * dos veces, una de las dos se queda corta: ya pasó.
 */
function pago_fecha_problema(string $fecha, string $fecha_pedido): string
{
    /* fecha_valida(), que es la que usa checkdate(): con strtotime() a secas,
       «2026-02-30» pasaba y valía 2 de marzo. En MySQL esa columna es DATE, así
       que o revienta el INSERT o queda 0000-00-00 — y entonces ese dinero no
       pertenece a ningún mes y no suma a ninguna meta. */
    if (!fecha_valida($fecha)) return 'La fecha del pago no se entiende.';
    if ($fecha > date('Y-m-d')) return 'La fecha del pago no puede ser futura.';

    $choque = pago_antes_del_pedido($fecha, $fecha_pedido);
    if ($choque !== '') return $choque;

    if (!puede('pagos.verificar')) {
        $tope = max(1, (int) ajuste('pago_dias_atras', 30));
        if ($fecha < date('Y-m-d', strtotime('-' . $tope . ' days'))) {
            return 'Esa fecha tiene más de ' . $tope . ' días. Un pago tan antiguo mueve la meta '
                 . 'de un mes ya cerrado: pídeselo a Administración y queda registrado.';
        }
    }
    return '';
}

/**
 * ¿ESTE PAGO ES ANTERIOR A SU PEDIDO? Devuelve el aviso, o '' si cuadra.
 *
 * La regla vive aquí y en un solo sitio porque hace falta en DOS momentos:
 * cuando se registra un pago sobre un pedido que ya existe —pago_registrar()—
 * y cuando se registra la venta entera de golpe, ANTES de guardar nada
 * (app/controladores/pedidos/nuevo.php). Escrita dos veces, el formulario de
 * nueva venta dejaba pasar lo que pago_registrar() rechazaba después: el
 * pedido se guardaba, el pago no, y quedaba una venta a cero que el asesor no
 * podía arreglar desde ninguna pantalla. Pasó de verdad y por eso está así.
 */
function pago_antes_del_pedido(string $fecha_pago, string $fecha_pedido): string
{
    if ($fecha_pedido === '' || $fecha_pago === '') return '';
    if ($fecha_pago >= $fecha_pedido) return '';
    return 'El pago no puede ser anterior al pedido, del ' . fecha_corta($fecha_pedido) . '.';
}

/**
 * Registra un pago. Devuelve ['ok'=>bool,'error'=>string,'id'=>int,'cashback'=>int].
 *
 * Hoy todo pago nace PENDIENTE: no suma a la meta, no aparece en el podio y no
 * genera cashback hasta que alguien lo confirme. El código sigue mirando el
 * interruptor por método —«cuenta al instante»— porque el día que se quiera
 * volver a encender uno, se enciende en Configuración y no aquí; pero nace
 * apagado para todos.
 */
function pago_registrar(int $pedido_id, array $d): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0, 'cashback' => 0];

    $pedido = pedido_de($pedido_id);
    if (!$pedido)                            return $mal('Ese pedido no existe.');
    if ((string)$pedido['estado'] === 'anulado') return $mal('No se registran pagos en un pedido anulado.');

    $monto = (int)($d['monto_centimos'] ?? 0);
    if ($monto <= 0) return $mal('El monto tiene que ser mayor que cero.');
    if (!dinero_razonable($monto)) {
        return $mal('Ese monto no puede ser real: el máximo es ' . soles(DINERO_MAXIMO_CENTIMOS) . '.');
    }

    /* Contra lo que falta CONTANDO lo ya registrado sin confirmar, no contra el
       saldo confirmado. Ver pedido_por_registrar(): con el saldo confirmado, el
       mismo importe se podía registrar tres veces y al confirmarlos todos el
       pedido quedaba cobrado por el triple. */
    $saldo = pedido_por_registrar($pedido);
    if ($monto > $saldo) {
        $ya = pedido_saldo($pedido) - $saldo;
        return $mal('El pago (' . soles($monto) . ') es mayor que lo que falta por cobrar ('
                  . soles($saldo) . ')'
                  . ($ya > 0 ? ', porque ya hay ' . soles($ya) . ' registrado esperando confirmación' : '')
                  . '. Corrige el monto o revisa el pedido.');
    }

    $metodo = (int)($d['metodo_item_id'] ?? 0);
    if (!lista_valida('metodos_pago', $metodo, (int)$pedido['pais_id'])) {
        return $mal('Elige un método de pago de la lista.');
    }

    $fecha = (string)($d['fecha'] ?? date('Y-m-d'));
    $fecha_pedido = (string)($pedido['fecha'] ?: substr((string)$pedido['creado_en'], 0, 10));
    $mal_fecha = pago_fecha_problema($fecha, $fecha_pedido);
    if ($mal_fecha !== '') return $mal($mal_fecha);

    if (metodo_pide_comprobante($metodo) && empty($d['voucher'])) {
        return $mal('Este método de pago necesita la foto del voucher.');
    }
    /* LA FOTO DEL DNI (3f): los métodos marcados en Configuración (el POS)
       piden además la foto del documento de quien paga. */
    if (metodo_pide_dni($metodo) && empty($d['foto_dni'])) {
        return $mal(metodo_pide_dni_texto());
    }

    $al_instante = metodo_al_instante($metodo);

    /* De aquí abajo, todo o nada, y con el pedido reservado.
       Sin esto, dos pestañas de la misma ficha leían el mismo saldo, las dos
       pasaban el «no cobres más de lo que falta» y el pedido quedaba
       sobrecobrado, con la diferencia sumando a la meta del asesor. */
    return con_pagos_frescos(fn() => en_transaccion(function () use ($pedido_id, $monto, $metodo, $fecha, $d, $al_instante, $pedido, $mal) {

    bloquear_fila('pedidos', $pedido_id);

    /* Se vuelve a leer YA con la fila reservada: el de antes pudo quedar viejo
       mientras se esperaba el turno. Y se lee lo MISMO que fuera —contando los
       pagos pendientes— porque `cobrado_centimos` solo cuenta lo confirmado:
       leyendo «total − cobrado», esta comprobación no veía los pendientes y era
       exactamente la que tenía que verlos, que para eso es la que serializa.
       Dos pestañas registrando el saldo entero pasaban las dos. */
    $ped_ahora  = una('SELECT id, total_centimos, cobrado_centimos FROM pedidos WHERE id = ?',
                      [$pedido_id]);
    $saldo_real = $ped_ahora ? pedido_por_registrar($ped_ahora) : 0;
    if ($monto > $saldo_real) {
        return $mal('El pago (' . soles($monto) . ') es mayor que lo que falta por cobrar ('
                  . soles($saldo_real) . '). Alguien acaba de registrar otro pago: revisa el pedido.');
    }

    $id = insertar('pagos', [
        'pedido_id'      => $pedido_id,
        'metodo_item_id' => $metodo,
        'monto_centimos' => $monto,
        'operacion'      => mb_substr(trim((string)($d['operacion'] ?? '')), 0, 60),
        'fecha'          => $fecha,
                'voucher'        => $d['voucher'] ?? null,
        'tipo'           => 'cobro',
        'verificado'     => $al_instante ? 1 : 0,
        'verificado_por' => $al_instante ? ($_SESSION['usuario_id'] ?? null) : null,
        'verificado_en'  => $al_instante ? date('Y-m-d H:i:s') : null,
        'registrado_por' => $_SESSION['usuario_id'] ?? null,
    ]);

    /* El concepto se escribe aparte y solo si la columna existe: metido en el
       INSERT, un HUB al que le falta el último paso de la actualización no
       podría registrar ningún pago. */
    $concepto = mb_substr(trim((string)($d['concepto'] ?? '')), 0, 80);
    if ($concepto !== '' && columna_existe('pagos', 'concepto')) {
        actualizar('pagos', $id, ['concepto' => $concepto]);
    }
    /* La foto del DNI, igual: aparte y solo si la columna existe. */
    if (!empty($d['foto_dni']) && columna_existe('pagos', 'foto_dni')) {
        actualizar('pagos', $id, ['foto_dni' => (string)$d['foto_dni']]);
    }

    $cashback = 0;
    if ($al_instante) {
        $cashback = cashback_acreditar_pago($id);
        cashback_vencer_pendientes((int)$pedido['cliente_id']);
    }

    pedido_recalcular($pedido_id);
    pedido_avanzar_si_esta_pagado($pedido_id);

    pedido_evento($pedido_id, 'pago',
        'Pago de ' . soles($monto) . ' · ' . lista_texto($metodo)
        . ($concepto !== '' ? ' · ' . $concepto : '')
        . ($al_instante ? '' : ' · queda PENDIENTE de validación'));
    bitacora('pago.registrar', 'pago', $id, ['pedido' => $pedido_id, 'monto' => $monto]);

    return ['ok' => true, 'error' => '', 'id' => $id, 'cashback' => $cashback,
            'pendiente' => !$al_instante];
    }));
}

/* ─────────────────────────  VALIDAR  ───────────────────────── */

/**
 * Administración confirma que el dinero llegó al banco.
 * Recién aquí el pago suma a la meta y nace su cashback, con la fecha del
 * pago —no la de hoy—, para que el vencimiento cuente desde que entró.
 *
 * El N.º de operación se puede completar AQUÍ, y es el mejor momento: ese
 * número solo sirve para cuadrar contra el extracto del banco, y quien valida
 * tiene el extracto delante. El asesor lo copia de una captura de WhatsApp con
 * el cliente esperando, que es justo donde se cuela un dígito cambiado.
 * Si llega vacío no se pisa el que ya hubiera.
 */
/**
 * QUIÉN CONFIRMA: lo decide el permiso `pagos.verificar`, y NO se mira quién
 * registró el pago.
 *
 * «Administración sí, el asesor no» (usuario, 2026-09-14). El asesor no lleva
 * ese permiso, así que no confirma nada —ni lo suyo—, y ahí se queda el control
 * que frena al voucher falso: los 19 asesores. Administración sí puede
 * confirmar un pago que ella misma registró, porque cuando adm vende directo no
 * hay a quién esperar y bloquearlo solo dejaría el dinero parado.
 *
 * Está escrito aquí para que se lea como una DECISIÓN y no como un olvido: la
 * comprobación que no existe es tan deliberada como las que sí. Hay dos pruebas
 * que la sujetan, en correr3.php.
 */
function pago_validar(int $pago_id, string $operacion = ''): array
{
    return con_pagos_frescos(fn() => en_transaccion(function () use ($pago_id, $operacion) {

    /* Siempre el mismo orden en todo el HUB: primero `pedidos`, después
       `pagos`. Dos caminos que reserven al revés se quedan esperándose el uno
       al otro y el servidor mata a uno con un error que nadie entiende.
       Reservada la fila, la segunda pestaña espera y ve el pago YA validado. */
    $pedido_id = (int) valor('SELECT pedido_id FROM pagos WHERE id = ?', [$pago_id]);
    if ($pedido_id) bloquear_fila('pedidos', $pedido_id);
    bloquear_fila('pagos', $pago_id);

    $pg = una('SELECT * FROM pagos WHERE id = ?', [$pago_id]);
    if (!$pg)                       return ['ok' => false, 'error' => 'Ese pago no existe.'];
    if ((int)$pg['anulado'] === 1)  return ['ok' => false, 'error' => 'Ese pago está anulado.'];
    if ((int)$pg['verificado'] === 1) return ['ok' => false, 'error' => 'Ese pago ya estaba validado.'];

    /* No se recorta en silencio: un número de operación cortado no cuadra
       contra el extracto, que es lo único para lo que sirve, y quien lo
       escribió creería que quedó bien guardado. */
    $operacion = trim($operacion);
    if (mb_strlen($operacion) > 60) {
        return ['ok' => false, 'error' => 'El N.º de operación no puede pasar de 60 caracteres. '
                                        . 'El pago sigue sin validar.'];
    }

    /* EL N.º DE OPERACIÓN ES OBLIGATORIO donde existe (usuario, 2026-09-09).
       Sirve para una sola cosa: cuadrar contra el extracto del banco. Sin él,
       el pago queda confirmado y tres semanas después nadie puede demostrar
       CUÁL movimiento del banco era — que es justo el trabajo que esta pantalla
       viene a hacer.
       Se pide en los métodos que piden voucher, que son los mismos que dejan un
       número: un método con foto de operación tiene número. El EFECTIVO no, y
       ahí no se pide: obligarlo terminaría con facturación escribiendo «000» en
       cada venta en efectivo, y entonces el campo dejaría de servir para nada
       en TODAS las demás.
       Y si el pago YA trae un número guardado, no se vuelve a pedir: llegar
       vacío entonces significa «no lo cambies», no «no hay». */
    $ya_tiene = trim((string)($pg['operacion'] ?? '')) !== '';
    if ($operacion === '' && !$ya_tiene && metodo_pide_comprobante($pg['metodo_item_id'] ?? null)) {
        return ['ok' => false, 'error' => 'Falta el N.º de operación. Cópialo del voucher o del '
            . 'extracto antes de confirmar: es lo único que después permite cuadrar este pago '
            . 'con el movimiento del banco.'];
    }

    /* La segunda red, y hace falta: entre registrar y confirmar pueden haber
       entrado otros pagos, o haberse confirmado el otro de los dos que el
       asesor registró por duplicado. Confirmar es lo único que mueve la meta,
       así que es aquí donde no se puede pasar del total. */
    $ped = pedido_de((int)$pg['pedido_id']);
    if ($ped && (string)$pg['tipo'] === 'cobro') {
        $cabe = (int)$ped['total_centimos'] - (int)$ped['cobrado_centimos'];
        if ((int)$pg['monto_centimos'] > $cabe) {
            return ['ok' => false, 'error' =>
                'Confirmar este pago dejaría el pedido cobrado por encima de su total: caben '
                . soles(max(0, $cabe)) . ' y el pago es de ' . soles((int)$pg['monto_centimos'])
                . '. Suele ser el mismo pago registrado dos veces — comprueba el otro y '
                . 'deniega el que sobre.'];
        }
    }

    $cambios = [
        'verificado'     => 1,
        'verificado_por' => $_SESSION['usuario_id'] ?? null,
        'verificado_en'  => date('Y-m-d H:i:s'),
        // Si estaba «en espera», la espera terminó. Dejar la marca puesta haría
        // que el pago saliera confirmado Y esperando a la vez.
        'en_espera'      => 0,
    ];
    if ($operacion !== '') $cambios['operacion'] = $operacion;
    actualizar('pagos', $pago_id, $cambios);
    $cashback = cashback_acreditar_pago($pago_id);
    pedido_recalcular((int)$pg['pedido_id']);
    pedido_avanzar_si_esta_pagado((int)$pg['pedido_id']);

    pedido_evento((int)$pg['pedido_id'], 'pago',
        'Pago de ' . soles((int)$pg['monto_centimos']) . ' validado');
    bitacora('pago.validar', 'pago', $pago_id, ['monto' => (int)$pg['monto_centimos']]);

    return ['ok' => true, 'error' => '', 'cashback' => $cashback];
    }));
}

/* ─────────────────────────  DESHACER  ───────────────────────── */

/**
 * Anula un pago que TODAVÍA NO se validó. Nunca contó para nada, así que se
 * apaga y ya está: no hace falta fila de reversión ni tocar el cashback.
 * Un pago ya validado NO pasa por aquí — para eso está pago_devolver().
 */
function pago_anular(int $pago_id, string $motivo = '', bool $denegado = false): array
{
    /* No se recorta en silencio, igual que el N.º de operación: el motivo es lo
       que va a leer el asesor para entender por qué su venta no cuenta, y uno
       cortado a media palabra no explica nada. */
    $motivo = trim($motivo);
    if (mb_strlen($motivo) > 200) {
        return ['ok' => false, 'error' => 'El motivo no puede pasar de 200 caracteres. '
                                        . 'No se tocó el pago.'];
    }

    return con_pagos_frescos(fn() => en_transaccion(function () use ($pago_id, $motivo, $denegado) {

    $pedido_id = (int) valor('SELECT pedido_id FROM pagos WHERE id = ?', [$pago_id]);
    if ($pedido_id) bloquear_fila('pedidos', $pedido_id);
    bloquear_fila('pagos', $pago_id);
    $pg = una('SELECT * FROM pagos WHERE id = ?', [$pago_id]);
    if (!$pg)                        return ['ok' => false, 'error' => 'Ese pago no existe.'];
    if ((int)$pg['anulado'] === 1)   return ['ok' => false, 'error' => 'Ese pago ya estaba anulado.'];
    if ((int)$pg['verificado'] === 1) {
        return ['ok' => false, 'error' => 'Ese pago ya está validado: se devuelve, no se borra.'];
    }

    actualizar('pagos', $pago_id, [
        'anulado'        => 1,
        'anulado_por'    => $_SESSION['usuario_id'] ?? null,
        'anulado_en'     => date('Y-m-d H:i:s'),
        'anulado_motivo' => $motivo,
        /* La marca de denegado va DENTRO de la transacción. Escrita después y
           fuera, un fallo a mitad dejaba el pago apagado pero sin la etiqueta:
           se perdía justo la distinción por la que existe la columna —«el
           asesor lo quitó» contra «facturación lo rechazó»— y a la persona se
           le decía que había salido bien. */
        'denegado'       => $denegado ? 1 : 0,
        // Denegado o quitado, la espera terminó: una fila apagada y «en espera»
        // a la vez se contradice a sí misma.
        'en_espera'      => 0,
    ]);
    pedido_recalcular((int)$pg['pedido_id']);
    // UN solo evento. Con dos, la bitácora del pedido decía «se quitó» y
    // «facturación denegó» del mismo pago, una debajo de la otra.
    pedido_evento((int)$pg['pedido_id'], 'pago',
        ($denegado ? 'Facturación DENEGÓ el pago de ' : 'Se quitó un pago sin validar de ')
        . soles((int)$pg['monto_centimos']) . ($motivo ? ' · ' . $motivo : ''));
    bitacora($denegado ? 'pago.denegar' : 'pago.anular', 'pago', $pago_id, ['motivo' => $motivo]);
    return ['ok' => true, 'error' => ''];
    }));
}

/**
 * «Lo miré, todavía no está en el banco.»
 *
 * Es el caso del interbancario, del fin de semana y del monto que no cuadra
 * por unos céntimos. No es confirmarlo —no suma a nada— pero tampoco es
 * denegarlo: el dinero probablemente llegue mañana.
 *
 * Existe porque sin ella un pago que facturación YA revisó se ve exactamente
 * igual que uno que nadie ha tocado. Con los pagos de 19 asesores cayendo en
 * la misma bandeja, esa fila deja de leerse en una semana y con ella se dejan
 * de leer las demás.
 *
 * La nota es OBLIGATORIA: «en espera» sin motivo no le dice nada ni al asesor,
 * que quiere saber por qué su venta no suma, ni a la propia facturación cuando
 * vuelva a mirar esa fila el jueves.
 */
function pago_en_espera(int $pago_id, string $nota): array
{
    return con_pagos_frescos(fn() => en_transaccion(function () use ($pago_id, $nota) {

    $nota = trim($nota);
    if ($nota === '')            return ['ok' => false, 'error' => 'Escribe por qué queda en espera. Es lo que verá el asesor.'];
    if (mb_strlen($nota) > 200)  return ['ok' => false, 'error' => 'La nota no puede pasar de 200 caracteres.'];

    // Mismo orden de candados que en todo el HUB: pedidos primero, pagos después.
    $pedido_id = (int) valor('SELECT pedido_id FROM pagos WHERE id = ?', [$pago_id]);
    if ($pedido_id) bloquear_fila('pedidos', $pedido_id);
    bloquear_fila('pagos', $pago_id);

    $pg = una('SELECT * FROM pagos WHERE id = ?', [$pago_id]);
    if (!$pg)                         return ['ok' => false, 'error' => 'Ese pago no existe.'];
    if ((int)$pg['anulado'] === 1)    return ['ok' => false, 'error' => 'Ese pago está anulado.'];
    if ((int)$pg['verificado'] === 1) return ['ok' => false, 'error' => 'Ese pago ya está confirmado.'];

    actualizar('pagos', $pago_id, [
        'en_espera'   => 1,
        'espera_nota' => $nota,
        'espera_por'  => $_SESSION['usuario_id'] ?? null,
        'espera_en'   => date('Y-m-d H:i:s'),
    ]);

    pedido_evento((int)$pg['pedido_id'], 'pago',
        'Pago de ' . soles((int)$pg['monto_centimos']) . ' en espera · ' . $nota);
    bitacora('pago.espera', 'pago', $pago_id, ['nota' => $nota]);
    return ['ok' => true, 'error' => ''];
    }));
}

/**
 * Facturación deniega un pago: el voucher no es bueno.
 *
 * Apaga el pago igual que pago_anular(), y por debajo es lo mismo — pero se
 * anota como DENEGADO, y esa diferencia importa por dos motivos: al asesor hay
 * que avisarle con el motivo (a él no le llegó ningún dinero de vuelta, le
 * están diciendo que su venta no era tal), y en el reporte no es lo mismo un
 * pago que el asesor quitó porque lo tecleó mal que uno que facturación
 * rechazó por falso.
 *
 * EL PEDIDO NO SE ANULA. El cliente puede mandar el voucher bueno en diez
 * minutos, y anular la venta obligaría a volver a registrarla entera. El
 * pedido se queda con su saldo por cobrar, que es la verdad.
 */
function pago_denegar(int $pago_id, string $motivo): array
{
    $motivo = trim($motivo);
    if ($motivo === '') {
        return ['ok' => false, 'error' => 'Escribe por qué se deniega. El asesor va a ver este motivo.'];
    }

    // Es pago_anular() con la etiqueta puesta, y la pone DENTRO de su misma
    // transacción: o queda todo o no queda nada.
    return pago_anular($pago_id, $motivo, true);
}

/**
 * Devuelve un pago YA VALIDADO.
 *
 * No se despinta el original: se mete una fila NEGATIVA con la fecha de HOY.
 * Así la meta del mes en que entró el dinero no cambia sola meses después, y
 * la reversión resta del mes en que de verdad ocurre. Y el cashback se
 * revierte por ese pago y solo por ese.
 */
function pago_devolver(int $pago_id, string $motivo = '', string $clase = 'cliente'): array
{
    /* Dos situaciones distintas con la misma aritmética:
         'cliente' → el dinero se le regresó de verdad al cliente.
         'error'   → nunca hubo tal dinero; se confirmó por equivocación.
       La fila negativa es idéntica —la meta baja el mes en que ocurre y el
       cashback se revierte— pero la pantalla tiene que decir cuál de las dos
       fue, o nadie entiende el movimiento tres semanas después. */
    if (!in_array($clase, ['cliente', 'error'], true)) $clase = 'cliente';

    $motivo = trim($motivo);
    if (mb_strlen($motivo) > 200) {
        return ['ok' => false, 'error' => 'El motivo no puede pasar de 200 caracteres. '
                                        . 'No se tocó el pago.'];
    }

    return con_pagos_frescos(fn() => en_transaccion(function () use ($pago_id, $motivo, $clase) {

    // Mismo orden que en todo el HUB: `pedidos` primero, `pagos` después.
    $pedido_id = (int) valor('SELECT pedido_id FROM pagos WHERE id = ?', [$pago_id]);
    if ($pedido_id) bloquear_fila('pedidos', $pedido_id);
    bloquear_fila('pagos', $pago_id);
    $pg = una('SELECT * FROM pagos WHERE id = ?', [$pago_id]);
    if (!$pg)                          return ['ok' => false, 'error' => 'Ese pago no existe.'];
    if ((int)$pg['anulado'] === 1)     return ['ok' => false, 'error' => 'Ese pago está anulado.'];
    if ((int)$pg['verificado'] !== 1)  return ['ok' => false, 'error' => 'Ese pago no está validado: se quita, no se devuelve.'];
    if ((string)($pg['tipo'] ?? 'cobro') !== 'cobro') {
        return ['ok' => false, 'error' => 'Eso ya es una devolución.'];
    }
    $ya = valor('SELECT id FROM pagos WHERE revierte_pago_id = ? AND anulado = 0', [$pago_id]);
    if ($ya) return ['ok' => false, 'error' => 'Ese pago ya fue devuelto.'];

    insertar('pagos', [
        'pedido_id'        => (int)$pg['pedido_id'],
        'metodo_item_id'   => $pg['metodo_item_id'],
        'monto_centimos'   => -abs((int)$pg['monto_centimos']),
        'operacion'        => $pg['operacion'],
        'fecha'            => date('Y-m-d'),               // el mes de la reversión, no el original
        'tipo'             => 'devolucion',
        'revierte_pago_id' => $pago_id,
        'verificado'       => 1,
        'verificado_por'   => $_SESSION['usuario_id'] ?? null,
        'verificado_en'    => date('Y-m-d H:i:s'),
        'registrado_por'   => $_SESSION['usuario_id'] ?? null,
        'reverso_clase'    => $clase,
    ]);

    cashback_revertir_pago($pago_id, $motivo);
    pedido_recalcular((int)$pg['pedido_id']);
    /* Y el estado. Sin esto el pedido se quedaba en «Pagado» con todo su saldo
       por cobrar — «la mentira más cara del sistema», con esas palabras, en
       estados_siguientes(). */
    pedido_retroceder_si_debe((int)$pg['pedido_id']);

    pedido_evento((int)$pg['pedido_id'], 'pago',
        ($clase === 'error'
            ? 'Se deshizo la confirmación de ' . soles((int)$pg['monto_centimos'])
              . ' · ese dinero no había entrado'
            : 'Devolución al cliente de ' . soles((int)$pg['monto_centimos']))
        . ($motivo ? ' · ' . $motivo : ''));
    bitacora('pago.devolver', 'pago', $pago_id, ['motivo' => $motivo, 'clase' => $clase]);
    return ['ok' => true, 'error' => ''];
    }));
}

/* ─────────────────────────  VOUCHER  ───────────────────────── */

/**
 * Guarda la foto del voucher.
 *
 * Va a uploads/vouchers/, que el .htaccess deja cerrada a cal y canto: un
 * voucher lleva el nombre y el banco del cliente y no puede quedar colgando
 * de una dirección que se adivine. Se sirve por /pagos/voucher, que comprueba
 * antes quién pide.
 * Las imágenes se vuelven a codificar con GD, así que lo que se guarda es una
 * imagen de verdad y no un archivo con cara de imagen.
 */
function guardar_voucher(array $archivo, string $que = 'El voucher'): array
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No se eligió ningún archivo.'];
    }
    /* Pasa del límite del servidor (muchos cPanel traen 2 MB): se dice que
       pesa, no que «no llegó completo» (verificación del 3f). */
    if (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return ['ok' => false, 'error' => $que . ' pesa demasiado para subirla. Toma la foto con TOMAR FOTO o elige una más liviana.'];
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'El archivo no llegó completo. Vuelve a intentarlo.'];
    }
    if (($archivo['size'] ?? 0) > VOUCHER_MAX_BYTES) {
        return ['ok' => false, 'error' => $que . ' pesa más de 5 MB.'];
    }
    if (!is_uploaded_file((string)($archivo['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'El archivo no llegó por el formulario.'];
    }

    $dir = HUB_SUBIDAS . '/vouchers';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    voucher_carpeta_cerrada($dir);
    $base = 'v' . date('Ymd') . '-' . substr(token(8), 0, 12);

    // ¿Es PDF? Se acepta tal cual: mucha gente descarga el comprobante del
    // banco en PDF y obligarle a fotografiar la pantalla sería absurdo.
    $cabeza = (string) @file_get_contents($archivo['tmp_name'], false, null, 0, 5);
    if ($cabeza === '%PDF-') {
        if (!@move_uploaded_file($archivo['tmp_name'], $dir . '/' . $base . '.pdf')) {
            return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
        }
        return ['ok' => true, 'archivo' => $base . '.pdf'];
    }

    $info = @getimagesize($archivo['tmp_name']);
    if (!$info) return ['ok' => false, 'error' => 'Eso no es una imagen ni un PDF.'];
    [$ancho, $alto, $tipo] = $info;
    if (!in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return ['ok' => false, 'error' => $que . ' tiene que ser JPG, PNG o PDF.'];
    }
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'error' => 'No se pudo procesar la imagen. Prueba con otra o avisa a Administración.'];
    }

    $origen = $tipo === IMAGETYPE_JPEG
        ? @imagecreatefromjpeg($archivo['tmp_name'])
        : @imagecreatefrompng($archivo['tmp_name']);
    if (!$origen) return ['ok' => false, 'error' => 'No se pudo leer la imagen.'];

    // Se reduce a 1400 px de lado mayor: una captura de banco se lee perfecto
    // y 19 asesores subiendo fotos de 8 MP llenarían el hosting en un mes.
    $max = 1400;
    $escala = min(1, $max / max($ancho, $alto));
    $na = max(1, (int)round($ancho * $escala));
    $nl = max(1, (int)round($alto  * $escala));

    $destino = imagecreatetruecolor($na, $nl);
    imagefilledrectangle($destino, 0, 0, $na, $nl, imagecolorallocate($destino, 255, 255, 255));
    imagecopyresampled($destino, $origen, 0, 0, 0, 0, $na, $nl, $ancho, $alto);
    /* DERECHA (auditoría del 3f): la foto de la cámara llega de lado con una
       marca que dice cómo girarla; GD la ignora. La MISMA regla que las fotos
       del catálogo (tienda_foto_giro). */
    if ($tipo === IMAGETYPE_JPEG && function_exists('tienda_foto_giro')) {
        $giro = tienda_foto_giro((string) @file_get_contents($archivo['tmp_name']));
        if ($giro) {
            $g = @imagerotate($destino, $giro, 0);
            if ($g) { imagedestroy($destino); $destino = $g; }
        }
    }
    $ok = imagejpeg($destino, $dir . '/' . $base . '.jpg', 84);
    imagedestroy($destino);
    imagedestroy($origen);

    if (!$ok) return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
    return ['ok' => true, 'archivo' => $base . '.jpg'];
}

/* ─────────────  EL ESTADO DE UN PAGO, EN COLOR  ─────────────
 *
 * «visualmente todo está muy plano y parece lo mismo» (usuario, 2026-09-09).
 *
 * Y estaba escrito TRES veces —la ficha, el reporte y la bandeja— con cadenas
 * ligeramente distintas y colores que no coincidían. Es el patrón de siempre:
 * si algo sale en dos sitios, sale de UNA función.
 *
 * La escala, y por qué esta y no otra:
 *   · Sin revisar → NEUTRO. Es el estado normal de todo pago recién nacido; si
 *     fuera ámbar, cada venta parecería una alarma y el color dejaría de
 *     significar nada. (Antes era ámbar, y «En espera» era el neutro: justo al
 *     revés de lo que quieren decir.)
 *   · En espera → ÁMBAR. Alguien ya lo miró y lo dejó parado: hay trabajo.
 *   · Validado → VERDE. El dinero cuenta. (La palabra es la que ya usan
 *     el botón, el filtro del reporte y la bandeja: cambiarla solo aquí
 *     habría dejado la misma fila con dos nombres según la pantalla.)
 *   · Denegado / devuelto → ROJO. No cuenta.
 *   · Quitado / deshecho → GRIS. Dejó de existir, sin culpa de nadie.
 *
 * El amarillo de marca NO aparece: tiene 1.36:1 de contraste y se reserva para
 * botones y selección. Ver [[paleta-datos]].
 *
 * Y nunca color a secas: cada chip lleva su PALABRA, porque un daltónico ve
 * tres chips iguales si el color es lo único que los separa.
 *
 * Devuelve ['clase', 'texto', 'icono', 'nota'].
 */
function pago_chip(array $pg): array
{
    $anulado   = (int)($pg['anulado'] ?? 0) === 1;
    $denegado  = (int)($pg['denegado'] ?? 0) === 1;
    $devolucion= (string)($pg['tipo'] ?? 'cobro') === 'devolucion';
    $motivo    = trim((string)($pg['anulado_motivo'] ?? ''));

    if ($denegado) {
        return ['chip--rojo', 'Denegado', 'alerta', $motivo];
    }
    if ($anulado) {
        return ['chip--gris', 'Quitado', '', $motivo];
    }
    if ($devolucion) {
        return (string)($pg['reverso_clase'] ?? '') === 'error'
            ? ['chip--gris', 'Confirmación deshecha', '', 'ese dinero nunca entró']
            : ['chip--rojo', 'Devuelto al cliente', '', ''];
    }
    if ((int)($pg['verificado'] ?? 0) === 1) {
        return ['chip--verde', 'Validado', 'check', ''];
    }
    if ((int)($pg['en_espera'] ?? 0) === 1) {
        return ['chip--ambar', 'En espera', 'reloj', trim((string)($pg['espera_nota'] ?? ''))];
    }
    return ['chip--gris', 'Sin revisar', '', ''];
}

/* ───────────  LOS PAGOS TRABADOS · EL AVISO AL ASESOR  ───────────
 *
 * «cuando se coloque en espera o denegado también debería ser notificado el
 * asesor» (usuario, 2026-09-09).
 *
 * Es el mismo hueco que la auditoría 18 encontró con el despacho: se construyó
 * la regla que FRENA —el pago no cuenta hasta que alguien lo confirme— sin
 * construir a quien AVISA. Peor todavía, porque la auditoría 14 ya lo había
 * anotado (un texto prometía que «el asesor recibirá el motivo» y no existía
 * tal aviso) y se quedó escrito sin hacer.
 *
 * Un pago confirmado NO entra aquí: ese ya lo avisa «Por despachar», y dos
 * contadores del mismo hecho es exactamente el patrón que este proyecto ha
 * producido seis veces.
 *
 * UNA SOLA DEFINICIÓN de qué es un pago trabado, y de ella salen el chip, la
 * lista y el aviso en vivo.
 */
/* OJO CON `anulado`: denegar es `pago_anular($id, $motivo, true)`, así que un
   pago denegado SIEMPRE lleva `anulado = 1`. Escrito como
   «anulado = 0 AND (en_espera = 1 OR denegado = 1)» la segunda rama era
   inalcanzable y el asesor no se enteraba nunca de una denegación —que es
   justo el caso urgente, el que hay que rehacer—. Lo pilló la auditoría del
   parche, no las pruebas: solo probaban «en espera».

   Y un denegado no se queda ahí para siempre: deja de ser tarea EN CUANTO EL
   ASESOR REGISTRA OTRO PAGO en ese pedido, que es exactamente lo que se le
   está pidiendo que haga. El primer intento cortaba por saldo, y eso tenía dos
   caras malas: una pre venta con saldo abierto por diseño dejaba la denegación
   clavada meses, y deshacer hoy una confirmación de otro pago RESUCITABA una
   denegación de hace tres meses como si fuera noticia nueva. Lo pilló la
   segunda auditoría. */
const PAGO_TRABADO = "(pg.tipo = 'cobro' AND ("
                   . "     (pg.anulado = 0 AND pg.en_espera = 1)"
                   . "  OR (pg.denegado = 1 AND NOT EXISTS ("
                   . "        SELECT 1 FROM pagos px"
                   . "         WHERE px.pedido_id = pg.pedido_id"
                   . "           AND px.id > pg.id"
                   . "           AND px.anulado = 0"
                   . "           AND px.tipo = 'cobro'))"
                   . "))";

/**
 * Cuántos pagos suyos están trabados y cuál es el de id más alto.
 * `ultimo` es una MARCA DE AGUA para el sondeo, no un contador.
 */
function pagos_trabados_resumen(?array $u = null, bool $olvidar = false): array
{
    static $cache = [];
    $vacio = ['n' => 0, 'ultimo' => 0];
    if ($olvidar) { $cache = []; return $vacio; }

    $u ??= yo();
    if (!$u) return $vacio;
    $llave = (int)$u['id'];
    if (isset($cache[$llave])) return $cache[$llave];

    /* Lo mismo que arriba, y por el mismo motivo: esto corre en el marco de
       CADA página del asesor. Sin `denegado` ni `en_espera` no hay nada
       trabado que contar, y contestar cero deja el HUB funcionando mientras
       alguien pasa actualizar.php. */
    if (!tabla_existe('pagos') || !columna_existe('pagos', 'denegado')
        || !columna_existe('pagos', 'en_espera') || !columna_existe('pagos', 'tipo')) return $vacio;

    [$ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);

    $f = una(
        'SELECT COUNT(*) AS n, COALESCE(MAX(pg.id), 0) AS ultimo
           FROM pagos pg
           JOIN pedidos pe ON pe.id = pg.pedido_id
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE pe.anulado_en IS NULL
            AND ' . PAGO_TRABADO . '
            AND ' . $ambito, $par);

    return $cache[$llave] = [
        'n'      => (int)($f['n'] ?? 0),
        'ultimo' => (int)($f['ultimo'] ?? 0),
    ];
}

function pagos_trabados_olvidar(): void
{
    pagos_trabados_resumen(null, true);
}

/**
 * ¿A quién se le avisa? Al que registra ventas y tiene que arreglarlo.
 *
 * A Facturación NO: es quien los trabó, y un contador de su propio trabajo ya
 * hecho no es un aviso. A Dirección tampoco. Misma decisión que con el chip de
 * despacho y con el de pagos por validar: **un contador rojo que no es tarea
 * de uno se deja de mirar en tres días, y con él se dejan de mirar los demás.**
 */
function le_avisamos_de_trabados(?array $u = null): bool
{
    $u ??= yo();
    if (!$u) return false;
    if (puede_el($u, 'pagos.verificar')) return false;   // él los trabó
    return in_array((string)$u['rol'], ['asesor', 'desarrollador'], true);
}

/** Los pagos trabados, para pintarlos con su motivo. */
function pagos_trabados_lista(?array $u = null, int $tope = 50): array
{
    $u ??= yo();
    if (!$u) return [];
    [$ambito, $par] = filtro_ambito('pe.asesor_id', 'pe.pais_id', 'ua.equipo_id', $u);

    return todas(
        'SELECT pg.id, pg.monto_centimos, pg.fecha, pg.denegado, pg.en_espera,
                pg.espera_nota, pg.espera_en, pg.anulado_motivo,
                pe.id AS pedido_id, pe.codigo, pe.pais_id,
                c.nombre, c.apellidos
           FROM pagos pg
           JOIN pedidos pe ON pe.id = pg.pedido_id
           LEFT JOIN clientes c ON c.id = pe.cliente_id
           LEFT JOIN usuarios ua ON ua.id = pe.asesor_id
          WHERE pe.anulado_en IS NULL
            AND ' . PAGO_TRABADO . '
            AND ' . $ambito . '
          ORDER BY pg.id DESC
          LIMIT ' . max(1, $tope), $par);
}

/* ─────────────  EL VOUCHER SOBREVIVE AL REBOTE  ─────────────
 *
 * Un <input type=file> NO se puede volver a llenar: el navegador no lo
 * permite, por seguridad. Así que cuando el formulario rebota por cualquier
 * otro error —falta el distrito, el monto no se entiende— el archivo
 * desaparece SIN DECIRLO y el asesor cree que lo mandó. Lo reportó el usuario
 * probando, y es el mismo fallo que la auditoría 9 encontró con el monto del
 * pago: un formulario que rebota no puede perder nada de lo que la persona ya
 * puso.
 *
 * La solución: guardar el archivo ANTES de validar nada, y acordarse de él
 * EN LA SESIÓN. El nombre del archivo nunca viaja al navegador — si viajara,
 * bastaría con mandar el nombre del voucher de otro para colgárselo a un pago
 * propio, y `ruta_voucher()` comprueba la forma del nombre, no de quién es.
 */

/** Cuánto vive un voucher a medio subir. Dos horas: más que cualquier
 *  formulario razonable y menos que una sesión olvidada. */
const VOUCHER_PENDIENTE_SEGUNDOS = 7200;

/** El voucher que quedó a medio camino, o null. */
function voucher_pendiente(?string $contexto = null): ?array
{
    foreach (vouchers_pendientes() as $v) {
        /* De otra persona no, aunque la sesión sobreviva a un cambio de cuenta. */
        if ((int)($v['usuario'] ?? 0) !== (int)($_SESSION['usuario_id'] ?? 0)) continue;
        /* Y de otra pantalla tampoco: un voucher es de UN pago. */
        if ($contexto !== null && (string)($v['ctx'] ?? '') !== $contexto) continue;
        return $v;
    }
    return null;
}

/**
 * LOS VOUCHERS EN ESPERA, uno por pantalla, ya limpios de caducados.
 *
 * Había UN SOLO HUECO en la sesión, y eso se rompió el día que cada formulario
 * de venta empezó a tener su propio identificador: con dos pestañas abiertas,
 * subir el voucher en la segunda borraba el archivo de la primera y esa venta
 * se guardaba sin comprobante sin un solo aviso —con efectivo, ni error—.
 * Ahora hay uno por contexto, con tope: un asesor no tiene cuarenta pestañas,
 * y sin tope la sesión crecería sin final.
 */
const VOUCHERS_PENDIENTES_MAX = 12;   // dos por pantalla desde la 3f: el voucher y la foto del DNI

function vouchers_pendientes(): array
{
    $todos = $_SESSION['voucher_pend'] ?? [];
    /* Compatibilidad con la sesión vieja, que guardaba UNO suelto: quien tenga
       una abierta cuando se suba esta versión no pierde su archivo. */
    if (is_array($todos) && isset($todos['archivo'])) $todos = [(string)($todos['ctx'] ?? '') => $todos];
    if (!is_array($todos)) $todos = [];

    $vivos = [];
    foreach ($todos as $ctx => $v) {
        if (!is_array($v) || empty($v['archivo'])) continue;
        if (time() - (int)($v['en'] ?? 0) > VOUCHER_PENDIENTE_SEGUNDOS) {
            $ruta = ruta_voucher((string)$v['archivo']);
            if ($ruta !== '' && is_file($ruta)) @unlink($ruta);
            continue;
        }
        $vivos[(string)$ctx] = $v;
    }
    $_SESSION['voucher_pend'] = $vivos;
    return $vivos;
}

/**
 * Lo tira, y borra el archivo: si no se va a usar, no se queda ocupando.
 *
 * Con `$contexto`, solo si el pendiente es DE ESA PANTALLA. Sin esa
 * comprobación, guardar un pedido nuevo sin pago borraba el voucher que estaba
 * esperando en la ficha de otro pedido: pérdida silenciosa, justo lo que este
 * aparato existe para impedir. Lo pilló la segunda auditoría.
 * Sin contexto (`null`) borra lo que haya: es lo que quiere `salir()`.
 */
function voucher_pendiente_olvidar(?string $contexto = null): void
{
    $vivos = vouchers_pendientes();
    foreach ($vivos as $ctx => $v) {
        if ($contexto !== null && (string)$ctx !== $contexto) continue;   // no es suyo
        $ruta = ruta_voucher((string)$v['archivo']);
        if ($ruta !== '' && is_file($ruta)) @unlink($ruta);
        unset($vivos[$ctx]);
    }
    $_SESSION['voucher_pend'] = $vivos;
}

/**
 * Guarda el archivo que acaba de llegar y lo deja en espera.
 * Devuelve lo mismo que guardar_voucher().
 */
function voucher_pendiente_guardar(array $archivo, string $contexto): array
{
    /* Lo alistado es una FOTO de la caja, no un PDF (auditoría del 3g). */
    /* Y la de la falla de una garantía (3i), igual: una foto. */
    $es_falla = (bool) preg_match('~#falla_\d+$~', $contexto);
    if ((str_ends_with($contexto, '#foto_alistado') || $es_falla) && is_file((string)($archivo['tmp_name'] ?? ''))
        && (string) @file_get_contents((string)$archivo['tmp_name'], false, null, 0, 5) === '%PDF-') {
        return ['ok' => false, 'error' => $es_falla ? 'Tiene que ser una foto de la falla, no un PDF.' : 'Tiene que ser una foto de lo alistado, no un PDF.'];
    }
    $r = guardar_voucher($archivo, str_ends_with($contexto, '#foto_dni') ? 'La foto del DNI'
                                 : (str_ends_with($contexto, '#foto_alistado') || str_ends_with($contexto, '#foto_entrega') || $es_falla ? 'La foto' : 'El voucher'));
    if (!$r['ok']) return $r;

    /* El de ESTA pantalla se reemplaza; el de las demás se queda donde está. */
    voucher_pendiente_olvidar($contexto);
    $vivos = vouchers_pendientes();
    $vivos[$contexto] = [
        'archivo' => $r['archivo'],
        'nombre'  => mb_substr((string)($archivo['name'] ?? 'voucher'), 0, 60),
        'ctx'     => $contexto,
        'usuario' => (int)($_SESSION['usuario_id'] ?? 0),
        'en'      => time(),
    ];
    /* Con tope, y tirando los más viejos: sin él la sesión crecería sin final
       con un asesor que abre y abandona formularios todo el día. */
    while (count($vivos) > VOUCHERS_PENDIENTES_MAX) {
        $viejo = array_key_first($vivos);
        $ruta  = ruta_voucher((string)$vivos[$viejo]['archivo']);
        if ($ruta !== '' && is_file($ruta)) @unlink($ruta);
        unset($vivos[$viejo]);
    }
    $_SESSION['voucher_pend'] = $vivos;
    return $r;
}

/**
 * El voucher que hay que usar en ESTE envío, mirando el formulario entero.
 *
 * Es la única definición: los dos sitios que suben voucher —el pedido nuevo y
 * el pago desde la ficha— la llaman igual. Escrita dos veces, uno de los dos
 * se quedaría sin arreglar la próxima vez que se toque.
 *
 * Devuelve ['ok'=>bool, 'archivo'=>?string, 'error'=>?string].
 */
function voucher_del_formulario(string $contexto, string $campo = 'voucher'): array
{
    /* LA FOTO DEL DNI (3f) usa el mismo aparato con su propio campo y su
       propio hueco en espera: `contexto#foto_dni`. */
    $contexto = voucher_contexto($contexto, $campo);
    /* Marcó «quitarlo»: manda su decisión por encima de todo. */
    if (pedir($campo . '_quitar') === '1') {
        voucher_pendiente_olvidar($contexto);
        return ['ok' => true, 'archivo' => null];
    }

    /* Llegó uno nuevo: reemplaza al que hubiera. Puede venir por el botón de
       la cámara (`campo_cam`, abre la cámara del celular) o por el de elegir
       archivo: son el mismo archivo por dos puertas. */
    foreach ([$campo . '_cam', $campo] as $f) {
        if (empty($_FILES[$f]['name'])) continue;
        $r = voucher_pendiente_guardar($_FILES[$f], $contexto);
        if (!$r['ok']) return ['ok' => false, 'archivo' => null, 'error' => $r['error']];
        return ['ok' => true, 'archivo' => $r['archivo']];
    }

    /* No llegó ninguno, pero quedaba uno del intento anterior — Y ERA DE ESTA
       MISMA PANTALLA. Sin comprobar el contexto, el voucher que quedó a medias
       en el pedido del cliente A se colgaba solo del pago del cliente B: el
       asesor abandonaba un formulario, abría otro, registraba un efectivo sin
       adjuntar nada y facturación acababa validando dinero de B mirando el
       comprobante bancario de A. Lo pilló la auditoría del parche. */
    $v = voucher_pendiente($contexto);
    return ['ok' => true, 'archivo' => $v ? (string)$v['archivo'] : null];
}

/** El hueco en espera de cada archivo de un formulario: el voucher usa el
 *  contexto tal cual (como siempre); la foto del DNI, el suyo al lado. */
function voucher_contexto(string $contexto, string $campo = 'voucher'): string
{
    return $campo === 'voucher' ? $contexto : $contexto . '#' . $campo;
}

/** Se guardó el pago: el voucher ya tiene dueño y deja de estar en espera.
 *  OJO: no borra el archivo, solo suelta la reserva. */
function voucher_pendiente_confirmar(?string $contexto = null): void
{
    /* SOLO EL DE SU PANTALLA. Borraba la sesión entera, así que guardar la
       venta de una pestaña dejaba a las otras sin su voucher — y la siguiente
       se guardaba sin comprobante y, con efectivo, sin un solo aviso.
       El archivo NO se borra del disco: ya es de un pago. */
    $vivos = vouchers_pendientes();
    foreach ($vivos as $ctx => $v) {
        if ($contexto !== null && (string)$ctx !== $contexto) continue;
        unset($vivos[$ctx]);
    }
    $_SESSION['voucher_pend'] = $vivos;
}

/**
 * Deja la carpeta de vouchers cerrada al web, y la deja cerrada SIEMPRE.
 *
 * El .htaccess faltaba: la carpeta la crea `mkdir` en la primera subida y
 * nadie escribía la protección. El comentario del código daba por hecho que
 * estaba, y la prueba que lo comprobaba medía el router del banco de pruebas
 * —que devuelve 403 por su cuenta— y no el HUB de verdad. Una prueba que mide
 * el arnés en vez del producto es peor que no tenerla: dice que sí.
 *
 * Se comprueba en cada subida, no solo al crear la carpeta, porque un HUB ya
 * instalado ya tiene la carpeta hecha y sin protección.
 */
function voucher_carpeta_cerrada(string $dir): void
{
    $f = $dir . '/.htaccess';
    if (is_file($f)) return;
    @file_put_contents($f, "# Los vouchers NO se sirven por su dirección: se piden por /pagos/voucher,\n"
        . "# que comprueba antes quién está pidiendo.\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}

/** Ruta en disco de un voucher, comprobando que el nombre sea de los nuestros. */
function ruta_voucher(string $archivo): string
{
    if (!preg_match('/^v\d{8}-[a-f0-9]{12}\.(jpg|pdf)$/', $archivo)) return '';
    $ruta = HUB_SUBIDAS . '/vouchers/' . $archivo;
    return is_file($ruta) ? $ruta : '';
}
