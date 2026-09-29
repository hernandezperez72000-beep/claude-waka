<?php
declare(strict_types=1);

/**
 * Esquema de la v1 completo. Se ejecuta una sola vez desde el instalador,
 * y es idempotente: se puede volver a correr sin romper nada.
 *
 * Reglas que están metidas en el propio esquema:
 *  · pais_id AÍSLA, oficina_id NO. Son dos columnas. `sede_id` no existe.
 *  · El dinero es BIGINT de céntimos. Nunca DECIMAL ni FLOAT.
 *  · Los índices se crean ahora, no cuando la tabla ya tenga 100,000 filas.
 */
function esquema_sql(): array
{
    $m = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $t = [];

    /* ─────────────  ORGANIZACIÓN  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS paises (
        id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        nombre    VARCHAR(60)  NOT NULL,
        codigo    CHAR(2)      NOT NULL,
        moneda    CHAR(3)      NOT NULL DEFAULT 'PEN',
        simbolo   VARCHAR(5)   NOT NULL DEFAULT 'S/',
        activo    TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pais_codigo (codigo)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS oficinas (
        id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id   SMALLINT UNSIGNED NOT NULL,
        nombre    VARCHAR(80)  NOT NULL,
        direccion VARCHAR(200) NULL,
        modulos   VARCHAR(120) NULL COMMENT 'números de módulo separados por coma',
        orden     SMALLINT     NOT NULL DEFAULT 0,
        activo    TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY ix_oficina_pais (pais_id, activo),
        CONSTRAINT fk_oficina_pais FOREIGN KEY (pais_id) REFERENCES paises(id)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS equipos (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id           SMALLINT UNSIGNED NOT NULL,
        nombre            VARCHAR(80) NOT NULL,
        lider_usuario_id  INT UNSIGNED NULL COMMENT 'uno solo por equipo',
        orden             SMALLINT NOT NULL DEFAULT 0,
        activo            TINYINT(1) NOT NULL DEFAULT 1,
        creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_equipo_pais (pais_id, activo)
    ) $m";

    /* ─────────────  PERSONAS Y PERMISOS  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS roles (
        id     TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clave  VARCHAR(30) NOT NULL,
        nombre VARCHAR(60) NOT NULL,
        orden  TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rol_clave (clave)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS permisos (
        id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clave  VARCHAR(60) NOT NULL,
        nombre VARCHAR(120) NOT NULL,
        grupo  VARCHAR(40) NOT NULL,
        tecnico TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_permiso_clave (clave)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS rol_permiso (
        rol_id     TINYINT UNSIGNED NOT NULL,
        permiso_id SMALLINT UNSIGNED NOT NULL,
        PRIMARY KEY (rol_id, permiso_id),
        CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
        CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS usuarios (
        id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pais_id                SMALLINT UNSIGNED NOT NULL,
        oficina_id             INT UNSIGNED NULL,
        equipo_id              INT UNSIGNED NULL,
        rol_id                 TINYINT UNSIGNED NOT NULL,
        nombre                 VARCHAR(80)  NOT NULL,
        apellidos              VARCHAR(80)  NULL,
        email                  VARCHAR(190) NOT NULL,
        celular                VARCHAR(25)  NULL,
        fecha_nacimiento       DATE NULL COMMENT 'para el saludo de cumpleaños',
        documento              VARCHAR(20)  NULL,
        password_hash          VARCHAR(255) NOT NULL,
        ambito                 ENUM('propio','equipo','todo') NOT NULL DEFAULT 'propio',
        nivel_cuota            TINYINT UNSIGNED NULL COMMENT 'la Meta #X',
        meta_mensual_centimos  BIGINT NULL COMMENT 'NULL = usa la meta general',
        foto                   VARCHAR(120) NULL,
        tema                   ENUM('auto','claro','oscuro') NOT NULL DEFAULT 'auto',
        avisos_rachas          TINYINT(1) NOT NULL DEFAULT 1,
        avisos_recepcion       TINYINT(1) NOT NULL DEFAULT 1,
        activo                 TINYINT(1) NOT NULL DEFAULT 1,
        debe_cambiar_password  TINYINT(1) NOT NULL DEFAULT 1,
        password_cambiada_en   DATETIME NULL,
        ultimo_acceso          DATETIME NULL,
        creado_por             INT UNSIGNED NULL,
        creado_en              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_usuario_email (email),
        KEY ix_usuario_pais   (pais_id, activo),
        KEY ix_usuario_equipo (equipo_id, activo),
        KEY ix_usuario_rol    (rol_id),
        CONSTRAINT fk_usuario_pais    FOREIGN KEY (pais_id)    REFERENCES paises(id),
        CONSTRAINT fk_usuario_oficina FOREIGN KEY (oficina_id) REFERENCES oficinas(id),
        CONSTRAINT fk_usuario_equipo  FOREIGN KEY (equipo_id)  REFERENCES equipos(id),
        CONSTRAINT fk_usuario_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS sesiones (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        usuario_id     INT UNSIGNED NOT NULL,
        selector       CHAR(18) NOT NULL,
        validador_hash CHAR(64) NOT NULL,
        ip             VARCHAR(45) NULL,
        user_agent     VARCHAR(250) NULL,
        expira_en      DATETIME NOT NULL,
        creada_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_sesion_selector (selector),
        KEY ix_sesion_usuario (usuario_id),
        KEY ix_sesion_expira (expira_en),
        CONSTRAINT fk_sesion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS intentos_acceso (
        id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email     VARCHAR(190) NULL,
        ip        VARCHAR(45)  NULL,
        exito     TINYINT(1)   NOT NULL DEFAULT 0,
        creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_intento_email (email, creado_en),
        KEY ix_intento_ip (ip, creado_en)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS solicitudes_password (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        usuario_id   INT UNSIGNED NOT NULL,
        estado       ENUM('pendiente','aprobada','usada','rechazada','caducada') NOT NULL DEFAULT 'pendiente',
        token_hash   CHAR(64) NULL,
        expira_en    DATETIME NULL,
        aprobada_por INT UNSIGNED NULL,
        nota         VARCHAR(200) NULL,
        creada_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        usada_en     DATETIME NULL,
        PRIMARY KEY (id),
        KEY ix_solpwd_estado (estado, creada_en),
        KEY ix_solpwd_usuario (usuario_id),
        CONSTRAINT fk_solpwd_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $m";

    /* ─────────────  CONFIGURACIÓN Y LISTAS  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS ajustes (
        clave       VARCHAR(60) NOT NULL,
        valor       TEXT NULL,
        tipo        ENUM('texto','entero','booleano','json') NOT NULL DEFAULT 'texto',
        grupo       VARCHAR(40) NOT NULL DEFAULT 'general',
        descripcion VARCHAR(200) NULL,
        tecnico     TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (clave)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS listas (
        id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clave  VARCHAR(40) NOT NULL,
        nombre VARCHAR(80) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_lista_clave (clave)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS lista_items (
        id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
        lista_id SMALLINT UNSIGNED NOT NULL,
        pais_id  SMALLINT UNSIGNED NULL COMMENT 'NULL = vale para todos los países',
        valor    VARCHAR(120) NOT NULL,
        extra    VARCHAR(200) NULL,
        orden    SMALLINT NOT NULL DEFAULT 0,
        activo   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'se apaga, nunca se borra',
        PRIMARY KEY (id),
        KEY ix_item_lista (lista_id, activo, orden),
        CONSTRAINT fk_item_lista FOREIGN KEY (lista_id) REFERENCES listas(id) ON DELETE CASCADE
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS frases (
        id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        rol_clave VARCHAR(30) NULL COMMENT 'NULL = para cualquiera',
        franja    ENUM('madrugada','manana','tarde','noche') NOT NULL,
        texto     VARCHAR(200) NOT NULL,
        usos      INT UNSIGNED NOT NULL DEFAULT 0,
        orden     SMALLINT NOT NULL DEFAULT 0,
        activo    TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY ix_frase_busca (franja, activo, rol_clave)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS plantillas_whatsapp (
        id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clave     VARCHAR(40) NOT NULL,
        nombre    VARCHAR(80) NOT NULL,
        texto     TEXT NOT NULL,
        activo    TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_plantilla_clave (clave)
    ) $m";

    /* ─────────────  BITÁCORA Y ERRORES  ───────────── */

    $t[] = "CREATE TABLE IF NOT EXISTS bitacora (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        usuario_id INT UNSIGNED NULL,
        accion     VARCHAR(60) NOT NULL,
        entidad    VARCHAR(40) NULL,
        entidad_id BIGINT UNSIGNED NULL,
        detalle    JSON NULL,
        ip         VARCHAR(45) NULL,
        creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_bit_usuario (usuario_id, creado_en),
        KEY ix_bit_entidad (entidad, entidad_id),
        KEY ix_bit_fecha (creado_en)
    ) $m";

    $t[] = "CREATE TABLE IF NOT EXISTS reportes_error (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        usuario_id  INT UNSIGNED NULL,
        seccion     VARCHAR(40) NULL,
        texto       TEXT NOT NULL,
        adjunto     VARCHAR(120) NULL,
        contexto    JSON NULL COMMENT 'pantalla, equipo, navegador: se manda solo',
        estado      ENUM('abierto','en_curso','resuelto','archivado') NOT NULL DEFAULT 'abierto',
        respuesta   TEXT NULL,
        resuelto_por INT UNSIGNED NULL,
        creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resuelto_en DATETIME NULL,
        PRIMARY KEY (id),
        KEY ix_error_estado (estado, creado_en),
        KEY ix_error_usuario (usuario_id)
    ) $m";

    return $t;
}
