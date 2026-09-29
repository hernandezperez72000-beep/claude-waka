<?php
declare(strict_types=1);
/**
 * La primera pantalla. Es la misma para todos, y cambia según si el HUB ya
 * sabe quién eres:
 *   · sin sesión   → frase del día, anónima, y el botón COMENZAR lleva al login
 *   · con sesión   → saludo por tu nombre, la frase de tu hora y tu rol, y
 *                    COMENZAR entra directo. No es un paso extra: es la misma
 *                    pantalla sabiendo más.
 */
$u = yo();

if ($u && (int)$u['debe_cambiar_password'] === 1) ir('/mi-perfil/contrasena');

$franja = franja_del_dia();
$frase  = frase_del_dia($u['rol'] ?? null, $franja);

$datos = [
    'usuario'  => $u,
    'saludo'   => $u ? saludo_para($u) : 'Bienvenido',
    'frase'    => $frase['texto'] ?? 'Hoy es un buen día para cerrar una venta.',
    'linea'    => $u ? linea_de_datos($u) : '',
    'fecha'    => ucfirst(strftime_es()),
    'destino'  => $u ? '/inicio' : '/entrar',
    'boton'    => $u ? 'COMENZAR' : 'COMENZAR',
    'oscuro'   => es_de_noche(),
];

function strftime_es(): string
{
    $dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto',
              'setiembre','octubre','noviembre','diciembre'];
    $t = time();
    return $dias[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' de ' . $meses[(int)date('n', $t) - 1]
         . ' · ' . hora_am_pm($t);
}

pagina_sola('acceso/bienvenida', $datos);
