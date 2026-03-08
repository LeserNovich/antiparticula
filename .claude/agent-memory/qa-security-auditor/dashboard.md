# Auditoria Dashboard PWA — 2026-03-08

## Hallazgos y estado de correcciones

| ID | Severidad | Descripcion | Archivo | Estado |
|---|---|---|---|---|
| CRITICO-01 | CRITICO | Credencial DB hardcodeada | `get_dashboard_data.php`, `sync_pos.php` | Fix parcial — fallback temporal |
| CRITICO-02 | CRITICO | XSS en window.onerror via innerHTML sin escape | `index.html` | CORREGIDO |
| ALTO-01 | ALTO | CORS `*` en endpoint con datos financieros | `get_dashboard_data.php` | CORREGIDO |
| ALTO-02 | ALTO | Sin autenticacion real (solo hardware_id) | `get_dashboard_data.php` | PENDIENTE — requiere cambio de arquitectura |
| ALTO-03 | ALTO | JSON sin validacion de estructura interna | `sync_pos.php` | CORREGIDO |
| ALTO-04 | ALTO | Race condition Chart.js async — un solo reintento de 800ms | `dashboard.js` | CORREGIDO — waitForChart con 20 reintentos |
| ALTO-05 | ALTO | `hace_minutos` puede ser negativo | `get_dashboard_data.php`, `dashboard.js` | CORREGIDO |
| ALTO-06 | ALTO | `escHtml()` no escapa comilla simple | `dashboard.js` | CORREGIDO |
| MEDIO-01 | MEDIO | Icono `logo.png` no existe — 404 en favicon y PWA | `index.html`, `manifest.json` | FIX TEMPORAL — apunta a A.png |
| MEDIO-02 | MEDIO | `start_url` relativo sin hardware_id — PWA instalada rota | `manifest.json` | PENDIENTE |
| MEDIO-03 | MEDIO | `theme_color` inconsistente (verde vs gris) | `manifest.json` | CORREGIDO |
| MEDIO-04 | MEDIO | Chart.js no cacheado en SW — PWA offline sin graficas | `sw.js` | CORREGIDO |
| MEDIO-05 | MEDIO | `formatMXN()` no maneja NaN/null/negativos | `dashboard.js` | CORREGIDO |
| MEDIO-06 | MEDIO | Hora invalida en `renderChartHoras` con ISO o formato 12h | `dashboard.js` | CORREGIDO |
| MEDIO-07 | MEDIO | Tooltip donut sin `maximumFractionDigits` | `dashboard.js` | CORREGIDO |
| MEDIO-08 | MEDIO | Sin `Options -Indexes` en .htaccess | `.htaccess` | CORREGIDO |
| MEDIO-09 | MEDIO | Historico con < 7 dias genera grafico compacto engaoso | `dashboard.js` | CORREGIDO |
| BAJO-01 | BAJO | Timer `setInterval` sin limpieza defensiva | `dashboard.js` | CORREGIDO |
| BAJO-02 | BAJO | Segmento negativo en donut si ganancia > ventas | `dashboard.js` | CORREGIDO |
| BAJO-03 | BAJO | `turno_inicio` muestra formato ISO crudo | `dashboard.js` | PENDIENTE (UX menor) |
| BAJO-04 | BAJO | Donut cortado en 320px | `dashboard.css` | CORREGIDO |
| BAJO-05 | BAJO | Preflight OPTIONS no manejado | ambos PHP | CORREGIDO |
| BAJO-06 | BAJO | `scope` no definido en manifest | `manifest.json` | CORREGIDO |
| BAJO-07 | BAJO | Icono en index.html apunta a logo.png inexistente | `index.html` | FIX TEMPORAL |
| BAJO-08 | BAJO | `sync_pos.php` acepta ganancia > ventas | `sync_pos.php` | CORREGIDO |

## Pendientes que requieren accion manual del equipo

1. **Rotar la contrasena de MySQL en Hostinger** — la actual esta en el historial de git.
2. **Crear `/home/u450756829/config_db.php`** con la nueva contrasena (ver `config_db.example.php`).
3. **Crear iconos PNG 192x192 y 512x512** para la PWA y actualizar manifest.json.
4. **Implementar autenticacion real** en el dashboard (token por negocio) — ALTO-02.
5. **Resolver start_url del manifest** con hardware_id — MEDIO-02.
6. **Revisar otros PHP** (`registrar_descarga.php`, `get_stats.php`, `registrar_uso.php`, `registrar_comentario.php`) por credenciales hardcodeadas.
