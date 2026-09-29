<?php
/**
 * HUB Waka — arranque
 * Se incluye desde index.php e instalar.php. No hace nada visible por sí solo.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('El HUB necesita PHP 8.1 o superior. Este servidor tiene ' . PHP_VERSION . '.');
}

/* QUÉ VERSIÓN DEL HUB ESTÁ SUBIDA. Se pinta al pie de cada pantalla.
   Existe porque un parche subido a medias —o el ZIP anterior subido por
   error— es indistinguible del bueno mirando la pantalla, y se pierde media
   tarde averiguando si el fallo es del código o de la subida. Con esto se mira
   el pie y se sabe. Se cambia A MANO en cada entrega: un número que se genera
   solo acabaría diciendo la fecha del servidor y no la del código. */
const HUB_VERSION = '5a';
const HUB_VERSION_FECHA = '2026-09-29';

define('HUB_INICIO', microtime(true));
define('HUB_RAIZ',   dirname(__DIR__, 2));          // carpeta pública del sitio
define('HUB_APP',    HUB_RAIZ . '/app');
define('HUB_VISTAS', HUB_APP . '/vistas');
define('HUB_SUBIDAS',HUB_RAIZ . '/uploads');

/**
 * La configuración vive FUERA de la carpeta pública, un nivel más arriba.
 * En el hosting eso es /home/<cuenta>/waka-config/config.php
 * Nunca se sube por FTP ni se guarda en el repositorio: la escribe el instalador.
 */
define('HUB_CONFIG_DIR',  dirname(HUB_RAIZ) . '/waka-config');
define('HUB_CONFIG_FILE', HUB_CONFIG_DIR . '/config.php');

mb_internal_encoding('UTF-8');
date_default_timezone_set('America/Lima');
setlocale(LC_TIME, 'es_PE.UTF-8', 'es_PE', 'Spanish');

// Errores: al log siempre, a pantalla solo si la configuración lo pide.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once HUB_APP . '/nucleo/ayudas.php';

/**
 * Ningún error deja al usuario mirando una pantalla en blanco.
 * El detalle va al log del servidor; a la persona se le da la pantalla del HUB
 * con un mensaje que se entiende y un número para poder buscarlo en el log.
 */
set_exception_handler(function (Throwable $ex): void {
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log('[HUB ' . $ref . '] ' . get_class($ex) . ': ' . $ex->getMessage()
              . ' en ' . $ex->getFile() . ':' . $ex->getLine());
    if (function_exists('cortar') && !headers_sent()) {
        cortar(500, 'Algo se rompió de este lado',
                    'No es culpa tuya y no se perdió nada de lo ya guardado. Vuelve a '
                  . 'intentarlo; si sigue igual, avísale a Bloomit con esta referencia: ' . $ref . '.');
    }
    http_response_code(500);
    exit('Error interno. Referencia: ' . $ref);
});

// Los avisos y notices se registran, pero NO tumban la página: un índice que
// falta no vale una pantalla de error en la cara de un asesor a media venta.
// Los errores fatales sí, y también acaban con pantalla propia.
register_shutdown_function(function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log('[HUB ' . $ref . '] fatal: ' . $e['message'] . ' en ' . $e['file'] . ':' . $e['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Error · HUB Waka</title>'
           , '<body style="font:15px/1.6 system-ui,sans-serif;max-width:32rem;margin:18vh auto;padding:0 24px">'
           , '<h1 style="font-size:22px">Algo se rompió de este lado</h1>'
           , '<p>No es culpa tuya y no se perdió nada de lo ya guardado. Vuelve a intentarlo; '
           , 'si sigue igual, avísale a Bloomit con esta referencia: <strong>', $ref, '</strong>.</p>';
    }
});

require_once HUB_APP . '/nucleo/bd.php';
require_once HUB_APP . '/nucleo/bitacora.php';
require_once HUB_APP . '/nucleo/sesion.php';
require_once HUB_APP . '/nucleo/permisos.php';
require_once HUB_APP . '/nucleo/vista.php';
require_once HUB_APP . '/nucleo/menu.php';
require_once HUB_APP . '/nucleo/frases.php';
require_once HUB_APP . '/nucleo/metas.php';
require_once HUB_APP . '/nucleo/fotos.php';

/* ── Módulo 2: clientes y pedidos ──────────────────────────────────────
   El orden importa poco porque son funciones sueltas, pero listas.php va
   antes que pagos.php y pedidos.php porque los dos preguntan por métodos de
   pago y motivos, y cashback.php antes que pagos.php por lo mismo. */
require_once HUB_APP . '/nucleo/listas.php';
require_once HUB_APP . '/nucleo/ubigeo.php';
require_once HUB_APP . '/nucleo/clientes.php';
require_once HUB_APP . '/nucleo/cashback.php';
require_once HUB_APP . '/nucleo/pedidos.php';
require_once HUB_APP . '/nucleo/descuentos.php';
require_once HUB_APP . '/nucleo/pagos.php';
require_once HUB_APP . '/nucleo/whatsapp.php';
require_once HUB_APP . '/nucleo/rotulo.php';
require_once HUB_APP . '/nucleo/nubefact.php';
require_once HUB_APP . '/nucleo/catalogo.php';
require_once HUB_APP . '/nucleo/tienda.php';
require_once HUB_APP . '/nucleo/stock_web.php';
require_once HUB_APP . '/nucleo/conector.php';
require_once HUB_APP . '/nucleo/tienda_web.php';
require_once HUB_APP . '/nucleo/stock_venta.php';
require_once HUB_APP . '/nucleo/alistar.php';
require_once HUB_APP . '/nucleo/preventa.php';
require_once HUB_APP . '/nucleo/lote_excel.php';
require_once HUB_APP . '/nucleo/repuestos.php';
require_once HUB_APP . '/nucleo/garantias.php';
require_once HUB_APP . '/nucleo/bonos.php';
require_once HUB_APP . '/nucleo/rachas.php';


/** ¿Está instalado el HUB? */
function hub_instalado(): bool
{
    return is_file(HUB_CONFIG_FILE);
}

/** Carga la configuración. Devuelve el array o null si no existe. */
function hub_config(): ?array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    if (!hub_instalado()) return null;
    $cfg = require HUB_CONFIG_FILE;
    if (!empty($cfg['depurar'])) ini_set('display_errors', '1');
    if (!empty($cfg['log'])) ini_set('error_log', $cfg['log']);
    return $cfg;
}
