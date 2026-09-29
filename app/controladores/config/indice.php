<?php
declare(strict_types=1);
seccion_activa('config');
$c = contadores();

// Las mismas filas en computadora y en celular.
$secciones = [
    ['i'=>'US','n'=>'Usuarios y roles','d'=>'Quién entra y qué puede hacer','ruta'=>'/usuarios','listo'=>true,'chip'=>0],
    ['i'=>'EQ','n'=>'Equipos y líderes','d'=>'Tres equipos · la estrella marca al líder','ruta'=>'/configuracion/equipos','listo'=>true,'chip'=>0],
    ['i'=>'ME','n'=>'Metas por asesor','d'=>'S/ 30,000 general, con excepción individual','ruta'=>'/configuracion/metas','listo'=>true,'chip'=>0],
    ['i'=>'BO','n'=>'Bonos','d'=>'Montos, períodos, condiciones y tramos','ruta'=>'/configuracion/bonos','listo'=>true,'chip'=>0],
    /* 5b: el aviso general (push al celular y ventana al entrar). */
    ['i'=>'NO','n'=>'Notificaciones','d'=>'Un aviso para todos: texto e imagen, al celular y al entrar','ruta'=>'/configuracion/notificaciones','listo'=>true,'chip'=>0],
    ['i'=>'MP','n'=>'Métodos de pago','d'=>'Qué pide cada uno: voucher, foto del DNI, recargo','ruta'=>'/configuracion/metodos-pago','listo'=>true,'chip'=>0],
    ['i'=>'ED','n'=>'Equipo de despacho','d'=>'Quién alista los pedidos en el almacén','ruta'=>'/configuracion/equipo-despacho','listo'=>true,'chip'=>0],
    ['i'=>'AC','n'=>'Agencias de carga','d'=>'Quién trae los contenedores de pre venta','ruta'=>'/configuracion/agencias-carga','listo'=>true,'chip'=>0],
    ['i'=>'GA','n'=>'Garantías','d'=>'Lo que se promete en cada venta y cuántos meses dura','ruta'=>'/configuracion/garantias','listo'=>true,'chip'=>0],
    ['i'=>'DE','n'=>'Descuentos','d'=>'Hasta cuánto descuenta el asesor sin pedir permiso','ruta'=>'/configuracion/descuentos','listo'=>true,'chip'=>0],
    ['i'=>'CB','n'=>'Cashback Waka','d'=>'Porcentaje, caducidad y uso con descuento','ruta'=>'/configuracion/cashback','listo'=>false,'chip'=>0],
    ['i'=>'SA','n'=>'Saludo y frases','d'=>'Las frases por hora y por rol','ruta'=>'/configuracion/frases','listo'=>false,'chip'=>0],
        ['i'=>'CT','n'=>'Contactos','d'=>'El WhatsApp de facturación, para el botón del pedido trabado','ruta'=>'/configuracion/contactos','listo'=>true,'chip'=>0],
    ['i'=>'FE','n'=>'Facturación electrónica','d'=>'NUBEFACT: ruta, token y series','ruta'=>'/configuracion/facturacion','listo'=>true,'chip'=>0],
    ['i'=>'TI','n'=>'Tienda','d'=>'compraenwaka: dirección y claves','ruta'=>'/configuracion/tienda','listo'=>true,'chip'=>0],
    ['i'=>'LI','n'=>'Listas editables','d'=>'Pagos, agencias, canales y oficinas','ruta'=>'/configuracion/listas','listo'=>false,'chip'=>0],
    ['i'=>'WA','n'=>'Plantillas de WhatsApp','d'=>'Los mensajes que el asesor copia','ruta'=>'/configuracion/whatsapp','listo'=>false,'chip'=>0],
    ['i'=>'ER','n'=>'Reportes de errores','d'=>'Lo que reportan los asesores','ruta'=>'/configuracion/errores','listo'=>false,'chip'=>(int)($c['errores'] ?? 0)],
    ['i'=>'CO','n'=>'Solicitudes de contraseña','d'=>'Esperan tu aprobación','ruta'=>'/configuracion/claves','listo'=>false,'chip'=>(int)($c['claves'] ?? 0)],
];

pagina('config/indice', ['secciones' => $secciones],
       ['titulo' => 'Configuración',
        'subtitulo' => 'Ajustes, listas y solicitudes de la empresa']);
