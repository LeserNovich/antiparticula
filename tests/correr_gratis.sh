#!/bin/bash
# Pruebas de la página de los anuncios (/gratis): registrar_gratis.php, el aviso de descargas a TikTok en
# registrar_descarga.php y gratis_comun.php, contra una MariaDB REAL y una Events API de TikTok de imitación
# (tests/tiktok_falso.php), todo en contenedores: no instala nada y no toca producción, ni TikTok, ni config_db.php.
#
#   tests/correr_gratis.sh
#   PUBLIC_HTML=/ruta/a/otra/copia tests/correr_gratis.sh   # probar una copia (mutaciones)
set -u
cd "$(dirname "$0")/.."
RAIZ="$(pwd)"; PH="${PUBLIC_HTML:-$RAIZ/public_html}"
RED=ap-gratis-test; DB=ap-dash-db; IMG="ap-dash-php:alpine"

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
sql < tests/admin_tablas_viejas.sql || { echo "Fallaron las tablas reconstruidas"; exit 2; }
sql < sql/2026-10-27_registros_gratis.sql >/dev/null || { echo "Falló la migración de /gratis (1.ª vez)"; exit 2; }
sql < sql/2026-10-27_registros_gratis.sql >/dev/null || { echo "La migración de /gratis NO es idempotente (2.ª vez)"; exit 2; }
echo "Migración de /gratis aplicada dos veces sin error"
salida=$(docker exec -i "$DB" mariadb -udash -pdash-prueba information_schema < sql/2026-10-27_registros_gratis.sql 2>&1)
if echo "$salida" | grep -qi "error"; then echo "La migración FALLA con information_schema como base activa:"; echo "$salida" | tail -3; exit 2; fi
echo "$salida" | grep -q "ELIGE TU BASE" || { echo "La migración no avisó que la base activa no es la correcta"; exit 2; }
echo "Con information_schema como base activa: avisa y no cambia nada"

# default_socket_timeout: registrar_descarga.php pregunta la ubicación a ip-api.com; sin red no debe colgarse
docker run --rm --network "$RED" -v "$PH":/src/public_html:ro -v "$RAIZ/tests":/src/tests:ro "$IMG" sh -c '
    mkdir -p /app && cp -r /src/public_html /app/public_html && cp /src/tests/config_db.test.php /app/config_db.php &&
    (php -d default_socket_timeout=3 -d log_errors=1 -d error_log=/tmp/php_errores.log -S 127.0.0.1:8080 -t /app/public_html >/tmp/php.log 2>&1 &) &&
    (php -S 127.0.0.1:8091 /src/tests/tiktok_falso.php >/tmp/tiktok.log 2>&1 &) && sleep 1 &&
    php /src/tests/test_gratis.php http://127.0.0.1:8080; r=$?
    if [ $r -ne 0 ]; then echo "── registro de PHP (errores)"; tail -20 /tmp/php_errores.log 2>/dev/null; grep -i "error\|warning" /tmp/php.log | tail -10; fi
    exit $r
'
