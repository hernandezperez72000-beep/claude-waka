<?php
declare(strict_types=1);

/** Cliente HTTP mínimo con tarro de galletas: para probar el HUB de verdad. */
class Navegador
{
    public array $cookies = [];
    public int $codigo = 0;
    public string $cuerpo = '';
    public array $cabeceras = [];
    private string $base;

    /** Lo que dice aceptar el «navegador». Un fetch de la pantalla manda solo application/json. */
    public string $aceptar = 'text/html,application/json';

    public function __construct(string $base) { $this->base = rtrim($base, '/'); }

    /**
     * Como `ir()`, pero mandando el formulario con un ARCHIVO adjunto, en
     * multipart, que es como lo manda el navegador de verdad.
     *
     * Hizo falta para probar el fallo que reportó el usuario —el voucher que
     * se borraba al rebotar el formulario—: ese fallo SOLO existe en un envío
     * con archivo, así que sin esto no había forma de reproducirlo, y una
     * corrección sin prueba que la reproduzca no está comprobada.
     *
     * $archivos: ['campo' => ['nombre.jpg', 'image/jpeg', $bytes]]
     */
    public function subir(string $ruta, array $post, array $archivos, bool $seguir = true): string
    {
        $limite = '----waka' . bin2hex(random_bytes(8));
        $cuerpo = '';

        /* Los campos pueden venir anidados (l_desc[], por ejemplo). Se aplanan
           con http_build_query, que es exactamente la forma de los nombres que
           PHP vuelve a armar del otro lado. */
        foreach (explode('&', http_build_query($post)) as $par) {
            if ($par === '') continue;
            [$k, $v] = array_pad(explode('=', $par, 2), 2, '');
            $cuerpo .= "--$limite\r\n"
                     . 'Content-Disposition: form-data; name="' . urldecode($k) . "\"\r\n\r\n"
                     . urldecode($v) . "\r\n";
        }
        foreach ($archivos as $campo => [$nombre, $tipo, $datos]) {
            $cuerpo .= "--$limite\r\n"
                     . 'Content-Disposition: form-data; name="' . $campo . '"; filename="' . $nombre . "\"\r\n"
                     . "Content-Type: $tipo\r\n\r\n" . $datos . "\r\n";
        }
        $cuerpo .= "--$limite--\r\n";

        return $this->ir($ruta, null, $seguir, [
            'tipo'   => 'multipart/form-data; boundary=' . $limite,
            'cuerpo' => $cuerpo,
        ]);
    }

    public function ir(string $ruta, ?array $post = null, bool $seguir = true, ?array $crudo = null): string
    {
        $url = $this->base . $ruta;
        $galletas = [];
        foreach ($this->cookies as $k => $v) $galletas[] = "$k=$v";

        $cab = ["Accept: " . $this->aceptar, "User-Agent: pruebas-waka"];
        if ($galletas) $cab[] = 'Cookie: ' . implode('; ', $galletas);

        $opciones = ['http' => [
            'method'        => ($post === null && $crudo === null) ? 'GET' : 'POST',
            'header'        => $cab,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout'       => 15,
        ]];
        if ($crudo !== null) {
            $opciones['http']['header'][] = 'Content-Type: ' . $crudo['tipo'];
            $opciones['http']['content']  = $crudo['cuerpo'];
        } elseif ($post !== null) {
            $opciones['http']['header'][] = 'Content-Type: application/x-www-form-urlencoded';
            $opciones['http']['content']  = http_build_query($post);
        }

        $this->cuerpo = (string) @file_get_contents($url, false, stream_context_create($opciones));
        $this->cabeceras = $http_response_header ?? [];
        $this->codigo = 0;
        $destino = '';
        foreach ($this->cabeceras as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $this->codigo = (int)$m[1];
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            }
            if (preg_match('/^Location:\s*(.+)$/i', $h, $m)) $destino = trim($m[1]);
        }

        if ($seguir && $destino && in_array($this->codigo, [301,302,303,307,308], true)) {
            $ruta_destino = parse_url($destino, PHP_URL_PATH) ?? '/';
            $q = parse_url($destino, PHP_URL_QUERY);
            return $this->ir($ruta_destino . ($q ? '?' . $q : ''), null, true);
        }
        return $this->cuerpo;
    }

    /** El testigo CSRF que lleva el formulario de la última página cargada. */
    public function testigo(): string
    {
        return preg_match('/name="_t" value="([^"]+)"/', $this->cuerpo, $m) ? $m[1] : '';
    }

    /**
     * Cualquier otro campo oculto de la última página, por su nombre.
     *
     * Hace falta porque las pruebas rearman el POST a mano y el navegador no:
     * él reenvía TODO lo que el formulario lleva dentro. Un campo oculto que la
     * prueba no reenvía es una prueba que mide otra cosa que el producto — y
     * con `f_id`, que es lo que ata el voucher a este formulario, la diferencia
     * es justo el fallo que se quería medir.
     */
    public function oculto(string $nombre): string
    {
        return preg_match('/name="' . preg_quote($nombre, '/') . '" value="([^"]*)"/',
                          $this->cuerpo, $m) ? $m[1] : '';
    }

    /**
     * Un testigo CSRF válido para esta sesión, venga de donde venga.
     * Hace falta para probar los POST que una cuenta NO debería poder hacer:
     * en esas pantallas no se le pinta ningún formulario, así que sin esto la
     * prueba mediría un 419 (falta el testigo) en vez del 403 (no es tuyo),
     * y el agujero de permisos quedaría sin comprobar.
     */
    public function testigoValido(): string
    {
        $antes = $this->cuerpo;
        $this->ir('/reportar-error');
        $t = $this->testigo();
        $this->cuerpo = $antes;
        return $t;
    }

    /** Entra con una cuenta. Devuelve true si quedó dentro. */
    public function entrar(string $email, string $clave): bool
    {
        $this->ir('/entrar');
        $this->ir('/entrar', ['_t' => $this->testigo(), 'email' => $email, 'clave' => $clave]);
        $this->ir('/inicio');
        return $this->codigo === 200 && !str_contains($this->cuerpo, 'name="clave"');
    }

    public function salir(): void
    {
        $this->cookies = [];
    }
}
