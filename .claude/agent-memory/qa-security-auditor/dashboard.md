# Auditoria Dashboard PWA — 2026-03-08

## Hallazgos y estado de correcciones

| ID | Severidad | Descripcion | Archivo | Estado |
|---|---|---|---|---|
| CRITICO-01 | CRITICO | Credencial DB hardcodeada | `get_dashboard_data.php`, `sync_pos.php` | CORREGIDO — fallback eliminado, falla si no hay config_db.php |
| CRITICO-02 | CRITICO | XSS en window.onerror via innerHTML sin escape | `index.html` | CORREGIDO |
| ALTO-01 | ALTO | CORS `*` en endpoint con datos financieros | `get_dashboard_data.php` | CORREGIDO |
| ALTO-02 | ALTO | Sin autenticacion real (solo hardware_id) | `get_dashboard_data.php` | PENDIENTE — requiere cambio de arquitectura |
| ALTO-03 | ALTO | JSON sin validacion de estructura interna | `sync_pos.php` | CORREGIDO |
| ALTO-04 | ALTO | Race condition Chart.js async — un solo reintento de 800ms | `dashboard.js` | CORREGIDO — waitForChart con 20 reintentos |
| ALTO-05 | ALTO | `hace_minutos` puede ser negativo | `get_dashboard_data.php`, `dashboard.js` | CORREGIDO |
| ALTO-06 | ALTO | `escHtml()` no escapa comilla simple | `dashboard.js` | CORREGIDO |
| MEDIO-01 | MEDIO | Icono `logo.png` no existe — 404 en favicon y PWA | `index.html`, `manifest.json` | CORREGIDO — iconos 192/512/maskable generados |
| MEDIO-02 | MEDIO | `start_url` relativo sin hardware_id — PWA instalada rota | `manifest.json` | CORREGIDO — manifest generado en runtime |
| MEDIO-03 | MEDIO | `theme_color` inconsistente (verde vs gris) | `manifest.json` | CORREGIDO |
| MEDIO-04 | MEDIO | Chart.js no cacheado en SW — PWA offline sin graficas | `sw.js` | CORREGIDO |
| MEDIO-05 | MEDIO | `formatMXN()` no maneja NaN/null/negativos | `dashboard.js` | CORREGIDO |
| MEDIO-06 | MEDIO | Hora invalida en `renderChartHoras` con ISO o formato 12h | `dashboard.js` | CORREGIDO |
| MEDIO-07 | MEDIO | Tooltip donut sin `maximumFractionDigits` | `dashboard.js` | CORREGIDO |
| MEDIO-08 | MEDIO | Sin `Options -Indexes` en .htaccess | `.htaccess` | CORREGIDO |
| MEDIO-09 | MEDIO | Historico con < 7 dias genera grafico compacto engaoso | `dashboard.js` | CORREGIDO |
| BAJO-01 | BAJO | Timer `setInterval` sin limpieza defensiva | `dashboard.js` | CORREGIDO |
| BAJO-02 | BAJO | Segmento negativo en donut si ganancia > ventas | `dashboard.js` | CORREGIDO |
| BAJO-03 | BAJO | `turno_inicio` muestra formato ISO crudo | `dashboard.js` | CORREGIDO — formatFechaHora() |
| BAJO-04 | BAJO | Donut cortado en 320px | `dashboard.css` | CORREGIDO |
| BAJO-05 | BAJO | Preflight OPTIONS no manejado | ambos PHP | CORREGIDO |
| BAJO-06 | BAJO | `scope` no definido en manifest | `manifest.json` | CORREGIDO |
| BAJO-07 | BAJO | Icono en index.html apunta a logo.png inexistente | `index.html` | CORREGIDO |
| BAJO-08 | BAJO | `sync_pos.php` acepta ganancia > ventas | `sync_pos.php` | CORREGIDO |

## Pendientes que requieren accion manual del equipo

1. **Rotar la contrasena de MySQL en Hostinger** — la actual esta en el historial de git.
2. **Crear `/home/u450756829/config_db.php`** con la nueva contrasena (ver `config_db.example.php`).
3. **Crear iconos PNG 192x192 y 512x512** para la PWA y actualizar manifest.json.
4. **Implementar autenticacion real** en el dashboard (token por negocio) — ALTO-02.
5. **Resolver start_url del manifest** con hardware_id — MEDIO-02.
6. **Revisar otros PHP** (`registrar_descarga.php`, `get_stats.php`, `registrar_uso.php`, `registrar_comentario.php`) por credenciales hardcodeadas.


# Segunda ronda — 2026-09-03

Hallazgos nuevos, todos corregidos y cubiertos por pruebas
(`test_dashboard.js` con jsdom: 55 asserts; `test_sync.php`: 30 asserts).

| ID | Severidad | Descripcion | Archivo | Estado |
|---|---|---|---|---|
| DATOS-01 | ALTO | Grafica "ventas por hora" derivada de `ultimas_ventas`, que solo trae las ultimas N ventas: las horas tempranas del dia salian vacias | `dashboard.js`, `sync_pos.php` | CORREGIDO — tabla `dashboard_pos_ventas` + campo `ventas_por_hora` |
| DATOS-02 | MEDIO | Las horas sin venta desaparecian del eje, haciendo ver actividad continua donde no la hubo | `dashboard.js` | CORREGIDO — se rellena el rango con ceros |
| DATOS-03 | MEDIO | Ventas de anoche reenviadas despues de medianoche se registraban en el dia nuevo | `sync_pos.php` | CORREGIDO — atribucion por `fecha` del POS o heuristica de hora futura (+1h de tolerancia por husos) |
| RUTAS-01 | ALTO | Con `/dashboard/ID/` (barra final, permitida por el .htaccess) todas las rutas relativas daban 404: pagina sin CSS ni JS | `index.html`, `dashboard.js` | CORREGIDO — rutas absolutas |
| UX-01 | MEDIO | El refresco de 60s reconstruia todo el `innerHTML` y recreaba las 4 graficas: parpadeo y perdida del scroll | `dashboard.js` | CORREGIDO — montar() / pintar() |
| UX-02 | MEDIO | Los refrescos fallidos solo hacian `console.error`; se seguian viendo cifras viejas como si fueran actuales | `dashboard.js` | CORREGIDO — banner de conexion |
| UX-03 | MEDIO | El estado vacio reemplazaba el `innerHTML` del wrap y destruia el canvas: la grafica ya no volvia sin recargar | `dashboard.js` | CORREGIDO — toggle con `hidden` |
| PWA-01 | MEDIO | `CACHE_NAME` sin subir: los clientes instalados no reciben el JS nuevo | `sw.js` | CORREGIDO — v3 |
| PWA-02 | MEDIO | Sin fallback de navegacion: la PWA instalada no abria sin red | `sw.js` | CORREGIDO |
| PWA-03 | BAJO | `cache.addAll` abortaba todo el install si el CDN fallaba | `sw.js` | CORREGIDO — `add()` individual |
| FUGA-01 | BAJO | Instancias de Chart.js no destruidas al remontar la estructura | `dashboard.js` | CORREGIDO — destruirCharts() |

## Estado de los pendientes manuales

1. ~~Rotar la contrasena de MySQL~~ — HECHO (config_db.php tiene la nueva).
   La vieja sigue en el historial de git, pero ya no sirve.
2. ~~Crear config_db.php~~ — HECHO, y en `.gitignore`.
3. ~~Iconos PNG 192/512~~ — HECHO.
4. **Autenticacion real (ALTO-02) — SIGUE PENDIENTE.** Es el unico hallazgo
   grave abierto. Requiere coordinar con la app Java del POS.
5. ~~start_url del manifest (MEDIO-02)~~ — HECHO.
6. ~~Revisar otros PHP por credenciales hardcodeadas~~ — HECHO: los cinco
   (`registrar_descarga`, `registrar_uso`, `get_stats`, `registrar_comentario`,
   `get_comentarios`) ya cargan `config_db.php`.

## Accion requerida antes de que DATOS-01 surta efecto

Ejecutar `sql/001_ventas_por_hora.sql` en la BD. Mientras no se aplique, el
endpoint registra el error y el frontend cae al comportamiento anterior
(derivar las horas de `ultimas_ventas`), asi que no se rompe nada.
