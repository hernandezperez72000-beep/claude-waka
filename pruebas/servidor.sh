#!/usr/bin/env bash
# Levanta un servidor PHP de verdad contra el banco de pruebas.
#   bash pruebas/servidor.sh start   → arranca en 127.0.0.1:8123
#   bash pruebas/servidor.sh stop    → lo baja
set -u
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PID="/tmp/waka-servidor.pid"
LOG="/tmp/waka-servidor.log"
PUERTO="${WAKA_PUERTO:-8123}"

# opcache apagado: con él, el servidor podía servir un par de segundos la
# versión VIEJA de un archivo recién cambiado, y una prueba de mutación daba
# por cazada (o por viva) una mutación que ya no estaba.
case "${1:-start}" in
  start)
    [ -f "$PID" ] && kill "$(cat "$PID")" 2>/dev/null
    php -S 127.0.0.1:"$PUERTO" -t "$RAIZ" \
        -d auto_prepend_file="$RAIZ/pruebas/inyectar.php" \
        -d display_errors=1 -d error_reporting=E_ALL -d opcache.enable=0 \
        "$RAIZ/pruebas/router.php" > "$LOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    echo "servidor en http://127.0.0.1:$PUERTO (log: $LOG)"
    ;;
  stop)
    [ -f "$PID" ] && kill "$(cat "$PID")" 2>/dev/null && rm -f "$PID"
    echo "servidor detenido"
    ;;
esac
