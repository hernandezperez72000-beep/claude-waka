<?php
declare(strict_types=1);

/**
 * BORRÓN Y CUENTA NUEVA — dejar el HUB sin datos de prueba.
 *
 * Existe porque el HUB entero está construido sobre la regla contraria:
 * «apagar en vez de borrar». Ningún registro de negocio desaparece nunca, y
 * anular no es borrar —anular escribe filas NEGATIVAS con la fecha de hoy, así
 * que anular las ventas de prueba dejaría la meta del mes en negativo y el
 * podio al revés—. Para estrenar el HUB hace falta otra cosa: vaciar de verdad.
 *
 * Se usa UNA vez, el día antes de la primera venta real, desde `limpiar.php`,
 * que se sube, se abre y se borra —igual que instalar.php y actualizar.php—.
 * Esa es la protección de verdad: si el archivo no está en el servidor, esto no
 * se puede ejecutar ni por accidente ni a propósito.
 *
 * LO QUE SE VA: el movimiento (ventas, clientes, dinero, cashback, avisos), y
 * con él los archivos que colgaban de esas filas — los vouchers y las boletas
 * y facturas subidas.
 * LO QUE SE QUEDA: quién es quién y cómo está configurado el HUB — usuarios,
 * roles, permisos, equipos, oficinas, países, metas, ajustes, listas, estados,
 * plantillas, frases, bonos, las fotos de perfil y el ubigeo. No hay que volver
 * a dar de alta a los 19 asesores.
 */

/**
 * Las tablas que se vacían, EN ORDEN: primero los hijos, luego los padres.
 * El orden no es decorativo — hay 31 claves foráneas declaradas, y borrar un
 * cliente antes que sus pedidos falla y deja el trabajo a medias.
 */
function limpieza_tablas(bool $incluir_catalogo = false): array
{
    $movimiento = [
        // Dinero y su rastro (antes que los pedidos que cuelgan de él)
        'cashback_movimientos',
        'pagos',
        // El pedido y sus hijos
        /* Lo que las ventas movieron en la web (3e). Borrarlo NO devuelve
           nada a la web: si se vendió de prueba con el control encendido,
           el stock se corrige allá. */
        'stock_web_cola',
        /* Las garantías (3i) cuelgan del pedido, y lo que movieron en el
           almacén del HUB con ellas. Borrarlo NO devuelve unidades: el conteo
           del almacén se corrige desde la ficha del repuesto. */
        'garantia_fotos',
        'garantia_lineas',
        'garantias',
        'stock_hub_mov',
        'pedido_eventos',
        'pedido_lineas',
        'pedidos',
        // El cliente y los suyos
        'cliente_notas',
        'cliente_direcciones',
        'cola_tienda',
        'clientes',
                /* Lo aprendido durante las pruebas: las sucursales que se apuntaron
           solas al registrar ventas de mentira, con sus erratas y su contador.
           Es dato de movimiento, no configuración. */
        'agencia_sucursales',
        // Lo que se calcula a partir de lo anterior y quedaría mintiendo
        'rachas',
        'bono_resultados',
        /* 5a: los cierres de bonos, las cacerías lanzadas, las novedades ya
           vistas y los avisos de racha: todo sale de las ventas de prueba. */
        'bono_cierres',
        'caceria_dias',
        'novedades_vistas',
        'racha_avisos',
        'visitas',
        // Rastro de la etapa de pruebas
        'bitacora',
        'reportes_error',
        'solicitudes_password',
        'intentos_acceso',
    ];

    /* El catálogo va aparte y solo si se pide: si ya cargaste lotes o productos
       de verdad, borrarlos sería la sorpresa más cara de todas. */
    $catalogo = [
        'stock_movimientos',
        'stock',
        'stock_hub',
        'precios',
        'lote_lineas',
        'variantes',
        'productos',
        'lotes',
        // Lo leído de la tienda: sin productos, no hay nada que comparar.
        'tienda_lecturas',
        'stock_web',
    ];

    return $incluir_catalogo ? array_merge($movimiento, $catalogo) : $movimiento;
}

/** Cuántas filas hay hoy en cada tabla que se va a vaciar. Se enseña ANTES. */
function limpieza_conteos(bool $incluir_catalogo = false): array
{
    $out = [];
    foreach (limpieza_tablas($incluir_catalogo) as $t) {
        if (!tabla_existe($t)) continue;
        $out[$t] = (int) valor("SELECT COUNT(*) FROM $t");
    }
    return $out;
}

/**
 * Vacía las tablas de movimiento y devuelve qué se borró.
 *
 * Va en UNA transacción: un borrado a medias —clientes sin pedidos, pagos sin
 * pedido— es peor que no haber empezado, porque deja el HUB en un estado que
 * ninguna pantalla sabe pintar.
 */
function limpieza_ejecutar(bool $incluir_catalogo = false): array
{
    $tablas  = limpieza_tablas($incluir_catalogo);
    $borrado = [];

    en_transaccion(function () use ($tablas, &$borrado) {
        foreach ($tablas as $t) {
            if (!tabla_existe($t)) continue;
            $n = (int) valor("SELECT COUNT(*) FROM $t");
            q("DELETE FROM $t");
            if ($n > 0) $borrado[$t] = $n;
        }
        return true;
    });

    /* Los contadores vuelven a 1, para que la primera venta de verdad sea
       P-00001 y no P-00238. El código del pedido sale del id, así que sin esto
       el HUB arrancaría con la numeración de las pruebas a cuestas.
       Va FUERA de la transacción: en MySQL un ALTER la cierra sola. */
    limpieza_reiniciar_contadores($tablas);

    return $borrado;
}

function limpieza_reiniciar_contadores(array $tablas): void
{
    foreach ($tablas as $t) {
        if (!tabla_existe($t)) continue;
        try {
            if (bd_es_sqlite()) {
                // En SQLite el contador vive en una tabla aparte que puede no existir.
                q("DELETE FROM sqlite_sequence WHERE name = ?", [$t]);
            } else {
                bd()->exec("ALTER TABLE $t AUTO_INCREMENT = 1");
            }
        } catch (Throwable $e) {
            /* Que no se pueda reiniciar un contador no invalida el borrado: la
               numeración arrancaría más alta y nada más. No se tumba por esto. */
        }
    }
}

/**
 * Borra los archivos que quedaron colgados en el disco.
 *
 * Vaciar la tabla `pagos` deja los vouchers de prueba en uploads/vouchers/ para
 * siempre: nadie los ve —esa carpeta está cerrada por .htaccess— pero son fotos
 * con nombres y bancos dentro, ocupando sitio y sin dueño que las reclame.
 *
 * Y LO MISMO CON LAS BOLETAS Y FACTURAS de uploads/comprobantes/. Faltaban, y
 * son peores: una boleta lleva el nombre, el documento y la dirección del
 * cliente y el detalle de lo que compró. Al vaciar `pedidos` se pierde la única
 * referencia que las nombraba, así que se quedaban en el disco para siempre sin
 * que ninguna pantalla pudiera enseñarlas ni borrarlas (lo preguntó el usuario,
 * 2026-09-14).
 *
 * Las FOTOS DE PERFIL (uploads/fotos/) no se tocan: son de los usuarios, y los
 * usuarios se quedan.
 *
 * Devuelve cuántos archivos se fueron, en total.
 */
function limpieza_borrar_vouchers(): int
{
    $n = 0;
    foreach (['vouchers', 'comprobantes'] as $carpeta) {
        $n += limpieza_vaciar_carpeta(HUB_RAIZ . '/uploads/' . $carpeta);
    }
    return $n;
}

/**
 * Las miniaturas del catálogo (uploads/productos/). Solo cuando el borrón se
 * lleva también el catálogo: sin productos no las nombra nadie.
 */
function limpieza_borrar_fotos_catalogo(): int
{
    return limpieza_vaciar_carpeta(tienda_fotos_dir());
}

/** Vacía una carpeta de subidas: solo archivos sueltos, nunca carpetas. */
function limpieza_vaciar_carpeta(string $dir): int
{
    if (!is_dir($dir)) return 0;

    $n = 0;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === '.gitkeep') continue;
        $ruta = $dir . '/' . $f;
        /* Solo archivos sueltos de esa carpeta, nunca carpetas ni enlaces: es
           un borrado, y un borrado que sigue un enlace simbólico sale de donde
           lo mandaron. */
        if (is_file($ruta) && !is_link($ruta) && @unlink($ruta)) $n++;
    }
    return $n;
}

/** Lo que NO se toca, para poder enseñarlo antes de que nadie apriete nada. */
function limpieza_se_conserva(): array
{
    return [
        'usuarios'   => 'Las cuentas, con sus contraseñas, roles, ámbitos y países',
        'equipos'    => 'Los equipos y sus líderes',
        'oficinas'   => 'Oficinas y países',
        'metas'      => 'Las metas mensuales ya fijadas',
        'ajustes'    => 'Toda la configuración del HUB',
        'listas'     => 'Métodos de pago, agencias, canales y tipos de envío',
        'plantillas' => 'Las plantillas de WhatsApp',
        'bonos'      => 'Los bonos configurados (sus resultados de prueba sí se van)',
        'ubigeo'     => 'Departamentos, provincias y distritos',
        'estados'    => 'Los estados del pedido',
    ];
}
