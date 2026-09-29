<?php
declare(strict_types=1);

/**
 * EL CATÁLOGO: productos, sus modelos y colores, y sus precios por tramo.
 * Primera entrega del módulo 3 (3a-1, 2026-09-22).
 *
 * POR QUÉ EXISTE. Hasta ahora el asesor ESCRIBÍA el producto, el modelo y el
 * precio a mano en cada venta. Eso significa que «Silla Gamer», «silla gamer»
 * y «Silla gammer» son tres productos distintos para cualquier reporte, que
 * nadie puede decir qué se vende más, y que el precio depende de la memoria de
 * quien vende. Con el catálogo, el precio lo pone la empresa una vez y la
 * venta queda enlazada al producto de verdad.
 *
 * LO QUE ESTA ENTREGA NO TRAE, a propósito: el stock (llega con los lotes, en
 * la 3a-2) y el descuento del asesor (el usuario lo dejó fuera por ahora,
 * 2026-09-22: el precio es el del tramo y no se toca).
 *
 * UNA SOLA DEFINICIÓN POR REGLA, como en el resto del HUB:
 *   · precio_de_variante()  — qué precio le toca a esta cantidad;
 *   · producto_completo()   — un producto sin precio no se puede vender;
 *   · catalogo_buscar()     — lo que ve el asesor al escribir.
 */

/* ───────────────────────── Los códigos ───────────────────────── */

/**
 * El código que se genera solo cuando no se pega uno: WK-000001.
 *
 * NO empieza por PRD-, a propósito (usuario, 2026-09-24): los PRD los pone
 * marketing en la tienda y en el Excel de cargas, y un PRD-000001 generado
 * aquí se «fundía» al traer la tienda con el PRD-000001 de verdad —el
 * producto dado de alta a mano pasaba a llamarse como el de la web—. Si ya
 * tienes el PRD del producto, se pega al darlo de alta.
 */
function producto_sku_nuevo(): string
{
    for ($i = 0; $i < 40; $i++) {
        $n = (int) valor('SELECT COUNT(*) FROM productos') + 1 + $i;
        $sku = 'WK-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
        if (!valor('SELECT id FROM productos WHERE sku = ?', [$sku])) return $sku;
    }
    return 'WK-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * ¿Vale este código? Letras, números, guiones, punto y guion bajo, de 3 a 60.
 * La única definición: la usan el alta a mano y el importador de la tienda.
 */
function sku_valido(string $sku): bool
{
    return (bool) preg_match('/^[A-Z0-9][A-Z0-9\-_.]{2,59}$/', $sku);
}

/**
 * El código de una variante cuelga del de su producto: PRD-000012-ROJO-40.
 * $reservados: códigos que tampoco se pueden usar aunque todavía no estén en
 * la base (los que trae la tienda en la misma lectura).
 */
function variante_sku_nuevo(string $sku_producto, string $color, string $medida, array $reservados = []): string
{
    $trozo = function (string $t): string {
        $t = strtr(mb_strtoupper(trim($t)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N']);
        $t = preg_replace('/[^A-Z0-9]+/', '', $t) ?? '';
        return mb_substr($t, 0, 8);
    };
    $base = $sku_producto;
    foreach ([$color, $medida] as $x) {
        $p = $trozo((string)$x);
        if ($p !== '') $base .= '-' . $p;
    }
    if ($base === $sku_producto) $base .= '-U';          // única
    $sku = mb_substr($base, 0, 60);
    $i = 2;
    while (isset($reservados[$sku]) || valor('SELECT id FROM variantes WHERE sku = ?', [$sku])) {
        $sku = mb_substr($base, 0, 56) . '-' . $i++;
    }
    return $sku;
}

/* ───────────────────────── Preguntar el catálogo ───────────────────────── */

/**
 * EL CATÁLOGO ES DE UN PAÍS. Waka abre en México y allí ni los productos ni
 * los precios son los mismos —ni la moneda—, así que un producto nace en el
 * país de quien lo crea. `pais_id` vacío significa «vale para todos», que es
 * lo que tienen los productos cargados antes de esta regla.
 */
function catalogo_pais(): int
{
    $u = yo();
    return $u ? (int)$u['pais_id'] : 0;
}

/** El trozo de SQL que deja fuera los productos de otro país. */
function sql_producto_de_mi_pais(string $alias = 'p'): string
{
    if (!columna_existe('productos', 'pais_id')) return '1=1';
    return "($alias.pais_id IS NULL OR $alias.pais_id = " . catalogo_pais() . ")";
}

/** Un producto con lo que hace falta para pintarlo. Null si no existe. */
function producto_de(int $id): ?array
{
    if (!tabla_existe('productos')) return null;
    $p = una('SELECT p.* FROM productos p WHERE p.id = ? AND ' . sql_producto_de_mi_pais(), [$id]);
    return $p ?: null;
}

/** Las variantes de un producto. Por defecto solo las encendidas. */
function producto_variantes(int $producto_id, bool $todas = false): array
{
    if (!tabla_existe('variantes')) return [];
    return todas('SELECT * FROM variantes WHERE producto_id = ?' . ($todas ? '' : ' AND activo = 1')
               . ' ORDER BY activo DESC, id', [$producto_id]);
}

/**
 * LOS TRAMOS DE PRECIO de un producto.
 *
 * `$variante_id` null devuelve los del producto —los que heredan todos los
 * colores—, que es lo normal: el usuario decidió el 2026-09-09 que «mismo
 * precio para todos los colores» viene encendido, porque un lote de 29
 * variantes serían 116 números escritos a mano.
 */
function producto_tramos(int $producto_id, ?int $variante_id = null, ?int $lote_id = null): array
{
    if (!tabla_existe('precios')) return [];
    /* $lote_id: los tramos de la PRE VENTA de ese lote (3h). null = la tienda. */
    $sql = 'SELECT * FROM precios WHERE producto_id = ? AND activo = 1 AND '
         . ($lote_id === null ? 'lote_id IS NULL' : 'lote_id = ?') . ' AND ';
    $sql .= $variante_id === null ? 'variante_id IS NULL' : 'variante_id = ?';
    $par = [$producto_id];
    if ($lote_id !== null) $par[] = $lote_id;
    if ($variante_id !== null) $par[] = $variante_id;
    return todas($sql . ' ORDER BY desde', $par);
}

/**
 * EL PRECIO DE UNA CANTIDAD DENTRO DE UNOS TRAMOS. La única cuenta: la usan
 * la tienda (precio_de_variante) y la pre venta (precio_de_lote).
 * null = esos tramos no dan precio (no hay ninguno).
 */
function precio_segun_tramos(array $tramos, int $cantidad): ?int
{
    if (!$tramos) return null;
    $cantidad = max(1, $cantidad);
    foreach ($tramos as $t) {
        $desde = max(1, (int)$t['desde']);
        $hasta = $t['hasta'] === null || $t['hasta'] === '' ? null : (int)$t['hasta'];
        if ($cantidad >= $desde && ($hasta === null || $cantidad <= $hasta)) {
            return (int)$t['precio_centimos'];
        }
    }
    /* Cantidad por encima del último tramo con «hasta»: vale el último.
       Es lo que espera quien vende: «de 30 a más» no puede dejar sin
       precio a quien pide 31 porque alguien olvidó quitar el hasta. */
    $ultimo = end($tramos);
    if ($cantidad > (int)$ultimo['desde']) return (int)$ultimo['precio_centimos'];
    return null;
}

/**
 * QUÉ PRECIO LE TOCA A ESTA CANTIDAD. La única definición.
 *
 * Primero los tramos de ESA variante —si alguien le puso precio propio, manda
 * el suyo—; si no tiene, los del producto. Devuelve céntimos, o null cuando el
 * producto todavía no tiene precio: sin precio no se puede vender, porque el
 * asesor no puede poner un número que nadie autorizó.
 */
function precio_de_variante(int $producto_id, ?int $variante_id, int $cantidad): ?int
{
    $listas = [];
    if ($variante_id) $listas[] = producto_tramos($producto_id, $variante_id);
    $listas[] = producto_tramos($producto_id, null);

    foreach ($listas as $tramos) {
        if (!$tramos) continue;
        $c = precio_segun_tramos($tramos, $cantidad);
        if ($c !== null) return $c;
    }
    return null;
}

/** Un producto está COMPLETO —se puede vender— cuando tiene al menos un tramo. */
function producto_completo(int $producto_id): bool
{
    if (!tabla_existe('precios')) return false;
    return (int) valor('SELECT COUNT(*) FROM precios WHERE producto_id = ? AND activo = 1 AND lote_id IS NULL',
                       [$producto_id]) > 0;
}

/** El precio de una unidad, que es el que se enseña como «desde». */
function producto_precio_base(int $producto_id): ?int
{
    return precio_de_variante($producto_id, null, 1);
}

/**
 * LO QUE VE EL ASESOR AL ESCRIBIR, en el formulario del pedido.
 * Solo productos encendidos y CON precio: ofrecer uno sin precio es ofrecer
 * una venta que no se va a poder registrar.
 */
function catalogo_buscar(string $q, int $limite = 8, bool $sin_repuestos = false): array
{
    if (!tabla_existe('productos')) return [];
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    /* El % y el _ son comodines dentro de un LIKE: sin escaparlos, buscar
       «100%» devolvía el catálogo entero. */
    $like = '%' . catalogo_escapar_like($q) . '%';
    /* LOS REPUESTOS SALEN BAJO SU MÁQUINA (3i): el asesor busca «el joystick
       de la máquina de peluches», así que buscar la máquina trae también sus
       repuestos, detrás de ella. */
    $con_padre = function_exists('repuestos_listo') && repuestos_listo();
    $por_maquina = $con_padre
        ? " OR p.padre_id IN (SELECT m.id FROM productos m WHERE m.nombre LIKE ? OR m.sku LIKE ?)" : '';
    $orden = $con_padre ? 'COALESCE(pm.nombre, p.nombre), CASE WHEN p.padre_id IS NULL THEN 0 ELSE 1 END, p.nombre' : 'p.nombre';
    $filas = todas(
        "SELECT p.*" . ($con_padre ? ", pm.nombre AS maquina_nombre" : '') . " FROM productos p
          " . ($con_padre ? 'LEFT JOIN productos pm ON pm.id = p.padre_id' : '') . "
          WHERE p.activo = 1 AND " . sql_producto_de_mi_pais() . "
            AND (p.nombre LIKE ? OR p.sku LIKE ? OR COALESCE(p.categoria,'') LIKE ?$por_maquina)
            " . ($con_padre && $sin_repuestos ? 'AND p.padre_id IS NULL' : '') . "
            AND EXISTS (SELECT 1 FROM precios x WHERE x.producto_id = p.id AND x.activo = 1 AND x.lote_id IS NULL)
          ORDER BY $orden LIMIT " . max(1, min(20, $limite)),
        $con_padre ? [$like, $like, $like, $like, $like] : [$like, $like, $like]);

    /* El stock de la web de todos de una vez (3b.5): el asesor lo ve al
       elegir, junto al precio. Solo se enseña; no frena la venta. */
    $st = stock_web_de(array_map(fn($x) => (int)$x['id'], $filas));
    /* El de los repuestos que lleva el HUB (3i). Con el control de stock
       encendido, sin unidades no se elige: la venta lo frena igual. */
    $hub = $con_padre ? stock_hub_de(array_map(fn($x) => (int)$x['id'], array_filter($filas, 'stock_hub_aplica'))) : [];
    $out = [];
    foreach ($filas as $p) {
        $stp = $st[(int)$p['id']] ?? null;
        $en_hub = $con_padre && stock_hub_aplica($p);
        $out[] = [
            'id'        => (int)$p['id'],
            'sku'       => (string)$p['sku'],
            'stock'     => $en_hub ? stock_hub_texto($hub[(int)$p['id']] ?? 0) : stock_web_texto($stp),
            'agotado'   => $en_hub ? ($hub[(int)$p['id']] ?? 0) <= 0 : stock_web_agotado($stp),
            'maquina'   => (string)($p['maquina_nombre'] ?? ''),
            /* El agotado del almacén del HUB frena con el control de stock
               encendido, haya tienda o no. */
            'frena'     => $en_hub && stock_hub_exige(),
            'nombre'    => (string)$p['nombre'],
            'categoria' => (string)($p['categoria'] ?? ''),
            'precio'    => producto_precio_base((int)$p['id']),
            'foto'      => producto_foto_url($p['foto'] ?? null),
            'garantia_item_id' => (int)($p['garantia_item_id'] ?? 0),
            /* Cada color con SUS tramos: si alguien le puso precio propio al
               rojo, la pantalla tiene que enseñar ese, no el del producto.
               Sin esto, el asesor le decía un precio al cliente y el servidor
               guardaba otro (auditoría del módulo 3a-1). */
            'variantes' => array_map(fn($v) => [
                'id'     => (int)$v['id'],
                'nombre' => variante_nombre($v),
                'stock'  => stock_web_texto(isset($stp['variantes'][(int)$v['id']])
                                            ? $stp['variantes'][(int)$v['id']] : null),
                'agotado'=> stock_web_agotado($stp['variantes'][(int)$v['id']] ?? null),
                'tramos' => array_map(fn($t) => [
                    'desde'  => (int)$t['desde'],
                    'hasta'  => $t['hasta'] === null ? null : (int)$t['hasta'],
                    'precio' => (int)$t['precio_centimos'],
                    'alias'  => (string)($t['alias'] ?? ''),
                ], producto_tramos((int)$p['id'], (int)$v['id'])),
            ], producto_variantes((int)$p['id'])),
            /* Los tramos viajan con el producto para que la pantalla pueda
               decir el precio en cuanto cambie la cantidad, sin otra vuelta al
               servidor. El precio que VALE lo vuelve a calcular el servidor al
               guardar: lo que llega del navegador no decide dinero. */
            'tramos'    => array_map(fn($t) => [
                'desde'  => (int)$t['desde'],
                'hasta'  => $t['hasta'] === null ? null : (int)$t['hasta'],
                'precio' => (int)$t['precio_centimos'],
                'alias'  => (string)($t['alias'] ?? ''),
            ], producto_tramos((int)$p['id'], null)),
        ];
    }
    return $out;
}

/** El % y el _ son comodines del LIKE: se escapan antes de buscar. */
function catalogo_escapar_like(string $t): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $t);
}

/**
 * CUÁNDO DOS COLORES SON EL MISMO. La única definición: sin tildes, sin
 * mayúsculas y sin espacios de más. «Marrón» y «marron » son el mismo color.
 * Antes el alta a mano lo preguntaba a la base (que en MySQL ignora tildes y
 * mayúsculas y en el banco de pruebas no) y el importador por su cuenta: un
 * «Marrón» de la tienda entraba como color nuevo junto al «Marron» de aquí.
 */
function variante_clave(string $color, string $medida): string
{
    $n = function (string $t): string {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $t) ?? $t));
        return strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                          'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    };
    return $n($color) . '|' . $n($medida);
}

/**
 * LA DIRECCIÓN DE LA MINIATURA de un producto, o '' si no tiene. La única
 * definición: la usan el catálogo, la ficha, el buscador del pedido y las
 * líneas de la venta. Solo nombres con la forma que genera el importador.
 */
function producto_foto_url(?string $foto): string
{
    $foto = (string)$foto;
    if (!foto_producto_valida($foto)) return '';
    return url(FOTO_PRODUCTO_CARPETA . '/' . $foto);
}

/** Dónde viven las miniaturas y cómo se llaman: una sola definición. */
const FOTO_PRODUCTO_CARPETA = 'uploads/productos';

function foto_producto_valida(string $archivo): bool
{
    return (bool) preg_match('/^w\d+-[a-f0-9]{8}\.jpg$/', $archivo);
}

/** Las fotos de varios productos de una vez: [id => archivo]. */
function producto_fotos(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !columna_existe('productos', 'foto')) return [];
    $en = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (todas("SELECT id, foto FROM productos WHERE foto IS NOT NULL AND id IN ($en)", $ids) as $x) {
        $out[(int)$x['id']] = (string)$x['foto'];
    }
    return $out;
}

/** «Rojo · 40 cm», o «Única» cuando el producto no tiene colores. */
function variante_nombre(array $v): string
{
    $t = array_values(array_filter([trim((string)($v['color'] ?? '')), trim((string)($v['medida'] ?? ''))]));
    return $t ? implode(' · ', $t) : 'Única';
}

/** ¿Se puede vender algo que no está en el catálogo? Se cambia en Configuración. */
function catalogo_libre(): bool
{
    return (string) ajuste('catalogo_libre', '1') === '1';
}

/* ───────────────────────── Guardar ───────────────────────── */

/**
 * Da de alta o edita un producto. Devuelve ['ok','error','id'].
 * El SKU se escribe una vez: cambiarlo después rompería el enlace con la
 * tienda y con las ventas que ya salieron con ese código.
 */
/**
 * LAS CATEGORÍAS DEL CATÁLOGO (3f): las de la web. «¿Qué tal si alguien
 * escribe algo que no está en la web?» (usuario, 2026-09-26): ya no se
 * escribe, se elige. La web sigue mandando: la lista se guarda cada vez que
 * se lee la tienda. Sin ninguna lectura todavía, las que ya tienen los
 * productos que vinieron de la web. Una sola definición.
 */
function categorias_del_catalogo(): array
{
    $l = json_decode((string) (valor("SELECT valor FROM ajustes WHERE clave = 'tienda_categorias'") ?? ''), true);
    if (!is_array($l) || !$l) {
        $l = array_column(todas("SELECT DISTINCT categoria FROM productos
                                   WHERE woo_id IS NOT NULL AND categoria IS NOT NULL AND categoria <> ''"), 'categoria');
    }
    $l = array_values(array_unique(array_map('strval', $l)));
    sort($l, SORT_NATURAL | SORT_FLAG_CASE);
    return $l;
}

/** Guarda la lista de categorías que tiene la web, de una lectura completa. */
function categorias_web_guardar(array $filas): void
{
    $l = [];
    foreach ($filas as $f) foreach ((array)($f['categorias'] ?? []) as $c) if ((string)$c !== '') $l[(string)$c] = true;
    if (!$l || !function_exists('guardar_ajuste_tecnico')) return;
    guardar_ajuste_tecnico('tienda_categorias', (string) json_encode(array_keys($l), JSON_UNESCAPED_UNICODE),
                           'Las categorías que tiene la web. Se llenan solas al leer la tienda.');
}

function producto_guardar(?int $id, array $d): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => $id ?? 0];

    $nombre = trim((string)($d['nombre'] ?? ''));
    if (mb_strlen($nombre) < 2) return $mal('Ponle un nombre al producto.');
    if (mb_strlen($nombre) > 180) $nombre = mb_substr($nombre, 0, 180);

    $cat = mb_substr(trim((string)($d['categoria'] ?? '')), 0, 80);
    /* De la lista de la web, o vacía. La que ya tenía se puede dejar tal cual
       (un producto viejo con una categoría que la web ya no tiene). */
    $cat_antes = $id ? (string)(producto_de($id)['categoria'] ?? '') : '';
    if ($cat !== '' && $cat !== $cat_antes && !in_array($cat, categorias_del_catalogo(), true)) {
        return $mal('Elige una categoría de la lista. Las categorías se crean en la web.');
    }
    $gar = (int)($d['garantia_item_id'] ?? 0);
    if ($gar && !lista_valida('garantias', $gar, catalogo_pais())) {
        return $mal('Elige una garantía de la lista.');
    }

    $sku = mb_strtoupper(trim((string)($d['sku'] ?? '')));
    if ($sku !== '' && !sku_valido($sku)) {
        return $mal('Ese código no vale: letras, números y guiones, de 3 a 60.');
    }

    $datos = ['nombre' => $nombre, 'categoria' => $cat ?: null,
              'activo' => !empty($d['activo']) ? 1 : 0];
    if (columna_existe('productos', 'garantia_item_id')) $datos['garantia_item_id'] = $gar ?: null;

    if ($id) {
        $antes = producto_de($id);
        if (!$antes) return $mal('Ese producto no existe.');
        actualizar('productos', $id, $datos);
        bitacora('catalogo.editar', 'producto', $id, ['nombre' => $nombre]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }

    if ($sku === '') $sku = producto_sku_nuevo();
    if (valor('SELECT id FROM productos WHERE sku = ?', [$sku])) {
        return $mal('Ya hay un producto con el código ' . $sku . '.');
    }
    $datos['sku'] = $sku;
    if (columna_existe('productos', 'pais_id')) $datos['pais_id'] = catalogo_pais() ?: null;
    /* Dos altas a la vez pueden pedir el mismo código: el único de la tabla lo
       impide, y aquí se contesta con una frase en vez de una pantalla rota. */
    try {
        $nuevo = insertar('productos', $datos);
    } catch (Throwable $ex) {
        if (es_choque_de_unico($ex)) return $mal('Otro producto acaba de quedarse con ese código. Inténtalo otra vez.');
        throw $ex;
    }
    bitacora('catalogo.crear', 'producto', $nuevo, ['nombre' => $nombre, 'sku' => $sku]);
    return ['ok' => true, 'error' => '', 'id' => $nuevo];
}

/** Añade una variante (modelo, color o medida) a un producto. */
function variante_guardar(int $producto_id, array $d): array
{
    $p = producto_de($producto_id);
    if (!$p) return ['ok' => false, 'error' => 'Ese producto no existe.'];
    $color  = mb_substr(trim((string)($d['color'] ?? '')), 0, 60);
    $medida = mb_substr(trim((string)($d['medida'] ?? '')), 0, 60);
    if ($color === '' && $medida === '') {
        return ['ok' => false, 'error' => 'Escribe el color, el modelo o la medida.'];
    }
    $clave = variante_clave($color, $medida);
    foreach (todas('SELECT color, medida FROM variantes WHERE producto_id = ?', [$producto_id]) as $x) {
        if (variante_clave((string)$x['color'], (string)$x['medida']) === $clave) {
            return ['ok' => false, 'error' => 'Ese modelo o color ya está en la lista.'];
        }
    }

    /* Dos personas añadiendo el mismo color a la vez: la segunda se encuentra
       con la clave única del código. Se le contesta lo mismo que al repetido de
       arriba, no una pantalla de error. */
    try {
        insertar('variantes', ['producto_id' => $producto_id, 'sku' => variante_sku_nuevo((string)$p['sku'], $color, $medida),
                               'color' => $color ?: null, 'medida' => $medida ?: null, 'activo' => 1]);
    } catch (Throwable $ex) {
        if (es_choque_de_unico($ex)) return ['ok' => false, 'error' => 'Ese modelo o color ya está en la lista.'];
        throw $ex;
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Apaga o enciende una variante. No se borra nunca: una venta anterior la
 * nombra, y borrarla dejaría esa venta hablando de algo que no existe.
 */
function variante_encender(int $variante_id, bool $encendida): void
{
    if (!tabla_existe('variantes')) return;
    actualizar('variantes', $variante_id, ['activo' => $encendida ? 1 : 0]);
}

/**
 * GUARDA LA TABLA DE TRAMOS ENTERA de un producto (o de una variante).
 *
 * Se guarda entera y no tramo a tramo porque lo que tiene que cuadrar es el
 * conjunto: sin huecos, sin solapes, y el último sin «hasta» —de ahí para
 * arriba—, que es el agujero clásico de «puse hasta 30 y alguien pidió 31».
 */
function precios_guardar(int $producto_id, ?int $variante_id, array $filas, ?int $lote_id = null): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e];
    /* Los de un lote (3h) se comprueban por el lote —cuyo país ya comprobó
       quien llama—: Dirección pone precios a un lote de otro país. */
    $existe = $lote_id === null ? (bool) producto_de($producto_id)
            : (bool) valor('SELECT 1 FROM lote_lineas WHERE lote_id = ? AND producto_id = ?', [$lote_id, $producto_id]);
    if (!$existe) return $mal('Ese producto no existe.');

    $limpias = [];
    foreach ($filas as $f) {
        $desde  = (int)($f['desde'] ?? 0);
        $hasta_t = trim((string)($f['hasta'] ?? ''));
        $precio = a_centimos(trim((string)($f['precio'] ?? '')));
        $alias  = mb_substr(trim((string)($f['alias'] ?? '')), 0, 60);
        if ($desde <= 0 && $hasta_t === '' && $precio === null) continue;       // fila vacía
        if ($desde <= 0) return $mal('El «desde» de cada tramo es 1 o más.');
        if ($precio === null || $precio <= 0) return $mal('Falta el precio de un tramo, o no se entiende.');
        if (!dinero_razonable($precio)) return $mal('Ese precio no puede ser real.');
        $hasta = $hasta_t === '' ? null : (int)$hasta_t;
        if ($hasta !== null && $hasta < $desde) return $mal('Un tramo no puede terminar antes de empezar.');
        $limpias[] = ['desde' => $desde, 'hasta' => $hasta, 'precio' => $precio, 'alias' => $alias];
    }
    if (!$limpias) return $mal('Pon al menos un tramo de precio: sin precio, el producto no se puede vender.');

    usort($limpias, fn($a, $b) => $a['desde'] <=> $b['desde']);
    if ((int)$limpias[0]['desde'] !== 1) return $mal('El primer tramo empieza en 1.');
    $n = count($limpias);
    foreach ($limpias as $i => $t) {
        $ultimo = $i === $n - 1;
        if ($ultimo) continue;
        if ($t['hasta'] === null) return $mal('Solo el último tramo puede quedarse sin «hasta».');
        if ((int)$limpias[$i + 1]['desde'] !== (int)$t['hasta'] + 1) {
            return $mal('Los tramos tienen que ir seguidos: después de ' . $t['hasta']
                      . ' viene ' . ((int)$t['hasta'] + 1) . '.');
        }
    }
    $limpias[$n - 1]['hasta'] = null;      // el último siempre es «de ahí para arriba»

    en_transaccion(function () use ($producto_id, $variante_id, $limpias, $lote_id) {
        /* $lote_id: los precios de la pre venta de ese lote (3h); null = tienda. */
        $sql = 'DELETE FROM precios WHERE producto_id = ? AND '
             . ($lote_id === null ? 'lote_id IS NULL' : 'lote_id = ?') . ' AND '
             . ($variante_id === null ? 'variante_id IS NULL' : 'variante_id = ?');
        $par = [$producto_id];
        if ($lote_id !== null) $par[] = $lote_id;
        if ($variante_id !== null) $par[] = $variante_id;
        q($sql, $par);
        foreach ($limpias as $t) {
            insertar('precios', [
                'producto_id' => $producto_id, 'variante_id' => $variante_id, 'lote_id' => $lote_id,
                'desde' => $t['desde'], 'hasta' => $t['hasta'], 'alias' => $t['alias'] ?: null,
                'precio_centimos' => $t['precio'], 'activo' => 1,
            ]);
        }
    });
    /* Qué precios quedaron, no cuántos: el día que alguien pregunte «¿desde
       cuándo cuesta 545?» la respuesta tiene que estar en la bitácora. */
    bitacora('catalogo.precios', 'producto', $producto_id, [
        'variante' => $variante_id, 'lote' => $lote_id,
        'tramos'   => array_map(fn($t) => $t['desde'] . '-' . ($t['hasta'] ?? '') . ':' . soles($t['precio'], false), $limpias),
    ]);
    return ['ok' => true, 'error' => ''];
}

/** Cuántos productos hay sin precio: es el aviso de la pantalla del catálogo. */
function catalogo_sin_precio(): int
{
    if (!tabla_existe('productos')) return 0;
    /* Un repuesto sin precio no es un olvido: solo sale por garantía (3i). */
    $no_rep = columna_existe('productos', 'padre_id') ? ' AND p.padre_id IS NULL' : '';
    return (int) valor("SELECT COUNT(*) FROM productos p WHERE p.activo = 1 AND " . sql_producto_de_mi_pais() . " AND NOT " . sql_producto_solo_preventa('p') . $no_rep . " 
                          AND NOT EXISTS (SELECT 1 FROM precios x WHERE x.producto_id = p.id
                                            AND x.activo = 1 AND x.lote_id IS NULL)");
}
