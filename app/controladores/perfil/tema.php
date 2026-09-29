<?php
declare(strict_types=1);
$u = yo();
$tema = pedir('tema');
if (in_array($tema, ['auto','claro','oscuro'], true)) {
    actualizar('usuarios', (int)$u['id'], ['tema' => $tema]);
}
ir(pedir('volver') ?: '/inicio');
