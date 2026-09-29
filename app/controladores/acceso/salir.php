<?php
declare(strict_types=1);
// Se exige el testigo: sin él, un simple <img src=".../salir"> metido en
// cualquier página cerraría la sesión de quien la abriera.
//
// Pero un testigo que no cuadra casi siempre es la sesión caducada de alguien
// que SÍ quiere salir, y devolverlo a /inicio lo dejaría dentro otra vez por la
// cookie de 30 días. Así que en ese caso se tira igualmente lo peligroso: la
// cookie de recuerdo y su fila. Lo que no se hace sin testigo es ir más allá.
if (!hash_equals(csrf(), (string) pedir('t', 'get'))) {
    olvidar_recuerdo();
    ir('/entrar?limpio=1');
}
salir();
// El aviso al service worker (borrar lo guardado en el celular) lo dispara
// la pantalla de acceso al cargarse con ?limpio=1.
ir('/entrar?limpio=1');
