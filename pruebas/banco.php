<?php
declare(strict_types=1);

/**
 * El banco de pruebas.
 *
 * El HUB corre sobre MySQL, pero las pruebas tienen que poder correr en
 * cualquier sitio sin instalar nada. La solución es un traductor: se toma el
 * MISMO DDL que se sube al hosting y se convierte a SQLite al vuelo.
 *
 * La regla que sostiene todo esto: **cero líneas de código de pruebas dentro
 * del producto**. El HUB no sabe que existe este archivo. El único punto de
 * contacto es $GLOBALS['__pdo_instalacion'], el mismo gancho que ya usaba el
 * instalador — no se inventó nada para poder probar.
 */

/** Traduce una sentencia CREATE TABLE de MySQL a SQLite. */
function ddl_a_sqlite(string $sql): string
{
    // Cola de la tabla: motor, juego de caracteres y cotejo.
    $sql = preg_replace('/\)\s*ENGINE=.*?$/is', ')', $sql) ?? $sql;

    // Comentarios de columna.
    $sql = preg_replace("/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $sql) ?? $sql;

    // ENUM('a','b') se guarda como texto. La validación vive en PHP, que es
    // donde hay que probarla: un ENUM solo daría una falsa sensación.
    $sql = preg_replace('/\bENUM\s*\([^)]*\)/i', 'VARCHAR(40)', $sql) ?? $sql;

    // La clave primaria autoincremental: SQLite solo la acepta como INTEGER
    // PRIMARY KEY AUTOINCREMENT, en la propia columna.
    $sql = preg_replace('/\b(BIGINT|INT|SMALLINT|TINYINT|MEDIUMINT)(\s+UNSIGNED)?\s+NOT NULL\s+AUTO_INCREMENT/i',
                        'INTEGER PRIMARY KEY AUTOINCREMENT', $sql) ?? $sql;
    $sql = preg_replace('/,\s*PRIMARY KEY \(\s*`?id`?\s*\)/i', '', $sql) ?? $sql;

    // Enteros: a SQLite le da igual el ancho y no conoce UNSIGNED.
    $sql = preg_replace('/\b(BIGINT|SMALLINT|TINYINT|MEDIUMINT|INT)\s*(\(\d+\))?\s+UNSIGNED/i', 'INTEGER', $sql) ?? $sql;
    $sql = preg_replace('/\b(BIGINT|SMALLINT|TINYINT|MEDIUMINT)\s*(\(\d+\))?/i', 'INTEGER', $sql) ?? $sql;
    $sql = preg_replace('/\bINT\s*\(\d+\)/i', 'INTEGER', $sql) ?? $sql;
    $sql = preg_replace('/\bJSON\b/i', 'TEXT', $sql) ?? $sql;
    $sql = preg_replace('/\bCHAR\s*\((\d+)\)/i', 'VARCHAR($1)', $sql) ?? $sql;

    // SQLite no sabe actualizar una fecha sola al modificar la fila.
    $sql = preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $sql) ?? $sql;

    /* EL RELOJ DEL BANCO TIENE QUE SER EL MISMO QUE EL DEL HUB.
       En producción el HUB usa dos relojes y los pone de acuerdo a mano: PHP va
       en America/Lima (arranque.php) y la conexión hace SET time_zone='-05:00'
       (bd.php), así que el CURRENT_TIMESTAMP de la base y el date() de PHP
       dicen la misma hora. SQLite no tiene SET time_zone: su CURRENT_TIMESTAMP
       es SIEMPRE UTC.

       Sin esta traducción, todo lo que el banco escribe entre las 19:00 y la
       medianoche de Lima nace con la fecha de MAÑANA, y cualquier prueba que
       filtre por un rango de fechas «hasta hoy» no lo encuentra. Pasó de
       verdad: la prueba del reporte de la bitácora se ponía roja sola a partir
       de las siete de la tarde, sin que nadie tocara una línea. Un banco que
       se pone rojo según la hora enseña a no mirar el rojo. */
    $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $signo  = $offset[0] === '-' ? '-' : '+';
    [$h, $m] = array_map('intval', explode(':', substr($offset, 1)));
    $desfase = sprintf("%s%d hours', '%s%d minutes", $signo, $h, $signo, $m);
    $sql = preg_replace('/DEFAULT\s+CURRENT_TIMESTAMP/i',
                        "DEFAULT (datetime('now', '$desfase'))", $sql) ?? $sql;

    // Los índices no van dentro del CREATE TABLE: se sacan aparte (los crea
    // banco_crear más abajo) o se descartan. UNIQUE KEY sí se conserva, como
    // restricción de tabla, porque las pruebas comprueban duplicados.
    $sql = preg_replace('/,\s*UNIQUE KEY\s+`?\w+`?\s*\(([^)]*)\)/i', ', UNIQUE ($1)', $sql) ?? $sql;
    $sql = preg_replace('/,\s*KEY\s+`?\w+`?\s*\([^)]*\)/i', '', $sql) ?? $sql;

    // Las claves foráneas se quedan: son parte de lo que se quiere probar,
    // pero SQLite no acepta el nombre CONSTRAINT antes de FOREIGN KEY.
    $sql = preg_replace('/CONSTRAINT\s+`?\w+`?\s+FOREIGN KEY/i', 'FOREIGN KEY', $sql) ?? $sql;

    return $sql;
}

/**
 * PDO de pruebas: traduce al vuelo las funciones de fecha de MySQL.
 * Envuelve al PDO real en vez de heredarlo para que ninguna consulta se
 * escape sin pasar por la traducción.
 */
class PdoPruebas extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(self::traducir($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::traducir($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetch): PDOStatement|false
    {
        return parent::query(self::traducir($query), $fetchMode, ...$fetch);
    }

    public static function traducir(string $sql): string
    {
        // DATE_SUB(NOW(), INTERVAL ? MINUTE) → datetime('now','-'||?||' minutes')
        $sql = preg_replace_callback(
            '/DATE_SUB\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\?|\d+)\s+(MINUTE|HOUR|DAY|MONTH)\s*\)/i',
            function ($m) {
                $unidad = strtolower($m[2]) . 's';
                return $m[1] === '?'
                    ? "datetime('now', '-' || ? || ' $unidad')"
                    : "datetime('now', '-{$m[1]} $unidad')";
            },
            $sql
        ) ?? $sql;

        $sql = preg_replace('/\bNOW\(\)/i',     "datetime('now')", $sql) ?? $sql;
        $sql = preg_replace('/\bCURDATE\(\)/i', "date('now')",     $sql) ?? $sql;
        $sql = preg_replace('/\bRAND\(\)/i',    'RANDOM()',        $sql) ?? $sql;
        return $sql;
    }
}

/**
 * Crea una base nueva y vacía con todo el esquema y las semillas.
 *
 * SIEMPRE se rehace: si se reutilizara, la cuenta que crea una prueba seguiría
 * ahí en la siguiente y los fallos serían imposibles de leer.
 */
function banco_crear(string $archivo = ''): PDO
{
    $archivo = $archivo ?: (sys_get_temp_dir() . '/waka-pruebas.sqlite');
    if (is_file($archivo)) @unlink($archivo);

    $pdo = new PdoPruebas('sqlite:' . $archivo, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $GLOBALS['__pdo_instalacion'] = $pdo;

    require_once HUB_APP . '/nucleo/esquema.php';
    require_once HUB_APP . '/nucleo/esquema2.php';
    require_once HUB_APP . '/nucleo/esquema3.php';
    require_once HUB_APP . '/nucleo/semillas.php';

    foreach (esquema_sql() as $sql)         $pdo->exec(ddl_a_sqlite($sql));
    foreach (esquema_sql_negocio() as $sql) $pdo->exec(ddl_a_sqlite($sql));
    foreach (esquema_sql_modulo2() as $sql) $pdo->exec(ddl_a_sqlite($sql));

    migraciones_modulo2();
    sembrar_todo();

    return $pdo;
}

/** Un usuario de prueba. Los nombres NO son de nadie real: viven solo aquí. */
function banco_usuario(string $rol, array $extra = []): int
{
    $pais = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
    $rol_id = (int) valor('SELECT id FROM roles WHERE clave = ?', [$rol]);
    $n = (int) valor('SELECT COUNT(*) FROM usuarios') + 1;

    return insertar('usuarios', array_merge([
        'pais_id'   => $pais,
        'rol_id'    => $rol_id,
        'nombre'    => 'Prueba' . $n,
        'apellidos' => 'Detest',
        'email'     => 'prueba' . $n . '@waka.test',
        'password_hash' => password_hash('Clave-Larga-1', PASSWORD_DEFAULT),
        'ambito'    => $rol === 'asesor' ? 'propio' : 'todo',
        'activo'    => 1,
        'debe_cambiar_password' => 0,
    ], $extra));
}

/** Deja abierta una sesión de ese usuario, como si hubiera entrado. */
function banco_entrar(int $usuario_id): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $_SESSION = [];
    }
    $_SESSION['usuario_id'] = $usuario_id;
    $_SESSION['csrf'] = 'prueba';
    yo_olvidar();
}
