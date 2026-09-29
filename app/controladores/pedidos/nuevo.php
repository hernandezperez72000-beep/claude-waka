<?php
declare(strict_types=1);
seccion_activa('pedidos');

$u       = yo();
$pais_id = (int)$u['pais_id'];

/* Un asesor registra a su nombre y punto. Administración puede registrar por
   otro (pasa: el asesor se quedó sin batería y llama a la oficina), pero
   siempre dentro de su país y dejando en la bitácora quién lo hizo. */
$puede_por_otro = puede('usuarios.gestionar') && $u['ambito'] === 'todo';

/**
 * ¿Puedo VENDERLE a este cliente?
 *
 * Ojo, que no es lo mismo que poder verlo. El ámbito «equipo» deja al líder
 * LEER la cartera de los suyos, y con solo comprobar lectura se le podía
 * registrar un pedido a un cliente de un subordinado: la venta nacía a nombre
 * del líder y le sumaba a su meta, a su podio y a sus bonos. Es exactamente la
 * disputa de meta a fin de mes que el ámbito de solo lectura existe para
 * evitar. Registrar un pedido es ESCRIBIR sobre la cartera de alguien, así que
 * se pregunta por escritura: o el cliente es tuyo, o eres Administración de su
 * país.
 * Un cliente sin asesor asignado no es de nadie, y a ese sí puede atenderlo
 * cualquiera de su país.
 */
/* puedo_venderle() vive en nucleo/permisos.php (3j): una sola definición. */

$cliente_id = pedir_int('cliente', 'get');
$cliente    = null;
if ($cliente_id) {
    $cliente = una('SELECT c.*, ua.equipo_id AS asesor_equipo FROM clientes c
                      LEFT JOIN usuarios ua ON ua.id = c.asesor_id
                     WHERE c.id = ? AND c.activo = 1', [$cliente_id]);
    if ($cliente && !puedo_venderle($cliente)) {
        cortar(403, 'Ese cliente es de otro asesor',
                    'Puedes ver su ficha, pero la venta es de quien lo atiende. Si tiene que '
                  . 'pasar a tu cartera, pídeselo a Administración y queda registrado.');
    }
}

$metodos     = lista('metodos_pago', $pais_id);
$agencias    = lista('agencias', $pais_id);
$canales     = lista('canales', $pais_id);
$tipos_envio = lista('tipos_envio', $pais_id);
$garantias   = lista('garantias', $pais_id);
/* ¿Existe ya la columna? Se pregunta UNA vez, no en cada pedido. */
$hay_garantia = columna_existe('pedidos', 'garantia_item_id');
/* Quién puede fechar el pedido hacia atrás. El asesor no: para él la fecha es
   hoy y el campo ni se pinta. */
$puede_fechar = puede('pagos.verificar');

/* EL CONTEXTO DEL VOUCHER ES EL FORMULARIO, NO EL CLIENTE.
   Era 'nuevo:<cliente_id>', y el cliente CAMBIA a mitad del formulario: el
   asesor escribe el nombre en el buscador, no llega a pulsar la sugerencia, el
   envío rebota con «Elige al cliente» y el archivo queda archivado bajo
   'nuevo:0'. Al elegir al cliente y reenviar, la clave pasaba a 'nuevo:123' y
   el voucher desaparecía sin decirlo — con efectivo ni siquiera daba error: el
   pago se guardaba sin comprobante. Con un identificador propio del
   formulario, cambiar de cliente no pierde nada y dos pantallas distintas
   siguen sin poder robarse el archivo, que es para lo que existe el contexto. */
$ctx_voucher = 'nuevo:' . substr(preg_replace('/[^a-zA-Z0-9]/', '', pedir('f_id')) ?? '', 0, 24);
if (strlen($ctx_voucher) < 14) $ctx_voucher = 'nuevo:' . bin2hex(random_bytes(6));
$f_id = substr($ctx_voucher, 6);
$asesores    = $puede_por_otro
    ? todas("SELECT us.id, us.nombre, us.apellidos FROM usuarios us
               JOIN roles r ON r.id = us.rol_id AND r.clave = 'asesor'
              WHERE us.pais_id = ? AND us.activo = 1 ORDER BY us.nombre", [$pais_id])
    : [];

$errores = [];
$d = [];
/* «NUEVA VENTA DE PRE VENTA» (3h) abre el formulario ya en pre venta. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && pedir('tipo', 'get') === 'preventa' && tipo_venta_valido('preventa')) $d['tipo'] = 'preventa';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* A DÓNDE VA: Lima, provincia u oficina. Se pregunta en el paso 2, antes
       que nada del envío, porque es lo que decide la mitad del formulario —y
       hasta el 2f se preguntaba al final, cuando el asesor ya había llenado
       campos que no aplicaban a su caso.

       No se guarda como columna: «Lima o provincia» ya lo dice el distrito, y
       «oficina» ya lo dice `entrega`. Guardarlo sería el mismo dato en dos
       sitios, que es como acaban diciendo cosas distintas. Viaja en el
       formulario para pintar la pantalla, y el servidor comprueba que cuadre
       con el distrito elegido. */
    $destino = pedir('destino');
    if (!in_array($destino, ['lima', 'provincia', 'oficina'], true)) {
        /* Sin `destino` —un formulario viejo, o alguien llamando a mano— se
           deduce de lo que sí hay: el distrito. Es la misma autoridad que
           manda en todo lo demás, y así la falta de un campo de pantalla
           nunca inventa un error que el asesor no puede entender. */
        $ubi = pedir_int('ubigeo_id');
        if (pedir('entrega') !== 'envio' && !$ubi) {
            $destino = 'oficina';
        } elseif ($ubi && !ubigeo_es_lima($ubi)) {
            $destino = 'provincia';
        } else {
            /* Sin distrito todavía, Lima: es lo que trae marcado la pantalla, y
               así el HUB no le pide una agencia a alguien que está mirando el
               formulario de Lima. */
            $destino = 'lima';
        }
    }

    $d = [
        /* Los tipos salen de tipos_de_venta(), que es de donde salen también
           el formulario y el filtro de la lista. Con el ternario de antes
           —«preventa ? preventa : inmediata»— una liquidación se habría
           guardado como entrega inmediata sin un solo error. */
        'tipo'        => tipo_venta_valido(pedir('tipo')) ? pedir('tipo') : 'inmediata',
        'cliente_id'  => pedir_int('cliente_id'),
        'asesor_id'   => pedir_int('asesor_id') ?: (int)$u['id'],
        'destino'     => $destino,
        'entrega'     => $destino === 'oficina' ? 'recojo' : 'envio',
        /* A provincia hay dos formas, y hasta ahora solo existía una: el
           formulario pedía dirección SIEMPRE. La más usada es que el cliente
           pase por la agencia, y ahí no hay dirección que valga. */
        'recojo_agencia' => ($destino === 'provincia' && pedir('envio_modo') !== 'domicilio') ? 1 : 0,
        'canal_item_id'      => pedir_int('canal_item_id'),
        'garantia_item_id'   => pedir_int('garantia_item_id'),
        'tipo_envio_item_id' => pedir_int('tipo_envio_item_id'),
        'agencia_item_id'    => pedir_int('agencia_item_id'),
        'ubigeo_id'   => pedir_int('ubigeo_id'),
        'direccion_txt' => pedir('direccion_txt'),
        'referencia_txt'=> pedir('referencia_txt'),
        'gps'         => pedir('gps'),
        'recibe'      => pedir('recibe'),
        'sucursal'    => pedir('sucursal'),
        'agencia_otra'=> pedir('agencia_otra'),
        'envio_armado'=> pedir('envio_armado') === '1' ? 1 : 0,
        /* Cuándo hay que entregarlo: lo que el cliente dice AHORA. Todo
           opcional; se valida abajo con pedido_entrega_guardar(), que es la
           misma función que usa la pantalla de despacho para corregirlo. */
        'entrega_fecha'     => pedir('entrega_fecha'),
        'entrega_hora_desde'=> pedir('entrega_hora_desde'),
        'entrega_hora_hasta'=> pedir('entrega_hora_hasta'),
        'entrega_recepcion' => pedir('entrega_recepcion') === '1' ? 1 : 0,
        'nota'        => pedir('nota'),
        /* LA FECHA DEL PEDIDO ES EL DÍA EN QUE SE REGISTRA, y el asesor no
           la elige: se lo preguntábamos y nunca tenía nada que contestar
           (usuario, 2026-09-11). La que de verdad importa —y esa sí se puede
           mover— es la del PAGO, que decide a qué mes suma el dinero.
           Administración y facturación sí pueden fecharla hacia atrás, por si
           hay que meter una venta vieja; para ellos el campo sigue saliendo. */
        'fecha'       => $puede_fechar
            ? (pedir('fecha') ?: date('Y-m-d'))
            /* Y CUANDO NO LA ELIGE, LA MARCA EL PAGO. No es un detalle: el
               caso más común del negocio es «el cliente yapeó anoche y el
               asesor registra la venta esta mañana». Con la fecha del pedido
               clavada en hoy, pago_registrar() rechazaba ese pago por ser
               anterior a su pedido, el pedido ya estaba guardado y quedaba una
               venta a cero que el asesor no podía arreglar desde ninguna
               pantalla. La fecha de la venta es el día en que el cliente pagó,
               que además es el único dato que el asesor tiene de verdad; hacia
               atrás la acota la propia regla del pago (pago_dias_atras). */
            : fecha_de_venta_por_el_pago(pedir('pago_fecha')),
        'flete_lo_paga' => pedir('flete_lo_paga') === 'incluido' ? 'incluido' : 'cliente_destino',
    ];

    /* ── El asesor del pedido ─────────────────────────────────────────
       Quien no puede registrar por otro se queda consigo mismo, aunque
       manipule el formulario: es lo que decide de quién es la venta, la meta
       y la comisión del futuro. */
    if (!$puede_por_otro) {
        $d['asesor_id'] = (int)$u['id'];
    } else {
        $ok = valor("SELECT 1 FROM usuarios us JOIN roles r ON r.id = us.rol_id
                      WHERE us.id = ? AND us.pais_id = ? AND us.activo = 1 AND r.clave = 'asesor'",
                    [$d['asesor_id'], $pais_id]);
        if (!$ok) { $errores[] = 'Ese asesor no existe o no es de tu país.'; $d['asesor_id'] = (int)$u['id']; }
    }

    /* ── El cliente ───────────────────────────────────────────────── */
    $cli = $d['cliente_id']
        ? una('SELECT c.*, ua.equipo_id AS asesor_equipo FROM clientes c
                 LEFT JOIN usuarios ua ON ua.id = c.asesor_id
                WHERE c.id = ? AND c.activo = 1', [$d['cliente_id']])
        : null;
    if (!$cli) {
        $errores[] = 'Elige al cliente. Si es nuevo, primero se registra su ficha.';
    } elseif ((int)$cli['pais_id'] !== $pais_id) {
        // Un cliente de otro país en un pedido de este sería una fuga entre países.
        $errores[] = 'Ese cliente no es de tu país.';
        $cli = null;
    } elseif (!puedo_venderle($cli)) {
        // La misma comprobación que al abrir el formulario, otra vez aquí: el
        // POST se puede mandar sin pasar por la pantalla.
        cortar(403, 'Ese cliente es de otro asesor',
                    'La venta es de quien lo atiende. Si tiene que pasar a tu cartera, '
                  . 'pídeselo a Administración.');
    } else {
        $cliente = $cli;
    }

    /* ── Las líneas ──────────────────────────────────────────────── */
    $lineas = [];
    $desc   = (array)($_POST['l_desc']  ?? []);
        $sku    = (array)($_POST['l_sku']   ?? []);
        $modelo = (array)($_POST['l_modelo']?? []);
    $hay_modelo = columna_existe('pedido_lineas', 'modelo');
    $cant   = (array)($_POST['l_cant']  ?? []);
    $precio = (array)($_POST['l_precio']?? []);
    /* EL CATÁLOGO (módulo 3a-1). La pantalla manda qué producto se eligió; el
       PRECIO lo vuelve a calcular aquí `precio_de_variante()`, porque lo que
       llega del navegador no puede decidir dinero. Si la línea no trae
       producto, se escribió a mano: se permite mientras el catálogo se llena
       y queda marcada, o se rechaza si Configuración ya lo cerró. */
    $l_prod = (array)($_POST['l_producto'] ?? []);
    $l_var  = (array)($_POST['l_variante'] ?? []);
    /* PRE VENTA (3h): la fila del lote de donde sale cada línea. Con un lote
       a la venta en el país, la pre venta SOLO sale de lotes (corte duro). */
    $l_lote = (array)($_POST['l_lote'] ?? []);
    $pv_lotes = preventa_con_lotes();
    /* El tramo de pre venta va por PRODUCTO del lote: se suman sus colores. */
    $pv_tot = [];
    if (preventa_lista()) {
        foreach ($l_lote as $i_l => $llx) {
            /* Solo las líneas que se van a guardar: una línea vacía o con una
               cantidad imposible no puede bajar el tramo de las demás. */
            $c_l = (int)($cant[$i_l] ?? 0);
            if (trim((string)($desc[$i_l] ?? '')) === '' || $c_l <= 0 || $c_l > 100000) continue;
            $llf = (int)$llx ? una('SELECT lote_id, producto_id FROM lote_lineas WHERE id = ?', [(int)$llx]) : null;
            if ($llf) $pv_tot[$llf['lote_id'] . '|' . $llf['producto_id']] = ($pv_tot[$llf['lote_id'] . '|' . $llf['producto_id']] ?? 0) + max(0, (int)($cant[$i_l] ?? 0));
        }
    }
    /* 3j · EL TRAMO VA POR PRODUCTO TAMBIÉN FUERA DE LOS LOTES: 5 negras y 5
       azules de la misma silla son 10 sillas y valen al precio de 10 (usuario,
       2026-09-28). Se suman las líneas del catálogo del mismo producto. */
    $cat_tot = [];
    foreach ($l_prod as $i_p => $pp) {
        $c_p = (int)($cant[$i_p] ?? 0);
        if ((int)$pp <= 0 || !empty($l_lote[$i_p]) || trim((string)($desc[$i_p] ?? '')) === '' || $c_p <= 0 || $c_p > 100000) continue;
        $cat_tot[(int)$pp] = ($cat_tot[(int)$pp] ?? 0) + $c_p;
    }
    $hay_catalogo = columna_existe('pedido_lineas', 'producto_id');
    $garantia_catalogo = 0;

    foreach ($desc as $i => $texto) {
        $texto = trim((string)$texto);
        if ($texto === '') continue;
        $c = (int)($cant[$i] ?? 0);
        $p = a_centimos(trim((string)($precio[$i] ?? '')));

        $prod_id = (int)($l_prod[$i] ?? 0);
        $var_id  = (int)($l_var[$i] ?? 0);
        $lote_ln = (int)($l_lote[$i] ?? 0);
        if ($lote_ln && !preventa_lista()) {
            $errores[] = 'La pre venta todavía no está lista: falta terminar la actualización.';
            continue;
        }
        if ($lote_ln && $d['tipo'] !== 'preventa') {
            $errores[] = '«' . $texto . '» es de pre venta: marca «Pre venta» o elige otro producto.';
            continue;
        }
        if ($d['tipo'] === 'preventa' && $pv_lotes && !$lote_ln) {
            $errores[] = $prod_id ? 'Elige el modelo o color de «' . $texto . '».'
                                  : 'Elige «' . $texto . '» de la pre venta (los productos de los lotes a la venta).';
            continue;
        }
        if ($lote_ln) {
            if ($c <= 0)     { $errores[] = 'La cantidad de «' . $texto . '» tiene que ser 1 o más.'; continue; }
            if ($c > 100000) { $errores[] = 'Esa cantidad de «' . $texto . '» no puede ser real.'; continue; }
            $llf = una('SELECT lote_id, producto_id FROM lote_lineas WHERE id = ?', [$lote_ln]);
            $pv = preventa_linea($lote_ln, $prod_id, $c, $llf ? ($pv_tot[$llf['lote_id'] . '|' . $llf['producto_id']] ?? $c) : $c);
            if (!$pv['ok']) { $errores[] = $pv['error']; continue; }
            $pl = $pv['linea'];
            if ($pl['precio_unit_centimos'] * $c > DINERO_MAXIMO_CENTIMOS) {
                $errores[] = 'La línea «' . $pl['descripcion'] . '» suma más de ' . soles(DINERO_MAXIMO_CENTIMOS) . '.';
                continue;
            }
            $fila_pv = [
                'descripcion' => $pl['descripcion'], 'sku' => $pl['sku'] ?: null, 'cantidad' => $c,
                'precio_unit_centimos' => $pl['precio_unit_centimos'], 'total_centimos' => $pl['precio_unit_centimos'] * $c,
                'origen' => pedido_origen_stock($d['tipo']), 'lote_linea_id' => $pl['lote_linea_id'],
            ];
            if ($hay_modelo) $fila_pv['modelo'] = mb_substr($pl['modelo'], 0, 120);
            if ($hay_catalogo) {
                $fila_pv['producto_id'] = $pl['producto_id'];
                $fila_pv['fuera_catalogo'] = 0;
                if (columna_existe('pedido_lineas', 'variante_id')) $fila_pv['variante_id'] = null;
            }
            $lineas[] = $fila_pv;
            continue;
        }
        $prod    = $prod_id ? producto_de($prod_id) : null;
        if ($prod_id && (!$prod || (int)$prod['activo'] !== 1)) {
            /* Llegó un producto que ya no existe o que se apagó mientras el
               asesor llenaba el formulario. Se para: dejarlo caer a texto
               libre guardaría el precio que mandó el navegador. */
            $errores[] = 'El producto de «' . $texto . '» ya no está disponible. Vuelve a elegirlo.';
            continue;
        }
        /* Los repuestos no se venden en pre venta (3i): se venden cuando llegan. */
        if ($prod && $d['tipo'] === 'preventa' && function_exists('producto_es_repuesto') && producto_es_repuesto($prod)) {
            $errores[] = '«' . $prod['nombre'] . '» es un repuesto: se vende como entrega inmediata.';
            continue;
        }
        if ($prod) {
            $tiene_variantes = count(producto_variantes($prod_id)) > 0;
            if ($var_id && !valor('SELECT id FROM variantes WHERE id = ? AND producto_id = ? AND activo = 1',
                                  [$var_id, $prod_id])) {
                $errores[] = 'Elige el modelo o color de «' . $prod['nombre'] . '».';
                continue;
            }
            /* Y si el producto TIENE colores, hay que decir cuál: despacho no
               puede adivinar qué color meter en la caja. */
            if (!$var_id && $tiene_variantes) {
                $errores[] = 'Elige el modelo o color de «' . $prod['nombre'] . '».';
                continue;
            }
            $cc = max(1, $cat_tot[$prod_id] ?? $c);
            $p_cat = precio_de_variante($prod_id, $var_id ?: null, $cc);
            if ($p_cat === null) {
                $errores[] = '«' . $prod['nombre'] . '» todavía no tiene precio en el catálogo.';
                continue;
            }
            $p = $p_cat;
            $texto = mb_substr((string)$prod['nombre'], 0, 220);
            /* Y el código también sale del catálogo: si lo pusiera el
               navegador, dos ventas del mismo producto podrían llevar códigos
               distintos y el reporte por producto dejaría de cuadrar. */
            $sku[$i] = $var_id
                ? (string) valor('SELECT sku FROM variantes WHERE id = ?', [$var_id])
                : (string)$prod['sku'];
            if (!$garantia_catalogo) $garantia_catalogo = (int)($prod['garantia_item_id'] ?? 0);
        } elseif (!catalogo_libre()) {
            $errores[] = 'Elige «' . $texto . '» del catálogo. Si falta, pídele a Administración que lo cargue.';
            continue;
        }
        if ($c <= 0)     { $errores[] = 'La cantidad de «' . $texto . '» tiene que ser 1 o más.'; continue; }
        if ($c > 100000) { $errores[] = 'Esa cantidad de «' . $texto . '» no puede ser real.'; continue; }
        if ($p === null) { $errores[] = 'El precio de «' . $texto . '» no se entiende. Escríbelo así: 379 o 379.50'; continue; }
        /* El tope no es una manía: precio × cantidad con un precio de quince
           dígitos desborda el entero de PHP, se vuelve float y acaba en una
           columna de dinero. Un solo pedido así destroza el «por cobrar» del
           panel del país. */
        if (!dinero_razonable($p)) {
            $errores[] = 'El precio de «' . $texto . '» no puede ser ese: el máximo es '
                       . soles(DINERO_MAXIMO_CENTIMOS) . ' por unidad.';
            continue;
        }
        if ($p * $c > DINERO_MAXIMO_CENTIMOS) {
            $errores[] = 'La línea «' . $texto . '» suma más de ' . soles(DINERO_MAXIMO_CENTIMOS)
                       . '. Revisa el precio y la cantidad.';
            continue;
        }
        $lineas[] = [
            'descripcion' => mb_substr($texto, 0, 220),
                                    'sku'         => mb_substr(trim((string)($sku[$i] ?? '')), 0, 60) ?: null,
            'cantidad'    => $c,
            'precio_unit_centimos' => $p,
            'total_centimos'       => $p * $c,
            /* De qué almacén sale. Liquidación tiene el suyo: reservarla
               contra el stock de tienda dejaría a la tienda vendiendo lo que
               ya no tiene. */
                        'origen'      => pedido_origen_stock($d['tipo']),
        ];
        /* MODELO Y COLOR, tal como se lo prometió al cliente: lo lee despacho y
           sale en el rótulo. Se añade SOLO si la columna existe —igual que la
           garantía—: en un HUB al que todavía no le han pasado el último paso
           de la actualización, meterlo en el INSERT reventaba cada venta. */
        if ($hay_modelo) {
            $lineas[count($lineas) - 1]['modelo'] = mb_substr(trim((string)($modelo[$i] ?? '')), 0, 120);
        }
        /* A qué producto del catálogo corresponde esta línea, que es lo que
           permite un reporte por producto. Solo si la columna existe: en un
           HUB al que le falte el último paso de la actualización, meterla en
           el INSERT reventaría cada venta. */
        if ($hay_catalogo) {
            /* `$k_lin` y no `$u`: `$u` es QUIEN REGISTRA la venta, se usa más
               abajo al guardar, y pisarlo aquí dejaba todos los pedidos sin
               asesor y sin oficina (auditoría del módulo 3a-1). */
            $k_lin = count($lineas) - 1;
            $lineas[$k_lin]['producto_id']    = $prod ? $prod_id : null;
            $lineas[$k_lin]['fuera_catalogo'] = $prod ? 0 : 1;
            if (columna_existe('pedido_lineas', 'variante_id')) {
                $lineas[$k_lin]['variante_id'] = $prod && $var_id ? $var_id : null;
            }
        }
    }
    if (!$lineas) $errores[] = 'Falta al menos un producto.';

    $subtotal = 0;
    foreach ($lineas as $l) $subtotal += (int)$l['total_centimos'];
    if ($subtotal <= 0) $errores[] = 'El pedido no puede sumar cero.';
    if ($subtotal > DINERO_MAXIMO_CENTIMOS) {
        $errores[] = 'El pedido entero pasa de ' . soles(DINERO_MAXIMO_CENTIMOS)
                   . '. Si es de verdad tan grande, pártelo o avisa a Administración.';
    }

    /* ── Canal, envío y entrega ──────────────────────────────────── */
    /* El comprobante NO se decide aquí: la venta nace sin solicitud, y eso
       significa que no lleva. El asesor lo pide cuando lo sabe —en la ventana
       de «Pedido registrado» o desde el menú ⋮ de la lista—. Ver
       pedido_solicitar_comprobante(). */

    /* ── La garantía ─────────────────────────────────────────────────
       Si el asesor no eligió nada, entra la primera de la lista: es lo que
       venía marcado en la pantalla, y así un formulario viejo o una venta
       creada desde fuera no se queda sin garantía en silencio. Si eligió algo
       que no está en la lista, se dice. */
    /* LA GARANTÍA LA TRAE EL PRODUCTO (módulo 3a-1): los carritos llevan seis
       meses y la silla doce, y hasta ahora había que acordarse en cada venta.
       Solo se hereda cuando el asesor NO eligió otra: `garantia_auto` vale 1
       mientras no toque el campo, y la pantalla lo pone a 0 en cuanto lo hace.
       Sin JavaScript sigue valiendo 1, y entonces manda el producto, que es
       justo lo que se quería. */
    $g_auto = pedir('garantia_auto', 'post', '1') !== '0';
    if ($g_auto && $garantia_catalogo && lista_valida('garantias', $garantia_catalogo, $pais_id)) {
        $d['garantia_item_id'] = $garantia_catalogo;
    }
    if (!$d['garantia_item_id']) {
        $d['garantia_item_id'] = garantia_por_defecto($d['tipo'], $pais_id) ?? 0;
    } elseif (!lista_valida('garantias', $d['garantia_item_id'], $pais_id)) {
        $errores[] = 'Esa garantía no está en la lista.';
    }

    /* Y que la base admita ese tipo de venta. Si no, se dice con todas las
       letras en vez de guardar un pedido con el tipo en blanco. */
    if (!isset(tipos_de_venta_ofrecidos()[$d['tipo']])) {
        $errores[] = 'Ese tipo de venta todavía no está disponible. '
                 . 'Avisa a Administración: falta terminar la actualización.';
    }

    if (!lista_valida('canales', $d['canal_item_id'], $pais_id)) {
        $errores[] = 'Elige el canal de venta. Va en el PEDIDO, no en el cliente: el mismo cliente puede llegar por Facebook una vez y por referido la siguiente.';
    }
    if ($d['tipo_envio_item_id'] && !lista_valida('tipos_envio', $d['tipo_envio_item_id'], $pais_id)) {
        $errores[] = 'Ese tipo de envío no está en la lista.';
    } elseif ($d['tipo_envio_item_id'] && $d['entrega'] === 'envio'
              && !tipo_envio_vale_para($d['tipo_envio_item_id'], $d['destino'])) {
        /* La pantalla ya solo enseña los que aplican, pero el formulario se
           puede tocar: «Envío gratis Lima» en una venta a Cusco saldría gratis
           en un sitio donde el envío nunca lo es.

           En un recojo en oficina no se comprueba: ahí el tipo de envío se
           borra igual, y el select escondido sigue mandando lo que el asesor
           eligió antes de cambiar de rama — le saldría un error sobre un campo
           que ya no ve. */
        $errores[] = 'Ese tipo de envío no aplica a ' . ($d['destino'] === 'lima' ? 'Lima'
                   : ($d['destino'] === 'provincia' ? 'provincia' : 'un recojo en oficina')) . '.';
    }

    /* ── Las tres formas de recibirlo ────────────────────────────────
       Cada rama pide SOLO lo suyo. La regla de quién manda: el DISTRITO. Si el
       asesor marcó «Lima» y eligió un distrito de Cusco, se le dice; no se
       corrige por detrás, porque una de las dos cosas está mal y solo él sabe
       cuál. `ubigeo_es_lima()` sigue siendo la única definición de qué es
       Lima: la pantalla no vota. */
    /* MANDA LO QUE EL ASESOR VE. La rama la decide `destino` —lo marcado en el
       paso 2, que es lo que gobierna la pantalla— y no el distrito. Antes se
       decidía por el distrito, y con el distrito todavía sin elegir el HUB le
       pedía «elige la agencia» a alguien que estaba mirando el formulario de
       Lima, donde no hay ninguna agencia que elegir.

       Que el distrito CUADRE con lo marcado se comprueba aparte, y ahí sí
       manda `ubigeo_es_lima()`: es la única definición de qué es Lima. Si no
       cuadran, se dice cuál de las dos cosas cambiar; no se corrige por
       detrás, porque una está mal y solo el asesor sabe cuál. */
    $es_lima = ($d['destino'] === 'lima');
    /* «Otra» no es un id: es el hueco para una agencia que no está en la lista. */
    $otra_agencia = pedir('agencia_item_id') === 'otra';

    if ($d['entrega'] === 'envio') {
        if (!ubigeo_distrito_valido($d['ubigeo_id'], $pais_id)) {
            $errores[] = 'Elige el distrito de entrega de la lista. Están los 1.893 del Perú: '
                       . 'si hay varios con el mismo nombre, escribe también la provincia.';
        } elseif (ubigeo_es_lima((int)$d['ubigeo_id']) !== $es_lima) {
            $errores[] = $es_lima
                ? 'Ese distrito no es de Lima. Cambia arriba a «Provincia» o elige otro distrito.'
                : 'Ese distrito es de Lima. Cambia arriba a «Lima» o elige otro distrito.';
        }

        if ($es_lima) {
            // Lima: lo llevamos nosotros. Dirección sí, agencia no.
            if ($d['direccion_txt'] === '') $errores[] = 'Falta la dirección de entrega.';
            $d['agencia_item_id'] = null;
            $d['sucursal'] = '';
            $d['recojo_agencia'] = 0;
            $d['agencia_otra'] = '';
        } else {
            /* LA AGENCIA, Y LA QUE NO ESTÁ EN LA LISTA.
               El asesor puede escribir una que falte y la venta no se traba:
               queda en el pedido tal como la escribió y Administración decide
               después si entra al desplegable. Lo pidió el usuario el
               2026-09-11: «que se mande con el pedido pero solo con
               autorización se añadiría a la lista».
               Lo que NO se deja es duplicar una que ya está: si escribe
               «shalom» y en la lista hay «Shalom», acabaríamos con dos
               agencias que son la misma y con un reporte que las cuenta por
               separado. */
            if ($otra_agencia) {
                $d['agencia_item_id'] = null;
                if ($d['agencia_otra'] === '') {
                    $errores[] = 'Escribe el nombre de la agencia.';
                } elseif ($ya = lista_item_por_texto('agencias', $d['agencia_otra'], $pais_id)) {
                    $errores[] = '«' . $ya['valor'] . '» ya está en la lista: elígela arriba '
                               . 'en vez de escribirla.';
                } elseif (mb_strlen($d['agencia_otra']) > 60) {
                    $errores[] = 'El nombre de la agencia es demasiado largo.';
                }
            } else {
                $d['agencia_otra'] = '';
                if (!lista_valida('agencias', $d['agencia_item_id'], $pais_id)) {
                    $errores[] = $d['recojo_agencia']
                        ? 'Elige la agencia donde lo recoge.'
                        : 'Elige la agencia que hace el reparto.';
                }
            }

            if ($d['recojo_agencia']) {
                /* Recoge en la agencia: tres campos, y ninguno es una
                   dirección que nadie va a usar. */
                if ($d['sucursal'] === '') $errores[] = 'Falta la sucursal donde lo recoge.';
                $d['direccion_txt'] = ''; $d['referencia_txt'] = ''; $d['recibe'] = '';
                        } else {
                // La agencia se lo lleva: aquí sí hace falta dirección.
                if ($d['direccion_txt'] === '') $errores[] = 'Falta la dirección del reparto.';
                /* Y NO hay sucursal: la pantalla esconde el campo al cambiar a
                   domicilio, pero un campo escondido sigue viajando. Sin esto,
                   al grupo le llegaba «Agencia: Shalom - Av. Grau 123» y esa
                   dirección se aprendía como sucursal buena de esa agencia
                   (auditoría del 2r). */
                $d['sucursal'] = '';
            }
        }
        /* El enlace del mapa solo sirve donde entregamos nosotros: fuera de
           Lima lo lleva la agencia y ese enlace no lo abre nadie. */
        if (!$es_lima) {
            $d['gps'] = '';
        } elseif ($d['gps'] === '') {
            /* Y en Lima es OBLIGATORIO (usuario, 2026-09-11): reparte Waka, y
               sin el mapa quien lleva el producto anda buscando una dirección
               escrita a mano. Solo cuando hay dirección a la que ir: en un
               recojo en la agencia no hay nada que buscar. */
            $errores[] = 'Falta el enlace de Google Maps. En Lima lo repartimos nosotros y es lo '
                       . 'que abre quien va a entregar: compártelo desde la app de Maps y pégalo aquí.';
        } elseif (!preg_match('#^https?://\S+$#iu', trim($d['gps']))) {
            /* TIENE QUE SER UN ENLACE, no una frase. Un campo obligatorio que
               acepta cualquier cosa se rellena con «sí» o «al costado del
               parque» el primer día, y ahora esto viaja al grupo de despacho:
               «📍 Mapa: sí» es peor que no mandar la línea. */
            $errores[] = 'Eso no es un enlace de Google Maps. Tiene que empezar por https:// — '
                       . 'compártelo desde la app de Maps y pega el enlace entero.';
        } elseif (mb_strlen(trim($d['gps'])) > 255) {
            /* Y tiene que CABER. Antes se recortaba en silencio a 80 y el que
               reparte abría un enlace partido que no lleva a ninguna parte. */
            $errores[] = 'Ese enlace es larguísimo y no cabe entero. Usa el enlace corto que da '
                       . '«Compartir» en la app de Maps.';
        }
    } else {
        /* Recojo en oficina: ni dirección, ni agencia, ni flete, ni «enviar
           armado» —no hay envío que armar— ni tipo de envío. Todo eso vive
           dentro del bloque que la pantalla esconde, y un campo escondido
           SIGUE viajando en el formulario: el asesor marcaba «enviar armado»
           pensando en un envío, cambiaba a recojo, y al grupo de despacho le
           llegaba «ENVIAR ARMADO» debajo de «recoge en la oficina». */
                $d['ubigeo_id'] = null; $d['direccion_txt'] = ''; $d['referencia_txt'] = '';
        $d['gps'] = ''; $d['agencia_item_id'] = null; $d['sucursal'] = '';
        $d['recojo_agencia'] = 0; $d['envio_armado'] = 0;
        $d['tipo_envio_item_id'] = 0; $d['agencia_otra'] = '';
    }

    /* ENVIAR ARMADO: SOLO LIMA Y NUNCA EN LIQUIDACIÓN (usuario, 2026-09-20).
       A provincia el producto viaja en su caja y lo arma la agencia o el
       cliente; en liquidación va como está. La pantalla ya esconde la casilla,
       pero una casilla escondida sigue viajando en el formulario, así que la
       regla se aplica también aquí. (El mapa fuera de Lima ya se limpia
       arriba, en la rama del envío: una sola definición por regla.) */
    if ($d['destino'] !== 'lima' || $d['tipo'] === 'liquidacion') {
        $d['envio_armado'] = 0;
    }

    /* ── La ventana de entrega solo donde entregamos nosotros ────────
       «¿Cuándo lo entregamos?» no aplica fuera de Lima: a provincia el horario
       lo pone la agencia, y prometerle una hora al cliente en nombre de un
       tercero es una promesa que Waka no puede cumplir. Lo pidió el usuario el
       2026-09-10.

       Se mira `destino` y no el distrito a propósito: con el distrito todavía
       sin elegir, mirar el distrito daba «no es Lima» y BORRABA la hora que el
       asesor acababa de escribir. Corregía el distrito, enviaba, y el pedido
       se guardaba sin la ventana de entrega que había acordado con el cliente. */
    if ($d['destino'] !== 'lima') {
        $d['entrega_fecha'] = ''; $d['entrega_hora_desde'] = '';
        $d['entrega_hora_hasta'] = ''; $d['entrega_recepcion'] = 0;
    }

    /* ── Flete y comisión ────────────────────────────────────────────
       El flete NO entra en el total cuando lo paga el cliente en destino:
       ese dinero se lo paga a la agencia, no a Waka, y meterlo dentro
       descuadraría el saldo por cobrar y la devolución de una anulación.
       La comisión de pasarela tampoco: es un costo de Waka, no algo que el
       cliente pague de más. */
    /* Se guarda también lo TECLEADO, no solo los céntimos: la vista repinta
       por el nombre del campo, y al volver el formulario con un error el flete
       y la comisión se vaciaban —el asesor corregía otra cosa, enviaba, y la
       venta se registraba sin el costo del envío. */
    $d['flete']    = pedir('flete');
    $d['comision'] = pedir('comision');

    $flete = a_centimos($d['flete']) ?? 0;
    if (!dinero_razonable($flete)) $flete = 0;

    /* EL FLETE SOLO EXISTE SI LO COBRA WAKA.
       Quien decide que hay costo es el TIPO DE ENVÍO, no la rama
       Lima/provincia — por eso «Envío con costo en Lima» no tenía dónde
       escribir el monto y el HUB lo tiraba en silencio.

       Y a provincia ya no se pregunta nada: ese flete se lo paga el cliente a
       la agencia en destino, no a Waka. No es dinero de la empresa, no entra
       en el total, no genera cashback y no cuadra contra nada. Pedirlo era
       pedirle al asesor un dato que a nadie le sirve. Lo dijo el usuario el
       2026-09-11: «el flete es a destino y lo paga el cliente a la agencia, no
       a nosotros».

       Con eso cae también «¿quién lo paga?»: si el único flete que se escribe
       es el que cobra Waka, siempre va DENTRO del total. */
    $cobra_flete = ($d['entrega'] === 'envio') && tipo_envio_cobra_flete($d['tipo_envio_item_id']);

    if (!$cobra_flete) {
        $flete = 0;
        $d['flete_lo_paga'] = 'cliente_destino';
    } else {
        $d['flete_lo_paga'] = 'incluido';
        /* EN LA PRE VENTA ES OPCIONAL (3j, usuario 2026-09-28): la entrega es
           dentro de semanas y el costo se pone después, desde el pedido o al
           mandarlo a despacho (pedido_flete_falta). */
        if ($flete <= 0 && $d['tipo'] !== 'preventa') {
            $errores[] = 'Ese tipo de envío tiene costo. Escribe cuánto se le cobra al cliente.';
        }
    }
    $d['flete_centimos'] = $flete;

    /* LA COMISIÓN DE PASARELA LA CALCULA EL SERVIDOR CUANDO NADIE LA ESCRIBE.
       Estaba SOLO en el JavaScript de la pantalla: `recargo_de_pago()` existía,
       estaba documentada y probada, y no la llamaba nadie. Con eso, en un
       navegador sin JavaScript —el caso que la propia vista dice defender— cada
       venta con POS se guardaba con comisión 0 y sin que nadie se enterara; y
       el día que el banco baje del 4% habría dos sitios que cambiar y uno se
       quedaría atrás. Ahora la regla vive en un solo sitio: el servidor la
       aplica y el JavaScript solo la ADELANTA en pantalla.
       Sigue siendo EDITABLE: si el asesor escribió algo, manda lo suyo — el
       banco cobra lo que cobra y a veces no es el 4% clavado. */
        /* Y LO MISMO EN EL SERVIDOR. La pantalla esconde el campo con cualquier
       método que no cobre recargo; el servidor no puede fiarse de eso —quien
       manda el formulario a mano manda lo que quiere—, así que aquí también:
       sin recargo configurado, no hay comisión de pasarela que guardar. */
    $cobra_pasarela = metodo_recargo_centesimas(pedir_int('pago_metodo_item_id')) > 0;
    if (!$cobra_pasarela) $d['comision'] = '';
    $comision = a_centimos($d['comision']) ?? 0;
    /* Y si escribió algo que no es dinero, SE DICE. Era el único campo de esta
       pantalla que se descartaba sin avisar: quedaba en 0 y encima bloqueaba
       el cálculo del 4%, así que el POS se guardaba sin su comisión. */
    if (trim((string)$d['comision']) !== '' && a_centimos($d['comision']) === null) {
        $errores[] = 'La comisión de pasarela no se entiende. Escríbela como 15.16, '
                   . 'o déjala vacía y la calculamos.';
    }
    if (trim((string)$d['comision']) === '') {
        $comision = recargo_de_pago(a_centimos(pedir('pago_monto')) ?? 0,
                                    pedir_int('pago_metodo_item_id'));
        if ($comision > 0) $d['comision'] = number_format($comision / 100, 2, '.', '');
    }
    $d['comision_centimos'] = dinero_razonable($comision) ? $comision : 0;

    /* ── La fecha ────────────────────────────────────────────────── */
    if (!fecha_valida($d['fecha'])) {   // checkdate(): «2026-02-30» no existe
        $errores[] = 'La fecha del pedido no se entiende.';
    } elseif ($d['fecha'] > date('Y-m-d')) {
        $errores[] = 'La fecha del pedido no puede ser futura.';
    } elseif ($d['fecha'] < date('Y-m-d', strtotime('-365 days'))) {
        /* Y tampoco muy atrás. Antes este freno era de 30 días y solo se le
           aplicaba a quien NO confirma dinero — es decir, desde que el asesor
           ya no elige la fecha (usuario, 2026-09-11), a nadie: la rama entera
           había quedado muerta y el HUB aceptaba en silencio una venta de
           2019. Ahora el freno es de un año y vale para todos: no es una
           política, es el año mal escrito. Una fecha vieja no mueve la meta
           —esa la mandan los pagos— pero sí falsea «pedidos de hoy», el
           filtro del día, la última compra del cliente y el reporte de
           ventas por periodo. */
        $errores[] = 'Esa fecha tiene más de 365 días. Revisa el año: '
                   . 'una venta más antigua se registra desde la base, no aquí.';
    }

    /* ── Cashback ────────────────────────────────────────────────────
       El tope es la TERCERA PARTE del total ANTES del descuento, y se calcula
       aquí en el servidor: lo que venga del formulario es solo una intención. */
    $cb_pedido = a_centimos(pedir('cashback')) ?? 0;
    $cb_aplicar = 0;
    /* En liquidación no se usa cashback y tampoco se gana (usuario,
       2026-09-10): el precio ya ES el descuento. La pantalla esconde el campo,
       pero un campo escondido sigue viajando, así que la regla se comprueba
       aquí — y en vez de descontarlo callando o de tragárselo, se dice. */
    if ($cli && $cb_pedido > 0 && !venta_da_cashback($d['tipo'])) {
        $errores[] = 'En una venta de liquidación no se puede usar cashback: el precio ya es el descuento.';
    } elseif ($cli && $cb_pedido > 0) {
        $cb_aplicar = min($cb_pedido, cashback_aplicable((int)$cli['id'], $subtotal));
        if ($cb_aplicar <= 0) {
            $errores[] = 'Ese cliente no tiene cashback usable en este pedido. Se usa desde '
                       . soles(cashback_minimo()) . ' y como máximo ' . soles(cashback_tope_del_pedido($subtotal))
                       . ' en un pedido de ' . soles($subtotal) . '.';
        }
    }

    /* ── El descuento del asesor (3d) ─────────────────────────────
       En soles, sobre el total, con motivo. Hasta el tope sale sola; por
       encima queda esperando a Administración. La regla vive en
       descuentos.php: aquí solo se lee y se pregunta. */
    $desc_txt    = trim(pedir('descuento'));
    $desc_motivo = trim(mb_substr(pedir('descuento_motivo'), 0, DESCUENTO_MOTIVO_MAX));
    $desc_aplicar = $desc_txt === '' ? 0 : (a_centimos($desc_txt) ?? -1);
    $mal_desc = descuento_problema($desc_aplicar, $desc_motivo, $subtotal, $cb_aplicar, $d['tipo']);
    if ($mal_desc !== '') { $errores[] = $mal_desc; $desc_aplicar = 0; }
    $desc_estado = descuento_estado_inicial($desc_aplicar, $subtotal, $u);

    /* ── El primer pago, si lo hay ───────────────────────────────── */
    $pago_monto  = a_centimos(pedir('pago_monto')) ?? 0;
    $pago_metodo = pedir_int('pago_metodo_item_id');
    $pago_fecha  = pedir('pago_fecha') ?: $d['fecha'];
    $voucher     = null;

    /* La comisión de pasarela es de un PAGO: sin pago no hay pasarela que
       cobre nada. Vive dentro del bloque que se esconde al marcar «todavía no
       paga», y un campo escondido sigue viajando: el asesor tecleaba la
       comisión de Izipay, el cliente se echaba atrás, cambiaba a «todavía no
       paga» y el pedido quedaba con un costo que nadie pagó. */
    if ($pago_monto <= 0) $d['comision_centimos'] = 0;

    /* SIN PAGO NO HAY PEDIDO. Lo decidió el usuario el 2026-09-11: «no pueden
       generar pedidos sin pagos». Cualquier monto mayor que cero vale — el HUB
       no juzga cuánto adelantó el cliente, solo que la venta traiga dinero.
       Antes había una tercera opción, «Todavía no paga», que además era la que
       venía marcada por defecto. */
    if ($pago_monto <= 0) {
        $errores[] = 'Falta el primer pago. Un pedido no se registra sin dinero: '
                   . 'escribe cuánto pagó, aunque sea un adelanto.';
    }

    /* El archivo se guarda ANTES de comprobar nada más, y FUERA del «si hay
       monto». Si se guardara solo cuando hay monto, el caso más probable del
       pago obligatorio —adjunta la foto y se olvida de escribir cuánto— se
       llevaría el archivo por delante sin decirlo, y el asesor creería que lo
       mandó. Es el mismo agujero que ya se tapó una vez, reabierto por la
       regla nueva. Ver voucher_del_formulario(). */
    $rv = voucher_del_formulario($ctx_voucher);
    if (!$rv['ok']) $errores[] = $rv['error'];
    else $voucher = $rv['archivo'];
    /* LA FOTO DEL DNI (3f), con el mismo cuidado: se guarda antes de nada. */
    $foto_dni = null;
    $rd = voucher_del_formulario($ctx_voucher, 'foto_dni');
    if (!$rd['ok']) $errores[] = str_contains($rd['error'], 'DNI') ? $rd['error'] : 'Foto del DNI: ' . $rd['error'];
    else $foto_dni = $rd['archivo'];

    if ($pago_monto > 0) {
        if (!lista_valida('metodos_pago', $pago_metodo, $pais_id)) {
            $errores[] = 'Elige el método del pago.';
        }
        $total_previsto = $subtotal - $cb_aplicar - $desc_aplicar
                        + pedido_flete_dentro(['flete_lo_paga'  => $d['flete_lo_paga'],
                                               'flete_centimos' => $flete]);

        /* A PROVINCIA SE PAGA TODO ANTES DE QUE SALGA (usuario, 2026-09-20).
           No hay contraentrega: la caja viaja en una agencia y nadie cobra al
           entregar, así que una venta a provincia con saldo pendiente es una
           venta que se queda parada o un cobro que se pierde.
           La pre venta es otra cosa y se queda fuera: ahí el adelanto es el
           trato, y el saldo se cobra cuando llega el contenedor. */
        if ($d['destino'] === 'provincia' && $d['tipo'] !== 'preventa'
            && $pago_monto < $total_previsto) {
            $errores[] = 'A provincia se cobra el total antes de despachar: no hay pago contra '
                       . 'entrega. Faltan ' . soles($total_previsto - $pago_monto) . '.';
        }
        if ($pago_monto > $total_previsto) {
            $errores[] = 'El pago (' . soles($pago_monto) . ') es mayor que el total del pedido ('
                       . soles($total_previsto) . ').';
        } elseif (!dinero_razonable($pago_monto)) {
            /* El subtotal y el flete están topados cada uno, pero su SUMA no,
               así que un pago igual al total podía pasar de DINERO_MAXIMO y
               reventar en pago_registrar() con el pedido ya guardado. Es la
               última rendija por la que quedaba una venta a cero. */
            $errores[] = 'Ese pago no puede ser real: el máximo es '
                       . soles(DINERO_MAXIMO_CENTIMOS) . '.';
        }
        /* LA FECHA DEL PAGO SE COMPRUEBA AQUÍ, ANTES DE GUARDAR NADA. El pedido
           se guarda primero y el pago se anota después, así que una fecha que
           pago_registrar() rechace deja una venta a cero — y no se puede
           arreglar desde ninguna pantalla: la ficha aplica la misma regla y la
           fecha del pedido ya no se edita. Es la MISMA función que usa
           pago_registrar(): una sola definición de las cuatro reglas. */
        $mal_fecha_pago = pago_fecha_problema($pago_fecha, $d['fecha']);
        if ($mal_fecha_pago !== '') $errores[] = $mal_fecha_pago;

        if (metodo_pide_comprobante($pago_metodo) && !$voucher) {
            /* La MISMA frase que devuelve pago_registrar(): el asesor veía
               «Ese» o «Este» según por dónde reventara. */
            $errores[] = 'Este método de pago necesita la foto del voucher.';
        }
        /* La MISMA frase que devuelve pago_registrar(). */
        if (metodo_pide_dni($pago_metodo) && !$foto_dni) $errores[] = metodo_pide_dni_texto();
    }

    /* La ventana de entrega se comprueba ANTES de guardar, con la misma
       función que la guarda: si la hora está mal escrita, se dice y se devuelve
       el formulario lleno. Guardarla en silencio y dejarla caer sería peor —el
       asesor creería que despacho tiene la hora, y despacho no la tendría. */
    $r_ent = pedido_entrega_validar([
        'fecha'      => $d['entrega_fecha'],
        'hora_desde' => $d['entrega_hora_desde'],
        'hora_hasta' => $d['entrega_hora_hasta'],
    ]);
    if (!$r_ent['ok']) $errores[] = $r_ent['error'];

    /* ── Guardar ─────────────────────────────────────────────────── */
    if (!$errores && $cli) {
        $estado = estado_por_clave(estado_inicial($d['tipo']));
        if (!$estado) {
            $errores[] = 'No se pudo guardar el pedido: falta terminar la actualización. Avisa a Administración.';
        } else {
            /* EL STOCK DE LA WEB (3e), lo último antes de guardar: con todo lo
               demás ya validado, para no descontar una venta que luego se
               rechaza por otra cosa. Sin stock, no se vende. */
            $prep_stock = stock_venta_preparar($d['tipo'], (int)$pais_id, $lineas);
            if (!$prep_stock['ok']) {
                $errores[] = $prep_stock['error'];
            } else {
                try {
                $nuevo_id = en_transaccion(function () use ($d, $cli, $u, $lineas, $estado, $pais_id, $cb_aplicar, $hay_garantia,
                                                            $desc_aplicar, $desc_motivo, $desc_estado, $prep_stock) {

                    $id = insertar('pedidos', [
                        'codigo'      => 'tmp-' . bin2hex(random_bytes(6)),
                        'pais_id'     => $pais_id,
                        'oficina_id'  => $u['oficina_id'] ?: null,
                        'cliente_id'  => (int)$cli['id'],
                        'asesor_id'   => (int)$d['asesor_id'],
                        // Sello histórico del equipo que tenía el asesor al vender.
                        // Los rankings usan el equipo de HOY; esta columna es el rastro.
                        'equipo_id'   => valor('SELECT equipo_id FROM usuarios WHERE id = ?', [$d['asesor_id']]) ?: null,
                        'tipo'        => $d['tipo'],
                        'estado_id'   => (int)$estado['id'],
                        'fecha'       => $d['fecha'],
                        'canal_item_id'      => $d['canal_item_id'],
                        'tipo_envio_item_id' => $d['tipo_envio_item_id'] ?: null,
                        'entrega'     => $d['entrega'],
                        'agencia_item_id' => $d['agencia_item_id'] ?: null,
                        'sucursal'    => mb_substr($d['sucursal'], 0, 120),
                        'agencia_otra'=> $d['agencia_otra'] !== ''
                                         ? mb_substr($d['agencia_otra'], 0, 60) : null,
                        /* La guía nace vacía. Antes se leía un campo `guia` del
                           formulario que NUNCA EXISTIÓ: se pedía, se recortaba a
                           60 caracteres y se guardaba siempre en blanco. Hoy nadie
                           la escribe todavía —la ficha ya tiene su sitio para
                           pintarla— y le toca a la pantalla de despacho cuando el
                           pedido salga de verdad, en la v2 del flujo. */
                        'guia'        => null,
                        'recojo_agencia' => (int)$d['recojo_agencia'],
                        'ubigeo_id'   => $d['ubigeo_id'] ?: null,
                        'direccion_txt' => mb_substr($d['direccion_txt'], 0, 220),
                        'referencia_txt'=> mb_substr($d['referencia_txt'], 0, 200),
                        'gps'         => mb_substr(trim($d['gps']), 0, 255),
                        'recibe'      => mb_substr($d['recibe'], 0, 120),
                        'envio_armado'=> $d['envio_armado'],
                        'flete_centimos'   => $d['flete_centimos'],
                        'flete_lo_paga'    => $d['flete_lo_paga'],
                        'comision_centimos'=> $d['comision_centimos'],
                        'nota'        => mb_substr($d['nota'], 0, 300),
                        'registrado_por' => (int)$u['id'],
                    ]);

                    // El código sale del id, que ya es único por la base. Un contador
                    // aparte se desincroniza en cuanto dos asesores registran a la vez.
                    $sello = ['codigo' => pedido_codigo_de_id($id)];
                    /* La garantía va aquí y SOLO si la columna existe. Metida en el
                       INSERT de arriba, un HUB al que le han subido el ZIP y
                       todavía no le han pasado actualizar.php reventaba en CADA
                       pedido nuevo con «Unknown column» —y la defensa escrita para
                       ese caso, el campo que no se pinta y el null, no defendía
                       nada, porque la columna viajaba igual—. Se aprovecha el
                       UPDATE del código en vez de hacer otro. */
                    if ($hay_garantia) $sello['garantia_item_id'] = $d['garantia_item_id'] ?: null;
                    actualizar('pedidos', $id, $sello);

                    /* La ventana de entrega la guarda la MISMA función que usa la
                       pantalla de despacho para corregirla: una sola definición de
                       qué hora es válida y de qué pasa con un rango al revés. */
                    pedido_entrega_guardar($id, [
                        'fecha'      => $d['entrega_fecha'],
                        'hora_desde' => $d['entrega_hora_desde'],
                        'hora_hasta' => $d['entrega_hora_hasta'],
                        'recepcion'  => $d['entrega_recepcion'],
                    ]);

                    /* EL CORTE DURO DE LA PRE VENTA (3h), con las filas del lote
                       bloqueadas: si otro asesor se llevó las últimas, no se guarda. */
                    preventa_asegurar($lineas);
                    foreach ($lineas as $l) {
                        insertar('pedido_lineas', array_merge($l, ['pedido_id' => $id]));
                    }
                    /* LOS REPUESTOS QUE NO ESTÁN EN LA WEB salen del almacén del
                       HUB (3i), con la fila bloqueada: sin stock, no se vende. */
                    if (repuestos_listo()) stock_hub_venta($id, $lineas, $d['tipo'], (int)$pais_id);

                    /* LA SUCURSAL QUEDA APUNTADA para la próxima venta (usuario,
                       2026-09-20): la lista de sucursales de cada agencia no existe
                       en ninguna parte, así que se aprende de lo que se escribe. No
                       valida ni rechaza nada — la venta ya está hecha. */
                    if ($d['destino'] === 'provincia' && trim((string)$d['sucursal']) !== '') {
                        sucursal_recordar($pais_id, $d['agencia_item_id'] ? (int)$d['agencia_item_id'] : null,
                                          $d['ubigeo_id'] ? (int)$d['ubigeo_id'] : null,
                                          (string)$d['sucursal'], (int)$u['id']);
                    }

                    if ($cb_aplicar > 0) {
                        $usado = cashback_usar((int)$cli['id'], $id, $cb_aplicar);
                        actualizar('pedidos', $id, ['cashback_usado_centimos' => $usado]);
                    }

                    /* El descuento resta del total DESDE YA, también si espera
                       aprobación: el cliente paga el precio que le dijeron. Si se
                       rechaza, se quita y el total sube (descuento_resolver()). */
                    if ($desc_aplicar > 0) {
                        actualizar('pedidos', $id, [
                            'descuento_centimos'        => $desc_aplicar,
                            'descuento_pedido_centimos' => $desc_aplicar,
                            'descuento_motivo'          => $desc_motivo,
                            'descuento_estado'          => $desc_estado,
                            'descuento_por'             => (int)$u['id'],
                        ]);
                    }

                    pedido_recalcular($id);
                    /* Nace con el estado que le toca (3j): una pre venta de un
                       lote que ya viaja nace «En camino». */
                    pedido_estado_auto($id, '', false);
                    /* Lo descontado de la web (o por descontar), con el número del
                       pedido. Si algo de esta transacción falla, se devuelve abajo. */
                    stock_venta_apuntar($id, $prep_stock);

                    // La dirección se guarda también en la ficha del cliente para
                    // precargarla la próxima vez. La del pedido es la que manda.
                    if ($d['entrega'] === 'envio' && $d['direccion_txt'] !== '') {
                        $ya = valor('SELECT id FROM cliente_direcciones
                                      WHERE cliente_id = ? AND direccion = ?',
                                    [(int)$cli['id'], $d['direccion_txt']]);
                        if (!$ya) {
                            $ubi = ubigeo_de($d['ubigeo_id'] ?: null);
                            insertar('cliente_direcciones', [
                                'cliente_id' => (int)$cli['id'],
                                'direccion'  => mb_substr($d['direccion_txt'], 0, 220),
                                'referencia' => mb_substr($d['referencia_txt'], 0, 200),
                                'ubigeo_id'  => $d['ubigeo_id'] ?: null,
                                'distrito'   => (string)($ubi['distrito'] ?? ''),
                                'provincia'  => (string)($ubi['provincia'] ?? ''),
                                'departamento' => (string)($ubi['departamento'] ?? ''),
                                'gps'        => mb_substr(trim($d['gps']), 0, 255),
                                'recibe'     => mb_substr($d['recibe'], 0, 120),
                            ]);
                        }
                    }
                    return $id;
                });
                } catch (PreventaAgotada | StockHubAgotado $ex) {
                    /* Se agotó mientras llenaba el formulario: se dice y no se guarda. */
                    stock_venta_deshacer($prep_stock, 'La venta no llegó a guardarse');
                    $errores[] = $ex->getMessage();
                    $nuevo_id = 0;
                } catch (Throwable $ex) {
                    /* La venta no se guardó, pero la web ya se descontó (o puede
                       que sí): se devuelve. */
                    stock_venta_deshacer($prep_stock, 'La venta no llegó a guardarse');
                    throw $ex;
                }
                if ($nuevo_id) {

                pedido_evento($nuevo_id, 'alta', 'Pedido registrado por '
                    . trim((string)$u['nombre'] . ' ' . (string)$u['apellidos']));
                bitacora('pedido.crear', 'pedido', $nuevo_id,
                         ['tipo' => $d['tipo'], 'cliente' => (int)$cli['id'], 'total' => $subtotal]);
                if ($desc_aplicar > 0) {
                    pedido_evento($nuevo_id, 'descuento', 'Descuento de ' . soles($desc_aplicar)
                        . ' (' . descuento_pct_texto($desc_aplicar, $subtotal) . ') · ' . $desc_motivo
                        . ($desc_estado === 'pendiente' ? ' · espera que Administración lo apruebe' : ''));
                    /* La línea de la bitácora es la que hace sonar el aviso de
                       Administración (descuentos_pedidos_desde): solo cuando espera. */
                    bitacora($desc_estado === 'pendiente' ? 'pedido.descuento_pide' : 'pedido.descuento',
                             'pedido', $nuevo_id, ['monto' => $desc_aplicar, 'motivo' => $desc_motivo]);
                }

                $pago_ok = true;
                if ($pago_monto > 0) {
                    $r = pago_registrar($nuevo_id, [
                        'monto_centimos' => $pago_monto,
                        'metodo_item_id' => $pago_metodo,
                        'fecha'          => $pago_fecha,
                        // El N.º de operación no lo escribe el asesor: lo copia del
                        // extracto quien valida el pago, que es cuando lo tiene
                        // delante y es para lo único que sirve.
                        'voucher'        => $voucher,
                        'foto_dni'       => metodo_pide_dni($pago_metodo) ? $foto_dni : null,
                    ]);
                    $pago_ok = (bool)$r['ok'];
                    if (!$r['ok']) {
                        avisar('error', 'El pedido se guardó, pero el pago no: ' . $r['error']
                             . ' Regístralo desde la ficha'
                             . ($voucher ? ' y vuelve a adjuntar el voucher' . ($foto_dni ? ' y la foto del DNI.' : '.') : '.'));
                    } elseif (!empty($r['pendiente'])) {
                        avisar('ok', 'Pedido registrado. El pago queda PENDIENTE de validación: '
                             . 'todavía no suma a tu meta ni genera cashback.');
                    } else {
                        avisar('ok', 'Pedido registrado y pago de ' . soles($pago_monto) . ' anotado.'
                             . ($r['cashback'] > 0 ? ' El cliente ganó ' . soles($r['cashback']) . ' de Cashback Waka.' : ''));
                    }
                } else {
                    avisar('ok', 'Pedido registrado.');
                }
                if ($desc_estado === 'pendiente') {
                    avisar('info', 'El descuento de ' . soles($desc_aplicar) . ' pasa del tope ('
                         . descuento_tope_pct() . ' %): espera que Administración lo apruebe. '
                         . 'Ya les avisamos. Hasta entonces la venta no sale a despacho.');
                }
                /* El pago ya tiene el voucher: deja de estar en espera, para que el
                   siguiente pedido no lo herede. */
                /* La tienda no respondió: la venta está hecha y el stock se
                   descuenta solo en cuanto la tienda vuelva. */
                if ($prep_stock['aviso'] !== '') avisar('info', $prep_stock['aviso']);
                if ($voucher && $pago_ok) voucher_pendiente_confirmar($ctx_voucher);
                else                      voucher_pendiente_olvidar($ctx_voucher);
                /* La foto del DNI: si se usó, ya es del pago; si no (el método
                   no la pedía), se tira. */
                $ctx_dni = voucher_contexto($ctx_voucher, 'foto_dni');
                if ($foto_dni && $pago_ok && metodo_pide_dni($pago_metodo)) voucher_pendiente_confirmar($ctx_dni);
                else                                                       voucher_pendiente_olvidar($ctx_dni);
                /* LA RACHA (5a): se celebra encima de «Pedido registrado», sin
                   reemplazarlo. Y si toca, avisa al equipo. Nunca tumba la venta. */
                try {
                    $rv = racha_tras_venta((int)$d['asesor_id']);
                    if ((int)$d['asesor_id'] === (int)$u['id'] && $rv['n'] >= 2) $_SESSION['racha_venta'] = ['pedido' => $nuevo_id] + $rv;
                } catch (Throwable $ex) { error_log('[HUB] racha: ' . $ex->getMessage()); }
                ir('/pedidos/ficha?id=' . $nuevo_id . '&nuevo=1');
                }
            }
        }
    }
}

/* La última dirección que usó, para precargarla. */
$ultima_dir = $cliente
    ? una('SELECT * FROM cliente_direcciones WHERE cliente_id = ? AND activo = 1
             ORDER BY id DESC LIMIT 1', [(int)$cliente['id']])
    : null;

/* La garantía que viene marcada se calcula AQUÍ, con el país, y no en la
   vista: allí se llamaba sin país y con dos países devolvería la unión de los
   dos, así que la opción marcada podía ser un item que ni siquiera está en el
   desplegable. */
$garantia_ini = garantia_por_defecto((string)($d['tipo'] ?? 'inmediata'), $pais_id);

pagina('pedidos/nuevo', [
    'd' => $d, 'errores' => $errores, 'cliente' => $cliente, 'ultima_dir' => $ultima_dir,
    'metodos' => $metodos, 'agencias' => $agencias, 'canales' => $canales,
    'tipos_envio' => $tipos_envio, 'asesores' => $asesores, 'garantias' => $garantias,
    'garantia_ini' => $garantia_ini, 'puede_fechar' => $puede_fechar,
    'f_id' => $f_id, 'ctx_voucher' => $ctx_voucher,
    'cashback_cliente' => $cliente ? cashback_saldo((int)$cliente['id']) : 0,
    'desc_tope_pct'    => descuento_tope_pct(),
    'desc_aprueba'     => puede_aprobar_descuentos($u),
    'desc_pide'        => descuento_pide_aprobacion(),
    'desc_con_cb'      => descuento_con_cashback(),
    'desc_listo'       => descuentos_disponibles(),
], ['titulo' => 'Nuevo pedido', 'migaja' => 'Pedidos']);
