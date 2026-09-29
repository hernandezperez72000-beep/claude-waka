<?php
declare(strict_types=1);
/**
 * LAS DESCARGAS DE FOTOS DE VERDAD (3b.4).
 *
 * Las demás tandas sustituyen la descarga por una función de mentira, así que
 * el código que de verdad habla con la red no corría nunca en las pruebas. Por
 * ahí se coló el fallo del 3b.3: una opción de descarga que el PHP 8.1 del
 * hosting no tiene. Aquí se levanta un servidor https de fotos en esta misma
 * máquina y se baja de él, por las dos vías (seis a la vez y una a una) y con
 * las dos formas de vigilar el tamaño (la del PHP 8.2 y la del 8.1).
 *
 * Necesita python3 y openssl. Sin ellos, se salta y lo dice.
 */
require_once __DIR__ . '/comun.php';
banco_crear(sys_get_temp_dir() . '/waka-pruebas5.sqlite');

$python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($python === '' || !extension_loaded('openssl') || !function_exists('curl_multi_init')) {
    echo "Se salta: hace falta python3, openssl y curl.\n";
    exit(0);
}

$DIR = sys_get_temp_dir() . '/waka-fotos5-' . getmypid();
@mkdir($DIR . '/servidas', 0755, true);
@mkdir($DIR . '/mini', 0755, true);
$GLOBALS['__tienda_fotos_dir'] = $DIR . '/mini';

/* Un certificado para «localhost», hecho ahora. */
$clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$cnf = $DIR . '/openssl.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\n"
                      . "basicConstraints=critical,CA:TRUE\n");
$csr = openssl_csr_new(['commonName' => 'localhost'], $clave, ['config' => $cnf, 'digest_alg' => 'sha256']);
$crt = openssl_csr_sign($csr, null, $clave, 2, ['config' => $cnf, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256']);
openssl_x509_export_to_file($crt, $DIR . '/c.pem');
openssl_pkey_export_to_file($clave, $DIR . '/k.pem');

/* Las fotos que sirve. */
$imagen = function (int $w, int $h, array $color = [200, 30, 30]): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, ...$color));
    ob_start(); imagejpeg($im, null, 90); imagedestroy($im);
    return (string) ob_get_clean();
};
file_put_contents($DIR . '/servidas/ok.jpg', $imagen(1200, 900));
for ($i = 1; $i <= 8; $i++) file_put_contents($DIR . '/servidas/f' . $i . '.jpg', $imagen(400, 400, [10 * $i, 90, 160]));

$PUERTO = 18443 + (getmypid() % 500);
$srv = proc_open([$python, __DIR__ . '/fotos_https.py', (string)$PUERTO, $DIR . '/c.pem', $DIR . '/k.pem', $DIR . '/servidas'],
                 [1 => ['file', '/dev/null', 'w'], 2 => ['file', $DIR . '/srv.log', 'w']], $tubos);
register_shutdown_function(function () use ($srv, $DIR) {
    @proc_terminate($srv);
    foreach (array_merge(glob($DIR . '/*/*') ?: [], glob($DIR . '/*') ?: []) as $f) if (is_file($f)) @unlink($f);
    @rmdir($DIR . '/servidas'); @rmdir($DIR . '/mini'); @rmdir($DIR);
});
for ($i = 0; $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $PUERTO, $e1, $e2, 0.2);
    if ($s) { fclose($s); break; }
    usleep(100000);
}

$U = fn(string $n) => 'https://localhost:' . $PUERTO . '/' . $n;
$red = ['permitir' => ['127.0.0.1'], 'ca' => $DIR . '/c.pem'];
$mini = fn() => count(glob($DIR . '/mini/w*.jpg') ?: []);

grupo('3b.4 · la red interna sigue cerrada');
unset($GLOBALS['__tienda_fotos_red_prueba']);
$r0 = tienda_bajar_fotos([1 => $U('ok.jpg')]);
ok('sin el permiso del banco, «localhost» es la red interna: no se baja y queda como mala',
   $r0['ok'] === [] && isset($r0['malas'][1]));
es('y no deja nada en el disco', 0, $mini());

foreach ([['seis a la vez', false, false], ['una a una', true, false],
          ['seis a la vez, con el PHP 8.1', false, true], ['una a una, con el PHP 8.1', true, true]] as [$via, $una, $viejo]) {
    grupo('3b.4 · bajar de verdad: ' . $via);
    $GLOBALS['__tienda_fotos_red_prueba'] = $red;
    $GLOBALS['__tienda_fotos_una_a_una'] = $una;
    $GLOBALS['__tienda_sin_xferinfo'] = $viejo;
    foreach (glob($DIR . '/mini/*') ?: [] as $f) @unlink($f);

    $r = tienda_bajar_fotos([
        1 => $U('ok.jpg'), 2 => $U('no.jpg'), 3 => $U('redirige.jpg'), 4 => $U('ocupada.jpg'),
        5 => $U('prohibida.jpg'), 6 => $U('texto.jpg'), 7 => $U('grande.jpg'), 8 => $U('grande-sin-decir.jpg'),
        9 => $U('cortada.jpg'), 10 => $U('ido.jpg'), 11 => $U('muchas.jpg'),
    ]);
    ok('una foto buena se baja y queda en miniatura', isset($r['ok'][1]) && is_file($DIR . '/mini/' . ($r['ok'][1] ?? 'x')),
       json_encode($r) . ' ' . @file_get_contents($DIR . '/srv.log'));
    $tam = @getimagesize($DIR . '/mini/' . ($r['ok'][1] ?? 'x'));
    es('de 240 × 240', [240, 240], [$tam[0] ?? 0, $tam[1] ?? 0]);
    ok('la que no existe (404), mala', isset($r['malas'][2]));
    ok('la que ya no está (410), mala', isset($r['malas'][10]));
    ok('la que redirige, mala, y NO se sigue la redirección', isset($r['malas'][3]));
    ok('la que no es una foto, mala', isset($r['malas'][6]));
    ok('la que dice pesar más de 8 MB, mala', isset($r['malas'][7]), json_encode($r));
    ok('la que pasa de 8 MB sin decirlo, mala', isset($r['malas'][8]), json_encode($r));
    usleep(300000);
    $escrito = (int) @file_get_contents($DIR . '/servidas/escrito.txt');
    ok('Y SE CORTA AL PASAR EL TOPE: no se baja entera (manda 64 MB)', $escrito > 0 && $escrito < 32 * 1024 * 1024,
       round($escrito / 1048576, 1) . ' MB');
    @unlink($DIR . '/servidas/escrito.txt');
    ok('la tienda ocupada (503) no la marca: se vuelve a pedir', !isset($r['ok'][4]) && !isset($r['malas'][4]));
    ok('un «prohibido» del cortafuegos (403) tampoco', !isset($r['ok'][5]) && !isset($r['malas'][5]));
    ok('ni un «demasiadas» (429)', !isset($r['ok'][11]) && !isset($r['malas'][11]));
    ok('UNA FOTO CORTADA A MEDIAS NO SE GUARDA NI SE MARCA: se vuelve a pedir',
       !isset($r['ok'][9]) && !isset($r['malas'][9]), json_encode($r));
    es('en el disco, solo la buena', 1, $mini());

    $GLOBALS['__tienda_resolver'] = fn(string $h) => [];
    $rd = tienda_bajar_fotos([31 => $U('ok.jpg')]);
    ok('si el nombre no contesta, ni entra ni queda como mala: otro día', $rd['ok'] === [] && $rd['malas'] === []);
    unset($GLOBALS['__tienda_resolver']);

    $r8 = tienda_bajar_fotos(array_combine(range(21, 28), array_map(fn($i) => $U('f' . $i . '.jpg'), range(1, 8))));
    es('ocho fotos, en dos tandas: llegan las ocho', 8, count($r8['ok']));
}
unset($GLOBALS['__tienda_fotos_una_a_una'], $GLOBALS['__tienda_sin_xferinfo']);

grupo('3b.4 · el guardado entero, con las fotos de verdad');
$GLOBALS['__tienda_fotos_red_prueba'] = $red;
foreach (glob($DIR . '/mini/*') ?: [] as $f) @unlink($f);
banco_entrar(banco_usuario('administracion'));
$PAIS = catalogo_pais();
guardar_ajuste('woo_pais', (string)$PAIS);
ajustes_olvidar();
$filas = [];
foreach ([[1, 'ok.jpg'], [2, 'cortada.jpg'], [3, 'no.jpg']] as [$i, $f]) {
    $filas[] = tienda_fila_producto(['id' => 7700 + $i, 'sku' => 'PRD-77000' . $i, 'name' => 'Real ' . $i, 'type' => 'simple',
                                     'regular_price' => '10', 'images' => [['id' => 5500 + $i, 'src' => $U($f)]]]);
}
try {
    $id = tienda_lectura_nueva($filas, $PAIS);
    $g = tienda_importar($id);
} catch (Throwable $ex) {
    $g = ['ok' => false, 'error' => get_class($ex) . ': ' . $ex->getMessage()];
}
ok('se guarda', (bool)($g['ok'] ?? false), (string)($g['error'] ?? ''));
es('entra una foto', 1, (int)($g['resumen']['fotos_ok'] ?? -1));
es('una no sirve (la que no existe)', 1, (int)($g['resumen']['fotos_malas'] ?? -1));
es('la cortada queda para la próxima', null, valor("SELECT foto_woo FROM productos WHERE sku = 'PRD-770002'"));
ok('el de la foto buena la tiene', foto_producto_valida((string) valor("SELECT foto FROM productos WHERE sku = 'PRD-770001'")));

exit(marcador());
