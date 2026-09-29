<?php
declare(strict_types=1);

/**
 * Acceso a la base de datos. Una sola conexión por petición, PDO en modo
 * excepción y consultas siempre preparadas: en el HUB no se concatena SQL.
 */
function bd(bool $otra = false): PDO
{
    static $pdo = null;
    if ($otra) $pdo = null;

    // Durante la instalación todavía no hay archivo de configuración: el
    // instalador deja aquí su propia conexión.
    if (!empty($GLOBALS['__pdo_instalacion']) && $GLOBALS['__pdo_instalacion'] instanceof PDO) {
        return $GLOBALS['__pdo_instalacion'];
    }
    if ($pdo instanceof PDO) return $pdo;

    $cfg = hub_config();
    if (!$cfg) {
        cortar(503, 'El HUB todavía no está instalado', 'Falta el archivo de configuración.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['bd']['host'], $cfg['bd']['puerto'] ?? 3306, $cfg['bd']['nombre']
    );

    try {
        $pdo = new PDO($dsn, $cfg['bd']['usuario'], $cfg['bd']['clave'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $pdo->exec("SET time_zone = '-05:00'");
    } catch (PDOException $ex) {
        error_log('[HUB] Conexión BD: ' . $ex->getMessage());
        cortar(503, 'No se puede entrar en este momento',
                    'Vuelve a intentarlo en unos minutos. Si sigue igual, avisa a Administración.');
    }
    return $pdo;
}

/**
 * DESPIERTA LA CONEXIÓN después de un rato largo sin usarla (leer la tienda,
 * bajar fotos): el servidor de la base de datos corta las conexiones calladas
 * y la siguiente consulta fallaba con «MySQL server has gone away». Si no
 * contesta, se abre otra. Dentro de una transacción no se toca.
 */
function bd_despertar(): void
{
    try {
        $pdo = bd();
        if ($pdo->inTransaction()) return;
        $pdo->query('SELECT 1');
    } catch (Throwable $ex) {
        error_log('[HUB] conexión dormida, se abre otra: ' . $ex->getMessage());
        /* El instalador y el banco de pruebas traen su propia conexión: el
           banco la reabre con su gancho. */
        if (!empty($GLOBALS['__pdo_instalacion'])) {
            if (isset($GLOBALS['__bd_reabrir']) && is_callable($GLOBALS['__bd_reabrir'])) ($GLOBALS['__bd_reabrir'])();
            return;
        }
        bd(true);
    }
}

/** Ejecuta y devuelve la sentencia. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = bd()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Una fila o null. */
function una(string $sql, array $params = []): ?array
{
    $f = q($sql, $params)->fetch();
    return $f === false ? null : $f;
}

/** Todas las filas. */
function todas(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Un solo valor de la primera fila. */
function valor(string $sql, array $params = [], mixed $por_defecto = null): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $por_defecto : $v;
}

/** INSERT sencillo desde un array columna => valor. Devuelve el id nuevo. */
function insertar(string $tabla, array $datos): int
{
    $cols = array_keys($datos);
    $sql  = 'INSERT INTO `' . $tabla . '` (`' . implode('`,`', $cols) . '`) VALUES (:'
          . implode(',:', $cols) . ')';
    q($sql, $datos);
    return (int) bd()->lastInsertId();
}

/** UPDATE por id. Devuelve las filas tocadas. */
function actualizar(string $tabla, int $id, array $datos): int
{
    if (!$datos) return 0;
    $sets = [];
    foreach (array_keys($datos) as $c) $sets[] = "`$c` = :$c";
    $datos['__id'] = $id;
    $st = q('UPDATE `' . $tabla . '` SET ' . implode(', ', $sets) . ' WHERE id = :__id', $datos);
    return $st->rowCount();
}

/** Envuelve varias escrituras en una transacción. */
/* Se usa desde el módulo 2 (pedidos y pagos): un pago toca tres tablas. */
function en_transaccion(callable $fn): mixed
{
    $pdo = bd();
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        if ($propia) $pdo->commit();
        return $r;
    } catch (Throwable $ex) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Inserta si no existe, y si existe actualiza. Se usa en las semillas.
 * Va con SELECT + INSERT/UPDATE en vez de ON DUPLICATE KEY para que el mismo
 * código corra en el banco de pruebas y en MySQL sin dos versiones.
 *
 *   sembrar_fila('roles', ['clave' => 'asesor'], ['nombre' => 'Asesor']);
 */
function sembrar_fila(string $tabla, array $busca, array $datos = []): int
{
    $cond = [];
    foreach (array_keys($busca) as $c) $cond[] = "`$c` = :$c";
    $id = valor('SELECT id FROM `' . $tabla . '` WHERE ' . implode(' AND ', $cond), $busca);

    if ($id) {
        if ($datos) actualizar($tabla, (int)$id, $datos);
        return (int)$id;
    }
    return insertar($tabla, array_merge($busca, $datos));
}

/** Igual, pero sin actualizar si ya estaba: para tablas de solo dos columnas. */
function sembrar_par(string $tabla, array $columnas): void
{
    $cond = [];
    foreach (array_keys($columnas) as $c) $cond[] = "`$c` = :$c";
    $hay = valor('SELECT 1 FROM `' . $tabla . '` WHERE ' . implode(' AND ', $cond), $columnas);
    if (!$hay) {
        $cols = array_keys($columnas);
        q('INSERT INTO `' . $tabla . '` (`' . implode('`,`', $cols) . '`) VALUES (:'
          . implode(',:', $cols) . ')', $columnas);
    }
}

/* ───────────────  FORMA DE LA BASE (para las migraciones)  ───────────────
   El módulo 2 añade columnas a tablas que ya existen, así que hay que poder
   preguntar "¿está esta columna?" sin reventar. En MySQL la respuesta vive en
   information_schema; en el banco de pruebas (SQLite) no existe esa vista, y
   preguntarle allí devolvería un error en vez de un "no". Se pregunta una sola
   vez aquí para que ninguna migración tenga que saber sobre qué motor corre. */

function bd_es_sqlite(): bool
{
    static $es = null;
    if ($es !== null) return $es;
    try {
        $es = bd()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    } catch (Throwable $ex) {
        $es = false;
    }
    return $es;
}

/**
 * ¿Existe esa columna en esa tabla?
 *
 * SE RECUERDA LO QUE EXISTE, Y SOLO ESO. En MySQL cada llamada es un SELECT a
 * `information_schema`, y hay pantallas que preguntan lo mismo cien veces para
 * pintar una tabla —«Por mandar a despacho» llegó a hacer 100 consultas de
 * catálogo para 100 filas—. Guardar el «sí» es seguro: en este proyecto las
 * migraciones AÑADEN columnas y no borran ninguna, así que un «sí» no puede
 * volverse «no» a mitad de petición. El «no» NO se guarda a propósito: es lo
 * que cambia cuando actualizar.php pasa un ALTER, y cachearlo dejaría a
 * `sembrar_todo()` creyendo que la columna que se acaba de crear no está.
 */
function columna_existe(string $tabla, string $columna): bool
{
    /* 3j · VELOCIDAD: en MySQL cada pregunta suelta es un SELECT a
       `information_schema`, y en el hosting compartido eso es lo más lento que
       hace el HUB (una ficha de pedido llegaba a 30). Ahora, la PRIMERA vez en
       cada petición se leen de una vez todas las columnas que existen, y
       después se contesta de memoria. Un «no» se sigue preguntando a la base:
       es lo que cambia cuando actualizar.php pasa un ALTER. */
    $mapa = &esquema_memoria();
    $t = strtolower($tabla);
    $c = strtolower($columna);
    if (isset($mapa['col'][$t][$c])) return true;
    if (!isset($mapa['cargado_col'][$t])) {
        $mapa['cargado_col'][$t] = true;
        foreach (esquema_columnas_de($t) as $cc) $mapa['col'][$t][strtolower($cc)] = true;
        if (isset($mapa['col'][$t][$c])) return true;
    }
    $hay = columna_existe_de_verdad($tabla, $columna);
    if ($hay) $mapa['col'][$t][$c] = true;
    return $hay;
}

/**
 * Lo que se sabe que EXISTE, por conexión. Cuelga de la conexión: el banco de
 * pruebas rehace la base varias veces en el mismo proceso (una a propósito
 * SIN migrar), y heredar lo de la anterior haría pasar en verde lo que no se
 * probó. La conexión se guarda para que su id no se recicle.
 */
function &esquema_memoria(): array
{
    static $mapas = [];
    static $vivas = [];
    static $vacio = [];
    try { $pdo = bd(); } catch (Throwable $ex) { $vacio = ['col' => [], 'tab' => [], 'cargado_col' => []]; return $vacio; }
    $id = spl_object_id($pdo);
    $vivas[$id] = $pdo;
    if (!isset($mapas[$id])) $mapas[$id] = ['col' => [], 'tab' => null, 'cargado_col' => []];
    return $mapas[$id];
}

/** Todas las columnas de una tabla, de una vez. [] si no existe o no se pudo leer. */
function esquema_columnas_de(string $tabla): array
{
    try {
        if (bd_es_sqlite()) {
            return array_map(fn($x) => (string)$x['name'], todas('PRAGMA table_info(`' . str_replace('`', '', $tabla) . '`)'));
        }
        return array_map('strval', array_column(todas('SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabla]), 'COLUMN_NAME'));
    } catch (Throwable $ex) {
        return [];
    }
}

function columna_existe_de_verdad(string $tabla, string $columna): bool
{
    if (bd_es_sqlite()) {
        try {
            foreach (todas('PRAGMA table_info(`' . str_replace('`', '', $tabla) . '`)') as $c) {
                if (strcasecmp((string)$c['name'], $columna) === 0) return true;
            }
        } catch (Throwable $ex) { return false; }
        return false;
    }
    return (bool) valor(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$tabla, $columna]
    );
}

/** ¿Existe ese índice? Sirve para no intentar crear dos veces el mismo. */
function indice_existe(string $tabla, string $indice): bool
{
    if (bd_es_sqlite()) {
        try {
            foreach (todas('PRAGMA index_list(`' . str_replace('`', '', $tabla) . '`)') as $i) {
                if (strcasecmp((string)$i['name'], $indice) === 0) return true;
            }
        } catch (Throwable $ex) { return false; }
        return false;
    }
    return (bool) valor(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$tabla, $indice]
    );
}

/** ¿Existe la tabla? (3j: todas las tablas se leen de una vez por petición; un «no» se vuelve a preguntar.) */
function tabla_existe(string $tabla): bool
{
    $mapa = &esquema_memoria();
    $t = strtolower($tabla);
    if ($mapa['tab'] === null) {
        $mapa['tab'] = [];
        try {
            $nombres = bd_es_sqlite()
                ? array_column(todas("SELECT name FROM sqlite_master WHERE type='table'"), 'name')
                : array_column(todas('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), 'TABLE_NAME');
            foreach ($nombres as $n) $mapa['tab'][strtolower((string)$n)] = true;
        } catch (Throwable $ex) { /* se pregunta suelta abajo */ }
    }
    if (isset($mapa['tab'][$t])) return true;
    $hay = bd_es_sqlite()
        ? (bool) valor("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?", [$tabla])
        : (bool) valor('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabla]);
    if ($hay) $mapa['tab'][$t] = true;
    return $hay;
}

/**
 * Añade una columna solo si falta. Devuelve true si la añadió.
 * Es la pieza que hace que actualizar.php se pueda pasar dos veces seguidas.
 */
function anadir_columna(string $tabla, string $columna, string $definicion): bool
{
    if (!tabla_existe($tabla) || columna_existe($tabla, $columna)) return false;
    bd()->exec("ALTER TABLE `$tabla` ADD COLUMN `$columna` $definicion");
    return true;
}

/**
 * ¿Esta columna de texto admite MENOS caracteres de los que hacen falta?
 *
 * Un VARCHAR corto no da error: MySQL recorta y sigue, así que el dato llega
 * mutilado a la pantalla sin que nada lo diga. Devuelve false cuando no se
 * puede saber —otro motor, otro tipo— para que la migración no intente
 * ensanchar a ciegas lo que no entiende.
 */
function columna_cabe_menos_que(string $tabla, string $columna, int $largo): bool
{
    try {
        /* El `_` es comodín en LIKE, así que «direccion_txt» casaría también
           con «direccionXtxt» y devolvería la columna que no era. Se escapa. */
        $patron = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $columna);
        $fila = una("SHOW COLUMNS FROM `$tabla` LIKE ?", [$patron]);
    } catch (Throwable) { return false; }
    $tipo = strtolower((string)($fila['Type'] ?? ''));
    if (!preg_match('/^varchar\((\d+)\)/', $tipo, $m)) return false;
    return (int)$m[1] < $largo;
}

/**
 * Los valores que admite una definición de columna ENUM.
 * Entiende igual "ENUM('a','b')" que la definición entera con su NOT NULL y
 * su DEFAULT, que es como se escriben en el esquema.
 */
function enum_valores(string $definicion): array
{
    if (!preg_match('/enum\s*\(([^)]*)\)/i', $definicion, $m)) return [];
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $v);
    return array_map(static fn ($s) => str_replace("''", "'", (string)$s), $v[1]);
}

/**
 * Qué valores de los que pide una definición NO admite todavía la columna.
 *
 * Devuelve [] cuando los admite todos, cuando la columna no es un ENUM (en el
 * banco de pruebas son VARCHAR, y ahí cabe cualquier cosa) y cuando no hay
 * tabla. Es lo que mira el HUB antes de OFRECER un tipo de venta: escribirlo
 * en una columna que no lo admite no da error de PHP —MySQL guarda la cadena
 * vacía— y el pedido sale mal contado y sin aparecer en ningún filtro.
 */
function enum_falta_admitir(string $tabla, string $columna, string $definicion): array
{
    if (bd_es_sqlite()) return [];
    if (!tabla_existe($tabla) || !columna_existe($tabla, $columna)) return [];

    $quiero = enum_valores($definicion);
    if (!$quiero) return [];

    try {
        $ahora = (string) valor(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );
    } catch (Throwable $ex) {
        return [];
    }
    $tiene = enum_valores($ahora);
    if (!$tiene) return [];          // no es ENUM: no hay nada que estreche

    return array_values(array_diff($quiero, $tiene));
}

/**
 * Añade valores nuevos a una columna ENUM que ya existe.
 *
 * Es la migración que ninguna otra pieza sabe hacer: `anadir_columna()` solo
 * sirve para columnas que faltan, y una columna que ya está pero admite menos
 * valores que el código NO da error de PHP — MySQL guarda la cadena vacía y
 * suelta un aviso. Un pedido de liquidación habría quedado con el tipo en
 * blanco, fuera de los dos filtros y sin estado que le tocara.
 *
 * Solo actúa si de verdad falta algún valor, así que actualizar.php se puede
 * pasar dos veces. En el banco de pruebas (SQLite) los ENUM son VARCHAR y no
 * hay nada que ensanchar; por eso la convergencia entre instalar y actualizar
 * la vigila una prueba de código fuente, no una de base.
 *
 * Devuelve true si tocó la tabla.
 */
function ampliar_enum(string $tabla, string $columna, string $definicion): bool
{
    if (!tabla_existe($tabla) || !columna_existe($tabla, $columna)) return false;
    if (bd_es_sqlite()) return false;

    $quiero = enum_valores($definicion);
    if (!$quiero) return false;

    $ahora = (string) valor(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$tabla, $columna]
    );
    /* Si la columna no es ENUM (alguien la pasó a VARCHAR a mano) no se toca:
       ensancharla sería estrecharla. */
    $tiene = enum_valores($ahora);
    if (!$tiene) return false;
    if (!array_diff($quiero, $tiene)) return false;

    bd()->exec("ALTER TABLE `$tabla` MODIFY `$columna` $definicion");
    return true;
}

/**
 * Reserva una fila para el resto de la transacción.
 *
 * Sirve para lo que ninguna comprobación de PHP puede: entre leer el saldo de
 * un pedido y guardar el pago cabe otra petición, y dos pestañas registrando a
 * la vez dejaban el pedido sobrecobrado. Con la fila reservada, la segunda
 * petición espera y vuelve a leer el saldo de verdad.
 *
 * En el banco de pruebas (SQLite) no existe FOR UPDATE y tampoco hace falta:
 * ahí las escrituras van en fila de una en una. Se hace un SELECT normal para
 * que el código sea el mismo en los dos sitios.
 */
function bloquear_fila(string $tabla, int $id): void
{
    $sql = 'SELECT id FROM `' . str_replace('`', '', $tabla) . '` WHERE id = ?';
    if (!bd_es_sqlite()) $sql .= ' FOR UPDATE';
    q($sql, [$id]);
}

/**
 * ¿Esta excepción es «ya existe una fila igual» y no otra cosa?
 *
 * Hace falta para poder apoyarse en un índice único como candado de verdad:
 * el código intenta insertar, y si otra petición se le adelantó, sigue como si
 * nada en vez de enseñarle una pantalla de error a alguien que no hizo nada
 * mal. Se pregunta por el SQLSTATE 23000, que es el mismo en MySQL y en el
 * banco de pruebas; cualquier otro error se vuelve a lanzar.
 */
function es_choque_de_unico(Throwable $ex): bool
{
    $codigo = (string) $ex->getCode();
    if ($codigo === '23000' || $codigo === '23505') return true;
    $texto = mb_strtolower($ex->getMessage());
    return str_contains($texto, 'duplicate entry')
        || str_contains($texto, 'unique constraint');
}
