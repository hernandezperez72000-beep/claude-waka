<?php
declare(strict_types=1);
seccion_activa('reportes');

/**
 * EL REPORTE COMPLETO DE LA BITÁCORA.
 *
 * La tarjeta del panel enseña cinco líneas y sirve para «¿qué acaba de pasar?».
 * Esta pantalla es la otra pregunta, la que se hace cuando algo no cuadra:
 * «¿quién tocó esto, y cuándo?». Por eso filtra por persona, por tipo de acción
 * y por rango de fechas, y por eso cada línea dice SOBRE QUÉ y lleva a la ficha.
 *
 * Va por permiso de usuarios y no por rol: la bitácora es el rastro de lo que
 * hace todo el mundo, y se la queda quien gobierna las cuentas — no quien
 * confirma pagos. Es la misma puerta que usa la tarjeta del panel.
 */
exigir('usuarios.ver');

$u     = yo();
$cruza = cruza_paises($u);

$desde  = pedir('desde', 'get') ?: date('Y-m-d', strtotime('-7 days'));
$hasta  = pedir('hasta', 'get') ?: date('Y-m-d');
if (!fecha_valida($desde)) $desde = date('Y-m-d', strtotime('-7 days'));
if (!fecha_valida($hasta)) $hasta = date('Y-m-d');
if ($hasta < $desde) [$desde, $hasta] = [$hasta, $desde];

$quien = pedir_int('quien', 'get') ?: 0;
$que   = pedir('que', 'get');     // el prefijo: pedido, pago, cliente, usuario, acceso…

$where = ['b.creado_en >= ?', 'b.creado_en <= ?'];
$par   = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

/* El país aísla también aquí: la bitácora de México no es asunto de quien lleva
   Perú. Va por el país de QUIEN hizo la acción, que es lo único que la fila
   sabe con certeza. */
if (!$cruza) { $where[] = 'u.pais_id = ?'; $par[] = (int)$u['pais_id']; }
if ($quien)  { $where[] = 'b.usuario_id = ?'; $par[] = $quien; }
if ($que !== '' && preg_match('/^[a-z_]{1,20}$/', $que)) {
    $where[] = 'b.accion LIKE ?';
    $par[]   = like_seguro($que) . '.%';
}

$sql_where = implode(' AND ', $where);
$tope = 400;

$filas = todas(
    "SELECT b.*, u.nombre, u.apellidos, r.nombre AS rol_nombre
       FROM bitacora b
       LEFT JOIN usuarios u ON u.id = b.usuario_id
       LEFT JOIN roles r    ON r.id = u.rol_id
      WHERE $sql_where
      ORDER BY b.id DESC
      LIMIT " . (int)$tope, $par);

$cuantas = (int) valor(
    "SELECT COUNT(*) FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
      WHERE $sql_where", $par);

/* Quién aparece en el rango, para el desplegable: la lista completa de usuarios
   incluiría a gente que no ha tocado nada en estas fechas, y elegirla daría una
   pantalla vacía sin decir por qué. */
$personas = todas(
    "SELECT DISTINCT u.id, u.nombre, u.apellidos
       FROM bitacora b JOIN usuarios u ON u.id = b.usuario_id
      WHERE $sql_where
      ORDER BY u.nombre", $par);

$tipos = ['pedido' => 'Ventas', 'pago' => 'Pagos', 'cliente' => 'Clientes',
          'usuario' => 'Usuarios', 'acceso' => 'Accesos', 'config' => 'Configuración'];

pagina('reportes/bitacora', [
    'filas' => $filas, 'cuantas' => $cuantas, 'tope' => $tope,
    'desde' => $desde, 'hasta' => $hasta, 'quien' => $quien, 'que' => $que,
    'personas' => $personas, 'tipos' => $tipos,
], ['titulo' => 'Quién hizo qué', 'migaja' => 'Reportes',
    'subtitulo' => 'Todo lo que se hizo, por persona, fecha y tipo']);
