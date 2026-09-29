<?php
/**
 * Plugin Name:       Waka HUB · Conector
 * Description:       Deja que el HUB de Waka cambie en la tienda el stock, el precio, el nombre y el código de los productos. Nada más: no puede borrar productos, ni ver pedidos, ni tocar clientes.
 * Version:           1.1.0
 * Author:            Bloomit
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       waka-hub-conector
 *
 * CÓMO SE USA
 *   1. Plugins › Añadir nuevo › Subir plugin › este ZIP › Activar.
 *   2. WooCommerce › HUB Waka › GENERAR CLAVE. La clave sale UNA vez.
 *   3. Pégala en el HUB: Configuración › Tienda › Conector.
 *
 * POR QUÉ UN PLUGIN Y NO CLAVES DE ESCRITURA DE WOOCOMMERCE
 *   Las claves de escritura de WooCommerce pueden cambiar o borrar TODO en la
 *   tienda. Este plugin solo sabe hacer cuatro cosas, y cada petición llega
 *   firmada con la clave: sin ella, la tienda no hace caso.
 */

if (!defined('ABSPATH')) exit;

const WAKA_HUB_VERSION    = '1.1.0';
const WAKA_HUB_OPCION     = 'waka_hub_clave';
const WAKA_HUB_TOLERANCIA = 300;        // segundos de diferencia de reloj que se aceptan
const WAKA_HUB_ESPACIO    = 'waka-hub/v1';
const WAKA_HUB_HECHAS     = 'waka_hub_hechas';   // las peticiones de stock ya hechas, para no repetirlas

/* ─────────────────────────  LA FIRMA  ───────────────────────── */

/**
 * ¿La petición viene del HUB? Firma = HMAC-SHA256(clave, fecha \n MÉTODO \n ruta \n cuerpo).
 * La fecha evita que alguien que vio pasar una petición la repita días después.
 */
function waka_hub_firma_valida(WP_REST_Request $req): bool
{
    $clave = (string) get_option(WAKA_HUB_OPCION, '');
    if ($clave === '') return false;
    $fecha = (string) $req->get_header('x_waka_fecha');
    $firma = (string) $req->get_header('x_waka_firma');
    if ($fecha === '' || $firma === '' || !ctype_digit($fecha)) return false;
    if (abs(time() - (int) $fecha) > WAKA_HUB_TOLERANCIA) return false;
    $ruta = '/' . ltrim((string) $req->get_route(), '/');
    $base = $fecha . "\n" . strtoupper($req->get_method()) . "\n" . $ruta . "\n" . (string) $req->get_body();
    return hash_equals(hash_hmac('sha256', $base, $clave), strtolower($firma));
}

function waka_hub_permiso(WP_REST_Request $req)
{
    if (!function_exists('wc_get_product')) {
        return new WP_Error('waka_sin_woo', 'WooCommerce no está activo.', ['status' => 503]);
    }
    if (!waka_hub_firma_valida($req)) {
        return new WP_Error('waka_firma', 'La clave del HUB no coincide.', ['status' => 401]);
    }
    return true;
}

function waka_hub_error(string $codigo, string $mensaje, int $http = 400): WP_REST_Response
{
    return new WP_REST_Response(['ok' => false, 'codigo' => $codigo, 'mensaje' => $mensaje], $http);
}

/* ─────────────────────────  LAS RUTAS  ───────────────────────── */

add_action('rest_api_init', function () {
    register_rest_route(WAKA_HUB_ESPACIO, '/estado', [
        'methods' => 'GET', 'callback' => 'waka_hub_estado', 'permission_callback' => 'waka_hub_permiso',
    ]);
    register_rest_route(WAKA_HUB_ESPACIO, '/producto', [
        'methods' => 'POST', 'callback' => 'waka_hub_producto', 'permission_callback' => 'waka_hub_permiso',
    ]);
    register_rest_route(WAKA_HUB_ESPACIO, '/lote', [
        'methods' => 'POST', 'callback' => 'waka_hub_lote', 'permission_callback' => 'waka_hub_permiso',
    ]);
    register_rest_route(WAKA_HUB_ESPACIO, '/stock/mover', [
        'methods' => 'POST', 'callback' => 'waka_hub_mover', 'permission_callback' => 'waka_hub_permiso',
    ]);
});

function waka_hub_estado(WP_REST_Request $req): WP_REST_Response
{
    return new WP_REST_Response([
        'ok' => true, 'version' => WAKA_HUB_VERSION,
        'woocommerce' => defined('WC_VERSION') ? WC_VERSION : '',
    ], 200);
}

/**
 * El producto (o la variación) al que se refiere el HUB, comprobando que la
 * variación es de ese producto: con un número cambiado a mano no se toca otro.
 */
function waka_hub_objetivo(array $d)
{
    $woo_id = isset($d['woo_id']) ? (int) $d['woo_id'] : 0;
    $var_id = isset($d['variacion_id']) ? (int) $d['variacion_id'] : 0;
    if ($woo_id <= 0) return null;
    $padre = wc_get_product($woo_id);
    if (!$padre || $padre->get_type() === 'variation') return null;
    /* Uno en la papelera no se toca: para la tienda ya no existe. */
    if (method_exists($padre, 'get_status') && $padre->get_status() === 'trash') return null;
    if ($var_id <= 0) return $padre;
    $v = wc_get_product($var_id);
    if (!$v || $v->get_type() !== 'variation' || (int) $v->get_parent_id() !== $woo_id) return null;
    return $v;
}

function waka_hub_resumen($p): array
{
    return [
        'id'       => $p->get_id(),
        'nombre'   => $p->get_name(),
        'sku'      => $p->get_sku(),
        'precio'   => $p->get_regular_price(),
        'stock'    => $p->managing_stock() ? (int) $p->get_stock_quantity() : null,
        'estado'   => $p->get_stock_status(),
    ];
}

/**
 * COMPRUEBA UN CAMBIO sin guardar nada: nombre, código, precio normal o stock.
 * Devuelve [objeto, null] o [null, respuesta de error].
 */
function waka_hub_validar(array $d): array
{
    $p = waka_hub_objetivo($d);
    if (!$p) return [null, waka_hub_error('no_existe', 'Ese producto no está en la tienda.', 404)];
    if (array_key_exists('nombre', $d)) {
        $n = waka_hub_nombre($d['nombre']);
        if ($n === '' || mb_strlen($n) > 180) return [null, waka_hub_error('nombre', 'El nombre no es válido.')];
        if ($p->get_type() === 'variation') return [null, waka_hub_error('nombre', 'El nombre se cambia en el producto, no en un color.')];
    }
    if (array_key_exists('sku', $d)) {
        $sku = trim((string) $d['sku']);
        if (!preg_match('/^[A-Za-z0-9._\-]{1,60}$/', $sku)) return [null, waka_hub_error('sku', 'El código no es válido.')];
        if (!wc_product_has_unique_sku($p->get_id(), $sku)) {
            return [null, waka_hub_error('sku_repetido', 'Ese código ya lo tiene otro producto de la tienda.', 409)];
        }
    }
    if (array_key_exists('precio', $d)) {
        $precio = (string) $d['precio'];
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $precio) || (float) $precio <= 0) {
            return [null, waka_hub_error('precio', 'El precio no es válido.')];
        }
        if ($p->get_type() === 'variable') return [null, waka_hub_error('precio', 'Un producto con colores lleva el precio en cada color.')];
    }
    if (array_key_exists('stock', $d)) {
        $st = $d['stock'];
        if (!is_int($st) || $st < 0 || $st > 999999) return [null, waka_hub_error('stock', 'La cantidad no es válida.')];
    }
    return [$p, null];
}

/** El nombre como lo guarda la tienda: sin etiquetas ni saltos de línea. */
function waka_hub_nombre($n): string
{
    return trim(sanitize_text_field((string) $n));
}

/**
 * APLICA un cambio ya comprobado. El stock de un color que usa las unidades
 * del producto se pone en el producto: escribirle uno propio lo separaría.
 */
function waka_hub_aplicar($p, array $d): array
{
    if (array_key_exists('nombre', $d)) $p->set_name(waka_hub_nombre($d['nombre']));
    if (array_key_exists('sku', $d))    $p->set_sku(trim((string) $d['sku']));
    if (array_key_exists('precio', $d)) $p->set_regular_price((string) $d['precio']);
    $en_padre = false;
    if (array_key_exists('stock', $d)) {
        $quien = $p;
        if ($p->get_type() === 'variation' && method_exists($p, 'get_stock_managed_by_id')
            && (int) $p->get_stock_managed_by_id() !== (int) $p->get_id()) {
            $quien = wc_get_product($p->get_stock_managed_by_id());
            $en_padre = true;
        }
        $quien->set_manage_stock(true);
        $quien->set_stock_quantity((int) $d['stock']);
        if ($quien !== $p) $quien->save();
    }
    $p->save();
    $r = waka_hub_resumen(wc_get_product($p->get_id()));
    if ($en_padre) {
        $pp = wc_get_product($p->get_parent_id());
        $r['stock'] = $pp->managing_stock() ? (int) $pp->get_stock_quantity() : null;
        $r['estado'] = $pp->get_stock_status();
    }
    $r['en_padre'] = $en_padre;
    return $r;
}

/** CAMBIA UN PRODUCTO. Todo se comprueba antes de guardar nada. */
function waka_hub_producto(WP_REST_Request $req): WP_REST_Response
{
    $d = json_decode((string) $req->get_body(), true);
    if (!is_array($d)) return waka_hub_error('datos', 'La petición no se entiende.');
    list($p, $err) = waka_hub_validar($d);
    if ($err) return $err;
    try {
        $r = waka_hub_aplicar($p, $d);
    } catch (Exception $ex) {
        return waka_hub_error('tienda', 'La tienda no lo aceptó: ' . $ex->getMessage(), 409);
    }
    return new WP_REST_Response(['ok' => true, 'producto' => $r], 200);
}

/**
 * CAMBIA VARIOS DE UNA VEZ (el precio de todos los colores de un producto).
 * Primero se comprueban TODOS; si uno no vale, no se cambia ninguno. Así el
 * HUB no queda con unos colores cambiados y otros no.
 */
function waka_hub_lote(WP_REST_Request $req): WP_REST_Response
{
    $d = json_decode((string) $req->get_body(), true);
    if (!is_array($d) || !is_array($d['cambios'] ?? null) || !$d['cambios'] || count($d['cambios']) > 200) {
        return waka_hub_error('datos', 'La petición no se entiende.');
    }
    $listos = [];
    $vistos = [];
    $skus = [];
    foreach ($d['cambios'] as $c) {
        $c = (array) $c;
        list($p, $err) = waka_hub_validar($c);
        if ($err) return $err;
        /* Dentro del mismo lote tampoco: el mismo producto dos veces, o el
           mismo código para dos, se niega ANTES de cambiar nada. */
        if (isset($vistos[$p->get_id()])) return waka_hub_error('datos', 'El mismo producto viene dos veces.');
        $vistos[$p->get_id()] = true;
        if (array_key_exists('sku', $c)) {
            $k = strtolower(trim((string) $c['sku']));
            if (isset($skus[$k])) return waka_hub_error('sku_repetido', 'Dos productos no pueden llevar el mismo código.', 409);
            $skus[$k] = true;
        }
        $listos[] = [$p, $c];
    }
    $hechos = [];
    try {
        foreach ($listos as $x) $hechos[] = waka_hub_aplicar($x[0], $x[1]);
    } catch (Exception $ex) {
        return waka_hub_error('tienda', 'La tienda no lo aceptó: ' . $ex->getMessage(), 409);
    }
    return new WP_REST_Response(['ok' => true, 'productos' => $hechos], 200);
}

/**
 * MUEVE EL STOCK de varias cosas de una vez: una venta descuenta, una
 * anulación devuelve. Todo o nada, y con candado: dos ventas a la vez no se
 * llevan la misma última unidad.
 *
 * `clave` hace que repetir la misma petición (porque se cortó la conexión) no
 * descuente dos veces: la segunda contesta lo mismo que la primera.
 * `exigir` = no descontar lo que no hay.
 *
 * El candado ordena las peticiones del HUB entre sí. Una compra en la web a la
 * vez exacta puede ganarle la última unidad: entonces el stock queda en -1, se
 * ve en el HUB y se corrige (WooCommerce descuenta sin candado).
 */
function waka_hub_mover(WP_REST_Request $req): WP_REST_Response
{
    global $wpdb;
    $cuerpo = (string) $req->get_body();
    $d = json_decode($cuerpo, true);
    if (!is_array($d) || empty($d['clave']) || !is_array($d['movimientos'] ?? null) || count($d['movimientos']) > 200) {
        return waka_hub_error('datos', 'La petición no se entiende.');
    }
    $clave = md5((string) $d['clave']);
    $huella = md5(json_encode([$d['movimientos'], !empty($d['exigir'])]));

    $lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', 'waka_hub_stock'));
    if ($lock !== 1) return waka_hub_error('ocupado', 'La tienda está ocupada. Vuelve a intentarlo.', 503);
    try {
        /* LO YA HECHO: si la misma clave vuelve (se cortó la conexión), se
           contesta lo mismo sin mover nada. Con otro contenido, se niega: una
           clave es de UNA petición. Se guarda en una opción y no en caché,
           para que una caché que se vacía no haga descontar dos veces. */
        $hechas = get_option(WAKA_HUB_HECHAS, []);
        if (!is_array($hechas)) $hechas = [];
        if (isset($hechas[$clave])) {
            if (($hechas[$clave]['h'] ?? '') !== $huella) {
                return waka_hub_error('clave_usada', 'Esa petición ya se hizo con otros datos.', 409);
            }
            return new WP_REST_Response($hechas[$clave]['r'] + ['repetida' => true], 200);
        }

        /* Lo que se pide de cada cosa que lleva su propio stock, SUMADO: dos
           líneas del mismo producto, o dos colores que usan las unidades del
           producto, cuentan juntas. */
        $plan = [];
        $pide = [];
        $objs = [];
        foreach ($d['movimientos'] as $m) {
            $m = (array) $m;
            $p = waka_hub_objetivo($m);
            $c = (int) ($m['cantidad'] ?? 0);
            if (!$p || $c === 0 || abs($c) > 999999) return waka_hub_error('no_existe', 'Un producto de la venta no está en la tienda.', 404);
            $dueno = method_exists($p, 'get_stock_managed_by_id') ? (int) $p->get_stock_managed_by_id() : (int) $p->get_id();
            wc_delete_product_transients($dueno);
            $q = wc_get_product($dueno);                  // la cantidad de ahora, no la de caché
            if (!$q || !$q->managing_stock()) { $plan[] = [$p, 0]; continue; }
            $objs[$dueno] = $q;
            $pide[$dueno] = ($pide[$dueno] ?? 0) + $c;
            $plan[] = [$p, $c];
        }
        $faltan = [];
        if (!empty($d['exigir'])) {
            foreach ($pide as $dueno => $c) {
                $hay = (int) $objs[$dueno]->get_stock_quantity();
                if ($c < 0 && $hay + $c < 0) $faltan[] = ['id' => $dueno, 'hay' => max(0, $hay), 'pide' => -$c];
            }
        }
        if ($faltan) return new WP_REST_Response(['ok' => false, 'codigo' => 'sin_stock', 'mensaje' => 'No hay stock suficiente.', 'faltan' => $faltan], 409);

        $quedan = [];
        foreach ($plan as $x) {
            list($p, $c) = $x;
            if ($c !== 0) wc_update_product_stock($p, abs($c), $c < 0 ? 'decrease' : 'increase');
            $p2 = wc_get_product($p->get_id());
            $quedan[] = ['id' => $p2->get_id(), 'stock' => $p2->managing_stock() ? (int) $p2->get_stock_quantity() : null,
                         'estado' => $p2->get_stock_status()];
        }
        $res = ['ok' => true, 'quedan' => $quedan];
        $hechas[$clave] = ['h' => $huella, 'r' => $res, 't' => time()];
        /* Se guardan las de los últimos 90 días, y como mucho 3000. */
        $hechas = array_filter($hechas, function ($x) { return ($x['t'] ?? 0) > time() - 90 * DAY_IN_SECONDS; });
        if (count($hechas) > 3000) $hechas = array_slice($hechas, -3000, null, true);
        update_option(WAKA_HUB_HECHAS, $hechas, false);
        return new WP_REST_Response($res, 200);
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'waka_hub_stock'));
    }
}

/* ─────────────────────────  LA PANTALLA EN WORDPRESS  ───────────────────────── */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'HUB Waka', 'HUB Waka', 'manage_woocommerce', 'waka-hub', 'waka_hub_pantalla');
});

function waka_hub_pantalla(): void
{
    if (!current_user_can('manage_woocommerce')) return;
    $nueva = '';
    if (isset($_POST['waka_hub_generar']) && check_admin_referer('waka_hub_generar')) {
        $nueva = bin2hex(random_bytes(24));
        update_option(WAKA_HUB_OPCION, $nueva, false);
    }
    if (isset($_POST['waka_hub_quitar']) && check_admin_referer('waka_hub_generar')) {
        delete_option(WAKA_HUB_OPCION);
    }
    $clave = (string) get_option(WAKA_HUB_OPCION, '');
    echo '<div class="wrap"><h1>HUB Waka · Conector</h1>';
    echo '<p>Deja que el HUB cambie en la tienda el <strong>stock, el precio, el nombre y el código</strong> de los productos. Nada más.</p>';
    if ($nueva !== '') {
        echo '<div class="notice notice-success"><p><strong>Clave nueva:</strong> <code style="font-size:14px;user-select:all">'
           . esc_html($nueva) . '</code></p><p>Cópiala y pégala en el HUB: <em>Configuración › Tienda › Conector</em>. '
           . 'No se vuelve a enseñar.</p></div>';
    }
    echo '<p>Estado: ' . ($clave !== '' ? '<strong>con clave</strong> (termina en ' . esc_html(substr($clave, -4)) . ')'
                                         : '<strong>sin clave</strong>: el HUB todavía no puede cambiar nada.') . '</p>';
    echo '<form method="post">';
    wp_nonce_field('waka_hub_generar');
    echo '<p><button class="button button-primary" name="waka_hub_generar" value="1">'
       . ($clave !== '' ? 'GENERAR OTRA CLAVE (la de ahora deja de valer)' : 'GENERAR CLAVE') . '</button> ';
    if ($clave !== '') echo '<button class="button" name="waka_hub_quitar" value="1">Quitar la clave</button>';
    echo '</p></form></div>';
}
