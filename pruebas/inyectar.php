<?php
/**
 * Se inyecta con auto_prepend_file al servidor de pruebas.
 *
 * Es el ÚNICO punto de contacto entre las pruebas y el producto, y ni siquiera
 * es nuevo: $GLOBALS['__pdo_instalacion'] es el mismo gancho que ya usaba
 * instalar.php para trabajar antes de que exista el archivo de configuración.
 * Dentro del HUB no hay una sola línea de código de pruebas.
 */
require_once __DIR__ . '/banco.php';

$archivo = getenv('WAKA_BANCO') ?: (sys_get_temp_dir() . '/waka-http.sqlite');

$pdo = new PdoPruebas('sqlite:' . $archivo, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$GLOBALS['__pdo_instalacion'] = $pdo;

/* LA TIENDA DE MENTIRA (módulo 3b). Si correr3.php deja el archivo, el
   servidor contesta como WooCommerce con lo que diga ahí; si no, no se toca
   nada. Vive en la carpeta de pruebas, no en el producto. */
/* Con el puerto en el nombre: dos servidores de pruebas a la vez no se pisan. */
$__tienda_archivo = sys_get_temp_dir() . '/waka-tienda-falsa-' . (int)($_SERVER['SERVER_PORT'] ?? 0) . '.json';
if (is_file($__tienda_archivo)) {
    require_once __DIR__ . '/tienda_falsa.php';
    $GLOBALS['__tienda_datos'] = json_decode((string) file_get_contents($__tienda_archivo), true) ?: [];
    $GLOBALS['__tienda_transporte'] = tienda_falsa($GLOBALS['__tienda_datos']);
    /* Las fotos: dirección => bytes en base64. */
    $GLOBALS['__tienda_fotos'] = function (string $src) {
        /* Como en el 3b.3 con un hosting que no deja usar una función. */
        if (!empty($GLOBALS['__tienda_datos']['fotos_revientan'])) throw new Error('Call to undefined function gethostbynamel()');
        return isset($GLOBALS['__tienda_datos']['fotos'][$src]) ? base64_decode($GLOBALS['__tienda_datos']['fotos'][$src]) : null;
    };
    /* La base de datos se duerme mientras se lee la tienda (3b.4): la
       conexión deja de contestar y el banco la reabre con su gancho. */
    if (!empty($GLOBALS['__tienda_datos']['duerme_al_leer'])) {
        $GLOBALS['__pdo_buena'] = $pdo;
        $GLOBALS['__bd_reabrir'] = function () {
            $GLOBALS['__pdo_instalacion'] = $GLOBALS['__pdo_buena'];
            @file_put_contents(sys_get_temp_dir() . '/waka-reabierta-' . (int)($_SERVER['SERVER_PORT'] ?? 0), '1');
        };
        $normal = $GLOBALS['__tienda_transporte'];
        $GLOBALS['__tienda_transporte'] = function (...$a) use ($normal) {
            $GLOBALS['__pdo_instalacion'] = new class('sqlite::memory:') extends PDO {
                public function query(string $q, ?int $m = null, mixed ...$x): PDOStatement|false
                { throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'); }
                public function prepare(string $q, array $o = []): PDOStatement|false
                { throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'); }
            };
            return $normal(...$a);
        };
    }
    /* Una lectura que revienta por dentro (no una tienda caída). */
    if (!empty($GLOBALS['__tienda_datos']['revienta'])) {
        $GLOBALS['__tienda_transporte'] = function () { throw new Error('Call to undefined function curl_multi_exec()'); };
    }
}

/* EL CONECTOR (3c): si correr3.php deja el archivo, la tienda también acepta
   escrituras por el plugin DE VERDAD, sobre un WordPress de mentira cuyo
   estado (productos, clave, peticiones ya hechas) vive en ese archivo entre
   petición y petición. */
$__wp_archivo = sys_get_temp_dir() . '/waka-wp-falso-' . (int)($_SERVER['SERVER_PORT'] ?? 0) . '.json';
if (is_file($__wp_archivo)) {
    require_once __DIR__ . '/wp_falso.php';
    $__wp_estado = json_decode((string) file_get_contents($__wp_archivo), true) ?: [];
    foreach (['productos', 'opciones', 'transitorios'] as $__k) {
        if (isset($__wp_estado[$__k])) $GLOBALS['__wp'][$__k] = $__wp_estado[$__k];
    }
    $GLOBALS['__wp']['caida'] = !empty($__wp_estado['caida']);
    $GLOBALS['__wp']['corta'] = !empty($__wp_estado['corta']);
    $GLOBALS['__conector_transporte'] = wp_falso_transporte();
    register_shutdown_function(function () use ($__wp_archivo) {
        $e = json_decode((string) @file_get_contents($__wp_archivo), true) ?: [];
        foreach (['productos', 'opciones', 'transitorios'] as $k) $e[$k] = $GLOBALS['__wp'][$k];
        $e['llamadas'] = array_merge($e['llamadas'] ?? [], $GLOBALS['__wp']['llamadas'] ?? []);
        file_put_contents($__wp_archivo, json_encode($e));
    });
}
