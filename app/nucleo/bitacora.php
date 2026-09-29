<?php
declare(strict_types=1);

/**
 * Bitácora. Toda acción sobre datos ajenos queda con quién y cuándo.
 * Nunca guarda contraseñas ni tokens: si llegan en el detalle, se recortan.
 */
function bitacora(string $accion, string $entidad = '', ?int $entidad_id = null, array $detalle = []): void
{
    static $sensibles = ['password', 'clave', 'contrasena', 'token', 'validador', '_t', 'hash'];

    foreach ($detalle as $k => $v) {
        foreach ($sensibles as $s) {
            if (stripos((string)$k, $s) !== false) { $detalle[$k] = '···'; break; }
        }
    }

    try {
        insertar('bitacora', [
            'usuario_id' => $_SESSION['usuario_id'] ?? null,
            'accion'     => mb_substr($accion, 0, 60),
            'entidad'    => mb_substr($entidad, 0, 40),
            'entidad_id' => $entidad_id,
            'detalle'    => $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
            'ip'         => ip_visitante(),
        ]);
    } catch (Throwable $ex) {
        // La bitácora nunca puede tumbar una operación del negocio.
        error_log('[HUB] bitacora: ' . $ex->getMessage());
    }
}

/** Últimas líneas legibles, para el panel de administración. */
function bitacora_reciente(int $limite = 8, ?int $pais_id = null): array
{
    $where = '';
    $par   = [];
    if ($pais_id) { $where = 'WHERE u.pais_id = ?'; $par[] = $pais_id; }

    return todas(
        "SELECT b.*, u.nombre, u.apellidos, r.nombre AS rol_nombre
           FROM bitacora b
           LEFT JOIN usuarios u ON u.id = b.usuario_id
           LEFT JOIN roles r    ON r.id = u.rol_id
           $where
          ORDER BY b.id DESC
          LIMIT " . (int)$limite,
        $par
    );
}

/* ══════════  LEER LA BITÁCORA  ══════════
   «Pedido · comprobante — Paul, hace 3 min» no sirve para nada: no dice de QUÉ
   pedido. Lo notó el usuario el 2026-09-09 y tenía razón — una bitácora que no
   nombra el sujeto es un ruido con fecha. Estas dos funciones convierten cada
   fila en una frase con nombre y un enlace a la ficha. */

/** Qué se hizo, en castellano. */
function bitacora_frase(array $b): string
{
    return match ((string)$b['accion']) {
        'acceso.entrar'            => 'Inició sesión',
        'acceso.salir'             => 'Cerró sesión',
        'acceso.cambio_contrasena' => 'Cambió su contraseña',
        'acceso.pide_contrasena'   => 'Pidió cambiar su contraseña',
        'usuario.crear'            => 'Dio de alta un usuario',
        'usuario.editar'           => 'Editó los datos de un usuario',
        'usuario.apagar'           => 'Apagó una cuenta',
        'usuario.encender'         => 'Volvió a encender una cuenta',
        'perfil.editar'            => 'Actualizó su perfil',
        'config.ajuste'            => 'Cambió un ajuste de configuración',
        'error.reportar'           => 'Reportó un error',
        'cliente.crear'            => 'Registró un cliente',
        'cliente.editar'           => 'Editó una ficha de cliente',
        'pedido.crear'             => 'Registró una venta',
        'pedido.editar'            => 'Editó una venta',
        'pedido.estado'            => 'Cambió el estado de una venta',
        'pedido.anular'            => 'Anuló una venta',
        'pedido.comprobante'       => 'Anotó el comprobante',
        /* PEDIRLO y EMITIRLO son hechos contrarios y estaban a un renglón de
           distancia en el mismo reporte: sin esta línea, la solicitud salía
           como «Pedido · comprobante pide» —la clave técnica— justo al lado de
           «Anotó el comprobante». Quien audita quién pidió qué leía la del
           programador y las confundía. */
        'pedido.comprobante_pide'  => 'Pidió un comprobante',
        'pedido.despacho'          => 'Mandó a despacho',
        'pedido.alistado'          => 'Marcó un pedido como alistado',
        'pedido.alistado_deshecho' => 'Deshizo un alistado',
        'tienda.stock'             => 'Puso el stock de la web',
        'pago.registrar'           => 'Registró un pago',
        'pago.validar'             => 'Confirmó un pago',
        'pago.espera'              => 'Dejó un pago en espera',
        'pago.denegar'             => 'Denegó un pago',
        'pago.anular'              => 'Quitó un pago',
        'pago.devolver'            => 'Deshizo una confirmación',
        'hub.limpiado'             => 'Vació los datos de prueba',
        'nubefact.produccion'      => 'Pasó la facturación electrónica a producción',
        default => ucfirst(str_replace(['.', '_'], [' · ', ' '], (string)$b['accion'])),
    };
}

/**
 * SOBRE QUÉ se hizo, y a dónde lleva.
 *
 * Devuelve ['texto' => 'P-00003 · Rodolfo Prueba', 'ruta' => '/pedidos/ficha?id=3'].
 * Se cachea por petición: una lista de cien líneas sobre el mismo pedido
 * preguntaría cien veces lo mismo.
 */
function bitacora_sujeto(array $b): array
{
    static $cache = [];
    $ent = (string)($b['entidad'] ?? '');
    $id  = (int)($b['entidad_id'] ?? 0);
    if ($ent === '' || $id <= 0) return ['texto' => '', 'ruta' => ''];

    $llave = $ent . ':' . $id;
    if (isset($cache[$llave])) return $cache[$llave];

    $r = ['texto' => '', 'ruta' => ''];
    try {
        if ($ent === 'pedido') {
            $f = una('SELECT pe.codigo, c.nombre, c.apellidos FROM pedidos pe
                        LEFT JOIN clientes c ON c.id = pe.cliente_id WHERE pe.id = ?', [$id]);
            if ($f) $r = ['texto' => trim($f['codigo'] . ' · ' . trim((string)$f['nombre'] . ' ' . (string)$f['apellidos'])),
                          'ruta'  => '/pedidos/ficha?id=' . $id];
        } elseif ($ent === 'pago') {
            /* Del pago se enseña el PEDIDO y el monto: «Confirmó un pago» sin
               cuánto ni de quién es la mitad de la información justo cuando se
               está buscando una cifra que no cuadra. */
            $f = una('SELECT pg.monto_centimos, pe.id AS pedido_id, pe.codigo
                        FROM pagos pg JOIN pedidos pe ON pe.id = pg.pedido_id
                       WHERE pg.id = ?', [$id]);
            if ($f) $r = ['texto' => $f['codigo'] . ' · ' . soles((int)$f['monto_centimos']),
                          'ruta'  => '/pedidos/ficha?id=' . (int)$f['pedido_id']];
        } elseif ($ent === 'cliente') {
            $f = una('SELECT nombre, apellidos FROM clientes WHERE id = ?', [$id]);
            if ($f) $r = ['texto' => trim((string)$f['nombre'] . ' ' . (string)$f['apellidos']),
                          'ruta'  => '/clientes/ficha?id=' . $id];
        } elseif ($ent === 'usuario') {
            /* CON EL ENLACE SOLO SI DE VERDAD PUEDE EDITARLO. La regla vive en
               puedo_tocar_rol() y la lista de usuarios ya la aplica —no pinta
               «Editar» para Dirección—; aquí faltaba, así que en el Inicio de
               Administración la línea «Entró al HUB» del CEO llevaba a un 403
               a pantalla completa. Sin el enlace se sigue leyendo el nombre,
               que es lo que hace falta para saber quién hizo qué. */
            $f = una('SELECT u.nombre, u.apellidos, r.clave AS rol FROM usuarios u
                        JOIN roles r ON r.id = u.rol_id WHERE u.id = ?', [$id]);
            if ($f) $r = ['texto' => trim((string)$f['nombre'] . ' ' . (string)$f['apellidos']),
                          'ruta'  => puedo_tocar_rol((string)$f['rol'])
                                     ? '/usuarios/editar?id=' . $id : ''];
        }
    } catch (Throwable $e) {
        /* Un sujeto que ya no existe —se borró el pedido de prueba— no puede
           tumbar la pantalla que lo lista. Se queda sin nombre y ya está. */
    }
    return $cache[$llave] = $r;
}
