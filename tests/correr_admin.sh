#!/bin/bash
# Pruebas de la página privada de datos (/admin/: admin_comun.php, admin/api.php, admin/index.php) contra PHP y una
# MariaDB REALES, todo en contenedores: no instala nada y no toca la base de producción ni config_db.php.
#
#   tests/correr_admin.sh          siembra datos de ejemplo y corre tests/test_admin.php
#   tests/correr_admin.sh ver      siembra y deja la página abierta en http://localhost:8099/admin/ (clave:
#                                  «clave de prueba 2026») hasta Ctrl+C, para mirarla o tomar capturas
#
# PUBLIC_HTML=<carpeta> prueba otra copia de public_html (para las mutaciones).
# DB_IMG=mysql:8.0 (o mariadb:11.8…) prueba contra otro servidor; DB_MODO=<sql_mode> fija el modo SQL del servidor.
set -u
cd "$(dirname "$0")/.."
MODO="${1:-probar}"
RAIZ="$(pwd)"; PH="${PUBLIC_HTML:-$RAIZ/public_html}"
RED=ap-admin-test; DB=ap-dash-db; IMG="ap-dash-php:alpine"; WEB=ap-admin-web
DB_IMG="${DB_IMG:-mariadb:10.11}"; CLI=mariadb; case "$DB_IMG" in mysql*) CLI=mysql;; esac
DB_OPC=(); [ -n "${DB_MODO:-}" ] && DB_OPC=(--sql-mode="$DB_MODO")

limpiar() { docker rm -f "$DB" "$WEB" >/dev/null 2>&1; docker network rm "$RED" >/dev/null 2>&1; }
trap limpiar EXIT; limpiar

docker build -q -t "$IMG" tests/docker >/dev/null || { echo "No se pudo construir la imagen de PHP"; exit 2; }
docker network create "$RED" >/dev/null
docker run -d --name "$DB" --network "$RED" -e MYSQL_ROOT_PASSWORD=root-prueba \
    -e MYSQL_DATABASE=dashtest -e MYSQL_USER=dash -e MYSQL_PASSWORD=dash-prueba "$DB_IMG" "${DB_OPC[@]}" >/dev/null
echo -n "Esperando la base"
for i in $(seq 1 120); do   # «port: 3306»: el servidor definitivo, no el temporal (port: 0) con que la imagen se inicializa
    docker logs "$DB" 2>&1 | grep -q "Version:.*port: 3306" &&
        docker exec "$DB" $CLI -udash -pdash-prueba dashtest -e "SELECT 1" >/dev/null 2>&1 && break
    echo -n "."; sleep 1
done; echo
sql() { docker exec -i "$DB" $CLI -udash -pdash-prueba dashtest; }
echo "Servidor: $(docker exec "$DB" $CLI -udash -pdash-prueba -N -e "SELECT CONCAT(VERSION(), ' · ', @@sql_mode)" 2>/dev/null)"

sql < sql/000_base_dashboard_pos.sql || { echo "Falló la línea base"; exit 2; }
for m in sql/2026-10-09_dashboard_extra_y_pin.sql sql/2026-10-20_panel_premium.sql sql/2026-10-21_tienda.sql; do
    sql < "$m" >/dev/null || { echo "Falló $m"; exit 2; }
done
sql < tests/admin_tablas_viejas.sql || { echo "Fallaron las tablas reconstruidas"; exit 2; }
sql < sql/2026-10-24_comentarios_telefono.sql >/dev/null || { echo "Falló la migración del teléfono (1.ª vez)"; exit 2; }
sql < sql/2026-10-24_comentarios_telefono.sql >/dev/null || { echo "La migración del teléfono NO es idempotente (2.ª vez)"; exit 2; }
sql < sql/2026-10-26_version_pos.sql >/dev/null || { echo "Falló la migración de la versión (1.ª vez)"; exit 2; }
sql < sql/2026-10-26_version_pos.sql >/dev/null || { echo "La migración de la versión NO es idempotente (2.ª vez)"; exit 2; }
salida=$(docker exec -i "$DB" $CLI -udash -pdash-prueba information_schema < sql/2026-10-26_version_pos.sql 2>&1)
echo "$salida" | grep -q "ELIGE TU BASE" && ! echo "$salida" | grep -qi "error" || { echo "La migración de la versión no avisó con information_schema"; exit 2; }
sql < sql/2026-10-21_admin.sql >/dev/null || { echo "Falló la migración de la página (1.ª vez)"; exit 2; }
sql < sql/2026-10-21_admin.sql >/dev/null || { echo "La migración de la página NO es idempotente (2.ª vez)"; exit 2; }
echo "Migración de la página aplicada dos veces sin error"
salida=$(docker exec -i "$DB" $CLI -udash -pdash-prueba information_schema < sql/2026-10-21_admin.sql 2>&1)
if echo "$salida" | grep -qi "error"; then echo "La migración FALLA con information_schema como base activa:"; echo "$salida" | tail -3; exit 2; fi
echo "$salida" | grep -q "ELIGE TU BASE" || { echo "La migración no avisó que la base activa no es la correcta"; exit 2; }
echo "Con information_schema como base activa: avisa y no cambia nada"

PUERTO=""; [ "$MODO" = "ver" ] && PUERTO="-p 127.0.0.1:8099:8080"
docker run -d --name "$WEB" --network "$RED" $PUERTO -v "$PH":/src/public_html:ro -v "$RAIZ/tests":/src/tests:ro "$IMG" sh -c '
    mkdir -p /app && cp -r /src/public_html /app/public_html && cp /src/tests/config_admin.test.php /app/config_db.php &&
    php -d log_errors=1 -d error_log=/tmp/php.log -d display_errors=0 -S 0.0.0.0:8080 -t /app/public_html >/tmp/server.log 2>&1
' >/dev/null
for i in $(seq 1 30); do docker exec "$WEB" test -f /app/config_db.php 2>/dev/null && break; sleep 1; done; sleep 1
docker exec "$WEB" sh -c 'php /src/tests/sembrar_admin.php /app/config_db.php > /tmp/hechos.json' || { echo "No se pudo sembrar"; exit 2; }
echo "Datos de ejemplo sembrados"

if [ "$MODO" = "ver" ]; then
    echo "Abierta en http://localhost:8099/admin/  (clave: clave de prueba 2026). Ctrl+C para cerrar."
    while true; do sleep 3600; done
fi

docker exec "$WEB" php /src/tests/test_admin.php http://127.0.0.1:8080 /app/config_db.php /tmp/hechos.json; r=$?
avisos=$(docker exec "$WEB" sh -c 'grep -iE "warning|notice|fatal|deprecated" /tmp/php.log 2>/dev/null | grep -v "admin/api (" | head -20')
if [ -n "$avisos" ]; then echo "── PHP dejó avisos:"; echo "$avisos"; r=1; fi
exit $r
