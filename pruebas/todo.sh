#!/usr/bin/env bash
# Corre el banco entero. Es lo que hay que ver en verde antes de empaquetar.
set -u
cd "$(dirname "${BASH_SOURCE[0]}")/.."
fallos=0
php pruebas/correr.php  || fallos=1
php pruebas/correr2.php || fallos=1
php pruebas/correr4.php || fallos=1
php pruebas/correr5.php || fallos=1
php pruebas/correr6.php || fallos=1
php pruebas/correr7.php || fallos=1
php pruebas/correr8.php || fallos=1
php pruebas/correr9.php || fallos=1
php pruebas/correr10.php || fallos=1
php pruebas/correr11.php || fallos=1
php pruebas/correr12.php || fallos=1
# Los avisos en vivo, ejecutados de verdad en Node (3d): el sondeo del asesor
# llevaba meses callado por un error que ninguna prueba de PHP puede ver.
salida_av=$(node pruebas/avisos.cjs 2>&1)
if [ "$salida_av" = "[]" ]; then echo "  ✓ los avisos en vivo salen (pagos, reclamos, descuentos, despacho)"
else echo "  ✗ avisos en vivo: $salida_av"; fallos=1; fi
bash pruebas/servidor.sh start >/dev/null
sleep 2
php pruebas/correr3.php || fallos=1

# LA AUDITORÍA DEL NAVEGADOR. Ninguna prueba de PHP puede ver dónde CAE un
# botón después de que el navegador aplique el CSS, y ahí estaban los dos
# fallos que el usuario vio: los botones de la bandeja fuera de la pantalla en
# el celular, y la ficha del pedido recortando «Validar» en un portátil.
# Se corre a los cinco anchos que importan; lista vacía («[]») es aprobado.
# Si no hay Playwright instalado, se avisa y el resto del banco sigue valiendo.
if node -e "require('/home/claude/.npm-global/lib/node_modules/playwright')" 2>/dev/null; then
  for ancho in 390 760 1101 1280 1366; do
    salida=$(WAKA_ANCHO=$ancho node pruebas/movil.cjs 2>/dev/null)
    if [ "$salida" = "[]" ]; then
      echo "  ✓ pantallas a ${ancho} px: nada recortado, nada ilegible, nada fuera de alcance"
    else
      echo "  ✗ pantallas a ${ancho} px: hay hallazgos — corre 'WAKA_ANCHO=$ancho node pruebas/movil.cjs'"
      fallos=1
    fi
  done
else
  echo "  · sin Playwright: la auditoría de pantallas no se corrió (npm i -g playwright)"
fi

bash pruebas/servidor.sh stop >/dev/null
exit $fallos
