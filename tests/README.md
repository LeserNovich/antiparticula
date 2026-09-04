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
