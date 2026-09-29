<?php
declare(strict_types=1);
seccion_activa('config');

$yo_soy = yo();
$id     = pedir_int('id', 'get');
$fila   = null;

$asignables = roles_que_puedo_dar($yo_soy);

if ($id) {
    $fila = una('SELECT us.*, r.clave AS rol_clave FROM usuarios us
                   JOIN roles r ON r.id = us.rol_id WHERE us.id = ?', [$id]);
    if (!$fila) cortar(404, 'Ese usuario no existe');
    // Nadie edita una cuenta de su mismo nivel o por encima: Administración no
    // degrada al CEO, no le cambia el correo y no se queda con su cuenta.
    if ($id !== (int)$yo_soy['id'] && !in_array($fila['rol_clave'], $asignables, true)) {
        cortar(403, 'Esa cuenta no es tuya',
                    'Solo Dirección puede tocar esa cuenta. Si hace falta un cambio, pídeselo.');
    }
    // Administración solo toca gente de su país.
    if (!cruza_paises($yo_soy)
        && (int)$fila['pais_id'] !== (int)$yo_soy['pais_id']) {
        cortar(403, 'Esto no es tuyo', 'Ese usuario es de otro país.');
    }
}

// Quien no cruza países solo ve —y solo puede mandar— lo de su país: así el
// formulario no ofrece nada que el servidor vaya a rechazar después, y el HTML
// no filtra la estructura de los demás países.
$solo_pais = cruza_paises($yo_soy) ? null : (int) $yo_soy['pais_id'];
$f_pais    = $solo_pais ? ' AND pais_id = ' . $solo_pais : '';

$paises   = $solo_pais
    ? todas('SELECT * FROM paises WHERE activo = 1 AND id = ? ORDER BY nombre', [$solo_pais])
    : todas('SELECT * FROM paises WHERE activo = 1 ORDER BY nombre');
$oficinas = todas("SELECT * FROM oficinas WHERE activo = 1$f_pais ORDER BY pais_id, orden");
$equipos  = todas("SELECT * FROM equipos WHERE activo = 1$f_pais ORDER BY pais_id, orden, nombre");
$roles    = todas('SELECT * FROM roles ORDER BY orden');

/* Solo se ofrecen los roles que quien edita puede repartir (ver la jerarquía
   en roles_que_puedo_dar): Administración solo crea asesores.

   Con una excepción: el rol que la ficha YA tiene. Nadie puede repartir su
   propio rol —Dirección no crea Dirección— y como la autoedición fija
   rol_id al que ya tiene, la validación de más abajo lo rechazaba: el CEO no
   podía corregirse ni una letra del apellido, y como Administración tampoco
   puede tocar una cuenta de Dirección, esa ficha no la podía arreglar nadie.
   Ofrecer el rol actual no reparte nada: es el que ya está puesto. */
$roles = array_values(array_filter($roles, fn($r) =>
    in_array($r['clave'], $asignables, true)
    || ($fila && (int)$fila['rol_id'] === (int)$r['id'])));

$errores = [];
$clave_temporal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'nombre'     => pedir('nombre'),
        'apellidos'  => pedir('apellidos'),
        'email'      => mb_strtolower(pedir('email')),
        'celular'    => pedir('celular'),
        'documento'  => pedir('documento'),
        'fecha_nacimiento' => pedir('fecha_nacimiento'),
        'pais_id'    => pedir_int('pais_id'),
        'oficina_id' => pedir_int('oficina_id'),
        'equipo_id'  => pedir_int('equipo_id'),
        'rol_id'     => pedir_int('rol_id'),
        // Ojo: el desplegable llega DESHABILITADO cuando el rol fija el ámbito,
        // y un campo deshabilitado no se envía. Ver $ambito_pedido más abajo.
        'ambito'     => pedir('ambito') ?: 'propio',
        'nivel_cuota'=> pedir_int('nivel_cuota'),
    ];

    /* Lo que la persona MARCÓ, antes de que nada lo pise. Se guarda aquí y no
       más abajo porque el aviso del final compara con esto para decir «guardé
       otra cosa»; capturado después, el aviso se callaba justo en los dos
       casos en que había algo que contar.

       Y `null` cuando el campo NO VINO: el desplegable llega deshabilitado
       siempre que el rol fija el ámbito, y un campo deshabilitado no se envía.
       Tomando el 'propio' de relleno como si fuera una elección, el aviso
       saltaba en cada guardado diciendo «quedó en Todo y no en Lo suyo» a
       alguien que había visto «Todo» en pantalla y ni pudo tocarlo. */
    $ambito_pedido = array_key_exists('ambito', $_POST) && $_POST['ambito'] !== ''
        ? (string)$_POST['ambito']
        : null;

    // ── Primero se fija lo que NO elige quien envía el formulario ────────
    // Administración se queda en su país: no lo elige, lo hereda.
    if (!cruza_paises($yo_soy)) {
        $d['pais_id'] = (int) $yo_soy['pais_id'];
    }
    // Nadie se cambia a sí mismo el rol, el ámbito ni el país.
    if ($id && $id === (int)$yo_soy['id']) {
        $d['rol_id']  = (int) $fila['rol_id'];
        $d['ambito']  = (string) $fila['ambito'];
        $d['pais_id'] = (int) $fila['pais_id'];
    }
    if (!in_array($d['ambito'], ['propio','equipo','todo'], true)) $d['ambito'] = 'propio';
    // Y nadie reparte un ámbito más ancho que el suyo.
    $escala = ['propio' => 1, 'equipo' => 2, 'todo' => 3];
    if ($escala[$d['ambito']] > $escala[$yo_soy['ambito'] ?? 'propio']) {
        $d['ambito'] = $yo_soy['ambito'] ?? 'propio';
    }
    /* Y por último, los roles donde el ámbito no es una elección. Va DESPUÉS
       del tope de arriba a propósito: no lo está repartiendo quien edita, lo
       exige el trabajo del rol. Una cuenta de Facturación en «lo suyo»
       confirma pagos y luego recibe 403 al abrir el voucher de ese pago. */
    /* Editarse a uno mismo no cambia el ámbito, ni para arriba: arriba está
       escrito «nadie se cambia a sí mismo el rol, el ámbito ni el país», y una
       garantía que se cumple casi siempre no es una garantía. Las cuentas que
       quedaran con el ámbito estrecho las arregla la migración o un compañero
       desde esta misma pantalla. */
    if ($d['rol_id'] && !($id && $id === (int)$yo_soy['id'])) {
        $forzado = ambito_forzado_de_rol((int)$d['rol_id']);
        if ($forzado !== null) {
            $d['ambito'] = $forzado;
        } elseif ($id && !empty($fila['rol_id'])
                  && (int)$fila['rol_id'] !== (int)$d['rol_id']
                  && ambito_forzado_de_rol((int)$fila['rol_id']) === (string)$d['ambito']) {
            /* Y al revés: quien DEJA de confirmar pagos no se queda con el
               ámbito ancho que le puso el rol viejo. Una cuenta de Facturación
               pasada a Asesor se quedaba viendo todos los clientes y todos los
               pedidos del país — un ensanchamiento que nadie eligió. Vuelve a
               «lo suyo»; si de verdad se le quiere dar más, se le da a
               propósito en una segunda edición, que es como se dan las cosas
               que importan. */
            $d['ambito'] = 'propio';
        }
    }

    /* Quien no vende no va en un equipo de ventas (3g): contaría como uno
       más del equipo y en sus metas. */
    $no_vende = $d['rol_id'] && !puede_el(['rol' => (string) valor('SELECT clave FROM roles WHERE id = ?', [(int)$d['rol_id']]),
                                            'rol_id' => (int)$d['rol_id']], 'pedidos.crear');
    if ($no_vende && !($id && $id === (int)$yo_soy['id'])) {
        $d['equipo_id'] = null;
    }

    // ── Y ahora sí, se valida lo que queda ───────────────────────────────
    if ($d['nombre'] === '')         $errores[] = 'Falta el nombre.';
    if (!correo_valido($d['email'])) $errores[] = 'El correo no es válido.';
    if (!$d['pais_id'])              $errores[] = 'Elige el país.';
    if (!$d['rol_id'])               $errores[] = 'Elige el rol.';

    // Longitudes: la base de datos las corta, y cortar sin avisar es un 500 en blanco.
    foreach ([['nombre',80],['apellidos',80],['email',120],['celular',25],['documento',20]] as [$campo, $max]) {
        if (mb_strlen((string)$d[$campo]) > $max) {
            $errores[] = 'El campo ' . $campo . ' no puede pasar de ' . $max . ' caracteres.';
        }
    }
    if ($d['nivel_cuota'] < 0 || $d['nivel_cuota'] > 9) $d['nivel_cuota'] = 0;

    /* LA FECHA DE NACIMIENTO. Opcional, y con dos topes de sentido común: una
       fecha futura o de hace 110 años es un dedazo, no un cumpleaños. Si la
       columna todavía no existe —falta actualizar.php— ni se guarda ni
       estorba, como el resto de columnas nuevas del HUB. */
    if (!columna_existe('usuarios', 'fecha_nacimiento')) {
        unset($d['fecha_nacimiento']);
    } elseif (trim((string)$d['fecha_nacimiento']) === '') {
        $d['fecha_nacimiento'] = null;
    } elseif (!fecha_valida((string)$d['fecha_nacimiento'])
              || $d['fecha_nacimiento'] > date('Y-m-d')
              || $d['fecha_nacimiento'] < date('Y-m-d', strtotime('-110 years'))) {
        $errores[] = 'Esa fecha de nacimiento no parece real.';
        $d['fecha_nacimiento'] = null;
    }

    // El rol tiene que estar entre los que quien edita puede repartir.
    // El desplegable ya los filtra, pero el formulario se puede manipular.
    $ids_de_rol = array_map('intval', array_column($roles, 'id'));
    if (!in_array($d['rol_id'], $ids_de_rol, true)) {
        $errores[] = 'Ese rol no existe o no puedes asignarlo.';
    }
    if (cruza_paises($yo_soy) && $d['pais_id']
        && !valor('SELECT id FROM paises WHERE id = ? AND activo = 1', [$d['pais_id']])) {
        $errores[] = 'Ese país no existe.';
    }

    $repe = valor('SELECT id FROM usuarios WHERE email = ?' . ($id ? ' AND id <> ?' : ''),
                  $id ? [$d['email'], $id] : [$d['email']]);
    if ($repe) $errores[] = 'Ya hay una cuenta con ese correo.';

    // La oficina tiene que ser del país elegido: el desplegable ya lo filtra,
    // pero se comprueba también aquí porque el formulario se puede manipular.
    if ($d['oficina_id']) {
        $ok = valor('SELECT id FROM oficinas WHERE id = ? AND pais_id = ?', [$d['oficina_id'], $d['pais_id']]);
        if (!$ok) $errores[] = 'Esa oficina no es del país elegido.';
    }
    if ($d['equipo_id']) {
        $ok = valor('SELECT id FROM equipos WHERE id = ? AND pais_id = ? AND activo = 1',
                    [$d['equipo_id'], $d['pais_id']]);
        if (!$ok) $errores[] = 'Ese equipo no existe, está apagado o no es del país elegido.';
    }

    // La meta es de la persona, no del rol: puede quedar vacía y usar la general.
    $meta_txt = pedir('meta');
    if ($meta_txt === '') {
        $d['meta_mensual_centimos'] = null;
    } else {
        $meta_cent = a_centimos($meta_txt);
        if ($meta_cent === null) {
            $errores[] = 'La meta no se entiende. Escríbela en soles, así: 30000 o 30000.50';
            $d['meta_mensual_centimos'] = null;
        } elseif (!dinero_razonable($meta_cent)) {
            // Una meta negativa o de quince dígitos no revienta nada, pero deja
            // al asesor sin barra de meta sin que nadie entienda por qué.
            $errores[] = 'Esa meta no puede ser: tiene que estar entre 0 y '
                       . soles(DINERO_MAXIMO_CENTIMOS) . '.';
            $d['meta_mensual_centimos'] = null;
        } else {
            $d['meta_mensual_centimos'] = $meta_cent;
        }
    }

    if (!$errores) {
        $foto_nueva = null;
        if (!empty($_FILES['foto']['name'])) {
            $r = guardar_foto($_FILES['foto'], $id ?: 0);
            if ($r['ok']) $foto_nueva = $r['archivo'];
            else $errores[] = $r['error'];
        }
    }

    /* EL NIVEL DE CUOTA (5a) tiene su historia: un cambio rige desde la
       quincena siguiente, así que no se escribe a pelo en la ficha. */
    $nivel_nuevo = (int)($d['nivel_cuota'] ?? 0);
    $con_historia = function_exists('bonos_listo') && bonos_listo();
    if ($con_historia) unset($d['nivel_cuota']);

    if (!$errores) {
        if ($id) {
            if ($foto_nueva) $d['foto'] = $foto_nueva;
            actualizar('usuarios', $id, $d);
            if ($con_historia && $nivel_nuevo !== nivel_de($id, date('Y-m-d'))
                && $nivel_nuevo !== (int) valor('SELECT COALESCE(nivel_cuota, 0) FROM usuarios WHERE id = ?', [$id])) {
                $desde_n = usuario_nivel_poner($id, $nivel_nuevo);
                if ($desde_n > date('Y-m-d')) avisar('info', 'El nivel nuevo vale desde el ' . fecha_corta($desde_n) . ': la quincena que va no cambia.');
            } elseif ($con_historia && $nivel_nuevo === nivel_de($id, date('Y-m-d'))) {
                usuario_nivel_poner($id, $nivel_nuevo);      // deshace un cambio programado
            }
            /* CAMBIAR EL ROL CIERRA LAS SESIONES RECORDADAS (auditoría del 3g):
               un asesor recordado 30 días que pasa a Almacén seguiría dentro
               en ese celular con permisos de cambiar la web, que es justo lo
               que el alta no deja recordar. Que vuelva a entrar. */
            if (!empty($fila['rol_id']) && (int)$fila['rol_id'] !== (int)$d['rol_id']) {
                q('DELETE FROM sesiones WHERE usuario_id = ?', [$id]);
                /* Y si deja de vender, deja de liderar un equipo de ventas. */
                if ($no_vende && columna_existe('equipos', 'lider_usuario_id')) {
                    q('UPDATE equipos SET lider_usuario_id = NULL WHERE lider_usuario_id = ?', [$id]);
                }
            }
            bitacora('usuario.editar', 'usuario', $id, ['email' => $d['email']]);
            /* Si el ámbito guardado no es el que se eligió, se dice. Guardar
               una cosa distinta de la que la persona marcó y contestar «quedó
               guardado» es la forma más barata de que deje de leer los avisos. */
            $nombres_ambito = ['propio' => 'Lo suyo', 'equipo' => 'Su equipo', 'todo' => 'Todo'];
            $porque = $id === (int)$yo_soy['id']
                ? 'nadie se cambia el ámbito a sí mismo. Pídeselo a otra persona con acceso a Usuarios.'
                : 'lo decide el rol. Si de verdad hace falta otro, cámbialo ahora en otra edición.';
            avisar('ok', 'Los datos de ' . primer_nombre($d['nombre']) . ' quedaron guardados.'
                . ($ambito_pedido !== null && $ambito_pedido !== $d['ambito']
                   ? ' El ámbito quedó en «' . ($nombres_ambito[$d['ambito']] ?? $d['ambito'])
                     . '» y no en «' . ($nombres_ambito[$ambito_pedido] ?? $ambito_pedido)
                     . '»: ' . $porque
                   : ''));
            ir('/usuarios');
        } else {
            // Contraseña temporal: se muestra UNA vez y la persona la cambia al entrar.
            $clave_temporal = clave_temporal();
            $d['password_hash']         = password_hash($clave_temporal, PASSWORD_DEFAULT);
            $d['debe_cambiar_password'] = 1;
            $d['creado_por']            = $yo_soy['id'];
            if ($foto_nueva) $d['foto'] = $foto_nueva;

            $nuevo = insertar('usuarios', $d);
            if ($con_historia && $nivel_nuevo > 0) usuario_nivel_poner($nuevo, $nivel_nuevo);

            // La foto se guardó con id 0 si venía en el alta: se renombra.
            if ($foto_nueva) {
                $dir = HUB_SUBIDAS . '/fotos/';
                $stem = preg_replace('/\.jpg$/', '', $foto_nueva);
                $nuevo_stem = 'u' . $nuevo . '-' . substr($stem, strrpos($stem, '-') + 1);
                @rename($dir . $stem . '.jpg',      $dir . $nuevo_stem . '.jpg');
                @rename($dir . $stem . '-mini.jpg', $dir . $nuevo_stem . '-mini.jpg');
                actualizar('usuarios', $nuevo, ['foto' => $nuevo_stem . '.jpg']);
            }

            bitacora('usuario.crear', 'usuario', $nuevo, ['email' => $d['email'], 'rol' => $d['rol_id']]);
            $_SESSION['clave_temporal'] = ['id' => $nuevo, 'nombre' => $d['nombre'],
                                           'email' => $d['email'], 'clave' => $clave_temporal];
            ir('/usuarios?nuevo=' . $nuevo);
        }
    }
    $fila = array_merge((array)$fila, $d);
}

/* Los niveles salen de La Yapa del país (5a): nombre y cuota editables en
   Configuración › Bonos, no escritos en la pantalla. */
$pais_nv = (int)(($fila['pais_id'] ?? 0) ?: $yo_soy['pais_id']);
pagina('usuarios/editar', [
    'niveles'  => function_exists('niveles_de_cuota') ? niveles_de_cuota($pais_nv) : [],
    'nivel_prox' => ($id && function_exists('bonos_listo') && bonos_listo())
        ? una('SELECT nivel, desde FROM usuario_niveles WHERE usuario_id = ? AND desde > ? ORDER BY desde LIMIT 1', [$id, date('Y-m-d')]) : null,
    'fila'     => $fila,
    'id'       => $id,
    'paises'   => $paises,
    'oficinas' => $oficinas,
    'equipos'  => $equipos,
    'roles'    => $roles,
    'errores'  => $errores,
    'soy_yo'   => $id && $id === (int)$yo_soy['id'],
], ['titulo' => $id ? 'Editar usuario' : 'Nuevo usuario',
    'subtitulo' => $id ? '' : 'La cuenta se crea con una contraseña temporal que la persona cambia al entrar']);
