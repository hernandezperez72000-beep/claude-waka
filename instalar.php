<?php
/**
 * HUB Waka — instalador.
 * Se usa una sola vez. En cuanto escribe la configuración, se apaga solo.
 *
 * La contraseña de la base de datos la escribes TÚ aquí: no viaja por chat
 * ni por correo, y se guarda en un archivo fuera de la carpeta pública.
 */
declare(strict_types=1);
require_once __DIR__ . '/app/nucleo/arranque.php';
require_once HUB_APP . '/nucleo/esquema.php';
require_once HUB_APP . '/nucleo/esquema2.php';
require_once HUB_APP . '/nucleo/esquema3.php';
require_once HUB_APP . '/nucleo/semillas.php';

sesion_iniciar();

if (hub_instalado()) {
    http_response_code(410);
    $ya = true;
}

$paso   = (int)($_POST['paso'] ?? $_GET['paso'] ?? 1);
$errores = [];
$avisos  = [];

/* ─────────────  Comprobaciones del servidor  ───────────── */
function chequeos(): array
{
    $c = [];
    $c[] = ['PHP 8.1 o superior', PHP_VERSION_ID >= 80100, PHP_VERSION];
    foreach (['pdo_mysql','mbstring','json','openssl','fileinfo'] as $ext) {
        $c[] = ['Extensión ' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'sí' : 'falta'];
    }
    $c[] = ['Extensión gd (fotos de perfil)', extension_loaded('gd'), extension_loaded('gd') ? 'sí' : 'falta'];

    $dir = dirname(HUB_RAIZ);
    $c[] = ['Se puede escribir fuera de la carpeta pública', is_writable($dir), $dir];
    $c[] = ['Carpeta uploads escribible', is_dir(HUB_SUBIDAS) && is_writable(HUB_SUBIDAS), HUB_SUBIDAS];
    return $c;
}

/* ─────────────  Paso 2: probar la base  ───────────── */
$bd = [
    'host'    => $_POST['host']    ?? 'localhost',
    'puerto'  => $_POST['puerto']  ?? '3306',
    'nombre'  => $_POST['nombre']  ?? '',
    'usuario' => $_POST['usuario'] ?? '',
    'clave'   => $_POST['clave']   ?? '',
];

if (!isset($ya) && $paso === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                       $bd['host'], (int)$bd['puerto'], $bd['nombre']);
        $pdo = new PDO($dsn, $bd['usuario'], $bd['clave'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $_SESSION['inst_bd'] = $bd;
    } catch (PDOException $ex) {
        error_log('[HUB instalar] ' . $ex->getMessage());
        $errores[] = 'No se pudo conectar a la base de datos. Revisa el nombre de la base, '
                   . 'el usuario y la contraseña en cPanel, y que el usuario esté añadido '
                   . 'a esa base con todos los permisos. El detalle exacto quedó en el log de errores.';
        $paso = 2;
    }
}

/* ─────────────  Paso 4: crear todo  ───────────── */
if (!isset($ya) && $paso === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $bd = $_SESSION['inst_bd'] ?? null;
    if (!$bd) { $errores[] = 'Se perdieron los datos de la base. Vuelve a empezar.'; $paso = 2; }

    $cuentas = [
        'admin' => [
            'nombre'   => trim($_POST['admin_nombre'] ?? ''),
            'apellidos'=> trim($_POST['admin_apellidos'] ?? ''),
            'email'    => mb_strtolower(trim($_POST['admin_email'] ?? '')),
            'clave'    => $_POST['admin_clave'] ?? '',
            'clave2'   => $_POST['admin_clave2'] ?? '',
        ],
        'ceo' => [
            'nombre'   => trim($_POST['ceo_nombre'] ?? ''),
            'apellidos'=> trim($_POST['ceo_apellidos'] ?? ''),
            'email'    => mb_strtolower(trim($_POST['ceo_email'] ?? '')),
            'clave'    => $_POST['ceo_clave'] ?? '',
            'clave2'   => $_POST['ceo_clave2'] ?? '',
        ],
    ];

    foreach ($cuentas as $k => $c) {
        $quien = $k === 'admin' ? 'Administración' : 'Dirección';
        if ($c['nombre'] === '')            $errores[] = "Falta el nombre de $quien.";
        if (!correo_valido($c['email']))    $errores[] = "El correo de $quien no es válido.";
        if ($c['clave'] !== $c['clave2'])   $errores[] = "Las contraseñas de $quien no coinciden.";
        foreach (pega_la_contrasena($c['clave']) as $f) $errores[] = "Contraseña de $quien: $f.";
    }
    if ($cuentas['admin']['email'] === $cuentas['ceo']['email'] && !$errores) {
        $errores[] = 'Las dos cuentas no pueden usar el mismo correo.';
    }

    if (!$errores) {
        try {
            // Conexión directa: todavía no hay archivo de configuración.
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                           $bd['host'], (int)$bd['puerto'], $bd['nombre']);
            $pdo = new PDO($dsn, $bd['usuario'], $bd['clave'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '-05:00'");

            // Puente para que bd() use esta conexión durante la instalación.
            $GLOBALS['__pdo_instalacion'] = $pdo;

            foreach (esquema_sql() as $sql)          $pdo->exec($sql);
            foreach (esquema_sql_negocio() as $sql)  $pdo->exec($sql);
            foreach (esquema_sql_modulo2() as $sql)  $pdo->exec($sql);

            // Las columnas del módulo 2 están definidas en un solo sitio y se
            // aplican igual en una instalación nueva que en una actualización.
            // Si estuvieran escritas dos veces, un HUB recién instalado y uno
            // actualizado acabarían con tablas distintas sin que nadie lo note.
            migraciones_modulo2();

            sembrar_todo();

            $peru    = (int) valor("SELECT id FROM paises WHERE codigo = 'PE'");
            $lima    = (int) valor('SELECT id FROM oficinas WHERE pais_id = ? ORDER BY orden LIMIT 1', [$peru]);
            $rolAdm  = (int) valor("SELECT id FROM roles WHERE clave = 'administracion'");
            $rolDir  = (int) valor("SELECT id FROM roles WHERE clave = 'direccion'");

            $crear = function (array $c, int $rol, string $ambito) use ($peru, $lima) {
                $ya = valor('SELECT id FROM usuarios WHERE email = ?', [$c['email']]);
                if ($ya) return (int)$ya;
                return insertar('usuarios', [
                    'pais_id'    => $peru,
                    'oficina_id' => $lima,
                    'rol_id'     => $rol,
                    'nombre'     => $c['nombre'],
                    'apellidos'  => $c['apellidos'] ?: null,
                    'email'      => $c['email'],
                    'password_hash' => password_hash($c['clave'], PASSWORD_DEFAULT),
                    'ambito'     => $ambito,
                    'debe_cambiar_password' => 0,
                    'password_cambiada_en'  => date('Y-m-d H:i:s'),
                ]);
            };
            $id_admin = $crear($cuentas['admin'], $rolAdm, 'todo');
            $crear($cuentas['ceo'], $rolDir, 'todo');

            // Lo que necesita que los roles ya estén sembrados. En una
            // instalación nueva no tiene nada que arreglar, pero se llama
            // igual: un solo camino para instalar y para actualizar.
            migraciones_tras_semillas();

            // La meta general del mes, para que el HUB arranque con algo coherente.
            $meta = (int) (valor("SELECT valor FROM ajustes WHERE clave='meta_mensual_general'") ?: 3000000);
            $hay_meta = valor('SELECT id FROM metas WHERE pais_id = ? AND usuario_id = 0 AND periodo = ?',
                              [$peru, date('Y-m')]);
            if (!$hay_meta) {
                insertar('metas', ['pais_id' => $peru, 'usuario_id' => 0,
                                   'periodo' => date('Y-m'), 'monto_centimos' => $meta]);
            }

            // Y ahora sí: la configuración, fuera de la carpeta pública.
            if (!is_dir(HUB_CONFIG_DIR)) @mkdir(HUB_CONFIG_DIR, 0750, true);
            if (!is_dir(HUB_CONFIG_DIR)) throw new RuntimeException(
                'No se pudo crear la carpeta ' . HUB_CONFIG_DIR . '. Créala a mano desde el Administrador de archivos y vuelve a intentar.');

            $php = "<?php\n"
                 . "/* HUB Waka — configuración. Este archivo vive FUERA de la carpeta pública.\n"
                 . "   No lo subas a ningún repositorio ni lo mandes por chat. */\n"
                 . "return [\n"
                 . "    'bd' => [\n"
                 . "        'host'    => " . var_export($bd['host'], true) . ",\n"
                 . "        'puerto'  => " . (int)$bd['puerto'] . ",\n"
                 . "        'nombre'  => " . var_export($bd['nombre'], true) . ",\n"
                 . "        'usuario' => " . var_export($bd['usuario'], true) . ",\n"
                 . "        'clave'   => " . var_export($bd['clave'], true) . ",\n"
                 . "    ],\n"
                 . "    // Proxies de confianza. Solo si la IP que conecta está aquí se cree\n"
                 . "    // la cabecera X-Forwarded-For; si no, cualquiera podría mentir sobre\n"
                 . "    // su IP y esquivar el bloqueo por intentos.\n"
                 . "    // Detrás de Cloudflare, pon aquí sus rangos publicados.\n"
                 . "    'proxies'  => [],\n"
                 . "    'depurar'  => false,\n"
                 . "    'log'      => " . var_export(HUB_CONFIG_DIR . '/hub-errores.log', true) . ",\n"
                 . "    'instalado_en' => " . var_export(date('c'), true) . ",\n"
                 . "];\n";

            if (@file_put_contents(HUB_CONFIG_FILE, $php) === false) {
                throw new RuntimeException('No se pudo escribir ' . HUB_CONFIG_FILE);
            }
            @chmod(HUB_CONFIG_FILE, 0640);
            @file_put_contents(HUB_CONFIG_DIR . '/.htaccess', "Require all denied\n");

            $GLOBALS['__pdo_instalacion'] = null;
            $_SESSION['inst_bd'] = null;
            $paso = 5;

        } catch (Throwable $ex) {
            error_log('[HUB instalar] ' . $ex->getMessage());
            $errores[] = 'No se pudieron crear las tablas. El detalle quedó en el log de '
                       . 'errores de cPanel. Lo más común es que al usuario de la base le '
                       . 'falten permisos: en cPanel, "Añadir usuario a la base" con TODOS.';
            $paso = 4;
        }
    }
}

$titulo = 'Instalar el HUB Waka';
?><!doctype html>
<html lang="es" data-theme="claro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="assets/css/hub.css">
</head>
<body class="pantalla-sola">
<div class="inst">
  <header class="inst__cab">
    <img src="assets/img/logo-waka.png" alt="Waka Importaciones" class="inst__logo">
    <span class="inst__paso">Instalación</span>
  </header>

<?php if (isset($ya)): ?>
  <div class="tarjeta">
    <h1>El HUB ya está instalado</h1>
    <p class="muted">Este instalador está apagado. Si necesitas volver a instalarlo, borra el archivo
      <code><?= e(HUB_CONFIG_FILE) ?></code> desde el Administrador de archivos.</p>
    <p><strong>Por seguridad, borra ahora <code>instalar.php</code> del servidor.</strong></p>
    <a class="btn btn--negro" href="<?= e(url('/')) ?>">Ir al HUB</a>
  </div>

<?php elseif ($paso === 5): ?>
  <div class="tarjeta">
    <div class="inst__ok">✓</div>
    <h1>Listo. El HUB está instalado.</h1>
    <p class="muted">Se crearon las tablas, la configuración quedó fuera de la carpeta pública y ya
       existen las dos cuentas.</p>
    <div class="aviso aviso--amarillo">
      <strong>Falta un paso, y es importante:</strong> borra el archivo <code>instalar.php</code>
      desde el Administrador de archivos de cPanel. Mientras siga ahí, cualquiera que lo encuentre
      ve esta pantalla.
    </div>
    <a class="btn btn--negro" href="<?= e(url('/')) ?>">Entrar al HUB</a>
  </div>

<?php else: ?>

  <?php if ($errores): ?>
    <div class="aviso aviso--rojo">
      <?php foreach ($errores as $x): ?><div><?= e($x) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($paso === 1): ?>
    <div class="tarjeta">
      <h1>Antes de empezar</h1>
      <p class="muted">Esto es lo que el HUB necesita del servidor. Todo tiene que estar en verde.</p>
      <ul class="chequeos">
        <?php $todo_ok = true; foreach (chequeos() as [$que, $ok, $det]): $todo_ok = $todo_ok && $ok; ?>
          <li class="<?= $ok ? 'ok' : 'mal' ?>">
            <span class="chequeos__i"><?= $ok ? '✓' : '×' ?></span>
            <span class="chequeos__t"><?= e($que) ?></span>
            <span class="chequeos__d"><?= e((string)$det) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($todo_ok): ?>
        <a class="btn btn--amarillo" href="?paso=2">CONTINUAR</a>
      <?php else: ?>
        <p class="aviso aviso--rojo">Arregla lo que está en rojo y recarga esta página.</p>
      <?php endif; ?>
    </div>

  <?php elseif ($paso === 2): ?>
    <div class="tarjeta">
      <h1>La base de datos</h1>
      <p class="muted">Estos datos los creaste en cPanel → MySQL Databases. La contraseña la escribes
         aquí y se guarda en un archivo fuera de la carpeta pública: no pasa por ningún chat.</p>
      <form method="post" class="form">
        <?= campo_csrf() ?>
        <input type="hidden" name="paso" value="3">
        <div class="form__fila">
          <label>Servidor<input type="text" name="host" value="<?= e($bd['host']) ?>" required></label>
          <label class="corto">Puerto<input type="text" name="puerto" value="<?= e((string)$bd['puerto']) ?>"></label>
        </div>
        <label>Nombre de la base
          <input type="text" name="nombre" value="<?= e($bd['nombre']) ?>" placeholder="pelugrxd_wakahub" required autocomplete="off"></label>
        <label>Usuario
          <input type="text" name="usuario" value="<?= e($bd['usuario']) ?>" placeholder="pelugrxd_wakahub" required autocomplete="off"></label>
        <label>Contraseña
          <input type="password" name="clave" required autocomplete="new-password"></label>
        <button class="btn btn--amarillo" type="submit">PROBAR LA CONEXIÓN</button>
      </form>
    </div>

  <?php elseif ($paso === 3 || $paso === 4): ?>
    <div class="tarjeta">
      <?php if ($paso === 3): ?>
        <div class="aviso aviso--verde">Conexión correcta con <strong><?= e($_SESSION['inst_bd']['nombre'] ?? '') ?></strong>.</div>
      <?php endif; ?>
      <h1>Las dos primeras cuentas</h1>
      <p class="muted">Una de Administración y una de Dirección. Cada quien escribe su propia
         contraseña: nadie más la va a ver, tampoco el sistema. Los 19 asesores se dan de alta
         después desde Configuración › Usuarios.</p>

      <form method="post" class="form">
        <?= campo_csrf() ?>
        <input type="hidden" name="paso" value="4">

        <fieldset class="bloque">
          <legend>Administración</legend>
          <div class="form__fila">
            <label>Nombre<input type="text" name="admin_nombre" value="<?= e($_POST['admin_nombre'] ?? '') ?>" required></label>
            <label>Apellidos<input type="text" name="admin_apellidos" value="<?= e($_POST['admin_apellidos'] ?? '') ?>"></label>
          </div>
          <label>Correo<input type="email" name="admin_email" value="<?= e($_POST['admin_email'] ?? '') ?>" required autocomplete="off"></label>
          <div class="form__fila">
            <label>Contraseña<input type="password" name="admin_clave" required autocomplete="new-password"></label>
            <label>Repítela<input type="password" name="admin_clave2" required autocomplete="new-password"></label>
          </div>
        </fieldset>

        <fieldset class="bloque">
          <legend>Dirección</legend>
          <div class="form__fila">
            <label>Nombre<input type="text" name="ceo_nombre" value="<?= e($_POST['ceo_nombre'] ?? '') ?>" required></label>
            <label>Apellidos<input type="text" name="ceo_apellidos" value="<?= e($_POST['ceo_apellidos'] ?? '') ?>"></label>
          </div>
          <label>Correo<input type="email" name="ceo_email" value="<?= e($_POST['ceo_email'] ?? '') ?>" required autocomplete="off"></label>
          <div class="form__fila">
            <label>Contraseña<input type="password" name="ceo_clave" required autocomplete="new-password"></label>
            <label>Repítela<input type="password" name="ceo_clave2" required autocomplete="new-password"></label>
          </div>
        </fieldset>

        <p class="mini">La contraseña necesita al menos 10 caracteres, una mayúscula, una minúscula y un número.</p>
        <button class="btn btn--negro" type="submit">CREAR LAS TABLAS Y ENTRAR</button>
      </form>
    </div>
  <?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
