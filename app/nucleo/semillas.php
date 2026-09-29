<?php
declare(strict_types=1);

/** Datos de arranque. Idempotente: se puede volver a correr. */
function sembrar_todo(): void
{
    sembrar_roles_y_permisos();
    sembrar_pais_y_oficinas();
    sembrar_listas();
    sembrar_ubigeo();
    sembrar_estados_pedido();
    sembrar_ajustes();
    sembrar_frases();
    sembrar_frases_de_rol();
    sembrar_bonos();
    sembrar_plantillas();
}

function sembrar_roles_y_permisos(): void
{
    $roles = [
        ['desarrollador',  'Desarrollador',  1],
        ['direccion',      'Dirección',      2],
        ['administracion', 'Administración', 3],
        // Facturación no vende: confirma que el dinero entró y emite el
        // comprobante. Es un rol propio y no una Administración recortada
        // porque toca dinero de todos y no debe poder configurar nada.
        ['facturacion',    'Facturación',    4],
        ['asesor',         'Asesor',         5],
        /* 3g (usuario, 2026-09-26): Almacén lleva el stock y Marketing los
           datos de la tienda. Ninguno vende ni confirma dinero. */
        ['almacen',        'Almacén',        6],
        ['marketing',      'Marketing',      7],
    ];
    foreach ($roles as [$clave, $nombre, $orden]) {
        sembrar_fila('roles', ['clave' => $clave], ['nombre' => $nombre, 'orden' => $orden]);
    }

    // grupo => [clave => nombre]
    $permisos = [
        'clientes' => [
            'clientes.ver'    => 'Ver clientes',
            'clientes.crear'  => 'Registrar un cliente',
            'clientes.editar' => 'Editar la ficha de un cliente',
        ],
        'pedidos' => [
            'pedidos.ver'      => 'Ver pedidos',
            'pedidos.crear'    => 'Registrar un pedido',
            'pedidos.editar'   => 'Editar un pedido',
            'pedidos.anular'   => 'Anular un pedido',
            'pedidos.despachar'=> 'Mandar un pedido a despacho',
            /* 3g: Almacén prepara lo que el asesor mandó a despacho y lo marca
               con foto y quién lo alistó. */
            'pedidos.alistar'  => 'Alistar los pedidos que salen',
            'pagos.registrar'  => 'Registrar un pago',
            'pagos.verificar'  => 'Validar un pago pendiente',
            'pagos.anular'     => 'Devolver o anular un pago ya validado',
            /* 3d: el descuento que pasa del tope espera a quien tenga
               esto. Administración (y Desarrollador), como pidió el usuario. */
            'descuentos.aprobar' => 'Aprobar descuentos que pasan del tope',
        ],
        'stock' => [
            'stock.ver'        => 'Ver el stock',
            'catalogo.gestionar' => 'Crear y editar productos del catálogo',
            /* Hoy el único stock que se pone a mano es el de la web (3c). */
            'stock.ajustar'    => 'Poner el stock de la web',
            'lotes.ver'        => 'Ver los lotes de importación',
            /* 3h: lo que el asesor ve para vender. Los lotes enteros (BL,
               factura, lo que todavía no está a la venta) son de quien los lleva. */
            'preventa.ver'     => 'Ver lo disponible para pre venta',
            'lotes.gestionar'  => 'Cargar y editar lotes',
            'precios.editar'   => 'Poner los niveles de precio',
            /* 3g: el viejo «tienda.escribir» se partió en tres, uno por
               trabajo: el stock (stock.ajustar, Almacén), los datos (Marketing)
               y lo que las ventas no pudieron mover (Administración). A
               Dirección no se le da ninguno. */
            'tienda.datos'     => 'Cambiar en la web el nombre, el código y el precio',
            'tienda.cola'      => 'Revisar el stock de la web que las ventas no pudieron mover',
        ],
        /* 3i: la garantía la PIDE el asesor desde el pedido y la APRUEBA
           Administración (usuario, 2026-09-27). Verla es de quien las pide,
           de quien las aprueba y de Dirección. */
        'garantias' => [
            'garantias.ver'     => 'Ver las garantías',
            'garantias.pedir'   => 'Pedir una garantía desde el pedido',
            'garantias.aprobar' => 'Aprobar o no una garantía',
        ],
        'cashback' => [
            'cashback.ver'     => 'Ver el saldo de cashback de un cliente',
            'cashback.panel'   => 'Entrar a la sección Cashback Waka',
            'cashback.ajustar' => 'Ajustar un saldo a mano',
        ],
        'bonos' => [
            'bonos.ver'        => 'Ver los bonos',
            'bonos.lanzar'     => 'Lanzar el bono del día',
            'bonos.configurar' => 'Configurar bonos y premios',
            /* 5a: ver cómo van los bonos de todos (el panel de Dirección y
               Administración) y marcar los premios pagados. */
            'bonos.seguir'     => 'Ver cómo van los bonos de todos',
            'bonos.pagar'      => 'Marcar un premio como pagado',
        ],
        'recepcion' => [
            'recepcion.ver'    => 'Ver la cola de visitas',
            'recepcion.avisar' => 'Avisar que llegó un cliente',
        ],
        'reportes' => [
            'reportes.ver'     => 'Ver los reportes',
            'reportes.exportar'=> 'Exportar a Excel',
        ],
        'config' => [
            /* 5b: el aviso general (texto e imagen) que llega como push al
               celular y en ventana al entrar al HUB. */
            'avisos.enviar'     => 'Mandar un aviso general a todos',
            'usuarios.ver'      => 'Ver usuarios',
            'usuarios.gestionar'=> 'Crear y editar usuarios',
            'equipos.gestionar' => 'Equipos y líderes',
            'metas.gestionar'   => 'Metas por asesor',
            'listas.gestionar'  => 'Listas editables',
            'frases.gestionar'  => 'Saludo y frases',
            'whatsapp.gestionar'=> 'Plantillas de WhatsApp',
            'errores.gestionar' => 'Bandeja de errores reportados',
            'claves.aprobar'    => 'Aprobar cambios de contraseña',
        ],
        'tecnico' => [
            'tecnico.tienda'   => 'Conexión con la tienda',
            'tecnico.bd'       => 'Base de datos y respaldos',
        ],
    ];

    foreach ($permisos as $grupo => $lista) {
        foreach ($lista as $clave => $nombre) {
            sembrar_fila('permisos', ['clave' => $clave],
                         ['nombre' => $nombre, 'grupo' => $grupo,
                          'tecnico' => $grupo === 'tecnico' ? 1 : 0]);
        }
    }

    $id_rol     = fn(string $c) => (int) valor('SELECT id FROM roles WHERE clave = ?', [$c]);
    $id_permiso = fn(string $c) => (int) valor('SELECT id FROM permisos WHERE clave = ?', [$c]);

    $dar = function (string $rol, array $claves) use ($id_rol, $id_permiso) {
        $r = $id_rol($rol);
        foreach ($claves as $c) {
            $p = $id_permiso($c);
            if ($r && $p) sembrar_par('rol_permiso', ['rol_id' => $r, 'permiso_id' => $p]);
        }
    };

    $todas_las_claves = [];
    foreach ($permisos as $lista) $todas_las_claves = array_merge($todas_las_claves, array_keys($lista));

    // Desarrollador: todo, incluido lo técnico.
    $dar('desarrollador', $todas_las_claves);

    // Administración: todo lo funcional de su país. Nada técnico.
    $dar('administracion', array_values(array_filter(
        $todas_las_claves, fn($c) => !str_starts_with($c, 'tecnico.')
    )));

    // Dirección: ve todo y configura todo menos lo técnico, pero NO registra,
    // NO edita clientes ni pedidos y NO anula. Recepción sí puede escribir.
    $dar('direccion', [
        'clientes.ver', 'pedidos.ver', 'stock.ver', 'lotes.ver', 'preventa.ver',
        'cashback.ver', 'cashback.panel', 'bonos.ver', 'bonos.lanzar', 'bonos.configurar', 'bonos.seguir',
        'recepcion.ver', 'recepcion.avisar',
        'reportes.ver', 'reportes.exportar',
        'usuarios.ver', 'usuarios.gestionar', 'equipos.gestionar', 'metas.gestionar',
        'listas.gestionar', 'frases.gestionar', 'whatsapp.gestionar',
        'errores.gestionar', 'claves.aprobar', 'avisos.enviar',
        'lotes.gestionar', 'precios.editar', 'catalogo.gestionar',
        'garantias.ver',
        // Confirmar un pago sí, pedido a pedido: lo pidió el usuario para que
        // nunca falte quien lo haga. Los AVISOS de pagos nuevos NO le llegan:
        // el CEO no está para vaciar una bandeja. Ver menu.php.
        'pagos.verificar',
    ]);

    /* Facturación: confirma el dinero y emite el comprobante. Nada más.
       NO vende, NO edita clientes ni pedidos, NO anula, NO devuelve un pago ya
       validado —eso es de Administración— y NO configura nada. Ve pedidos y
       clientes porque necesita los datos para emitir la boleta. */
    $dar('facturacion', [
        'pedidos.ver', 'clientes.ver',
        'pagos.verificar',
        'reportes.ver', 'reportes.exportar',
    ]);

    // Asesor: lo suyo.
    $dar('asesor', [
        'clientes.ver', 'clientes.crear', 'clientes.editar',
        'pedidos.ver', 'pedidos.crear', 'pedidos.editar', 'pedidos.anular',
        /* Mandar al grupo de despacho es TAREA DEL ASESOR, no de Administración
           (usuario, 2026-09-09): hoy lo hace a mano desde su teléfono. A
           Facturación y a Dirección no se les da: no es su trabajo, y hasta
           ahora esa pantalla les salía por llevar 'pedidos.ver'. */
        'pedidos.despachar',
        'pagos.registrar',
        'stock.ver', 'preventa.ver',
        'garantias.ver', 'garantias.pedir',
        'cashback.ver',
        'bonos.ver',
        'recepcion.ver', 'recepcion.avisar',
    ]);

    /* Almacén (3g): el stock y lo que sale. Alista los pedidos mandados a
       despacho, pone el stock de la web y ve los lotes. No vende, no toca
       dinero, no configura. */
    $dar('almacen', [
        'pedidos.alistar', 'stock.ver', 'stock.ajustar', 'lotes.ver',
    ]);

    /* Marketing (3g): los datos de la tienda. Trae el catálogo, pone los
       precios por cantidad y cambia en la web nombre, código y precio. Mira
       los clientes, sin tocarlos. */
    $dar('marketing', [
        'stock.ver', 'catalogo.gestionar', 'precios.editar', 'tienda.datos',
        'clientes.ver',
    ]);
}

function sembrar_pais_y_oficinas(): void
{
    $peru = sembrar_fila('paises', ['codigo' => 'PE'],
                         ['nombre' => 'Perú', 'moneda' => 'PEN', 'simbolo' => 'S/']);

    foreach ([['Lima', '432,454', 1], ['Arequipa', '', 2]] as [$nombre, $modulos, $orden]) {
        $existe = valor('SELECT id FROM oficinas WHERE pais_id = ? AND nombre = ?', [$peru, $nombre]);
        if (!$existe) {
            insertar('oficinas', ['pais_id'=>$peru, 'nombre'=>$nombre, 'modulos'=>$modulos, 'orden'=>$orden]);
        }
    }
}

function sembrar_listas(): void
{
    // Cada item: valor, o [valor, extras...] cuando la lista tiene ajustes.
    // Los ajustes SOLO se escriben al crear el item: si se reescribieran en
    // cada arranque, la actualización pisaría lo que Administración cambió
    // en pantalla, y nadie entendería por qué su transferencia volvió a
    // contar al instante sola.
    $listas = [
        // [nombre, pide comprobante, cuenta como cobrado al instante]
        //
        // NINGUNO cuenta al instante. Lo decidió el usuario el 2026-09-08:
        // quiere que facturación confirme cada ingreso, sin excepciones, y que
        // el HUB sea el sitio donde eso queda registrado — hoy lo hacen por
        // WhatsApp. Ningún pago suma a la meta, al podio ni al cashback hasta
        // que alguien con `pagos.verificar` lo valida.
        //
        // Es un interruptor por método, no una regla de código: el día que
        // pese demasiado, Yape y efectivo se vuelven a poner al instante desde
        // Configuración y nadie tiene que tocar nada más.
        'metodos_pago' => ['Métodos de pago', [
            ['Yape',                    1, 0],
            ['Plin',                    1, 0],
            ['Transferencia BCP',       1, 0],
            ['Transferencia Interbank', 1, 0],
            ['Depósito',                1, 0],
            /* El SÉPTIMO valor es el recargo en centésimas de punto: el POS
               cobra 4% y por eso lleva 400. Los demás, nada. */
            ['POS / tarjeta',           1, 0, 0, '', '', 400],
            /* El SEXTO valor es `extra`, y aquí marca «esto es efectivo»: es
             lo que enciende la cuenta del vuelto en el formulario. Va en la
             lista y no en el nombre del item, para que «Efectivo soles» o
             «Efectivo caja 2» funcionen igual sin tocar código. */
            ['Efectivo',                0, 0, 0, '', 'efectivo'],
        ]],
        'agencias'   => ['Agencias de envío',
            ['Shalom','Olva Courier','Marvisur','Cruz del Sur Cargo','Entrega propia']],
        'canales'    => ['Cómo nos conoció',
            ['WhatsApp','Campaña Facebook','Instagram','TikTok','Referido','Vino a la oficina','Página web']],
        /* «Envío con costo en Lima» lo pidió el usuario el 2026-09-09 para la
           PRE VENTA: al llegar el contenedor los pedidos son más grandes y más
           pesados, y ahí el delivery de Lima sí se cobra. Faltaba la opción y
           no había forma de decirlo — solo existían «gratis Lima» y
           «provincia». Cuánto se cobra sigue siendo el campo Flete. */
        /* El cuarto valor es «cobra flete»: si el tipo de envío tiene costo,
           el formulario pide el monto. Va aquí y no en un if del controlador
           para que crear «Envío con costo a Arequipa» no sea tocar código. */
        /* «cobra flete» = el envío tiene un costo QUE COBRA WAKA, y por eso el
           monto es obligatorio. «Flete a provincia» NO lo lleva: ese dinero se
           lo paga el cliente a la agencia en destino, muchas veces el asesor
           todavía no sabe cuánto es, y exigírselo le impediría guardar la
           venta. Su campo sigue apareciendo —lo enciende la rama de
           provincia— pero vacío es válido, como siempre. */
        /* El quinto valor dice DÓNDE aplica cada tipo: lima, provincia u
           oficina. El desplegable enseñaba los cuatro siempre, así que a una
           venta a Cusco le ofrecía «Envío gratis Lima». */
        'tipos_envio'=> ['Tipos de envío',
            [['Envío gratis Lima',       0, 0, 0, 'lima'],
             ['Envío con costo en Lima', 0, 0, 1, 'lima'],
             ['Flete a provincia',       0, 0, 0, 'provincia'],
             ['Recojo en oficina',       0, 0, 0, 'oficina']]],
        'motivos_anulacion' => ['Motivos de anulación',
            ['El cliente se arrepintió','Se registró mal','No pagó en el plazo',
             'No hay stock para cumplirlo','Otro motivo']],
        /* Quién alista los pedidos en el almacén (3g). Nace vacía: se llena
           en Configuración › Equipo de despacho. Más adelante cada uno tendrá
           su cuenta y esto se registrará solo. */
        'equipo_despacho'   => ['Equipo de despacho', []],
        /* Las agencias que traen los contenedores (3h). Nace vacía. */
        'agencias_carga'    => ['Agencias de carga', []],
        'motivos_visita'    => ['Motivos de visita',
            ['Cotización','Recojo','Pago de saldo','Cambio o garantía','Otro']],
        /* ── LA GARANTÍA QUE SE PROMETE ──────────────────────────────
           El PRIMERO de la lista es el que viene marcado en el formulario.
           No hay una columna «por defecto» y no hace falta: el orden ya es
           editable desde Configuración, así que cambiar la promesa por
           defecto es mover un item de sitio. Una columna más sería una
           segunda definición de lo mismo, y de esas ya se pagaron seis. */
        'garantias'         => ['Garantías', [
            '12 meses',
            '6 meses',
            /* El SEXTO valor es `extra`: a qué tipo de venta le toca esta
               garantía por defecto. Sin él, «Liquidación» habría salido con
               doce meses puestos y el asesor tendría que acordarse de
               cambiarlo en cada venta — y el día que se olvide, Waka responde
               un año por un saldo de almacén. */
            ['No aplica', 0, 0, 0, '', 'liquidacion'],
        ]],
    ];

    foreach ($listas as $clave => [$nombre, $items]) {
        $lista_id = sembrar_fila('listas', ['clave' => $clave], ['nombre' => $nombre]);
        $orden = 0;
        foreach ($items as $item) {
            $orden += 10;
            [$valor, $comprobante, $instante, $cobra_flete, $ambito, $extra, $recargo] = is_array($item)
                ? [$item[0], (int)($item[1] ?? 0), (int)($item[2] ?? 0), (int)($item[3] ?? 0),
                   (string)($item[4] ?? ''), (string)($item[5] ?? ''), (int)($item[6] ?? 0)]
                /* El tercer valor es «cuenta al instante», y por defecto es
                   0: ningún método suma sin que alguien lo confirme. */
                : [$item, 0, 0, 0, '', '', 0];
            $existe = valor('SELECT id FROM lista_items WHERE lista_id = ? AND valor = ?',
                            [$lista_id, $valor]);
            if ($existe) continue;
            /* Un método de fábrica que Administración renombró no se vuelve a crear (3f). */
            if ($clave === 'metodos_pago' && function_exists('metodos_renombrados')
                && tabla_existe('ajustes') && in_array($valor, metodos_renombrados(), true)) continue;
            $fila = ['lista_id' => $lista_id, 'valor' => $valor, 'orden' => $orden];
            if (columna_existe('lista_items', 'pide_comprobante')) {
                $fila['pide_comprobante'] = $comprobante;
                $fila['al_instante']      = $instante;
            }
            if (columna_existe('lista_items', 'cobra_flete')) {
                $fila['cobra_flete'] = $cobra_flete;
            }
            if (columna_existe('lista_items', 'ambito_envio')) {
                $fila['ambito_envio'] = $ambito;
            }
            if ($extra !== '') $fila['extra'] = $extra;
            if ($recargo > 0 && columna_existe('lista_items', 'recargo_centesimas')) {
                $fila['recargo_centesimas'] = $recargo;
            }
            /* El POS pide también la foto del DNI (3f). */
            if ($clave === 'metodos_pago' && str_starts_with((string)$valor, 'POS') && columna_existe('lista_items', 'pide_dni')) {
                $fila['pide_dni'] = 1;
            }
            insertar('lista_items', $fila);
        }
    }
}

/**
 * Los sitios del Perú. Solo se siembra si la tabla está vacía: después manda
 * lo que haya en pantalla, porque los asesores van añadiendo distritos y una
 * resiembra no puede deshacerlo.
 */
function sembrar_ubigeo(): void
{
    if (!tabla_existe('ubigeo')) return;
    if ((int) valor('SELECT COUNT(*) FROM ubigeo') > 0) return;

    $pais = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
    if (!$pais) return;

    ubigeo_sembrar_padron($pais);
}

function sembrar_estados_pedido(): void
{
    // Los que disparan lógica (dispara <> NULL) no se pueden borrar desde
    // Configuración: solo se les cambia el nombre.
    $estados = [
        ['registrado','Registrado',      10, 0, null,        'gris'],
        ['reservado', 'Reservado',       20, 0, null,        'ambar'],
        ['en_camino', 'En camino',       30, 0, null,        'gris'],
        ['llego',     'Producto llegó',  40, 0, 'stock',     'ambar'],
        // OJO: 'pagado' NO dispara el cashback. El cashback nace del PAGO, no
        // del estado; marcar un pedido como pagado a mano no puede regalar
        // saldo. Lo que dispara este estado es la comprobación de que no queda
        // saldo por cobrar.
        ['pagado',    'Pagado',          50, 0, 'cobro_total', 'verde'],
        /* 3j · el HUB los mueve solo: «Listo para entrega» cuando el CEO
           marca el lote, «En despacho» al mandarlo y «Entregado» con la foto. */
        ['listo',     'Listo para entrega', 45, 0, 'lote_listo', 'verde'],
        ['en_despacho','En despacho',    55, 0, 'despacho',  'ambar'],
        /* 5b (usuario, 2026-09-29): cuando Almacén lo alista, la etiqueta
           cambia de «En despacho» a «Alistado». */
        ['alistado',  'Alistado',        57, 0, 'alistado',  'verde'],
        ['entregado', 'Entregado',       60, 1, 'entrega',   'verde'],
        ['anulado',   'Anulado',         99, 1, 'anulacion', 'rojo'],
    ];
    foreach ($estados as [$clave,$nombre,$orden,$final,$dispara,$color]) {
        sembrar_fila('pedido_estados', ['clave' => $clave],
                     ['nombre' => $nombre, 'orden' => $orden, 'es_final' => $final,
                      'dispara' => $dispara, 'color' => $color]);
    }
}

function sembrar_ajustes(): void
{
    $ajustes = [
        ['empresa_nombre',       'Waka Importaciones',  'texto',    'general', 'Nombre de la empresa', 0],
        ['tratamiento_direccion','Jefe',                'texto',    'saludo',  'Cómo se saluda a Dirección', 0],
        ['cashback_porcentaje',  '1',                   'entero',   'cashback','Porcentaje de cada pago que se acredita', 0],
        ['cashback_meses',       '6',                   'entero',   'cashback','Meses que dura el saldo antes de vencer', 0],
        ['cashback_con_descuento','0',                  'booleano', 'cashback','¿El saldo se puede usar junto con un descuento?', 0],
        /* EL TOPE DEL DESCUENTO DEL ASESOR (3d): hasta este % del precio de
           los productos la venta sale sola; por encima espera a que
           Administración lo apruebe. Se cambia en Configuración › Descuentos. */
        ['descuento_tope_pct',   '5',                   'entero',   'pedidos', 'Hasta qué % puede descontar el asesor sin aprobación', 0],
        /* 3h.1 (usuario 2026-09-28): el descuento ya no pide permiso; queda
           anotado con su motivo. Quien quiera volver a la aprobación la
           enciende en Configuración › Descuentos. */
        ['descuento_pide_aprobacion','0',               'booleano', 'pedidos', '¿Un descuento que pasa del tope espera a Administración?', 0],
        ['meta_mensual_general', '3000000',             'entero',   'metas',   'Meta mensual en céntimos (S/ 30,000)', 0],
        /* MÓDULO 5 · bonos y rachas. Todo se cambia en Configuración › Bonos. */
        ['bonos_poco_pct',       '70',                  'entero',   'bonos',   'Desde qué % de avance la tarjeta dice «Dale con todo»', 0],
        ['bonos_cierre_horas',   '12',                  'entero',   'bonos',   'Horas que espera un bono vencido antes de cerrarse (para que Facturación confirme lo de ayer)', 0],
        ['racha_aviso_desde',    '3',                   'entero',   'bonos',   'Desde qué racha del día se avisa al equipo (x3)', 0],
        ['racha_avisos',         '1',                   'booleano', 'bonos',   'Avisar al equipo de las rachas', 0],
        ['racha_nombres',        '',                    'texto',    'bonos',   'Los nombres de la escalera de la racha', 0],
        ['logro_montos_bloqueado','0',                  'booleano', 'bonos',   'No dejar poner montos en soles en la imagen de un logro', 0],
        ['los_lobos_titulo',     'Los lobos del mes',   'texto',    'bonos',   'Título del podio del Inicio', 0],
        ['frases_caceria',       '',                    'texto',    'bonos',   'Frases para la Cacería del Día (una por línea)', 0],
        ['frases_team_primero',  '',                    'texto',    'bonos',   'Modo team: frases cuando el equipo va primero', 0],
        ['frases_team_medio',    '',                    'texto',    'bonos',   'Modo team: frases cuando el equipo no va primero', 0],
        ['frases_team_ultimo',   '',                    'texto',    'bonos',   'Modo team: frases cuando el equipo va último', 0],
        ['control_stock',        '0',                   'booleano', 'stock',   'Mientras esté apagado, las ventas no descuentan inventario', 0],
        /* Desde cuántas unidades el stock de la web sale en ámbar: «quedan pocas» (3b.5). */
        ['stock_poco',           '3',                   'entero',   'stock',   'Unidades desde las que el stock sale como «quedan pocas»', 0],
        /* MIENTRAS EL CATÁLOGO SE LLENA (usuario, 2026-09-22): el asesor puede
           escribir un producto que todavía no está cargado. La venta queda
           marcada «fuera de catálogo» para que Administración vea qué falta.
           Se apaga desde Configuración el día que el catálogo esté completo. */
        ['catalogo_libre',       '1',                   'booleano', 'stock',   'Dejar vender productos que no están en el catálogo', 0],
        // El precio que se teclea es el que paga el cliente, IGV incluido
        // (confirmado por el usuario el 2026-09-08). Se guarda la regla ahora
        // para que el día que se conecte la facturación electrónica no haya
        // que tocar los pedidos ya registrados: la base y el IGV se calculan
        // desde el total, no al revés.
        ['precios_incluyen_igv', '1',                   'booleano', 'facturacion','Los precios ya incluyen IGV', 0],
        ['igv_porcentaje',       '18',                  'entero',   'facturacion','Porcentaje de IGV vigente', 0],
        ['recordarme_dias',      '30',                  'entero',   'acceso',  'Días que dura "recordarme"', 0],
        ['pago_dias_atras',      '30',                  'entero',   'pagos',   'Cuántos días atrás puede fechar un pago un asesor (Administración no tiene tope)', 0],
        ['cashback_minimo_centimos','1000',              'entero',   'cashback','Mínimo acumulado para poder usar el cashback (S/ 10)', 0],
        /* El WhatsApp al que escribe el asesor cuando facturación le deja un
           pago en espera o se lo deniega. Nace VACÍO y con el campo vacío el
           botón no se pinta: un botón que abre un chat con nadie es peor que
           no tener botón. */
        ['whatsapp_facturacion', '',                    'texto',    'pagos',   'WhatsApp de facturación, para el botón del pedido trabado (ej. 51987654321)', 0],
        /* Cuánto espera el asesor antes de poder reclamar que le confirmen una
           venta. Cinco minutos: con un minuto, facturación recibe reclamos de
           ventas que estaba atendiendo (usuario, 2026-09-14). Editable. */
                ['reclamo_minutos',      '5',                   'entero',   'pagos',   'Minutos antes de poder reclamar que confirmen una venta', 0],
        ['saldo_aviso_dias',     '1',                   'entero',   'pagos',   'Días después del despacho para avisar al asesor del saldo por registrar', 0],
                ['saldo_admin_dias',     '3',                   'entero',   'pagos',   'Días después del despacho para que Administración vea el saldo sin registrar', 0],
        /* NUBEFACT (parche 2u). Todo técnico: se edita en su propia pantalla,
           Configuración › Facturación electrónica, nunca en una lista genérica
           de ajustes, porque el token es una llave y no se enseña. */
        ['nubefact_ambiente',             'prueba', 'texto', 'nubefact', 'Ambiente de NUBEFACT en uso', 1],
        ['nubefact_auth',                 'directo','texto', 'nubefact', 'Cómo se manda el token a NUBEFACT', 1],
        ['nubefact_prueba_ruta',          '',       'texto', 'nubefact', 'Ruta de NUBEFACT (prueba)', 1],
        ['nubefact_prueba_token',         '',       'texto', 'nubefact', 'Token de NUBEFACT (prueba)', 1],
        ['nubefact_prueba_serie_boleta',  'BBB1',   'texto', 'nubefact', 'Serie de boletas (prueba)', 1],
        ['nubefact_prueba_serie_factura', 'FFF1',   'texto', 'nubefact', 'Serie de facturas (prueba)', 1],
        ['nubefact_prueba_serie_nc_boleta','BBB1',  'texto', 'nubefact', 'Serie de notas de crédito de boleta (prueba)', 1],
        ['nubefact_prueba_serie_nc_factura','FFF1', 'texto', 'nubefact', 'Serie de notas de crédito de factura (prueba)', 1],
        ['nubefact_produccion_ruta',      '',       'texto', 'nubefact', 'Ruta de NUBEFACT (producción)', 1],
        ['nubefact_produccion_token',     '',       'texto', 'nubefact', 'Token de NUBEFACT (producción)', 1],
        ['nubefact_produccion_serie_boleta',  '',   'texto', 'nubefact', 'Serie de boletas (producción)', 1],
        ['nubefact_produccion_serie_factura', '',   'texto', 'nubefact', 'Serie de facturas (producción)', 1],
        ['nubefact_produccion_serie_nc_boleta',  '','texto', 'nubefact', 'Serie de notas de crédito de boleta (producción)', 1],
        ['nubefact_produccion_serie_nc_factura', '','texto', 'nubefact', 'Serie de notas de crédito de factura (producción)', 1],
        ['woo_url',              '',                    'texto',    'tienda',  'Dirección de la tienda', 1],
        ['woo_key',              '',                    'texto',    'tienda',  'Clave de SOLO LECTURA de la tienda', 1],
        ['woo_secret',           '',                    'texto',    'tienda',  'Secreto de SOLO LECTURA de la tienda', 1],
        /* Cómo se mandan las claves (en la cabecera o en la dirección): se
           queda la que funcionó al probar la conexión. Y cuándo se probó. */
        ['woo_auth',             'basica',              'texto',    'tienda',  'Cómo se mandan las claves a la tienda', 1],
        ['woo_probada_en',       '',                    'texto',    'tienda',  'Última prueba correcta de la conexión con la tienda', 1],
        /* 3c: la clave del plugin «Waka HUB · Conector». Se pega en
           Configuración › Tienda y no se vuelve a enseñar. */
        ['conector_clave',       '',                    'texto',    'tienda',  'Clave del conector de la tienda', 1],
        ['conector_probado_en',  '',                    'texto',    'tienda',  'Última prueba correcta del conector', 1],
        ['woo_pais',             '0',                   'entero',   'tienda',  'País al que pertenece la tienda conectada', 1],
    ];
    foreach ($ajustes as [$c,$v,$t,$g,$d,$tec]) {
        $hay = valor('SELECT 1 FROM ajustes WHERE clave = ?', [$c]);
        if ($hay) {
            q('UPDATE ajustes SET tipo=?, grupo=?, descripcion=?, tecnico=? WHERE clave=?',
              [$t,$g,$d,$tec,$c]);
        } else {
            q('INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
               VALUES (?,?,?,?,?,?)', [$c,$v,$t,$g,$d,$tec]);
        }
    }
}

/**
 * Las frases de los roles que no venden (3g). Las generales hablan de vender
 * («el que llama primero, cierra primero») y a Almacén y Marketing no les
 * tocan. Aparte de sembrar_frases() porque aquella solo siembra con la tabla
 * vacía: así llegan igual a un HUB nuevo que a uno actualizado. Solo si ese
 * rol todavía no tiene ninguna: las que se editen no se pisan.
 */
function sembrar_frases_de_rol(): void
{
    if (!tabla_existe('frases')) return;
    $por_rol = [
        /* Varias por franja: la frase del día no repite la última, y con una
           sola se caía a las generales, que hablan de vender. */
        'almacen' => [
            ['manana', 'Lo que sale hoy se prepara temprano.'],
            ['manana', 'Un buen conteo ahorra diez llamadas.'],
            ['manana', 'Modelo correcto, cliente contento.'],
            ['tarde', 'Mira lo que falta alistar: que nada espere por una caja.'],
            ['tarde', 'Antes de cerrar la caja, revisa el modelo otra vez.'],
            ['tarde', 'Una foto clara hoy ahorra un reclamo mañana.'],
            ['noche', 'Stock bien puesto hoy, ventas sin sorpresas mañana.'],
            ['noche', 'Deja el almacén como te gustaría encontrarlo.'],
            ['madrugada', 'Que el descanso también cuente como trabajo.'],
            ['madrugada', 'Mañana se alista mejor si hoy se descansa.'],
        ],
        'marketing' => [
            ['manana', 'Una buena foto y un buen nombre venden antes que el precio.'],
            ['manana', 'Lo que el cliente lee primero es el nombre del producto.'],
            ['tarde', 'Revisa qué productos siguen sin precio: así no se pueden vender.'],
            ['tarde', 'Un precio claro por cantidad convence más que un descuento.'],
            ['noche', 'Lo que dejes listo hoy en la tienda vende mañana.'],
            ['noche', 'Mira la tienda como la ve un cliente.'],
            ['madrugada', 'Que el descanso también cuente como trabajo.'],
            ['madrugada', 'Las mejores ideas llegan descansado.'],
        ],
    ];
    foreach ($por_rol as $rol => $lista) {
        if ((int) valor('SELECT COUNT(*) FROM frases WHERE rol_clave = ?', [$rol]) > 0) continue;
        $orden = (int) valor('SELECT COALESCE(MAX(orden), 0) FROM frases');
        foreach ($lista as [$franja, $texto]) {
            $orden += 10;
            insertar('frases', ['rol_clave' => $rol, 'franja' => $franja, 'texto' => $texto, 'orden' => $orden]);
        }
    }
}

function sembrar_frases(): void
{
    if ((int) valor('SELECT COUNT(*) FROM frases') > 0) return;

    $frases = [
        // Para cualquiera
        [null,'manana','Hoy empieza otra vez, y eso es una ventaja.'],
        [null,'manana','El que llama primero, cierra primero.'],
        [null,'manana','Un buen día se decide en la primera hora.'],
        [null,'manana','Nadie vendió nunca mirando el teléfono apagado.'],
        [null,'tarde','La tarde es de los que insisten.'],
        [null,'tarde','Todavía queda medio día por delante.'],
        [null,'tarde','Una llamada más no cuesta nada y a veces lo cambia todo.'],
        [null,'noche','Lo que cierres hoy ya no lo tienes que perseguir mañana.'],
        [null,'noche','Buen momento para dejar la lista de mañana lista.'],
        [null,'madrugada','A esta hora ya casi nadie compite contigo.'],
        [null,'madrugada','Que el descanso también cuente como trabajo.'],
        // Dirección
        ['direccion','manana','La empresa arranca contigo.'],
        ['direccion','manana','Los números de ayer ya están; los de hoy se escriben ahora.'],
        ['direccion','tarde','Mientras el resto almuerza, algo se está moviendo en el Pacífico.'],
        ['direccion','noche','Se cierra el día. Buen momento para mirar el marcador.'],
        ['direccion','madrugada','Trasnochando por negocios.'],
        ['direccion','madrugada','Tu mente nunca se calma.'],
        ['direccion','madrugada','A esta hora las decisiones pesan el doble. Y se toman igual.'],
        // Asesor
        ['asesor','manana','Tu cartera te está esperando.'],
        ['asesor','tarde','Revisa quién tiene saldo por vencer: es una llamada con motivo.'],
        ['asesor','noche','Cierra el día registrando lo que vendiste. Mañana no te vas a acordar.'],
    ];
    foreach ($frases as $i => [$rol, $franja, $texto]) {
        insertar('frases', ['rol_clave'=>$rol, 'franja'=>$franja, 'texto'=>$texto, 'orden'=>$i*10]);
    }
}

function sembrar_bonos(): void
{
    /* 5a: los bonos se siembran POR PAÍS y apagados (bonos_sembrar, en
       bonos.php). Esta lista vieja, sin país, ya no se crea. */
    if (function_exists('bonos_listo') && bonos_listo()) return;
    if ((int) valor('SELECT COUNT(*) FROM bonos') > 0) return;

    // El motor es uno solo: período × métrica × condición × premio.
    // Cambiar un monto o un tramo es editar una fila, no tocar código.
    $bonos = [
        ['caceria_dia', 'La Cacería del Día', 'dia', 'pedidos',
         ['tramos' => [['desde'=>5,'premio'=>5000],['desde'=>10,'premio'=>10000],
                       ['desde'=>15,'premio'=>15000],['desde'=>20,'premio'=>20000]]],
         ['tipo'=>'escalera','maximo'=>20000], 1, 10],

        ['meta_mes', 'La Meta del Mes', 'mes', 'cobrado',
         ['minimo_centimos' => 3000000], ['tipo'=>'fijo','monto'=>30000], 1, 20],

        ['la_manada', 'La Manada', 'mes', 'equipo',
         ['condiciones' => 2], ['tipo'=>'fijo','monto'=>50000], 0, 30],

        ['sin_freno', 'Sin Freno', 'semana', 'clientes_semana',
         ['minimo' => 7], ['tipo'=>'fijo','monto'=>10000], 1, 40],

        ['racha_viva', 'Racha Viva', 'semana', 'dias_seguidos',
         ['minimo' => 5], ['tipo'=>'fijo','monto'=>8000], 1, 50],

        ['cobrador', 'El Cobrador', 'quincena', 'cobrado_de_saldos',
         ['minimo_centimos' => 1000000], ['tipo'=>'fijo','monto'=>15000], 1, 60],

        ['wakaciones', 'Wakaciones', 'semestre', 'cobrado',
         ['top' => 1], ['tipo'=>'viaje'], 1, 70],
    ];
    foreach ($bonos as [$clave,$nombre,$periodo,$metrica,$cond,$premio,$indiv,$orden]) {
        insertar('bonos', [
            'clave'=>$clave, 'nombre'=>$nombre, 'periodo'=>$periodo, 'metrica'=>$metrica,
            'condicion'=>json_encode($cond, JSON_UNESCAPED_UNICODE),
            'premio'=>json_encode($premio, JSON_UNESCAPED_UNICODE),
            'individual'=>$indiv, 'orden'=>$orden,
        ]);
    }
}

/**
 * Las plantillas de WhatsApp.
 *
 * Se SIEMBRAN, no se reescriben: si una ya existe se deja como está, porque
 * el texto se edita desde Configuración y una actualización del HUB no puede
 * pisar lo que Administración cambió en pantalla.
 *
 * Las tres de venta salen del mensaje que hoy escriben a mano al grupo de
 * facturación, con el mismo orden y los mismos emojis, para que el que lo
 * recibe no note el cambio. Una línea cuyas variables salgan vacías se cae
 * sola: en un recojo en oficina no aparece «Dirección:» vacía.
 */
function sembrar_plantillas(): void
{
    $p = [
        ['venta_lima', 'Confirmación de venta · Lima',
"▶️ Confirmación de Venta en LIMA 🎉🌟
✅ Nombre: {cliente}
✅ Celular: {celular}
✅ {tipo_doc}: {documento}
✅ Dirección: {direccion}
✅ Referencia: {referencia}
✅ Distrito: {distrito}
✅ Producto:
{detalle}
✅ Garantía: {garantia}
✅ Detalle: {nota}
Cobrar {saldo}
{nota_flete}
{armado}
✅ Asesor: {asesor}
✅ Fecha: {fecha}
✅ Pedido: {codigo}"],

        ['venta_provincia', 'Confirmación de venta · Provincia',
"▶️ Confirmación de Venta a PROVINCIA 🎉🌟
✅ Nombre: {cliente}
✅ Celular: {celular}
✅ {tipo_doc}: {documento}
✅ Departamento: {departamento}
✅ Provincia: {provincia}
✅ Distrito: {distrito}
✅ Agencia: {agencia}
✅ Sucursal: {sucursal}
✅ Producto:
{detalle}
✅ Garantía: {garantia}
✅ Detalle: {nota}
Cobrar {saldo}
{nota_flete}
{armado}
✅ Asesor: {asesor}
✅ Fecha: {fecha}
✅ Pedido: {codigo}"],

        ['venta_preventa', 'Confirmación de PRE VENTA',
"🚢 PRE VENTA SEPARADA
✅ Nombre: {cliente}
✅ Celular: {celular}
✅ {tipo_doc}: {documento}
✅ Producto:
{detalle}
✅ Garantía: {garantia}
✅ Total: {total}
✅ Adelanto recibido: {cobrado}
✅ Saldo al llegar el contenedor: {saldo}
✅ Asesor: {asesor}
✅ Fecha: {fecha}
✅ Pedido: {codigo}"],

        /* AL GRUPO DE DESPACHO. Una sola plantilla para Lima y para provincia:
           las líneas cuyas variables salgan todas vacías se caen solas, así que
           en un envío a provincia no aparece «Dirección:» en blanco ni en uno
           de Lima aparece «Agencia:».
           Lleva las cuatro cosas que pidió el usuario: datos de envío,
           productos, estado del pago y código del pedido con su asesor. */
        ['despacho', 'Al grupo de DESPACHO · Lima',
"📦 DESPACHO · {codigo}
✅ Nombre: {cliente}
✅ Número de contacto: {celular}
✅ {tipo_doc}: {documento}
✅ Recibe: {recibe}
✅ Dirección: {direccion}
✅ Referencia: {referencia}
✅ Distrito: {distrito}
📍 Mapa: {mapa}
✅ Producto:
{productos}
✅ Modelo/color: {modelos}
✅ Nota: {nota}
📅 Entregar: {entrega_cuando}
{pago_estado}
{armado}
✅ Comprobante: {comprobante}
✅ Asesor: {asesor}
✅ Fecha: {fecha}"],

        /* PROVINCIA ES OTRO MENSAJE, no el mismo con líneas vacías
           (usuario, 2026-09-20): el destino va en una sola línea, no
           lleva mapa —allá entrega la agencia— y no lleva precio ni
           cobro, porque a provincia se paga todo antes de que salga. */
        ['despacho_provincia', 'Al grupo de DESPACHO · Provincia',
"📦 DESPACHO A PROVINCIA · {codigo}
✅ Nombre: {cliente}
✅ Número de contacto: {celular}
✅ {tipo_doc}: {documento}
✅ Destino: {destino_linea}
✅ Producto:
{productos}
✅ Modelo/color: {modelos}
✅ Agencia: {agencia} - {sucursal}
✅ Cómo lo recibe: {como_recibe}
✅ Nota: {nota}
✅ Comprobante: {comprobante}
✅ Asesor: {asesor}
✅ Fecha: {fecha}"],

        ['pedido_registrado','Aviso al cliente · pedido registrado',
"Hola {nombre}, soy {asesor} de Waka Importaciones.
Tu pedido {codigo} quedó registrado por {total}.
{detalle}
Saldo por cobrar: {saldo}
Cualquier cosa me escribes por aquí."],

        ['datos_pago','Datos para el pago',
"Hola {nombre}, te paso los datos para el pago de tu pedido {codigo}.
Queda {saldo} por cobrar.
Cuando transfieras me mandas la captura, por favor."],

        ['pago_recibido','Pago recibido',
"Listo {nombre}, recibí tu pago. Tu pedido {codigo} queda con {saldo} por cobrar.
Recuerda que cada pago te suma Cashback Waka para tu próxima compra."],

        ['lote_llego','Llegó el contenedor',
"Hola {nombre}, ya llegó el contenedor con tu {producto}.
Queda {saldo} por cobrar. ¿Cuándo lo recoges o te lo enviamos?"],

        ['cashback_por_vencer','Cashback por vencer',
"Hola {nombre}, tienes Cashback Waka a punto de vencer.
Es un saldo para descontar en tu próxima compra."],
    ];

    foreach ($p as [$clave, $nombre, $texto]) {
        if (valor('SELECT id FROM plantillas_whatsapp WHERE clave = ?', [$clave])) continue;
        insertar('plantillas_whatsapp', ['clave' => $clave, 'nombre' => $nombre, 'texto' => $texto]);
    }
}
