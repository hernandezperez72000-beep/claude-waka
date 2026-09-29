<?php
declare(strict_types=1);

/** Segunda mitad del esquema: el negocio. Clientes, catálogo, pedidos,
 *  cashback, bonos y recepción. */
function esquema_sql_negocio(): array
{
    $m = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $t = [];

    /* ─────────────  CLIENTES  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS clientes (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id       SMALLINT UNSIGNED NOT NULL,
        oficina_id    INT UNSIGNED NULL,
        asesor_id     INT UNSIGNED NULL,
        tipo_doc      ENUM('DNI','RUC','CE','PAS') NOT NULL DEFAULT 'DNI',
        documento     VARCHAR(20) NOT NULL,
        nombre        VARCHAR(140) NOT NULL,
        razon_social  VARCHAR(160) NULL,
                ruc_factura   VARCHAR(15)  NULL,
        direccion_fiscal VARCHAR(160) NULL,
        email         VARCHAR(190) NULL,
        celular       VARCHAR(25)  NULL,
        canal_item_id INT UNSIGNED NULL COMMENT 'cómo nos conoció',
        tipo_comprobante ENUM('boleta','factura') NOT NULL DEFAULT 'boleta',
        cuenta_tienda TINYINT(1) NOT NULL DEFAULT 0,
        activo        TINYINT(1) NOT NULL DEFAULT 1,
        creado_por    INT UNSIGNED NULL,
        creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cliente_doc (pais_id, documento),
        KEY ix_cliente_asesor (asesor_id, activo),
        KEY ix_cliente_nombre (nombre),
        KEY ix_cliente_celular (celular),
        CONSTRAINT fk_cliente_pais   FOREIGN KEY (pais_id)   REFERENCES paises(id),
        CONSTRAINT fk_cliente_asesor FOREIGN KEY (asesor_id) REFERENCES usuarios(id)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS cliente_direcciones (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cliente_id   INT UNSIGNED NOT NULL,
        etiqueta     VARCHAR(40) NOT NULL DEFAULT 'Principal',
        direccion    VARCHAR(220) NOT NULL,
        referencia   VARCHAR(200) NULL,
        distrito     VARCHAR(80) NULL,
        provincia    VARCHAR(80) NULL,
        departamento VARCHAR(80) NULL,
        recibe       VARCHAR(120) NULL,
        principal    TINYINT(1) NOT NULL DEFAULT 0,
        activo       TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY ix_dir_cliente (cliente_id, activo),
        CONSTRAINT fk_dir_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS cliente_notas (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cliente_id INT UNSIGNED NOT NULL,
        usuario_id INT UNSIGNED NULL,
        texto      VARCHAR(500) NOT NULL,
        creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_nota_cliente (cliente_id, creado_en),
        CONSTRAINT fk_nota_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    ) $m";

    /* ─────────────  CATÁLOGO Y STOCK  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS productos (
        id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        sku       VARCHAR(60) NOT NULL,
        nombre    VARCHAR(180) NOT NULL,
        categoria VARCHAR(80) NULL,
        pais_id   SMALLINT UNSIGNED NULL COMMENT 'NULL = vale para todos los países',
        garantia_item_id INT UNSIGNED NULL COMMENT 'la garantía que hereda la venta',
        woo_id    BIGINT UNSIGNED NULL COMMENT 'id en la tienda, si existe allá',
        foto      VARCHAR(80) NULL COMMENT 'la miniatura, en uploads/productos',
        foto_woo  BIGINT UNSIGNED NULL COMMENT 'de qué foto de la tienda salió',
        activo    TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_producto_sku (sku),
        KEY ix_producto_nombre (nombre),
        KEY ix_producto_woo (woo_id)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS variantes (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        producto_id   INT UNSIGNED NOT NULL,
        sku           VARCHAR(60) NOT NULL,
        color         VARCHAR(60) NULL,
        medida        VARCHAR(60) NULL,
        woo_id        BIGINT UNSIGNED NULL,
        activo        TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_variante_sku (sku),
        KEY ix_variante_producto (producto_id, activo),
        CONSTRAINT fk_variante_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS stock (
        variante_id  INT UNSIGNED NOT NULL,
        oficina_id   INT UNSIGNED NOT NULL,
        origen       ENUM('tienda','preventa','liquidacion') NOT NULL DEFAULT 'tienda',
        cantidad     INT NOT NULL DEFAULT 0,
        reservado    INT NOT NULL DEFAULT 0,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (variante_id, oficina_id, origen),
        KEY ix_stock_oficina (oficina_id, origen)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS stock_movimientos (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        variante_id    INT UNSIGNED NOT NULL,
        oficina_id     INT UNSIGNED NOT NULL,
        origen         ENUM('tienda','preventa','liquidacion') NOT NULL DEFAULT 'tienda',
        tipo           ENUM('entrada','salida','reserva','libera','ajuste','devolucion') NOT NULL,
        cantidad       INT NOT NULL,
        referencia_tipo VARCHAR(20) NULL,
        referencia_id  BIGINT UNSIGNED NULL,
        motivo         VARCHAR(200) NULL,
        usuario_id     INT UNSIGNED NULL,
        creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_smov_variante (variante_id, creado_en),
        KEY ix_smov_ref (referencia_tipo, referencia_id)
    ) $m";

    /* ─────────────  LOTES DE IMPORTACIÓN  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS lotes (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id            SMALLINT UNSIGNED NOT NULL,
        codigo             VARCHAR(30) NOT NULL,
        nombre             VARCHAR(140) NULL,
        proveedor          VARCHAR(140) NULL,
        estado             ENUM('borrador','en_camino','en_aduana','recibido','cancelado') NOT NULL DEFAULT 'borrador',
        fecha_salida       DATE NULL,
        fecha_llegada_est  DATE NULL,
        fecha_llegada_real DATE NULL,
        archivo_origen     VARCHAR(160) NULL,
        productos_leidos   INT NOT NULL DEFAULT 0,
        variantes_creadas  INT NOT NULL DEFAULT 0,
        unidades_totales   INT NOT NULL DEFAULT 0,
        filas_con_aviso    INT NOT NULL DEFAULT 0,
        precios_listos     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'sin precios nadie puede vender',
        disponible         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'el interruptor de venta',
        creado_por         INT UNSIGNED NULL,
        creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_lote_codigo (codigo),
        KEY ix_lote_estado (pais_id, estado, fecha_llegada_est)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS lote_lineas (
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        lote_id             INT UNSIGNED NOT NULL,
        variante_id         INT UNSIGNED NULL COMMENT 'NULL mientras la fila esté sin repartir',
        producto_id         INT UNSIGNED NULL,
        texto_origen        VARCHAR(300) NULL COMMENT 'lo que decía el Excel, tal cual',
        unidades            INT NOT NULL DEFAULT 0,
        unidades_repartidas INT NOT NULL DEFAULT 0,
        aviso               VARCHAR(200) NULL,
        resuelto            TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY ix_lotelinea_lote (lote_id, resuelto),
        CONSTRAINT fk_lotelinea_lote FOREIGN KEY (lote_id) REFERENCES lotes(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS precios (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        producto_id      INT UNSIGNED NOT NULL,
        variante_id      INT UNSIGNED NULL COMMENT 'NULL = vale para todas las variantes',
        lote_id          INT UNSIGNED NULL COMMENT 'NULL = precio de tienda',
        desde            INT NOT NULL DEFAULT 1,
        hasta            INT NULL,
        alias            VARCHAR(60) NULL,
        precio_centimos  BIGINT NOT NULL,
        activo           TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY ix_precio_busca (producto_id, lote_id, activo, desde),
        CONSTRAINT fk_precio_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) $m";

    /* ─────────────  PEDIDOS  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS pedido_estados (
        id       TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clave    VARCHAR(30) NOT NULL,
        nombre   VARCHAR(60) NOT NULL,
        orden    TINYINT NOT NULL DEFAULT 0,
        es_final TINYINT(1) NOT NULL DEFAULT 0,
        dispara  VARCHAR(40) NULL COMMENT 'lógica fija: no se puede borrar este estado',
        color    VARCHAR(20) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pedestado_clave (clave)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS pedidos (
        id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
        codigo                  VARCHAR(20) NOT NULL,
        pais_id                 SMALLINT UNSIGNED NOT NULL,
        oficina_id              INT UNSIGNED NULL,
        cliente_id              INT UNSIGNED NOT NULL,
        asesor_id               INT UNSIGNED NOT NULL,
        equipo_id               INT UNSIGNED NULL COMMENT 'el que tenía el asesor al vender',
        tipo                    ENUM('inmediata','preventa','liquidacion') NOT NULL DEFAULT 'inmediata',
        lote_id                 INT UNSIGNED NULL,
        estado_id               TINYINT UNSIGNED NOT NULL,
        subtotal_centimos       BIGINT NOT NULL DEFAULT 0,
        descuento_centimos      BIGINT NOT NULL DEFAULT 0,
        cashback_usado_centimos BIGINT NOT NULL DEFAULT 0,
        total_centimos          BIGINT NOT NULL DEFAULT 0,
        cobrado_centimos        BIGINT NOT NULL DEFAULT 0,
        canal_item_id           INT UNSIGNED NULL,
        entrega                 ENUM('recojo','envio') NOT NULL DEFAULT 'recojo',
        agencia_item_id         INT UNSIGNED NULL,
        guia                    VARCHAR(60) NULL,
        direccion_id            INT UNSIGNED NULL,
        nota                    VARCHAR(300) NULL,
        anulado_motivo_item_id  INT UNSIGNED NULL,
        anulado_nota            VARCHAR(300) NULL,
        anulado_por             INT UNSIGNED NULL,
        anulado_en              DATETIME NULL,
        por_devolver_centimos   BIGINT NOT NULL DEFAULT 0,
        creado_en               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pedido_codigo (codigo),
        KEY ix_pedido_asesor (asesor_id, creado_en),
        KEY ix_pedido_equipo (equipo_id, creado_en),
        KEY ix_pedido_cliente (cliente_id, creado_en),
        KEY ix_pedido_pais (pais_id, creado_en),
        KEY ix_pedido_estado (estado_id),
        KEY ix_pedido_lote (lote_id),
        KEY ix_pedido_tipo (tipo, creado_en),
        CONSTRAINT fk_pedido_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
        CONSTRAINT fk_pedido_asesor  FOREIGN KEY (asesor_id)  REFERENCES usuarios(id),
        CONSTRAINT fk_pedido_estado  FOREIGN KEY (estado_id)  REFERENCES pedido_estados(id)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS pedido_lineas (
        id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pedido_id             INT UNSIGNED NOT NULL,
        producto_id           INT UNSIGNED NULL,
        variante_id           INT UNSIGNED NULL,
        fuera_catalogo        TINYINT(1) NOT NULL DEFAULT 0,
        descripcion           VARCHAR(220) NOT NULL,
        sku                   VARCHAR(60) NULL,
        /* Modelo y color de lo que se vendió, escrito por el asesor. */
        modelo                VARCHAR(120) NOT NULL DEFAULT '',
        origen                ENUM('tienda','preventa','liquidacion') NOT NULL DEFAULT 'tienda',
        cantidad              INT NOT NULL DEFAULT 1,
        precio_unit_centimos  BIGINT NOT NULL DEFAULT 0,
        total_centimos        BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY ix_linea_pedido (pedido_id),
        KEY ix_linea_variante (variante_id),
        CONSTRAINT fk_linea_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS pedido_eventos (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pedido_id  INT UNSIGNED NOT NULL,
        usuario_id INT UNSIGNED NULL,
        tipo       VARCHAR(40) NOT NULL,
        texto      VARCHAR(300) NOT NULL,
        creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_evento_pedido (pedido_id, creado_en),
        CONSTRAINT fk_evento_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS pagos (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pedido_id         INT UNSIGNED NOT NULL,
        metodo_item_id    INT UNSIGNED NULL,
        monto_centimos    BIGINT NOT NULL,
        operacion         VARCHAR(60) NULL,
        fecha             DATE NOT NULL,
                voucher           VARCHAR(120) NULL,
        concepto          VARCHAR(80) NOT NULL DEFAULT '',
        verificado        TINYINT(1) NOT NULL DEFAULT 0,
        verificado_por    INT UNSIGNED NULL,
        verificado_en     DATETIME NULL,
        registrado_por    INT UNSIGNED NULL,
        anulado           TINYINT(1) NOT NULL DEFAULT 0,
        creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_pago_pedido (pedido_id, creado_en),
        KEY ix_pago_verificado (verificado, creado_en),
        CONSTRAINT fk_pago_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
    ) $m";

    /* ─────────────  CASHBACK  ─────────────
       Es un libro de movimientos, no un saldo guardado. Cada línea suma o
       resta; el saldo es la suma. Nada se borra: una anulación entra como
       línea negativa. El cashback nace del PAGO, no del pedido. */

    $t[] = "CREATE TABLE IF NOT EXISTS cashback_movimientos (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cliente_id        INT UNSIGNED NOT NULL,
        tipo              ENUM('acredita','usa','vence','ajuste','revierte') NOT NULL,
        monto_centimos    BIGINT NOT NULL COMMENT 'positivo suma, negativo resta',
        pago_id           INT UNSIGNED NULL COMMENT 'el pago que lo originó',
        pedido_id         INT UNSIGNED NULL,
        vence_en          DATE NULL COMMENT 'se guarda al nacer y no cambia después',
        consumido_centimos BIGINT NOT NULL DEFAULT 0 COMMENT 'para consumir FIFO',
        motivo            VARCHAR(200) NULL,
        usuario_id        INT UNSIGNED NULL,
        creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_cb_cliente (cliente_id, creado_en),
        KEY ix_cb_vence (vence_en, tipo),
        KEY ix_cb_pago (pago_id),
        CONSTRAINT fk_cb_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    ) $m";

    /* ─────────────  METAS, BONOS Y RACHAS  ───────────── */

    // usuario_id usa 0 (no NULL) para decir "la meta general del país":
    // en MySQL dos NULL no chocan en un índice único, así que con NULL se
    // podrían meter diez metas generales del mismo mes y el HUB tomaría
    // cualquiera de ellas. Lo mismo en bono_resultados.
    $t[] = "CREATE TABLE IF NOT EXISTS metas (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id        SMALLINT UNSIGNED NOT NULL,
        usuario_id     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = la meta general del país',
        periodo        CHAR(7) NOT NULL COMMENT 'AAAA-MM',
        monto_centimos BIGINT NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_meta (pais_id, usuario_id, periodo),
        CONSTRAINT fk_meta_pais FOREIGN KEY (pais_id) REFERENCES paises(id),
        KEY ix_meta_periodo (periodo)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS bonos (
        id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clave     VARCHAR(40) NOT NULL,
        nombre    VARCHAR(80) NOT NULL,
        periodo   ENUM('dia','semana','quincena','mes','semestre') NOT NULL,
        metrica   VARCHAR(40) NOT NULL,
        condicion JSON NULL,
        premio    JSON NULL,
        individual TINYINT(1) NOT NULL DEFAULT 1,
        activo    TINYINT(1) NOT NULL DEFAULT 1,
        orden     SMALLINT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_bono_clave (clave)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS bono_resultados (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        bono_id        SMALLINT UNSIGNED NOT NULL,
        usuario_id     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = no aplica (bono de equipo)',
        equipo_id      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = no aplica (bono individual)',
        periodo_inicio DATE NOT NULL,
        periodo_fin    DATE NOT NULL,
        valor          BIGINT NOT NULL DEFAULT 0,
        ganado         TINYINT(1) NOT NULL DEFAULT 0,
        premio_centimos BIGINT NOT NULL DEFAULT 0,
        pagado         TINYINT(1) NOT NULL DEFAULT 0,
        cerrado_en     DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_bonores (bono_id, usuario_id, equipo_id, periodo_inicio),
        KEY ix_bonores_periodo (periodo_inicio, periodo_fin),
        CONSTRAINT fk_bonores_bono FOREIGN KEY (bono_id) REFERENCES bonos(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS rachas (
        usuario_id     INT UNSIGNED NOT NULL,
        tipo           ENUM('dias','semanas','clientes_semana') NOT NULL,
        valor          INT NOT NULL DEFAULT 0,
        mejor          INT NOT NULL DEFAULT 0,
        ultima_fecha   DATE NULL,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_id, tipo),
        CONSTRAINT fk_racha_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $m";

    /* ─────────────  RECEPCIÓN  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS visitas (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        oficina_id     INT UNSIGNED NOT NULL,
        modulo         VARCHAR(10) NOT NULL,
        cliente_id     INT UNSIGNED NULL,
        cliente_nombre VARCHAR(140) NULL COMMENT 'si todavía no está registrado',
        asesor_id      INT UNSIGNED NULL,
        motivo         VARCHAR(80) NULL,
        estado         ENUM('esperando','atendido','no_vino','derivado') NOT NULL DEFAULT 'esperando',
        termino_en_venta TINYINT(1) NOT NULL DEFAULT 0,
        pedido_id      INT UNSIGNED NULL,
        avisado_en     DATETIME NULL,
        atendido_en    DATETIME NULL,
        creado_por     INT UNSIGNED NULL,
        creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_visita_estado (oficina_id, estado, creado_en),
        KEY ix_visita_asesor (asesor_id, creado_en),
        CONSTRAINT fk_visita_oficina FOREIGN KEY (oficina_id) REFERENCES oficinas(id),
        CONSTRAINT fk_visita_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
        CONSTRAINT fk_visita_asesor  FOREIGN KEY (asesor_id)  REFERENCES usuarios(id) ON DELETE SET NULL
    ) $m";

    return $t;
}
