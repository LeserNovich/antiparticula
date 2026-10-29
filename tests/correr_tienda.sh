#!/bin/bash
# Pruebas de la tienda de Premium (tienda_comun.php y public_html/tienda/*) contra una MariaDB REAL y un Stripe de
# imitación, todo en contenedores: no instala nada y no toca producción, ni Stripe de verdad, ni config_db.php.
#
#   tests/correr_tienda.sh [carpeta_para_el_ejemplo]
#
# Con una carpeta, deja ahí clave_tienda.json: claves que firmó el PHP, para que el POS compruebe que las acepta
# (test/test/PruebaClavesDeLaTienda.java del repo del POS).
set -u
cd "$(dirname "$0")/.."
RAIZ="$(pwd)"; PH="${PUBLIC_HTML:-$RAIZ/public_html}"; EJEMPLO="${1:-}"
RED=ap-tienda-test; DB=ap-dash-db; IMG="ap-dash-php:alpine"

limpiar() { docker rm -f "$DB" >/dev/null 2>&1; docker network rm "$RED" >/dev/null 2>&1; }
trap limpiar EXIT; limpiar

docker build -q -t "$IMG" tests/docker >/dev/null || { echo "No se pudo construir la imagen de PHP"; exit 2; }
docker network create "$RED" >/dev/null
docker run -d --name "$DB" --network "$RED" -e MARIADB_ROOT_PASSWORD=root-prueba \
    -e MARIADB_DATABASE=dashtest -e MARIADB_USER=dash -e MARIADB_PASSWORD=dash-prueba mariadb:10.11 >/dev/null
echo -n "Esperando la base"
for i in $(seq 1 60); do
    docker exec "$DB" mariadb -udash -pdash-prueba dashtest -e "SELECT 1" >/dev/null 2>&1 && break
    echo -n "."; sleep 1
done; echo
sql() { docker exec -i "$DB" mariadb -udash -pdash-prueba dashtest; }
sql < sql/000_base_dashboard_pos.sql || { echo "Falló la línea base"; exit 2; }
sql < sql/2026-10-21_tienda.sql >/dev/null || { echo "Falló la migración de la tienda (1.ª vez)"; exit 2; }
sql < sql/2026-10-21_tienda.sql >/dev/null || { echo "La migración de la tienda NO es idempotente (2.ª vez)"; exit 2; }
echo "Migración de la tienda aplicada dos veces sin error"
sql < sql/2026-10-29_celular.sql >/dev/null || { echo "Falló la migración del celular (1.ª vez)"; exit 2; }
sql < sql/2026-10-29_celular.sql >/dev/null || { echo "La migración del celular NO es idempotente (2.ª vez)"; exit 2; }
salida=$(docker exec -i "$DB" mariadb -udash -pdash-prueba information_schema < sql/2026-10-29_celular.sql 2>&1)
echo "$salida" | grep -qi "error" && { echo "La migración del celular FALLA con information_schema:"; echo "$salida" | tail -3; exit 2; }
echo "$salida" | grep -q "ELIGE TU BASE" || { echo "La migración del celular no avisó que la base activa no es la correcta"; exit 2; }
echo "Migración del celular: dos veces sin error, y avisa con information_schema"
salida=$(docker exec -i "$DB" mariadb -udash -pdash-prueba information_schema < sql/2026-10-21_tienda.sql 2>&1)
if echo "$salida" | grep -qi "error"; then echo "La migración FALLA con information_schema como base activa:"; echo "$salida" | tail -3; exit 2; fi
echo "$salida" | grep -q "ELIGE TU BASE" || { echo "La migración no avisó que la base activa no es la correcta"; exit 2; }
echo "Con information_schema como base activa: avisa y no cambia nada"

MONTAR_EJEMPLO=""; [ -n "$EJEMPLO" ] && { mkdir -p "$EJEMPLO"; MONTAR_EJEMPLO="-v $(cd "$EJEMPLO" && pwd):/ejemplo"; }
docker run --rm --network "$RED" -v "$PH":/src/public_html:ro -v "$RAIZ/tests":/src/tests:ro $MONTAR_EJEMPLO "$IMG" sh -c '
    mkdir -p /app && cp -r /src/public_html /app/public_html && cp /src/tests/config_db.test.php /app/config_db.php &&
    (php -S 127.0.0.1:8080 -t /app/public_html >/tmp/php.log 2>&1 &) &&
    (php -S 127.0.0.1:8090 /src/tests/stripe_falso.php >/tmp/stripe.log 2>&1 &) && sleep 1 &&
    php /src/tests/test_tienda.php http://127.0.0.1:8080; r=$?
    if [ $r -ne 0 ]; then echo "── registro de PHP (errores)"; grep -i "tienda\|error\|warning" /tmp/php.log | tail -20; fi
    exit $r
'
