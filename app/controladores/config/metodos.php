<?php
declare(strict_types=1);
seccion_activa('config');

/**
 * Configuración › Métodos de pago (3f).
 *
 * «Cuando se pague con POS, que haya dos capturas que subir: la del DNI y la
 * del voucher» (usuario, 2026-09-26). Decidido en tarjetas: POR MÉTODO y
 * OBLIGATORIA. Hasta hoy lo que pide cada método solo se cambiaba en la base:
 * aquí se ve y se cambia sin programador.
 *
 * Por método: pide voucher, pide la foto del DNI, el recargo (el 4 % del POS)
 * y si está encendido. Uno nuevo se añade aquí. Nunca se borran: se apagan,
 * porque los pagos viejos tienen que seguir diciendo con qué se pagó.
 */
$u = yo();
$pais = (int)$u['pais_id'];
$lista_id = (int) valor("SELECT id FROM listas WHERE clave = 'metodos_pago'");
if (!$lista_id) cortar(404, 'Falta la lista de métodos de pago');
$hay_dni = columna_existe('lista_items', 'pide_dni');
$errores = [];

/** Un método de ESTA lista y de mi país (o de todos). */
$mio = function (int $id) use ($lista_id, $pais): ?array {
    return una('SELECT * FROM lista_items WHERE id = ? AND lista_id = ? AND (pais_id IS NULL OR pais_id = ?)',
               [$id, $lista_id, $pais]);
};
/** «4», «4.5», «3,75» → centésimas de punto (400, 450, 375); null si no se entiende. */
$recargo = function (string $t): ?int {
    $t = str_replace([',', '%', ' '], ['.', '', ''], trim($t));
    if ($t === '') return 0;
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $t)) return null;
    $c = (int) round((float)$t * 100);
    return $c <= 2000 ? $c : null;
};
/* El nombre, único EN MI PAÍS (México puede tener su «Yape»). */
$nombre_libre = function (string $v, int $salvo = 0) use ($lista_id, $pais): bool {
    return !valor('SELECT 1 FROM lista_items WHERE lista_id = ? AND (pais_id IS NULL OR pais_id = ?)
                     AND LOWER(valor) = LOWER(?) AND id <> ?', [$lista_id, $pais, $v, $salvo]);
};
/**
 * UN MÉTODO DE TODOS LOS PAÍSES SE HACE DE MI PAÍS AL CAMBIARLO (auditoría
 * del 3f). Los de fábrica valen para todos; si Perú apaga el POS o le quita
 * la foto del DNI, México no puede quedarse sin él. Con más de un país, el
 * que lo cambia se lo queda y los demás reciben su copia tal como estaba.
 */
$hacer_mio = function (array $m) use ($pais): int {
    if ($m['pais_id'] !== null) return (int)$m['id'];
    $paises = array_map('intval', array_column(todas('SELECT id FROM paises ORDER BY id'), 'id'));
    if (count($paises) <= 1) return (int)$m['id'];
    /* LA FILA DE SIEMPRE SE QUEDA CON EL PAÍS CUYOS PAGOS CUELGAN DE ELLA
       (verificación del 3f): si se la llevaba el que edita, los pagos viejos
       del otro país pasaban a decir «POS MX» y sus reportes dejaban de
       encontrarlos. Sin pagos, con el primer país (el de la instalación). */
    $dueno = (int) (valor('SELECT pe.pais_id FROM pagos pg JOIN pedidos pe ON pe.id = pg.pedido_id
                            WHERE pg.metodo_item_id = ? GROUP BY pe.pais_id ORDER BY COUNT(*) DESC, pe.pais_id LIMIT 1',
                          [(int)$m['id']]) ?: $paises[0]);
    $mio = (int)$m['id'];
    foreach ($paises as $pa) {
        if ($pa === $dueno) continue;
        $c = $m; unset($c['id']);
        $c['pais_id'] = $pa;
        $nuevo = insertar('lista_items', $c);
        if ($pa === $pais) $mio = $nuevo;
    }
    actualizar('lista_items', (int)$m['id'], ['pais_id' => $dueno]);
    return $mio;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = pedir('accion');
    if ($accion === 'guardar') {
        $m = $mio(pedir_int('id'));
        $nombre = trim(mb_substr((string) pedir('valor'), 0, 60));
        $rec = $recargo((string) pedir('recargo'));
        if (!$m) $errores[] = 'Ese método no existe.';
        elseif (mb_strlen($nombre) < 2) $errores[] = 'Escribe el nombre del método.';
        elseif (!$nombre_libre($nombre, (int)$m['id'])) $errores[] = 'Ya hay un método con ese nombre.';
        elseif ($rec === null) $errores[] = 'El recargo es un porcentaje: 4 o 3.5 (hasta 20).';
        /* Sin ningún método encendido no se registra ninguna venta. */
        if (!$errores && pedir('activo') !== '1' && (int)$m['activo'] === 1
            && (int) valor('SELECT COUNT(*) FROM lista_items WHERE lista_id = ? AND activo = 1 AND (pais_id IS NULL OR pais_id = ?)',
                           [$lista_id, $pais]) <= 1) {
            $errores[] = 'Tiene que quedar al menos un método encendido: sin él no se puede registrar ninguna venta.';
        }
        if (!$errores) {
            $d = ['valor' => $nombre, 'pide_comprobante' => pedir('pide_comprobante') === '1' ? 1 : 0,
                  'activo' => pedir('activo') === '1' ? 1 : 0];
            if ($hay_dni) $d['pide_dni'] = pedir('pide_dni') === '1' ? 1 : 0;
            if (columna_existe('lista_items', 'recargo_centesimas')) $d['recargo_centesimas'] = $rec;
            en_transaccion(function () use ($m, $d, $hacer_mio, $nombre) {
                $id_m = $hacer_mio($m);
                actualizar('lista_items', $id_m, $d);
                /* RENOMBRADO: se apunta el nombre de antes, para que la próxima
                   actualización no vuelva a crear el de fábrica (auditoría). */
                if ($nombre !== (string)$m['valor']) metodo_renombrado_apuntar((string)$m['valor']);
                bitacora('config.metodo', 'lista_item', $id_m, $d);
            });
            avisar('ok', 'Guardado: ' . $nombre . '.');
            ir('/configuracion/metodos-pago');
        }
    }
    if ($accion === 'nuevo') {
        $nombre = trim(mb_substr((string) pedir('valor'), 0, 60));
        if (mb_strlen($nombre) < 2) $errores[] = 'Escribe el nombre del método nuevo.';
        elseif (!$nombre_libre($nombre)) $errores[] = 'Ya hay un método con ese nombre.';
        if (!$errores) {
            $orden = (int) valor('SELECT COALESCE(MAX(orden), 0) FROM lista_items WHERE lista_id = ?', [$lista_id]) + 10;
            /* Nace pidiendo voucher: lo seguro es que alguien pueda mirar el
               comprobante. Se quita aquí mismo si no hace falta. */
            $id = insertar('lista_items', ['lista_id' => $lista_id, 'pais_id' => $pais, 'valor' => $nombre,
                                           'orden' => $orden, 'activo' => 1, 'pide_comprobante' => 1]);
            bitacora('config.metodo_nuevo', 'lista_item', $id, ['valor' => $nombre]);
            avisar('ok', 'Añadido: ' . $nombre . '. Revisa qué pide.');
            ir('/configuracion/metodos-pago');
        }
    }
}

$metodos = todas('SELECT * FROM lista_items WHERE lista_id = ? AND (pais_id IS NULL OR pais_id = ?)
                   ORDER BY activo DESC, orden, valor', [$lista_id, $pais]);

pagina('config/metodos', ['metodos' => $metodos, 'errores' => $errores, 'hay_dni' => $hay_dni],
       ['titulo' => 'Métodos de pago', 'migaja' => 'Configuración', 'sin_titulo' => true]);
