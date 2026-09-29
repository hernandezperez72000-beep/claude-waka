<?php
declare(strict_types=1);

/**
 * El menú se arma por rol. Regla del proyecto: si una pantalla no cuelga de
 * una sección del menú, no existe.
 *
 * Cada entrada: clave, texto, ruta, icono, permiso, barra (si va en la barra
 * móvil), solo_lectura (chip "Solo miras") y `corto`, el rótulo que usa SOLO
 * la barra de abajo del celular cuando el largo no cabe —«Pagos por validar»
 * partía en dos renglones y empujaba la barra entera—. El nombre de una
 * sección se decide aquí y en ningún otro sitio.
 */
function menu_de(?array $u = null): array
{
    $u ??= yo();
    if (!$u) return [];

    $rol = $u['rol'];

    /* ── Asesor: cuatro pestañas y «Más» ──────────────────────
       Abajo va lo de TODOS LOS DÍAS, y el orden lo eligió el usuario el
       2026-09-14: Inicio · Pedidos · Por despachar · Bonos. Clientes, Stock y
       Recepción se van a «Más» — al cliente se le busca y se le da de alta
       DENTRO del pedido, así que esa pestaña se usaba poco.
       El ORDEN DEL ARREGLO es el del menú lateral del escritorio, que no
       cambia; la barra del celular se elige con la bandera `barra`, no con la
       posición. Escribirlo al revés reordenaba el lateral de rebote y a nadie
       se le había preguntado por eso.
       «Por despachar» baja a la barra porque es la única sección con una
       tarea que aparece sola: facturación confirma un pago y la venta queda
       libre sin que nadie se lo diga al asesor. Antes no estaba en la barra y
       el asesor NO TENÍA NINGUNA FORMA de llegar a ella desde el celular
       —tampoco tenía «Más»—, justo la sección que lleva contador. */
    $asesor = [
        ['clave'=>'inicio',    'texto'=>'Inicio',        'ruta'=>'/inicio',    'icono'=>'casa',      'barra'=>true],
        ['clave'=>'clientes',  'texto'=>'Clientes',      'ruta'=>'/clientes',  'icono'=>'personas'],
        ['clave'=>'pedidos',   'texto'=>'Pedidos',       'ruta'=>'/pedidos',   'icono'=>'caja',      'barra'=>true],
        ['clave'=>'despacho',  'texto'=>'Por despachar', 'corto'=>'Despacho',
         'ruta'=>'/pedidos/por-despachar', 'icono'=>'camion', 'barra'=>true],
        ['clave'=>'stock',     'texto'=>'Stock',         'ruta'=>'/stock',     'icono'=>'cubo'],
        /* 3h (usuario, 2026-09-27): la pre venta va en la barra en lugar de
           Bonos. 5a (usuario, 2026-09-29): Bonos se queda en «Más». */
        ['clave'=>'preventa',  'texto'=>'Pre venta',     'ruta'=>'/preventa',  'icono'=>'barco',     'barra'=>true],
        ['clave'=>'bonos',     'texto'=>'Bonos',         'ruta'=>'/bonos',     'icono'=>'medalla'],
        /* 3i: las garantías que pidió (se piden desde el pedido). */
        ['clave'=>'garantias', 'texto'=>'Garantías',     'ruta'=>'/garantias', 'icono'=>'escudo'],
        ['clave'=>'recepcion', 'texto'=>'Recepción',     'ruta'=>'/recepcion', 'icono'=>'puerta'],
    ];

    // ── Facturación: confirma el dinero. No vende. ────────────
    $facturacion = [
        ['clave'=>'inicio',   'texto'=>'Inicio',           'ruta'=>'/inicio',            'icono'=>'casa',    'barra'=>true],
        ['clave'=>'pagos',    'texto'=>'Pagos por validar','corto'=>'Pagos','ruta'=>'/pagos/por-validar', 'icono'=>'tarjeta', 'barra'=>true],
        ['clave'=>'pedidos',  'texto'=>'Pedidos',          'ruta'=>'/pedidos',           'icono'=>'caja',    'barra'=>true, 'solo_lectura'=>true],
        ['clave'=>'clientes', 'texto'=>'Clientes',         'ruta'=>'/clientes',          'icono'=>'personas','barra'=>true, 'solo_lectura'=>true],
        /* 3i (usuario, 2026-09-28): Reportes pasa a «Más». Con cinco pestañas
           justas Facturación no tenía «Más», y en el celular no podía cerrar
           sesión ni cambiar su contraseña. */
        ['clave'=>'reportes', 'texto'=>'Reportes',         'ruta'=>'/reportes',          'icono'=>'barras'],
    ];

    // ── Administración: todo lo funcional de su país ──────────
    $admin = [
        ['clave'=>'inicio',    'texto'=>'Inicio',          'ruta'=>'/inicio',    'icono'=>'casa',     'barra'=>true],
        ['clave'=>'clientes',  'texto'=>'Clientes',        'ruta'=>'/clientes',  'icono'=>'personas'],
        ['clave'=>'pedidos',   'texto'=>'Pedidos',         'ruta'=>'/pedidos',   'icono'=>'caja',     'barra'=>true],
        ['clave'=>'pagos',     'texto'=>'Pagos por validar','ruta'=>'/pagos/por-validar','icono'=>'tarjeta'],
        ['clave'=>'despacho',  'texto'=>'Por despachar',   'ruta'=>'/pedidos/por-despachar','icono'=>'camion'],
        /* Administración también alista, de respaldo (3g). */
        ['clave'=>'alistar',   'texto'=>'Por alistar',     'ruta'=>'/pedidos/por-alistar','icono'=>'caja'],
        ['clave'=>'entregar',  'texto'=>'Por entregar',    'ruta'=>'/pedidos/por-entregar','icono'=>'camion'],
        ['clave'=>'stock',     'texto'=>'Stock y pre venta','corto'=>'Stock','ruta'=>'/stock',    'icono'=>'cubo',     'barra'=>true],
        /* 3i: las garantías que esperan su aprobación. */
        ['clave'=>'garantias', 'texto'=>'Garantías',       'ruta'=>'/garantias', 'icono'=>'escudo'],
        ['clave'=>'bonos',     'texto'=>'Bonos',           'ruta'=>'/bonos',     'icono'=>'medalla'],
        ['clave'=>'recepcion', 'texto'=>'Recepción',       'ruta'=>'/recepcion', 'icono'=>'puerta'],
        ['clave'=>'cashback',  'texto'=>'Cashback Waka',   'ruta'=>'/cashback',  'icono'=>'tarjeta'],
        ['clave'=>'reportes',  'texto'=>'Reportes',        'ruta'=>'/reportes',  'icono'=>'barras',   'barra'=>true],
        ['clave'=>'config',    'texto'=>'Configuración',   'ruta'=>'/configuracion','icono'=>'rueda'],
    ];

    // ── Dirección: nueve secciones. Ve todo, toca poco ────────
    $direccion = [
        ['clave'=>'inicio',    'texto'=>'Inicio',          'ruta'=>'/inicio',    'icono'=>'casa',     'barra'=>true],
        ['clave'=>'clientes',  'texto'=>'Clientes',        'ruta'=>'/clientes',  'icono'=>'personas', 'solo_lectura'=>true],
        ['clave'=>'pedidos',   'texto'=>'Pedidos',         'ruta'=>'/pedidos',   'icono'=>'caja',     'solo_lectura'=>true],
        ['clave'=>'stock',     'texto'=>'Stock',           'ruta'=>'/stock',     'icono'=>'cubo',     'barra'=>true],
        /* 3h.1 (usuario, 2026-09-28): el CEO llena los lotes, así que la pre
           venta es una sección suya. En la barra, en lugar de Bonos, que se
           queda en «Más» (5a, usuario, 2026-09-29), como con el asesor. */
        ['clave'=>'lotes',     'texto'=>'Pre venta',       'ruta'=>'/stock/lotes','icono'=>'barco',   'barra'=>true],
        ['clave'=>'garantias', 'texto'=>'Garantías',       'ruta'=>'/garantias', 'icono'=>'escudo', 'solo_lectura'=>true],
        ['clave'=>'bonos',     'texto'=>'Bonos',           'ruta'=>'/bonos',     'icono'=>'medalla'],
        ['clave'=>'recepcion', 'texto'=>'Recepción',       'ruta'=>'/recepcion', 'icono'=>'puerta'],
        ['clave'=>'cashback',  'texto'=>'Cashback Waka',   'ruta'=>'/cashback',  'icono'=>'tarjeta'],
        ['clave'=>'reportes',  'texto'=>'Reportes',        'ruta'=>'/reportes',  'icono'=>'barras',   'barra'=>true],
        ['clave'=>'config',    'texto'=>'Configuración',   'ruta'=>'/configuracion','icono'=>'rueda'],
    ];

    /* ── Almacén (3g): lo que hay que alistar y el stock ──────
       (usuario, 2026-09-26: solo «Por alistar», no «Por despachar»). */
    $almacen = [
        ['clave'=>'inicio',    'texto'=>'Inicio',          'ruta'=>'/inicio',    'icono'=>'casa',     'barra'=>true],
        ['clave'=>'alistar',   'texto'=>'Por alistar',     'corto'=>'Alistar',
         'ruta'=>'/pedidos/por-alistar', 'icono'=>'caja', 'barra'=>true],
        /* 5b (usuario, 2026-09-29): lo ya alistado, en su propia opción. */
        ['clave'=>'entregar',  'texto'=>'Por entregar',    'corto'=>'Entregar',
         'ruta'=>'/pedidos/por-entregar', 'icono'=>'camion', 'barra'=>true],
        ['clave'=>'stock',     'texto'=>'Stock y pre venta','corto'=>'Stock','ruta'=>'/stock', 'icono'=>'cubo', 'barra'=>true],
    ];

    /* ── Marketing (3g): los datos de la tienda ───────────────
       El catálogo (con «Traer de la tienda» y los precios) y los clientes,
       estos en solo mirar. */
    $marketing = [
        ['clave'=>'inicio',    'texto'=>'Inicio',          'ruta'=>'/inicio',    'icono'=>'casa',     'barra'=>true],
        ['clave'=>'stock',     'texto'=>'Stock y pre venta','corto'=>'Stock','ruta'=>'/stock', 'icono'=>'cubo', 'barra'=>true],
        ['clave'=>'clientes',  'texto'=>'Clientes',        'ruta'=>'/clientes',  'icono'=>'personas', 'barra'=>true, 'solo_lectura'=>true],
    ];

    $menu = match ($rol) {
        'asesor'         => $asesor,
        'facturacion'    => $facturacion,
        'administracion' => $admin,
        'direccion'      => $direccion,
        'almacen'        => $almacen,
        'marketing'      => $marketing,
        default          => $admin,     // desarrollador
    };

    /* Dirección también puede confirmar un pago, pero la sección NO le sale en
       el menú: entra por la ficha del pedido cuando quiere. Es la otra mitad
       de la decisión de no avisarle — si no, tendría un contador rojo
       permanente que no piensa vaciar. */

    // Recepción tiene su propia gente: si el usuario es asesor pero su oficina
    // lo marcó como recepción, la sección sigue siendo la misma.
    $con_lotes = in_array('lotes', array_column($menu, 'clave'), true);
    foreach ($menu as &$m) {
        $m['activo'] = seccion_activa() === $m['clave'];
        $m['url']    = url($m['ruta']);
        $m['contador'] = contador_de($m['clave']);
        /* Con su sección de pre venta, los lotes sin precio se cuentan allí
           y no dos veces (también en Stock). */
        if ($con_lotes && $m['clave'] === 'stock') $m['contador'] = max(0, $m['contador'] - contador_de('lotes'));
    }
    return $menu;
}

/** La sección de las pantallas de lotes: «lotes» para Dirección (la única con
    esa entrada en menu_de()), Stock para los demás. */
function seccion_de_lotes(?array $u = null): string
{
    $u ??= yo();
    return ($u['rol'] ?? '') === 'direccion' ? 'lotes' : 'stock';
}

/** Las cinco pestañas de la barra móvil, con "Más" al final si sobra sitio. */
function barra_movil(?array $u = null): array
{
    $u ??= yo();
    $menu = menu_de($u);

    $en_barra = array_values(array_filter($menu, fn($m) => !empty($m['barra'])));

    /* Cinco secciones justas y ninguna fuera (Facturación): no hay sitio
       para «Más». Ver barra_sin_mas(). */
    if (barra_sin_mas($menu)) return $en_barra;

    /* LO QUE NO CABE SE VA A «MÁS», NO AL VACÍO. `array_slice` cortaba a cuatro
       y `menu_mas()` solo recogía lo que NUNCA tuvo bandera: una sección que se
       cayera del corte desaparecía del celular entera, sin dejar rastro. Hoy no
       muerde —todos los roles tienen cuatro justas—, pero el asesor quedó
       exactamente en el límite y el día que se añada una sección el fallo es
       mudo (auditoría, 2026-09-14). Por eso el reparto se hace UNA vez, aquí,
       y `menu_mas()` pregunta por él. */
    [$en_barra, $resto] = barra_reparto($menu);
    $pendientes = 0;
    foreach ($resto as $r) $pendientes += (int)($r['contador'] ?? 0);

    $en_barra[] = [
        'clave' => 'mas', 'texto' => 'Más', 'ruta' => '/mas', 'icono' => 'puntos',
        'url' => url('/mas'), 'activo' => seccion_activa() === 'mas',
        'contador' => $pendientes,
    ];
    return $en_barra;
}

/**
 * El reparto: qué va en la barra de abajo y qué se queda para «Más».
 *
 * Una sola función para las dos mitades, para que nada pueda caerse entre las
 * dos. Devuelve [barra, resto].
 */
function barra_reparto(array $menu): array
{
    $con = array_values(array_filter($menu, fn($m) => !empty($m['barra'])));
    $sin = array_values(array_filter($menu, fn($m) => empty($m['barra'])));

    $barra = array_slice($con, 0, 4);
    /* Las que llevaban bandera pero no cupieron NO se pierden: se van a «Más»,
       delante de las que nunca la tuvieron. */
    $resto = array_merge(array_slice($con, 4), $sin);
    return [$barra, $resto];
}

/**
 * ¿La barra va sin «Más»? Solo si las CINCO pestañas son secciones y no sobra
 * ninguna (Facturación). Con menos, «Más» se queda: además de secciones lleva
 * Mi perfil, la contraseña y Cerrar sesión, que en el celular no están en
 * ningún otro sitio (auditoría del 3g: Almacén y Marketing no podían salir).
 */
function barra_sin_mas(array $menu): bool
{
    $con = count(array_filter($menu, fn($m) => !empty($m['barra'])));
    return $con === count($menu) && $con === 5;
}

/** Lo que queda fuera de la barra, para la pantalla "Más". */
function menu_mas(?array $u = null): array
{
    $u ??= yo();
    $menu = menu_de($u);
    /* Sin «Más» (ver barra_movil) no queda nada. */
    if (barra_sin_mas($menu)) return [];
    return barra_reparto($menu)[1];
}

/** Sección activa: la fija cada controlador con seccion_activa('clientes'). */
function seccion_activa(?string $poner = null): string
{
    static $actual = 'inicio';
    if ($poner !== null) $actual = $poner;
    return $actual;
}

/**
 * Contadores de pendientes del menú. Solo cuentan para quien puede resolverlos.
 * Se calculan de una vez para no disparar una consulta por entrada.
 */
function contadores(bool $olvidar = false): array
{
    static $c = null;
    if ($olvidar) { $c = null; return []; }
    if ($c !== null) return $c;

    $u = yo();
    if (!$u) return $c = [];

    $c = ['recepcion' => 0, 'config' => 0, 'stock' => 0, 'pagos' => 0, 'despacho' => 0, 'alistar' => 0, 'entregar' => 0, 'garantias' => 0];

    try {
        /* Visitas esperando en las oficinas de su país. Por PERMISO como todo
           lo demás: iba sin comprobar nada, y como la bandeja del panel se fía
           de que «lo que aquí vale cero no existe», a Facturación le salía el
           chip «1 visita en recepción» y /recepcion le contestaba 403. */
        if (puede_el($u, 'recepcion.ver')) {
            $c['recepcion'] = visitas_esperando(cruza_paises($u) ? null : (int)$u['pais_id']);
        }

        /* Pagos esperando confirmación. El número solo se le pinta a quien lo
           va a vaciar: Facturación y Administración. A Dirección NO, aunque
           pueda confirmar — un contador rojo que nadie piensa apagar deja de
           mirarse en tres días, y con él se dejan de mirar los demás.
           (Al Desarrollador sí: es la cuenta técnica y lo ve todo.)

           Sale de pagos_por_validar_resumen() y no de una consulta escrita
           aquí: esa función es la que usan el aviso vivo, la línea del saludo
           y la bandeja, y aplica el país Y el ámbito de quien pregunta. Con la
           consulta escrita a mano el chip decía 351 y la bandeja contestaba
           «no hay nada esperando». Dos cifras distintas de lo mismo en la
           misma pantalla es un fallo aunque ninguna esté mal. */
        /* Ventas con el pago ya confirmado que todavía no salieron. El número
           solo se le pinta a quien las tiene que mandar —el ASESOR—, aunque
           Administración también pueda hacerlo: un contador rojo que no es
           tarea de uno se deja de mirar en tres días, y con él se dejan de
           mirar los demás. Misma decisión que con Dirección y los pagos. */
        if (le_avisamos_de_despacho($u)) {
            $c['despacho'] = pedidos_por_despachar_resumen($u)['n'];
        }

        /* Lo que Almacén tiene que alistar (3g): solo a quien alista y no vende. */
        if (function_exists('le_avisamos_de_alistar') && le_avisamos_de_alistar($u)) {
            $c['alistar'] = alistar_pendientes_n($u);
            $c['entregar'] = function_exists('pedidos_por_entregar_n') ? pedidos_por_entregar_n($u) : 0;
        }

        /* Las garantías que esperan aprobación (3i): a quien las aprueba. */
        if (function_exists('garantias_por_aprobar_n') && puede_el($u, 'garantias.aprobar')) {
            $c['garantias'] = garantias_por_aprobar_n($u);
        }

        if (le_avisamos_de_pagos($u)) {
            /* SIN REVISAR, no el total pendiente. El chip es una lista de
               tareas: lo que ya se miró y espera al banco no es tarea de nadie
               hoy, y dejarlo dentro hace que el número no baje nunca por más
               que facturación trabaje — que es como se deja de mirar un chip. */
            $c['pagos'] = pagos_por_validar_resumen($u)['sin_revisar'];
        }

        /* Lo demás, por permiso y no por rol: con una lista de roles, cada rol
           nuevo obliga a acordarse de este sitio, y olvidarse no da error —
           solo deja de contar algo, en silencio. */
        if (puede('errores.gestionar') || puede('claves.aprobar')) {
            $errores = puede('errores.gestionar')
                ? (int) valor("SELECT COUNT(*) FROM reportes_error WHERE estado = 'abierto'") : 0;
            $claves  = puede('claves.aprobar')
                ? (int) valor("SELECT COUNT(*) FROM solicitudes_password WHERE estado = 'pendiente'") : 0;
            $c['config']  = $errores + $claves;
            $c['errores'] = $errores;
            $c['claves']  = $claves;
        }

        // Lotes que llegan sin niveles de precio: si no se ponen, nadie vende
        if (puede('lotes.gestionar')) {
            $c['lotes'] = function_exists('lotes_sin_precio_n') ? lotes_sin_precio_n(cruza_paises($u) ? null : (int)$u['pais_id']) : 0;
        }
        /* El stock de la web que espera o que quedó en negativo (3e). */
        if (puede('tienda.cola') && function_exists('stock_cola_atencion')) {
            $c['cola'] = stock_cola_atencion();
        }
        /* El chip de Stock suma las dos cosas; la bandeja del inicio las dice
           por separado (antes todo salía como «lotes sin precios»). */
        $c['stock'] = (int)($c['lotes'] ?? 0) + (int)($c['cola'] ?? 0);
    } catch (Throwable $ex) {
        error_log('[HUB] contadores: ' . $ex->getMessage());
    }
    return $c;
}

/**
 * Cuántas visitas esperan en las oficinas. `null` = todos los países, que es
 * lo que ve Dirección. Cacheada por petición: la preguntan los contadores y la
 * línea del saludo, y era el mismo agregado dos veces.
 */
function visitas_esperando(?int $pais_id, bool $olvidar = false): int
{
    static $cache = [];
    if ($olvidar) { $cache = []; return 0; }
    $llave = $pais_id === null ? 'todos' : (string)$pais_id;
    if (isset($cache[$llave])) return $cache[$llave];

    $sql = "SELECT COUNT(*) FROM visitas v
              JOIN oficinas o ON o.id = v.oficina_id
             WHERE v.estado = 'esperando'";
    return $cache[$llave] = $pais_id === null
        ? (int) valor($sql)
        : (int) valor($sql . ' AND o.pais_id = ?', [$pais_id]);
}

/** Tira los contadores. La llama yo_olvidar(): son cifras de UNA persona. */
function contadores_olvidar(): void
{
    contadores(true);
    visitas_esperando(null, true);
    if (function_exists('despacho_pendiente_olvidar')) despacho_pendiente_olvidar();
    if (function_exists('pagos_trabados_olvidar')) pagos_trabados_olvidar();
}

function contador_de(string $clave): int
{
    return (int) (contadores()[$clave] ?? 0);
}
