<?php
declare(strict_types=1);

/**
 * Permisos y ámbito.
 *
 * Dos cosas distintas, y no se mezclan:
 *   ROL    = qué puedes HACER  → tabla rol_permiso
 *   ÁMBITO = cuánto puedes VER → columna usuarios.ambito ('propio'|'equipo'|'todo')
 *
 * Y dos comprobaciones separadas: una para leer y otra para escribir.
 * El ámbito 'equipo' es de SOLO LECTURA: un líder ve los pedidos de los suyos
 * pero no puede tocarlos.
 */

/**
 * Las claves de permiso de UN rol, cacheadas por petición.
 *
 * Se cachea por rol y no «por el que está dentro» porque hay preguntas que se
 * hacen sobre OTRA persona —«¿a este le avisamos?»— y con una sola caché
 * global la segunda pregunta contestaba con los permisos de la primera.
 */
function permisos_del_rol(int $rol_id, bool $olvidar = false): array
{
    static $cache = [];
    if ($olvidar) { $cache = []; return []; }
    if (isset($cache[$rol_id])) return $cache[$rol_id];

    $filas = todas(
        'SELECT p.clave FROM rol_permiso rp
           JOIN permisos p ON p.id = rp.permiso_id
          WHERE rp.rol_id = ?',
        [$rol_id]
    );
    return $cache[$rol_id] = array_column($filas, 'clave');
}

/**
 * Todas las claves de permiso de quien está dentro.
 *
 * Sin caché propia: la de verdad vive en permisos_del_rol(), y dos cachés de
 * lo mismo son dos sitios que vaciar y uno que alguien olvidará.
 */
function mis_permisos(bool $olvidar = false): array
{
    if ($olvidar) { permisos_del_rol(0, true); return []; }

    $u = yo();
    return $u ? permisos_del_rol((int)$u['rol_id']) : [];
}

/**
 * Tira la caché de permisos. La llama yo_olvidar(): si se olvida quién está
 * dentro pero se conservan SUS permisos, lo siguiente que pregunte «¿puede?»
 * responde por la persona anterior. En una petición normal no pasa nunca —una
 * petición, una persona— pero en el banco de pruebas se cambia de cuenta a
 * media pasada, y ahí sí mentía.
 */
function permisos_olvidar(): void
{
    mis_permisos(true);
}

/** ¿Puede hacer esto? Acepta 'clientes.crear' o comodín 'clientes.*'. */
function puede(string $permiso): bool
{
    return puede_el(yo(), $permiso);
}

/**
 * Lo mismo, pero de OTRA persona. Existe porque hay decisiones que se toman
 * sobre un usuario que no es el que está mirando —a quién se le avisa, por
 * ejemplo— y preguntarlo con puede() contestaba siempre por el de la sesión.
 */
function puede_el(?array $u, string $permiso): bool
{
    if (!$u) return false;
    if (($u['rol'] ?? '') === 'desarrollador') return true;   // el rol técnico lo puede todo

    $tengo = permisos_del_rol((int)($u['rol_id'] ?? 0));
    if (in_array($permiso, $tengo, true)) return true;

    $grupo = explode('.', $permiso)[0] . '.*';
    return in_array($grupo, $tengo, true);
}

/** Igual que puede(), pero corta con 403. */
function exigir(string $permiso): void
{
    if (!puede($permiso)) {
        cortar(403, 'Esto no es para tu perfil',
                    'Tu cuenta no tiene acceso a esta sección. Si crees que debería, pídeselo a Administración.');
    }
}

/* ─────────────────────────  ÁMBITO  ───────────────────────── */

/**
 * ¿Este usuario ve más de un país?
 * Solo Dirección y Desarrollador. Administración se queda en el suyo.
 * Existe para que la regla se escriba UNA vez: repetirla en cada consulta
 * es exactamente cómo se cuela la que se olvida.
 */
function cruza_paises(?array $u = null): bool
{
    $u ??= yo();
    return $u !== null && in_array($u['rol'], ['direccion', 'desarrollador'], true);
}

/**
 * Roles que este usuario puede REPARTIR y TOCAR.
 *
 * Es una jerarquía, no una lista negra. Si fuera una lista negra ("todos menos
 * desarrollador"), Administración se fabricaría una cuenta de Dirección, leería
 * la contraseña temporal en pantalla y con ella cruzaría a los demás países:
 * ESA es la escalada que hay que impedir, y por eso 'direccion' no aparece en
 * la fila de administración.
 *
 * Administración sí puede crear y editar otras cuentas de Administración.
 * No es una escalada: ya puede hacer todo lo que esa cuenta haría, así que el
 * movimiento es lateral, no hacia arriba. Y cerrarlo tenía un costo peor —
 * obligar a que el CEO entre a crear cada cuenta termina en que alguien le
 * presta la suya. Cada alta queda en la bitácora con quién la hizo, y la
 * columna `creado_por` guarda el rastro.
 *
 * Lo que sigue cerrado, y no se abre: nadie se edita a sí mismo el rol, el
 * ámbito ni el país (eso se comprueba aparte, en el controlador).
 */
function roles_que_puedo_dar(?array $u = null): array
{
    $u ??= yo();
    return match ($u['rol'] ?? '') {
        'desarrollador'  => ['direccion', 'administracion', 'facturacion', 'asesor', 'almacen', 'marketing'],
        'direccion'      => ['administracion', 'facturacion', 'asesor', 'almacen', 'marketing'],
        // Administración puede dar de alta a Facturación: es un rol POR DEBAJO
        // del suyo —no configura, no vende, no anula—, así que no es subir de
        // nivel. Facturación no reparte ningún rol. Almacén y Marketing (3g)
        // tampoco suben: hacen una parte de lo que Administración ya hace.
        'administracion' => ['administracion', 'facturacion', 'asesor', 'almacen', 'marketing'],
        default          => [],
    };
}

/** ¿Puedo crear, editar o apagar una cuenta que hoy tiene este rol? */
function puedo_tocar_rol(string $rol_clave, ?array $u = null): bool
{
    return in_array($rol_clave, roles_que_puedo_dar($u), true);
}

/**
 * Devuelve el trozo de WHERE que limita una consulta al ámbito de quien mira.
 *
 *   [$sql, $params] = filtro_ambito('p.asesor_id', 'p.pais_id', 'p.equipo_id');
 *   $filas = todas("SELECT ... WHERE $sql", $params);
 *
 * SIEMPRE se aplica en el servidor. Ocultar el menú no es seguridad.
 */
function filtro_ambito(string $col_asesor, ?string $col_pais = null, ?string $col_equipo = null,
                       ?array $u = null): array
{
    $u ??= yo();
    if (!$u) return ['1 = 0', []];

    $sql = [];
    $par = [];

    switch ($u['ambito']) {
        case 'todo':
            // Dirección y Desarrollador cruzan países. Administración se queda en el suyo.
            if ($col_pais && !cruza_paises($u)) {
                $sql[] = "$col_pais = ?";
                $par[] = $u['pais_id'];
            }
            break;

        case 'equipo':
            if ($col_equipo && $u['equipo_id']) {
                $sql[] = "($col_equipo = ? OR $col_asesor = ?)";
                $par[] = $u['equipo_id'];
                $par[] = $u['id'];
            } else {
                $sql[] = "$col_asesor = ?";
                $par[] = $u['id'];
            }
            if ($col_pais) { $sql[] = "$col_pais = ?"; $par[] = $u['pais_id']; }
            break;

        case 'propio':
        default:
            $sql[] = "$col_asesor = ?";
            $par[] = $u['id'];
            break;
    }

    return [$sql ? implode(' AND ', $sql) : '1 = 1', $par];
}

/**
 * ¿Puedo LEER un registro cuyo dueño es $asesor_id?
 * $equipo_id es el equipo del dueño, si la tabla lo guarda.
 */
function puedo_ver(?int $asesor_id, ?int $pais_id = null, ?int $equipo_id = null): bool
{
    $u = yo();
    if (!$u) return false;

    if ($u['ambito'] === 'todo') {
        if (cruza_paises($u)) return true;
        return $pais_id === null || (int)$pais_id === (int)$u['pais_id'];
    }
    if ($u['ambito'] === 'equipo') {
        if ((int)$asesor_id === (int)$u['id']) return true;
        return $equipo_id !== null && $u['equipo_id'] && (int)$equipo_id === (int)$u['equipo_id'];
    }
    return (int)$asesor_id === (int)$u['id'];
}

/**
 * ¿Puedo ESCRIBIR sobre un registro DE OPERACIÓN de $asesor_id?
 * Esto es para pedidos, clientes, pagos y visitas: cosas que tienen dueño.
 * Distinta de puedo_ver a propósito: el ámbito 'equipo' no da escritura.
 *
 * NO se usa para la configuración (usuarios, equipos, metas, listas): eso se
 * gobierna con puede('usuarios.gestionar') y con el país, porque Dirección sí
 * configura aunque no toque una sola venta.
 */
function puedo_editar(?int $asesor_id, ?int $pais_id = null): bool
{
    $u = yo();
    if (!$u) return false;

    if ($u['rol'] === 'desarrollador') return true;
    if ($u['rol'] === 'direccion')     return false;   // Dirección mira, no toca

    /* Facturación tampoco toca registros de operación: no edita un pedido, no
       lo anula y no registra pagos ajenos. Lo único que escribe es la
       CONFIRMACIÓN de un pago, y eso no pasa por aquí — va por el permiso
       `pagos.verificar` y por la comprobación de país de su controlador. */
    if ($u['rol'] === 'facturacion')   return false;

    if ($u['rol'] === 'administracion') {
        return $pais_id === null || (int)$pais_id === (int)$u['pais_id'];
    }
    /* Almacén y Marketing (3g) no escriben registros de operación: no son
       dueños de ninguno. Se dice aquí y no se deja caer en la línea de abajo,
       que hoy daría lo mismo por casualidad. */
    if (in_array($u['rol'], ['almacen', 'marketing'], true)) return false;
    return (int)$asesor_id === (int)$u['id'];           // el asesor, solo lo suyo
}

/**
 * ¿PUEDO VENDERLE A ESTE CLIENTE? Una sola definición (3j): la usan el
 * buscador de la venta, la ficha del cliente y el formulario del pedido.
 * Es el suyo, o no tiene dueño y es de su país; Administración, los de su
 * país. Ver sql_puedo_venderle(), que es lo mismo dicho en SQL.
 */
function puedo_venderle(array $cli): bool
{
    if (($cli['asesor_id'] ?? null) === null) {
        $u = yo();
        if (!$u || in_array($u['rol'], ['direccion', 'facturacion', 'almacen', 'marketing'], true)) return false;
        return $u['rol'] === 'desarrollador' || (int)$cli['pais_id'] === (int)$u['pais_id'];
    }
    return puedo_editar((int)$cli['asesor_id'], (int)$cli['pais_id']);
}

/** puedo_venderle() en SQL. `$c` es el alias de `clientes`. → [sql, parámetros] */
function sql_puedo_venderle(string $c = 'c'): array
{
    $u = yo();
    if (!$u) return ['1 = 0', []];
    if ($u['rol'] === 'desarrollador') return ['1 = 1', []];
    if (in_array($u['rol'], ['direccion', 'facturacion', 'almacen', 'marketing'], true)) return ['1 = 0', []];
    if ($u['rol'] === 'administracion') return ["$c.pais_id = ?", [(int)$u['pais_id']]];
    return ["($c.asesor_id = ? OR ($c.asesor_id IS NULL AND $c.pais_id = ?))", [(int)$u['id'], (int)$u['pais_id']]];
}

/** Corta con 403 si no puede ver. */
function exigir_ver(?int $asesor_id, ?int $pais_id = null, ?int $equipo_id = null): void
{
    if (!puedo_ver($asesor_id, $pais_id, $equipo_id)) {
        cortar(403, 'Esto no es tuyo',
                    'Este registro es de otra persona. Si necesitas verlo, pídeselo a Administración.');
    }
}

/**
 * Para las acciones que se autorizan por PERMISO y no por dueño —confirmar un
 * pago, denegarlo, anotar un comprobante—: hay que poder VER el registro, y
 * además no estar en el ámbito «equipo».
 *
 * El ámbito «equipo» es de solo lectura en todo el HUB, y `puedo_ver()` sí lo
 * concede. Hoy no es alcanzable —hace falta `pagos.verificar` para llegar, y a
 * quien confirma pagos el alta le fuerza el ámbito «todo»— pero es exactamente
 * la vía por la que ya se coló una cuenta con el ámbito cambiado a mano.
 */
function exigir_ver_para_escribir(?int $asesor_id, ?int $pais_id = null, ?int $equipo_id = null): void
{
    exigir_ver($asesor_id, $pais_id, $equipo_id);

    $u = yo();
    if ($u && ($u['ambito'] ?? '') === 'equipo') {
        cortar(403, 'Solo lectura',
                    'Tu cuenta ve lo de tu equipo, pero no escribe sobre ello.');
    }
}

/** Corta con 403 si no puede editar. */
function exigir_editar(?int $asesor_id, ?int $pais_id = null): void
{
    if (!puedo_editar($asesor_id, $pais_id)) {
        cortar(403, 'Solo lectura',
                    'Puedes mirar este registro, pero no modificarlo. Quien lo registró o Administración sí pueden.');
    }
}

/**
 * ¿A esta persona le avisamos de que entró un pago por confirmar?
 *
 * Lo puede confirmar cualquiera con `pagos.verificar` —Facturación,
 * Administración y Dirección—, pero AVISAR es otra cosa. El CEO no está para
 * vaciar una bandeja: un contador rojo que no piensa vaciar se deja de mirar
 * en tres días, y con él se dejan de mirar los demás. Lo pidió el usuario.
 */
function le_avisamos_de_pagos(?array $u = null): bool
{
    $u ??= yo();
    if (!$u || !puede_el($u, 'pagos.verificar')) return false;
    return in_array($u['rol'] ?? '', ['facturacion', 'administracion', 'desarrollador'], true);
}

/**
 * Hay roles en los que el ámbito no se elige: se deduce del trabajo.
 *
 * La regla es una sola frase: QUIEN CONFIRMA EL DINERO, LO VE. Confirmar un
 * pago es decir «este voucher es bueno», y para eso hay que poder abrir el
 * voucher, la ficha del pedido y los datos del cliente. Con el ámbito en «lo
 * suyo», que es como sale el desplegable por defecto, la cuenta confirmaba el
 * pago y recibía 403 al abrir el voucher de ESE MISMO pago: confirmaba dinero
 * a ciegas. Eso no se arregla con un aviso en pantalla —el que da de alta no
 * lo lee—, se fija al guardar.
 *
 * La primera versión de esta regla decía «confirma pagos Y no vende», y por
 * eso dejaba fuera a Administración, que confirma tanto como Facturación y
 * repetía el agujero entera. La condición correcta es solo la primera.
 *
 * Va por permisos y no por lista de roles a mano: el rol nuevo que confirme
 * pagos hereda el ámbito correcto sin que nadie se acuerde de este sitio.
 */
function ambito_forzado_de_rol(int $rol_id): ?string
{
    $p = permisos_del_rol($rol_id);
    $tiene = static fn(string $c): bool =>
        in_array($c, $p, true) || in_array(explode('.', $c)[0] . '.*', $p, true);

    if ($tiene('pagos.verificar')) return 'todo';
    /* QUIEN NO VENDE NO TIENE NADA «SUYO» (3g). «Lo suyo» son los clientes y
       los pedidos que uno registró; Almacén y Marketing no registran ninguno,
       así que con ese ámbito su lista de clientes o de lo que va a salir
       estaría siempre vacía. Mismo razonamiento: por permiso, no por rol. */
    if (!$tiene('pedidos.crear')) return 'todo';
    return null;
}

/** ¿Este usuario compite por metas y rankings? Dirección y Desarrollador no. */
function compite_en_metas(?array $u = null): bool
{
    $u ??= yo();
    return $u && in_array($u['rol'], ['asesor'], true);
}
