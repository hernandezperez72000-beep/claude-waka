# Un servidor de fotos por https, solo para correr5.php (las descargas de verdad).
# Uso: python3 fotos_https.py PUERTO cert.pem clave.pem carpeta_de_fotos
import http.server, ssl, sys, os, time

PUERTO, CERT, CLAVE, DIR = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]

class H(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.0'
    def log_message(self, *a): pass
    def responder(self, cod, cuerpo=b'', cabeceras=None):
        self.send_response(cod)
        for k, v in (cabeceras or {'Content-Length': str(len(cuerpo))}).items(): self.send_header(k, v)
        self.end_headers()
        if cuerpo: self.wfile.write(cuerpo)
    def do_GET(self):
        n = self.path.split('?')[0].lstrip('/')
        if n == 'no.jpg':        return self.responder(404, b'no')
        if n == 'ido.jpg':       return self.responder(410, b'no')
        if n == 'redirige.jpg':  return self.responder(302, b'', {'Location': 'https://127.0.0.1/secreto', 'Content-Length': '0'})
        if n == 'ocupada.jpg':   return self.responder(503, b'luego')
        if n == 'prohibida.jpg': return self.responder(403, b'no')
        if n == 'muchas.jpg':    return self.responder(429, b'no')
        if n == 'texto.jpg':     return self.responder(200, b'esto no es una foto')
        if n == 'grande.jpg':    # dice que pesa 9 MB
            self.responder(200, b'', {'Content-Length': str(9 * 1024 * 1024)})
            try: self.wfile.write(b'\xff' * 1024)
            except Exception: pass
            return
        if n == 'grande-sin-decir.jpg':   # no dice cuánto pesa, y manda hasta 64 MB
            self.responder(200, b'', {'Content-Type': 'image/jpeg'})
            escrito = 0
            try:
                for _ in range(64 * 16):
                    self.wfile.write(b'\xff' * 65536); escrito += 65536
            except Exception: pass
            open(os.path.join(DIR, 'escrito.txt'), 'w').write(str(escrito))
            return
        if n == 'cortada.jpg':   # promete una foto entera y corta a la mitad
            b = open(os.path.join(DIR, 'ok.jpg'), 'rb').read()
            self.responder(200, b'', {'Content-Length': str(len(b))})
            self.wfile.write(b[:len(b) // 2]); self.wfile.flush()
            self.connection.shutdown(2)
            return
        ruta = os.path.join(DIR, os.path.basename(n))
        if os.path.isfile(ruta): return self.responder(200, open(ruta, 'rb').read())
        return self.responder(404, b'no')

s = http.server.ThreadingHTTPServer(('127.0.0.1', PUERTO), H)
c = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); c.load_cert_chain(CERT, CLAVE)
s.socket = c.wrap_socket(s.socket, server_side=True)
s.serve_forever()
