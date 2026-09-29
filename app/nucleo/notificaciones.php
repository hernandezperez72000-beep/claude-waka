<?php
declare(strict_types=1);

/**
 * LAS NOTIFICACIONES (5b).
 *
 * Lo pidió el usuario el 2026-09-29: «¿las notificaciones aún no están
 * creadas?». Una sola tabla para todo lo que el HUB le dice a alguien sin que
 * lo pida: la pre venta nueva, el pago confirmado, el pedido por alistar, la
 * Cacería del Día y el aviso general que escribe Administración.
 *
 * CÓMO LLEGA
 *   · Con el HUB abierto: el sondeo que cada persona ya hacía (pagos o
 *     despacho) trae también sus notificaciones; quien no sondeaba nada
 *     (Almacén, Dirección, Marketing) pregunta a /avisos/nuevos. Sale una
 *     barra con su sonido y, si la pestaña está escondida, la notificación del
 *     sistema.
 *   · Al entrar: lo «emergente» (el aviso general, la Cacería, la pre venta)
 *     sale en una ventana encima de la pantalla, una sola vez por persona.
 *   · Con el celular cerrado: PUSH (Web Push, con VAPID), a quien lo activó en
 *     ese equipo. Sin librerías: el cifrado es el del RFC 8291 con OpenSSL.
 *
 * Cada notificación se ve UNA vez por persona, también en otro equipo: lo
 * visto se guarda en el servidor (notificacion_vistas).
 */

/** Los tipos, con su sonido y si salen en ventana al entrar. */
function notif_tipos(): array
{
    return [
        'general'   => ['nombre' => 'Aviso general',          'sonido' => 'pago',     'emergente' => 1, 'horas' => 72],
        /* La Cacería y el resumen ya salen al entrar en la pila de novedades
           del Inicio: aquí van al celular y como barra, no en otra ventana. */
        'caceria'   => ['nombre' => 'Cacería del Día',         'sonido' => 'pago',     'emergente' => 0, 'horas' => 16],
        'preventa'  => ['nombre' => 'Nueva pre venta',         'sonido' => 'pago',     'emergente' => 1, 'horas' => 48],
        'pago_ok'   => ['nombre' => 'Pago confirmado',         'sonido' => 'despacho', 'emergente' => 0, 'horas' => 12],
        'alistar'   => ['nombre' => 'Pedido por alistar',      'sonido' => 'almacen',  'emergente' => 0, 'horas' => 12],
        /* Solo al celular: dentro del HUB ya lo avisa (y lo repite cada 3
           minutos) el sondeo de pagos de Facturación. */
        'pago_nuevo'=> ['nombre' => 'Pago por confirmar',      'sonido' => 'pago',     'emergente' => 0, 'horas' => 1, 'solo_push' => 1],
        'resumen'   => ['nombre' => 'Se cerró un bono',        'sonido' => 'despacho', 'emergente' => 0, 'horas' => 24],
        'entregado' => ['nombre' => 'Pedido entregado',        'sonido' => 'despacho', 'emergente' => 0, 'horas' => 24],
    ];
}

/** Límites del aviso general (Configuración › Notificaciones). */
const NOTIF_TITULO_MAX = 50;
const NOTIF_TEXTO_MAX  = 150;
const NOTIF_IMAGEN_MAX_BYTES = 400 * 1024;

function notif_listo(): bool
{
    return tabla_existe('notificaciones') && tabla_existe('notificacion_vistas');
}

function push_listo(): bool
{
    return notif_listo() && tabla_existe('push_suscripciones') && function_exists('openssl_pkey_new')
        && function_exists('openssl_pkey_derive');
}

/**
 * CREA UNA NOTIFICACIÓN y la manda por push a quien le toca.
 * $d: pais_id?, para_usuario_id?, para_rol?, para_permiso?, para_oficina_id?,
 *     para_equipo_id?, tipo, titulo, texto, url?, imagen?, sin_push?
 * → id (0 si el HUB no está actualizado)
 */
function notificar(array $d): int
{
    if (!notif_listo()) return 0;
    $tipos = notif_tipos();
    $tipo = isset($tipos[$d['tipo'] ?? '']) ? (string)$d['tipo'] : 'general';
    $fila = [
        'pais_id'         => isset($d['pais_id']) ? (int)$d['pais_id'] : null,
        'para_usuario_id' => !empty($d['para_usuario_id']) ? (int)$d['para_usuario_id'] : null,
        'para_rol'        => ($d['para_rol'] ?? '') !== '' ? mb_substr((string)$d['para_rol'], 0, 40) : null,
        'para_permiso'    => ($d['para_permiso'] ?? '') !== '' ? mb_substr((string)$d['para_permiso'], 0, 60) : null,
        'para_oficina_id' => !empty($d['para_oficina_id']) ? (int)$d['para_oficina_id'] : null,
        'para_equipo_id'  => !empty($d['para_equipo_id']) ? (int)$d['para_equipo_id'] : null,
        'tipo'            => $tipo,
        'titulo'          => mb_substr(trim((string)($d['titulo'] ?? '')), 0, 80),
        'texto'           => mb_substr(trim((string)($d['texto'] ?? '')), 0, 255),
        'url'             => ($d['url'] ?? '') !== '' ? mb_substr((string)$d['url'], 0, 255) : null,
        'imagen'          => ($d['imagen'] ?? '') !== '' ? mb_substr((string)$d['imagen'], 0, 120) : null,
        'emergente'       => (int)($d['emergente'] ?? $tipos[$tipo]['emergente']),
        'creado_por'      => $d['creado_por'] ?? ($_SESSION['usuario_id'] ?? null),
        'creado_en'       => date('Y-m-d H:i:s'),
    ];
    $id = insertar('notificaciones', $fila);
    if (empty($d['sin_push'])) push_encolar($id);
    return $id;
}

/**
 * EL PUSH SALE AL TERMINAR LA PETICIÓN, no en medio. Muchas notificaciones
 * nacen dentro de una transacción (el pago validado, el pedido mandado): hablar
 * con los servicios de push ahí dentro tendría la venta bloqueada varios
 * segundos. Y si la transacción se deshace, la fila ya no está y no se manda.
 */
function push_encolar(int $id): void
{
    static $puesto = false;
    $GLOBALS['__push_cola'][] = $id;
    if (!$puesto) {
        $puesto = true;
        register_shutdown_function('push_vaciar_cola');
    }
}

/** Manda lo encolado. Lo llaman el final de la petición y las pruebas. → cuántos llegaron */
function push_vaciar_cola(): int
{
    $ids = array_unique((array)($GLOBALS['__push_cola'] ?? []));
    $GLOBALS['__push_cola'] = [];
    $bien = 0;
    foreach ($ids as $id) {
        try {
            $n = una('SELECT * FROM notificaciones WHERE id = ?', [(int)$id]);
            if (!$n) continue;                    // la transacción se deshizo
            $bien += push_enviar_a(notif_destinatarios($n), notif_carga_push($n));
        } catch (Throwable $ex) {
            /* El push es un extra: si falla, la notificación sigue dentro del HUB. */
            error_log('[HUB] push: ' . $ex->getMessage());
        }
    }
    return $bien;
}

/** ¿Esta notificación es para esta persona? Una sola definición (lista y push). */
function notif_me_toca(array $n, array $u): bool
{
    if (!empty($n['para_usuario_id'])) return (int)$n['para_usuario_id'] === (int)$u['id'];
    /* Lo que manda uno a todos no le sale a quien lo mandó. */
    if (!empty($n['creado_por']) && (int)$n['creado_por'] === (int)$u['id']) return false;
    if ($n['pais_id'] !== null && !cruza_paises($u) && (int)$n['pais_id'] !== (int)$u['pais_id']) return false;
    /* Quien cruza países recibe lo general de SU país (el CEO no quiere cada
       pre venta de México repetida): lo dirigido a su rol o a él, sí. */
    if ($n['pais_id'] !== null && cruza_paises($u) && (int)$n['pais_id'] !== (int)$u['pais_id'] && empty($n['para_rol'])) return false;
    if (!empty($n['para_rol']) && (string)$n['para_rol'] !== (string)$u['rol']) return false;
    if (!empty($n['para_permiso']) && !puede_el($u, (string)$n['para_permiso'])) return false;
    if (!empty($n['para_oficina_id']) && (int)$n['para_oficina_id'] !== (int)($u['oficina_id'] ?? 0)) return false;
    if (!empty($n['para_equipo_id']) && (int)$n['para_equipo_id'] !== (int)($u['equipo_id'] ?? 0)) return false;
    return true;
}

/** A quién le toca, de las cuentas activas (para el push). → [usuario_id, …] */
function notif_destinatarios(array $n): array
{
    if (!empty($n['para_usuario_id'])) return [(int)$n['para_usuario_id']];
    $sql = 'SELECT u.id, u.pais_id, u.oficina_id, u.equipo_id, u.rol_id, r.clave AS rol
              FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.activo = 1';
    $out = [];
    foreach (todas($sql) as $u) {
        if (notif_me_toca($n, $u)) $out[] = (int)$u['id'];
    }
    return $out;
}

/**
 * LO QUE LE FALTA VER A ESTA PERSONA, lo más viejo primero. Con $marcar se da
 * por visto al entregarlo (así no vuelve a salir en otra pestaña ni en el
 * celular). $solo_emergentes: lo que sale en ventana al entrar.
 */
function notif_para_mi(array $u, bool $solo_emergentes = false, int $tope = 5, bool $marcar = true): array
{
    if (!notif_listo()) return [];
    $tipos = notif_tipos();
    $desde = date('Y-m-d H:i:s', time() - 72 * 3600);
    /* Lo que no es para esta persona se descarta YA en la consulta: si no,
       cien avisos a Almacén de un día llenarían el tope y al asesor no le
       llegaría el suyo. El permiso se mira contra los que tiene su rol. */
    $mios = function_exists('permisos_del_rol') ? permisos_del_rol((int)($u['rol_id'] ?? 0)) : [];
    $w = ['n.creado_en >= ?', '(n.para_usuario_id IS NULL OR n.para_usuario_id = ?)',
          '(n.para_rol IS NULL OR n.para_rol = ?)',
          '(n.para_oficina_id IS NULL OR n.para_oficina_id = ?)', '(n.para_equipo_id IS NULL OR n.para_equipo_id = ?)'];
    $par = [$desde, (int)$u['id'], (string)($u['rol'] ?? ''), (int)($u['oficina_id'] ?? 0), (int)($u['equipo_id'] ?? 0)];
    if (($u['rol'] ?? '') !== 'desarrollador') {
        $w[] = $mios ? '(n.para_permiso IS NULL OR n.para_permiso IN (' . implode(',', array_fill(0, count($mios), '?')) . '))' : 'n.para_permiso IS NULL';
        $par = array_merge($par, $mios);
    }
    if ($solo_emergentes) $w[] = 'n.emergente = 1';
    $par[] = (int)$u['id'];
    $cand = array_reverse(todas('SELECT n.* FROM notificaciones n WHERE ' . implode(' AND ', $w) . '
                      AND NOT EXISTS (SELECT 1 FROM notificacion_vistas v WHERE v.notificacion_id = n.id AND v.usuario_id = ?)
                    ORDER BY n.id DESC LIMIT 100', $par));
    $out = [];
    $solo_push = [];
    foreach ($cand as $n) {
        $horas = (int)($tipos[$n['tipo']]['horas'] ?? 24);
        if (strtotime((string)$n['creado_en']) < time() - $horas * 3600) continue;
        /* Lo que existía antes de que la cuenta naciera no es para ella. */
        if (!empty($u['creado_en']) && (string)$n['creado_en'] < (string)$u['creado_en']) continue;
        if (!notif_me_toca($n, $u)) continue;
        if (!empty($tipos[$n['tipo']]['solo_push'])) { $solo_push[] = (int)$n['id']; continue; }
        $out[] = $n;
        if (count($out) >= $tope) break;
    }
    if ($marcar && ($out || $solo_push)) notif_marcar_vistas((int)$u['id'], array_merge($solo_push, array_map(fn($n) => (int)$n['id'], $out)));
    return array_map('notif_publica', $out);
}

function notif_marcar_vistas(int $uid, array $ids): void
{
    $ahora = date('Y-m-d H:i:s');
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if (!$id) continue;
        try {
            q('INSERT INTO notificacion_vistas (notificacion_id, usuario_id, visto_en) VALUES (?, ?, ?)', [$id, $uid, $ahora]);
        } catch (Throwable $ex) { /* ya estaba vista: dos pestañas a la vez */ }
    }
}

/** Lo que viaja al navegador: sin nada que no le toque ver. */
function notif_publica(array $n): array
{
    $t = notif_tipos()[$n['tipo']] ?? notif_tipos()['general'];
    return [
        'id'        => (int)$n['id'],
        'tipo'      => (string)$n['tipo'],
        'titulo'    => (string)$n['titulo'],
        'texto'     => (string)$n['texto'],
        'url'       => $n['url'] ? url((string)$n['url']) : '',
        'imagen'    => $n['imagen'] ? url('uploads/avisos/' . basename((string)$n['imagen'])) : '',
        'sonido'    => $t['sonido'],
        'emergente' => (int)$n['emergente'],
    ];
}

/** Las de un tipo mandadas hace poco a lo mismo (para no repetir la de la pre venta al apagar y encender). */
function notif_reciente(string $tipo, string $url, int $minutos): bool
{
    if (!notif_listo()) return false;
    return (bool) valor('SELECT 1 FROM notificaciones WHERE tipo = ? AND url = ? AND creado_en >= ? LIMIT 1',
                        [$tipo, $url, date('Y-m-d H:i:s', time() - $minutos * 60)]);
}

/* ─────────────────────────  LOS AVISOS QUE MANDA EL HUB SOLO  ───────────────────────── */

/** «Nueva pre venta disponible»: a quien vende en el país del lote. */
function notif_preventa_nueva(array $lote): int
{
    $url = '/preventa';
    if (notif_reciente('preventa', $url . '#lote-' . (int)$lote['id'], 60)) return 0;
    $n = (int) valor('SELECT COUNT(DISTINCT producto_id) FROM lote_lineas WHERE lote_id = ?', [(int)$lote['id']]);
    return notificar([
        'pais_id' => (int)$lote['pais_id'], 'para_permiso' => 'pedidos.crear', 'tipo' => 'preventa',
        'titulo' => 'Nueva pre venta disponible',
        'texto' => (string)$lote['nombre'] . ($n > 0 ? ' · ' . plural($n, 'producto', 'productos') : '') . '. Ya puedes vender.',
        'url' => $url . '#lote-' . (int)$lote['id'],
    ]);
}

/**
 * «Pago confirmado»: al asesor de la venta, en el momento en que Facturación
 * lo valida. Pre venta: «Pago pre venta · confirmado». Entrega inmediata con
 * la venta lista para salir: «Pago confirmado · despachar».
 */
function notif_pago_confirmado(int $pago_id): int
{
    $pg = una('SELECT pg.id, pg.monto_centimos, pe.id AS pedido_id, pe.codigo, pe.tipo, pe.asesor_id, pe.pais_id,
                      c.nombre AS cliente_nombre
                 FROM pagos pg JOIN pedidos pe ON pe.id = pg.pedido_id JOIN clientes c ON c.id = pe.cliente_id
                WHERE pg.id = ?', [$pago_id]);
    if (!$pg || (int)$pg['monto_centimos'] <= 0) return 0;
    $p = pedido_de((int)$pg['pedido_id']);
    if (!$p) return 0;
    $pre = (string)$pg['tipo'] === 'preventa';
    $listo = !$pre && (int)($p['despacho_veces'] ?? 0) === 0 && pedido_despacho_bloqueo($p) === '';
    return notificar([
        'pais_id' => (int)$pg['pais_id'], 'para_usuario_id' => (int)$pg['asesor_id'], 'tipo' => 'pago_ok',
        'titulo' => $pre ? 'Pago pre venta · confirmado' : ($listo ? 'Pago confirmado · despachar' : 'Pago confirmado'),
        'texto' => (string)$pg['codigo'] . ' · ' . primer_nombre((string)$pg['cliente_nombre']) . ' · ' . soles((int)$pg['monto_centimos'])
                 . ($listo ? '. Ya lo puedes mandar a despacho.' : '.'),
        'url' => $listo ? '/pedidos/por-despachar' : '/pedidos/ficha?id=' . (int)$pg['pedido_id'],
    ]);
}

/** «Entró un pago por confirmar»: a Facturación del país, por push. */
function notif_pago_nuevo(array $pedido, int $monto): int
{
    return notificar([
        'pais_id' => (int)$pedido['pais_id'], 'para_rol' => 'facturacion', 'tipo' => 'pago_nuevo',
        'titulo' => 'Entró un pago por confirmar',
        'texto' => (string)$pedido['codigo'] . ' · ' . soles($monto) . ' · ' . primer_nombre((string)($pedido['asesor_nombre'] ?? '')),
        'url' => '/pagos/por-validar',
    ]);
}

/** «Pedido entregado»: al asesor, cuando Almacén marca la entrega con su foto. */
function notif_entregado(int $pedido_id): int
{
    $p = una('SELECT pe.id, pe.codigo, pe.pais_id, pe.asesor_id, c.nombre AS cliente_nombre FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id WHERE pe.id = ?', [$pedido_id]);
    if (!$p) return 0;
    return notificar([
        'pais_id' => (int)$p['pais_id'], 'para_usuario_id' => (int)$p['asesor_id'], 'tipo' => 'entregado',
        'titulo' => 'Pedido entregado · ' . (string)$p['codigo'],
        'texto' => primer_nombre((string)$p['cliente_nombre']) . ' ya lo tiene. La foto de la entrega está en el pedido.',
        'url' => '/pedidos/ficha?id=' . (int)$p['id'],
    ]);
}

/** «Nuevo pedido por alistar»: a Almacén del país, cuando el asesor lo manda. */
function notif_por_alistar(int $pedido_id, int $veces): int
{
    $p = una('SELECT pe.id, pe.codigo, pe.pais_id, c.nombre AS cliente_nombre FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id WHERE pe.id = ?', [$pedido_id]);
    if (!$p) return 0;
    return notificar([
        'pais_id' => (int)$p['pais_id'], 'para_rol' => 'almacen', 'tipo' => 'alistar',
        'titulo' => $veces > 1 ? 'Volvieron a mandar un pedido' : 'Nuevo pedido por alistar',
        'texto' => (string)$p['codigo'] . ' · ' . primer_nombre((string)$p['cliente_nombre']) . ($veces > 1 ? ' · revisa las indicaciones' : ''),
        'url' => '/pedidos/por-alistar#alistar-' . (int)$p['id'],
    ]);
}

/** La Cacería del Día, a quien le llega. */
function notif_caceria(int $pais_id, array $cac): int
{
    $d = ['pais_id' => $pais_id, 'para_rol' => 'asesor', 'tipo' => 'caceria',
          'titulo' => '🎯 ' . mb_substr((string)$cac['titulo'], 0, 60),
          'texto' => trim((string)($cac['frase'] ?? '')) !== '' ? (string)$cac['frase'] : 'Hoy hay Cacería del Día. ¡A cazar!',
          'url' => '/bonos'];
    [$que, $id] = array_pad(explode(':', (string)($cac['destino'] ?? 'todos'), 2), 2, '0');
    if ($que === 'oficina') $d['para_oficina_id'] = (int)$id;
    if ($que === 'equipo')  $d['para_equipo_id'] = (int)$id;
    return notificar($d);
}

/* ─────────────────────────  EL AVISO GENERAL (Configuración)  ───────────────────────── */

/** A quién se le puede mandar el aviso general. → [clave => texto] */
function notif_audiencias(int $pais_id): array
{
    $out = ['todos' => 'Todos', 'rol:asesor' => 'Asesores', 'rol:facturacion' => 'Facturación',
            'rol:almacen' => 'Almacén', 'rol:administracion' => 'Administración'];
    foreach (todas('SELECT id, nombre FROM oficinas WHERE pais_id = ? ORDER BY orden, nombre', [$pais_id]) as $o) {
        $out['oficina:' . (int)$o['id']] = 'Oficina ' . $o['nombre'];
    }
    foreach (todas('SELECT id, nombre FROM equipos WHERE pais_id = ? AND activo = 1 ORDER BY nombre', [$pais_id]) as $eq) {
        $out['equipo:' . (int)$eq['id']] = 'Equipo ' . $eq['nombre'];
    }
    return $out;
}

/**
 * Manda el aviso general. $imagen: nombre ya guardado en uploads/avisos o ''.
 * → ['ok', 'error', 'id', 'n' (a cuántas personas)]
 */
function notif_general_mandar(int $pais_id, string $titulo, string $texto, string $audiencia, string $imagen = '', string $url = ''): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0, 'n' => 0];
    if (!notif_listo()) return $mal('Falta terminar la actualización.');
    $titulo = trim(preg_replace('/\s+/u', ' ', $titulo) ?? '');
    $texto  = trim(preg_replace('/[ \t]+/u', ' ', $texto) ?? '');
    if (mb_strlen($titulo) < 3) return $mal('Ponle un título al aviso.');
    if (mb_strlen($titulo) > NOTIF_TITULO_MAX) return $mal('El título tiene más de ' . NOTIF_TITULO_MAX . ' caracteres: en el celular se cortaría.');
    if ($texto === '') return $mal('Escribe el texto del aviso.');
    if (mb_strlen($texto) > NOTIF_TEXTO_MAX) return $mal('El texto tiene más de ' . NOTIF_TEXTO_MAX . ' caracteres: en el celular se cortaría.');
    $aud = notif_audiencias($pais_id);
    if (!isset($aud[$audiencia])) return $mal('Elige a quién le llega.');
    $url = trim($url);
    if ($url !== '' && !preg_match('~^/[A-Za-z0-9/_\-?=&#.]*$~', $url)) return $mal('El enlace tiene que ser una pantalla de la plataforma (empieza con /).');
    $d = ['pais_id' => $pais_id, 'tipo' => 'general', 'titulo' => $titulo, 'texto' => $texto, 'imagen' => $imagen, 'url' => $url];
    [$que, $id] = array_pad(explode(':', $audiencia, 2), 2, '');
    if ($que === 'rol') $d['para_rol'] = $id;
    if ($que === 'oficina') $d['para_oficina_id'] = (int)$id;
    if ($que === 'equipo') $d['para_equipo_id'] = (int)$id;
    $nid = notificar($d);
    $fila = una('SELECT * FROM notificaciones WHERE id = ?', [$nid]);
    $n = $fila ? count(notif_destinatarios($fila)) : 0;
    bitacora('aviso.general', 'notificacion', $nid, ['titulo' => $titulo, 'para' => $aud[$audiencia], 'personas' => $n]);
    return ['ok' => true, 'error' => '', 'id' => $nid, 'n' => $n];
}

/**
 * LA IMAGEN DEL AVISO: se guarda en uploads/avisos, pública (la pinta el
 * celular sin sesión), re-codificada a JPEG de 1200 px de ancho como mucho.
 * → ['ok', 'error', 'archivo']
 */
function notif_imagen_guardar(array $f): array
{
    $mal = fn(string $e) => ['ok' => false, 'error' => $e, 'archivo' => ''];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'error' => '', 'archivo' => ''];
    if ($f['error'] !== UPLOAD_ERR_OK) return $mal('La imagen no llegó completa. Vuelve a elegirla.');
    if (($f['size'] ?? 0) > 5 * 1024 * 1024) return $mal('La imagen pesa más de 5 MB. Elige una más liviana (lo ideal: menos de 400 KB).');
    $info = @getimagesize((string)$f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return $mal('La imagen tiene que ser JPG, PNG o WebP.');
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return $mal('Ahora no se pueden procesar imágenes. Manda el aviso sin imagen.');
    $src = @imagecreatefromstring((string) file_get_contents((string)$f['tmp_name']));
    if (!$src) return $mal('No se pudo leer la imagen.');
    $w = imagesx($src); $h = imagesy($src);
    $max = 1200;
    $nw = min($w, $max); $nh = (int) round($h * $nw / max(1, $w));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = HUB_SUBIDAS . '/avisos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nombre = 'a' . date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
    $ok = false;
    foreach ([82, 72, 62] as $cal) {
        $ok = imagejpeg($dst, $dir . '/' . $nombre, $cal);
        if ($ok && filesize($dir . '/' . $nombre) <= NOTIF_IMAGEN_MAX_BYTES) break;
    }
    imagedestroy($src); imagedestroy($dst);
    if (!$ok) return $mal('No se pudo guardar la imagen.');
    return ['ok' => true, 'error' => '', 'archivo' => $nombre];
}

/** Lo mandado últimamente, con cuántos lo vieron. */
function notif_generales_recientes(int $pais_id, int $tope = 20): array
{
    if (!notif_listo()) return [];
    return todas("SELECT n.*, u.nombre AS por_nombre,
                         (SELECT COUNT(*) FROM notificacion_vistas v WHERE v.notificacion_id = n.id) AS vistas
                    FROM notificaciones n LEFT JOIN usuarios u ON u.id = n.creado_por
                   WHERE n.tipo = 'general' AND (n.pais_id = ? OR n.pais_id IS NULL)
                   ORDER BY n.id DESC LIMIT " . (int)$tope, [$pais_id]);
}

/* ─────────────────────────  PUSH (Web Push + VAPID, RFC 8291 / 8292)  ───────────────────────── */

function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Un punto P-256 sin comprimir (65 bytes) como clave pública PEM. */
function ec_publica_pem(string $punto): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $punto;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** El punto público (65 bytes) de una clave EC de OpenSSL. */
function ec_punto($clave): string
{
    $d = openssl_pkey_get_details($clave);
    $x = str_pad((string)$d['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad((string)$d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return "\x04" . $x . $y;
}

function ec_nueva()
{
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/** Las claves VAPID del HUB: se crean la primera vez y no cambian (si cambian, hay que volver a suscribirse). */
function push_vapid(): array
{
    $priv = (string) ajuste('push_vapid_privada', '');
    if ($priv === '' || !openssl_pkey_get_private($priv)) {
        $k = ec_nueva();
        if (!$k) throw new RuntimeException('OpenSSL no puede crear claves EC');
        openssl_pkey_export($k, $priv);
        guardar_ajuste_tecnico('push_vapid_privada', $priv, 'Clave VAPID de las notificaciones push. No tocar: cambiarla corta el push de todos.');
        guardar_ajuste_tecnico('push_vapid_publica', b64u(ec_punto($k)), 'Clave pública VAPID. No tocar.');
        ajustes_olvidar();
    }
    $k = openssl_pkey_get_private($priv);
    return ['privada' => $k, 'publica' => b64u(ec_punto($k))];
}

/** La firma ES256 de OpenSSL viene en DER; el JWT la quiere en crudo (r||s, 64 bytes). */
function ecdsa_der_a_crudo(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $leer = function () use ($der, &$pos): string {
        $pos++;                                   // 0x02
        $n = ord($der[$pos++]);
        $v = substr($der, $pos, $n); $pos += $n;
        return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    };
    return $leer() . $leer();
}

/** El JWT VAPID para el servicio de push de ese endpoint. */
function vapid_jwt(string $endpoint, $privada): string
{
    $p = parse_url($endpoint);
    $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    $sub = (string) ajuste('push_contacto', '');
    if ($sub === '') $sub = 'mailto:hub@' . preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['SERVER_NAME'] ?? 'waka.local'));
    $cab = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $cue = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    openssl_sign($cab . '.' . $cue, $firma, $privada, OPENSSL_ALGO_SHA256);
    return $cab . '.' . $cue . '.' . b64u(ecdsa_der_a_crudo($firma));
}

/**
 * CIFRA LA CARGA para una suscripción (aes128gcm, RFC 8291).
 * $ua_publica: la p256dh del navegador (65 bytes), $auth: su secreto (16 bytes).
 * $efimera y $sal solo los pasan las pruebas. → el cuerpo a mandar.
 */
function webpush_cifrar(string $carga, string $ua_publica, string $auth, $efimera = null, ?string $sal = null): string
{
    $as = $efimera ?: ec_nueva();
    $as_publica = ec_punto($as);
    $secreto = openssl_pkey_derive(openssl_pkey_get_public(ec_publica_pem($ua_publica)), $as, 32);
    if ($secreto === false) throw new RuntimeException('No se pudo derivar la clave del push');
    $prk_key = hash_hmac('sha256', $secreto, $auth, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $ua_publica . $as_publica . "\x01", $prk_key, true);
    $sal ??= random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $sal, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $tag = '';
    $cifrado = openssl_encrypt($carga . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cifrado === false) throw new RuntimeException('No se pudo cifrar el push');
    return $sal . pack('N', 4096) . chr(strlen($as_publica)) . $as_publica . $cifrado . $tag;
}

/** Lo que viaja dentro del push: corto (el límite es ~4 KB) y sin datos de clientes que no hagan falta. */
function notif_carga_push(array $n): string
{
    return json_encode([
        'id' => (int)($n['id'] ?? 0),
        't'  => (string)$n['titulo'],
        'b'  => (string)$n['texto'],
        'u'  => ltrim((string)($n['url'] ?? ''), '/'),
        'i'  => !empty($n['imagen']) ? 'uploads/avisos/' . basename((string)$n['imagen']) : '',
        'g'  => (string)$n['tipo'],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * SOLO LOS SERVICIOS DE PUSH DE LOS NAVEGADORES. La dirección la da el
 * navegador, pero llega desde fuera: sin esta lista, cualquiera con cuenta
 * podría hacer que el servidor mande peticiones a donde quisiera.
 */
function push_servicio_conocido(string $endpoint): bool
{
    $h = mb_strtolower((string) parse_url($endpoint, PHP_URL_HOST));
    foreach (['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
              'notify.windows.com', 'push.apple.com', 'web.push.apple.com'] as $ok) {
        if ($h === $ok || str_ends_with($h, '.' . $ok)) return true;
    }
    return false;
}

/** Guarda la suscripción de este equipo. → ['ok', 'error'] */
function push_suscribir(int $uid, string $endpoint, string $p256dh, string $auth, string $agente = ''): array
{
    if (!push_listo()) return ['ok' => false, 'error' => 'Falta terminar la actualización.'];
    if (!preg_match('~^https://[^\s]{10,1000}$~', $endpoint) || !push_servicio_conocido($endpoint)) return ['ok' => false, 'error' => 'Suscripción no válida.'];
    $k = b64u_dec($p256dh); $a = b64u_dec($auth);
    if (strlen($k) !== 65 || $k[0] !== "\x04" || strlen($a) < 16) return ['ok' => false, 'error' => 'Suscripción no válida.'];
    $h = hash('sha256', $endpoint);
    $ya = valor('SELECT id FROM push_suscripciones WHERE endpoint_hash = ?', [$h]);
    $fila = ['usuario_id' => $uid, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
             'agente' => mb_substr($agente, 0, 120), 'fallos' => 0];
    if ($ya) actualizar('push_suscripciones', (int)$ya, $fila);
    else insertar('push_suscripciones', $fila + ['endpoint_hash' => $h, 'creado_en' => date('Y-m-d H:i:s')]);
    return ['ok' => true, 'error' => ''];
}

function push_quitar(string $endpoint): void
{
    if (push_listo()) q('DELETE FROM push_suscripciones WHERE endpoint_hash = ?', [hash('sha256', $endpoint)]);
}

/**
 * Cómo se habla con los servicios de push. Por defecto cURL en paralelo; las
 * pruebas lo cambian con $GLOBALS['__push_transporte'].
 * Recibe [[endpoint, cabeceras, cuerpo], …] y devuelve [código HTTP, …].
 */
function push_transporte(): callable
{
    if (isset($GLOBALS['__push_transporte']) && is_callable($GLOBALS['__push_transporte'])) return $GLOBALS['__push_transporte'];
    return function (array $envios): array {
        if (!function_exists('curl_multi_init')) return array_fill(0, count($envios), 0);
        $mh = curl_multi_init();
        $hs = [];
        foreach ($envios as $i => [$url, $cab, $cuerpo]) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_HTTPHEADER => $cab,
                                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3]);
            curl_multi_add_handle($mh, $ch);
            $hs[$i] = $ch;
        }
        $t0 = microtime(true);
        do {
            $st = curl_multi_exec($mh, $activos);
            if ($activos) curl_multi_select($mh, 0.5);
        } while ($activos && $st === CURLM_OK && microtime(true) - $t0 < 8);
        $out = [];
        foreach ($hs as $i => $ch) {
            $out[$i] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    };
}

/** Manda la carga a todos los equipos de esas personas. → cuántos llegaron. */
function push_enviar_a(array $uids, string $carga): int
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    if (!$uids || !push_listo()) return 0;
    $en = implode(',', array_fill(0, count($uids), '?'));
    $subs = todas("SELECT * FROM push_suscripciones WHERE usuario_id IN ($en) AND fallos < 5 ORDER BY id LIMIT 400", $uids);
    if (!$subs) return 0;
    $v = push_vapid();
    $jwts = [];
    $envios = [];
    foreach ($subs as $s) {
        try {
            $cuerpo = webpush_cifrar($carga, b64u_dec((string)$s['p256dh']), b64u_dec((string)$s['auth']));
        } catch (Throwable $ex) { continue; }
        $host = (string) parse_url((string)$s['endpoint'], PHP_URL_HOST);
        $jwts[$host] ??= vapid_jwt((string)$s['endpoint'], $v['privada']);
        $envios[] = [(string)$s['endpoint'], ['Authorization: vapid t=' . $jwts[$host] . ', k=' . $v['publica'],
                     'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high'],
                     $cuerpo, (int)$s['id']];
    }
    if (!$envios) return 0;
    $codigos = (push_transporte())(array_map(fn($e) => array_slice($e, 0, 3), $envios));
    $bien = 0;
    foreach ($envios as $i => $e) {
        $c = (int)($codigos[$i] ?? 0);
        if ($c >= 200 && $c < 300) { $bien++; q('UPDATE push_suscripciones SET fallos = 0, ultimo_ok = ? WHERE id = ?', [date('Y-m-d H:i:s'), $e[3]]); }
        elseif ($c === 404 || $c === 410) q('DELETE FROM push_suscripciones WHERE id = ?', [$e[3]]);   // el equipo se dio de baja
        else q('UPDATE push_suscripciones SET fallos = fallos + 1 WHERE id = ?', [$e[3]]);
    }
    return $bien;
}

/** ¿Esta persona tiene el push activado en algún equipo? */
function push_de(int $uid): int
{
    return push_listo() ? (int) valor('SELECT COUNT(*) FROM push_suscripciones WHERE usuario_id = ? AND fallos < 5', [$uid]) : 0;
}
