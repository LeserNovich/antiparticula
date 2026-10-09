# Pruebas

## Frontend — `test_dashboard.js`

Monta `public_html/dashboard/index.html` con jsdom, simula la API y verifica
el render incremental, los estados vacíos, el banner de conexión, el escapado
XSS y los formatos de fecha/moneda.

```bash
npm install jsdom      # una sola vez
node tests/test_dashboard.js
```

## Backend — `test_sync.php`

Ejecuta `public_html/sync_pos.php` con `$_POST` simulado y verifica el parseo
de `ultimas_ventas`: extracción de hora/minuto, descarte de horas inválidas,
atribución de fecha al cruzar medianoche, claves de deduplicación y saneado
de entrada.

No necesita base de datos: la conexión PDO falla al final, pero el parseo
—que es lo que se prueba— ya ocurrió.

```bash
php tests/test_sync.php
```

Ambos scripts salen con código 0 si todo pasa.

## Backend del panel móvil v2 — `correr_api.sh` y `test_dashboard_api.php`

PHP real contra MariaDB real, todo en contenedores (no instala nada, no toca producción):

```bash
tests/correr_api.sh              # base migrada: 127 comprobaciones; la migración se aplica dos veces
tests/correr_api.sh sin-migrar   # solo la línea base: lo viejo funciona y lo nuevo se omite con aviso (29)
PUBLIC_HTML=/ruta/a/otra/copia tests/correr_api.sh   # probar una copia (mutaciones)
```

Cubre `sync_pos.php`, `get_dashboard_data.php` y `dashboard_auth.php` por HTTP: `extra`, `dias`, `meses`,
límites de tamaño, PIN completo (activar, leer con y sin token, escribir con y sin clave, cambiar, quitar,
reconectar, bloqueo a los 8 fallos, tokens alterados/vencidos/de otro panel) y que nunca se filtren
`pin_hash`, `clave_hash` ni `extra_json`. `test_dashboard.js` ahora tiene 267 comprobaciones (pestañas,
caja, inventario, cancelaciones, comparativos, mes, PIN, caché del teléfono).
