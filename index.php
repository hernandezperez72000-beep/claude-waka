<?php
/**
 * HUB Waka — punto de entrada. Todo pasa por aquí.
 */
declare(strict_types=1);
require_once __DIR__ . '/app/nucleo/arranque.php';

if (!hub_instalado()) {
    header('Location: instalar.php');
    exit;
}

hub_config();
sesion_iniciar();

/* Ruta pedida, sin la carpeta base y sin parámetros. */
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($base && str_starts_with($ruta, $base)) $ruta = substr($ruta, strlen($base));
$ruta = '/' . trim(rawurldecode($ruta), '/');
if ($ruta === '/index.php') $ruta = '/';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'POST') exigir_csrf();

/* Tabla de rutas: ruta => [archivo del controlador, permiso o null] */
$rutas = [
    '/'                      => ['acceso/bienvenida', null],
    '/entrar'                => ['acceso/entrar',     null],
    '/salir'                 => ['acceso/salir',      null],
    '/recuperar'             => ['acceso/recuperar',  null],

    '/inicio'                => ['inicio/panel',      '@sesion'],
    '/mas'                   => ['inicio/mas',        '@sesion'],

    '/mi-perfil'             => ['perfil/ver',        '@sesion'],
    '/mi-perfil/contrasena'  => ['perfil/contrasena', '@sesion'],
    '/mi-perfil/tema'        => ['perfil/tema',       '@sesion'],

    '/usuarios'              => ['usuarios/lista',    'usuarios.ver'],
    '/usuarios/nuevo'        => ['usuarios/editar',   'usuarios.gestionar'],
    '/usuarios/editar'       => ['usuarios/editar',   'usuarios.gestionar'],
    '/usuarios/estado'       => ['usuarios/estado',   'usuarios.gestionar'],
    '/usuarios/clave'        => ['usuarios/clave',    'usuarios.gestionar'],

    '/configuracion'         => ['config/indice',     'usuarios.ver'],
    '/configuracion/equipos' => ['config/equipos',    'equipos.gestionar'],
    /* El WhatsApp de facturación. Sin esta pantalla el botón «HABLAR CON
       FACTURACIÓN» del pedido trabado no se podía encender más que con un
       UPDATE a mano, y el usuario lo pidió «editable». */
    '/configuracion/contactos' => ['config/contactos', 'listas.gestionar'],

    /* ── Módulo 2 · clientes ─────────────────────────────────────────── */
    '/clientes'              => ['clientes/lista',    'clientes.ver'],
    '/clientes/ficha'        => ['clientes/ficha',    'clientes.ver'],
    '/clientes/nuevo'        => ['clientes/editar',   'clientes.crear'],
    '/clientes/editar'       => ['clientes/editar',   'clientes.editar'],
    '/clientes/buscar'       => ['clientes/buscar',   'clientes.ver'],
    '/clientes/rapido'       => ['clientes/rapido',   'clientes.crear'],
    '/ubigeo/buscar'         => ['clientes/ubigeo',   '@sesion'],
    /* Las sucursales que ya se usaron con esa agencia en esa ciudad. */
    '/sucursales/buscar'     => ['pedidos/sucursales', 'pedidos.crear'],

    /* ── Módulo 3a · el catálogo ─────────────────────────────────────
       La sección «Stock y pre venta» empieza por el catálogo: sin productos
       no hay stock que contar ni lote que cargar. */
    '/stock'                 => ['catalogo/lista',    'stock.ver'],
    '/stock/producto'        => ['catalogo/producto', 'stock.ver'],
    /* El buscador del formulario del pedido: lo usa quien registra ventas. */
    '/stock/buscar'          => ['catalogo/buscar',   'pedidos.crear'],
    /* ── Módulo 3b · la tienda ───────────────────────────────────────
       Traer el catálogo de compraenwaka: quien crea productos. Las claves se
       pegan en Configuración, como las de NUBEFACT. */
    '/stock/tienda'          => ['catalogo/tienda',   'catalogo.gestionar'],
    /* ── 3h · pre venta: los lotes (el CEO) y lo disponible (el asesor) ── */
    '/stock/lotes'           => ['catalogo/lotes',     'lotes.ver'],
    '/stock/lote'            => ['catalogo/lote',      'lotes.ver'],
    '/stock/lote/excel'      => ['catalogo/loteexcel', 'lotes.gestionar'],
    '/preventa'              => ['catalogo/preventa',  'preventa.ver'],
    /* ACTUALIZAR STOCK: lo pulsa quien lleva el stock o quien trae la tienda (3g). */
    '/stock/leer'            => ['catalogo/leerstock', 'stock.ajustar|catalogo.gestionar'],
    /* Lo que las ventas tienen que mover en la web y espera (3e). */
    '/stock/pendientes'      => ['catalogo/pendientes', 'tienda.cola'],
    '/configuracion/tienda'  => ['config/tienda',     'listas.gestionar'],

    /* ── Módulo 2 · pedidos ──────────────────────────────────────────── */
    '/pedidos'               => ['pedidos/lista',     'pedidos.ver'],
    '/pedidos/ficha'         => ['pedidos/ficha',     'pedidos.ver'],
    '/pedidos/nuevo'         => ['pedidos/nuevo',     'pedidos.crear'],
    '/pedidos/estado'        => ['pedidos/estado',    'pedidos.editar'],
    '/pedidos/anular'        => ['pedidos/anular',    'pedidos.anular'],
    '/pedidos/mensaje'       => ['pedidos/mensaje',   'pedidos.ver'],
    '/pedidos/facturar'      => ['pedidos/facturar',  'pagos.verificar'],
    '/pedidos/comprobante'   => ['pedidos/comprobante','pagos.verificar'],
    /* Emitir la boleta o la factura electrónica con NUBEFACT (parche 2u). */
    '/pedidos/emitir'        => ['pedidos/emitir',    'pagos.verificar'],
    '/configuracion/facturacion' => ['config/facturacion', 'listas.gestionar'],
    /* PEDIR el comprobante es del ASESOR; EMITIRLO es de facturación. Dos
       rutas y dos permisos, porque son dos hechos distintos: uno avisa de lo
       que el cliente quiere y el otro deja constancia de lo que se hizo. */
    '/pedidos/solicitar-comprobante' => ['pedidos/solicitarcomprobante','pedidos.editar'],
    /* Reclamar que confirmen una venta. Del asesor, como pedir el comprobante:
       las dos son «hablar por esta venta». */
    '/pedidos/reclamar'      => ['pedidos/reclamar',    'pedidos.editar'],
    /* El descuento que pasó del tope (3d): aprobarlo o rechazarlo, y la
       lista de los que esperan, a la que lleva el aviso. */
    '/pedidos/descuento'     => ['pedidos/descuento',   'descuentos.aprobar'],
    '/pedidos/descuentos'    => ['pedidos/descuentos',  'descuentos.aprobar'],
    '/configuracion/descuentos' => ['config/descuentos', 'listas.gestionar'],
    /* Qué pide cada método de pago: voucher, foto del DNI, recargo (3f). */
    '/configuracion/metodos-pago' => ['config/metodos', 'listas.gestionar'],
    /* Ver la boleta/factura la puede abrir quien ve el pedido: el asesor la
       necesita cuando el cliente se la pide, y no toca nada. */
    '/pedidos/comprobante-archivo' => ['pedidos/comprobantearchivo','pedidos.ver'],
    /* Mandar a despacho es tarea del ASESOR (usuario, 2026-09-09), no de
       Administración y menos de Facturación, a quien esta pantalla le salía
       solo por llevar 'pedidos.ver'. Permiso propio: 'pedidos.despachar'. */
    '/pedidos/despacho'      => ['pedidos/despacho',   'pedidos.despachar'],
    /* El rótulo para pegar en la caja: quien manda a despacho y, desde el
       3h.1, Almacén, que es quien arma la caja y la pega. */
    '/pedidos/rotulo'        => ['pedidos/rotulo',     'pedidos.despachar|pedidos.alistar'],
    '/pedidos/por-despachar' => ['pedidos/pordespachar','pedidos.despachar'],
    /* El sondeo del asesor lleva DOS noticias —lo que ya puede despachar y lo
       que facturación le paró— y cada una tiene su propia puerta dentro del
       controlador. Pedir aquí 'pedidos.despachar' dejaba sin aviso a un asesor
       sin ese permiso que sí tiene pagos parados. No enseña ni un dato: solo
       cuántos hay. */
    '/pedidos/nuevos-despacho'=> ['pedidos/nuevosdespacho','@sesion'],

    /* ── 3g · Almacén alista lo que el asesor mandó a despacho ── */
    '/pedidos/por-alistar'    => ['pedidos/poralistar',   'pedidos.alistar'],
    /* 5b · lo alistado que falta entregar, en su propia opción del menú. */
    '/pedidos/por-entregar'   => ['pedidos/poralistar',   'pedidos.alistar'],
    /* 5b · la guía de remisión de los envíos a provincia: Almacén y Facturación. */
    '/pedidos/guia'           => ['pedidos/guia',         'pedidos.alistar|pagos.verificar'],
    '/pedidos/alistado-foto'  => ['pedidos/alistadofoto', 'pedidos.alistar|pedidos.ver'],
    /* 3j · la foto de la entrega y el costo del envío que quedó pendiente */
    '/pedidos/entrega-foto'   => ['pedidos/entregafoto', 'pedidos.alistar|pedidos.ver'],
    '/pedidos/flete'          => ['pedidos/flete',        'pedidos.editar'],
    '/configuracion/equipo-despacho' => ['config/listasimple', 'listas.gestionar'],
    '/configuracion/agencias-carga'  => ['config/listasimple', 'listas.gestionar'],

    /* ── 3i · la garantía: la pide el asesor desde el pedido, la aprueba
       Administración y sale por «Por alistar». ── */
    '/garantias'              => ['garantias/lista',  'garantias.ver'],
    '/garantias/pedir'        => ['garantias/pedir',  'garantias.pedir'],
    '/garantias/ver'          => ['garantias/ver',    'garantias.ver'],
    '/garantias/foto'         => ['garantias/foto',   'garantias.ver|pedidos.alistar'],
    '/garantias/rotulo'       => ['garantias/rotulo', 'pedidos.alistar|garantias.aprobar'],
    '/configuracion/garantias'=> ['config/garantias', 'listas.gestionar'],
    /* Módulo 5 · bonos, metas y rachas */
    '/configuracion/bonos'   => ['config/bonos',      'bonos.configurar'],
    '/configuracion/metas'   => ['config/metas',      'metas.gestionar'],
    '/bonos'                 => ['bonos/indice',      'bonos.ver'],
    '/bonos/caceria'         => ['bonos/caceria',     'bonos.lanzar'],
    '/bonos/rachas'          => ['bonos/rachas',      'bonos.ver'],
    '/bonos/pagar'           => ['bonos/pagar',       'bonos.pagar'],
    '/equipo'                => ['bonos/equipo',      'bonos.ver'],
    '/novedades/vista'       => ['bonos/novedad',     '@sesion'],
    /* 5b · las notificaciones: el sondeo de quien no tenía otro, el push de
       cada equipo y el aviso general que se escribe en Configuración. */
    '/avisos/nuevos'         => ['avisos/nuevos',     '@sesion'],
    '/avisos/suscribir'      => ['avisos/suscribir',  '@sesion'],
    '/avisos/probar'         => ['avisos/probar',     '@sesion'],
    '/configuracion/notificaciones' => ['config/notificaciones', 'avisos.enviar'],

    /* ── Módulo 2 · pagos ────────────────────────────────────────────── */
    '/pagos/registrar'       => ['pagos/registrar',   'pagos.registrar'],
    '/pagos/quitar'          => ['pagos/quitar',      'pagos.registrar'],
    '/pagos/devolver'        => ['pagos/devolver',    'pagos.anular'],
    '/pagos/por-validar'     => ['pagos/porvalidar',  'pagos.verificar'],
    '/pagos/nuevos'          => ['pagos/nuevos',      'pagos.verificar'],
    '/pagos/validar'         => ['pagos/validar',     'pagos.verificar'],
    '/pagos/espera'          => ['pagos/espera',      'pagos.verificar'],
    '/pagos/denegar'         => ['pagos/denegar',     'pagos.verificar'],
    '/pagos/voucher'         => ['pagos/voucher',     'pedidos.ver'],

    /* ── Módulo 2 · reportes ─────────────────────────────────────────── */
    '/reportes'              => ['reportes/indice',   'reportes.ver'],
    /* El rastro de quién hizo qué. Por permiso de usuarios: la bitácora la
       gobierna quien gobierna las cuentas, no quien confirma pagos. */
    '/reportes/bitacora'     => ['reportes/bitacora', 'usuarios.ver'],
    '/reportes/pagos'        => ['reportes/pagos',    'reportes.ver'],

    '/reportar-error'        => ['inicio/reportar',   '@sesion'],
];

if (!isset($rutas[$ruta])) {
    // Secciones que todavía no se han construido: se avisa, no se rompe.
    // Cada una nace ya con su permiso: cuando se construyan, la protección
    // no depende de que alguien se acuerde de añadirla. El menú no es seguridad.
    $pendientes = [
        '/recepcion' => ['Recepción',          'recepcion.ver'],
        '/cashback'  => ['Cashback Waka',      'cashback.panel'],
    ];
    if (isset($pendientes[$ruta])) {
        [$titulo_seccion, $permiso_seccion] = $pendientes[$ruta];
        exigir_sesion();
        exigir($permiso_seccion);
        seccion_activa(trim($ruta, '/'));
        pagina('inicio/pendiente', ['seccion' => $titulo_seccion],
               ['titulo' => $titulo_seccion, 'sin_titulo' => true]);
    }
    cortar(404, 'Esta página no existe',
                'Revisa el enlace o vuelve al inicio.');
}

[$controlador, $permiso] = $rutas[$ruta];

if ($permiso === '@sesion') {
    exigir_sesion();
} elseif ($permiso !== null) {
    exigir_sesion();
    /* «a|b» = basta con uno de los dos (3g: ACTUALIZAR STOCK lo pulsa quien
       lleva el stock o quien trae la tienda). */
    $alguno = array_values(array_filter(explode('|', $permiso), 'puede'));
    exigir($alguno[0] ?? explode('|', $permiso)[0]);
}

require HUB_APP . '/controladores/' . $controlador . '.php';
