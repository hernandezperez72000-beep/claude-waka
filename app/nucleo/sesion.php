<?php
declare(strict_types=1);

/**
 * Sesión, inicio de sesión y "recordarme 30 días".
 *
 * El recordatorio NO guarda la contraseña: guarda un par selector/validador.
 * En la base solo vive el hash del validador, así que robar la tabla no basta
 * para entrar. Cada uso rota el validador.
 */

const HUB_COOKIE_RECUERDO = 'wk_r';
const HUB_DIAS_RECUERDO_MAX = 90;   // tope duro: el ajuste no puede pasar de aquí
const HUB_MAX_INTENTOS_IP   = 60;   // toda una oficina comparte una IP pública
const HUB_MAX_INTENTOS      = 5;    // por cuenta
const HUB_BLOQUEO_MIN       = 60;

/**
 * Días que dura "recordarme". Se lee del ajuste (Configuración lo cambia)
 * con 30 por defecto: si estuviera fijo en el código, cambiarlo en pantalla
 * no haría nada y la persona creería que sí.
 */
function dias_de_recuerdo(): int
{
    $d = function_exists('ajuste') ? (int) ajuste('recordarme_dias', 30) : 30;
    return max(1, min(HUB_DIAS_RECUERDO_MAX, $d));
}

function sesion_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('wk_s');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $seguro,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = token(32);
}

/* ─────────────────────────  CSRF  ───────────────────────── */

function csrf(): string
{
    return $_SESSION['csrf'] ?? '';
}

function campo_csrf(): string
{
    return '<input type="hidden" name="_t" value="' . e(csrf()) . '">';
}

/** ¿El envío pasó de post_max_size? PHP lo deja vacío sin decir nada. */
function envio_demasiado_grande(): bool
{
    $largo = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($largo <= 0 || !empty($_POST) || !empty($_FILES)) return false;
    $max = ini_bytes((string) ini_get('post_max_size'));
    return $max > 0 && $largo > $max;
}

/** «8M» → 8388608. */
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** Toda escritura pasa por aquí. */
function exigir_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    /* EL ENVÍO PASÓ DEL TAMAÑO QUE ACEPTA EL SERVIDOR (auditoría del 3f):
       PHP tira el formulario entero —también el testigo— y esto decía «la
       página caducó, envíalo otra vez», que vuelve a fallar. Con dos fotos
       por pago pasa más. Se dice lo que es. */
    if (envio_demasiado_grande()) {
        cortar(413, 'Las fotos pesan demasiado',
                    'Juntas pesan más de lo que se puede subir de una vez. Vuelve atrás y adjúntalas otra vez, '
                  . 'o elige fotos más livianas.');
    }
    $enviado = $_POST['_t'] ?? '';
    if (!is_string($enviado) || $enviado === '' || !hash_equals(csrf(), $enviado)) {
        cortar(419, 'La página caducó',
                    'Por seguridad el formulario expiró. Vuelve atrás y envíalo otra vez.');
    }
}

/* ─────────────────────  QUIÉN ESTÁ DENTRO  ───────────────────── */

/**
 * Devuelve el usuario de la sesión, o null. Lo cachea por petición.
 * Después de entrar o de salir hay que llamar a yo_olvidar(), o el resto de
 * la petición seguiría viendo la foto vieja (o un null que ya no es cierto).
 */
function yo(bool $olvidar = false): ?array
{
    static $u = null, $buscado = false;
    if ($olvidar) { $u = null; $buscado = false; return null; }
    if ($buscado) return $u;
    $buscado = true;

    $id = $_SESSION['usuario_id'] ?? null;
    if (!$id) { $u = recordado(); return $u; }

    $u = una(
        'SELECT u.*, r.clave AS rol, r.nombre AS rol_nombre,
                p.nombre AS pais, o.nombre AS oficina, eq.nombre AS equipo
           FROM usuarios u
           JOIN roles r     ON r.id  = u.rol_id
           LEFT JOIN paises p  ON p.id = u.pais_id
           LEFT JOIN oficinas o ON o.id = u.oficina_id
           LEFT JOIN equipos eq ON eq.id = u.equipo_id
          WHERE u.id = ? AND u.activo = 1',
        [$id]
    );
    if (!$u) {
        // La cuenta se apagó o se borró con la sesión abierta.
        salir();                       // salir() vacía la caché…
        $u = null; $buscado = true;    // …y aquí se deja fijada en "no hay nadie".
        return null;
    }
    return $u;
}

function hay_sesion(): bool { return yo() !== null; }

/** Borra la caché de yo() y la de sus permisos. Se llama al entrar y al salir. */
function yo_olvidar(): void
{
    yo(true);
    if (function_exists('permisos_olvidar'))   permisos_olvidar();
    if (function_exists('contadores_olvidar')) contadores_olvidar();
    if (function_exists('pagos_resumen_olvidar')) pagos_resumen_olvidar();
    if (function_exists('simbolo_moneda_olvidar')) simbolo_moneda_olvidar();
    if (function_exists('comprobantes_pendientes_olvidar')) comprobantes_pendientes_olvidar();
    if (function_exists('despacho_pendiente_olvidar')) despacho_pendiente_olvidar();
    if (function_exists('pagos_trabados_olvidar')) pagos_trabados_olvidar();
}

/**
 * Deja pasar solo rutas de este HUB. Sin esto, una petición en forma absoluta
 * ("GET http://otrositio/inicio") acabaría mandando a la persona a ese otro
 * sitio justo después de escribir su contraseña.
 */
function destino_seguro(?string $ruta): string
{
    $ruta = (string) $ruta;

    /* La barra invertida se normaliza a barra normal en los navegadores, así
       que «/\evil.com» acaba siendo «//evil.com» — una dirección absoluta a
       otro dominio disfrazada de ruta interna. Se convierte ANTES de mirar,
       para que la comprobación de abajo vea lo mismo que verá el navegador. */
    $ruta = str_replace('\\', '/', $ruta);

    if ($ruta === '' || $ruta[0] !== '/' || str_starts_with($ruta, '//')) return '/inicio';
    if (str_contains($ruta, "\n") || str_contains($ruta, "\r")) return '/inicio';
    return $ruta;
}

/** Exige sesión; si no hay, manda al acceso guardando a dónde iba. */
function exigir_sesion(): array
{
    $u = yo();
    if (!$u) {
        $_SESSION['despues_de_entrar'] = destino_seguro($_SERVER['REQUEST_URI'] ?? '/inicio');
        ir('/entrar');
    }
    // Contraseña temporal: no se puede navegar hasta cambiarla.
    $ruta = $_SERVER['REQUEST_URI'] ?? '';
    if ((int)$u['debe_cambiar_password'] === 1 && !str_contains($ruta, 'contrasena')) {
        ir('/mi-perfil/contrasena');
    }
    return $u;
}

/* ─────────────────────────  ENTRAR  ───────────────────────── */

/**
 * Intenta iniciar sesión.
 * Devuelve ['ok'=>bool, 'error'=>string, 'restantes'=>int|null].
 * Nunca dice si el correo existe: eso le regalaría media contraseña a
 * quien esté probando.
 */
function entrar(string $email, string $clave, bool $recordarme): array
{
    $ip = ip_visitante();
    $generico = 'Correo o contraseña incorrectos.';

    // El bloqueo por CUENTA sí para en seco: son cinco fallos contra esa
    // contraseña y no hay razón legítima para seguir probando.
    if (intentos_recientes($email, $ip) >= HUB_MAX_INTENTOS) {
        return ['ok' => false, 'restantes' => 0,
                'error' => 'Demasiados intentos con esta cuenta. Espera una hora e inténtalo otra vez.'];
    }

    $u = una('SELECT * FROM usuarios WHERE email = ? LIMIT 1', [mb_strtolower($email)]);

    // Se compara siempre contra un hash, exista o no el usuario: así el tiempo
    // de respuesta no delata qué correos están dados de alta.
    $hash = $u['password_hash'] ?? '$2y$12$imposibledeacertaraaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $bien = password_verify($clave, $hash);

    if (!$bien || !$u || (int)$u['activo'] !== 1) {
        registrar_intento($email, $ip, false);
        // El bloqueo por IP se comprueba DESPUÉS de la contraseña: una oficina
        // entera comparte una sola IP pública, y si parase antes, sesenta
        // despistes entre todos dejarían fuera a los diecinueve asesores aunque
        // escribieran bien su contraseña.
        if (intentos_de_ip($ip) >= HUB_MAX_INTENTOS_IP) {
            return ['ok' => false, 'restantes' => 0,
                    'error' => 'Demasiados intentos fallidos desde esta conexión. '
                             . 'Si tu contraseña es correcta seguirás pudiendo entrar; '
                             . 'si no, avisa a Administración.'];
        }
        $quedan = max(0, HUB_MAX_INTENTOS - intentos_recientes($email, $ip));
        return ['ok' => false, 'error' => $generico, 'restantes' => $quedan];
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        actualizar('usuarios', (int)$u['id'],
                   ['password_hash' => password_hash($clave, PASSWORD_DEFAULT)]);
    }

    registrar_intento($email, $ip, true);
    abrir_sesion((int)$u['id'], $recordarme);
    return ['ok' => true, 'error' => '', 'restantes' => null];
}

function abrir_sesion(int $usuario_id, bool $recordarme = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    yo_olvidar();
    $_SESSION['usuario_id'] = $usuario_id;
    $_SESSION['csrf']       = token(32);
    actualizar('usuarios', $usuario_id, ['ultimo_acceso' => date('Y-m-d H:i:s')]);
    bitacora('acceso.entrar', 'usuario', $usuario_id);
    if ($recordarme) crear_recuerdo($usuario_id);
}

function salir(): void
{
    /* Un voucher subido y nunca usado no puede quedarse en el disco sin dueño:
       nadie lo va a borrar después. */
    if (function_exists('voucher_pendiente_olvidar')) voucher_pendiente_olvidar();

    yo_olvidar();
    $u = $_SESSION['usuario_id'] ?? null;
    if ($u) bitacora('acceso.salir', 'usuario', (int)$u);
    olvidar_recuerdo();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
        session_start();
        $_SESSION['csrf'] = token(32);
    }
}

/* ────────────────  RECORDARME (30 DÍAS POR DEFECTO)  ──────────────── */

function crear_recuerdo(int $usuario_id): void
{
    // Administración, Facturación y Desarrollador no se recuerdan: son las
    // cuentas que tocan el dinero de todos, y una sesión de 30 días en un
    // celular perdido sería el HUB entero. Facturación trabaja con el HUB
    // abierto todo el día y el sondeo de la bandeja mantiene viva su sesión
    // mientras la pantalla esté abierta, así que no le estorba.
    $fila = una('SELECT r.clave AS rol, u.rol_id FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?',
                [$usuario_id]);
    $rol = (string)($fila['rol'] ?? '');
    if (in_array($rol, ['administracion', 'facturacion', 'desarrollador'], true)) return;
    /* Ni quien cambia la tienda web (3g: Almacén el stock, Marketing nombre,
       código y precio): un celular perdido con la sesión abierta cambiaría
       lo que ven y pagan los clientes de la web. Por permiso: el día que otro
       rol lo tenga, tampoco se recuerda. */
    $u_r = ['rol' => $rol, 'rol_id' => (int)($fila['rol_id'] ?? 0)];
    if (puede_el($u_r, 'stock.ajustar') || puede_el($u_r, 'tienda.datos')) return;

    $selector  = token(9);
    $validador = token(32);
    $expira    = date('Y-m-d H:i:s', time() + dias_de_recuerdo() * 86400);

    insertar('sesiones', [
        'usuario_id'      => $usuario_id,
        'selector'        => $selector,
        'validador_hash'  => hash('sha256', $validador),
        'ip'              => ip_visitante(),
        'user_agent'      => navegador(),
        'expira_en'       => $expira,
    ]);

    poner_cookie_recuerdo($selector . ':' . $validador, time() + dias_de_recuerdo() * 86400);
}

function poner_cookie_recuerdo(string $valor, int $expira): void
{
    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(HUB_COOKIE_RECUERDO, $valor, [
        'expires'  => $expira,
        'path'     => '/',
        'secure'   => $seguro,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function olvidar_recuerdo(): void
{
    $c = $_COOKIE[HUB_COOKIE_RECUERDO] ?? '';
    if ($c && str_contains($c, ':')) {
        [$sel] = explode(':', $c, 2);
        q('DELETE FROM sesiones WHERE selector = ?', [$sel]);
    }
    poner_cookie_recuerdo('', time() - 3600);
}

/** Si hay cookie válida, abre la sesión sola y devuelve el usuario. */
function recordado(): ?array
{
    $c = $_COOKIE[HUB_COOKIE_RECUERDO] ?? '';
    if (!$c || !str_contains($c, ':')) return null;

    [$sel, $val] = explode(':', $c, 2);
    $fila = una('SELECT * FROM sesiones WHERE selector = ? AND expira_en > NOW()', [$sel]);
    if (!$fila) { olvidar_recuerdo(); return null; }

    if (!hash_equals($fila['validador_hash'], hash('sha256', $val))) {
        // Cookie manipulada: se cierran todas las sesiones recordadas del usuario.
        q('DELETE FROM sesiones WHERE usuario_id = ?', [$fila['usuario_id']]);
        olvidar_recuerdo();
        return null;
    }

    // Rotar el validador en cada uso.
    $nuevo = token(32);
    actualizar('sesiones', (int)$fila['id'], [
        'validador_hash' => hash('sha256', $nuevo),
        'expira_en'      => date('Y-m-d H:i:s', time() + dias_de_recuerdo() * 86400),
    ]);
    poner_cookie_recuerdo($sel . ':' . $nuevo, time() + dias_de_recuerdo() * 86400);

    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int)$fila['usuario_id'];
    $_SESSION['csrf']       = token(32);

    return una(
        'SELECT u.*, r.clave AS rol, r.nombre AS rol_nombre,
                p.nombre AS pais, o.nombre AS oficina, eq.nombre AS equipo
           FROM usuarios u
           JOIN roles r ON r.id = u.rol_id
           LEFT JOIN paises p   ON p.id  = u.pais_id
           LEFT JOIN oficinas o ON o.id  = u.oficina_id
           LEFT JOIN equipos eq ON eq.id = u.equipo_id
          WHERE u.id = ? AND u.activo = 1',
        [$fila['usuario_id']]
    );
}

/* ────────────────────  INTENTOS Y BLOQUEO  ──────────────────── */

function registrar_intento(string $email, string $ip, bool $exito): void
{
    insertar('intentos_acceso', [
        'email' => mb_substr(mb_strtolower($email), 0, 190),
        'ip'    => $ip,
        'exito' => $exito ? 1 : 0,
    ]);
    if ($exito) {
        // Se limpian los fallos de esa cuenta Y los de esa IP: los 19 asesores de
        // una oficina salen a internet por la misma IP, y si el contador de IP solo
        // subiera, cinco despistes entre todos dejarían fuera a la oficina entera.
        q('DELETE FROM intentos_acceso WHERE exito = 0 AND (email = ? OR ip = ?)',
          [mb_strtolower($email), $ip]);
    }
}

/**
 * Dos contadores distintos a propósito:
 *  - por cuenta: 5 fallos y esa cuenta espera una hora (protege la contraseña).
 *  - por IP: mucho más alto, porque toda una oficina comparte IP pública.
 *    Solo salta si alguien está probando cuentas en masa desde fuera.
 */
function intentos_recientes(string $email, string $ip): int
{
    return (int) valor(
        'SELECT COUNT(*) FROM intentos_acceso
          WHERE exito = 0 AND email = ?
            AND creado_en > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
        [mb_strtolower($email), HUB_BLOQUEO_MIN]
    );
}

function intentos_de_ip(string $ip): int
{
    if ($ip === '') return 0;
    return (int) valor(
        'SELECT COUNT(*) FROM intentos_acceso
          WHERE exito = 0 AND ip = ?
            AND creado_en > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
        [$ip, HUB_BLOQUEO_MIN]
    );
}

/**
 * ¿Está bloqueada ESTA CUENTA? El contador por IP no se mira aquí: se usa
 * dentro de entrar(), después de comprobar la contraseña, para que una
 * contraseña correcta siempre pueda pasar (ver el comentario allí).
 */
function bloqueado(string $email, string $ip = ''): bool
{
    return intentos_recientes($email, $ip) >= HUB_MAX_INTENTOS;
}

/* ────────────────────────  CONTRASEÑA  ──────────────────────── */

/** Contraseña temporal fácil de dictar por WhatsApp y de escribir una vez. */
function clave_temporal(): string
{
    $palabras = ['Chasqui','Contenedor','Arequipa','Pacifico','Almacen','Cordillera','Terminal','Bodega'];
    return $palabras[random_int(0, count($palabras) - 1)] . random_int(100, 999) . 'wk';
}

/**
 * Le pone a alguien una contraseña temporal nueva y devuelve la clave en claro
 * para enseñarla UNA vez. Cierra sus sesiones abiertas: si le estaban entrando
 * la cuenta, esto la echa.
 */
function poner_clave_temporal(int $usuario_id): string
{
    $clave = clave_temporal();
    actualizar('usuarios', $usuario_id, [
        'password_hash'         => password_hash($clave, PASSWORD_DEFAULT),
        'debe_cambiar_password' => 1,
    ]);
    q('DELETE FROM sesiones WHERE usuario_id = ?', [$usuario_id]);
    return $clave;
}

/** Reglas mínimas. Devuelve [] si está bien, o la lista de lo que falta. */
function pega_la_contrasena(string $c): array
{
    $faltan = [];
    if (mb_strlen($c) < 10)          $faltan[] = 'Al menos 10 caracteres';
    if (!preg_match('/[a-záéíóúñ]/u', $c) || !preg_match('/[A-ZÁÉÍÓÚÑ]/u', $c))
                                     $faltan[] = 'Una mayúscula y una minúscula';
    if (!preg_match('/\d/', $c))     $faltan[] = 'Un número';
    return $faltan;
}

function guardar_contrasena(int $usuario_id, string $clave): void
{
    actualizar('usuarios', $usuario_id, [
        'password_hash'          => password_hash($clave, PASSWORD_DEFAULT),
        'debe_cambiar_password'  => 0,
        'password_cambiada_en'   => date('Y-m-d H:i:s'),
    ]);
    // Cambiar la contraseña cierra todas las sesiones recordadas.
    q('DELETE FROM sesiones WHERE usuario_id = ?', [$usuario_id]);
    bitacora('acceso.cambio_contrasena', 'usuario', $usuario_id);
}
