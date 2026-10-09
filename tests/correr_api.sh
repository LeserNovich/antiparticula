#!/bin/bash
# Pruebas del lado servidor del panel móvil (sync_pos.php, get_dashboard_data.php,
# dashboard_auth.php) contra una MariaDB REAL, todo en contenedores: no instala
# nada y no toca la base de producción ni config_db.php.
#
#   tests/correr_api.sh [migrado|sin-migrar]
#     migrado      (por omisión) aplica 000 + 2026-10-09, dos veces: comprueba que es idempotente
#     sin-migrar   solo la línea base: lo nuevo debe omitirse sin romper lo de siempre
#
# PUBLIC_HTML=<carpeta> prueba otra copia de public_html (para las mutaciones).
set -u
cd "$(dirname "$0")/.."
MODO="${1:-migrado}"
RAIZ="$(pwd)"; PH="${PUBLIC_HTML:-$RAIZ/public_html}"
RED=ap-dash-test; DB=ap-dash-db; IMG="ap-dash-php:alpine"

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
if [ "$MODO" = "migrado" ]; then
    sql < sql/2026-10-09_dashboard_extra_y_pin.sql >/dev/null || { echo "Falló la migración (1.ª vez)"; exit 2; }
    sql < sql/2026-10-09_dashboard_extra_y_pin.sql >/dev/null || { echo "La migración NO es idempotente (2.ª vez)"; exit 2; }
    echo "Migración aplicada dos veces sin error"
    # El error real de producción: phpMyAdmin con information_schema como base activa (#1044). El script debe
    # avisar y no tocar nada, no fallar.
    salida=$(docker exec -i "$DB" mariadb -udash -pdash-prueba information_schema < sql/2026-10-09_dashboard_extra_y_pin.sql 2>&1)
    if echo "$salida" | grep -qi "error"; then echo "La migración FALLA con information_schema como base activa:"; echo "$salida" | tail -3; exit 2; fi
    echo "$salida" | grep -q "ELIGE TU BASE" || { echo "La migración no avisó que la base activa no es la correcta"; exit 2; }
    echo "Con information_schema como base activa: avisa y no cambia nada"
fi

docker run --rm --network "$RED" -v "$PH":/src/public_html:ro -v "$RAIZ/tests":/src/tests:ro "$IMG" sh -c '
    mkdir -p /app && cp -r /src/public_html /app/public_html && cp /src/tests/config_db.test.php /app/config_db.php &&
    (php -S 127.0.0.1:8080 -t /app/public_html >/tmp/php.log 2>&1 &) && sleep 1 &&
    php /src/tests/test_dashboard_api.php http://127.0.0.1:8080 '"$MODO"'
'
