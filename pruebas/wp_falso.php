<?php
declare(strict_types=1);

/**
 * UN WORDPRESS + WOOCOMMERCE DE MENTIRA, lo justo para correr el plugin
 * «Waka HUB · Conector» (wordpress/waka-hub-conector) en el banco de pruebas.
 *
 * El plugin corre en el WordPress de compraenwaka, donde el banco no llega.
 * Con esto se prueba de punta a punta: el HUB firma una petición, el plugin
 * la comprueba con SU código de verdad y cambia los productos de mentira.
 * Si el HUB y el plugin no firmaran igual, aquí se ve.
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

$GLOBALS['__wp'] = ['opciones' => [], 'transitorios' => [], 'rutas' => [], 'productos' => [], 'candado' => 0];

function add_action($gancho, $fn) { $GLOBALS['__wp']['acciones'][$gancho][] = $fn; }
function register_rest_route($ns, $ruta, $def) { $GLOBALS['__wp']['rutas']['/' . $ns . $ruta] = $def; }
function get_option($k, $def = false) { return $GLOBALS['__wp']['opciones'][$k] ?? $def; }
function update_option($k, $v, $auto = null) { $GLOBALS['__wp']['opciones'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__wp']['opciones'][$k]); return true; }
function get_transient($k) { return $GLOBALS['__wp']['transitorios'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__wp']['transitorios'][$k] = $v; return true; }
/* Como WordPress: un «<» suelto se vuelve «&lt;», las etiquetas se van, los
   espacios se juntan. Y al guardar el título, un «&» se vuelve «&amp;». */
function sanitize_text_field($t) {
    $t = preg_replace('/<(?![a-zA-Z\/])/', '&lt;', (string) $t);
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags($t)));
}
function wc_delete_product_transients($id) {}
function current_user_can($c) { return true; }

class WP_Error { public $code; public $message; public $data;
    function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; $this->data = $d; } }
class WP_REST_Response { public $data; public $status;
    function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; }
    function get_status() { return $this->status; } function get_data() { return $this->data; } }
class WP_REST_Request {
    private $m; private $r; private $h; private $b;
    function __construct($m, $r, $h, $b) {
        $this->m = $m; $this->r = $r; $this->b = $b; $this->h = [];
        foreach ($h as $k => $v) $this->h[strtolower(str_replace('-', '_', $k))] = $v;
    }
    function get_method() { return $this->m; } function get_route() { return $this->r; } function get_body() { return $this->b; }
    /* Como WordPress: las cabeceras se guardan en minúsculas y con «_» en vez de «-». */
    function get_header($k) { $k = strtolower(str_replace('-', '_', $k)); return $this->h[$k] ?? null; }
}

/** Un producto de WooCommerce de mentira. */
class ProductoFalso {
    public $d;
    function __construct(array $d) { $this->d = $d + ['tipo' => 'simple', 'padre' => 0, 'nombre' => '', 'sku' => '',
                                                        'precio' => '', 'gestiona' => false, 'stock' => null, 'estado' => 'instock'];
                                      $this->d['guardado'] = $this->d; }
    function get_id() { return $this->d['id']; }
    function get_type() { return $this->d['tipo']; }
    function get_parent_id() { return $this->d['padre']; }
    function get_name() { return $this->d['nombre']; }
    function get_sku() { return $this->d['sku']; }
    function get_regular_price() { return $this->d['precio']; }
    function managing_stock() { return (bool) $this->d['gestiona']; }
    function get_stock_quantity() { return $this->d['stock']; }
    function get_stock_status() { return $this->d['estado']; }
    function get_status() { return $this->d['status'] ?? 'publish'; }
    /* Como WooCommerce: un color sin inventario propio usa el del producto, si él lo lleva. */
    function get_stock_managed_by_id() {
        if ($this->d['tipo'] === 'variation' && !$this->d['gestiona']) {
            $pa = $GLOBALS['__wp']['productos'][$this->d['padre']] ?? null;
            if ($pa && $pa['gestiona']) return $this->d['padre'];
        }
        return $this->d['id'];
    }
    function set_name($n) { $this->d['nombre'] = preg_replace('/&(?![a-z]+;|#\d+;)/i', '&amp;', $n); }
    function set_sku($s) {
        if (!wc_product_has_unique_sku($this->d['id'], $s)) throw new Exception('SKU no válido o duplicado.');
        $this->d['sku'] = $s;
    }
    function set_regular_price($p) { $this->d['precio'] = $p; }
    function set_manage_stock($b) { $this->d['gestiona'] = (bool) $b; }
    function set_stock_quantity($q) { $this->d['stock'] = $q; $this->d['estado'] = $q > 0 ? 'instock' : 'outofstock'; }
    function save() { $GLOBALS['__wp']['productos'][$this->d['id']] = $this->d; }
}
function wc_get_product($id) {
    $d = $GLOBALS['__wp']['productos'][(int) $id] ?? null;
    return $d ? new ProductoFalso($d) : false;
}
function wc_product_has_unique_sku($id, $sku) {
    foreach ($GLOBALS['__wp']['productos'] as $pid => $p) if ($pid !== (int) $id && $p['sku'] === $sku) return false;
    return true;
}
function wc_update_product_stock($p, $n, $op) {
    $d = &$GLOBALS['__wp']['productos'][$p->get_stock_managed_by_id()];
    $d['stock'] = (int) $d['stock'] + ($op === 'decrease' ? -$n : $n);
    $d['estado'] = $d['stock'] > 0 ? 'instock' : 'outofstock';
}

/** $wpdb de mentira: solo el candado. */
class WpdbFalso {
    function prepare($sql, ...$a) { return $sql; }
    function get_var($sql) {
        if (!str_contains($sql, 'GET_LOCK')) return null;
        if (!empty($GLOBALS['__wp']['sin_candado'])) return 0;       // no lo consigue: no hay nada que soltar
        $GLOBALS['__wp']['candado']++;
        return 1;
    }
    function query($sql) { if (str_contains($sql, 'RELEASE_LOCK')) $GLOBALS['__wp']['soltado'] = ($GLOBALS['__wp']['soltado'] ?? 0) + 1; return 1; }
}
$GLOBALS['wpdb'] = new WpdbFalso();
if (!defined('WC_VERSION')) define('WC_VERSION', '9.0.0');

require_once dirname(__DIR__) . '/wordpress/waka-hub-conector/waka-hub-conector.php';
foreach ($GLOBALS['__wp']['acciones']['rest_api_init'] ?? [] as $fn) $fn();

/**
 * La tienda que contesta al HUB: recibe lo que manda conector_llamar() y lo
 * pasa por el plugin de verdad (permiso + ruta). Es el `__conector_transporte`.
 */
function wp_falso_transporte(): callable
{
    return function (string $metodo, string $url, array $cab, string $cuerpo): array {
        $GLOBALS['__wp']['llamadas'][] = [$metodo, $url, $cuerpo];
        if (!empty($GLOBALS['__wp']['caida'])) return ['http' => 0, 'cuerpo' => '', 'red' => 'caída'];
        $ruta = (string) preg_replace('#^https://[^/]+/wp-json#', '', $url);
        $h = [];
        foreach ($cab as $c) { [$k, $v] = array_map('trim', explode(':', $c, 2)); $h[strtolower($k)] = $v; }
        $def = $GLOBALS['__wp']['rutas'][$ruta] ?? null;
        if (!$def || $def['methods'] !== $metodo) return ['http' => 404, 'cuerpo' => json_encode(['code' => 'rest_no_route']), 'red' => ''];
        $req = new WP_REST_Request($metodo, $ruta, $h, $cuerpo);
        $ok = ($def['permission_callback'])($req);
        if ($ok instanceof WP_Error) return ['http' => (int)($ok->data['status'] ?? 403), 'cuerpo' => json_encode(['code' => $ok->code, 'message' => $ok->message]), 'red' => ''];
        $res = ($def['callback'])($req);
        /* LA RESPUESTA SE PIERDE (3e): la tienda hizo el cambio, pero al HUB
           no le llega la respuesta. Es el caso que obliga a la clave de
           repetición: reintentar no puede descontar dos veces. */
        if (!empty($GLOBALS['__wp']['corta'])) return ['http' => 0, 'cuerpo' => '', 'red' => 'se cortó'];
        return ['http' => $res->get_status(), 'cuerpo' => json_encode($res->get_data()), 'red' => ''];
    };
}
