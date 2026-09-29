<?php
declare(strict_types=1);

/**
 * Foto de perfil.
 * Lo que la pantalla promete: JPG o PNG · cuadrada · mínimo 400×400 · máximo 2 MB.
 * El HUB recorta al centro y guarda dos tamaños. Si no hay foto se muestran las
 * iniciales sobre un círculo — nunca un ícono de persona genérico ni un hueco.
 */
const FOTO_MAX_BYTES = 2 * 1024 * 1024;
const FOTO_MIN_LADO  = 400;
const FOTO_LADO      = 512;
const FOTO_MINI      = 128;

function guardar_foto(array $archivo, int $usuario_id): array
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No se eligió ninguna imagen.'];
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'La imagen no llegó completa. Vuelve a intentarlo.'];
    }
    if ($archivo['size'] > FOTO_MAX_BYTES) {
        return ['ok' => false, 'error' => 'La imagen pesa más de 2 MB.'];
    }
    // Es la comprobación que sostiene todo lo demás: sin ella, un tmp_name
    // manipulado convertiría esta función en un lector de archivos del servidor.
    if (!is_uploaded_file((string)($archivo['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'La imagen no llegó por el formulario.'];
    }

    $info = @getimagesize($archivo['tmp_name']);
    if (!$info) return ['ok' => false, 'error' => 'Ese archivo no es una imagen.'];

    [$ancho, $alto, $tipo] = $info;
    if (!in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return ['ok' => false, 'error' => 'La foto tiene que ser JPG o PNG.'];
    }
    if (min($ancho, $alto) < FOTO_MIN_LADO) {
        return ['ok' => false, 'error' => 'La foto es pequeña: necesita al menos 400 × 400 px.'];
    }
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'error' => 'No se pudo procesar la imagen. Prueba con otra o avisa a Administración.'];
    }

    $origen = $tipo === IMAGETYPE_JPEG
        ? @imagecreatefromjpeg($archivo['tmp_name'])
        : @imagecreatefrompng($archivo['tmp_name']);
    if (!$origen) return ['ok' => false, 'error' => 'No se pudo leer la imagen.'];

    // Recorte cuadrado al centro
    $lado = min($ancho, $alto);
    $x = (int) (($ancho - $lado) / 2);
    $y = (int) (($alto  - $lado) / 2);

    $dir = HUB_SUBIDAS . '/fotos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $base = 'u' . $usuario_id . '-' . substr(token(6), 0, 8);
    $ok = true;

    foreach ([FOTO_LADO => '', FOTO_MINI => '-mini'] as $tam => $sufijo) {
        $destino = imagecreatetruecolor($tam, $tam);
        // El lienzo nace negro. Un PNG recortado o con fondo transparente
        // saldría con un marco negro alrededor de la cara: se pinta en blanco.
        imagefilledrectangle($destino, 0, 0, $tam, $tam, imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $origen, 0, 0, $x, $y, $tam, $tam, $lado, $lado);
        $ok = imagejpeg($destino, $dir . '/' . $base . $sufijo . '.jpg', 86) && $ok;
        imagedestroy($destino);
    }
    imagedestroy($origen);

    if (!$ok) return ['ok' => false, 'error' => 'No se pudo guardar la foto.'];

    borrar_foto_anterior($usuario_id);
    return ['ok' => true, 'archivo' => $base . '.jpg'];
}

function borrar_foto_anterior(int $usuario_id): void
{
    $anterior = valor('SELECT foto FROM usuarios WHERE id = ?', [$usuario_id]);
    if (!$anterior) return;
    $dir = HUB_SUBIDAS . '/fotos/';
    $stem = preg_replace('/\.jpg$/', '', (string)$anterior);
    // Solo se borran archivos con el patrón que genera el propio HUB.
    if (preg_match('/^u\d+-[a-f0-9]{8}$/', (string)$stem)) {
        @unlink($dir . $stem . '.jpg');
        @unlink($dir . $stem . '-mini.jpg');
    }
}
