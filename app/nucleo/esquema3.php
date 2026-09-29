<?php
declare(strict_types=1);

/**
 * Módulo 2 — clientes y pedidos.
 *
 * Aquí van las tablas NUEVAS y las columnas que se le añaden a tablas que ya
 * existen. Las columnas están definidas UNA sola vez, en migraciones_modulo2(),
 * y esa misma función la llaman el instalador y actualizar.php: si estuvieran
 * escritas en dos sitios, una instalación nueva y una actualizada acabarían
 * con tablas distintas y nadie se daría cuenta hasta que fallara una consulta.
 *
 * Reglas que están metidas en el propio esquema:
 *  · pais_id AÍSLA, oficina_id NO. `sede_id` no existe.
 *  · El dinero es BIGINT de céntimos. Nunca DECIMAL ni FLOAT.
 *  · Un pago validado NO se borra ni se despinta: se revierte con otra fila
 *    negativa fechada el día de la reversión (pagos.tipo = 'devolucion').
 */

function esquema_sql_modulo2(): array
{
    $m = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $t = [];

    /* ─────────────  UBIGEO: departamento › provincia › distrito  ─────────────
       Los reportes por zona agrupan por distrito. Con texto libre, "S.J.L.",
       "SJL" y "San Juan de Lurigancho" serían tres zonas distintas y el reporte
       de 25 zonas nunca cuadraría. Por eso es una tabla y el campo es un
       desplegable con buscador, no un input. */

    $t[] = "CREATE TABLE IF NOT EXISTS ubigeo (
        id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id  SMALLINT UNSIGNED NOT NULL,
        tipo     ENUM('departamento','provincia','distrito') NOT NULL,
        padre_id INT UNSIGNED NULL,
        nombre   VARCHAR(90) NOT NULL,
        busca    VARCHAR(90) NOT NULL COMMENT 'el nombre sin tildes y en minúsculas',
        capital        VARCHAR(90) NULL COMMENT 'la capital del distrito, si se llama distinto',
        busca_capital  VARCHAR(90) NULL COMMENT 'la capital sin tildes: la gente busca por ahí',
        activo   TINYINT(1) NOT NULL DEFAULT 1,
        creado_por INT UNSIGNED NULL COMMENT 'quién lo añadió, si lo añadió alguien',
        PRIMARY KEY (id),
        UNIQUE KEY uq_ubigeo (pais_id, tipo, padre_id, nombre),
        KEY ix_ubigeo_padre (padre_id, activo),
        KEY ix_ubigeo_busca (busca)
    ) $m";

    /* ─────────────  COLA DE ENLACE CON LA TIENDA  ─────────────
       La cuenta del cliente en compraenwaka la crea el plugin de WordPress,
       que todavía no existe. Sin cola, el día que exista no habría forma de
       saber a quién le falta cuenta salvo cruzando tablas a mano. */

    $t[] = "CREATE TABLE IF NOT EXISTS cola_tienda (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cliente_id  INT UNSIGNED NOT NULL,
        accion      ENUM('crear_cuenta','enlazar','cupon') NOT NULL DEFAULT 'crear_cuenta',
        estado      ENUM('pendiente','enviado','hecho','error') NOT NULL DEFAULT 'pendiente',
        intentos    SMALLINT NOT NULL DEFAULT 0,
        ultimo_error VARCHAR(300) NULL,
        creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        procesado_en DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cola (cliente_id, accion),
        KEY ix_cola_estado (estado, creado_en),
        CONSTRAINT fk_cola_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    ) $m";


    /* ─────────────  SUCURSALES DE CADA AGENCIA, POR CIUDAD  ─────────────
       «¿Podremos hacer que según la agencia y destino salgan las sucursales
       disponibles?» (usuario, 2026-09-20). No hay lista oficial que cargar, así
       que la tabla SE LLENA SOLA: cada vez que un asesor escribe una sucursal
       en una venta, esa sucursal queda apuntada para esa agencia y esa
       provincia, y desde la siguiente venta se le ofrece a todos.

       La cuenta de veces no es adorno: ordena las sugerencias por lo que de
       verdad se usa, así que la de siempre sale primero y las que alguien
       escribió mal una vez se hunden solas. Se apagan, no se borran: la venta
       vieja tiene que seguir diciendo a dónde se mandó. */

        /* ─────────────  COMPROBANTES ELECTRÓNICOS (NUBEFACT)  ─────────────
       Cada boleta, factura o nota de crédito que se manda a NUBEFACT deja su
       fila, también las que fallan: el número se reserva ANTES de llamar, y
       si la llamada se corta a medias (sin saber si NUBEFACT la registró) la
       fila queda «incierto» y el siguiente intento reusa el mismo número en
       vez de emitir otra boleta por la misma venta. Parche 2u, 2026-09-21. */

    $t[] = "CREATE TABLE IF NOT EXISTS comprobantes_electronicos (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pedido_id        INT UNSIGNED NOT NULL,
        ambiente         VARCHAR(12) NOT NULL COMMENT 'prueba o produccion',
        tipo_cpe         TINYINT NOT NULL COMMENT '1 factura, 2 boleta, 3 nota de crédito',
        serie            VARCHAR(4) NOT NULL,
        numero           INT UNSIGNED NOT NULL,
        modifica_id      INT UNSIGNED NULL COMMENT 'la boleta o factura que anula una nota de crédito',
        estado           VARCHAR(12) NOT NULL DEFAULT 'enviando',
        total_centimos   BIGINT NOT NULL DEFAULT 0,
        enlace_pdf       VARCHAR(255) NULL,
        enlace_xml       VARCHAR(255) NULL,
        enlace_cdr       VARCHAR(255) NULL,
        aceptada_sunat   TINYINT(1) NULL,
        sunat_descripcion VARCHAR(255) NULL,
        codigo_error     INT NULL,
        error_texto      VARCHAR(500) NULL,
        emitido_por      INT UNSIGNED NULL,
        datos_json       TEXT NULL COMMENT 'lo que se mandó a NUBEFACT: la nota de crédito lo copia',
        enviado_en       DATETIME NULL,
        creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cpe_numero (ambiente, tipo_cpe, serie, numero),
        KEY ix_cpe_pedido (pedido_id, estado)
    ) $m";

        /* El siguiente número de cada serie. Una fila por ambiente, tipo y serie:
       las series de prueba de NUBEFACT no son las de producción, y una boleta
       y su nota de crédito pueden compartir serie sin compartir numeración. */
    $t[] = "CREATE TABLE IF NOT EXISTS nubefact_correlativos (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ambiente   VARCHAR(12) NOT NULL,
        tipo_cpe   TINYINT NOT NULL,
        serie      VARCHAR(4) NOT NULL,
        siguiente  INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_correlativo (ambiente, tipo_cpe, serie)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS agencia_sucursales (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id          SMALLINT UNSIGNED NOT NULL,
        agencia_item_id  INT UNSIGNED NOT NULL,
        provincia_id     INT UNSIGNED NULL COMMENT 'la provincia del destino, no el distrito',
        nombre           VARCHAR(120) NOT NULL,
        busca            VARCHAR(120) NOT NULL COMMENT 'el nombre sin tildes y en minúsculas',
        veces            INT UNSIGNED NOT NULL DEFAULT 1,
        activo           TINYINT(1) NOT NULL DEFAULT 1,
        creado_por       INT UNSIGNED NULL,
        creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_sucursal (pais_id, agencia_item_id, provincia_id, busca),
        KEY ix_sucursal_busca (agencia_item_id, provincia_id, activo)
    ) $m";

    /* ─────────────  LECTURAS DE LA TIENDA (módulo 3b)  ─────────────
       Lo que se leyó de compraenwaka se apunta aquí antes de guardarlo en el
       catálogo: así lo que se guarda es EXACTAMENTE lo que se enseñó en la
       vista previa, y no lo que la tienda diga un minuto después. */
    $t[] = "CREATE TABLE IF NOT EXISTS tienda_lecturas (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id      SMALLINT UNSIGNED NOT NULL,
        usuario_id   INT UNSIGNED NULL,
        filas        MEDIUMTEXT NOT NULL COMMENT 'lo leído de la tienda, en JSON',
        resumen      TEXT NOT NULL COMMENT 'las cifras que se enseñaron',
        estado       ENUM('leida','guardada') NOT NULL DEFAULT 'leida',
        creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        guardada_en  DATETIME NULL,
        guardada_por INT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY ix_lectura_pais (pais_id, id)
    ) $m";

    /* ─────────────  EL STOCK DE LA WEB (3b.5)  ─────────────
       Una FOTO de lo que dijo la tienda la última vez que se leyó: la
       cantidad que vale es la de la web. Una fila por producto (variante 0) y
       una por cada color o modelo. Se reemplaza entera en cada lectura. */
    $t[] = "CREATE TABLE IF NOT EXISTS stock_web (
        producto_id  INT UNSIGNED NOT NULL,
        variante_id  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = el producto',
        cantidad     INT NULL COMMENT 'NULL = la web no cuenta unidades',
        estado       VARCHAR(20) NOT NULL DEFAULT 'instock',
        compartido   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = usa las unidades del producto',
        leido_en     DATETIME NOT NULL,
        PRIMARY KEY (producto_id, variante_id)
    ) $m";

    /* ─────────────  LA COLA DEL STOCK DE LA WEB (3e)  ─────────────
       Todo lo que una venta mueve en la web: el descuento al registrarla y la
       devolución al anularla, también lo que salió bien a la primera. Lo que
       no se pudo (la tienda no respondió) espera aquí y se reintenta solo.
       `clave` es la clave de repetición que recuerda el conector: repetir una
       fila nunca descuenta dos veces. Una devolución espera a su descuento
       (`depende_de`). pedido_id NULL = una venta que no llegó a guardarse. */
    $t[] = "CREATE TABLE IF NOT EXISTS stock_web_cola (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pedido_id    INT UNSIGNED NULL,
        tipo         ENUM('descuento','devolucion') NOT NULL,
        clave        VARCHAR(40) NOT NULL,
        movimientos  TEXT NOT NULL,
        detalle      VARCHAR(500) NOT NULL DEFAULT '',
        exigir       TINYINT(1) NOT NULL DEFAULT 0,
        forzado      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = se descuenta aunque la web ya no tenga',
        depende_de   INT UNSIGNED NULL,
        estado       ENUM('pendiente','hecho','cancelado') NOT NULL DEFAULT 'pendiente',
        intentos     SMALLINT NOT NULL DEFAULT 0,
        ultimo_error VARCHAR(300) NULL,
        proximo_en   DATETIME NOT NULL,
        negativo     VARCHAR(500) NULL COMMENT 'se descontó sin stock en la web',
        revisar      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Administración tiene que mirarla',
        tienda       VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'la dirección de la tienda a la que va',
        nota         VARCHAR(200) NULL,
        resuelto_por INT UNSIGNED NULL,
        creado_en    DATETIME NOT NULL,
        hecho_en     DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_swc_clave (clave),
        UNIQUE KEY uq_swc_pedido (pedido_id, tipo),
        KEY ix_swc_estado (estado, proximo_en),
        KEY ix_swc_revisar (revisar)
    ) $m";

    /* ─────────────  EL STOCK DE LOS REPUESTOS (3i)  ─────────────
       Un repuesto que NO está en la web lleva su stock aquí: entra cuando su
       lote marca «Llegó» y lo corrige Almacén. Lo que está en la web usa el
       de la web, como todo. Ver nucleo/repuestos.php. Una fila por producto
       y país; `stock_hub_mov` es el rastro de cada unidad que entra o sale. */
    $t[] = "CREATE TABLE IF NOT EXISTS stock_hub (
        producto_id    INT UNSIGNED NOT NULL,
        pais_id        SMALLINT UNSIGNED NOT NULL,
        cantidad       INT NOT NULL DEFAULT 0,
        actualizado_en DATETIME NOT NULL,
        PRIMARY KEY (producto_id, pais_id)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS stock_hub_mov (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        producto_id  INT UNSIGNED NOT NULL,
        pais_id      SMALLINT UNSIGNED NOT NULL,
        cantidad     INT NOT NULL COMMENT 'positivo entra, negativo sale',
        queda        INT NOT NULL COMMENT 'lo que quedó después',
        motivo       VARCHAR(20) NOT NULL COMMENT 'lote, venta, anulacion, garantia, garantia_vuelve, ajuste',
        ref_tipo     VARCHAR(20) NULL,
        ref_id       INT UNSIGNED NULL,
        nota         VARCHAR(200) NULL,
        usuario_id   INT UNSIGNED NULL,
        creado_en    DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY ix_shm_producto (producto_id, pais_id, creado_en),
        KEY ix_shm_ref (ref_tipo, ref_id)
    ) $m";

    /* ─────────────  LAS GARANTÍAS (3i)  ─────────────
       Una salida por garantía NO es un pedido: no suma a la meta, no da
       cashback y no se cobra. Va atada al pedido original, la pide el asesor
       y la aprueba Administración. Aprobada, entra en «Por alistar».
       estado: pedida · aprobada · denegada · anulada. */
    $t[] = "CREATE TABLE IF NOT EXISTS garantias (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id            SMALLINT UNSIGNED NOT NULL,
        pedido_id          INT UNSIGNED NOT NULL,
        cliente_id         INT UNSIGNED NOT NULL,
        asesor_id          INT UNSIGNED NOT NULL COMMENT 'el dueño del pedido',
        estado             VARCHAR(12) NOT NULL DEFAULT 'pedida',
        motivo             VARCHAR(500) NOT NULL,
        sin_pieza          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'el asesor no sabe qué pieza es',
        fuera_plazo        TINYINT(1) NOT NULL DEFAULT 0,
        vence_en           DATE NULL COMMENT 'cuándo vencía al pedirla',
        pedida_por         INT UNSIGNED NULL,
        pedida_en          DATETIME NOT NULL,
        resuelta_por       INT UNSIGNED NULL,
        resuelta_en        DATETIME NULL,
        nota               VARCHAR(300) NULL COMMENT 'lo que dijo Administración',
        alistado_en        DATETIME NULL,
        alistado_por       INT UNSIGNED NULL,
        alistado_quien_id  INT UNSIGNED NULL,
        alistado_foto      VARCHAR(40) NULL,
        PRIMARY KEY (id),
        KEY ix_gar_pedido (pedido_id),
        KEY ix_gar_estado (pais_id, estado, pedida_en),
        KEY ix_gar_asesor (asesor_id, pedida_en)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS garantia_lineas (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        garantia_id  INT UNSIGNED NOT NULL,
        producto_id  INT UNSIGNED NOT NULL,
        cantidad     INT NOT NULL DEFAULT 1,
        anadida      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'la puso Administración',
        PRIMARY KEY (id),
        KEY ix_gl_garantia (garantia_id)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS garantia_fotos (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        garantia_id  INT UNSIGNED NOT NULL,
        archivo      VARCHAR(40) NOT NULL,
        orden        SMALLINT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY ix_gf_garantia (garantia_id)
    ) $m";

    /* ─────────────  MÓDULO 5 · BONOS, METAS Y RACHAS  ─────────────
       El cierre de cada período de cada bono: con las REGLAS con que se
       calculó, congeladas. Cambiar un bono después no toca lo ya cerrado. */
    $t[] = "CREATE TABLE IF NOT EXISTS bono_cierres (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        bono_id        SMALLINT UNSIGNED NOT NULL,
        periodo_inicio DATE NOT NULL,
        periodo_fin    DATE NOT NULL,
        reglas         MEDIUMTEXT NULL,
        cerrado_en     DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_bono_cierre (bono_id, periodo_inicio)
    ) $m";

    /* El nivel de cuota de cada asesor, con desde cuándo vale: los cambios
       rigen desde la quincena siguiente, y un período ya empezado no cambia. */
    $t[] = "CREATE TABLE IF NOT EXISTS usuario_niveles (
        usuario_id  INT UNSIGNED NOT NULL,
        desde       DATE NOT NULL,
        nivel       TINYINT UNSIGNED NULL,
        creado_por  INT UNSIGNED NULL,
        creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_id, desde)
    ) $m";

    /* La Cacería del Día: la lanza el CEO, una por día y país. */
    $t[] = "CREATE TABLE IF NOT EXISTS caceria_dias (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id     SMALLINT UNSIGNED NOT NULL,
        fecha       DATE NOT NULL,
        titulo      VARCHAR(120) NOT NULL,
        frase       VARCHAR(240) NULL,
        escalera    TEXT NULL,
        destino     VARCHAR(30) NOT NULL DEFAULT 'todos',
        lanzada_por INT UNSIGNED NULL,
        lanzada_en  DATETIME NOT NULL,
        editada_en  DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_caceria_dia (pais_id, fecha)
    ) $m";

    /* Lo que ya vio cada persona en la pila de novedades (en el servidor: si
       fuera en el navegador, desde la computadora lo vería otra vez). */
    $t[] = "CREATE TABLE IF NOT EXISTS novedades_vistas (
        usuario_id  INT UNSIGNED NOT NULL,
        clave       VARCHAR(80) NOT NULL,
        visto_en    DATETIME NOT NULL,
        PRIMARY KEY (usuario_id, clave)
    ) $m";

    /* Los avisos de racha al equipo: quién puso la marca más alta del día. */
    $t[] = "CREATE TABLE IF NOT EXISTS racha_avisos (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id     SMALLINT UNSIGNED NOT NULL,
        oficina_id  INT UNSIGNED NULL,
        usuario_id  INT UNSIGNED NOT NULL,
        nivel       SMALLINT NOT NULL,
        tipo        VARCHAR(10) NOT NULL DEFAULT 'dia',
        fecha       DATE NOT NULL,
        creado_en   DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY ix_racha_aviso (pais_id, fecha)
    ) $m";

    return $t;
}

/**
 * Columnas que el módulo 2 le añade a tablas del módulo 1.
 * Cada una se añade solo si falta, así que esto se puede pasar mil veces.
 * Devuelve la lista de lo que hizo, para enseñárselo a quien actualiza.
 */
/**
 * Las columnas ENUM que esta versión ensancha, con su definición COMPLETA.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ESTA LISTA Y EL `CREATE TABLE` DE esquema2.php TIENEN QUE DECIR LO MISMO.
 *  Si no, un HUB instalado desde cero y uno actualizado quedan con columnas
 *  distintas — la divergencia que ya obligó a escribir correr4.php. Y aquí el
 *  banco de pruebas no puede avisar: pruebas/banco.php traduce todo ENUM a
 *  VARCHAR(40) para que SQLite lo trague, así que los dos casos pasan en verde
 *  aunque se contradigan. Lo vigila una prueba que lee el CÓDIGO FUENTE de los
 *  dos sitios y los compara.
 * ═══════════════════════════════════════════════════════════════════════
 */
function enums_a_ampliar(): array
{
    return [
        /* «Liquidación» es un tipo de venta más, no una etiqueta: tiene su
           propio stock, no genera cashback y tampoco se puede pagar con él
           (usuario, 2026-09-11). */
        ['pedidos',           'tipo',   "ENUM('inmediata','preventa','liquidacion') NOT NULL DEFAULT 'inmediata'"],
        ['pedido_lineas',     'origen', "ENUM('tienda','preventa','liquidacion') NOT NULL DEFAULT 'tienda'"],
        ['stock',             'origen', "ENUM('tienda','preventa','liquidacion') NOT NULL DEFAULT 'tienda'"],
        ['stock_movimientos', 'origen', "ENUM('tienda','preventa','liquidacion') NOT NULL DEFAULT 'tienda'"],
    ];
}

function migraciones_modulo2(): array
{
    $hecho = [];

    $columnas = [
        /* ── CLIENTES ────────────────────────────────────────────────
           Nombres y apellidos van separados porque la tienda saluda por el
           nombre de pila. El correo es la llave con compraenwaka. */
        ['clientes', 'apellidos',      "VARCHAR(80) NULL"],
        ['clientes', 'telefono_alt',   "VARCHAR(25) NULL"],
        ['clientes', 'rubro',          "VARCHAR(80) NULL"],
        ['clientes', 'notas',          "VARCHAR(500) NULL"],
        ['clientes', 'woo_cliente_id', "BIGINT UNSIGNED NULL"],
        ['clientes', 'estado_tienda',  "VARCHAR(20) NOT NULL DEFAULT 'sin_cuenta'"],

        /* ── DIRECCIONES ───────────────────────────────────────────── */
        ['cliente_direcciones', 'ubigeo_id', "INT UNSIGNED NULL"],
        ['cliente_direcciones', 'gps',       "VARCHAR(255) NULL"],

        /* ── PEDIDOS ─────────────────────────────────────────────────
           La dirección se guarda COPIADA en el pedido, no solo enlazada: si
           el cliente se muda, el pedido de hace seis meses tiene que seguir
           diciendo a dónde se envió de verdad.
           El flete va SEPARADO del total: en provincia lo paga el cliente en
           destino, así que meterlo dentro descuadraría el saldo por cobrar. */
        ['pedidos', 'fecha',              "DATE NULL"],
        ['pedidos', 'registrado_por',     "INT UNSIGNED NULL"],
        ['pedidos', 'tipo_envio_item_id', "INT UNSIGNED NULL"],
        ['pedidos', 'flete_centimos',     "BIGINT NOT NULL DEFAULT 0"],
        ['pedidos', 'flete_lo_paga',      "VARCHAR(20) NOT NULL DEFAULT 'cliente_destino'"],
        ['pedidos', 'comision_centimos',  "BIGINT NOT NULL DEFAULT 0"],
        ['pedidos', 'sucursal',           "VARCHAR(120) NULL"],
        ['pedidos', 'envio_armado',       "TINYINT(1) NOT NULL DEFAULT 0"],
        /* MODELO Y COLOR de lo que se vendió, escrito por el asesor (usuario,
           2026-09-20). Despacho necesita saber si son «1 negro y 1 blanco», y
           hasta ahora eso viajaba por WhatsApp aparte o no viajaba. Cuando
           llegue el catálogo del módulo 3 la variante saldrá de una lista; este
           campo se queda con lo que se le prometió a ESTE cliente. */
                ['pedido_lineas', 'modelo',     "VARCHAR(120) NOT NULL DEFAULT ''"],
        /* EL CONCEPTO DE CADA PAGO que viene después del primero (usuario,
           2026-09-21): «saldo contraentrega», «delivery»… Lo escribe el asesor
           al subir un voucher adicional. El número —1.er pago, 2.º pago— no se
           guarda: se cuenta, y así no se puede desordenar. */
                ['pagos',         'concepto',   "VARCHAR(80) NOT NULL DEFAULT ''"],
        /* LA DIRECCIÓN FISCAL del cliente con RUC: una factura electrónica la
           exige, y la del pedido es a dónde se ENVÍA, que no tiene por qué ser
           la de la empresa (parche 2u). */
        ['clientes',      'direccion_fiscal', "VARCHAR(160) NULL"],
        /* EL CATÁLOGO (módulo 3a-1, 2026-09-22). La garantía deja de elegirse a
           mano en cada venta: la trae el producto, y el pedido la hereda. Y la
           línea del pedido guarda a QUÉ producto del catálogo corresponde, que
           es lo que permite un reporte por producto; `fuera_catalogo` marca las
           que el asesor escribió a mano mientras el catálogo se llena. */
        /* EL CUMPLEAÑOS DEL EQUIPO (usuario, 2026-09-23): se pide al dar de
           alta la cuenta. El HUB lo usa para saludar el día que toca; no se
           enseña en ninguna lista pública. */
        ['usuarios',      'fecha_nacimiento', "DATE NULL"],
        ['productos',     'garantia_item_id', "INT UNSIGNED NULL"],
        /* La foto principal de la tienda, en miniatura (3b.3, usuario
           2026-09-24): el nombre del archivo y de qué foto de la tienda salió,
           para no volver a bajarla si no cambió. */
        ['productos',     'foto',             "VARCHAR(80) NULL"],
        ['productos',     'foto_woo',         "BIGINT UNSIGNED NULL"],
        /* El catálogo es de un país: México vende otras cosas y en otra moneda.
           Vacío = vale para todos, que es lo que tienen los cargados antes. */
        ['productos',     'pais_id',          "SMALLINT UNSIGNED NULL"],
        ['pedido_lineas', 'producto_id',      "INT UNSIGNED NULL"],
        ['pedido_lineas', 'variante_id',      "INT UNSIGNED NULL"],
        ['pedido_lineas', 'fuera_catalogo',   "TINYINT(1) NOT NULL DEFAULT 0"],
        /* Lo enviado a NUBEFACT y cuándo: la nota de crédito lo copia, y un
           envío en marcha no se repite (auditoría del parche 2u). */
        ['comprobantes_electronicos', 'datos_json', "TEXT NULL"],
        ['comprobantes_electronicos', 'enviado_en', "DATETIME NULL"],
        ['pedidos', 'ubigeo_id',          "INT UNSIGNED NULL"],
        ['pedidos', 'direccion_txt',      "VARCHAR(220) NULL"],
        ['pedidos', 'referencia_txt',     "VARCHAR(200) NULL"],
        ['pedidos', 'gps',                "VARCHAR(255) NULL"],
        ['pedidos', 'recibe',             "VARCHAR(120) NULL"],

        /* ── EL COMPROBANTE ──────────────────────────────────────────
           Va en el PEDIDO y no en el pago: la boleta o la factura son de la
           VENTA. Una pre venta con adelanto y saldo lleva un solo comprobante,
           aunque el dinero entre en dos veces (decidido por el usuario el
           2026-09-09). Facturación lo emite a mano en Tumifactura; aquí solo
           queda la constancia, para que el reporte sepa qué ventas siguen sin
           comprobante.
           NULL = todavía no se decidió · 'ninguno' = se decidió no emitir.
           Serie y número son opcionales: obligarlos frenaría a facturación en
           cada venta, y sin ellos el reporte igual sabe que se emitió. */
        ['pedidos', 'comprobante_tipo',   "VARCHAR(10) NULL"],
        ['pedidos', 'comprobante_serie',  "VARCHAR(10) NULL"],
        ['pedidos', 'comprobante_numero', "VARCHAR(20) NULL"],
        ['pedidos', 'comprobante_por',    "INT UNSIGNED NULL"],
        ['pedidos', 'comprobante_en',     "DATETIME NULL"],
        /* El PDF de la boleta o la factura, subido a mano desde Tumifactura
           (usuario, 2026-09-09). Vive en uploads/comprobantes/, cerrada por
           .htaccess igual que los vouchers: una boleta lleva el nombre, el
           documento y la dirección del cliente. El día que haya API, este
           mismo campo se llena solo y nadie tiene que aprender nada nuevo. */
        ['pedidos', 'comprobante_archivo', "VARCHAR(80) NULL"],

        /* ── DESPACHO ────────────────────────────────────────────────
           El asesor manda los datos del envío al grupo de despacho por
           WhatsApp. El HUB arma el mensaje y anota que se mandó: sin esa
           anotación, nadie sabe si un pedido ya salió o se quedó en el aire,
           y el remedio de la casa es mandarlo dos veces. */
        ['pedidos', 'despacho_por',       "INT UNSIGNED NULL"],
        ['pedidos', 'despacho_en',        "DATETIME NULL"],
        ['pedidos', 'despacho_veces',     "INT NOT NULL DEFAULT 0"],

        /* ── CUÁNDO SE ENTREGA ───────────────────────────────────────
           Lo que el cliente dice en la conversación de la venta —«el jueves
           por la tarde», «déjenlo en la oficina»— y que hasta hoy se perdía
           entre el pedido y el grupo de despacho. Todo opcional: la mayoría
           de las entregas no tienen hora.
           La hora se guarda como texto HH:MM y no como TIME porque es un
           rango que a veces está a medias («desde las 3», sin hasta), y un
           TIME vacío obliga a inventarse un valor. */
        /* A provincia hay dos formas de recibirlo y hasta ahora solo existía
           una: el formulario pedía dirección SIEMPRE. La más usada es que el
           cliente pase por la agencia, y ahí no hay dirección que valga. No
           sustituye a `entrega` —sigue siendo un envío, no un recojo en
           oficina— porque quien recoge en la agencia de Trujillo no está
           recogiendo en la oficina de Lima, y el mensaje que se manda al grupo
           es otro. */
        ['pedidos', 'recojo_agencia',     "TINYINT(1) NOT NULL DEFAULT 0"],
        /* La agencia que el asesor escribió porque no estaba en la lista. No
           traba la venta y no ensucia el desplegable: queda aquí hasta que
           Administración la autorice, y entonces los pedidos que la usaron se
           enganchan a la lista. Lo pidió el usuario el 2026-09-10: «que se
           mande con el pedido pero solo con autorización se añadiría». */
        ['pedidos', 'agencia_otra',       "VARCHAR(60) NULL"],
        /* La capital del distrito, cuando se llama distinto. 357 de los 1.893
           la tienen, y la gente habla con ese nombre: nadie dice «mándalo a
           Chanchamayo», dicen «a La Merced». `busca_capital` es lo que mira el
           buscador; `capital` es lo que se enseña entre paréntesis. */
        ['ubigeo', 'capital',             "VARCHAR(90) NULL"],
        ['ubigeo', 'busca_capital',       "VARCHAR(90) NULL"],
        ['pedidos', 'entrega_fecha',      "DATE NULL"],
        ['pedidos', 'entrega_hora_desde', "VARCHAR(5) NULL"],
        ['pedidos', 'entrega_hora_hasta', "VARCHAR(5) NULL"],
        ['pedidos', 'entrega_recepcion',  "TINYINT NOT NULL DEFAULT 0"],

        /* ── LA GARANTÍA ─────────────────────────────────────────────
           Cuántos meses responde Waka por lo vendido. Va en el PEDIDO y no en
           el producto porque es lo que se le prometió a ESE cliente ese día:
           lo de liquidación sale sin garantía y lo normal con doce meses, pero
           el asesor puede pactar otra cosa y el papel tiene que decir lo que
           se pactó.

           Es un item de lista, no un número ni un enum: el día que la promesa
           pase a 18 meses se cambia en Configuración, y los pedidos viejos
           siguen diciendo los 12 que se les vendió. */
        ['pedidos', 'garantia_item_id',   "INT UNSIGNED NULL"],

        /* ── LO QUE EL CLIENTE PIDIÓ ─────────────────────────────────
           DOS COLUMNAS Y NO UNA, a propósito. `comprobante_pide` es lo que el
           ASESOR dice que el cliente quiere —él es quien habla con él— y
           `comprobante_tipo` es lo que FACTURACIÓN decidió emitir de verdad.
           Metidos en la misma columna, marcar «no necesita» al vender borraría
           la boleta que facturación emitió media hora antes, o al revés: son
           dos hechos distintos y cada uno necesita su sitio.
           'boleta' · 'factura' · NULL = NO SE PIDIÓ NINGUNO, y eso significa
           que esa venta no lleva comprobante (usuario, 2026-09-12: «no pedir
           nada es no necesita»). Dejó de ser un campo del formulario para ser
           una SOLICITUD que el asesor manda cuando quiere —al terminar la
           venta o días después—, así que hace falta saber quién la mandó y
           cuándo: sin eso, «pidió factura» es una palabra sin dueño el día que
           haya que discutirla. */
        ['pedidos', 'comprobante_pide',    "VARCHAR(10) NULL"],

        /* ── EL RECLAMO ──────────────────────────────────────────────
           Cuántas veces el asesor le ha pedido a facturación que mire ESTA
           venta, y cuándo fue la última. Va en el pedido y no en el pago
           porque es lo que el asesor ve: «mi venta lleva parada media hora».
           El contador importa tanto como la fecha — una venta reclamada tres
           veces dice algo que una reclamada una vez no dice, y es lo único
           que después permite ver quién hace esperar a quién. */
        ['pedidos', 'reclamo_veces',       "SMALLINT UNSIGNED NOT NULL DEFAULT 0"],
        ['pedidos', 'reclamo_en',          "DATETIME NULL"],
        ['pedidos', 'reclamo_por',         "INT UNSIGNED NULL"],
        ['pedidos', 'comprobante_pide_en', "DATETIME NULL"],
        ['pedidos', 'comprobante_pide_por',"INT UNSIGNED NULL"],

        /* ── EL DESCUENTO DEL ASESOR (3d) ─────────────────────────
           `descuento_centimos` ya existía y es el que resta del total. Esto
           es su historia: cuánto se pidió, por qué, quién, y qué respondió
           Administración cuando pasaba del tope. Ver descuentos.php. */
        ['pedidos', 'descuento_pedido_centimos', "BIGINT NOT NULL DEFAULT 0"],
        ['pedidos', 'descuento_motivo',          "VARCHAR(160) NULL"],
        ['pedidos', 'descuento_estado',          "VARCHAR(12) NOT NULL DEFAULT ''"],
        ['pedidos', 'descuento_por',             "INT UNSIGNED NULL"],
        ['pedidos', 'descuento_revisado_por',    "INT UNSIGNED NULL"],
        ['pedidos', 'descuento_revisado_en',     "DATETIME NULL"],
        ['pedidos', 'descuento_nota',            "VARCHAR(160) NULL"],

        /* ── PAGOS ───────────────────────────────────────────────────
           'devolucion' es una fila con monto NEGATIVO y la fecha del día en
           que se revierte, no del pago original. Así la meta del mes pasado
           no cambia sola cuando se anula algo hoy. */
        ['pagos', 'tipo',             "VARCHAR(12) NOT NULL DEFAULT 'cobro'"],
        ['pagos', 'revierte_pago_id', "INT UNSIGNED NULL"],
        ['pagos', 'anulado_por',      "INT UNSIGNED NULL"],
        ['pagos', 'anulado_en',       "DATETIME NULL"],
        ['pagos', 'anulado_motivo',   "VARCHAR(200) NULL"],

        /* ── LA REVISIÓN DE FACTURACIÓN ──────────────────────────────
           Ojo: el dinero lo siguen mandando `verificado` y `anulado`, y nada
           de aquí abajo entra en PAGO_CUENTA. Esto solo cuenta lo que hizo
           facturación con el pago, que es otra cosa.

           `en_espera` es el caso del interbancario: lo miré, no está en el
           banco todavía, y no es falso. Sin esta columna, un pago que
           facturación ya revisó se ve EXACTAMENTE igual que uno que nadie
           tocó — y con 19 asesores esa fila se vuelve ruido en una semana.

           `denegado` separa «facturación dijo que el voucher no es bueno» de
           «el asesor quitó un pago que anotó mal». Los dos apagan el pago,
           pero solo el primero tiene que llegarle al asesor con su motivo. */
        ['pagos', 'en_espera',        "TINYINT(1) NOT NULL DEFAULT 0"],
        ['pagos', 'espera_nota',      "VARCHAR(200) NULL"],
        ['pagos', 'espera_por',       "INT UNSIGNED NULL"],
        ['pagos', 'espera_en',        "DATETIME NULL"],
        ['pagos', 'denegado',         "TINYINT(1) NOT NULL DEFAULT 0"],

        /* Deshacer una confirmación y devolverle el dinero al cliente producen
           la MISMA fila negativa, pero no son lo mismo y quien las mira tiene
           que poder distinguirlas: en una no salió dinero de la caja y en la
           otra sí. La palabra «devolución» a secas confundía a los tres roles
           a la vez, así que la fila negativa dice de cuál de las dos viene.
           'error' = se confirmó por equivocación · 'cliente' = se le regresó. */
        ['pagos', 'reverso_clase',    "VARCHAR(12) NULL"],

        /* ── LISTAS EDITABLES ────────────────────────────────────────
           Dos ajustes por método de pago, tal como se decidió:
           "pide comprobante" y "cuenta como cobrado al instante". */
        ['lista_items', 'pide_comprobante', "TINYINT(1) NOT NULL DEFAULT 0"],
        /* DEFAULT 0 y no 1: un método que nace contando al instante nace
           validado, acredita cashback y suma a la meta sin que nadie lo haya
           mirado. Con el valor por defecto del lado seguro, lo peor que puede
           pasar es que alguien tenga que confirmarlo a mano. */
        ['lista_items', 'al_instante',      "TINYINT(1) NOT NULL DEFAULT 0"],

        /* Un tipo de envío que tiene costo para el cliente. Lo pidió el
           usuario probando: elegía «Envío con costo en Lima» y no había dónde
           escribir cuánto, porque el formulario decidía por la rama
           Lima/provincia en vez de por una propiedad de la lista. */
        ['lista_items', 'cobra_flete',      "TINYINT(1) NOT NULL DEFAULT 0"],

        /* DÓNDE APLICA un tipo de envío: lima, provincia, oficina — o vacío,
           que es «en todas». El desplegable enseñaba las cuatro opciones
           siempre, así que a provincia salían igual las dos de Lima. Va como
           propiedad del ITEM y no como una lista de nombres escrita en el
           código: el día que Administración cree «Envío con costo a Arequipa»
           le pone la marca y aparece donde toca, sin llamar al programador. */
        ['lista_items', 'ambito_envio',     "VARCHAR(12) NOT NULL DEFAULT ''"],

        /* El recargo que cobra un método de pago, en CENTÉSIMAS de punto: el
           4% del POS es 400. Entero, como todo el dinero del HUB — un 4.0
           flotante multiplicado por un monto grande es justo lo que descuadra
           un céntimo cada mil ventas.
           Va en la LISTA y no en el código: el día que el banco baje al 3.5%,
           Administración escribe 350 y listo. Y si mañana otra pasarela cobra
           otra cosa, tiene la suya. 0 = no cobra recargo, que es lo normal. */
        ['lista_items', 'recargo_centesimas', "INT NOT NULL DEFAULT 0"],
        /* LA FOTO DEL DNI (3f): «cuando se pague con POS, que haya dos
           imágenes: la del DNI y la del voucher» (usuario, 2026-09-26). Va por
           método, como el voucher: se marca en Configuración. */
        ['lista_items', 'pide_dni',           "TINYINT(1) NOT NULL DEFAULT 0"],
        ['pagos',       'foto_dni',           "VARCHAR(40) NULL"],
        /* ALISTADO (3g): Almacén marca el pedido con la foto de lo que
           preparó y quién lo preparó (de la lista «Equipo de despacho»). */
        ['pedidos',     'alistado_en',        "DATETIME NULL"],
        ['pedidos',     'alistado_por',       "INT UNSIGNED NULL"],
        ['pedidos',     'alistado_quien_id',  "INT UNSIGNED NULL"],
        ['pedidos',     'alistado_foto',      "VARCHAR(40) NULL"],
        /* ── PRE VENTA Y LOTES (3h) ──────────────────────────────────
           Lo que el Excel de ingresos de carga trae al pie de cada
           contenedor, ahora en campos para llenar a mano. */
        ['lotes',       'agencia_item_id',    "INT UNSIGNED NULL"],
        ['lotes',       'agencia_ref',        "VARCHAR(120) NULL"],
        ['lotes',       'bl',                 "VARCHAR(80) NULL"],
        ['lotes',       'factura',            "VARCHAR(80) NULL"],
        ['lotes',       'canal',              "VARCHAR(12) NOT NULL DEFAULT ''"],
        ['lotes',       'almacen',            "VARCHAR(120) NULL"],
        ['lotes',       'fecha_almacen',      "DATE NULL"],
        ['lotes',       'responsable_id',     "INT UNSIGNED NULL"],
        ['lotes',       'notas',              "VARCHAR(500) NULL"],
        ['lotes',       'puerto_origen',      "VARCHAR(60) NULL"],
        ['lotes',       'puerto_destino',     "VARCHAR(60) NULL"],
        /* Cada fila del lote: un producto y su modelo o color. */
        ['lote_lineas', 'modelo',             "VARCHAR(120) NOT NULL DEFAULT ''"],
        ['lote_lineas', 'codigo',             "VARCHAR(60) NULL"],
        ['lote_lineas', 'nuevo',              "TINYINT(1) NOT NULL DEFAULT 0"],
        ['lote_lineas', 'orden',              "INT NOT NULL DEFAULT 0"],
        /* De qué fila del lote salió lo vendido: el stock de pre venta es
           lo que trae el lote MENOS lo vendido (una sola cuenta). */
        ['pedido_lineas', 'lote_linea_id',    "INT UNSIGNED NULL"],

        /* ── REPUESTOS Y GARANTÍA (3i) ───────────────────────────────
           Un repuesto es un producto más, colgado de SU MÁQUINA (padre_id).
           En el lote, la fila de un repuesto lleva el código de su máquina
           (`maquina`): así se sabe que no es para la pre venta. */
        ['productos',   'padre_id',           "INT UNSIGNED NULL"],
        ['lote_lineas', 'maquina',            "VARCHAR(60) NULL"],
        /* Los meses de cada garantía, como número: con ellos se sabe hasta
           cuándo vale. NULL = sin poner todavía (se saca del texto al actualizar). */
        ['lista_items', 'meses',              "SMALLINT NULL"],
        /* La PRIMERA vez que el pedido salió a despacho: la garantía cuenta
           desde aquí. `despacho_en` cambia al volver a mandarlo. */
        ['pedidos',     'despacho_primero_en', "DATETIME NULL"],
        /* Lo que una garantía aprobada movió en la web (un repuesto que está
           en la tienda): la fila de la cola va colgada de la garantía. */
        ['stock_web_cola', 'garantia_id',     "INT UNSIGNED NULL"],

        /* ── LISTO PARA ENTREGA Y ENTREGADO (3j) ─────────────────────
           El lote que llegó queda «listo para entrega» cuando el CEO lo dice
           (descargado y ordenado): recién ahí sus pre ventas salen. Y el
           pedido se da por entregado con la foto de la entrega. */
        ['lotes',       'listo_en',           "DATETIME NULL"],
        ['lotes',       'listo_por',          "INT UNSIGNED NULL"],
        ['pedidos',     'entregado_en',       "DATETIME NULL"],
        ['pedidos',     'entregado_por',      "INT UNSIGNED NULL"],
        ['pedidos',     'entregado_foto',     "VARCHAR(160) NULL"],

        /* ── BONOS (módulo 5) ────────────────────────────────────────
           Cada bono es de un país (los mismos bonos, otros montos en
           México), con sus reglas editables y desde cuándo cuenta. */
        ['bonos', 'pais_id',          "SMALLINT UNSIGNED NULL"],
        ['bonos', 'tipo',             "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['bonos', 'nombre_anterior',  "VARCHAR(80) NULL"],
        ['bonos', 'mostrar_anterior', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['bonos', 'reglas',           "MEDIUMTEXT NULL"],
        ['bonos', 'activo_desde',     "DATE NULL"],
        /* Auditoría 5a: el cierre avanza con un cursor, un bono apagado deja
           de contar desde el período que iba, las pausas no se pagan y las
           reglas que regían al terminar un período esperan a su cierre. */
        ['bonos', 'cerrar_desde',     "DATE NULL"],
        ['bonos', 'apagado_desde',    "DATE NULL"],
        ['bonos', 'pausas',           "TEXT NULL"],
        ['bonos', 'reglas_antes',     "MEDIUMTEXT NULL"],
        ['bono_resultados', 'pais_id',    "SMALLINT UNSIGNED NULL"],
        ['bono_resultados', 'puesto',     "SMALLINT NULL"],
        ['bono_resultados', 'detalle',    "TEXT NULL"],
        ['bono_resultados', 'pagado_en',  "DATETIME NULL"],
        ['bono_resultados', 'pagado_por', "INT UNSIGNED NULL"],

        /* ── EQUIPOS ─────────────────────────────────────────────────
           Un equipo es de una oficina: dos de Lima y uno de Arequipa. */
        ['equipos', 'oficina_id', "INT UNSIGNED NULL"],

        /* ── CASHBACK ────────────────────────────────────────────────
           De qué bloque salió cada consumo. Sin esto, al anular un pedido se
           liberaba «algún» bloque del cliente en vez del que se había gastado
           de verdad, y el saldo devuelto se llevaba la fecha de vencimiento de
           otro: se le regalaban meses de vigencia por anular. */
        ['cashback_movimientos', 'bloque_id', "BIGINT UNSIGNED NULL"],
    ];

    /* CADA COLUMNA EN SU PROPIO try, por lo mismo que los ENUM de más abajo:
       el DDL de MySQL hace COMMIT al vuelo. Un solo ALTER que falle —al
       usuario de la base le falta ALTER, la tabla está ocupada— abortaba
       actualizar.php aquí mismo, justo DELANTE de los rellenos de «solo la
       primera vez»; y en la siguiente pasada las columnas ya creadas existen,
       $recien sale vacío y esos rellenos no vuelven a correr NUNCA. El HUB se
       quedaba para siempre con Yape sin pedir voucher, «Envío gratis Lima»
       ofreciéndose en Cusco y el POS sin su 4%. Ahora lo que falle se dice y
       el resto sigue. Lo encontró la auditoría del 2026-09-11. */
    $recien = [];
    foreach ($columnas as [$tabla, $col, $def]) {
        try {
            if (anadir_columna($tabla, $col, $def)) {
                $hecho[] = "$tabla.$col";
                $recien["$tabla.$col"] = true;
            }
        } catch (Throwable $ex) {
            error_log('[HUB] columna ' . $tabla . '.' . $col . ': ' . $ex->getMessage());
            $hecho[] = "NO se pudo crear $tabla.$col. El resto sí se aplicó. "
                     . 'Lo más común es que al usuario de la base le falte ALTER.';
        }
    }

    /* ── Los dos ajustes de cada método de pago, la primera vez ──────────
       Las columnas nacen con su valor por defecto (no pide comprobante,
       cuenta al instante), así que en un HUB YA INSTALADO todos los métodos
       quedaban contando al instante: una transferencia habría sumado a la
       meta sin que nadie la confirmara en el banco, que es justo lo que la
       validación existe para impedir. En una instalación nueva sí salían
       bien, porque los siembra sembrar_listas(). Dos HUB con el mismo código
       y distinto comportamiento, y sin nada en pantalla que lo dijera.

       Esto solo corre la vez que se crean las columnas. Después manda lo que
       Administración haya puesto en Configuración, y una actualización no lo
       pisa. */
    /* CADA RELLENO EN SU PROPIO try, por lo mismo que los ALTER de arriba: uno
       que reviente —porque la columna hermana no llegó a crearse, por ejemplo—
       abortaba actualizar.php AQUÍ, delante de los demás, y como el DDL ya
       comiteó, en la siguiente pasada `$recien` sale vacío y no vuelven a
       correr NUNCA. Cada uno protege solo lo suyo. */
    $relleno = function (callable $fn, string $que) use (&$hecho): void {
        try { $fn(); }
        catch (Throwable $ex) {
            error_log('[HUB] relleno ' . $que . ': ' . $ex->getMessage());
            /* Y se dice que REPETIR NO LO ARREGLA: la columna ya se creó, así
               que en la siguiente pasada $recien sale vacío y esto no vuelve a
               correr. Hay que ponerlo a mano o desde Configuración. */
            $hecho[] = "NO se pudo poner $que. El resto sí se aplicó, y volver a pasar esta "
                     . 'página no lo arregla: hay que ponerlo desde Configuración.';
        }
    };

    if (!empty($recien['lista_items.pide_comprobante'])) $relleno(function () use (&$hecho) {
        $porDefecto = [
            'Yape'                    => [1, 0],
            'Plin'                    => [1, 0],
            'Transferencia BCP'       => [1, 0],
            'Transferencia Interbank' => [1, 0],
            'Depósito'                => [1, 0],
            'POS / tarjeta'           => [1, 0],
            'Efectivo'                => [0, 0],
        ];
        $lista_id = valor("SELECT id FROM listas WHERE clave = 'metodos_pago'");
        if ($lista_id) {
            foreach ($porDefecto as $valor => [$comprobante, $instante]) {
                q('UPDATE lista_items SET pide_comprobante = ?, al_instante = ?
                    WHERE lista_id = ? AND valor = ?',
                  [$comprobante, $instante, $lista_id, $valor]);
            }
            $hecho[] = 'métodos de pago: puesto qué cuenta al instante y qué pide voucher';
        }
    }, 'qué método pide voucher');

    /* ── El POS pide la foto del DNI, la primera vez (3f) ─────────────── */
    if (!empty($recien['lista_items.pide_dni'])) $relleno(function () use (&$hecho) {
        $lista_id = valor("SELECT id FROM listas WHERE clave = 'metodos_pago'");
        if ($lista_id) {
            q("UPDATE lista_items SET pide_dni = 1 WHERE lista_id = ? AND valor LIKE 'POS%'", [$lista_id]);
            $hecho[] = 'métodos de pago: el POS pide la foto del DNI';
        }
    }, 'qué método pide la foto del DNI');

    /* ── Qué tipo de envío cobra flete, la primera vez ──────────────────
       Misma trampa que con los métodos de pago: la columna nace en 0, así que
       en un HUB ya instalado «Envío con costo en Lima» habría quedado sin
       costo y el asesor seguiría sin poder escribir el monto. En una
       instalación nueva sí sale bien porque lo siembra sembrar_listas(); dos
       HUB con el mismo código y distinto comportamiento otra vez.

       Solo corre la vez que se crea la columna. Después manda lo que
       Administración haya puesto en Configuración. */
    if (!empty($recien['lista_items.cobra_flete'])) $relleno(function () use (&$hecho) {
        $lista_id = valor("SELECT id FROM listas WHERE clave = 'tipos_envio'");
        if ($lista_id) {
            q("UPDATE lista_items SET cobra_flete = 1
                WHERE lista_id = ? AND valor = 'Envío con costo en Lima'",
              [$lista_id]);
            $hecho[] = 'tipos de envío: marcado cuáles tienen costo';
        }
    }, 'qué tipo de envío cobra flete');

    /* ── El recargo del POS, la primera vez ─────────────────────────────
       Misma trampa que las tres de arriba: la columna nace en 0, así que en un
       HUB ya instalado el POS no cobraría nada y el asesor volvería a calcular
       el 4% de cabeza. Solo corre la vez que se crea la columna; después manda
       lo que Administración haya puesto en Configuración. */
    if (!empty($recien['lista_items.recargo_centesimas'])) $relleno(function () use (&$hecho) {
        $lista_id = valor("SELECT id FROM listas WHERE clave = 'metodos_pago'");
        if ($lista_id) {
            $st = q("UPDATE lista_items SET recargo_centesimas = 400
                      WHERE lista_id = ? AND valor = 'POS / tarjeta'", [$lista_id]);
            $hecho[] = $st->rowCount() > 0
                ? 'POS / tarjeta: puesto su 4% de recargo'
                : 'no se encontró «POS / tarjeta» para ponerle el 4%: revísalo en Configuración';
        }
    }, 'el recargo del POS');

    /* ── Dónde aplica cada tipo de envío, la primera vez ────────────────
       Misma trampa, tercera vez: la columna nace vacía, y vacío significa «en
       todas», así que en un HUB ya instalado el desplegable seguiría
       ofreciendo «Envío gratis Lima» en una venta a Cusco. Solo corre la vez
       que se crea la columna; después manda lo que haya en Configuración. */
    if (!empty($recien['lista_items.ambito_envio'])) $relleno(function () use (&$hecho) {
        $lista_id = valor("SELECT id FROM listas WHERE clave = 'tipos_envio'");
        if ($lista_id) {
            foreach ([
                'Envío gratis Lima'       => 'lima',
                'Envío con costo en Lima' => 'lima',
                'Flete a provincia'       => 'provincia',
                'Recojo en oficina'       => 'oficina',
            ] as $valor => $ambito) {
                q('UPDATE lista_items SET ambito_envio = ? WHERE lista_id = ? AND valor = ?',
                  [$ambito, $lista_id, $valor]);
            }
            $hecho[] = 'tipos de envío: puesto dónde aplica cada uno '
                     . '(a provincia ya no salen los de Lima)';
        }
    }, 'el ámbito de los tipos de envío');

    /* Lo vendido de cada fila de un lote se cuenta y se BLOQUEA por esta
       columna (3h): sin índice, cada cuenta recorrería todas las líneas de
       todos los pedidos, y el bloqueo de la venta tomaría la tabla entera. */
    if (tabla_existe('pedido_lineas') && columna_existe('pedido_lineas', 'lote_linea_id')
        && !indice_existe('pedido_lineas', 'ix_linea_lote')) {
        try {
            bd()->exec('CREATE INDEX ix_linea_lote ON pedido_lineas (lote_linea_id)');
        } catch (Throwable $ex) {
            error_log('[HUB] indice lote_linea_id: ' . $ex->getMessage());
        }
    }
    /* Los repuestos de una máquina (3i): el buscador y la garantía los piden
       por su padre. */
    if (tabla_existe('productos') && columna_existe('productos', 'padre_id')
        && !indice_existe('productos', 'ix_producto_padre')) {
        try {
            bd()->exec('CREATE INDEX ix_producto_padre ON productos (padre_id)');
        } catch (Throwable $ex) {
            error_log('[HUB] indice padre_id: ' . $ex->getMessage());
        }
    }

    /* El correo del cliente es la llave con su cuenta de la tienda y con su
       cashback: dos fichas con el mismo correo son dos personas compartiendo
       saldo. El índice se añade solo si no hay duplicados ya metidos; si los
       hubiera, se avisa en vez de reventar la actualización a medias. */
    if (tabla_existe('clientes') && columna_existe('clientes', 'email')
        && !indice_existe('clientes', 'uq_cliente_email')) {
        try {
            /* Dos correos VACÍOS también chocan en un índice único: solo los
               NULL no chocan. Si se contaran solo los correos escritos, dos
               fichas viejas sin correo hacían reventar el ALTER... y la
               excepción se llevaba por delante el resto de actualizar.php, así
               que el HUB se quedaba con las tablas nuevas pero sin permisos,
               listas ni estados, y la pantalla culpaba a un permiso de ALTER
               que no tenía nada que ver. Ahora se cuentan los dos casos, y
               además esto va en su propio try: una migración que no se puede
               aplicar avisa, no tumba a las demás. */
            $dup = (int) valor("SELECT COUNT(*) FROM (
                        SELECT pais_id, email FROM clientes
                         WHERE email IS NOT NULL
                         GROUP BY pais_id, email HAVING COUNT(*) > 1) x");
            if ($dup === 0) {
                bd()->exec('CREATE UNIQUE INDEX uq_cliente_email ON clientes (pais_id, email)');
                $hecho[] = 'clientes: correo único por país';
            } else {
                $hecho[] = "clientes: NO se puso el correo único porque hay $dup caso(s) repetido(s) "
                         . '(cuentan también las fichas sin correo). Únelas o ponles su correo desde '
                         . 'la lista de clientes, y vuelve a pasar esta página. El resto sí se aplicó.';
            }
        } catch (Throwable $ex) {
            error_log('[HUB] indice correo cliente: ' . $ex->getMessage());
            $hecho[] = 'clientes: no se pudo poner el correo único. El resto sí se aplicó.';
        }
    }

    /* ── NINGÚN pago cuenta al instante ──────────────────────────────
       Lo decidió el usuario el 2026-09-08: quiere que facturación confirme
       cada ingreso, sin excepciones, y que el HUB sea donde eso queda
       registrado — hoy lo hacen por WhatsApp.

       Va con marca de "hecho" en ajustes y no en el sembrado, porque esto
       corre UNA sola vez en la vida del HUB: si se repitiera en cada
       actualización, el día que Administración decida volver a poner Yape al
       instante desde Configuración, la siguiente actualización se lo desharía
       sin decir nada. */
    if (tabla_existe('ajustes') && columna_existe('lista_items', 'al_instante')) {
        try {
            /* Se pregunta si la FILA existe, no por su valor: `valor()` devuelve
               null tanto cuando no hay fila como cuando la hay con el valor
               vacío, y en ese segundo caso la migración se creía pendiente y
               el INSERT chocaba contra la clave primaria. */
            $hay = (int) valor("SELECT COUNT(*) FROM ajustes WHERE clave = 'migracion_pos_pendiente'");
            if ($hay === 0) {
                $st = q("UPDATE lista_items SET al_instante = 0
                          WHERE al_instante = 1
                            AND lista_id = (SELECT id FROM listas WHERE clave = 'metodos_pago')");
                $tocadas = $st->rowCount();

                q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
                   VALUES ('migracion_pos_pendiente', '1', 'booleano', 'pagos',
                           'Marca de que el POS ya pasó a validación manual. No tocar.', 1)");

                /* Y se dice lo que de verdad pasó. Si el método está con otro
                   nombre, el UPDATE no toca nada; dar la migración por hecha en
                   silencio dejaba el POS contando al instante para siempre y la
                   pantalla diciendo lo contrario. */
                $hecho[] = $tocadas > 0
                    ? "todos los métodos de pago pasan a esperar confirmación ($tocadas cambiados)"
                    : 'los métodos de pago ya esperaban confirmación, no hubo nada que cambiar';
            }
        } catch (Throwable $ex) {
            error_log('[HUB] migracion metodos: ' . $ex->getMessage());
            $hecho[] = 'no se pudieron pasar los métodos de pago a confirmación. El resto sí se aplicó.';
        }
    }

    /* Un pago no puede acreditar —ni revertir— dos veces.
       La comprobación de PHP («¿ya hay un movimiento de este pago?») es una
       lectura y luego una escritura: entre las dos cabe otra petición. Con dos
       pestañas abiertas en la bandeja de validación, dos clics casi a la vez
       le regalaban al cliente el cashback dos veces. Esto lo cierra la base,
       que es la única que puede: el código captura el choque y sigue. */
    if (tabla_existe('cashback_movimientos') && !indice_existe('cashback_movimientos', 'uq_cb_pago_tipo')) {
        try {
            $dup = (int) valor("SELECT COUNT(*) FROM (
                        SELECT pago_id, tipo FROM cashback_movimientos
                         WHERE pago_id IS NOT NULL
                         GROUP BY pago_id, tipo HAVING COUNT(*) > 1) x");
            if ($dup === 0) {
                bd()->exec('CREATE UNIQUE INDEX uq_cb_pago_tipo ON cashback_movimientos (pago_id, tipo)');
                $hecho[] = 'cashback: un pago no puede acreditar dos veces';
            } else {
                $hecho[] = "cashback: NO se pudo poner el candado, hay $dup movimiento(s) repetido(s). "
                         . 'Avisa a Bloomit antes de seguir.';
            }
        } catch (Throwable $ex) {
            error_log('[HUB] indice cashback: ' . $ex->getMessage());
            $hecho[] = 'cashback: no se pudo poner el candado de un pago = un movimiento.';
        }
    }

    /* «Recomendación» se llama Referido: quedó decidido y el módulo 1 lo sembró
       con el nombre viejo. Se renombra el item, no se crea otro, para que los
       pedidos que ya lo usen no se queden apuntando a un valor apagado. */
    try {
        q("UPDATE lista_items SET valor = 'Referido'
            WHERE valor = 'Recomendado'
              AND lista_id = (SELECT id FROM listas WHERE clave = 'canales')");
    } catch (Throwable $ex) {
        error_log('[HUB] migracion canales: ' . $ex->getMessage());
    }

    /* La fecha del pedido existe desde ahora; los pedidos viejos toman la de
       registro para que ningún reporte los deje fuera por tener la fecha vacía.

       EN SU PROPIO try, como todo lo demás de aquí, y por el mismo motivo con
       un desenlace peor: esta excepción subía hasta actualizar.php, que tiene
       UN SOLO try para los pasos 1 a 4, así que se llevaba por delante
       sembrar_todo() — el HUB arrancaba con las tablas nuevas y SIN los
       permisos, roles, listas ni plantillas nuevos, diciendo solo «algo falló
       a mitad». Y volver a pasar la página no lo arreglaba: el mismo UPDATE
       volvía a fallar. Basta un pedido viejo con creado_en en 0000-00-00, o un
       lock, o un usuario de base sin UPDATE sobre pedidos. */
    $relleno(function () use (&$hecho) {
        if (!tabla_existe('pedidos') || !columna_existe('pedidos', 'fecha')) return;
        $sin = (int) valor('SELECT COUNT(*) FROM pedidos WHERE fecha IS NULL');
        if (!$sin) return;
        /* Solo los que tienen un creado_en creíble: DATE('0000-00-00') en
           MySQL estricto revienta el UPDATE entero por una sola fila mala. */
        q("UPDATE pedidos SET fecha = DATE(creado_en)
            WHERE fecha IS NULL AND creado_en IS NOT NULL AND creado_en > '1970-01-01'");
        $quedan = (int) valor('SELECT COUNT(*) FROM pedidos WHERE fecha IS NULL');
        $hecho[] = "pedidos: " . ($sin - $quedan) . " pedido(s) sin fecha tomaron la de registro"
                 . ($quedan > 0 ? " · quedan $quedan con la fecha de registro ilegible" : '');
    }, 'la fecha de los pedidos viejos');

    /* «ninguno» dejó de ser algo que se pida (usuario, 2026-09-12: «no pedir
       nada es no necesita»), así que se limpia: si se quedara escrito, la
       ficha enseñaría «Pidió» con la palabra en blanco —comprobante_pide_texto()
       ya no lo conoce— y la cola de facturación lo ignoraría sin decir por qué.
       Solo puede haberlo en un HUB que alcanzó a instalar el 2j de la mañana. */
    $relleno(function () use (&$hecho) {
        if (!columna_existe('pedidos', 'comprobante_pide')) return;
        $st = q("UPDATE pedidos SET comprobante_pide = NULL WHERE comprobante_pide = 'ninguno'");
        if ($st->rowCount() > 0) {
            $hecho[] = 'pedidos: ' . $st->rowCount() . ' con «no necesita» pasaron a sin solicitud';
        }
    }, 'la limpieza de «no necesita»');

    /* ── Las columnas de texto que se ENSANCHAN ──────────────────────────
       Un VARCHAR que se queda corto no da error: MySQL recorta y sigue. El
       enlace de Google Maps cabía en 80 caracteres porque no viajaba a ninguna
       parte; desde que es obligatorio en Lima y va en el mensaje de despacho,
       uno pegado de la barra del navegador (150-200 caracteres) llegaba
       cortado al grupo y no abría nada. Se ensancha también en los HUB que ya
       existen, y con su propio try por lo mismo que todo lo demás de aquí. */
    foreach ([['pedidos', 'gps', 255], ['cliente_direcciones', 'gps', 255]] as [$t_a, $c_a, $n_a]) {
        try {
            if (columna_existe($t_a, $c_a) && columna_cabe_menos_que($t_a, $c_a, $n_a)) {
                bd()->exec("ALTER TABLE `$t_a` MODIFY `$c_a` VARCHAR($n_a) NULL");
                $hecho[] = "$t_a.$c_a ensanchada a $n_a";
            }
        } catch (Throwable $ex) {
            error_log('[HUB] ensanchar ' . $t_a . '.' . $c_a . ': ' . $ex->getMessage());
            $hecho[] = "NO se pudo ensanchar $t_a.$c_a. Los enlaces de Maps largos seguirán "
                     . 'llegando cortados al grupo de despacho.';
        }
    }

    /* ── Los ENUM que se ensanchan ───────────────────────────────────────
       Una columna que YA EXISTE pero admite menos valores que el código no da
       error de PHP: MySQL guarda la cadena vacía y sigue. Un pedido de
       liquidación habría quedado con el tipo en blanco, fuera de los tres
       filtros de la lista y contado como entrega inmediata en cuatro
       pantallas — y nadie se entera hasta que un asesor lo cuenta.

       VA AL FINAL Y CADA UNO EN SU PROPIO try. Escrito arriba, entre el bucle
       de columnas y los rellenos de «solo la primera vez», un ALTER que
       fallara —el usuario de la base sin permiso, la tabla ocupada— se llevaba
       por delante el resto de actualizar.php. Y eso no se arregla
       reintentando: el DDL de MySQL hace commit al vuelo, así que en la
       segunda pasada las columnas YA existen, $recien sale vacío y los
       rellenos no vuelven a correr NUNCA. El HUB se quedaba para siempre con
       Yape sin pedir voucher y con «Envío gratis Lima» ofreciéndose en Cusco.
       Lo encontró la auditoría del 2026-09-11.

       Un ENUM sin ensanchar es malo, pero es un tipo de venta que todavía no
       se puede ofrecer —tipos_de_venta_ofrecidos() lo esconde— y eso se
       aguanta. Perder los permisos y las listas, no. */
    foreach (enums_a_ampliar() as [$tabla_e, $col_e, $def_e]) {
        try {
            if (ampliar_enum($tabla_e, $col_e, $def_e)) $hecho[] = "$tabla_e.$col_e (valores nuevos)";
        } catch (Throwable $ex) {
            error_log('[HUB] ampliar enum ' . $tabla_e . '.' . $col_e . ': ' . $ex->getMessage());
            $hecho[] = "NO se pudo ensanchar $tabla_e.$col_e. El resto sí se aplicó, pero hasta "
                     . 'arreglarlo el HUB no ofrecerá los tipos de venta nuevos. '
                     . 'Lo más común es que al usuario de la base le falte ALTER.';
        }
    }

    /* Y se COMPRUEBA, en vez de dar por hecho que fue bien: ampliar_enum()
       devuelve false igual cuando no había nada que hacer que cuando no pudo
       leer el tipo de la columna, y «las columnas ya estaban todas» es un
       mensaje de éxito para un caso en que no se comprobó nada. */
    try {
        foreach (enums_a_ampliar() as [$tabla_e, $col_e, $def_e]) {
            $falta = enum_falta_admitir($tabla_e, $col_e, $def_e);
            if ($falta) {
                $hecho[] = "OJO: $tabla_e.$col_e todavía no admite " . implode(', ', $falta)
                         . '. Los tipos de venta nuevos no se ofrecerán hasta que se arregle.';
            }
        }
    } catch (Throwable $ex) {
        /* Comprobar no puede tumbar lo que ya se aplicó. Esto corre AL FINAL,
           así que una excepción aquí abortaría actualizar.php justo después de
           haberlo hecho todo bien — el mismo fallo que el orden de arriba
           existe para evitar. */
        error_log('[HUB] comprobar enums: ' . $ex->getMessage());
    }

    return $hecho;
}


/**
 * Arreglos que dependen de las SEMILLAS, no del esquema.
 *
 * migraciones_modulo2() corre ANTES de sembrar_todo() —tiene que hacerlo: añade
 * las columnas sobre las que luego se siembra—. Lo que necesita que los roles
 * nuevos ya existan tiene que correr después, o la primera actualización lo
 * salta en silencio y solo se arregla en la segunda, si es que la hay.
 *
 * La llaman instalar.php y actualizar.php, los dos justo detrás de
 * sembrar_todo(). Se puede pasar las veces que haga falta.
 */
function migraciones_tras_semillas(): array
{
    $hecho = [];

    /* Las semillas acaban de escribir rol_permiso. Cualquier respuesta que se
       hubiera cacheado antes en esta misma pasada es de antes de sembrar. */
    if (function_exists('permisos_del_rol')) permisos_del_rol(0, true);

    /* ── El asesor ya no abre los lotes enteros (3h) ──────────────────────
       Tenía «lotes.ver» desde el módulo 2, cuando no había pantalla. Desde la
       3h los lotes llevan BL, factura y lo que todavía no está a la venta: el
       asesor ve «Disponible para pre venta» (preventa.ver). Una sola vez. */
    if (tabla_existe('rol_permiso') && tabla_existe('ajustes') && (string) ajuste('preventa_permiso_asesor', '') !== '1') {
        $ra = (int) valor("SELECT id FROM roles WHERE clave = 'asesor'");
        $pl = (int) valor("SELECT id FROM permisos WHERE clave = 'lotes.ver'");
        if ($ra && $pl) q('DELETE FROM rol_permiso WHERE rol_id = ? AND permiso_id = ?', [$ra, $pl]);
        guardar_ajuste_tecnico('preventa_permiso_asesor', '1', 'El asesor ve lo disponible, no los lotes enteros');
        ajustes_olvidar();
        if (function_exists('permisos_del_rol')) permisos_del_rol(0, true);
    }

    /* ── 3i · la garantía cuenta desde el despacho ──────────────────────
       Los MESES de cada garantía, como número, sacados del texto la primera
       vez («12 meses» → 12, «No aplica» → 0). Después manda Configuración. */
    if (tabla_existe('lista_items') && columna_existe('lista_items', 'meses') && function_exists('garantia_meses_de_texto')) {
        try {
            $lista_g = (int) valor("SELECT id FROM listas WHERE clave = 'garantias'");
            $n_m = 0;
            if ($lista_g) {
                foreach (todas('SELECT id, valor FROM lista_items WHERE lista_id = ? AND meses IS NULL', [$lista_g]) as $gi) {
                    $mm = garantia_meses_de_texto((string)$gi['valor']);
                    if ($mm === null) continue;
                    q('UPDATE lista_items SET meses = ? WHERE id = ? AND meses IS NULL', [$mm, (int)$gi['id']]);
                    $n_m++;
                }
            }
            if ($n_m) $hecho[] = "garantías: los meses de $n_m garantía(s) quedan como número (se cambian en Configuración › Garantías)";
        } catch (Throwable $ex) { error_log('[HUB] meses de garantía: ' . $ex->getMessage()); }
    }
    /* La PRIMERA salida a despacho de lo que ya salió: la del primer evento
       «Se mandó a despacho», o la de despacho_en si no hay evento. */
    if (tabla_existe('pedidos') && columna_existe('pedidos', 'despacho_primero_en')) {
        try {
            $n_d = q("UPDATE pedidos SET despacho_primero_en = COALESCE(
                          (SELECT MIN(ev.creado_en) FROM pedido_eventos ev WHERE ev.pedido_id = pedidos.id AND ev.tipo = 'despacho'),
                          despacho_en)
                      WHERE despacho_primero_en IS NULL AND despacho_veces > 0")->rowCount();
            if ($n_d) $hecho[] = "$n_d pedido(s) ya despachados: su garantía cuenta desde la primera salida";
        } catch (Throwable $ex) { error_log('[HUB] primer despacho: ' . $ex->getMessage()); }
    }
    /* El día en que el HUB empezó a contar así. Una venta de antes, sin fecha
       de despacho, cuenta desde el día de la venta (tarjeta del 2026-09-28). */
    if (tabla_existe('ajustes') && function_exists('guardar_ajuste_tecnico') && (string) ajuste('garantia_arranque', '') === '') {
        guardar_ajuste_tecnico('garantia_arranque', date('Y-m-d H:i:s'),
                               'Desde cuándo la garantía cuenta desde el despacho. Lo de antes, desde la venta. No tocar.');
        ajustes_olvidar();
    }

    /* ── Lo que ya había salido no se alista (3g) ── */
    if (function_exists('alistado_arranque')) {
        try { $m = alistado_arranque(); if ($m !== '') $hecho[] = $m; }
        catch (Throwable $ex) { error_log('[HUB] alistado arranque: ' . $ex->getMessage()); }
    }
    /* ── Lo entregado antes de la 3j y los estados que mueve el HUB ── */
    /* ── Módulo 5: los siete bonos, APAGADOS, en cada país ── */
    if (function_exists('bonos_sembrar')) {
        try { $m = bonos_sembrar(); if ($m !== '') $hecho[] = $m; }
        catch (Throwable $ex) { error_log('[HUB] bonos sembrar: ' . $ex->getMessage()); }
    }
    if (function_exists('entrega_arranque')) {
        try { $m = entrega_arranque(); if ($m !== '') $hecho[] = $m; }
        catch (Throwable $ex) { error_log('[HUB] entrega arranque: ' . $ex->getMessage()); }
    }

    /* ── «tienda.escribir» se parte en tres (3g) ─────────────────────────
       Almacén pone el stock (stock.ajustar) y Marketing los datos
       (tienda.datos); lo que las ventas no pudieron mover lo revisa
       Administración (tienda.cola). Quien tenía el permiso viejo se queda con
       los dos que no son el stock: el stock ya pedía también stock.ajustar, y
       dárselo aquí sería ensanchar. Después el viejo se borra, para que un HUB
       actualizado quede igual que uno nuevo. */
    if (tabla_existe('permisos') && tabla_existe('rol_permiso')) {
        $viejo = (int) valor("SELECT id FROM permisos WHERE clave = 'tienda.escribir'");
        if ($viejo) {
            $nuevos = array_map('intval', array_column(todas(
                "SELECT id FROM permisos WHERE clave IN ('tienda.datos', 'tienda.cola')"), 'id'));
            foreach (todas('SELECT rol_id FROM rol_permiso WHERE permiso_id = ?', [$viejo]) as $rp) {
                foreach ($nuevos as $pid) sembrar_par('rol_permiso', ['rol_id' => (int)$rp['rol_id'], 'permiso_id' => $pid]);
            }
            q('DELETE FROM rol_permiso WHERE permiso_id = ?', [$viejo]);
            q('DELETE FROM permisos WHERE id = ?', [$viejo]);
            if (function_exists('permisos_del_rol')) permisos_del_rol(0, true);
            $hecho[] = 'el permiso de cambiar la tienda web se reparte entre Almacén (stock) y Marketing (datos)';
        }
    }

    /* ── La línea del flete en el mensaje a provincia ────────────────────
       Decía SIEMPRE «el flete lo paga el cliente en destino». Desde que el
       flete puede ir DENTRO del total, ese mensaje le pide al cliente que lo
       pague dos veces: una en el «Cobrar» y otra en la agencia. Ahora la frase
       la decide {nota_flete} según el pedido.

       Las plantillas ya sembradas NO se resiembran —sembrar_plantillas() salta
       las que existen, y con razón: Administración las edita—. Así que un HUB
       actualizado se habría quedado con el cobro doble mientras uno nuevo
       salía bien: el mismo tipo de divergencia que ya obligó a escribir
       correr4.php. Se cambia SOLO esa línea, respetando lo demás que hayan
       editado, y solo si sigue estando tal cual. */
    /* La carpeta de vouchers, cerrada. En un HUB ya instalado la carpeta existe
       desde la primera subida y nunca tuvo su .htaccess. */
    if (defined('HUB_SUBIDAS') && function_exists('voucher_carpeta_cerrada')) {
        $dirv = HUB_SUBIDAS . '/vouchers';
        if (!is_dir($dirv)) @mkdir($dirv, 0755, true);
        if (!is_file($dirv . '/.htaccess')) {
            voucher_carpeta_cerrada($dirv);
            $hecho[] = 'la carpeta de vouchers queda cerrada al web';
        }
    }

    if (tabla_existe('plantillas_whatsapp')) {
        $vieja = '📦 El flete lo paga el cliente en destino';
        $fila = una('SELECT id, texto FROM plantillas_whatsapp WHERE clave = ?', ['venta_provincia']);
        if ($fila && str_contains((string)$fila['texto'], $vieja)) {
            actualizar('plantillas_whatsapp', (int)$fila['id'], [
                'texto' => str_replace($vieja, '{nota_flete}', (string)$fila['texto']),
            ]);
            $hecho[] = 'mensaje a provincia: la línea del flete ya no se contradice con el total';
        }

        /* Y LIMA LA NECESITA MÁS QUE PROVINCIA: «Envío con costo en Lima» es
           el caso que este parche inventó, y su mensaje salía con un «Cobrar»
           inflado sin una línea que dijera que ahí dentro va el envío. La
           plantilla de Lima no tenía dónde ponerlo. */
        $filaL = una('SELECT id, texto FROM plantillas_whatsapp WHERE clave = ?', ['venta_lima']);
        if ($filaL && !str_contains((string)$filaL['texto'], '{nota_flete}')
                   && str_contains((string)$filaL['texto'], 'Cobrar {saldo}')) {
            actualizar('plantillas_whatsapp', (int)$filaL['id'], [
                'texto' => str_replace('Cobrar {saldo}', "Cobrar {saldo}\n{nota_flete}",
                                       (string)$filaL['texto']),
            ]);
            $hecho[] = 'mensaje de Lima: dice cuánto del cobro es envío';
        }

        /* Despacho tiene que saber si el cliente PASA por la agencia o si la
           agencia se lo lleva a casa: son dos trabajos distintos y el mensaje
           traía lo mismo en los dos casos —agencia y sucursal—. Se mete la
           línea justo encima de «Recibe», donde estaba el hueco. Las
           plantillas ya sembradas no se resiembran, así que sin esto un HUB
           actualizado se quedaría sin la línea mientras uno nuevo la trae. */
        $filaD = una('SELECT id, texto FROM plantillas_whatsapp WHERE clave = ?', ['despacho']);
        if ($filaD && !str_contains((string)$filaD['texto'], '{como_recibe}')
                   && str_contains((string)$filaD['texto'], '✅ Recibe: {recibe}')) {
            actualizar('plantillas_whatsapp', (int)$filaD['id'], [
                'texto' => str_replace('✅ Recibe: {recibe}',
                                       "✅ Cómo lo recibe: {como_recibe}\n✅ Recibe: {recibe}",
                                       (string)$filaD['texto']),
            ]);
            $hecho[] = 'mensaje de despacho: dice si el cliente recoge en la agencia '
                     . 'o si la agencia se lo lleva';
        }

        /* ── El mapa en el mensaje de despacho ────────────────────────────
           El enlace se pedía en el formulario desde el principio y no viajaba
           a ninguna parte: quien reparte recibía la dirección escrita a mano y
           nada más. Las plantillas ya sembradas no se resiembran, así que sin
           esto un HUB actualizado se quedaría sin la línea. */
        $filaM = una('SELECT id, texto FROM plantillas_whatsapp WHERE clave = ?', ['despacho']);
        if ($filaM && !str_contains((string)$filaM['texto'], '{mapa}')
                   && str_contains((string)$filaM['texto'], '✅ Distrito: {distrito}')) {
            actualizar('plantillas_whatsapp', (int)$filaM['id'], [
                'texto' => str_replace('✅ Distrito: {distrito}',
                                       "✅ Distrito: {distrito}\n📍 Mapa: {mapa}",
                                       (string)$filaM['texto']),
            ]);
            $hecho[] = 'mensaje de despacho: ahora lleva el enlace del mapa';
        }

        /* ── PARCHE 2R · DESPACHO SE PARTE EN DOS MENSAJES ───────────────
           «En provincia no va ubicación GPS; distrito, provincia y departamento
           van en una sola línea; que no salga el precio del producto puede
           confundir a despacho; en provincia no hay contraentrega» (usuario,
           2026-09-20). Eso no es la misma plantilla con líneas vacías, así que
           provincia tiene la suya. Las sembradas no se resiembran, de modo que
           aquí se crea si falta y se deja en paz si ya está. */
        $hay_prov = una('SELECT id FROM plantillas_whatsapp WHERE clave = ?', ['despacho_provincia']);
        if (!$hay_prov) {
            insertar('plantillas_whatsapp', [
                'clave'  => 'despacho_provincia',
                'nombre' => 'Al grupo de DESPACHO · Provincia',
                'texto'  => "📦 DESPACHO A PROVINCIA · {codigo}\n"
                          . "✅ Nombre: {cliente}\n"
                          . "✅ Número de contacto: {celular}\n"
                          . "✅ {tipo_doc}: {documento}\n"
                          . "✅ Destino: {destino_linea}\n"
                          . "✅ Producto:\n{productos}\n"
                          . "✅ Modelo/color: {modelos}\n"
                          . "✅ Agencia: {agencia} - {sucursal}\n"
                          . "✅ Cómo lo recibe: {como_recibe}\n"
                          . "✅ Nota: {nota}\n"
                          . "✅ Comprobante: {comprobante}\n"
                          . "✅ Asesor: {asesor}\n"
                          . "✅ Fecha: {fecha}",
                'activo' => 1,
            ]);
            $hecho[] = 'mensaje de despacho a provincia: destino en una línea, sin mapa y sin precios';
        }

        /* Y el de Lima deja de llevar el precio de cada producto: {productos}
           es la misma lista sin importes. Solo si sigue con la línea tal cual. */
        $filaP = una('SELECT id, texto FROM plantillas_whatsapp WHERE clave = ?', ['despacho']);
        if ($filaP && str_contains((string)$filaP['texto'], "✅ Producto:\n{detalle}")) {
            actualizar('plantillas_whatsapp', (int)$filaP['id'], [
                'texto' => str_replace("✅ Producto:\n{detalle}",
                                       "✅ Producto:\n{productos}\n✅ Modelo/color: {modelos}",
                                       (string)$filaP['texto']),
            ]);
            $hecho[] = 'mensaje de despacho: los productos van sin precio y con su modelo o color';
        }

        /* ── La garantía en los tres mensajes de venta ────────────────────
           Misma historia que las dos de arriba: las plantillas ya sembradas no
           se resiembran —Administración las edita—, así que un HUB actualizado
           se habría quedado sin la línea mientras uno nuevo la trae, y la
           garantía es justo lo que el cliente reclama seis meses después.

           Se mete ENCIMA de la línea que ya existe en cada plantilla, y solo
           si esa línea sigue tal cual: una plantilla reescrita a mano se deja
           en paz. Si la variable sale vacía —los pedidos de antes de esta
           versión— la línea entera se cae sola, que es la regla de siempre. */
        foreach ([
            ['venta_lima',      '✅ Detalle: {nota}'],
            ['venta_provincia', '✅ Detalle: {nota}'],
            ['venta_preventa',  '✅ Total: {total}'],
        ] as [$clave_pl, $ancla]) {
            $filaG = una('SELECT id, texto FROM plantillas_whatsapp WHERE clave = ?', [$clave_pl]);
            if (!$filaG) continue;
            if (str_contains((string)$filaG['texto'], '{garantia}')) continue;
            if (!str_contains((string)$filaG['texto'], $ancla)) continue;
            actualizar('plantillas_whatsapp', (int)$filaG['id'], [
                'texto' => str_replace($ancla, "✅ Garantía: {garantia}\n" . $ancla,
                                       (string)$filaG['texto']),
            ]);
            /* Con llaves. PHP admite bytes ≥ 0x80 dentro de un nombre de
             variable, así que en «$clave_pl» el parser se comía el » y buscaba
             una variable $clave_pl» que no existe: tres warnings en el log de
             cPanel y tres líneas idénticas e inútiles en la pantalla, justo en
             la pasada más delicada. */
            $hecho[] = "mensaje «{$clave_pl}»: dice la garantía que se prometió";
        }
    }

    /* ── La garantía que le toca a la liquidación ────────────────────────
       sembrar_listas() escribe `extra` SOLO al crear el item: si «No aplica» ya
       existía —de una versión anterior, o recreado desde Configuración— se
       queda sin la marca, y entonces garantia_por_defecto('liquidacion')
       devuelve el primero de la lista, que son doce meses. Waka respondería un
       año por un saldo de almacén, y el mensaje al grupo lo mandaría por
       escrito.

       Solo rellena lo que está VACÍO: si alguien le puso otra marca a mano,
       manda la suya. */
    if (tabla_existe('lista_items') && columna_existe('lista_items', 'extra')) {
        $lista_g = valor("SELECT id FROM listas WHERE clave = 'garantias'");
        if ($lista_g) {
            $st = q("UPDATE lista_items SET extra = 'liquidacion'
                      WHERE lista_id = ? AND valor = 'No aplica'
                        AND (extra IS NULL OR extra = '')", [$lista_g]);
            if ($st->rowCount() > 0) {
                $hecho[] = 'garantías: «No aplica» queda marcada como la de liquidación';
            }
        }

        /* Y la marca de «esto es efectivo», que enciende la cuenta del vuelto.
           Mismo motivo: sembrar_listas() solo escribe `extra` al CREAR el item,
           así que en un HUB donde «Efectivo» ya existía se quedaría sin marca y
           el vuelto no saldría nunca. */
        $lista_m = valor("SELECT id FROM listas WHERE clave = 'metodos_pago'");
        if ($lista_m) {
            $st = q("UPDATE lista_items SET extra = 'efectivo'
                      WHERE lista_id = ? AND valor = 'Efectivo'
                        AND (extra IS NULL OR extra = '')", [$lista_m]);
            if ($st->rowCount() > 0) {
                $hecho[] = 'métodos de pago: «Efectivo» queda marcado (enciende el cálculo del vuelto)';
            }
        }
    }

    /* Los roles que confirman el dinero de TODOS tienen que VER a todos.
       Una cuenta de Facturación con el ámbito estrecho —creada antes de que el
       alta lo fijara, o cambiada a mano— confirma un pago y recibe 403 al abrir
       el voucher de ese mismo pago: confirma dinero a ciegas. Aquí se arreglan
       las que ya existan; el alta se encarga de las nuevas.

       Solo sube ámbitos, nunca los baja, y solo en los roles que lo exigen: a
       nadie se le ensancha lo que ve por actualizar. */
    if (tabla_existe('usuarios') && tabla_existe('roles') && function_exists('ambito_forzado_de_rol')) {
        try {
            $arreglados = 0;
            foreach (todas('SELECT id, clave FROM roles') as $r) {
                if (ambito_forzado_de_rol((int)$r['id']) !== 'todo') continue;
                $st = q("UPDATE usuarios SET ambito = 'todo'
                          WHERE rol_id = ? AND ambito <> 'todo'", [(int)$r['id']]);
                $arreglados += $st->rowCount();
            }
            if ($arreglados) {
                $hecho[] = "$arreglados cuenta(s) pasan a ver todo su país: su trabajo lo pide "
                         . '(confirmar pagos, o un rol que no vende y no tiene nada «suyo»)';
            }
        } catch (Throwable $ex) {
            error_log('[HUB] migracion ambito facturacion: ' . $ex->getMessage());
            $hecho[] = 'no se pudo revisar el ámbito de las cuentas que confirman pagos.';
        }
    }

    /* ── El padrón entero del Perú ───────────────────────────────────────
       El HUB arrancó con 79 distritos: Lima, Callao y Arequipa. Fuera de ahí
       el distrito no existía, y el distrito es obligatorio en todo envío: una
       venta a Cusco, a Piura o a Trujillo NO SE PODÍA REGISTRAR. La pantalla
       prometía «si tu distrito no está, se añade desde el mismo buscador» y
       eso nunca se construyó. Ahora están los 1.893.

       sembrar_ubigeo() solo siembra con la tabla vacía —y con razón: una
       resiembra no puede deshacer lo que alguien añadió a mano—, así que un
       HUB ya instalado se habría quedado en 79 mientras una instalación nueva
       tenía 1.893. Esa es exactamente la divergencia que correr4.php vigila.

       LA MARCA SE ESCRIBE PRIMERO, y dentro de la misma transacción que la
       siembra. Es el candado: si dos personas abren actualizar.php a la vez,
       la segunda choca contra la clave primaria de `ajustes` y su transacción
       entera se deshace, en vez de sembrar el país por segunda vez. Y como la
       marca viaja con la siembra, no puede quedar puesta con el padrón a
       medias: o entran las dos cosas, o no entra ninguna. */
    if (tabla_existe('ubigeo') && tabla_existe('ajustes') && function_exists('ubigeo_sembrar_padron')) {
        try {
            /* Se pregunta si la FILA existe, no por su valor: valor() devuelve
               null tanto si no hay fila como si la hay vacía. */
            $hay = (int) valor("SELECT COUNT(*) FROM ajustes WHERE clave = 'ubigeo_padron_v2'");
            if ($hay === 0) {
                $pais = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
                if (!$pais) {
                    /* Sin país no se siembra NADA, y la marca tampoco se pone:
                       darla por hecha aquí dejaba un HUB con cero distritos
                       para siempre, porque actualizar.php no lo reintentaría
                       nunca y en pantalla decía que había ido bien. */
                    $hecho[] = 'no se pudo cargar el padrón del Perú: falta el país PE. '
                             . 'Vuelve a pasar actualizar.php.';
                } else {
                    $r = en_transaccion(function () use ($pais) {
                        q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
                           VALUES ('ubigeo_padron_v2', '1', 'booleano', 'general',
                                   'Marca de que el padrón completo del Perú ya está cargado. No tocar.', 1)");
                        return ubigeo_sembrar_padron($pais);
                    });

                    /* Y se dice lo que de verdad pasó, no lo que se esperaba. */
                    $hecho[] = $r['puestos'] > 0
                        ? "el padrón completo del Perú: {$r['puestos']} sitios añadidos "
                        . '(ya se puede vender a cualquier distrito del país)'
                        : 'el padrón del Perú ya estaba completo, no hubo nada que añadir';
                    if ($r['corregidos'] > 0) {
                        $hecho[] = "{$r['corregidos']} nombre(s) de sitio corregidos "
                                 . '(por ejemplo «Villa el Salvador», que iba con la ele minúscula)';
                    }
                }
            }
        } catch (Throwable $ex) {
            /* Que otra pasada se nos adelantara no es un fallo: es el candado
               haciendo su trabajo. Pero eso NO se decide por el código de
               error: el 23000 de MySQL es también el choque del único del
               propio ubigeo y la clave foránea rota. Callarse por el código
               dejaba el HUB sin un solo distrito, con actualizar.php diciendo
               que todo fue bien y sin una línea en el log. Se decide por el
               hecho: si la marca está puesta, alguien lo hizo; si no, falló y
               hay que decirlo. */
            $la_puso_otro = es_choque_de_unico($ex)
                && (int) valor("SELECT COUNT(*) FROM ajustes WHERE clave = 'ubigeo_padron_v2'") > 0;
            if (!$la_puso_otro) {
                error_log('[HUB] migracion ubigeo padron: ' . $ex->getMessage());
                $hecho[] = 'NO se pudo cargar el padrón completo del Perú: ' . $ex->getMessage()
                         . '. El resto sí se aplicó — vuelve a pasar actualizar.php.';
            }
        }
    }

    /* ── Las capitales de los distritos ──────────────────────────────────
       357 de los 1.893 distritos tienen una capital que se llama distinto, y
       la gente habla con el nombre de la capital: nadie dice «mándalo a
       Chanchamayo», dicen «a La Merced». Sin esto el buscador no la encuentra
       y el asesor concluye que su distrito no existe. Lo encontró el usuario
       buscando La Merced.

       Marca propia porque el padrón ya se cargó en la pasada anterior: la de
       aquel no vuelve a correr, y reutilizarla dejaría las capitales fuera
       para siempre en el HUB que ya está en producción. */
    if (tabla_existe('ubigeo') && tabla_existe('ajustes')
        && columna_existe('ubigeo', 'busca_capital') && function_exists('ubigeo_sembrar_padron')) {
        try {
            $hay = (int) valor("SELECT COUNT(*) FROM ajustes WHERE clave = 'ubigeo_capitales_v1'");
            if ($hay === 0) {
                $pais = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
                if (!$pais) {
                    $hecho[] = 'no se pudieron cargar las capitales de los distritos: falta el país PE.';
                } else {
                    $r = en_transaccion(function () use ($pais) {
                        q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
                           VALUES ('ubigeo_capitales_v1', '1', 'booleano', 'general',
                                   'Marca de que las capitales de los distritos ya están cargadas. No tocar.', 1)");
                        return ubigeo_sembrar_padron($pais);
                    });
                    $tocados = (int) valor('SELECT COUNT(*) FROM ubigeo WHERE busca_capital IS NOT NULL');
                    $hecho[] = $tocados > 0
                        ? "las capitales de $tocados distritos: ahora «La Merced» encuentra Chanchamayo"
                        : 'no se pudo escribir ninguna capital de distrito';
                }
            }
        } catch (Throwable $ex) {
            $otro = es_choque_de_unico($ex)
                 && (int) valor("SELECT COUNT(*) FROM ajustes WHERE clave = 'ubigeo_capitales_v1'") > 0;
            if (!$otro) {
                error_log('[HUB] migracion ubigeo capitales: ' . $ex->getMessage());
                $hecho[] = 'NO se pudieron cargar las capitales de los distritos: ' . $ex->getMessage();
            }
        }
    }

    return $hecho;
}
