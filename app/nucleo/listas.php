<?php
declare(strict_types=1);

/**
 * Listas editables: métodos de pago, agencias, canales de venta, tipos de
 * envío y motivos de anulación.
 *
 * Ninguna de estas listas puede ser un `enum` en el código: si mañana entra
 * otra billetera tiene que aparecer sola en el registro del pedido, en el
 * filtro del reporte y en el panel, sin llamar al programador.
 *
 * Regla que no se salta: un valor ya usado en una venta NO se borra, se apaga.
 * Apagado deja de ofrecerse en pedidos nuevos y sigue apareciendo en los
 * antiguos; borrarlo dejaría a los pedidos viejos sin nombre de pago y los
 * reportes históricos cambiarían solos.
 */

/** Items activos de una lista, ya ordenados. $pais_id null = los de todos. */
function lista(string $clave, ?int $pais_id = null): array
{
    static $cache = [];
    $k = $clave . '|' . (int)$pais_id;
    if (isset($cache[$k])) return $cache[$k];

    $sql = 'SELECT i.* FROM lista_items i
              JOIN listas l ON l.id = i.lista_id
             WHERE l.clave = ? AND i.activo = 1';
    $par = [$clave];
    if ($pais_id) { $sql .= ' AND (i.pais_id IS NULL OR i.pais_id = ?)'; $par[] = $pais_id; }
    $sql .= ' ORDER BY i.orden, i.valor';

    return $cache[$k] = todas($sql, $par);
}

/** Una fila de lista por id, con la clave de su lista. null si no existe. */
function lista_item(?int $id): ?array
{
    if (!$id) return null;
    return una('SELECT i.*, l.clave AS lista FROM lista_items i
                  JOIN listas l ON l.id = i.lista_id WHERE i.id = ?', [$id]);
}

/** El texto de un item, o '' si no hay. Sirve para pintar pedidos antiguos. */
function lista_texto(?int $id): string
{
    $i = lista_item($id);
    return $i ? (string)$i['valor'] : '';
}

/**
 * ¿Ese id pertenece a esa lista y sigue encendido?
 * Es la comprobación del servidor: el desplegable ya filtra, pero el
 * formulario se puede tocar y nadie puede pagar con un método de otra lista.
 */
function lista_valida(string $clave, ?int $id, ?int $pais_id = null): bool
{
    if (!$id) return false;
    $sql = 'SELECT 1 FROM lista_items i JOIN listas l ON l.id = i.lista_id
             WHERE i.id = ? AND l.clave = ? AND i.activo = 1';
    $par = [$id, $clave];
    if ($pais_id) { $sql .= ' AND (i.pais_id IS NULL OR i.pais_id = ?)'; $par[] = $pais_id; }
    return (bool) valor($sql, $par);
}

/**
 * ¿Este método de pago cuenta como cobrado en el momento?
 *
 * HOY NINGUNO. Lo decidió el usuario el 2026-09-08 y por una razón concreta:
 * hay clientes que mandan vouchers falsos, y un pago que suma a la meta sin
 * que nadie lo mire es dinero inventado en el podio y cashback regalado. Todos
 * los métodos entran PENDIENTES y los confirma Facturación.
 *
 * El interruptor sigue existiendo por método —no es una regla escrita en el
 * código— pero nace apagado: si la respuesta no se sabe, la respuesta es que
 * hay que confirmarlo. La pantalla para encenderlo llega con las listas
 * editables, en el módulo 3; hoy solo se cambia en la base.
 */
function metodo_al_instante(?int $item_id): bool
{
    $i = lista_item($item_id);
    if (!$i || ($i['lista'] ?? '') !== 'metodos_pago') return false;
    return (int)($i['al_instante'] ?? 0) === 1;
}

/** ¿Este método obliga a adjuntar la foto del voucher? */
function metodo_pide_comprobante(?int $item_id): bool
{
    $i = lista_item($item_id);
    if (!$i || ($i['lista'] ?? '') !== 'metodos_pago') return false;
    return (int)($i['pide_comprobante'] ?? 0) === 1;
}

/**
 * ¿Este método pide además la FOTO DEL DNI de quien paga? (3f) Se marca por
 * método en Configuración › Métodos de pago; nace marcado en el POS.
 */
function metodo_pide_dni(?int $item_id): bool
{
    if (!$item_id || !columna_existe('lista_items', 'pide_dni')) return false;
    $i = lista_item($item_id);
    if (!$i || ($i['lista'] ?? '') !== 'metodos_pago') return false;
    return (int)($i['pide_dni'] ?? 0) === 1;
}

/**
 * LOS NOMBRES DE FÁBRICA QUE SE CAMBIARON en Configuración › Métodos de pago.
 * Las semillas buscan cada método por su nombre: sin esto, renombrar
 * «POS / tarjeta» a «POS Izipay» hacía que la siguiente actualización volviera
 * a crear un «POS / tarjeta» encendido (auditoría del 3f).
 */
function metodos_renombrados(): array
{
    $l = json_decode((string) (valor("SELECT valor FROM ajustes WHERE clave = 'metodos_renombrados'") ?? ''), true);
    return is_array($l) ? array_values(array_map('strval', $l)) : [];
}

function metodo_renombrado_apuntar(string $antes): void
{
    $l = metodos_renombrados();
    if (in_array($antes, $l, true)) return;
    $l[] = $antes;
    $v = (string) json_encode($l, JSON_UNESCAPED_UNICODE);
    if (valor("SELECT 1 FROM ajustes WHERE clave = 'metodos_renombrados'")) {
        q("UPDATE ajustes SET valor = ? WHERE clave = 'metodos_renombrados'", [$v]);
    } else {
        q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
           VALUES ('metodos_renombrados', ?, 'texto', 'pagos', 'Métodos de pago de fábrica a los que se les cambió el nombre. No tocar.', 1)", [$v]);
    }
}

/** La frase, una sola vez: la dicen el formulario de la venta y el del pago. */
function metodo_pide_dni_texto(): string
{
    return 'Este método de pago necesita también la foto del DNI de quien paga.';
}

/**
 * ¿Este método es EFECTIVO? Es lo que enciende la cuenta del vuelto.
 *
 * Se mira la marca de la lista, no el nombre: así «Efectivo soles» o «Efectivo
 * caja 2» funcionan igual el día que existan, sin tocar una línea.
 */
function metodo_es_efectivo(?int $item_id): bool
{
    if (!$item_id) return false;
    $i = lista_item($item_id);
    if (!$i || ($i['lista'] ?? '') !== 'metodos_pago') return false;
    return (string)($i['extra'] ?? '') === 'efectivo';
}

/**
 * El recargo que cobra un método de pago, en CENTÉSIMAS de punto porcentual:
 * el 4% del POS son 400.
 *
 * Es una propiedad de la LISTA, no un número escrito en el código: el día que
 * el banco baje al 3.5%, Administración escribe 350 en Configuración y la
 * pantalla se entera sola. Sin la columna todavía —un HUB al que no le han
 * pasado actualizar.php— devuelve 0, que es «no cobra nada»: el asesor lo
 * escribe a mano como hasta ahora y nada se rompe.
 */
function metodo_recargo_centesimas(?int $item_id): int
{
    if (!$item_id || !columna_existe('lista_items', 'recargo_centesimas')) return 0;
    $i = lista_item($item_id);
    if (!$i || ($i['lista'] ?? '') !== 'metodos_pago') return 0;
    return max(0, (int)($i['recargo_centesimas'] ?? 0));
}

/**
 * Lo que cobra la pasarela por un pago, en céntimos.
 *
 * «El pago en POS tiene un cargo del 4% del MONTO TOTAL PAGADO» (usuario,
 * 2026-09-11): se calcula sobre lo que pasa por el POS, no sobre el precio sin
 * recargo.
 *
 * Aritmética de enteros de principio a fin —400 centésimas de punto son
 * monto × 400 / 10000— porque un 4.0 flotante por un monto grande es
 * exactamente lo que descuadra un céntimo cada mil ventas. Se trunca hacia
 * abajo, como todo el dinero del HUB.
 */
function recargo_de_pago(int $monto_centimos, ?int $metodo_item_id): int
{
    if ($monto_centimos <= 0) return 0;
    $pct = metodo_recargo_centesimas($metodo_item_id);
    if ($pct <= 0) return 0;
    return intdiv($monto_centimos * $pct, 10000);
}

/** Cuántas veces se usó un item. Es lo que decide si se puede borrar o solo apagar. */
function lista_usos(int $item_id): int
{
    $n = 0;
    foreach ([
        ['pagos',    'metodo_item_id'],
        ['pedidos',  'canal_item_id'],
        ['pedidos',  'agencia_item_id'],
        ['pedidos',  'tipo_envio_item_id'],
        ['pedidos',  'anulado_motivo_item_id'],
        ['pedidos',  'garantia_item_id'],
        ['pedidos',  'alistado_quien_id'],     // quién alistó (3g)
        ['garantias','alistado_quien_id'],     // y las garantías (3i)
        ['lotes',    'agencia_item_id'],       // la agencia de carga (3h)
        ['clientes', 'canal_item_id'],
        ['visitas',  'motivo'],
    ] as [$tabla, $col]) {
        if (!tabla_existe($tabla) || !columna_existe($tabla, $col)) continue;
        if ($col === 'motivo') continue;          // visitas.motivo es texto, no un id
        $n += (int) valor("SELECT COUNT(*) FROM `$tabla` WHERE `$col` = ?", [$item_id]);
    }
    return $n;
}

/**
 * ¿Este tipo de envío tiene costo para el cliente?
 *
 * Es una propiedad de la LISTA, no del nombre del item. Antes el formulario
 * decidía por la rama Lima/provincia y por eso «Envío con costo en Lima» no
 * tenía dónde escribir el monto: el asesor elegía envío con costo y el HUB lo
 * ponía en cero sin decir nada. Con la columna, el día que se cree «Envío con
 * costo a Arequipa» aparece solo, sin tocar código.
 */
function tipo_envio_cobra_flete(?int $item_id): bool
{
    if (!$item_id) return false;
    /* Sin la columna todavía —un HUB al que no le han pasado actualizar.php—
       se cae al comportamiento de antes: a provincia se cobra flete. Devolver
       `false` a secas dejaba el campo inaccesible en TODO el país y era una
       capacidad que desaparecía en silencio. */
    /* Sin la columna todavía —un HUB al que no le han pasado actualizar.php—
       ningún tipo cobra flete, que es como se comportaba antes. El campo de
       provincia lo sigue encendiendo su propia rama, así que no se pierde
       nada: el respaldo anterior, que devolvía true para «Flete a provincia»,
       hacía obligatorio un monto cuyo campo la vista mantenía escondido y
       dejaba TODA venta a provincia sin poder guardarse. */
    if (!columna_existe('lista_items', 'cobra_flete')) return false;
    /* Y se comprueba que el item sea de SU lista y esté encendido, como sus
       hermanas de métodos de pago: es la que decide si entra dinero al total. */
    $i = lista_item($item_id);
    if (!$i || ($i['lista'] ?? '') !== 'tipos_envio' || (int)($i['activo'] ?? 0) !== 1) return false;
    return (int)($i['cobra_flete'] ?? 0) === 1;
}

/**
 * ¿Ya existe un item con ese texto en esta lista? Devuelve el item o null.
 *
 * Compara SIN tildes y sin mayúsculas, que es como se cuela lo repetido:
 * alguien escribe «shalom» y en la lista está «Shalom», y acabamos con dos
 * agencias que son la misma. Lo pidió el usuario el 2026-09-11 al abrir la
 * puerta de «otra agencia»: «si ya existe no debe permitir añadirlo».
 */
function lista_item_por_texto(string $clave, string $texto, ?int $pais_id = null): ?array
{
    $t = lista_texto_llano($texto);
    if ($t === '') return null;
    foreach (lista($clave, $pais_id) as $i) {
        if (lista_texto_llano((string)$i['valor']) === $t) return $i;
    }
    return null;
}

/** El texto de un item, sin tildes, sin mayúsculas y sin dobles espacios. */
function lista_texto_llano(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/**
 * ¿Dónde aplica un tipo de envío? 'lima', 'provincia', 'oficina' — o '' , que
 * es «en todas».
 *
 * Otra propiedad de la LISTA, por la misma razón que `cobra_flete`: el
 * desplegable enseñaba los cuatro tipos siempre, así que a una venta a Cusco
 * le ofrecía «Envío gratis Lima». Con los nombres escritos en el código, cada
 * tipo nuevo habría que pedírselo al programador.
 */
function tipo_envio_ambito(?int $item_id): string
{
    if (!$item_id || !columna_existe('lista_items', 'ambito_envio')) return '';
    $i = lista_item($item_id);
    if (!$i || ($i['lista'] ?? '') !== 'tipos_envio') return '';
    $a = (string)($i['ambito_envio'] ?? '');
    return in_array($a, ['lima', 'provincia', 'oficina'], true) ? $a : '';
}

/**
 * ¿Este tipo de envío vale para este destino? Lo vacío vale en todas partes,
 * que es como se comportaba el HUB antes de que la columna existiera.
 */
function tipo_envio_vale_para(?int $item_id, string $destino): bool
{
    $a = tipo_envio_ambito($item_id);
    return $a === '' || $a === $destino;
}

/* ══════════════════════════════════════════════════════════════════════
 * LAS SUCURSALES DE CADA AGENCIA, POR CIUDAD (parche 2r)
 *
 * «¿Podremos hacer que según la agencia y destino salgan las sucursales
 * disponibles?» (usuario, 2026-09-20). No existe una lista oficial que
 * cargar —cada agencia abre y cierra sucursales cuando quiere—, así que la
 * lista SE APRENDE: lo que un asesor escribe una vez se lo ofrece a todos la
 * siguiente, ordenado por lo que de verdad se usa.
 * ═══════════════════════════════════════════════════════════════════════ */

/** La provincia de un distrito: es el nivel al que una agencia tiene sucursales. */
function sucursal_provincia_de(?int $ubigeo_id): ?int
{
    if (!$ubigeo_id) return null;
    $u = ubigeo_de($ubigeo_id);
    $pid = $u['provincia_id'] ?? null;
    return $pid ? (int)$pid : null;
}

/**
 * Apunta la sucursal que acaba de usarse. Si ya estaba, le suma una vez.
 *
 * No valida nada ni rechaza nada: la venta ya está guardada y esto es solo
 * memoria para la próxima. Por eso tampoco puede reventar una venta — si la
 * tabla todavía no existe, se va sin hacer ruido.
 */
function sucursal_recordar(int $pais_id, ?int $agencia_item_id, ?int $ubigeo_id,
                           string $nombre, ?int $usuario_id = null): void
{
    $nombre = trim($nombre);
    if (!$agencia_item_id || $nombre === '' || mb_strlen($nombre) > 120) return;
    if (!tabla_existe('agencia_sucursales')) return;

    $busca = ubigeo_busca($nombre);
    if ($busca === '') return;
    $prov = sucursal_provincia_de($ubigeo_id);

    /* La provincia puede ser NULL, y en SQL «NULL = NULL» es NULL: con `= ?`
       la misma sucursal se habría duplicado en cada venta sin distrito. */
        /* TODO ESTO VA EN UN try: se llama DENTRO de la transacción que guarda la
       venta, y dos asesores registrando a la vez la misma agencia, provincia y
       sucursal chocan en la clave única. Sin el try, esa colisión se llevaba
       por delante el pedido entero, sus líneas y su cashback — por apuntar una
       sugerencia (auditoría del 2r). */
    try {
        $ya = una('SELECT id FROM agencia_sucursales
                    WHERE pais_id = ? AND agencia_item_id = ? AND busca = ?
                      AND ((provincia_id IS NULL AND ? IS NULL) OR provincia_id = ?)',
                  [$pais_id, $agencia_item_id, $busca, $prov, $prov]);
        if ($ya) {
            q('UPDATE agencia_sucursales SET veces = veces + 1, activo = 1 WHERE id = ?', [(int)$ya['id']]);
            return;
        }
        insertar('agencia_sucursales', [
            'pais_id' => $pais_id, 'agencia_item_id' => $agencia_item_id,
            'provincia_id' => $prov, 'nombre' => $nombre, 'busca' => $busca,
            'veces' => 1, 'activo' => 1, 'creado_por' => $usuario_id,
        ]);
    } catch (Throwable $ex) {
        error_log('[HUB] sucursal_recordar: ' . $ex->getMessage());
    }
}

/**
 * Las sucursales que se han usado con esa agencia en esa ciudad.
 *
 * Primero las de la ciudad del pedido y después las de la misma agencia en
 * otras ciudades: una agencia nueva en Puno no tiene ninguna apuntada todavía,
 * y enseñar las de Cusco es mejor que enseñar una lista vacía — el asesor
 * reconoce el nombre o escribe el suyo, que es lo de siempre.
 */
function sucursales_de(int $pais_id, ?int $agencia_item_id, ?int $ubigeo_id, int $limite = 12): array
{
    if (!$agencia_item_id || !tabla_existe('agencia_sucursales')) return [];
    $prov = sucursal_provincia_de($ubigeo_id);

    $filas = todas(
        'SELECT nombre, veces,
                CASE WHEN provincia_id IS NOT NULL AND provincia_id = ? THEN 1 ELSE 0 END AS aqui
           FROM agencia_sucursales
          WHERE pais_id = ? AND agencia_item_id = ? AND activo = 1
          ORDER BY aqui DESC, veces DESC, nombre
          LIMIT ' . (int)$limite,
        [$prov, $pais_id, $agencia_item_id]
    );
    return array_map(fn($f) => [
        'nombre' => (string)$f['nombre'],
        'aqui'   => (int)$f['aqui'] === 1,
    ], $filas);
}
