# HUB Waka · Estado al 2026-09-30 (5b) — leer PRIMERO al retomar

Se suma a `estado-hub-2026-09-29-5a.md` y a `decisiones-5a.md`.

## Versión 5b (`HUB_VERSION = '5b'`)
- **Pide `actualizar.php`**: tablas `notificaciones`, `notificacion_vistas`, `push_suscripciones`; columna `ubigeo.codigo_inei`; estado `alistado` (orden 57); permiso `avisos.enviar` (Dirección y Administración); migración única `alistado_estado_arranque` (lo alistado y sin entregar pasa a «Alistado»).
- Para arrancar: serie de guía (T…), ubigeo y dirección del almacén y peso por bulto en Configuración › Facturación electrónica. Cada persona activa el push en cada equipo (Mi perfil › Notificaciones).
- Banco: correr 120 · correr2 1410 · correr4 82 · correr5 71 · correr6 99 · correr7 159 · correr8 122 · correr9 145 · correr10 121 · correr11 240 · **correr12 124** · avisos `[]` · correr3 1570 · pantallas limpias a 390/760/1101/1280/1366.

## Lo que pidió el usuario el 2026-09-29 y cómo quedó
1. **«Revisa» / «Ya lo revisé»**: sin casilla. La fila dice «Falta confirmar» con el motivo; GUARDAR confirma toda fila que ya estaba (`revisado = fid > 0` en el controlador). Lo que el HUB no resuelve solo (máquina que no está, producto ya en catálogo) sigue sin confirmar.
2. **«Es repuesto»**: el código se bloquea y se borra al marcar; el controlador también lo borra en filas nuevas con máquina. El repuesto recibe su código al guardar (WK-…) y su nombre no cambia (el cambio de nombre venía de escribir un código existente en la fila del repuesto).
3. **«Cómo lo llama el asesor»**: quitado de la pantalla del lote (se conserva escondido el que había).
4. **Poner a la venta**: vuelve arriba (sin ancla) con la ventana «Pre venta activada»; `notif_preventa_nueva()` a `pedidos.crear` del país, una vez por hora por lote.
5. **Notificaciones** (`nucleo/notificaciones.php`): `notificar()` → fila + push al final de la petición (`push_encolar`/`push_vaciar_cola`, fuera de transacciones). Llegan por el sondeo que cada uno ya tenía (`notifs` en `/pagos/nuevos` y `/pedidos/nuevos-despacho`) o por `/avisos/nuevos` (Almacén, Dirección, Marketing, cada 20 s). Lo emergente sale en ventana al entrar (marco.php). Push: VAPID + aes128gcm hechos con OpenSSL, solo a servicios de push conocidos. Tipos: general, caceria, preventa, pago_ok, pago_nuevo (solo push), alistar, entregado, resumen.
6. **Pagos cada 3 min**: el sondeo de Facturación vuelve a sonar mientras haya sin revisar (hora compartida entre pestañas en localStorage).
7. **Pagos por validar**: Sin revisar → En espera → Comprobantes solicitados → Validados. Voucher flotante (`#voucher-flota`) con N.º de operación y los tres botones.
8. **Reporte de pagos**: cifras → detalle → sin comprobante → por día / por banco → denegados por asesor.
9. **Pago confirmado al asesor**: `notif_pago_confirmado()` dentro de `pago_validar()`; el sondeo del asesor pasó a 15 s.
10. **Alistado**: `pedido_estado_calculado()` devuelve `alistado` con despacho y alistado sin entrega.
11. **Por entregar**: ruta `/pedidos/por-entregar` (mismo controlador, `$modo`), en el menú de Almacén y Administración.
12. **Rótulo**: `?vista=1` es la vista previa; el PDF (`rotulos_a4`) pone dos rótulos por A4 horizontal, «BULTO k DE n», línea de corte.
13. **Guía de remisión** (`nucleo/guias.php`): NUBEFACT tipo 7, `generar_guia`/`consultar_guia`, misma tabla y numeración que las boletas. Códigos INEI en `nucleo/ubigeo_inei.php` (1886 de 1893, MIT); los que falten se escriben y se recuerdan.
14. **Vista previa de boleta/factura**: GET `/pedidos/emitir` es la vista; POST con `previa=1` manda lo corregido (`nubefact_aplicar_ediciones`). Solo texto; los montos no.
15. **Por alistar**: indicador grande arriba (se refresca con el sondeo) y sonido «almacen».
16. **Cacería en Bonos del asesor**: durante el día se ven las ventas registradas (`valor`), el premio sale de las confirmadas (`confirmadas`) y al cerrar solo cuentan esas. Escalón alcanzado sin confirmar = rayado.
17. **Texto de «Por despachar»**: sin hablar de Almacén.
18. **Contador del Inicio**: el Inicio del asesor se recarga al volver (bfcache, app del fondo) y cada 2 min a la vista.
19. **Aviso general**: Configuración › Notificaciones (título 50, texto 150, imagen 1200×600 < 400 KB, a quién, enlace).
20. **Cacería**: también Administración (ya tenía el permiso; atajo en el Inicio de CEO y Admin).

## Pendiente / a confirmar con el usuario
- «Avisos de clientes» (mencionado junto a las notificaciones): no quedó claro a qué se refería; no se tocó.
- El recordatorio de 3 min es dentro de la plataforma; con el celular cerrado llega un push por cada pago nuevo, no cada 3 min (haría falta una tarea programada cada minuto).
- Las maquetas del diseño no están en el repositorio: se revisaron las pantallas contra el sistema de estilos y la auditoría de anchos.
