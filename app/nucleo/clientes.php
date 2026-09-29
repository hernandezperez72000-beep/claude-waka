<?php
declare(strict_types=1);

/**
 * Clientes.
 *
 * El cliente NUNCA entra al HUB: entra a compraenwaka.com. Aquí existe solo
 * como ficha, y la crea el asesor. El documento lo identifica y es su usuario
 * en la tienda; el correo es la llave con su cuenta y con su cashback.
 */

const CLIENTE_TIPOS_DOC = [
    'DNI' => 'DNI',
    'RUC' => 'RUC',
    'CE'  => 'Carnet de Extranjería',
    'PAS' => 'Pasaporte',
];

/**
 * Valida un documento. Devuelve '' si está bien, o el motivo si no.
 *
 * El RUC se comprueba con su dígito de control: pilla el número tecleado al
 * revés ANTES de guardarlo, que es cuando se puede arreglar. Sin eso, el error
 * aparece semanas después, al facturar.
 * El Carnet de Extranjería NO se fuerza a un patrón: el formato cambia por país
 * de origen, y una validación estricta dejaría fuera a clientes reales.
 */
function documento_invalido(string $tipo, string $numero): string
{
    $n = trim($numero);
    if ($n === '') return 'Falta el número de documento.';

    switch ($tipo) {
        case 'DNI':
            if (!preg_match('/^\d{8}$/', $n)) return 'El DNI son exactamente 8 dígitos.';
            return '';

        case 'RUC':
            if (!preg_match('/^\d{11}$/', $n)) return 'El RUC son exactamente 11 dígitos.';
            if (!in_array(substr($n, 0, 2), ['10','15','16','17','20'], true)) {
                return 'Un RUC empieza en 10, 15, 16, 17 o 20.';
            }
            if (!ruc_digito_ok($n)) return 'Ese RUC no existe: revisa los números, hay uno cambiado.';
            return '';

        case 'CE':
            if (!preg_match('/^[A-Za-z0-9\-]{6,20}$/', $n)) {
                return 'El carnet de extranjería tiene entre 6 y 20 caracteres, sin espacios.';
            }
            return '';

        case 'PAS':
            if (!preg_match('/^[A-Za-z0-9\-]{5,20}$/', $n)) {
                return 'El pasaporte tiene entre 5 y 20 caracteres, sin espacios.';
            }
            return '';
    }
    return 'Ese tipo de documento no existe.';
}

/** Dígito de control del RUC peruano (módulo 11 con pesos 5,4,3,2,7,6,5,4,3,2). */
function ruc_digito_ok(string $ruc): bool
{
    if (!preg_match('/^\d{11}$/', $ruc)) return false;
    $pesos = [5,4,3,2,7,6,5,4,3,2];
    $suma  = 0;
    for ($i = 0; $i < 10; $i++) $suma += ((int)$ruc[$i]) * $pesos[$i];
    $resto = $suma % 11;
    $esperado = 11 - $resto;
    if ($esperado === 10) $esperado = 0;
    if ($esperado === 11) $esperado = 1;
    return $esperado === (int)$ruc[10];
}

/** Nombre completo para pintar. */
function cliente_nombre(array $c): string
{
    return trim((string)$c['nombre'] . ' ' . (string)($c['apellidos'] ?? ''));
}

/**
 * Busca el cliente por documento dentro del país. Se usa ANTES de dar de alta:
 * si ya existe, el asesor tiene que ir a su ficha, no crear una segunda que
 * partiría su cashback en dos.
 */
function cliente_por_documento(int $pais_id, string $documento): ?array
{
    return una('SELECT * FROM clientes WHERE pais_id = ? AND documento = ?',
               [$pais_id, trim($documento)]);
}

function cliente_por_email(int $pais_id, string $email): ?array
{
    $e = mb_strtolower(trim($email));
    if ($e === '') return null;
    return una('SELECT * FROM clientes WHERE pais_id = ? AND email = ?', [$pais_id, $e]);
}

/* ═══════════════  LEER, VALIDAR Y CREAR UNA FICHA  ═══════════════
   Estas tres funciones son la ÚNICA puerta por la que nace un cliente. Las
   usan la pantalla de Clientes y el alta rápida de dentro del pedido.

   Están aquí y no copiadas en cada controlador a propósito: si el alta rápida
   pidiera menos que la normal, en dos semanas habría dos calidades de ficha
   según por dónde entró el cliente, y nadie sabría cuál mirar. Mismas reglas,
   mismo control de duplicados, misma cola de la tienda. */

/** Toma los campos del formulario y los deja normalizados. */
function cliente_datos_del_post(int $pais_id): array
{
    $d = [
        'tipo_doc'         => pedir('tipo_doc') ?: 'DNI',
        'documento'        => strtoupper(preg_replace('/\s+/', '', pedir('documento')) ?? ''),
        'nombre'           => pedir('nombre'),
        'apellidos'        => pedir('apellidos'),
        'email'            => mb_strtolower(pedir('email')),
        'celular'          => preg_replace('/[^\d+]/', '', pedir('celular')) ?? '',
        'telefono_alt'     => preg_replace('/[^\d+]/', '', pedir('telefono_alt')) ?? '',
        'canal_item_id'    => pedir_int('canal_item_id'),
        'tipo_comprobante' => pedir('tipo_comprobante') === 'factura' ? 'factura' : 'boleta',
        'razon_social'     => pedir('razon_social'),
        'ruc_factura'      => preg_replace('/\D/', '', pedir('ruc_factura')) ?? '',
        'rubro'            => pedir('rubro'),
        'notas'            => pedir('notas'),
    ];
    /* El país NO se elige: se hereda de quien registra. Un desplegable de país
       aquí sería la puerta para mover un cliente de Perú a México desde el
       formulario. */
        $d['pais_id'] = $pais_id;
    if (!array_key_exists($d['tipo_doc'], CLIENTE_TIPOS_DOC)) $d['tipo_doc'] = 'DNI';
    /* La dirección fiscal, solo si la columna existe (parche 2u): metida a
       pelo, un HUB sin actualizar no podría guardar ningún cliente. */
    if (columna_existe('clientes', 'direccion_fiscal')) {
        $d['direccion_fiscal'] = pedir('direccion_fiscal');
    }
    return $d;
}

/**
 * Devuelve ['errores' => [...], 'repetido' => fila|null].
 * $id es el cliente que se está editando, para no chocar consigo mismo.
 */
function cliente_validar(array $d, int $pais_id, ?int $id = null): array
{
    $errores  = [];
    $repetido = null;

    if ($e = documento_invalido((string)$d['tipo_doc'], (string)$d['documento'])) $errores[] = $e;
    if ($d['nombre'] === '')    $errores[] = 'Faltan los nombres.';
    if ($d['apellidos'] === '') $errores[] = 'Faltan los apellidos.';

    /* El correo es obligatorio y no es un capricho: es la llave con su cuenta
       de compraenwaka y con su cashback. Sin correo, el cliente nunca ve su
       saldo. El Drive de hoy no lo pide, y por eso hay que empezar a pedirlo. */
    if ($d['email'] === '')              $errores[] = 'Falta el correo. Es la llave de su cuenta en la tienda y de su cashback.';
    elseif (!correo_valido($d['email'])) $errores[] = 'El correo no es válido.';

    if ($d['celular'] === '') $errores[] = 'Falta el celular.';
    if (!$d['canal_item_id'] || !lista_valida('canales', (int)$d['canal_item_id'], $pais_id)) {
        $errores[] = 'Elige cómo nos conoció.';
    }
    if ($d['tipo_comprobante'] === 'factura') {
        if ($d['ruc_factura'] === '') $errores[] = 'Para factura hace falta el RUC.';
        elseif (!ruc_digito_ok((string)$d['ruc_factura'])) $errores[] = 'Ese RUC de facturación tiene un número cambiado.';
                if ($d['razon_social'] === '') $errores[] = 'Para factura hace falta la razón social.';
        /* Una factura electrónica sin dirección fiscal no sale: NUBEFACT la
           exige. Se pide aquí, al guardar la ficha, y no en el momento de
           emitir con el cliente esperando. */
        if (array_key_exists('direccion_fiscal', $d) && trim((string)$d['direccion_fiscal']) === '') {
            $errores[] = 'Para factura hace falta la dirección fiscal.';
        }
    }
    if (array_key_exists('direccion_fiscal', $d) && mb_strlen((string)$d['direccion_fiscal']) > 160) {
        $errores[] = 'La dirección fiscal no puede pasar de 160 caracteres.';
    }

    foreach ([['nombre',140],['apellidos',80],['email',190],['celular',25],['telefono_alt',25],
              ['razon_social',160],['ruc_factura',15],['rubro',80],['notas',500],['documento',20]] as [$c,$max]) {
        if (mb_strlen((string)$d[$c]) > $max) $errores[] = "El campo $c no puede pasar de $max caracteres.";
    }

    /* Documento y correo repetidos: NO se crea una segunda ficha. Dos fichas
       de la misma persona parten su cashback en dos y nadie lo nota hasta que
       el cliente reclama.

       PERO el nombre de la ficha que choca solo se enseña si quien pregunta
       puede verla. Si no, este formulario sería un buscador encubierto: se
       teclea un documento y el HUB responde con el nombre del cliente del
       asesor de al lado — justo lo que el buscador de clientes evita a
       propósito. Se avisa igual de que existe, sin decir de quién. */
    $rep_doc = una('SELECT c.id, c.nombre, c.apellidos, c.asesor_id, c.pais_id, ua.equipo_id
                      FROM clientes c LEFT JOIN usuarios ua ON ua.id = c.asesor_id
                     WHERE c.pais_id = ? AND c.documento = ?' . ($id ? ' AND c.id <> ?' : ''),
                   $id ? [$pais_id, $d['documento'], $id] : [$pais_id, $d['documento']]);
    if ($rep_doc) {
        $errores[] = cliente_puedo_ver_ficha($rep_doc)
            ? 'Ya hay una ficha con ese documento.'
            : 'Ya hay una ficha con ese documento, y es de otro asesor. Pídesela a Administración.';
        $repetido = $rep_doc;
    }

    if ($d['email'] !== '') {
        $rep_mail = una('SELECT c.id, c.nombre, c.apellidos, c.asesor_id, c.pais_id, ua.equipo_id
                           FROM clientes c LEFT JOIN usuarios ua ON ua.id = c.asesor_id
                          WHERE c.pais_id = ? AND c.email = ?' . ($id ? ' AND c.id <> ?' : ''),
                        $id ? [$pais_id, $d['email'], $id] : [$pais_id, $d['email']]);
        if ($rep_mail) {
            $errores[] = cliente_puedo_ver_ficha($rep_mail)
                ? 'Ya hay una ficha con ese correo.'
                : 'Ya hay una ficha con ese correo, y es de otro asesor. Pídesela a Administración.';
            $repetido = $repetido ?: $rep_mail;
        }
    }

    return ['errores' => $errores, 'repetido' => $repetido];
}

/** ¿Quien está mirando puede ver esta ficha? Se usa para no delatar carteras. */
function cliente_puedo_ver_ficha(array $c): bool
{
    return puedo_ver(
        $c['asesor_id'] !== null ? (int)$c['asesor_id'] : null,
        (int)$c['pais_id'],
        isset($c['equipo_id']) && $c['equipo_id'] !== null ? (int)$c['equipo_id'] : null
    );
}

/** ¿Y puede además venderle? Es lo que decide si se le ofrece «usa esa ficha». */
function cliente_puedo_venderle(array $c): bool
{
    return puedo_venderle($c);      // la única definición (3j), en permisos.php
}

/** Crea la ficha, la mete en la cola de la tienda y lo deja en la bitácora. */
function cliente_crear(array $d, array $u): int
{
    $d['asesor_id']  = (int)$u['id'];
    $d['oficina_id'] = $u['oficina_id'] ?: null;
    $d['creado_por'] = (int)$u['id'];

    /* Entre validar y guardar cabe otra petición. El índice único impide la
       ficha duplicada —que es lo que importa—, pero sin capturar el choque el
       asesor veía una pantalla de error genérica en vez de saber que esa
       persona ya está registrada. Devolver 0 deja que quien llama lo cuente
       bien. */
    try {
        $nuevo = insertar('clientes', $d);
    } catch (PDOException $ex) {
        if (!es_choque_de_unico($ex)) throw $ex;
        return 0;
    }

    // A la cola de la tienda. El plugin de WordPress detecta por correo si esa
    // persona YA compró antes por la web y enlaza esa cuenta en vez de crear
    // una segunda: si no, quedan dos cuentas de la misma persona y el cashback
    // partido en dos.
    tienda_encolar($nuevo);

    bitacora('cliente.crear', 'cliente', $nuevo, ['documento' => $d['documento']]);
    return $nuevo;
}

/* ─────────────────────  ENLACE CON LA TIENDA  ─────────────────────
   La cuenta en compraenwaka la crea el plugin de WordPress, que todavía no
   existe. Hasta entonces cada cliente nuevo entra en cola y la ficha lo dice.
   El día que el plugin exista se procesa la cola de golpe y nadie se queda
   sin cuenta; sin cola, habría que cruzar tablas a mano para saber a quién
   le falta.

   CASO BORDE OBLIGATORIO: un cliente que ya compró antes por la web YA tiene
   cuenta. No se duplica: se detecta por correo y se enlaza la ficha a la
   cuenta que existe. Si no, quedan dos cuentas de la misma persona y el
   cashback partido en dos. */

const TIENDA_ESTADOS = [
    'sin_cuenta' => 'Sin cuenta en la tienda',
    'en_cola'    => 'Esperando a crearse en la tienda',
    'creada'     => 'Cuenta creada en la tienda',
    'enlazada'   => 'Enlazada con una cuenta que ya existía',
    'error'      => 'No se pudo crear en la tienda',
];

/** Pone al cliente en la cola de enlace con la tienda. Idempotente. */
function tienda_encolar(int $cliente_id, string $accion = 'crear_cuenta'): void
{
    try {
        $ya = valor('SELECT id FROM cola_tienda WHERE cliente_id = ? AND accion = ?',
                    [$cliente_id, $accion]);
        if ($ya) {
            q("UPDATE cola_tienda SET estado = 'pendiente' WHERE id = ? AND estado = 'error'", [$ya]);
        } else {
            insertar('cola_tienda', ['cliente_id' => $cliente_id, 'accion' => $accion]);
        }
        q("UPDATE clientes SET estado_tienda = 'en_cola'
            WHERE id = ? AND estado_tienda IN ('sin_cuenta','error')", [$cliente_id]);
    } catch (Throwable $ex) {
        // Que la tienda no esté lista no puede impedir dar de alta a un cliente.
        error_log('[HUB] cola_tienda: ' . $ex->getMessage());
    }
}

/** Cuántos clientes esperan cuenta en la tienda. Se pinta en la lista. */
function tienda_en_cola(int $pais_id): int
{
    try {
        return (int) valor("SELECT COUNT(*) FROM cola_tienda c
                              JOIN clientes cl ON cl.id = c.cliente_id
                             WHERE c.estado = 'pendiente' AND cl.pais_id = ?", [$pais_id]);
    } catch (Throwable $ex) {
        return 0;
    }
}
