<?php
declare(strict_types=1);

/**
 * Las frases del saludo.
 *
 * NO se generan con IA en cada entrada: costaría dinero por visita, tardaría,
 * fallaría sin señal y nadie habría revisado qué le dice el HUB al CEO a la
 * una de la mañana. Viven en la tabla `frases`, se siembran una vez y se
 * editan desde Configuración › Saludo y frases.
 *
 * La elección es una consulta: al azar dentro de la franja horaria y del rol,
 * evitando la que salió la última vez.
 */
function frase_del_dia(?string $rol = null, ?string $franja = null): ?array
{
    $franja ??= franja_del_dia();
    $ultima  = $_SESSION['ultima_frase'] ?? 0;

    // Primero se buscan las escritas para ese rol; si no hay, las generales.
    foreach ([$rol, null] as $r) {
        $sql = 'SELECT * FROM frases
                 WHERE activo = 1 AND franja = ? AND id <> ?
                   AND ' . ($r ? 'rol_clave = ?' : 'rol_clave IS NULL') . '
                 ORDER BY RAND() LIMIT 1';
        $par = $r ? [$franja, $ultima, $r] : [$franja, $ultima];
        $f = una($sql, $par);
        if ($f) {
            $_SESSION['ultima_frase'] = (int)$f['id'];
            try { q('UPDATE frases SET usos = usos + 1 WHERE id = ?', [$f['id']]); } catch (Throwable) {}
            return $f;
        }
    }
    return null;
}

/** Saludo por nombre y hora: "Buenos días, Viviana" · "Bienvenido, Jefe". */
function saludo_para(array $u): string
{
    $franja = franja_del_dia();

    if ($u['rol'] === 'direccion') {
        $tratamiento = ajuste('tratamiento_direccion', 'Jefe');
        return match ($franja) {
            'madrugada' => 'Bienvenido, ' . $tratamiento,
            'manana'    => 'Buenos días, ' . $tratamiento,
            'tarde'     => 'Buenas tardes, ' . $tratamiento,
            default     => 'Buenas noches, ' . $tratamiento,
        };
    }

    $nombre = primer_nombre($u['nombre']);
    return match ($franja) {
        'madrugada' => 'Buenas noches, ' . $nombre,
        'manana'    => 'Buenos días, ' . $nombre,
        'tarde'     => 'Buenas tardes, ' . $nombre,
        default     => 'Buenas noches, ' . $nombre,
    };
}

/**
 * La línea de datos del saludo. Se arma con piezas: lo que vale cero
 * desaparece entero, no sale "0 contenedores".
 * Nunca se mezcla con la frase motivacional: son dos campos distintos.
 */
function linea_de_datos(array $u): string
{
    $piezas = [];

    /* Ojo con el sujeto: TODO lo de aquí se mide sobre $u, no sobre quien
       tenga la sesión abierta. Los permisos con puede_el($u,…) y las cifras
       con el país de $u. Preguntar los permisos de uno y las cifras del otro
       es la clase de error que no da error: solo cuenta mal, en silencio.

       Y el país: Dirección cruza. Su cabecera dice «Perú · todos los países»
       y la línea de al lado contaba solo Perú. */
    $solo_pais = cruza_paises($u) ? null : (int)$u['pais_id'];

    /* Cada pieza va por el PERMISO de la pantalla a la que lleva el dato. A
       Facturación le salía «3 contenedores en camino» y /stock le contestaba
       403: un dato que no se puede abrir es peor que no tenerlo. */
    try {
        if (puede_el($u, 'stock.ver')) {
            $en_camino = lotes_contar($solo_pais, "estado IN ('en_camino','en_aduana')
                                                   AND fecha_llegada_real IS NULL");
            if ($en_camino === 1) $piezas[] = 'un contenedor en camino';
            elseif ($en_camino > 1) $piezas[] = $en_camino . ' contenedores en camino';
        }

        if (puede_el($u, 'lotes.gestionar')) {
            $sin_precio = lotes_sin_precio_n($solo_pais);
            if ($sin_precio === 1) $piezas[] = 'un lote esperando precios';
            elseif ($sin_precio > 1) $piezas[] = $sin_precio . ' lotes esperando precios';
        }

        if (puede_el($u, 'recepcion.ver')) {
            $esperando = visitas_esperando($solo_pais);
            if ($esperando === 1) $piezas[] = 'un cliente esperando en oficina';
            elseif ($esperando > 1) $piezas[] = $esperando . ' clientes esperando en oficina';
        }

        if (le_avisamos_de_pagos($u)) {
            $porValidar = pagos_por_validar_resumen($u)['sin_revisar'];
            /* «sin revisar», no «esperando confirmación»: la cifra es la de los
               que nadie ha mirado, y los que están en espera también esperan
               confirmación. La palabra tiene que decir lo que el número cuenta. */
            if ($porValidar === 1) $piezas[] = 'un pago sin revisar';
            elseif ($porValidar > 1) $piezas[] = $porValidar . ' pagos sin revisar';
        }
    } catch (Throwable $ex) {
        error_log('[HUB] linea_de_datos: ' . $ex->getMessage());
    }

    if (!$piezas) return '';
    if (count($piezas) === 1) return ucfirst($piezas[0]) . '.';
    $ultima = array_pop($piezas);
    return ucfirst(implode(', ', $piezas)) . ' y ' . $ultima . '.';
}

/** Cuenta lotes con una condición, en un país o en todos (null = todos). */
function lotes_contar(?int $pais_id, string $condicion): int
{
    $sql = "SELECT COUNT(*) FROM lotes WHERE $condicion";
    return $pais_id === null
        ? (int) valor($sql)
        : (int) valor($sql . ' AND pais_id = ?', [$pais_id]);
}

/* ─────────────────────────  AJUSTES  ───────────────────────── */

/** Lee un ajuste de la tabla `ajustes`. Todo lo configurable vive ahí. */
/**
 * QUIÉN CUMPLE AÑOS HOY, dentro del ámbito de quien mira.
 *
 * La fecha de nacimiento se pide al dar de alta la cuenta (usuario,
 * 2026-09-23) y esto es lo único que el HUB hace con ella: decirlo el día que
 * toca, en el Inicio de quien lleva al equipo. No sale en ninguna lista
 * pública ni se usa para nada más.
 */
function cumplen_hoy(?array $u = null): array
{
    $u ??= yo();
    if (!$u || !columna_existe('usuarios', 'fecha_nacimiento')) return [];
    [$amb, $par] = filtro_ambito('us.id', 'us.pais_id', 'us.equipo_id');
    $par[] = date('m-d');
    return todas("SELECT us.id, us.nombre, us.apellidos FROM usuarios us
                   WHERE us.activo = 1 AND us.fecha_nacimiento IS NOT NULL
                     AND $amb
                     AND " . (bd_es_sqlite()
                          ? "strftime('%m-%d', us.fecha_nacimiento)"
                          : "DATE_FORMAT(us.fecha_nacimiento, '%m-%d')") . " = ?
                   ORDER BY us.nombre", $par);
}

function ajuste(string $clave, mixed $por_defecto = null, bool $olvidar = false): mixed
{
    static $cache = null;
    /* Con `$olvidar` se tira el cache: hace falta justo después de guardar un
       ajuste en la misma petición, o la pantalla repinta el valor viejo y
       parece que no se guardó. Ver ajustes_olvidar(). */
    if ($olvidar) { $cache = null; return null; }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (todas('SELECT clave, valor, tipo FROM ajustes') as $a) {
                $cache[$a['clave']] = match ($a['tipo']) {
                    'entero'  => (int)$a['valor'],
                    'booleano'=> (bool)(int)$a['valor'],
                    'json'    => json_decode((string)$a['valor'], true),
                    default   => $a['valor'],
                };
            }
        } catch (Throwable $ex) {
            error_log('[HUB] ajustes: ' . $ex->getMessage());
        }
    }
    return $cache[$clave] ?? $por_defecto;
}

/** Tira el cache de ajuste(). Se llama después de guardar. */
function ajustes_olvidar(): void
{
    ajuste('', null, true);
}

function guardar_ajuste(string $clave, mixed $valor): void
{
    $v = is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : (string)$valor;
    if (valor('SELECT 1 FROM ajustes WHERE clave = ?', [$clave])) {
        q('UPDATE ajustes SET valor = ? WHERE clave = ?', [$v, $clave]);
    } else {
        q('INSERT INTO ajustes (clave, valor) VALUES (?, ?)', [$clave, $v]);
    }
    bitacora('config.ajuste', 'ajuste', null, ['clave' => $clave]);
}
