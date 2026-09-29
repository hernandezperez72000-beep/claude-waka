<?php
declare(strict_types=1);
seccion_activa('garantias');

/**
 * LAS GARANTÍAS (3i): arriba, «¿Sigue en garantía?» —el buscador para
 * validarla desde el HUB—; abajo, las pedidas. El asesor ve las suyas,
 * Administración las de su país (con las que esperan primero) y Dirección
 * todas. Se piden desde la ficha del pedido.
 */
if (!garantias_listo()) {
    pagina('pedidos/sinmigrar', [], ['titulo' => 'Garantías', 'subtitulo' => 'Disponible en cuanto se termine la actualización']);
    return;
}
$estado = (string) pedir('e', 'get');
if (!isset(garantia_estados()[$estado])) $estado = '';
$q = trim((string) pedir('q', 'get'));
$busca = trim((string) pedir('v', 'get'));

pagina('garantias/lista', [
    'filas'    => garantias_lista(['estado' => $estado, 'q' => $q], 100),
    'estado'   => $estado,
    'q'        => $q,
    'busca'    => $busca,
    'hallados' => $busca !== '' ? garantia_buscar_pedidos($busca, 10) : [],
    'por_aprobar' => garantias_por_aprobar_n(),
    'resuelvo' => puede('garantias.aprobar'),
], ['titulo' => 'Garantías', 'sin_titulo' => true]);
