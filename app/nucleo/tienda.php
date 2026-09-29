<?php
declare(strict_types=1);

/**
 * LA TIENDA (compraenwaka, WooCommerce) — módulo 3b, primera parte.
 * Lo pidió el usuario el 2026-09-23/24.
 *
 * QUÉ HACE: leer el catálogo de la tienda por su API REST, con claves de SOLO
 * LECTURA, y llevar sus productos y sus colores o modelos al catálogo de aquí.
 * No escribe nada en la tienda: con esas claves no podría aunque quisiera.
 *
 * DECISIONES DEL USUARIO (2026-09-24, en tarjetas):
 *   · Se empareja por CÓDIGO (el SKU nativo). «COD: PRD-016782» se lee como
 *     PRD-016782: en la tienda hay de las dos formas.
 *   · Un código que no es PRD (NEL-603) entra con su código tal cual.
 *   · El producto padre lleva el PRD y cada color o modelo el suyo.
 *   · MANDA LA TIENDA en el nombre y la categoría. Los precios por cantidad y la
 *     garantía se ponen aquí y la tienda NUNCA los toca.
 *   · UNA EXCEPCIÓN (usuario, 2026-09-24): un producto SIN NINGÚN PRECIO —el
 *     que se crea ahora, o uno que ya estaba sin precio, como los que trajo la
 *     3b— recibe el precio NORMAL de la web (no el de oferta) como único
 *     tramo, «1 a más», y ya se puede vender. Si la web no tiene precio, se
 *     queda sin precio. Un producto con algún precio puesto aquí no recibe
 *     NUNCA uno de la web.
 *   · LA FOTO PRINCIPAL (3b.3): se baja UNA vez, se achica a miniatura (unos
 *     10 KB) y queda en uploads/productos. Solo se vuelve a bajar si en la
 *     tienda cambia. Nunca se le mandan las claves al servidor de las fotos.
 *   · Los códigos que se generan solos aquí empiezan por WK-, no por PRD-: así
 *     un producto dado de alta a mano nunca se «funde» con uno de la tienda
 *     que casualmente tenga el mismo número (auditoría del 3b).
 *
 * LA REGLA QUE SOSTIENE LA PANTALLA: lo que se enseña antes de guardar es lo que
 * se guarda. Por eso hay UNA sola función que decide qué pasa con cada producto
 * —tienda_plan()—, la llaman la vista previa y el guardado, y el guardado se
 * niega si al recalcular sale un plan distinto del que se enseñó (no solo otras
 * cifras: otra FIRMA, que cubre cada producto y cada cambio).
 *
 * Y ES TODO O NADA: si algo falla a mitad, no queda medio catálogo importado.
 *
 * LA TIENDA ES DE UN PAÍS. La conexión se guarda con el país de quien la puso,
 * y solo ese país lee y guarda: los códigos son únicos entre países, y una
 * importación hecha desde México se quedaba con los productos de Perú.
 */

/* ───────────────────────── Configuración ───────────────────────── */

/**
 * La dirección de la tienda, limpia: solo https, sin barra final y sin lo que
 * venga detrás de /wp-json (la gente pega la ruta de la API entera). Vacío si
 * no vale.
 */
function tienda_ruta_limpia(string $ruta): string
{
    $ruta = trim($ruta);
    if ($ruta === '') return '';
    $p = stripos($ruta, '/wp-json');
    if ($p !== false) $ruta = substr($ruta, 0, $p);
    $ruta = rtrim($ruta, '/');
    if (!preg_match('~^https://[a-z0-9.\-]+(:\d+)?(/[^\s?#]*)?$~i', $ruta)) return '';
    return $ruta;
}

function tienda_config(): array
{
    $cfg = [
        'ruta'   => tienda_ruta_limpia((string) ajuste('woo_url', '')),
        'key'    => trim((string) ajuste('woo_key', '')),
        'secret' => trim((string) ajuste('woo_secret', '')),
        'pais'   => (int) ajuste('woo_pais', 0),
    ];
    $cfg['listo'] = $cfg['ruta'] !== '' && $cfg['key'] !== '' && $cfg['secret'] !== '';
    return $cfg;
}

/**
 * ¿Puedo leer y guardar la tienda desde mi país? La conexión es de un país: el
 * de quien la puso. Sin país apuntado todavía (una conexión anterior a esta
 * regla) vale para quien la use primero.
 */
function tienda_es_de_mi_pais(?array $cfg = null, ?int $pais = null): bool
{
    $cfg ??= tienda_config();
    return $cfg['pais'] === 0 || $cfg['pais'] === ($pais ?? catalogo_pais());
}

/** Las claves de WooCommerce empiezan por ck_ y cs_. Se dice antes de guardar. */
function tienda_clave_valida(string $clave, string $prefijo): bool
{
    return (bool) preg_match('/^' . $prefijo . '_[A-Za-z0-9]{20,80}$/', $clave);
}

/* ───────────────────────── La llamada ───────────────────────── */

/**
 * EL PLAZO DE UNA LECTURA. Se fija al empezar a leer; cada pedido a la tienda
 * espera como mucho lo que quede (y nunca más de 40 s). Sin esto, el último
 * pedido podía pasarse del plazo otros cuarenta segundos.
 */
function tienda_plazo(?float $hasta = null): float
{
    static $fin = 0.0;
    if ($hasta !== null) $fin = $hasta;
    return $fin;
}

function tienda_segundos_por_pedido(): int
{
    $fin = tienda_plazo();
    if ($fin <= 0) return 40;
    return (int) max(5, min(40, ceil($fin - microtime(true))));
}

/**
 * Un pedido a la tienda con cURL, listo para lanzarse solo o junto a otros.
 * Nunca se salta a http: las claves irían en claro.
 */
function tienda_curl(string $url, string $key, string $secret, string $modo, array &$cab)
{
    if ($modo === 'consulta') {
        $url .= (str_contains($url, '?') ? '&' : '?')
              . http_build_query(['consumer_key' => $key, 'consumer_secret' => $secret]);
    }
    $ch = curl_init($url);
    $op = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => tienda_segundos_por_pedido(),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Waka/1.0',
        CURLOPT_HEADERFUNCTION => function ($c, string $linea) use (&$cab) {
            $p = strpos($linea, ':');
            if ($p !== false) $cab[strtolower(trim(substr($linea, 0, $p)))] = trim(substr($linea, $p + 1));
            return strlen($linea);
        },
    ];
    if (defined('CURLPROTO_HTTPS')) {
        $op[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        $op[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($modo === 'basica') {
        $op[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $op[CURLOPT_USERPWD]  = $key . ':' . $secret;
    }
    curl_setopt_array($ch, $op);
    return $ch;
}

/** Lo que contestó la tienda, en la forma que usa el resto del archivo. */
function tienda_curl_resultado($ch, mixed $txt, array $cab): array
{
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($txt === false || ($txt === null && $err !== '')) {
        return ['http' => $http, 'cuerpo' => null, 'total' => null, 'paginas' => null,
                'red' => $err !== '' ? $err : 'Sin respuesta'];
    }
    $j = json_decode((string)$txt, true);
    return ['http' => $http, 'cuerpo' => is_array($j) ? $j : null,
            'total'   => isset($cab['x-wp-total']) ? (int)$cab['x-wp-total'] : null,
            'paginas' => isset($cab['x-wp-totalpages']) ? (int)$cab['x-wp-totalpages'] : null,
            'red' => ''];
}

/**
 * Cómo se habla con la tienda: un GET, y la respuesta en
 * ['http','cuerpo','total','paginas','red'] (`red` no vacío = no contestó).
 *
 * Se puede sustituir con `$GLOBALS['__tienda_transporte']`, igual que
 * NUBEFACT: el banco de pruebas no puede llamar a la tienda de verdad.
 *
 * $modo: 'basica' manda las claves en la cabecera; 'consulta' en la dirección.
 * Algunos alojamientos se comen la cabecera de autorización; WooCommerce
 * acepta las dos formas por https, así que se prueba una y luego la otra.
 */
function tienda_transporte(): callable
{
    if (isset($GLOBALS['__tienda_transporte']) && is_callable($GLOBALS['__tienda_transporte'])) {
        return $GLOBALS['__tienda_transporte'];
    }
    return function (string $url, string $key, string $secret, string $modo): array {
        if (!function_exists('curl_init')) {
            return ['http' => 0, 'cuerpo' => null, 'total' => null, 'paginas' => null,
                    'red' => 'Este servidor no tiene cURL.'];
        }
        $cab = [];
        $ch = tienda_curl($url, $key, $secret, $modo, $cab);
        $txt = curl_exec($ch);
        $r = tienda_curl_resultado($ch, $txt, $cab);
        curl_close($ch);
        return $r;
    };
}

/**
 * VARIOS pedidos a la vez. Leer una tienda con trescientos productos con
 * colores es un pedido por producto: uno detrás de otro, en un alojamiento
 * compartido, pasaba del tiempo que el servidor deja a una página. De a seis
 * en paralelo tarda una sexta parte. Con el transporte de pruebas, se piden
 * de uno en uno.
 */
function tienda_llamar_varios(array $urls, string $key, string $secret, string $modo): array
{
    if (isset($GLOBALS['__tienda_transporte']) || !function_exists('curl_multi_init')) {
        $t = tienda_transporte();
        return array_map(fn($u) => $t($u, $key, $secret, $modo), $urls);
    }
    $out = [];
    foreach (array_chunk($urls, 6, true) as $tanda) {
        $mh = curl_multi_init();
        $hs = $cabs = [];
        foreach ($tanda as $i => $u) {
            $cabs[$i] = [];
            $hs[$i] = tienda_curl($u, $key, $secret, $modo, $cabs[$i]);
            curl_multi_add_handle($mh, $hs[$i]);
        }
        /* En paralelo, el error de cada pedido (no conectó, se cortó) no está
           en curl_errno: llega como mensaje de la tanda, y hay que leerlo. */
        $fallo = [];
        do {
            $st = curl_multi_exec($mh, $activos);
            if ($activos) curl_multi_select($mh, 1.0);
            while ($msg = curl_multi_info_read($mh)) {
                if ($msg['result'] !== CURLE_OK) $fallo[spl_object_id($msg['handle'])] = curl_strerror($msg['result']);
            }
        } while ($activos && $st === CURLM_OK);
        foreach ($hs as $i => $ch) {
            $err = $fallo[spl_object_id($ch)] ?? '';
            $out[$i] = $err !== ''
                ? ['http' => 0, 'cuerpo' => null, 'total' => null, 'paginas' => null, 'red' => $err]
                : tienda_curl_resultado($ch, curl_multi_getcontent($ch), $cabs[$i]);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    ksort($out);
    return $out;
}

/** La dirección completa de una ruta de la API de WooCommerce (v3). */
function tienda_url(array $cfg, string $camino, array $params = []): string
{
    $url = $cfg['ruta'] . '/wp-json/wc/v3/' . ltrim($camino, '/');
    return $params ? $url . '?' . http_build_query($params) : $url;
}

/** La forma de mandar las claves que funcionó al probar. */
function tienda_modo(): string
{
    return (string) ajuste('woo_auth', 'basica') === 'consulta' ? 'consulta' : 'basica';
}

/** Pide una ruta de la API con la configuración guardada. */
function tienda_llamar(string $camino, array $params = [], ?string $modo = null, ?array $cfg = null): array
{
    $cfg ??= tienda_config();
    return (tienda_transporte())(tienda_url($cfg, $camino, $params), $cfg['key'], $cfg['secret'], $modo ?? tienda_modo());
}

/** Lo que salió mal, dicho para quien administra y no para un programador. */
function tienda_error_texto(array $r): string
{
    if (($r['red'] ?? '') !== '') {
        return 'No hubo respuesta de la tienda. Revisa la dirección y vuelve a intentarlo en un momento.';
    }
    $http = (int)($r['http'] ?? 0);
    return match (true) {
        $http === 401, $http === 403 => 'La tienda no aceptó las claves. Cópialas otra vez desde WooCommerce.',
        $http === 404                => 'No encuentro la tienda en esa dirección. Revisa que sea la de compraenwaka.',
        $http >= 500                 => 'La tienda tuvo un problema al contestar. Vuelve a intentarlo en un momento.',
        default                      => 'La tienda contestó algo que no se entiende. Revisa la dirección.',
    };
}

/** ¿Es una respuesta buena de una lista? (y no una página de WordPress, ni un error) */
function tienda_respuesta_lista(array $r): bool
{
    return ($r['red'] ?? '') === '' && (int)$r['http'] === 200
        && is_array($r['cuerpo']) && array_is_list($r['cuerpo']);
}

/**
 * PROBAR LA CONEXIÓN. Pide un solo producto publicado: con eso se sabe si la
 * dirección es una tienda, si las claves valen y cuántos productos tiene. Se
 * queda con la forma de mandar las claves que funcionó. Si falla, la conexión
 * deja de figurar como probada.
 */
function tienda_probar(): array
{
    $cfg = tienda_config();
    if (!$cfg['listo']) return ['ok' => false, 'texto' => 'Falta la dirección o alguna de las dos claves.'];
    if (!tienda_es_de_mi_pais($cfg)) return ['ok' => false, 'texto' => 'La tienda está conectada para otro país.'];

    $ultimo = null;
    foreach (['basica', 'consulta'] as $modo) {
        $r = tienda_llamar('products', ['per_page' => 1, 'status' => 'publish', '_fields' => 'id'], $modo, $cfg);
        $ultimo = $r;
        if (($r['red'] ?? '') !== '') break;
        if (in_array((int)$r['http'], [401, 403], true)) continue;   // se prueba la otra forma
        if (!tienda_respuesta_lista($r)) break;
        guardar_ajuste('woo_auth', $modo);
        guardar_ajuste('woo_probada_en', date('Y-m-d H:i:s'));
        ajustes_olvidar();
        $n = $r['total'];
        return ['ok' => true, 'texto' => 'Conectada con la tienda'
              . ($n !== null ? ': tiene ' . plural((int)$n, 'producto publicado', 'productos publicados') . '.' : '.')];
    }
    if ((string) ajuste('woo_probada_en', '') !== '') {
        guardar_ajuste('woo_probada_en', '');
        ajustes_olvidar();
    }
    return ['ok' => false, 'texto' => tienda_error_texto($ultimo ?? [])];
}

/* ───────────────────────── Los códigos ───────────────────────── */

/**
 * El código de la tienda, limpio. En compraenwaka hay «PRD-010437» y también
 * «COD: PRD-016782»; los dos son el mismo código. Se quita el «COD:» (o
 * «CÓDIGO:», «SKU:») del principio, los espacios y se pasa a mayúsculas.
 */
function tienda_sku_limpio(string $crudo): string
{
    $s = html_entity_decode($crudo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = mb_strtoupper(trim($s));
    /* Solo si detrás viene «:», «.», «#» o un espacio: «CODEX-1» es un código
       que empieza por COD, no un «COD:» delante. */
    $s = preg_replace('/^(C[OÓ]D(IGO)?|SKU)(\s*[:.#]\s*|\s+)/u', '', $s) ?? $s;
    $s = preg_replace('/\s+/u', '', $s) ?? $s;
    return $s;
}

/* ───────────────────────── Leer la tienda ───────────────────────── */

/**
 * Lo que dice la tienda en un texto: sin entidades HTML, sin etiquetas y sin
 * espacios de más. Solo se quitan etiquetas HTML conocidas («<b>», «</span>»):
 * strip_tags se comía nombres reales como «Cable HDMI <2m> 4K», y una regla
 * más general, tallas como «<M>» o «<XL>».
 */
function tienda_texto(mixed $t, int $max): string
{
    $t = preg_replace('~</?(a|b|i|u|s|em|strong|span|br|p|div|small|big|sup|sub|font|mark|h[1-6]|ul|ol|li)(\s[^<>]*)?/?>~i',
                      '', (string)$t) ?? (string)$t;
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    return mb_substr($t, 0, $max);
}

/**
 * El color y el modelo de una variación, a partir de sus atributos. El
 * atributo que se llama «color» va a Color; el resto (talla, medida, modelo…)
 * se junta en el otro campo.
 */
function tienda_atributos(array $atributos): array
{
    $color = '';
    $otros = [];
    foreach ($atributos as $a) {
        $nombre = tienda_texto($a['name'] ?? '', 60);
        $valor  = tienda_texto($a['option'] ?? '', 60);
        if ($valor === '') continue;
        if ($color === '' && preg_match('/colou?r/iu', $nombre)) $color = $valor;
        else $otros[] = $valor;
    }
    return ['color' => $color, 'medida' => mb_substr(implode(' / ', $otros), 0, 60)];
}

/**
 * EL PRECIO NORMAL DE LA WEB, en céntimos, o null si no tiene o no se entiende.
 * El normal y no el de oferta: las ofertas de la web son de unos días, y el
 * tramo que nace de aquí se queda (usuario, 2026-09-24).
 */
function tienda_precio_web(array $x): ?int
{
    $c = a_centimos(trim((string)($x['regular_price'] ?? '')));
    return ($c !== null && $c > 0 && dinero_razonable($c)) ? $c : null;
}

/**
 * La foto principal de un producto de la tienda: su número y su dirección, o
 * null. Solo https: la foto la baja el servidor, y no se le deja pedir nada
 * por http ni fuera de la web.
 */
function tienda_foto_de(array $p): ?array
{
    $img = $p['images'][0] ?? null;
    if (!is_array($img)) return null;
    $id  = (int)($img['id'] ?? 0);
    $src = trim((string)($img['src'] ?? ''));
    if ($id <= 0 || !preg_match('~^https://[a-z0-9.\-]+(:\d+)?/\S+$~i', $src)) return null;   // ni http, ni usuario@
    return ['id' => $id, 'src' => $src];
}

/** Una fila de producto de la tienda, reducida a lo que se usa aquí. */
function tienda_fila_producto(array $p): array
{
    $cat = '';
    $todas = [];
    foreach (($p['categories'] ?? []) as $c) {
        $n = tienda_texto($c['name'] ?? '', 80);
        /* «Sin categorizar» es la que WooCommerce pone sola: no es una categoría. */
        if ($n !== '' && !preg_match('/^(sin categorizar|uncategori[sz]ed)$/iu', $n)) {
            if ($cat === '') $cat = $n;
            $todas[] = $n;
        }
    }
    return [
        'woo_id'      => (int)($p['id'] ?? 0),
        'nombre'      => tienda_texto($p['name'] ?? '', 180),
        'categoria'   => $cat,
        /* Todas, para la lista de categorías del catálogo (3f). */
        'categorias'  => $todas,
        'sku_crudo'   => tienda_texto($p['sku'] ?? '', 80),
        'sku'         => tienda_sku_limpio((string)($p['sku'] ?? '')),
        'tipo'        => (string)($p['type'] ?? 'simple'),
        'precio'      => tienda_precio_web($p),
        'foto'        => tienda_foto_de($p),
        'stock'       => tienda_stock_de($p),
        'variaciones' => [],
    ];
}

/**
 * Una variación de la tienda, reducida a lo que se usa aquí.
 *
 * OJO: WooCommerce devuelve el código DEL PADRE en cada variación que no tiene
 * código propio. Eso no es un código repetido: es «sin código propio», y así
 * se trata.
 */
function tienda_fila_variacion(array $v, string $sku_padre = '', ?array $stock_padre = null): array
{
    $at  = tienda_atributos((array)($v['attributes'] ?? []));
    $sku = tienda_sku_limpio((string)($v['sku'] ?? ''));
    if ($sku !== '' && $sku === $sku_padre) $sku = '';
    return [
        'woo_id'    => (int)($v['id'] ?? 0),
        'sku_crudo' => $sku === '' ? '' : tienda_texto($v['sku'] ?? '', 80),
        'sku'       => $sku,
        'color'     => $at['color'],
        'medida'    => $at['medida'],
        'precio'    => tienda_precio_web($v),
        'stock'     => tienda_stock_de($v, $stock_padre),
    ];
}

/**
 * EL STOCK DE LA WEB de un producto o de una variación (3b.5):
 *   ['cantidad' => int|null, 'estado' => 'instock'|'outofstock'|'onbackorder']
 * `cantidad` es null cuando la web no cuenta unidades de ese producto (en
 * WooCommerce, «Gestionar inventario» apagado): entonces solo vale el estado.
 * Una variación que hereda el inventario del producto («parent») toma el de él.
 * Devuelve null si la tienda no mandó nada de stock.
 */
function tienda_stock_de(array $x, ?array $padre = null): ?array
{
    if (!array_key_exists('stock_status', $x) && !array_key_exists('manage_stock', $x)) return null;
    $ms = $x['manage_stock'] ?? false;
    /* Heredado: las variaciones comparten las unidades del producto. Se marca
       para no sumarlas una vez por color (con 3 colores y 10 unidades, salía
       «30 en stock»: auditoría del 3b.5). */
    if ($ms === 'parent') return $padre === null ? null : $padre + ['compartido' => true];
    $estado = in_array($x['stock_status'] ?? '', ['instock', 'outofstock', 'onbackorder'], true)
            ? (string)$x['stock_status'] : 'instock';
    $cuenta = $ms === true || $ms === 1 || $ms === '1';
    $q = $x['stock_quantity'] ?? null;
    $cantidad = ($cuenta && is_numeric($q)) ? max(-999999, min(999999, (int)$q)) : null;
    return ['cantidad' => $cantidad, 'estado' => $estado];
}

/** Cuánto tiempo se le da a una lectura entera antes de rendirse. */
const TIENDA_SEGUNDOS = 240;

/**
 * Trae de la tienda todas las páginas de una ruta. Devuelve la lista o un
 * error; nunca media lista: un catálogo leído a medias enseñaría productos
 * «que faltan» que en realidad están.
 *
 * $primera: la primera página, si ya se pidió (las variaciones se piden de a
 * varias a la vez y aquí solo se sigue con las que tengan más de una página).
 */
function tienda_todas_las_paginas(string $camino, array $params, ?array $primera = null, float $hasta = 0.0): array
{
    $todo = [];
    $max  = 60;                                          // 6.000 filas: de sobra
    for ($pag = 1; $pag <= $max; $pag++) {
        if ($hasta > 0 && microtime(true) > $hasta) {
            return ['ok' => false, 'error' => 'La tienda tardó demasiado en contestar. Vuelve a intentarlo en un rato.', 'filas' => []];
        }
        $r = ($pag === 1 && $primera !== null) ? $primera
           : tienda_llamar($camino, $params + ['per_page' => 100, 'page' => $pag]);
        /* Sin la cabecera del número de páginas se sigue hasta una página corta;
           si el catálogo es justo múltiplo de 100, la página de más contesta 400
           («no hay esa página»): eso es el final, no un error. */
        if ($pag > 1 && $r['paginas'] === null && (int)$r['http'] === 400 && ($r['red'] ?? '') === '') {
            return ['ok' => true, 'error' => '', 'filas' => $todo];
        }
        if (!tienda_respuesta_lista($r)) {
            return ['ok' => false, 'error' => tienda_error_texto($r), 'filas' => []];
        }
        foreach ($r['cuerpo'] as $x) if (is_array($x)) $todo[] = $x;
        $paginas = $r['paginas'];
        if ($paginas !== null ? $pag >= $paginas : count($r['cuerpo']) < 100) {
            return ['ok' => true, 'error' => '', 'filas' => $todo];
        }
    }
    return ['ok' => false, 'error' => 'La tienda tiene más productos de los que se pueden leer de una vez.', 'filas' => []];
}

/**
 * LEE EL CATÁLOGO PUBLICADO de la tienda: productos y, de los que tienen
 * colores o modelos, sus variaciones publicadas.
 */
function tienda_leer(?int $pais = null): array
{
    $cfg = tienda_config();
    if (!$cfg['listo']) return ['ok' => false, 'error' => 'Falta conectar la tienda en Configuración › Tienda.', 'filas' => []];
    if (!tienda_es_de_mi_pais($cfg, $pais)) return ['ok' => false, 'error' => 'La tienda está conectada para otro país.', 'filas' => []];
    tiempo_extra(TIENDA_SEGUNDOS + 60);
    $hasta = tienda_plazo(microtime(true) + TIENDA_SEGUNDOS);

    $r = tienda_todas_las_paginas('products', [
        'status' => 'publish', 'orderby' => 'id', 'order' => 'asc',
        '_fields' => 'id,name,sku,type,categories,regular_price,images,manage_stock,stock_quantity,stock_status',
    ], null, $hasta);
    if (!$r['ok']) return $r;

    $filas = [];
    foreach ($r['filas'] as $p) {
        $f = tienda_fila_producto($p);
        if ($f['woo_id'] > 0) $filas[] = $f;
    }

    /* Las variaciones: la primera página de todas, de a varias a la vez. */
    $pvar = ['status' => 'publish', '_fields' => 'id,sku,attributes,regular_price,manage_stock,stock_quantity,stock_status'];
    $variables = array_keys(array_filter($filas, fn($f) => $f['tipo'] === 'variable'));
    foreach (array_chunk($variables, 30) as $tanda) {
        if (microtime(true) > $hasta) {
            return ['ok' => false, 'error' => 'La tienda tardó demasiado en contestar. Vuelve a intentarlo en un rato.', 'filas' => []];
        }
        $urls = [];
        foreach ($tanda as $i) {
            $urls[$i] = tienda_url($cfg, 'products/' . $filas[$i]['woo_id'] . '/variations', $pvar + ['per_page' => 100, 'page' => 1]);
        }
        $primeras = tienda_llamar_varios($urls, $cfg['key'], $cfg['secret'], tienda_modo());
        foreach ($tanda as $i) {
            $rv = tienda_todas_las_paginas('products/' . $filas[$i]['woo_id'] . '/variations', $pvar, $primeras[$i], $hasta);
            if (!$rv['ok']) return $rv;
            foreach ($rv['filas'] as $v) {
                $fv = tienda_fila_variacion($v, $filas[$i]['sku'], $filas[$i]['stock']);
                if ($fv['woo_id'] > 0) $filas[$i]['variaciones'][] = $fv;
            }
        }
    }
    /* La lista de categorías de la web, para elegir en la ficha (3f). Nunca
       tumba la lectura. */
    try { categorias_web_guardar($filas); } catch (Throwable $ex) { error_log('[HUB] categorías: ' . $ex->getMessage()); }
    return ['ok' => true, 'error' => '', 'filas' => array_values($filas)];
}

/* ───────────────────────── El plan ───────────────────────── */

/**
 * QUÉ PASA CON CADA PRODUCTO DE LA TIENDA. La única definición.
 *
 * No toca la base: solo la lee. Devuelve
 *   'productos' => [ ['accion' => crear|actualizar|igual, 'fila', 'id', 'cambios', 'variantes' => [...]] ]
 *   'fuera'     => [ ['nombre', 'codigo', 'motivo'] ]   lo que NO entra y por qué
 *   'resumen'   => los números que se enseñan, y la firma del plan entero,
 *                  que el guardado vuelve a comprobar.
 *
 * El emparejado de un producto, en este orden:
 *   1. por su número en la tienda (si ya se importó antes);
 *   2. por el código.
 * El de un color, en DOS PASADAS: primero todos los que se reconocen por su
 * número o su código; después, solo con lo que sobre, por el nombre del color.
 * Si no, el orden en que la tienda lista los colores decidía cuál se llevaba
 * el color de aquí.
 *
 * Si dos datos dicen cosas distintas, no se adivina: sale en «no entran» con
 * el motivo, para que alguien lo mire.
 */
function tienda_plan(array $filas, int $pais_id): array
{
    $plan = ['productos' => [], 'fuera' => []];
    $fuera = function (string $nombre, string $codigo, string $motivo) use (&$plan) {
        $plan['fuera'][] = ['nombre' => $nombre, 'codigo' => $codigo, 'motivo' => $motivo];
    };
    $enlazado = fn(array $x) => $x['woo_id'] !== null && $x['woo_id'] !== '';

    /* Lo que ya hay aquí, cargado una vez. */
    $porWoo = $porSku = [];
    /* ORDER BY: el plan tiene que salir igual al leer y al guardar. */
    $colFoto = columna_existe('productos', 'foto_woo');
    foreach (todas('SELECT id, sku, nombre, categoria, woo_id, pais_id'
                 . ($colFoto ? ', foto_woo' : ', NULL AS foto_woo') . ' FROM productos ORDER BY id') as $p) {
        $porSku[(string)$p['sku']] = $p;
        if ($enlazado($p)) $porWoo[(int)$p['woo_id']] = $p;
    }
    /* Los productos que ya tienen algún precio puesto aquí: esos no reciben
       nunca el de la web. */
    $conPrecio = [];
    foreach (todas('SELECT DISTINCT producto_id FROM precios WHERE activo = 1 AND lote_id IS NULL ORDER BY producto_id') as $x) {
        $conPrecio[(int)$x['producto_id']] = true;
    }
    /* MANDA EL HUB (3c, usuario 2026-09-25): con el conector puesto, el nombre,
       el código y el precio de un producto YA ENLAZADO se cambian aquí. Lo que
       la web diga distinto no se aplica: se enseña en «difiere» para decidir. */
    $manda_hub = conector_listo();
    $precio1 = [];                       // [producto][variante o 0] => precio de 1 unidad aquí
    if ($manda_hub) {
        foreach (todas('SELECT producto_id, variante_id, precio_centimos FROM precios
                         WHERE activo = 1 AND lote_id IS NULL AND desde = 1 ORDER BY id') as $x) {
            $precio1[(int)$x['producto_id']][(int)($x['variante_id'] ?? 0)] = (int)$x['precio_centimos'];
        }
    }
    $vWoo = $vSku = $vCombo = $vPorProducto = [];
    $vActiva = [];
    foreach (todas('SELECT id, producto_id, sku, color, medida, woo_id, activo FROM variantes ORDER BY id') as $v) {
        $vActiva[(int)$v['id']] = (int)$v['activo'] === 1;
        $vPorProducto[(int)$v['producto_id']][] = $v;
        $vSku[(string)$v['sku']] = $v;
        if ($enlazado($v)) $vWoo[(int)$v['woo_id']] = $v;
        $vCombo[(int)$v['producto_id'] . '|' . variante_clave((string)$v['color'], (string)$v['medida'])] = $v;
    }

    /* Lo que está en la tienda ahora, y los códigos que ya tienen dueño. */
    $cuenta = $cuentaV = $wooLeidos = $wooVarLeidos = [];
    foreach ($filas as $f) {
        $wooLeidos[$f['woo_id']] = true;
        if ($f['sku'] !== '') $cuenta[$f['sku']] = ($cuenta[$f['sku']] ?? 0) + 1;
        foreach ($f['variaciones'] as $fv) {
            $wooVarLeidos[$fv['woo_id']] = true;
            if ($fv['sku'] !== '') $cuentaV[$fv['sku']] = ($cuentaV[$fv['sku']] ?? 0) + 1;
        }
    }
    /* Los códigos de color que NO se pueden generar: los que ya existen aquí y
       los que trae la tienda. Un código generado que choca con uno de la tienda
       tumbaba el guardado entero, una y otra vez (auditoría del 3b). */
    $reservados = $cuentaV + $cuenta;
    $usadas = [];                            // colores de aquí que ya tienen su variación

    foreach ($filas as $f) {
        $nombre = $f['nombre'] !== '' ? $f['nombre'] : '(sin nombre)';
        if (!in_array($f['tipo'], ['simple', 'variable'], true)) {
            $fuera($nombre, $f['sku_crudo'], 'Es un paquete o un enlace a otra web: no se vende suelto.');
            continue;
        }
        if ($f['sku'] === '') { $fuera($nombre, '', 'No tiene código en la tienda.'); continue; }
        if (!sku_valido($f['sku'])) {
            $fuera($nombre, $f['sku_crudo'], 'El código tiene caracteres que no valen: solo letras, números y guiones.');
            continue;
        }
        if (($cuenta[$f['sku']] ?? 0) > 1) {
            $fuera($nombre, $f['sku_crudo'], 'Hay otro producto con el mismo código en la tienda.');
            continue;
        }
        if (mb_strlen($f['nombre']) < 2) { $fuera($nombre, $f['sku_crudo'], 'No tiene nombre en la tienda.'); continue; }

        $aqui = $porWoo[$f['woo_id']] ?? null;
        $difiere = [];
        if ($aqui && (string)$aqui['sku'] !== $f['sku']) {
            if (!$manda_hub) {
                $fuera($nombre, $f['sku_crudo'], 'El código cambió en la tienda: aquí es ' . $aqui['sku'] . '.');
                continue;
            }
            $difiere['codigo'] = [(string)$aqui['sku'], $f['sku_crudo']];
        }
        if (!$aqui) {
            $aqui = $porSku[$f['sku']] ?? null;
            /* Enlazado con otro producto de la tienda: si ese otro SIGUE ahí, son
               dos cosas distintas; si ya no está, se borró y se volvió a crear,
               y se vuelve a enlazar. */
            if ($aqui && $enlazado($aqui) && (int)$aqui['woo_id'] !== $f['woo_id']
                && isset($wooLeidos[(int)$aqui['woo_id']])) {
                $fuera($nombre, $f['sku_crudo'], 'Ese código ya está enlazado a otro producto de la tienda.');
                continue;
            }
        }
        if ($aqui && $aqui['pais_id'] !== null && $aqui['pais_id'] !== '' && (int)$aqui['pais_id'] !== $pais_id) {
            $fuera($nombre, $f['sku_crudo'], 'Ese código es de un producto de otro país.');
            continue;
        }

        $item = ['accion' => $aqui ? 'igual' : 'crear', 'fila' => $f,
                 'id' => $aqui ? (int)$aqui['id'] : null, 'cambios' => [], 'variantes' => [], 'difiere' => []];
        /* Ya enlazado a ESTE producto de la tienda y con el conector: manda el HUB. */
        $es_suyo = $manda_hub && $aqui && $enlazado($aqui) && (int)$aqui['woo_id'] === $f['woo_id'];
        if ($aqui) {
            if ((string)$aqui['nombre'] !== $f['nombre'] && $es_suyo) {
                $difiere['nombre'] = [(string)$aqui['nombre'], $f['nombre']];
                $item['fila']['nombre'] = (string)$aqui['nombre'];      // guardar deja el de aquí
            } elseif ((string)$aqui['nombre'] !== $f['nombre']) {
                $item['cambios']['nombre'] = [(string)$aqui['nombre'], $f['nombre']];
            }
            if ($f['categoria'] !== '' && (string)($aqui['categoria'] ?? '') !== $f['categoria']) {
                $item['cambios']['categoria'] = [(string)($aqui['categoria'] ?? ''), $f['categoria']];
            }
            if (!$enlazado($aqui) || (int)$aqui['woo_id'] !== $f['woo_id']) {
                $item['cambios']['enlace'] = [$enlazado($aqui) ? (string)$aqui['woo_id'] : '', (string)$f['woo_id']];
            }
        }

        /* ── Los colores y modelos ── */
        $vars = [];                          // las que pasan los filtros
        $claves = [];
        foreach ($f['variaciones'] as $fv) {
            $vnom = $nombre . ' · ' . variante_nombre($fv);
            if ($fv['color'] === '' && $fv['medida'] === '') {
                $fuera($vnom, $fv['sku_crudo'], 'La variación no dice su color ni su modelo.');
                continue;
            }
            if ($fv['sku'] !== '' && ($cuentaV[$fv['sku']] ?? 0) > 1) {
                $fuera($vnom, $fv['sku_crudo'], 'Hay otra variación con el mismo código en la tienda.');
                continue;
            }
            if ($fv['sku'] !== '' && (isset($cuenta[$fv['sku']]) || isset($porSku[$fv['sku']]))) {
                $fuera($vnom, $fv['sku_crudo'], 'Ese código es de un producto, no de un color.');
                continue;
            }
            $clave = variante_clave($fv['color'], $fv['medida']);
            if (isset($claves[$clave])) {
                $fuera($vnom, $fv['sku_crudo'], 'El producto tiene ese color o modelo repetido en la tienda.');
                continue;
            }
            $claves[$clave] = true;
            $vars[] = ['fv' => $fv, 'nom' => $vnom, 'clave' => $clave, 'v' => null];
        }

        /* Primera pasada: por su número y por su código. */
        foreach ($vars as $k => $x) {
            $fv = $x['fv'];
            $v = $vWoo[$fv['woo_id']] ?? null;
            if ($v) {
                if (!$aqui || (int)$v['producto_id'] !== (int)$aqui['id']) {
                    $fuera($x['nom'], $fv['sku_crudo'], 'Esa variación ya está enlazada a otro producto de aquí.');
                    unset($vars[$k]); continue;
                }
            } elseif ($fv['sku'] !== '' && isset($vSku[$fv['sku']])) {
                $v = $vSku[$fv['sku']];
                if (!$aqui || (int)$v['producto_id'] !== (int)$aqui['id']) {
                    $fuera($x['nom'], $fv['sku_crudo'], 'Ese código es de un color de otro producto.');
                    unset($vars[$k]); continue;
                }
                if ($enlazado($v) && (int)$v['woo_id'] !== $fv['woo_id'] && isset($wooVarLeidos[(int)$v['woo_id']])) {
                    $fuera($x['nom'], $fv['sku_crudo'], 'Ese código ya está enlazado a otra variación de la tienda.');
                    unset($vars[$k]); continue;
                }
            }
            if ($v) {
                if (isset($usadas[(int)$v['id']])) {
                    $fuera($x['nom'], $fv['sku_crudo'], 'Dos variaciones de la tienda apuntan al mismo color de aquí.');
                    unset($vars[$k]); continue;
                }
                $usadas[(int)$v['id']] = true;
                $vars[$k]['v'] = $v;
            }
        }
        /* Segunda pasada: lo que quedó, por el nombre del color. Solo un color de
           aquí sin reclamar, y cuyo enlace (si lo tiene) ya no está en la tienda. */
        if ($aqui) {
            foreach ($vars as $k => $x) {
                if ($x['v']) continue;
                $cand = $vCombo[(int)$aqui['id'] . '|' . $x['clave']] ?? null;
                if (!$cand || isset($usadas[(int)$cand['id']])) continue;
                if (!$enlazado($cand) || !isset($wooVarLeidos[(int)$cand['woo_id']])) {
                    $usadas[(int)$cand['id']] = true;
                    $vars[$k]['v'] = $cand;
                } else {
                    /* Ese color de aquí sigue enlazado a otra variación que está
                       en la tienda (y que no entró por otro motivo): crear otro
                       igual sería tener dos «Negro» en el mismo producto. */
                    $fuera($x['nom'], $x['fv']['sku_crudo'], 'Ya hay un color que se llama así, enlazado a otra variación de la tienda.');
                    unset($vars[$k]);
                }
            }
        }

        /* Primero los colores que ya existen (se enlazan o se renombran) y
           después los nuevos: un color nuevo no puede llamarse como uno de aquí
           que se QUEDA con ese nombre —porque su cambio de nombre no entró, o
           porque la tienda ya no lo trae—. Si no, el producto acababa con dos
           «Negro» (verificación de los arreglos del 3b). */
        $quedan = [];                        // id de aquí => su clave cuando termine
        if ($aqui) {
            foreach ($vPorProducto[(int)$aqui['id']] ?? [] as $lv) {
                $quedan[(int)$lv['id']] = variante_clave((string)$lv['color'], (string)$lv['medida']);
            }
        }
        foreach ($vars as $x) {
            $fv = $x['fv'];
            $v  = $x['v'];
            if (!$v) continue;
            $cam = [];
            if ((string)$v['color'] !== $fv['color'])   $cam['color']  = [(string)$v['color'], $fv['color']];
            if ((string)$v['medida'] !== $fv['medida']) $cam['medida'] = [(string)$v['medida'], $fv['medida']];
            if (!$enlazado($v) || (int)$v['woo_id'] !== $fv['woo_id']) {
                $cam['enlace'] = [$enlazado($v) ? (string)$v['woo_id'] : '', (string)$fv['woo_id']];
            }
            if ((isset($cam['color']) || isset($cam['medida']))
                && variante_clave((string)$v['color'], (string)$v['medida']) !== $x['clave']) {
                $otra = $vCombo[(int)$v['producto_id'] . '|' . $x['clave']] ?? null;
                if ($otra && (int)$otra['id'] !== (int)$v['id']) {
                    $fuera($x['nom'], $fv['sku_crudo'], 'Ya hay otro color o modelo que se llama así en este producto.');
                    continue;
                }
            }
            $quedan[(int)$v['id']] = $x['clave'];
            $item['variantes'][] = ['accion' => $cam ? 'actualizar' : 'igual', 'fila' => $fv,
                                    'id' => (int)$v['id'], 'sku' => (string)$v['sku'], 'cambios' => $cam];
        }
        $sku_prod = $aqui ? (string)$aqui['sku'] : $f['sku'];
        foreach ($vars as $x) {
            $fv = $x['fv'];
            if ($x['v']) continue;
            if (in_array($x['clave'], $quedan, true)) {
                $fuera($x['nom'], $fv['sku_crudo'], 'Ya hay un color que se llama así en este producto.');
                continue;
            }
            /* El código de la tienda si vale y está libre; si no, uno colgado
               del producto, generado AQUÍ para que la vista previa diga el
               código que de verdad se va a guardar. */
            if ($fv['sku'] !== '' && sku_valido($fv['sku']) && !isset($vSku[$fv['sku']])) {
                $sku = $fv['sku'];
            } else {
                $sku = variante_sku_nuevo($sku_prod, $fv['color'], $fv['medida'], $reservados);
            }
            $reservados[$sku] = true;
            $item['variantes'][] = ['accion' => 'crear', 'fila' => $fv, 'id' => null, 'sku' => $sku, 'cambios' => []];
        }

        /* Un producto con colores en la tienda del que no entra NINGUNO no se
           trae como «Única»: el asesor lo vendería sin elegir color. */
        if ($f['tipo'] === 'variable' && !$item['variantes']) {
            $fuera($nombre, $f['sku_crudo'], 'Ninguno de sus colores o modelos se puede traer.');
            continue;
        }
        /* Un producto cuyos colores cambian CUENTA como actualizado: si no, la
           lista «Se actualizan» lo enseñaba y la cifra de arriba no lo sumaba. */
        if ($item['accion'] === 'igual'
            && ($item['cambios'] || array_filter($item['variantes'], fn($v) => $v['accion'] !== 'igual'))) {
            $item['accion'] = 'actualizar';
        }
        /* La foto: se baja si el producto no tiene la que hoy tiene la tienda.
           No cuenta como «se actualiza»: va aparte, en su propia cifra. */
        $fw = $f['foto'] ?? null;
        $item['foto'] = ($colFoto && tienda_foto_pendiente($item['accion'] === 'crear', $aqui['foto_woo'] ?? null, $fw))
            ? $fw : null;
        $item['tramos'] = ($item['accion'] === 'crear' || !isset($conPrecio[(int)$item['id']]))
            ? tienda_tramos_web($item) : [];
        /* El precio de 1 unidad de aquí contra el normal de la web, color por
           color (o el del producto sin colores). Solo se enseña. */
        if ($es_suyo && isset($precio1[(int)$aqui['id']])) {
            $pp = $precio1[(int)$aqui['id']];
            $dist = [];
            if ($item['variantes']) {
                foreach ($item['variantes'] as $v) {
                    if ($v['id'] === null || !($vActiva[(int)$v['id']] ?? false)) continue;   // uno apagado aquí no se manda
                    $h = $pp[(int)$v['id']] ?? ($pp[0] ?? null);
                    $w = $v['fila']['precio'] ?? null;
                    if ($h !== null && $w !== null && $h !== (int)$w) $dist[] = [$h, (int)$w];
                }
            } else {
                $h = $pp[0] ?? null;
                $w = $f['precio'] ?? null;
                if ($h !== null && $w !== null && $h !== (int)$w) $dist[] = [$h, (int)$w];
            }
            if ($dist) $difiere['precio'] = [$dist[0][0], $dist[0][1], count($dist)];
        }
        $item['difiere'] = $difiere;
        if ($item['accion'] === 'igual' && $item['tramos']) $item['accion'] = 'actualizar';
        $plan['productos'][] = $item;
    }

    $plan['resumen'] = tienda_resumen($plan);
    return $plan;
}

/**
 * LOS TRAMOS CON LOS QUE NACE UN PRODUCTO NUEVO, a partir de los precios de la
 * web. Uno solo, «1 a más».
 *   · Sin colores: su precio.
 *   · Con colores: el precio que más se repite va al producto (lo heredan
 *     todos); un color con otro precio en la web lleva el suyo.
 * Devuelve [['woo_var' => null|número de la variación, 'precio' => céntimos]].
 * Vacío si la web no tiene precio: el producto nace sin precio, como antes.
 */
function tienda_tramos_web(array $item): array
{
    $f = $item['fila'];
    if (!$item['variantes']) {
        return ($f['precio'] ?? null) ? [['woo_var' => null, 'precio' => (int)$f['precio']]] : [];
    }
    $precios = [];
    foreach ($item['variantes'] as $v) {
        if (($v['fila']['precio'] ?? null) !== null) $precios[] = (int)$v['fila']['precio'];
    }
    if (!$precios) return ($f['precio'] ?? null) ? [['woo_var' => null, 'precio' => (int)$f['precio']]] : [];
    $veces = array_count_values($precios);
    ksort($veces);                          // a igual número de veces, el más bajo
    arsort($veces);
    $base = (int) array_key_first($veces);
    $t = [['woo_var' => null, 'precio' => $base]];
    foreach ($item['variantes'] as $v) {
        $p = $v['fila']['precio'] ?? null;
        if ($p !== null && (int)$p !== $base) $t[] = ['woo_var' => (int)$v['fila']['woo_id'], 'precio' => (int)$p];
    }
    return $t;
}

/**
 * Los números del plan y su FIRMA. Las cifras solas no bastaban: con los
 * mismos totales, un producto que al leer salía «sin cambios» podía salir
 * «se actualiza» al guardar, y se pisaba un nombre que la vista previa no
 * había anunciado (auditoría del 3b). La firma cubre cada producto, cada
 * color, cada cambio y cada «no entra».
 */
function tienda_resumen(array $plan): array
{
    $r = ['crear' => 0, 'actualizar' => 0, 'igual' => 0, 'sin_precio' => 0, 'precio_web' => 0, 'fotos' => 0,
          'var_crear' => 0, 'var_actualizar' => 0, 'fuera' => count($plan['fuera']), 'difieren' => 0];
    $huella = [];
    foreach ($plan['productos'] as $p) {
        $r[$p['accion']]++;
        if ($p['difiere'] ?? []) $r['difieren']++;
        if ($p['accion'] === 'crear' && !($p['tramos'] ?? [])) $r['sin_precio']++;
        if ($p['accion'] !== 'crear' && ($p['tramos'] ?? [])) $r['precio_web']++;
        if ($p['foto'] ?? null) $r['fotos']++;
        $hv = [];
        foreach ($p['variantes'] as $v) {
            if ($v['accion'] === 'crear') $r['var_crear']++;
            if ($v['accion'] === 'actualizar') $r['var_actualizar']++;
            $hv[] = [$v['fila']['woo_id'], $v['accion'], $v['id'], $v['sku'], $v['cambios']];
        }
        $huella[] = [$p['fila']['woo_id'], $p['accion'], $p['id'], $p['cambios'], $hv, $p['tramos'] ?? []];
    }
    $huella[] = $plan['fuera'];
    $r['firma'] = sha1(json_encode($huella, JSON_UNESCAPED_UNICODE));
    return $r;
}

/* ───────────────────────── Guardar ───────────────────────── */

/**
 * Deja la lectura apuntada para enseñarla y, si el usuario dice que sí,
 * guardarla. Se guarda lo leído, no se vuelve a pedir a la tienda: así lo que
 * se guarda es exactamente lo que se enseñó.
 */
function tienda_lectura_nueva(array $filas, int $pais_id): int
{
    $plan = tienda_plan($filas, $pais_id);
    $id = insertar('tienda_lecturas', [
        'pais_id'    => $pais_id,
        'usuario_id' => (int)(yo()['id'] ?? 0) ?: null,
        'filas'      => json_encode($filas, JSON_UNESCAPED_UNICODE),
        'resumen'    => json_encode($plan['resumen']),
        'estado'     => 'leida',
    ]);
    /* Las lecturas que nadie guardó no sirven de nada en cuanto hay otra. */
    q("DELETE FROM tienda_lecturas WHERE pais_id = ? AND estado = 'leida' AND id < ?", [$pais_id, $id]);
    return $id;
}

/**
 * RETOCA UNA LECTURA SIN GUARDAR y deja apuntadas sus cifras nuevas (3c):
 * después de «usar el de la web» o «corregir la web», la vista previa tiene
 * que dejar de enseñar esa diferencia sin obligar a leer la tienda entera otra
 * vez. $cambiar recibe las filas y devuelve las filas (o null para dejarlas).
 */
function tienda_lectura_retocar(int $id, int $pais_id, bool $antes_ok, ?callable $cambiar = null): void
{
    $l = tienda_lectura($id);
    if (!$l || $l['estado'] !== 'leida') return;
    /* $antes_ok: si el catálogo YA había cambiado por otro lado ANTES de esto,
       no se tapa: las cifras se dejan como estaban y la pantalla sigue pidiendo
       leer otra vez. Lo mira quien llama, antes de tocar nada. */
    $filas = $cambiar ? ($cambiar($l['filas']) ?? $l['filas']) : $l['filas'];
    $resumen = $antes_ok ? tienda_plan($filas, $pais_id)['resumen'] : $l['resumen'];
    q("UPDATE tienda_lecturas SET filas = ?, resumen = ? WHERE id = ? AND estado = 'leida'",
      [json_encode($filas, JSON_UNESCAPED_UNICODE), json_encode($resumen), $id]);
}

/** Una lectura de mi país, con sus filas ya decodificadas. */
function tienda_lectura(int $id): ?array
{
    if (!tabla_existe('tienda_lecturas')) return null;
    $l = una('SELECT * FROM tienda_lecturas WHERE id = ? AND pais_id = ?', [$id, catalogo_pais()]);
    if (!$l) return null;
    $l['filas']   = json_decode((string)$l['filas'], true) ?: [];
    $l['resumen'] = json_decode((string)$l['resumen'], true) ?: [];
    return $l;
}

/**
 * GUARDA EN EL CATÁLOGO lo que se enseñó. Todo o nada.
 *
 * Se niega si la lectura ya se guardó, si hay otra más nueva, si la tienda es
 * de otro país, o si al recalcular el plan sale otro distinto (alguien creó o
 * cambió un producto mientras tanto): en ese caso hay que volver a leer.
 */
function tienda_importar(int $lectura_id): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'resumen' => []];
    $pais = catalogo_pais();
    if (!$pais) return $mal('No se sabe de qué país es tu cuenta.');
    if (!tienda_es_de_mi_pais()) return $mal('La tienda está conectada para otro país.');

    /* LAS FOTOS SE BAJAN ANTES, fuera de la transacción: bajar fotos con las
       filas bloqueadas dejaría el catálogo parado. Antes se hacen las
       comprobaciones baratas —ya guardada, hay otra más nueva, el catálogo
       cambió—: no se baja nada para un guardado que se va a negar. Se bajan
       como mucho TIENDA_FOTOS_POR_VEZ; las demás, en la siguiente lectura.
       Una foto que no baja no tumba el guardado. */
    $fotos = ['ok' => [], 'malas' => []];
    /* LAS FOTOS NUNCA TUMBAN EL GUARDADO: si algo de las fotos falla (el
       servidor no deja usar alguna función, una imagen rara), se guarda el
       catálogo sin ellas y se vuelven a pedir en la siguiente lectura. En el
       3b.3 un fallo aquí daba la pantalla de «Algo se rompió». */
    try {
        $l0 = tienda_lectura($lectura_id);
        if ($l0 && $l0['estado'] === 'leida' && columna_existe('productos', 'foto') && tienda_fotos_posible()
            && (int) valor('SELECT MAX(id) FROM tienda_lecturas WHERE pais_id = ?', [$pais]) === $lectura_id) {
            $plan0 = tienda_plan($l0['filas'], $pais);
            if (($plan0['resumen']['firma'] ?? '') === ($l0['resumen']['firma'] ?? null)) {
                /* Las que faltan, EN DESORDEN: si unas cuantas no bajan nunca
                   (la tienda se corta con ellas), no ocupan siempre los mismos
                   40 sitios y las demás también llegan. */
                $todas_f = [];
                foreach ($plan0['productos'] as $p0) {
                    if ($p0['foto'] ?? null) $todas_f[(int)$p0['foto']['id']] = $p0['foto']['src'];
                }
                $ids_f = array_keys($todas_f);
                if (empty($GLOBALS['__tienda_fotos_en_orden'])) shuffle($ids_f);
                $pedir = [];
                foreach (array_slice($ids_f, 0, TIENDA_FOTOS_POR_VEZ) as $idf) $pedir[$idf] = $todas_f[$idf];
                unset($plan0, $l0);
                if ($pedir) {
                    tienda_fotos_barrer();
                    $fotos = tienda_bajar_fotos($pedir);
                }
            }
        }
    } catch (Throwable $ex) {
        error_log('[HUB tienda fotos] ' . get_class($ex) . ': ' . $ex->getMessage() . ' en ' . $ex->getFile() . ':' . $ex->getLine());
        $fotos = ['ok' => [], 'malas' => []];
    }
    /* Bajar fotos lleva su rato: la base de datos puede haber colgado. */
    bd_despertar();
    $bajadas = $fotos['ok'];
    $malas   = $fotos['malas'];
    $viejas = [];

    try {
        $r = en_transaccion(function () use ($lectura_id, $pais, $mal, $bajadas, $malas, &$viejas) {
            bloquear_fila('tienda_lecturas', $lectura_id);
            $l = tienda_lectura($lectura_id);
            if (!$l) return $mal('Esa lectura de la tienda ya no existe. Vuelve a leerla.');
            if ($l['estado'] !== 'leida') return $mal('Esa lectura ya se guardó.');
            $ultima = (int) valor('SELECT MAX(id) FROM tienda_lecturas WHERE pais_id = ?', [$pais]);
            if ($ultima !== $lectura_id) return $mal('Hay una lectura más nueva de la tienda. Guarda esa.');

            $plan = tienda_plan($l['filas'], $pais);
            if (($plan['resumen']['firma'] ?? '') !== ($l['resumen']['firma'] ?? null)) {
                return $mal('El catálogo cambió desde que leíste la tienda. Vuelve a leerla y revisa otra vez.');
            }

            foreach ($plan['productos'] as $p) {
                $f = $p['fila'];
                if ($p['accion'] === 'crear') {
                    $pid = insertar('productos', [
                        'sku' => $f['sku'], 'nombre' => $f['nombre'],
                        'categoria' => $f['categoria'] !== '' ? $f['categoria'] : null,
                        'pais_id' => $pais, 'woo_id' => $f['woo_id'], 'activo' => 1,
                    ]);
                } else {
                    $pid = (int)$p['id'];
                    if ($p['accion'] === 'actualizar') {
                        $d = ['woo_id' => $f['woo_id'], 'nombre' => $f['nombre']];
                        if ($f['categoria'] !== '') $d['categoria'] = $f['categoria'];
                        actualizar('productos', $pid, $d);
                    }
                }
                $fw = $p['foto'] ?? null;
                if ($fw && isset($bajadas[(int)$fw['id']])) {
                    $antes = (string) valor('SELECT foto FROM productos WHERE id = ?', [$pid]);
                    if ($antes !== '') $viejas[] = $antes;
                    actualizar('productos', $pid, ['foto' => $bajadas[(int)$fw['id']], 'foto_woo' => (int)$fw['id']]);
                } elseif ($fw && isset($malas[(int)$fw['id']])) {
                    /* Una foto que no sirve (no es imagen, enorme, dirección
                       interna) se apunta como intentada: no se pide otra vez
                       hasta que la tienda ponga otra. El producto se queda con
                       la que tenía. */
                    actualizar('productos', $pid, ['foto_woo' => (int)$fw['id']]);
                }
                $nuevas = [];                   // número de la variación => id de aquí (nuevas y que ya estaban)
                foreach ($p['variantes'] as $v) {
                    $fv = $v['fila'];
                    if ($v['accion'] === 'crear') {
                        $nuevas[(int)$fv['woo_id']] = insertar('variantes', [
                            'producto_id' => $pid, 'sku' => $v['sku'],
                            'color' => $fv['color'] !== '' ? $fv['color'] : null,
                            'medida' => $fv['medida'] !== '' ? $fv['medida'] : null,
                            'woo_id' => $fv['woo_id'], 'activo' => 1,
                        ]);
                    } else {
                        $nuevas[(int)$fv['woo_id']] = (int)$v['id'];
                    }
                    if ($v['accion'] === 'actualizar') {
                        actualizar('variantes', (int)$v['id'], [
                            'color' => $fv['color'] !== '' ? $fv['color'] : null,
                            'medida' => $fv['medida'] !== '' ? $fv['medida'] : null,
                            'woo_id' => $fv['woo_id'],
                        ]);
                    }
                }
                /* El precio de la web, solo en los que no tienen ninguno. Por la MISMA
                   función que el precio puesto a mano: los tramos que valen
                   son los mismos, se guarde desde donde se guarde. */
                foreach ($p['tramos'] ?? [] as $t) {
                    $vid = $t['woo_var'] === null ? null : ($nuevas[(int)$t['woo_var']] ?? null);
                    if ($t['woo_var'] !== null && !$vid) continue;
                    $g = precios_guardar($pid, $vid, [['desde' => 1, 'hasta' => '',
                                         'precio' => number_format($t['precio'] / 100, 2, '.', '')]]);
                    if (!$g['ok']) throw new RuntimeException('precio de la web: ' . $g['error']);
                }
            }

            /* EL STOCK de lo leído, en la misma transacción, con la hora de la
               LECTURA: los productos que se acaban de crear salen ya con el
               suyo. Si hay una foto del stock más nueva, no se pisa (solo se
               completan los que no tenían). No entra en la firma: cambia a
               cada rato y no es un cambio del catálogo. */
            stock_web_guardar($l['filas'], $pais, (string)$l['creado_en']);

            q("UPDATE tienda_lecturas SET estado = 'guardada', guardada_en = ?, guardada_por = ? WHERE id = ?",
              [date('Y-m-d H:i:s'), (int)(yo()['id'] ?? 0) ?: null, $lectura_id]);
            /* Una conexión de antes de la regla del país queda como de quien la usa. */
            if ((int) ajuste('woo_pais', 0) === 0) { guardar_ajuste('woo_pais', (string)$pais); ajustes_olvidar(); }
            $res = $plan['resumen'];
            unset($res['firma']);
            bitacora('catalogo.tienda', 'lectura', $lectura_id, $res);
            $res2 = $plan['resumen'];
            $res2['fotos_ok'] = $res2['fotos_malas'] = 0;
            foreach ($plan['productos'] as $p) {
                if (!($p['foto'] ?? null)) continue;
                if (isset($bajadas[(int)$p['foto']['id']])) $res2['fotos_ok']++;
                elseif (isset($malas[(int)$p['foto']['id']])) $res2['fotos_malas']++;
            }
            return ['ok' => true, 'error' => '', 'resumen' => $res2];
        });
    } catch (Throwable $ex) {
        error_log('[HUB tienda] ' . get_class($ex) . ': ' . $ex->getMessage() . ' en ' . $ex->getFile() . ':' . $ex->getLine());
        tienda_fotos_quitar_sin_mirar(array_values($bajadas));
        if (es_choque_de_unico($ex)) {
            return $mal('Otro producto se quedó con uno de esos códigos mientras guardabas. '
                      . 'No se cambió nada del catálogo: vuelve a leer la tienda.');
        }
        return $mal('No se pudo guardar. No se cambió nada del catálogo.');
    }
    /* Guardado: se van las miniaturas que quedaron sin dueño. No guardado: se
       van las recién bajadas, que nadie nombra. */
    try {
        tienda_fotos_borrar($r['ok'] ? $viejas : array_values($bajadas));
    } catch (Throwable $ex) {
        error_log('[HUB tienda fotos] ' . $ex->getMessage());      // las huérfanas las barre la siguiente vez
    }
    return $r;
}

/* ───────────────────────── Las fotos ───────────────────────── */

const TIENDA_FOTO_LADO     = 240;               // la miniatura: 240 × 240
const TIENDA_FOTO_BYTES    = 8 * 1024 * 1024;   // una foto de la web de más de 8 MB no se baja
const TIENDA_FOTO_PIXELES  = 16_000_000;        // ni una de más de 16 megapíxeles: no cabe en memoria
const TIENDA_FOTOS_POR_VEZ = 40;                // por guardado; las demás, en la siguiente lectura
const TIENDA_FOTOS_SEGUNDOS = 60;               // bajar Y achicar, todo junto

/** ¿Se pueden hacer miniaturas en este servidor? Sin GD no se piden fotos. */
function tienda_fotos_posible(): bool
{
    if (isset($GLOBALS['__tienda_fotos_posible'])) return (bool)$GLOBALS['__tienda_fotos_posible'];
    return extension_loaded('gd') && function_exists('imagecreatefromstring')
        && function_exists('imagecreatetruecolor') && function_exists('imagejpeg')
        && function_exists('curl_init') && function_exists('curl_exec')
        && (function_exists('gethostbynamel') || function_exists('dns_get_record') || function_exists('gethostbyname'));
}

/** La carpeta de las miniaturas. El banco de pruebas la cambia por una suya. */
function tienda_fotos_dir(): string
{
    return (string)($GLOBALS['__tienda_fotos_dir'] ?? (HUB_RAIZ . '/' . FOTO_PRODUCTO_CARPETA));
}

/**
 * Las direcciones IP de un nombre. Algunos hostings apagan alguna de estas
 * funciones: se prueba la siguiente. Sin ninguna, [] (la foto no se baja y
 * se vuelve a pedir; no se marca como mala).
 */
function tienda_resolver(string $host): array
{
    if (isset($GLOBALS['__tienda_resolver']) && is_callable($GLOBALS['__tienda_resolver'])) {
        return (array)($GLOBALS['__tienda_resolver'])($host);
    }
    if (function_exists('gethostbynamel')) {
        $ips = @gethostbynamel($host);
        if ($ips) return $ips;
    }
    if (function_exists('dns_get_record')) {
        $r = @dns_get_record($host, DNS_A);
        $ips = [];
        foreach ($r ?: [] as $x) if (!empty($x['ip'])) $ips[] = (string)$x['ip'];
        if ($ips) return $ips;
    }
    if (function_exists('gethostbyname')) {
        $ip = @gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) return [$ip];
    }
    return [];
}

/** ¿Es una ip de internet? No la red interna, ni la reservada, ni la compartida del proveedor (100.64/10). */
function tienda_ip_publica(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        if ($n !== false && ($n & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) return false;
    }
    return true;
}

/**
 * A DÓNDE SE CONECTA de verdad para bajar una foto:
 *   [host, puerto, ip] → se baja de esa ip, fijada (no puede cambiar entre la
 *                        comprobación y la conexión) y sin seguir redirecciones;
 *   null  → NO SIRVE NUNCA: no es https, lleva usuario o apunta a la red
 *           interna del servidor (127.0.0.1, 10.x, 192.168.x…). La dirección
 *           de la foto la escribe quien edita la tienda: sin esto, el servidor
 *           podía «bajar como foto» cualquier cosa de su propia red;
 *   false → ahora no se pudo saber a dónde apunta (el nombre no contesta): se
 *           vuelve a intentar otro día, no se marca como mala.
 */
function tienda_foto_destino(string $src): array|null|false
{
    $u = parse_url($src);
    if (($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user'])) return null;
    $host = strtolower((string)$u['host']);
    $port = (int)($u['port'] ?? 443);
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : tienda_resolver($host);
    if (!$ips) return false;
    $permitidas = (array)($GLOBALS['__tienda_fotos_red_prueba']['permitir'] ?? []);   // solo el banco de pruebas
    foreach ($ips as $ip) {
        if (tienda_ip_publica((string)$ip) || in_array($ip, $permitidas, true)) return [$host, $port, (string)$ip];
    }
    return null;
}

/**
 * Las opciones de la descarga de UNA foto. OJO CON LA VERSIÓN DE PHP: el
 * hosting tiene PHP 8.1 y CURLOPT_XFERINFOFUNCTION solo existe desde el 8.2.
 * Nombrarla sin más reventaba el guardado (3b.3, «Algo se rompió»): se usa si
 * existe y, si no, la de siempre, que recibe lo mismo en el mismo orden.
 */
function tienda_foto_opciones(string $src, array $dest, float $hasta): array
{
    [$host, $port, $ip] = $dest;
    $avance = defined('CURLOPT_XFERINFOFUNCTION') && empty($GLOBALS['__tienda_sin_xferinfo'])
            ? constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION;
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8,
           CURLOPT_TIMEOUT => (int) max(5, min(25, ceil($hasta - microtime(true)))),
           CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Waka/1.0)',
           CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
           CURLOPT_MAXFILESIZE => TIENDA_FOTO_BYTES,
           /* El tope también cuando el servidor no dice cuánto pesa. */
           CURLOPT_NOPROGRESS => false,
           $avance => fn($c, $t, $bajado) => $bajado > TIENDA_FOTO_BYTES ? 1 : 0];
    if (defined('CURLPROTO_HTTPS')) $op[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (!empty($GLOBALS['__tienda_fotos_red_prueba']['ca'])) $op[CURLOPT_CAINFO] = $GLOBALS['__tienda_fotos_red_prueba']['ca'];
    return $op;
}

/**
 * BAJA LAS FOTOS de la tienda y las deja en miniatura, de seis en seis: cada
 * tanda se achica en cuanto llega y se suelta de la memoria (con todas juntas,
 * un catálogo grande agotaba la memoria del servidor y no se guardaba nada).
 *
 * [número de foto => dirección] → ['ok' => [número => archivo],
 *                                  'malas' => [número => true]]
 * 'malas' son las que NO SIRVEN y no se piden más hasta que la tienda ponga
 * otra: no es una imagen, es enorme, no existe, redirige, apunta a la red
 * interna. Las que no llegan por algo pasajero (la red, el plazo, un corte a
 * medias, un «ahora no» de la tienda) no salen en ninguna: se vuelven a pedir.
 *
 * Nunca se mandan las claves de la tienda. Se puede sustituir con
 * `$GLOBALS['__tienda_fotos']` (dirección → bytes o null) para el banco.
 */
function tienda_bajar_fotos(array $pedir): array
{
    $out = ['ok' => [], 'malas' => []];
    if (!tienda_fotos_posible() || !$pedir) return $out;
    tiempo_extra(TIENDA_FOTOS_SEGUNDOS + 60);
    $hasta = microtime(true) + TIENDA_FOTOS_SEGUNDOS;
    $falsas = isset($GLOBALS['__tienda_fotos']) && is_callable($GLOBALS['__tienda_fotos']);
    /* De seis en seis a la vez; si el hosting no deja pedir varias juntas,
       una detrás de otra. */
    $juntas = function_exists('curl_multi_init') && function_exists('curl_multi_exec')
           && function_exists('curl_multi_getcontent') && function_exists('curl_multi_info_read')
           && empty($GLOBALS['__tienda_fotos_una_a_una']);

    $parar = false;
    foreach (array_chunk($pedir, 6, true) as $tanda) {
        if ($parar || microtime(true) > $hasta) break;
        $bytes = [];
        try {
            if ($falsas) {
                foreach ($tanda as $id => $src) $bytes[$id] = ($GLOBALS['__tienda_fotos'])($src);
            } else {
                $mh = $juntas ? curl_multi_init() : null;
                $hs = [];
                foreach ($tanda as $id => $src) {
                    $dest = tienda_foto_destino($src);
                    if ($dest === null) { $out['malas'][(int)$id] = true; continue; }
                    if ($dest === false) continue;                              // el nombre no contesta: otro día
                    $ch = curl_init($src);
                    if (!$ch || !@curl_setopt_array($ch, tienda_foto_opciones($src, $dest, $hasta))) {
                        if ($ch) curl_close($ch);                               // sin todas las protecciones, no se baja
                        continue;
                    }
                    if ($mh) {
                        curl_multi_add_handle($mh, $ch);
                        $hs[$id] = $ch;
                    } else {
                        if (microtime(true) > $hasta) { curl_close($ch); break; }
                        $txt = curl_exec($ch);
                        tienda_foto_llegada($out, $bytes, (int)$id, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                                            $txt, curl_errno($ch));
                        curl_close($ch);
                    }
                }
                if ($mh) {
                    /* Cómo acabó CADA descarga: una cortada a medias contesta
                       «200» con media foto, y no vale. */
                    $fin = [];
                    do {
                        $st = curl_multi_exec($mh, $activos);
                        if ($activos) curl_multi_select($mh, 1.0);
                        while ($m = curl_multi_info_read($mh)) $fin[spl_object_id($m['handle'])] = (int)$m['result'];
                    } while ($activos && $st === CURLM_OK);
                    while ($m = curl_multi_info_read($mh)) $fin[spl_object_id($m['handle'])] = (int)$m['result'];
                    foreach ($hs as $id => $ch) {
                        tienda_foto_llegada($out, $bytes, (int)$id, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                                            curl_multi_getcontent($ch), $fin[spl_object_id($ch)] ?? -1);
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh);
                }
            }
        } catch (Throwable $ex) {
            /* Si algo falla, se para: lo que ya llegó (también de esta tanda)
               se aprovecha y el resto se pide en la siguiente lectura. */
            error_log('[HUB tienda fotos] ' . get_class($ex) . ': ' . $ex->getMessage());
            $parar = true;
        }
        foreach (array_keys($bytes) as $id) {
            $b = $bytes[$id];
            unset($bytes[$id]);                                                 // se suelta antes de achicar la siguiente
            if (!is_string($b) || $b === '') continue;                          // no llegó: otra vez
            try {
                $archivo = strlen($b) <= TIENDA_FOTO_BYTES ? tienda_foto_guardar($b, (int)$id) : '';
            } catch (Throwable $ex) {
                error_log('[HUB tienda foto ' . (int)$id . '] ' . get_class($ex) . ': ' . $ex->getMessage());
                $archivo = '';                                                  // no se puede achicar: no sirve
            }
            unset($b);
            if ($archivo === 'otra-vez') continue;                             // ahora no se pudo: otra vez
            if ($archivo !== '') $out['ok'][(int)$id] = $archivo;
            else $out['malas'][(int)$id] = true;
        }
    }
    return $out;
}

/**
 * Lo que contestó la tienda por UNA foto.
 *   · llegó entera (200, sin error de la descarga) → los bytes;
 *   · es enorme (pasa del tope) → mala;
 *   · no existe o redirige (404, 410, 400, 3xx) → mala;
 *   · lo demás es pasajero —un corte, el plazo, 401/403 de un cortafuegos,
 *     429 «demasiadas», 5xx— → nada: se vuelve a pedir.
 */
function tienda_foto_llegada(array &$out, array &$bytes, int $id, int $cod, mixed $txt, int $error = 0): void
{
    if (in_array($error, [63, 42], true)) { $out['malas'][$id] = true; return; }   // pasa del tope / cortada por el tope
    if ($error !== 0) return;
    if ($cod === 200 && is_string($txt) && $txt !== '') { $bytes[$id] = $txt; return; }
    if (($cod >= 300 && $cod < 400) || in_array($cod, [400, 404, 410], true)) $out['malas'][$id] = true;
}

/** La memoria que queda para trabajar, en bytes (INF si no hay tope). */
function tienda_memoria_libre(): float
{
    $txt = trim((string) ini_get('memory_limit'));
    if ($txt === '' || $txt === '-1') return INF;
    $n = (float) $txt;
    $u = strtolower(substr($txt, -1));
    $n *= ['g' => 1073741824, 'm' => 1048576, 'k' => 1024][$u] ?? 1;
    return $n - memory_get_usage(true);
}

/**
 * ¿Cabe en memoria abrir una foto de ancho × alto? Abierta ocupa unos 5 bytes
 * por punto, y el doble si hay que girarla. Si no cabe, se pide un poco más
 * al servidor; si tampoco, no se abre: pasarse de la memoria mata la página
 * entera, sin aviso y sin guardar nada.
 */
function tienda_memoria_alcanza(int $ancho, int $alto, bool $girar): bool
{
    $hace_falta = $ancho * $alto * ($girar ? 10 : 5) + 8 * 1048576;
    if (isset($GLOBALS['__tienda_memoria_libre'])) return (float)$GLOBALS['__tienda_memoria_libre'] >= $hace_falta;
    if (tienda_memoria_libre() >= $hace_falta) return true;
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', (string)(int) ceil((memory_get_usage(true) + $hace_falta) / 1048576 + 16) . 'M');
    }
    return tienda_memoria_libre() >= $hace_falta;
}

/** Cuánto hay que girar una foto JPEG del celular (0, 90, 180, 270). */
function tienda_foto_giro(string $bytes): int
{
    if (!function_exists('exif_read_data')) return 0;
    /* Desde la memoria, no con «data://»: esa forma la bloquea el hosting que
       tiene cerrado allow_url_fopen. */
    $h = @fopen('php://memory', 'r+b');
    if (!$h) return 0;
    fwrite($h, $bytes);
    rewind($h);
    $exif = @exif_read_data($h);
    fclose($h);
    return [3 => 180, 6 => 270, 8 => 90][(int)(is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)] ?? 0;
}

/**
 * ACHICA una foto a miniatura cuadrada (la foto entera, centrada sobre blanco:
 * un producto recortado por los lados se confunde con otro), la endereza si
 * viene girada y la guarda en JPEG. Devuelve el nombre del archivo; '' si no
 * es una imagen que se pueda usar; 'otra-vez' si ahora no se puede (sin sitio en el
 * disco, sin memoria, un tipo que este servidor no abre): se vuelve a pedir.
 */
function tienda_foto_guardar(string $bytes, int $foto_woo): string
{
    if (!tienda_fotos_posible()) return 'otra-vez';
    $info = @getimagesizefromstring($bytes);
    $tipos = [IMAGETYPE_JPEG => IMG_JPG, IMAGETYPE_PNG => IMG_PNG, IMAGETYPE_GIF => IMG_GIF, IMAGETYPE_WEBP => IMG_WEBP];
    if (!$info || !isset($tipos[$info[2]])) return '';
    /* Un tipo que el GD de este servidor no sabe abrir no es una foto mala. */
    if (function_exists('imagetypes') && !(imagetypes() & $tipos[$info[2]])) return 'otra-vez';
    [$ancho, $alto] = $info;
    if ($ancho < 1 || $alto < 1 || $ancho * $alto > TIENDA_FOTO_PIXELES) return '';
    $giro = ($info[2] === IMAGETYPE_JPEG && function_exists('imagerotate')) ? tienda_foto_giro($bytes) : 0;
    /* Sin memoria AHORA no es una foto mala: se pide otra vez. */
    if (!tienda_memoria_alcanza($ancho, $alto, $giro !== 0)) return 'otra-vez';
    $origen = @imagecreatefromstring($bytes);
    if (!$origen) return '';

    /* Las fotos del celular vienen «acostadas» con una marca que dice cómo
       girarlas. GD no la lee: se gira aquí. */
    if ($giro) {
        $girada = imagerotate($origen, $giro, 0);
        if ($girada) { imagedestroy($origen); $origen = $girada; }
    }
    $ancho = imagesx($origen);
    $alto  = imagesy($origen);

    $lado = TIENDA_FOTO_LADO;
    $esc = min($lado / $ancho, $lado / $alto, 1.0);
    $w = max(1, (int) round($ancho * $esc));
    $h = max(1, (int) round($alto * $esc));
    $lienzo = imagecreatetruecolor($lado, $lado);
    imagefilledrectangle($lienzo, 0, 0, $lado, $lado, (int) imagecolorallocate($lienzo, 255, 255, 255));
    imagecopyresampled($lienzo, $origen, (int)(($lado - $w) / 2), (int)(($lado - $h) / 2), 0, 0, $w, $h, $ancho, $alto);
    imagedestroy($origen);

    $dir = tienda_fotos_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nombre = 'w' . $foto_woo . '-' . bin2hex(random_bytes(4)) . '.jpg';
    $ok = is_dir($dir) && is_writable($dir) && @imagejpeg($lienzo, $dir . '/' . $nombre, 78);
    imagedestroy($lienzo);
    return $ok ? $nombre : 'otra-vez';
}

/**
 * ¿LE FALTA A ESTE PRODUCTO LA FOTO QUE HOY TIENE LA TIENDA? Una sola
 * definición: la usan la vista previa (tienda_plan) y el botón «Traer las fotos
 * que faltan». Falta si el producto se crea ahora o si no tiene apuntada esa
 * misma foto de la tienda (ni bajada ni dada por mala).
 */
function tienda_foto_pendiente(bool $crear, mixed $foto_woo_aqui, ?array $foto_tienda): bool
{
    if (!$foto_tienda || !tienda_fotos_posible()) return false;
    return $crear || (int)($foto_woo_aqui ?? 0) !== (int)$foto_tienda['id'];
}

/**
 * LAS FOTOS QUE FALTAN, sin volver a leer la tienda (3b.5). Salen de la última
 * lectura GUARDADA de mi país: la tienda ya se leyó entera, y de ahí se sabe
 * qué foto tiene cada producto. [número de foto => ['src', 'productos' => [id…]]]
 */
function tienda_fotos_pendientes(int $pais_id): array
{
    if (!tabla_existe('tienda_lecturas') || !columna_existe('productos', 'foto_woo') || !tienda_fotos_posible()) return [];
    $l = una("SELECT filas FROM tienda_lecturas WHERE pais_id = ? AND estado = 'guardada' ORDER BY id DESC LIMIT 1", [$pais_id]);
    if (!$l) return [];
    $aqui = [];
    foreach (todas('SELECT id, woo_id, foto_woo FROM productos
                     WHERE woo_id IS NOT NULL AND (pais_id = ? OR pais_id IS NULL) ORDER BY id', [$pais_id]) as $p) {
        $aqui[(int)$p['woo_id']][] = $p;
    }
    $out = [];
    foreach (json_decode((string)$l['filas'], true) ?: [] as $f) {
        $fw = $f['foto'] ?? null;
        foreach ($aqui[(int)($f['woo_id'] ?? 0)] ?? [] as $p) {
            if (!tienda_foto_pendiente(false, $p['foto_woo'], $fw)) continue;
            $out[(int)$fw['id']]['src'] = (string)$fw['src'];
            $out[(int)$fw['id']]['productos'][] = (int)$p['id'];
        }
    }
    return $out;
}

/**
 * TRAE UNA TANDA DE LAS FOTOS QUE FALTAN (como mucho TIENDA_FOTOS_POR_VEZ).
 * La pantalla la llama una y otra vez hasta que no quede ninguna, enseñando
 * cuántas van: con un catálogo grande, leer y guardar la tienda diez veces
 * para traer las fotos de 40 en 40 no tenía sentido.
 *
 * → ['ok', 'error', 'traidas', 'malas', 'quedan']
 */
function tienda_traer_fotos(int $pais_id): array
{
    $res = ['ok' => true, 'error' => '', 'traidas' => 0, 'malas' => 0, 'quedan' => 0];
    if (!$pais_id) return ['ok' => false, 'error' => 'No se sabe de qué país es tu cuenta.'] + $res;
    if (!tienda_es_de_mi_pais()) return ['ok' => false, 'error' => 'La tienda está conectada para otro país.'] + $res;

    $bajadas = $malas = $pend = [];
    try {
        $pend = tienda_fotos_pendientes($pais_id);
        if (!$pend) return $res;
        $ids = array_keys($pend);
        if (empty($GLOBALS['__tienda_fotos_en_orden'])) shuffle($ids);
        $pedir = [];
        foreach (array_slice($ids, 0, TIENDA_FOTOS_POR_VEZ) as $id) $pedir[$id] = $pend[$id]['src'];
        tienda_fotos_barrer();
        $f = tienda_bajar_fotos($pedir);
        $bajadas = $f['ok'];
        $malas   = $f['malas'];
    } catch (Throwable $ex) {
        error_log('[HUB tienda fotos] ' . get_class($ex) . ': ' . $ex->getMessage());
    }
    bd_despertar();

    $viejas = [];
    $usadas = [];
    try {
        en_transaccion(function () use ($pend, $bajadas, $malas, &$viejas, &$usadas, &$res) {
            foreach ($bajadas + array_fill_keys(array_keys($malas), null) as $id => $archivo) {
                foreach ($pend[$id]['productos'] ?? [] as $pid) {
                    bloquear_fila('productos', (int)$pid);
                    $p = una('SELECT foto, foto_woo FROM productos WHERE id = ?', [(int)$pid]);
                    /* Si mientras bajaba alguien guardó otra lectura con otra
                       foto, esa manda: no se pisa. */
                    if (!$p || (int)($p['foto_woo'] ?? 0) === (int)$id) continue;
                    if ($archivo !== null) {
                        if ((string)$p['foto'] !== '') $viejas[] = (string)$p['foto'];
                        actualizar('productos', (int)$pid, ['foto' => $archivo, 'foto_woo' => (int)$id]);
                        $usadas[$archivo] = true;
                    } else {
                        actualizar('productos', (int)$pid, ['foto_woo' => (int)$id]);
                    }
                }
                if ($archivo !== null) $res['traidas']++; else $res['malas']++;
            }
        });
    } catch (Throwable $ex) {
        error_log('[HUB tienda fotos] ' . get_class($ex) . ': ' . $ex->getMessage());
        tienda_fotos_quitar_sin_mirar(array_values($bajadas));
        return ['ok' => false, 'error' => 'No se pudieron guardar las fotos. Vuelve a intentarlo.'] + $res;
    }
    try {
        /* Las viejas que ya nadie usa y las bajadas que nadie tomó. */
        $sobran = array_values(array_filter($bajadas, fn($a) => !isset($usadas[$a])));
        tienda_fotos_borrar(array_merge($viejas, $sobran));
    } catch (Throwable $ex) {
        error_log('[HUB tienda fotos] ' . $ex->getMessage());
    }
    $res['quedan'] = count(tienda_fotos_pendientes($pais_id));
    return $res;
}

/**
 * Borra miniaturas que ya NO NOMBRA NINGÚN PRODUCTO. Dos productos pueden
 * compartir la misma foto de la tienda: borrar la de uno sin mirar dejaba al
 * otro con la foto rota para siempre (auditoría del 3b.3).
 */
function tienda_fotos_borrar(array $archivos): void
{
    foreach (array_unique(array_map('strval', $archivos)) as $a) {
        if (!foto_producto_valida($a)) continue;
        if (valor('SELECT 1 FROM productos WHERE foto = ?', [$a])) continue;
        @unlink(tienda_fotos_dir() . '/' . $a);
    }
}

/**
 * Borra miniaturas RECIÉN BAJADAS de un guardado que no llegó a guardarse: sus
 * nombres son nuevos y nadie los apunta, así que no hace falta preguntar a la
 * base de datos (que puede ser justo lo que falló).
 */
function tienda_fotos_quitar_sin_mirar(array $archivos): void
{
    foreach (array_unique(array_map('strval', $archivos)) as $a) {
        if (foto_producto_valida($a)) @unlink(tienda_fotos_dir() . '/' . $a);
    }
}

/**
 * BARRE LAS MINIATURAS HUÉRFANAS: las de más de una hora que no nombra ningún
 * producto. Quedan si el servidor corta un guardado a mitad (tiempo agotado):
 * las fotos ya bajadas se escribieron y nadie llegó a apuntarlas.
 */
function tienda_fotos_barrer(): int
{
    $dir = tienda_fotos_dir();
    if (!is_dir($dir)) return 0;
    $usadas = [];
    foreach (todas('SELECT foto FROM productos WHERE foto IS NOT NULL') as $x) $usadas[(string)$x['foto']] = true;
    $n = 0;
    foreach (scandir($dir) ?: [] as $f) {
        $ruta = $dir . '/' . $f;
        if (!foto_producto_valida($f) || isset($usadas[$f]) || !is_file($ruta) || is_link($ruta)) continue;
        if (filemtime($ruta) > time() - 3600) continue;          // puede ser de un guardado en curso
        if (@unlink($ruta)) $n++;
    }
    return $n;
}
