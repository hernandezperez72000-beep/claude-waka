<?php
declare(strict_types=1);

/** Escapa para HTML. Se usa SIEMPRE al imprimir algo que venga de la base. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Ruta absoluta dentro del sitio. */
function url(string $ruta = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if ($base === '.') $base = '';
    return $base . '/' . ltrim($ruta, '/');
}

/**
 * Dirección de un archivo estático con su versión pegada: /assets/css/hub.css?v=1757…
 *
 * Sin esto, el service worker y el navegador siguen sirviendo el CSS viejo
 * después de subir una versión nueva, y la pantalla sale rota o a medio pintar
 * hasta que la persona limpia la caché a mano — que no va a hacer.
 * La versión es la fecha del archivo: cambia sola al subirlo, no hay que
 * acordarse de tocar ningún número.
 */
function activo(string $ruta): string
{
    static $versiones = [];
    if (!isset($versiones[$ruta])) {
        $archivo = HUB_RAIZ . '/' . ltrim($ruta, '/');
        $versiones[$ruta] = is_file($archivo) ? (string) filemtime($archivo) : '1';
    }
    return url($ruta) . '?v=' . $versiones[$ruta];
}

/** Redirige y termina. */
function ir(string $ruta): never
{
    header('Location: ' . url($ruta));
    exit;
}

/**
 * Más tiempo para una página larga (leer la tienda, bajar fotos). Algunos
 * hostings apagan set_time_limit: en PHP 8 llamarla entonces revienta, así
 * que se pregunta antes.
 */
function tiempo_extra(int $segundos): void
{
    if (function_exists('set_time_limit')) @set_time_limit($segundos);
}

/** Termina con un código HTTP y su pantalla. */
function cortar(int $codigo, string $titulo = '', string $texto = ''): never
{
    http_response_code($codigo);
    $datos = ['codigo' => $codigo, 'titulo' => $titulo, 'texto' => $texto];
    require HUB_VISTAS . '/errores/http.php';
    exit;
}

/** Dato del POST/GET, ya recortado. */
function pedir(string $clave, string $de = 'post', string $por_defecto = ''): string
{
    $fuente = $de === 'get' ? $_GET : $_POST;
    $v = $fuente[$clave] ?? $por_defecto;
    return is_string($v) ? trim($v) : $por_defecto;
}

function pedir_int(string $clave, string $de = 'post', ?int $por_defecto = null): ?int
{
    $v = pedir($clave, $de, '');
    if ($v === '') return $por_defecto;
    return ctype_digit(ltrim($v, '-')) ? (int)$v : $por_defecto;
}

/* ─────────────────────────  DINERO  ─────────────────────────
   Regla del proyecto: el dinero se guarda SIEMPRE como entero de
   céntimos. Nunca float, nunca decimal en PHP. */

/**
 * "1234.50", "1,234.50", "S/ 30 000" → céntimos enteros.
 * Devuelve null si no se entiende, para que quien llama pueda avisar
 * en vez de guardar un cero en silencio.
 * El dinero se guarda SIEMPRE como entero de céntimos.
 */
function a_centimos(string $texto): ?int
{
    $limpio = trim($texto);
    $limpio = preg_replace('/^S\/\.?\s*/u', '', $limpio);          // quita "S/" o "S/."
    $limpio = str_replace([' ', "\xc2\xa0", ','], '', $limpio);      // espacios y separador de miles
    // Se acota a 15 dígitos: con 19 el entero desbordaría a float y la función
    // moriría con un TypeError, que es justo la pantalla en blanco que se
    // quiere evitar. Nadie escribe una meta de mil billones de soles.
    if ($limpio === '' || !preg_match('/^-?\d{1,15}(\.\d{1,2})?$/', $limpio)) return null;

    // Se parte por el punto y se suma en enteros: nunca pasa por float.
    $neg = $limpio[0] === '-';
    if ($neg) $limpio = substr($limpio, 1);
    [$enteros, $decimales] = array_pad(explode('.', $limpio, 2), 2, '');
    $centimos = (int)$enteros * 100 + (int)str_pad(substr($decimales, 0, 2), 2, '0');
    return $neg ? -$centimos : $centimos;
}

/**
 * El tope de cualquier importe que se teclea: S/ 10,000,000 en céntimos.
 *
 * No es una manía: `precio * cantidad` con un precio de quince dígitos
 * desborda PHP_INT_MAX, se convierte en float y acaba guardado en una columna
 * BIGINT. Un solo pedido así destroza el «por cobrar» del panel del país, y
 * con MySQL en modo estricto ni siquiera se guarda: le sale una pantalla de
 * error al asesor a media venta. Ningún importe real de Waka se acerca.
 */
const DINERO_MAXIMO_CENTIMOS = 1000000000;

/** ¿Este importe está dentro de lo que puede ser un importe de verdad? */
function dinero_razonable(?int $centimos): bool
{
    return $centimos !== null && $centimos >= 0 && $centimos <= DINERO_MAXIMO_CENTIMOS;
}

/**
 * Escapa los comodines de LIKE.
 *
 * Sin esto, buscar «_» casa con cualquier carácter y «%» con cualquier cosa:
 * no hay inyección (todo va parametrizado) pero el buscador devuelve lo que
 * nadie pidió, y quien busca un documento con guion bajo no entiende nada.
 */
function like_seguro(string $texto): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $texto);
}

/**
 * El símbolo de la moneda de quien está mirando.
 *
 * Estaba escrito «S/» a mano en las dos funciones de abajo, así que a México
 * se le pintaban sus pesos con el símbolo del sol. La columna `paises.simbolo`
 * existía desde el módulo 1 y no la leía nadie.
 *
 * Se toma del país de la persona y no del importe porque un importe es un
 * entero y no sabe de dónde viene: quien tiene que garantizar que la cifra es
 * de un solo país es la pantalla que la arma, y por eso el panel y el reporte
 * acotan el dinero a un país antes de sumarlo.
 */
function simbolo_moneda(bool $olvidar = false): string
{
    static $s = null;
    if ($olvidar) { $s = null; unset($GLOBALS['__moneda_fijada']); return ''; }
    // Una pantalla puede fijar la moneda del país que está enseñando.
    if (isset($GLOBALS['__moneda_fijada'])) return (string)$GLOBALS['__moneda_fijada'];
    if ($s !== null) return $s;

    $s = 'S/';
    try {
        $u = function_exists('yo') ? yo() : null;
        if ($u && !empty($u['pais_id'])) {
            $v = valor('SELECT simbolo FROM paises WHERE id = ?', [(int)$u['pais_id']]);
            if (is_string($v) && trim($v) !== '') $s = trim($v);
        }
    } catch (Throwable $ex) {
        error_log('[HUB] simbolo_moneda: ' . $ex->getMessage());
    }
    return $s;
}

/** Tira ese símbolo. La llama yo_olvidar(): la moneda es la de UNA persona. */
function simbolo_moneda_olvidar(): void
{
    simbolo_moneda(true);
}

/**
 * Fija el símbolo del PAÍS que se está mirando, no el de quien mira.
 *
 * Lo usa el reporte de pagos, que deja elegir país a quien cruza: sin esto, el
 * CEO peruano leía los pesos de México con un «S/» delante.
 */
function simbolo_moneda_de_pais(int $pais_id): void
{
    try {
        $v = valor('SELECT simbolo FROM paises WHERE id = ?', [$pais_id]);
        if (is_string($v) && trim($v) !== '') {
            simbolo_moneda(true);
            $GLOBALS['__moneda_fijada'] = trim($v);
        }
    } catch (Throwable $ex) {
        error_log('[HUB] simbolo_moneda_de_pais: ' . $ex->getMessage());
    }
}

/** 123450 → "S/ 1,234.50" */
function soles(?int $centimos, bool $simbolo = true): string
{
    $centimos = (int)$centimos;
    $txt = number_format($centimos / 100, 2, '.', ',');
    return $simbolo ? simbolo_moneda() . ' ' . $txt : $txt;
}

/** 123450 → "S/ 1,235" (sin decimales, para tarjetas y titulares) */
function soles_corto(?int $centimos): string
{
    return simbolo_moneda() . ' ' . number_format(((int)$centimos) / 100, 0, '.', ',');
}

/**
 * Separa un importe en base imponible e IGV.
 *
 * Todavía no se pinta en ninguna pantalla: hace falta el día que se conecte la
 * facturación electrónica, y está escrito ahora porque el reparto depende de
 * una decisión —si el precio ya incluye IGV— que es barata de fijar con cero
 * pedidos cargados y cara con tres mil.
 *
 * Los precios de Waka YA incluyen IGV, así que la base sale de dividir, no de
 * sumar. Se reparte en enteros y el redondeo se le da al IGV, para que
 * base + igv sea EXACTAMENTE el total: si se calcularan por separado, la
 * boleta acabaría descuadrada un céntimo.
 */
function desglose_igv(int $total_centimos, ?int $pct = null, ?bool $incluido = null): array
{
    // Los dos parámetros existen para poder probar la aritmética sin base de
    // datos. En el HUB nunca se pasan: mandan los ajustes.
    $pct      = $pct      ?? max(0, (int) ajuste('igv_porcentaje', 18));
    $incluido = $incluido ?? (bool) ajuste('precios_incluyen_igv', true);
    $pct = max(0, $pct);

    if ($pct === 0) return ['base' => $total_centimos, 'igv' => 0, 'total' => $total_centimos];

    /* Multiplicar por 100 desbordaría el entero con importes absurdos y esto
       moriría con un error. No debería llegar nunca uno así —todo importe pasa
       por dinero_razonable()— pero una función de dinero no puede fiarse de que
       la llamen bien. */
    if (abs($total_centimos) > DINERO_MAXIMO_CENTIMOS) {
        return ['base' => $total_centimos, 'igv' => 0, 'total' => $total_centimos];
    }

    if ($incluido) {
        $base = intdiv($total_centimos * 100, 100 + $pct);
        return ['base' => $base, 'igv' => $total_centimos - $base, 'total' => $total_centimos];
    }
    /* Ojo si algún día se apaga «los precios incluyen IGV»: en esa rama lo que
       entra es la BASE y el total sale mayor. Quien la llame con el total de un
       pedido estaría cobrando de más. Hoy el ajuste está encendido. */
    $igv = intdiv($total_centimos * $pct, 100);
    return ['base' => $total_centimos, 'igv' => $igv, 'total' => $total_centimos + $igv];
}

/* ─────────────────────────  FECHAS  ───────────────────────── */

/**
 * ¿Es una fecha ISO que EXISTE? `2026-13-45` casa con cualquier regex de
 * «cuatro-dos-dos» y no es una fecha. checkdate() es quien lo sabe.
 * Vivía escrita a mano dentro del reporte de pagos; escribirla otra vez en la
 * segunda pantalla que la necesita es como nacen las dos versiones de la misma
 * regla, así que vive aquí y las dos la llaman.
 */
function fecha_valida(?string $iso): bool
{
    if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** «Jueves 11/09/2026» — para una fecha futura, el día de la semana es la
    mitad de la información: nadie sabe de memoria qué día cae el 11. */
function fecha_con_dia(?string $iso): string
{
    if (!fecha_valida($iso)) return fecha_corta($iso);
    $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $t = strtotime((string)$iso);
    return $dias[(int)date('w', $t)] . ' ' . date('d/m/Y', $t);
}

function fecha_corta(?string $iso): string
{
    if (!$iso) return '—';
    $t = strtotime($iso);
    return $t ? date('d/m/Y', $t) : '—';
}

function fecha_hora(?string $iso): string
{
    if (!$iso) return '—';
    $t = strtotime($iso);
    if (!$t) return '—';
    return date('d/m/Y', $t) . ' · ' . hora_am_pm($t);
}

function hora_am_pm(int $t): string
{
    $h = (int)date('G', $t);
    $m = date('i', $t);
    $suf = $h < 12 ? 'a. m.' : 'p. m.';
    $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
    return $h12 . ':' . $m . ' ' . $suf;
}

/** "hace 4 min", "hace 2 h", "ayer" */
function hace(?string $iso): string
{
    if (!$iso) return '';
    $t = strtotime($iso);
    if (!$t) return '';
    $s = time() - $t;
    if ($s < 60)     return 'hace un momento';
    if ($s < 3600)   return 'hace ' . floor($s / 60) . ' min';
    if ($s < 86400)  return 'hace ' . floor($s / 3600) . ' h';
    if ($s < 172800) return 'ayer';
    return 'hace ' . floor($s / 86400) . ' días';
}

/** Franja del día, para las frases del saludo. */
function franja_del_dia(?int $hora = null): string
{
    $h = $hora ?? (int)date('G');
    if ($h >= 5  && $h < 12) return 'manana';
    if ($h >= 12 && $h < 19) return 'tarde';
    if ($h >= 19 && $h < 24) return 'noche';
    return 'madrugada';
}

/** El HUB se pone oscuro solo: de 19:00 a 6:00. */
function es_de_noche(?int $hora = null): bool
{
    $h = $hora ?? (int)date('G');
    return $h >= 19 || $h < 6;
}

/* ─────────────────────────  TEXTO  ───────────────────────── */

/** Iniciales para el círculo del avatar. Nunca un ícono genérico. */
function iniciales(string $nombre, string $apellidos = ''): string
{
    $a = mb_substr(trim($nombre), 0, 1);
    $b = mb_substr(trim($apellidos), 0, 1);
    if ($b === '') {
        $partes = preg_split('/\s+/', trim($nombre)) ?: [];
        $b = isset($partes[1]) ? mb_substr($partes[1], 0, 1) : '';
    }
    return mb_strtoupper($a . $b);
}

function primer_nombre(string $nombre): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    return $partes[0] ?? $nombre;
}

/** Plural sencillo: plural(3,'pedido','pedidos') → "3 pedidos" */
/**
 * Una llave (token, clave secreta) enseñada sin enseñarla: «•••• 3f9a». Se
 * guarda entera, pero en pantalla solo salen sus cuatro últimos caracteres,
 * para saber cuál está puesta. La usan NUBEFACT y la tienda.
 */
function llave_tapada(string $llave): string
{
    $llave = trim($llave);
    if ($llave === '') return '';
    return '•••• ' . mb_substr($llave, -4);
}

function plural(int $n, string $uno, string $varios, bool $con_numero = true): string
{
    $palabra = $n === 1 ? $uno : $varios;
    return $con_numero ? $n . ' ' . $palabra : $palabra;
}

function correo_valido(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Token aleatorio en hexadecimal. */
function token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * IP del visitante.
 *
 * Las cabeceras X-Forwarded-For y CF-Connecting-IP las escribe quien conecta,
 * así que cualquiera puede mentir con ellas: si se creyeran a ciegas, se podría
 * esquivar el bloqueo por intentos y ensuciar la bitácora con IPs inventadas.
 * Por eso solo se leen cuando la conexión viene de un proxy declarado en la
 * configuración ('proxies' => ['10.0.0.1', '172.16.0.0/12', ...]).
 */
function ip_visitante(): string
{
    $directa = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($directa !== '' && ip_es_de_confianza($directa)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return filter_var($directa, FILTER_VALIDATE_IP) ? $directa : '';
}

/** ¿Esta IP es uno de los proxies declarados en la configuración? */
function ip_es_de_confianza(string $ip): bool
{
    $cfg = function_exists('hub_config') ? (hub_config() ?? []) : [];
    foreach (($cfg['proxies'] ?? []) as $rango) {
        if (strpos($rango, '/') === false) {
            if ($ip === $rango) return true;
            continue;
        }
        [$red, $bits] = explode('/', $rango, 2);
        // ::ffff:10.0.0.1 es la misma máquina que 10.0.0.1: en un servidor de
        // pila dual el proxy llega así y si no se normalizara nunca coincidiría.
        $a = @inet_pton(preg_replace('/^::ffff:(?=\d+\.)/i', '', $ip));
        $b = @inet_pton($red);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) continue;
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        $resto = $bits % 8;
        if ($bytes && strncmp($a, $b, $bytes) !== 0) continue;
        if ($resto === 0) return true;
        $mascara = chr((0xFF << (8 - $resto)) & 0xFF);
        if ((($a[$bytes] ?? "\0") & $mascara) === (($b[$bytes] ?? "\0") & $mascara)) return true;
    }
    return false;
}

function navegador(): string
{
    return mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
}

/** Avisos de una página a la siguiente (se leen una sola vez). */
function avisar(string $tipo, string $texto): void
{
    $_SESSION['avisos'][] = ['tipo' => $tipo, 'texto' => $texto];
}

function avisos(): array
{
    $a = $_SESSION['avisos'] ?? [];
    unset($_SESSION['avisos']);
    return $a;
}
