<?php
declare(strict_types=1);

/**
 * Departamento › provincia › distrito.
 *
 * Por qué es una tabla y no un campo de texto: los reportes por zona agrupan
 * por distrito. Escrito a mano, "S.J.L.", "SJL" y "San Juan de Lurigancho"
 * son tres zonas distintas y el reporte de 25 zonas no cuadra nunca.
 *
 * El HUB trae el PADRÓN COMPLETO del Perú: 25 departamentos, 196 provincias y
 * 1.893 distritos. Antes traía 79 —Lima, Callao y Arequipa— y una promesa de
 * que el resto se añadiría solo desde el buscador que nunca se construyó:
 * ubigeo_anadir() no la llama ninguna pantalla. Con el padrón entero esa
 * promesa sobra, y una venta a Cusco o a Piura se puede registrar.
 */

/** Nombre normalizado para buscar: sin tildes, en minúsculas, sin dobles espacios. */
function ubigeo_busca(string $nombre): string
{
    $s = mb_strtolower(trim($nombre), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                    'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/** Cómo se escribe un nombre de sitio: cada palabra con mayúscula, salvo enlaces. */
function ubigeo_titulo(string $nombre): string
{
    /* 'el' NO va aquí. En los topónimos del Perú va con mayúscula dentro del
       nombre —Villa El Salvador, San Miguel de El Faique— y bajarlo escribía
       mal uno de los distritos más grandes de Lima en el buscador, en la ficha
       y en el mensaje que se le manda al cliente. */
    $menores = ['de','del','la','las','los','y'];
    $partes  = preg_split('/\s+/', trim($nombre)) ?: [];
    $out = [];
    foreach ($partes as $i => $p) {
        $b = mb_strtolower($p, 'UTF-8');
        $out[] = ($i > 0 && in_array($b, $menores, true))
               ? $b
               : mb_strtoupper(mb_substr($b, 0, 1), 'UTF-8') . mb_substr($b, 1, null, 'UTF-8');
    }
    return implode(' ', $out);
}

function ubigeo_departamentos(int $pais_id): array
{
    return todas("SELECT id, nombre FROM ubigeo
                   WHERE pais_id = ? AND tipo = 'departamento' AND activo = 1
                   ORDER BY nombre", [$pais_id]);
}

function ubigeo_hijos(int $padre_id): array
{
    return todas('SELECT id, nombre, tipo FROM ubigeo WHERE padre_id = ? AND activo = 1
                   ORDER BY nombre', [$padre_id]);
}

/** Una fila con su cadena completa: distrito, provincia y departamento. */
function ubigeo_de(?int $id): ?array
{
    if (!$id) return null;
    return una(
        "SELECT d.id, d.nombre AS distrito, d.capital, d.tipo, d.pais_id,
                p.id AS provincia_id, p.nombre AS provincia,
                dp.id AS departamento_id, dp.nombre AS departamento
           FROM ubigeo d
           LEFT JOIN ubigeo p  ON p.id  = d.padre_id
           LEFT JOIN ubigeo dp ON dp.id = p.padre_id
          WHERE d.id = ?", [$id]
    );
}

/** "San Juan de Lurigancho · Lima · Lima" */
function ubigeo_texto(?int $id): string
{
    $u = ubigeo_de($id);
    if (!$u) return '';
    /* Con la capital al lado cuando se llama distinto: el cliente y la agencia
       dicen «La Merced», no «Chanchamayo», y esta línea la lee una persona en
       la ficha y en la pantalla de facturar. */
    $distrito = (string)$u['distrito']
              . ($u['capital'] ? ' (' . (string)$u['capital'] . ')' : '');
    $partes = array_filter([$distrito, $u['provincia'], $u['departamento']]);
    return implode(' · ', $partes);
}

/**
 * Busca distritos por nombre. Devuelve como mucho $limite filas, cada una con
 * su cadena completa: sin la provincia al lado, "Miraflores" es ambiguo (está
 * en Lima y en Arequipa) y el asesor elegiría el equivocado.
 */
/**
 * Busca distritos, por su nombre Y POR EL DE SU CAPITAL. 357 distritos tienen
 * una capital que se llama distinto y la gente habla con ese nombre: nadie dice
 * «mándalo a Chanchamayo», dicen «a La Merced». Lo encontró el usuario buscando
 * La Merced y no encontrándola — y el padrón la tenía, en otra columna.
 *
 * Con los 1.893 del país cargados, buscar solo por el nombre
 * del distrito no alcanza: «Santa Rosa» son catorce distritos repartidos por
 * todo el Perú y son indistinguibles en la lista. Por eso se busca PALABRA A
 * PALABRA contra el distrito, su provincia y su departamento: «santa rosa
 * lima» encuentra el de Lima, «wanchaq» y «cusco» encuentran lo mismo.
 *
 * Cada palabra tiene que aparecer en alguno de los tres sitios; si no, sería
 * un colador. Y el orden pone primero el nombre exacto, después los que
 * EMPIEZAN por lo escrito —San Juan de Lurigancho antes que Cajaruro de San
 * Juan— y al final el resto, alfabético.
 *
 * Las palabras se cortan a seis para que la consulta no crezca sin tope si
 * alguien pega un párrafo. Seis y no cuatro porque hay distritos de cuatro
 * palabras —«San Juan de Lurigancho», «Villa María del Triunfo»— y con el tope
 * en cuatro la palabra que se tiraba era justo la provincia que el asesor
 * acababa de escribir: el buscador devolvía UN solo resultado, el de Lima, y
 * al pulsarlo el formulario se ponía en modo Lima. Un pedido de provincia
 * registrado como pedido de Lima, sin flete y sin agencia.
 */
/**
 * LAS ABREVIATURAS QUE USAN LOS ASESORES.
 *
 * «SJL» no está en el padrón y nunca va a estarlo, pero es como se habla: lo
 * pidió el usuario el 2026-09-20. Se expande ANTES de buscar, así que «sjl» y
 * «san juan de lurigancho» son la misma búsqueda y el orden de resultados
 * sigue siendo el de siempre.
 *
 * Solo las de Lima, que es donde hay volumen y donde los nombres son largos.
 * La lista se lee de arriba abajo y solo cambia palabras SUELTAS: «smp» se
 * convierte, «smpx» no.
 */
function ubigeo_abreviaturas(): array
{
    return [
        'sjl'         => 'san juan de lurigancho',
        'sjm'         => 'san juan de miraflores',
        'smp'         => 'san martin de porres',
        'vmt'         => 'villa maria del triunfo',
        'ves'         => 'villa el salvador',
        'vse'         => 'villa el salvador',
                /* Solo de varias palabras las que podrían pisar un nombre real:
           «cercado» suelto convertía «cercado de lima» en «lima de lima», y
           como cada palabra tiene que aparecer en algún sitio, el distrito Lima
           —que no lleva «de»— se quedaba fuera y salía Limabamba. Y «vitarte»
           ya encontraba Ate por su capital (auditoría del 2r). */
        'cercado de lima' => 'lima',
        'lima cercado'    => 'lima',
        'ate vitarte'     => 'ate',
    ];
}

/** Aplica las abreviaturas a un texto ya normalizado. */
function ubigeo_expandir(string $t): string
{
    $abr = ubigeo_abreviaturas();
    /* «S.J.L.» llega aquí como «s j l»: tres letras sueltas no son tres
       palabras, son una sigla. Se pegan antes de mirar la lista. */
    $sueltas = array_filter(explode(' ', $t));
    if (count($sueltas) > 1 && count($sueltas) <= 4) {
        $todas_una = true;
        foreach ($sueltas as $x) if (mb_strlen($x) !== 1) $todas_una = false;
        if ($todas_una) $t = implode('', $sueltas);
    }
    /* Primero las de varias palabras, que si no «ate vitarte» nunca casaría. */
    foreach ($abr as $corto => $largo) {
        if (!str_contains($corto, ' ')) continue;
        $t = trim((string) preg_replace('/\b' . preg_quote($corto, '/') . '\b/u', $largo, $t));
    }
    $palabras = array_filter(explode(' ', $t));
    foreach ($palabras as $i => $pal) {
        if (isset($abr[$pal]) && !str_contains($pal, ' ')) $palabras[$i] = $abr[$pal];
    }
    return trim(implode(' ', $palabras));
}

/**
 * LO QUE SE PARECE A LO ESCRITO, cuando no hay nada que case.
 *
 * «Que salgan similares cuando se escriba algo incorrecto» (usuario,
 * 2026-09-20). Solo se usa cuando la búsqueda normal se queda vacía: mientras
 * haya resultados exactos, mandan ellos.
 *
 * Se comparan los nombres del país en PHP y no en SQL porque ningún MySQL
 * corriente trae una distancia de edición, y son menos de dos mil nombres. Se
 * exige que el parecido sea de verdad —dos retoques como mucho, y proporcional
 * al largo— para no ofrecer «Ica» a quien escribió «Piura».
 */
function ubigeo_buscar_parecidos(int $pais_id, string $texto, int $limite = 8): array
{
    $t = ubigeo_expandir(ubigeo_busca($texto));
    if (mb_strlen($t) < 3) return [];
    /* La primera palabra es la que se compara: quien escribe «lurigancho lima»
       se equivoca en el distrito, no en el departamento. */
    $palabras = array_filter(explode(' ', $t));
    $clave = (string) reset($palabras);
    if (mb_strlen($clave) < 3) $clave = $t;

    $filas = todas(
        "SELECT d.id, d.nombre AS distrito, d.busca, d.capital,
                p.nombre AS provincia, dp.nombre AS departamento
           FROM ubigeo d
           LEFT JOIN ubigeo p  ON p.id  = d.padre_id
           LEFT JOIN ubigeo dp ON dp.id = p.padre_id
          WHERE d.pais_id = ? AND d.tipo = 'distrito' AND d.activo = 1",
        [$pais_id]
    );

    $tope = mb_strlen($clave) <= 5 ? 1 : 2;
    $cerca = [];
    foreach ($filas as $f) {
        $nombre = (string)$f['busca'];
        /* Contra el nombre entero y contra cada palabra suya: quien escribe
           «lurigancho» quiere San Juan de Lurigancho, y la distancia contra el
           nombre completo es enorme. */
        $d = levenshtein($clave, $nombre);
        foreach (explode(' ', $nombre) as $pal) {
            if (mb_strlen($pal) < 3) continue;
            $d = min($d, levenshtein($clave, $pal));
        }
        if ($d > $tope) continue;
        $f['_d'] = $d;
        $cerca[] = $f;
    }
    usort($cerca, fn($a, $b) => [$a['_d'], $a['distrito']] <=> [$b['_d'], $b['distrito']]);
    return array_slice($cerca, 0, $limite);
}

function ubigeo_buscar_distritos(int $pais_id, string $texto, int $limite = 25): array
{
    /* Las abreviaturas primero: «sjl» es «san juan de lurigancho» y a partir de
       ahí la búsqueda es la de siempre. */
    $t = ubigeo_expandir(ubigeo_busca($texto));
    if ($t === '') return [];

    $palabras = array_values(array_slice(array_filter(explode(' ', $t)), 0, 6));
    if (!$palabras) return [];

    $donde = [];
    $par   = [$pais_id];
    foreach ($palabras as $pal) {
        $donde[] = "(d.busca LIKE ? OR COALESCE(d.busca_capital, '') LIKE ?"
                 . " OR p.busca LIKE ? OR dp.busca LIKE ?)";
        $par[] = '%' . $pal . '%';
        $par[] = '%' . $pal . '%';
        $par[] = '%' . $pal . '%';
        $par[] = '%' . $pal . '%';
    }

    /* El orden se mide contra el nombre del DISTRITO, probando primero con
       todo lo escrito y luego con una palabra menos cada vez.
       Comparar solo con el texto completo era una trampa: al escribir «piura
       piura» —distrito y provincia, justo lo que la pantalla pide— ningún
       distrito se llama «piura piura», así que los dos niveles morían y
       quedaba el alfabético: Piura salía en el puesto 44 de una lista de 25.
       El asesor veía 25 distritos de verdad de su provincia, ninguno el suyo,
       y un cartel diciéndole que escribiera la provincia que ya había escrito.
       Quitando palabras por la cola, «piura» sí es un nombre exacto y sale
       primero. */
    $orden = [];
    for ($k = count($palabras); $k >= 1; $k--) {
        $trozo = implode(' ', array_slice($palabras, 0, $k));
        /* La capital cuenta igual que el nombre: quien escribe «la merced»
           quiere Chanchamayo, y tiene que salir el primero, no el decimoquinto. */
        /* COALESCE y no la columna a pelo: `busca_capital` es NULL en los
           1.536 distritos cuya capital se llama igual, y en SQL «0 OR NULL» es
           NULL, no 0 — con DESC los nulos caen al final y TODO distrito con
           capital adelantaba a los que no la tienen. «piura piura» devolvía
           Bellavista de la Unión. */
        $orden[] = "(d.busca = ? OR COALESCE(d.busca_capital, '') = ?) DESC";
        $par[]   = $trozo;
        $par[]   = $trozo;
        $orden[] = "(d.busca LIKE ? OR COALESCE(d.busca_capital, '') LIKE ?) DESC";
        $par[]   = $trozo . '%';
        $par[]   = $trozo . '%';
    }
    $orden[] = 'd.nombre';

    return todas(
        "SELECT d.id, d.nombre AS distrito, d.capital,
                p.nombre AS provincia, dp.nombre AS departamento
           FROM ubigeo d
           LEFT JOIN ubigeo p  ON p.id  = d.padre_id
           LEFT JOIN ubigeo dp ON dp.id = p.padre_id
          WHERE d.pais_id = ? AND d.tipo = 'distrito' AND d.activo = 1
            AND " . implode(' AND ', $donde) . "
          ORDER BY " . implode(', ', $orden) . "
          LIMIT " . (int)$limite,
        $par
    );
}

/**
 * Añade un sitio que no estaba y devuelve su id. Si ya estaba, devuelve el que
 * había: escribirlo dos veces no puede crear dos zonas con el mismo nombre.
 * $tipo es 'provincia' o 'distrito' y $padre_id manda: un distrito siempre
 * cuelga de una provincia, nunca suelto.
 */
function ubigeo_anadir(int $pais_id, string $tipo, ?int $padre_id, string $nombre, ?int $usuario_id = null): ?int
{
    $nombre = ubigeo_titulo(trim($nombre));
    if ($nombre === '' || mb_strlen($nombre) > 90) return null;
    if (!in_array($tipo, ['departamento','provincia','distrito'], true)) return null;
    if ($tipo !== 'departamento' && !$padre_id) return null;

    // El padre tiene que existir, ser del mismo país y del nivel de arriba.
    if ($padre_id) {
        $arriba = ['provincia' => 'departamento', 'distrito' => 'provincia'][$tipo] ?? '';
        $ok = valor('SELECT 1 FROM ubigeo WHERE id = ? AND pais_id = ? AND tipo = ?',
                    [$padre_id, $pais_id, $arriba]);
        if (!$ok) return null;
    }

    $ya = valor('SELECT id FROM ubigeo WHERE pais_id = ? AND tipo = ? AND nombre = ?
                   AND ' . ($padre_id ? 'padre_id = ?' : 'padre_id IS NULL'),
                $padre_id ? [$pais_id, $tipo, $nombre, $padre_id] : [$pais_id, $tipo, $nombre]);
    if ($ya) return (int)$ya;

    $id = insertar('ubigeo', [
        'pais_id' => $pais_id, 'tipo' => $tipo, 'padre_id' => $padre_id,
        'nombre'  => $nombre,  'busca' => ubigeo_busca($nombre),
        'creado_por' => $usuario_id,
    ]);
    bitacora('ubigeo.anadir', 'ubigeo', $id, ['tipo' => $tipo, 'nombre' => $nombre]);
    return $id;
}

/**
 * Siembra el padrón del Perú entero (departamentos, provincias y distritos).
 *
 * UNA sola definición para las dos entradas: la instalación nueva
 * (sembrar_ubigeo) y la migración del HUB que ya está en marcha. Si cada una
 * tuviera su copia, un HUB instalado y otro actualizado acabarían con listas
 * distintas y nadie se enteraría hasta que un asesor no encontrara su distrito.
 *
 * TRES COSAS QUE HACE Y QUE NO SE PUEDEN TOCAR:
 *
 * 1. CANDADO. Lo primero es bloquear la fila del país. Sin él, dos personas
 *    abriendo actualizar.php a la vez leían las dos la tabla vacía y sembraban
 *    las dos: el índice único NO lo impide, porque un departamento tiene
 *    padre_id NULL y en MySQL un único con NULL no restringe — el segundo crea
 *    su propio «Lima» y todo el país le cuelga debajo. El resultado eran 4.228
 *    filas, cada distrito dos veces e indistinguible en el buscador, sin un
 *    solo error en pantalla y sin forma de deshacerlo (limpiar.php conserva el
 *    ubigeo a propósito).
 *
 * 2. BARATA. Una sola consulta trae lo que ya hay y el resto se decide en
 *    memoria. Nada de ubigeo_anadir() aquí: esa hace un SELECT y una línea de
 *    bitácora POR FILA, y con 2.114 sitios son más de seis mil consultas en
 *    una petición de cPanel.
 *
 * 3. COMPARA POR `busca`, no por el nombre escrito. El padrón viene sin tildes
 *    y los 79 distritos que ya estaban las tienen: por nombre, «Ancon» y
 *    «Ancón» serían dos distritos de la misma provincia.
 *
 * Solo AÑADE sitios —nunca borra— así que los pedidos que ya apuntan a un
 * ubigeo no se mueven de sitio. Lo único que corrige es la GRAFÍA cuando el
 * padrón la escribe mejor que la base: «Villa el Salvador» pasa a «Villa El
 * Salvador» sin cambiar de id. Sin eso, un HUB actualizado y uno recién
 * instalado escribirían distinto el mismo distrito, y esa divergencia es justo
 * la que correr4.php existe para cazar.
 *
 * Devuelve ['puestos' => cuántos añadió, 'corregidos' => cuántos reescribió].
 */
function ubigeo_sembrar_padron(int $pais_id): array
{
    require_once HUB_APP . '/nucleo/ubigeo_peru.php';

    return en_transaccion(function () use ($pais_id) {
        /* EL CERROJO. Va sobre una fila de `ajustes` puesta solo para esto, no
           sobre la del país: `clientes`, `usuarios`, `oficinas` y `metas`
           tienen clave foránea a `paises`, y bloquear esa fila deja colgado
           cada alta de cliente mientras dura la siembra. Esta fila no la
           referencia nadie.

           El INSERT es la primera mitad del cerrojo: si dos actualizaciones
           corren a la vez, la segunda se queda esperando en la clave primaria.
           El SELECT ... FOR UPDATE es la otra mitad, para cuando la fila ya
           existe.

           NO PONER NINGUNA LECTURA NORMAL ANTES DE ESTAS DOS LÍNEAS. InnoDB
           fija la foto de la transacción en la primera lectura consistente, y
           ni un INSERT ni un SELECT ... FOR UPDATE lo son. Un simple SELECT
           aquí arriba congelaría la foto en el estado de antes del cerrojo y
           resucitaría la duplicación del país entero. */
        try {
            q("INSERT INTO ajustes (clave, valor, tipo, grupo, descripcion, tecnico)
               VALUES ('ubigeo_cerrojo', '1', 'booleano', 'general',
                       'Cerrojo de la siembra del ubigeo. No tocar.', 1)");
        } catch (Throwable $ex) {
            if (!es_choque_de_unico($ex)) throw $ex;
        }
        $cerrojo = "SELECT clave FROM ajustes WHERE clave = 'ubigeo_cerrojo'";
        if (!bd_es_sqlite()) $cerrojo .= ' FOR UPDATE';
        q($cerrojo);

        $hay = [];
        foreach (todas('SELECT id, tipo, padre_id, nombre, busca, capital FROM ubigeo WHERE pais_id = ?',
                       [$pais_id]) as $f) {
            $hay[$f['tipo'] . '|' . (int) $f['padre_id'] . '|' . $f['busca']] =
                ['id' => (int) $f['id'], 'nombre' => (string) $f['nombre'],
                 'capital' => (string) ($f['capital'] ?? '')];
        }

        $puestos = 0;
        $corregidos = 0;
        $meter = function (string $tipo, ?int $padre, string $nombre, string $capital = '')
                 use (&$hay, &$puestos, &$corregidos, $pais_id): ?int {
            $nombre = ubigeo_titulo(trim($nombre));
            if ($nombre === '' || mb_strlen($nombre) > 90) return null;
            $clave = $tipo . '|' . (int) $padre . '|' . ubigeo_busca($nombre);

            /* La capital solo cuenta si se llama DISTINTO. Guardar «Miraflores»
               como capital de Miraflores no ayuda a nadie a encontrarlo y
               duplicaría cada nombre en el buscador. */
            $capital = ubigeo_titulo(trim($capital));
            if ($capital !== '' && (mb_strlen($capital) > 90
                || ubigeo_busca($capital) === ubigeo_busca($nombre))) $capital = '';

            if (isset($hay[$clave])) {
                $ya = $hay[$clave];
                $cambia = [];
                if ($ya['nombre'] !== $nombre)   $cambia['nombre'] = $nombre;
                if ($capital !== '' && (string)$ya['capital'] !== $capital) {
                    $cambia['capital'] = $capital;
                    $cambia['busca_capital'] = ubigeo_busca($capital);
                }
                if ($cambia) {
                    actualizar('ubigeo', $ya['id'], $cambia);
                    $hay[$clave]['nombre']  = $nombre;
                    if (isset($cambia['capital'])) $hay[$clave]['capital'] = $capital;
                    $corregidos++;
                }
                return $ya['id'];
            }

            $id = insertar('ubigeo', [
                'pais_id' => $pais_id, 'tipo' => $tipo, 'padre_id' => $padre,
                'nombre'  => $nombre,  'busca' => ubigeo_busca($nombre),
                'capital' => $capital ?: null,
                'busca_capital' => $capital !== '' ? ubigeo_busca($capital) : null,
                'creado_por' => null,
            ]);
            $hay[$clave] = ['id' => $id, 'nombre' => $nombre, 'capital' => $capital];
            $puestos++;
            return $id;
        };

        foreach (ubigeo_peru() as $departamento => $provincias) {
            $dep = $meter('departamento', null, (string) $departamento);
            if (!$dep) continue;
            foreach ($provincias as $provincia => $distritos) {
                $prov = $meter('provincia', $dep, (string) $provincia);
                if (!$prov) continue;
                foreach ($distritos as $distrito) {
                    /* Un distrito es una cadena suelta o un par [nombre, capital]. */
                    if (is_array($distrito)) $meter('distrito', $prov, (string)$distrito[0], (string)($distrito[1] ?? ''));
                    else                     $meter('distrito', $prov, (string) $distrito);
                }
            }
        }

        return ['puestos' => $puestos, 'corregidos' => $corregidos];
    });
}

/** ¿El ubigeo pertenece a este país y es un distrito de verdad? */
function ubigeo_distrito_valido(?int $id, int $pais_id): bool
{
    if (!$id) return false;
    return (bool) valor("SELECT 1 FROM ubigeo WHERE id = ? AND pais_id = ?
                           AND tipo = 'distrito' AND activo = 1", [$id, $pais_id]);
}

/**
 * ¿Este distrito es Lima metropolitana o Callao?
 * Lo usa el pedido: en Lima el envío es gratis y se pide dirección; fuera de
 * Lima hay agencia, sucursal y flete que paga el cliente en destino.
 */
function ubigeo_es_lima(?int $id): bool
{
    $u = ubigeo_de($id);
    if (!$u) return false;
    return in_array(mb_strtolower((string)$u['provincia'], 'UTF-8'), ['lima', 'callao'], true);
}
