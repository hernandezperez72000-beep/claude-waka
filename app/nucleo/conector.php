<?php
declare(strict_types=1);

/**
 * EL CONECTOR (3c): lo que el HUB le PIDE a la tienda.
 *
 * Las claves de WooCommerce del HUB son de SOLO LECTURA, a propósito. Para
 * escribir en compraenwaka —stock, precio, nombre y código— hay un plugin
 * propio, «Waka HUB · Conector», que solo sabe hacer esas cuatro cosas. Cada
 * petición va firmada con la clave del conector (HMAC-SHA256 de fecha, método,
 * ruta y cuerpo): sin ella la tienda no hace caso, y una petición vieja
 * tampoco vale.
 *
 * La clave se genera en WordPress (WooCommerce › HUB Waka) y se pega aquí, en
 * Configuración › Tienda. Como el token de NUBEFACT: se guarda y no se vuelve
 * a enseñar.
 */

const CONECTOR_ESPACIO = '/waka-hub/v1';

/** La clave del conector y si se puede usar. */
function conector_config(): array
{
    $clave = trim((string) ajuste('conector_clave', ''));
    $t = tienda_config();
    return ['clave' => $clave, 'ruta' => $t['ruta'], 'listo' => $clave !== '' && $t['ruta'] !== ''];
}

/** ¿El conector está puesto y la tienda es de mi país? Solo entonces manda el HUB. */
function conector_listo(): bool
{
    return conector_config()['listo'] && tienda_es_de_mi_pais();
}

/** Una clave del conector: 48 caracteres hexadecimales, como la genera el plugin. */
function conector_clave_valida(string $c): bool
{
    return (bool) preg_match('/^[a-f0-9]{48}$/', $c);
}

/** La firma de una petición. Una sola definición: la misma cuenta que hace el plugin. */
function conector_firma(string $clave, string $fecha, string $metodo, string $ruta, string $cuerpo): string
{
    return hash_hmac('sha256', $fecha . "\n" . strtoupper($metodo) . "\n" . $ruta . "\n" . $cuerpo, $clave);
}

/**
 * LLAMA AL CONECTOR. → ['ok', 'http', 'datos' (la respuesta), 'error' (texto
 * para el asesor)]. Se puede sustituir con `$GLOBALS['__conector_transporte']`
 * (método, url, cabeceras, cuerpo → ['http', 'cuerpo', 'red']) para el banco.
 */
function conector_llamar(string $metodo, string $camino, ?array $datos = null, int $segundos = 20): array
{
    $cfg = conector_config();
    if (!$cfg['listo']) return ['ok' => false, 'http' => 0, 'datos' => [], 'error' => 'Falta poner la clave del conector en Configuración › Tienda.'];
    $ruta   = CONECTOR_ESPACIO . $camino;
    $url    = rtrim($cfg['ruta'], '/') . '/wp-json' . $ruta;
    $cuerpo = $datos === null ? '' : (string) json_encode($datos, JSON_UNESCAPED_UNICODE);
    $fecha  = (string) time();
    $cab = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Waka-Fecha: ' . $fecha,
        'X-Waka-Firma: ' . conector_firma($cfg['clave'], $fecha, $metodo, $ruta, $cuerpo),
        'User-Agent: WakaHUB/' . HUB_VERSION,
    ];

    if (isset($GLOBALS['__conector_transporte']) && is_callable($GLOBALS['__conector_transporte'])) {
        $r = ($GLOBALS['__conector_transporte'])($metodo, $url, $cab, $cuerpo);
    } elseif (function_exists('curl_init')) {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
               CURLOPT_HTTPHEADER => $cab, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $segundos,
               CURLOPT_FOLLOWLOCATION => false];
        if ($cuerpo !== '') $op[CURLOPT_POSTFIELDS] = $cuerpo;
        if (defined('CURLPROTO_HTTPS')) $op[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        curl_setopt_array($ch, $op);
        $txt = curl_exec($ch);
        $r = ['http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'cuerpo' => is_string($txt) ? $txt : '',
              'red' => $txt === false ? curl_error($ch) : ''];
        curl_close($ch);
    } else {
        return ['ok' => false, 'http' => 0, 'datos' => [], 'error' => 'Este servidor no puede llamar a la tienda.'];
    }

    $http = (int)($r['http'] ?? 0);
    $j = json_decode((string)($r['cuerpo'] ?? ''), true);
    $j = is_array($j) ? $j : [];
    if ($http === 0) {
        return ['ok' => false, 'http' => 0, 'datos' => [], 'error' => 'No hubo respuesta de la tienda. Vuelve a intentarlo en un rato.'];
    }
    if ($http >= 200 && $http < 300 && !empty($j['ok'])) return ['ok' => true, 'http' => $http, 'datos' => $j, 'error' => ''];
    return ['ok' => false, 'http' => $http, 'datos' => $j, 'error' => conector_error_texto($http, $j)];
}

/** Lo que dijo la tienda, en palabras para quien está delante. */
function conector_error_texto(int $http, array $j): string
{
    if (($j['code'] ?? '') === 'waka_firma') {
        return 'La tienda no aceptó la clave del conector. Genera una nueva en WordPress y pégala en Configuración › Tienda. '
             . 'Si la clave es la buena, revisa que la hora de la tienda esté bien.';
    }
    if ($http === 401 || $http === 403) {
        return 'La tienda no deja entrar al conector. Puede que un complemento de seguridad de WordPress lo esté bloqueando.';
    }
    if ($http === 404 && ($j['code'] ?? '') === 'rest_no_route') {
        return 'En el WordPress de la tienda falta activar o actualizar «Waka HUB · Conector».';
    }
    $m = trim((string)($j['mensaje'] ?? ''));
    if ($m !== '') return 'La tienda dice: ' . mb_substr($m, 0, 200);
    if ($http >= 500) return 'La tienda tuvo un problema. Vuelve a intentarlo en un rato.';
    return 'La tienda no aceptó el cambio (código ' . $http . ').';
}

/** PROBAR EL CONECTOR. → ['ok', 'texto'] */
/** La versión del conector que necesita este HUB. */
const CONECTOR_VERSION_MINIMA = '1.1.0';

function conector_probar(): array
{
    $r = conector_llamar('GET', '/estado');
    if (!$r['ok']) return ['ok' => false, 'texto' => $r['error']];
    $v = (string)($r['datos']['version'] ?? '0');
    if (version_compare($v, CONECTOR_VERSION_MINIMA, '<')) {
        return ['ok' => false, 'texto' => 'El conector de la tienda es viejo (' . $v . '): descárgalo otra vez de aquí y súbelo en WordPress.'];
    }
    return ['ok' => true, 'texto' => 'El conector funciona (versión ' . (string)($r['datos']['version'] ?? '?') . ').'];
}
