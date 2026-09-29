<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * Configuración › Equipos y líderes.
 *
 * Existe para poder dar de alta a los 19 asesores de una sola pasada: primero
 * se crean los tres equipos, y al crear cada cuenta ya se elige el suyo. Y si
 * alguien quedó sin equipo, se le asigna desde aquí en bloque, sin entrar a
 * su ficha una por una.
 *
 * LÍDER DE EQUIPO NO ES UN ROL: es un asesor con ámbito «equipo», que es de
 * SOLO LECTURA. Ve los pedidos de los suyos y no puede tocarlos. Marcar la
 * estrella aquí es lo mismo que ponerle ese ámbito en su ficha, y por eso se
 * hace en un solo sitio: dos formas de marcar lo mismo acaban discrepando.
 */

$u       = yo();
$pais_id = (int)$u['pais_id'];
$todos_los_paises = cruza_paises($u);

/* Quien no cruza países solo ve y solo toca lo suyo. El filtro se aplica en
   el servidor, no escondiendo filas en la pantalla. */
$f_pais = $todos_los_paises ? '' : ' AND e.pais_id = ' . $pais_id;

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');

    /* ── Crear o editar un equipo ─────────────────────────────── */
    if ($accion === 'guardar') {
        $id     = pedir_int('id');
        $nombre = pedir('nombre');
        $oficina_id = pedir_int('oficina_id');
        $lider  = pedir_int('lider_usuario_id');

        $equipo = $id ? una('SELECT * FROM equipos WHERE id = ?', [$id]) : null;
        if ($id && !$equipo) cortar(404, 'Ese equipo no existe');

        // El país no se elige: se hereda de quien edita, o se conserva.
        $pais_equipo = $equipo ? (int)$equipo['pais_id'] : $pais_id;
        if (!$todos_los_paises && $pais_equipo !== $pais_id) {
            cortar(403, 'Esto no es tuyo', 'Ese equipo es de otro país.');
        }

        if ($nombre === '')            $errores[] = 'Falta el nombre del equipo.';
        if (mb_strlen($nombre) > 80)   $errores[] = 'El nombre no puede pasar de 80 caracteres.';

        $repe = valor('SELECT id FROM equipos WHERE pais_id = ? AND nombre = ?'
                      . ($id ? ' AND id <> ?' : ''),
                      $id ? [$pais_equipo, $nombre, $id] : [$pais_equipo, $nombre]);
        if ($repe) $errores[] = 'Ya hay un equipo con ese nombre.';

        if ($oficina_id && !valor('SELECT id FROM oficinas WHERE id = ? AND pais_id = ?',
                                  [$oficina_id, $pais_equipo])) {
            $errores[] = 'Esa oficina no es del país del equipo.';
        }

        /* El líder tiene que ser un asesor activo DE ESE EQUIPO. Si no, se
           estaría dando visibilidad sobre un equipo al que no pertenece: es
           exactamente la fuga que el ámbito debería impedir. */
        if ($lider) {
            $ok = valor("SELECT 1 FROM usuarios us JOIN roles r ON r.id = us.rol_id
                          WHERE us.id = ? AND us.activo = 1 AND r.clave = 'asesor'
                            AND us.pais_id = ? AND us.equipo_id = ?",
                        [$lider, $pais_equipo, $id ?: 0]);
            if (!$ok) $errores[] = 'El líder tiene que ser un asesor activo que ya esté en ese equipo.';
        }

        if (!$errores) {
            $datos = ['nombre' => $nombre, 'oficina_id' => $oficina_id ?: null,
                      'lider_usuario_id' => $lider ?: null];
            if ($id) {
                $lider_antes = $equipo['lider_usuario_id'] ? (int)$equipo['lider_usuario_id'] : null;
                actualizar('equipos', $id, $datos);

                /* La estrella y el ámbito son la misma decisión, así que se
                   mueven juntos. Al que deja de ser líder se le devuelve el
                   ámbito «lo suyo»: si no, seguiría viendo al equipo entero
                   sin que nada en pantalla lo diga. */
                if ($lider_antes && $lider_antes !== ($lider ?: null)) {
                    q("UPDATE usuarios SET ambito = 'propio' WHERE id = ? AND ambito = 'equipo'",
                      [$lider_antes]);
                }
                if ($lider) q("UPDATE usuarios SET ambito = 'equipo' WHERE id = ?", [$lider]);

                bitacora('equipo.editar', 'equipo', $id, ['nombre' => $nombre]);
                avisar('ok', 'El equipo «' . $nombre . '» quedó guardado.');
            } else {
                // Al crear todavía no hay miembros, así que tampoco líder.
                $datos['pais_id'] = $pais_equipo;
                $datos['lider_usuario_id'] = null;
                $datos['orden'] = (int) valor('SELECT COALESCE(MAX(orden),0) + 10 FROM equipos WHERE pais_id = ?',
                                              [$pais_equipo]);
                $nuevo = insertar('equipos', $datos);
                bitacora('equipo.crear', 'equipo', $nuevo, ['nombre' => $nombre]);
                avisar('ok', 'Equipo «' . $nombre . '» creado. Ahora ponle su gente y marca al líder.');
            }
            ir('/configuracion/equipos');
        }
    }

    /* ── Apagar o encender ────────────────────────────────────── */
    if ($accion === 'estado') {
        $id = pedir_int('id');
        $eq = $id ? una('SELECT * FROM equipos WHERE id = ?', [$id]) : null;
        if (!$eq) cortar(404, 'Ese equipo no existe');
        if (!$todos_los_paises && (int)$eq['pais_id'] !== $pais_id) cortar(403, 'Esto no es tuyo');

        $nuevo_estado = (int)$eq['activo'] === 1 ? 0 : 1;
        actualizar('equipos', $id, ['activo' => $nuevo_estado]);
        bitacora('equipo.estado', 'equipo', $id, ['activo' => $nuevo_estado]);
        avisar('ok', $nuevo_estado
            ? 'El equipo vuelve a estar activo.'
            : 'Equipo apagado. Deja de ofrecerse en las fichas nuevas, pero sus ventas '
              . 'siguen contando en los reportes históricos.');
        ir('/configuracion/equipos');
    }

    /* ── Asignar gente en bloque ──────────────────────────────────
       Esto es lo que ahorra entrar a 19 fichas una por una. */
    if ($accion === 'asignar') {
        $asignaciones = (array)($_POST['equipo_de'] ?? []);
        $cambios = 0;
        foreach ($asignaciones as $usuario_id => $equipo_id) {
            $usuario_id = (int)$usuario_id;
            $equipo_id  = (int)$equipo_id;

            $us = una("SELECT us.*, r.clave AS rol FROM usuarios us
                         JOIN roles r ON r.id = us.rol_id WHERE us.id = ?", [$usuario_id]);
            if (!$us || $us['rol'] !== 'asesor') continue;
            if (!$todos_los_paises && (int)$us['pais_id'] !== $pais_id) continue;

            // El equipo tiene que ser del MISMO país que la persona. Sin esto,
            // el formulario manipulado metería a un asesor de Perú en un equipo
            // de México y su venta aparecería en el ranking del otro país.
            if ($equipo_id) {
                $ok = valor('SELECT 1 FROM equipos WHERE id = ? AND pais_id = ? AND activo = 1',
                            [$equipo_id, (int)$us['pais_id']]);
                if (!$ok) continue;
            }
            if ((int)($us['equipo_id'] ?? 0) === $equipo_id) continue;

            // Si sale del equipo que lideraba, deja de ser líder y de tener
            // ámbito de equipo: si no, seguiría viendo a un equipo que ya no
            // es el suyo.
            q('UPDATE equipos SET lider_usuario_id = NULL WHERE lider_usuario_id = ?', [$usuario_id]);
            if (($us['ambito'] ?? '') === 'equipo') {
                q("UPDATE usuarios SET ambito = 'propio' WHERE id = ?", [$usuario_id]);
            }

            actualizar('usuarios', $usuario_id, ['equipo_id' => $equipo_id ?: null]);
            $cambios++;
        }
        bitacora('equipo.asignar', 'equipo', null, ['cambios' => $cambios]);
        avisar('ok', $cambios
            ? plural($cambios, 'asesor movido de equipo', 'asesores movidos de equipo') . '.'
            : 'No había nada que cambiar.');
        ir('/configuracion/equipos');
    }
}

$equipos = todas(
    "SELECT e.*, o.nombre AS oficina, p.nombre AS pais,
            us.nombre AS lider_nombre, us.apellidos AS lider_apellidos,
            (SELECT COUNT(*) FROM usuarios x WHERE x.equipo_id = e.id AND x.activo = 1) AS gente,
            (SELECT COUNT(*) FROM pedidos pe
               JOIN usuarios y ON y.id = pe.asesor_id
              WHERE y.equipo_id = e.id) AS pedidos
       FROM equipos e
       LEFT JOIN oficinas o ON o.id = e.oficina_id
       LEFT JOIN paises p   ON p.id = e.pais_id
       LEFT JOIN usuarios us ON us.id = e.lider_usuario_id
      WHERE 1 = 1 $f_pais
      ORDER BY e.activo DESC, e.orden, e.nombre");

$asesores = todas(
    "SELECT us.id, us.nombre, us.apellidos, us.equipo_id, us.ambito, us.foto,
            o.nombre AS oficina
       FROM usuarios us
       JOIN roles r ON r.id = us.rol_id AND r.clave = 'asesor'
       LEFT JOIN oficinas o ON o.id = us.oficina_id
      WHERE us.activo = 1" . ($todos_los_paises ? '' : ' AND us.pais_id = ' . $pais_id) . "
      ORDER BY us.equipo_id IS NULL DESC, us.nombre");

$oficinas = todas('SELECT * FROM oficinas WHERE activo = 1'
                  . ($todos_los_paises ? '' : ' AND pais_id = ' . $pais_id)
                  . ' ORDER BY orden, nombre');

$editar = pedir_int('editar', 'get');
$fila   = $editar ? una('SELECT * FROM equipos WHERE id = ?', [$editar]) : null;
if ($editar && !$fila) cortar(404, 'Ese equipo no existe');
if ($fila && !$todos_los_paises && (int)$fila['pais_id'] !== $pais_id) cortar(403, 'Esto no es tuyo');

// Los candidatos a líder son solo los que YA están en ese equipo.
$candidatos = $fila
    ? todas("SELECT us.id, us.nombre, us.apellidos FROM usuarios us
               JOIN roles r ON r.id = us.rol_id AND r.clave = 'asesor'
              WHERE us.equipo_id = ? AND us.activo = 1 ORDER BY us.nombre", [(int)$fila['id']])
    : [];

pagina('config/equipos', [
    'equipos' => $equipos, 'asesores' => $asesores, 'oficinas' => $oficinas,
    'fila' => $fila, 'candidatos' => $candidatos, 'errores' => $errores,
    'nuevo' => pedir('nuevo', 'get') === '1',
], ['titulo' => 'Equipos y líderes', 'migaja' => 'Configuración',
    'subtitulo' => 'Créalos antes de dar de alta a los asesores: así cada ficha se llena una sola vez']);
