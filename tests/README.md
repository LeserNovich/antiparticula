# Pruebas

## Frontend — `test_dashboard.js`

Monta `public_html/dashboard/index.html` con jsdom, simula la API y verifica
el render incremental, los estados vacíos, el banner de conexión, el escapado
XSS y los formatos de fecha/moneda.

```bash
npm install jsdom      # una sola vez
node tests/test_dashboard.js
```

## Panel de todas las tiendas — `test_tiendas.js`

El panel `/dashboard/T-<código>` (`public_html/dashboard/tiendas.js`) con jsdom y el servidor multisucursal simulado: PIN, selector de tienda, cada
pestaña, frescura de las tiendas, compartir y escapado de textos. **Sus ejemplos de respuesta (`fixtures/panel_tiendas_*.json`) los genera el servidor
real**, no se escriben a mano: `cd servidor && mvn test -Dtest=PanelTest -Dpanel.ejemplo=/work/target/ejemplo` (ver `docs/panel-tiendas.md` del repo del POS).

```bash
NODE_PATH=<carpeta con jsdom> node tests/test_tiendas.js     # 171 comprobaciones
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
tests/correr_api.sh              # base migrada: 214 comprobaciones; cada migración se aplica dos veces
tests/correr_api.sh sin-migrar   # solo la línea base: lo viejo funciona y lo nuevo se omite con aviso (38)
PUBLIC_HTML=/ruta/a/otra/copia tests/correr_api.sh   # probar una copia (mutaciones)
```

Cubre `sync_pos.php`, `get_dashboard_data.php` y `dashboard_auth.php` por HTTP: `extra`, `dias`, `meses`,
límites de tamaño, PIN completo (activar, leer con y sin token, escribir con y sin clave, cambiar, quitar,
reconectar, bloqueo a los 8 fallos, tokens alterados/vencidos/de otro panel) y que nunca se filtren
`pin_hash`, `clave_hash` ni `extra_json`. `test_dashboard.js` ahora tiene 412 comprobaciones (pestañas,
caja, inventario, cancelaciones, comparativos, mes, PIN, caché del teléfono, Premium).

**Premium (2026-10-20)** — el panel es solo de Premium: con la base migrada **cada panel de prueba lleva su propia clave firmada**
(`sync()` la agrega; `'licencia' => ''` para probar sin clave). Secciones 9 y 10: sin días gratis aunque la columna los tenga, la clave
firmada (válida, renovada, alterada en cualquiera de sus 125 caracteres, de otra llave, vencida, de recuperación, en un tercer
panel), la puerta de lectura (403 después del PIN) y `prueba_gratis.php`. Necesitan `fixtures/licencia_java.json`, que **genera
el POS** (`test/test/PruebaPremium.java` con la ruta del archivo como argumento): así se comprueba que PHP lee las claves igual que Java.

## Tienda de Premium — `correr_tienda.sh` y `test_tienda.php`

PHP real contra MariaDB real y un **Stripe de imitación** (`stripe_falso.php`), todo en contenedores: planes, abrir el cobro
(lo que se le pide a Stripe), la firma del aviso, compra desde el programa / el panel / la página, renovar sumando, la mensualidad,
OXXO pendiente → pagado → correo, para siempre, varias sucursales, canjear y cambiar de PC, el freno de códigos y que sin la llave
de ventas Stripe reintente. La migración se aplica dos veces y con `information_schema` activa.

```bash
tests/correr_tienda.sh              # 150 comprobaciones (también la moneda de quien compra)
tests/correr_tienda.sh /tmp/ejemplo # además deja /tmp/ejemplo/clave_tienda.json para PruebaClavesDeLaTienda del POS
```

## Página privada de datos (`/admin/`) — `correr_admin.sh` y `test_admin.php`

MariaDB y PHP reales en contenedores. Aplica las migraciones (la de `/admin/` dos veces), crea las tablas que nunca se versionaron
(`admin_tablas_viejas.sql`, en otra intercalación a propósito), siembra datos inventados relativos a hoy (`sembrar_admin.php`) y compara
cada cifra de la API con una consulta escrita aparte: entrada, cookie, freno, cada sección, apartar un equipo, tablas que faltan.

```bash
tests/correr_admin.sh        # 177 comprobaciones (también Registros de /gratis: cifras, seguimiento, BAJA, borrar)
tests/correr_admin.sh ver    # la deja abierta en http://localhost:8099/admin/ (clave: clave de prueba 2026)
DB_IMG=mysql:8.0 tests/correr_admin.sh                   # contra otro servidor (también mariadb:11.8…)
DB_MODO="ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES" tests/correr_admin.sh   # con otro sql_mode
```


## Página de los anuncios (`/gratis`) — `correr_gratis.sh`, `test_gratis.php` y `test_gratis_pagina.js`

`correr_gratis.sh`: MariaDB y PHP reales en contenedores con una Events API de TikTok de imitación (`tiktok_falso.php`, que anota
cada evento en `/tmp/tiktok_eventos.jsonl` y con `/tmp/tiktok_caido` contesta error). Cubre `registrar_gratis.php` (WhatsApp en
E.164, giro, aceptación, campo de robots, misma persona = mismo código sin contar doble, freno por IP, sin la tabla), lo que le llega a
TikTok (evento, event_id, Pixel, teléfono y código cifrados, ttclid, `_ttp`), el aviso de descargas de `registrar_descarga.php`
(con y sin código, cookie `ap_reg`, la primera descarga no se pisa, sin la tabla) y que el formulario, `gratis.js`, el Pixel de las
páginas y el `.htaccess` digan lo mismo que `gratis_comun.php`. La migración se aplica dos veces y con `information_schema` activa.

`test_gratis_pagina.js`: la página en un Chromium de verdad (Playwright), como iPhone y como computadora con Windows, con el servidor
contestado por la prueba: qué ve cada aparato, errores del formulario, lo que se manda al servidor y a la cola del Pixel (mismo event_id),
el link personal, `?c=`, la cookie `ap_reg`, la descarga, las cifras reales, las preguntas y el aviso de privacidad sin scroll de lado.

```bash
tests/correr_gratis.sh                                                     # 132 comprobaciones
NODE_PATH=<carpeta con playwright> node tests/test_gratis_pagina.js [capturas]   # 54; con carpeta deja capturas de cada estado
```
