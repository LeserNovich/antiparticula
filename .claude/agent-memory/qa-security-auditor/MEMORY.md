# QA Security Auditor — Memoria del Proyecto Antiparticula

## Credenciales comprometidas (CRITICO — accion pendiente del equipo)

- **Usuario MySQL:** `u450756829_Leser`
- **BD:** `u450756829_PaginaWeb`
- **Estado:** Contrasena hardcodeada en `get_dashboard_data.php`, `sync_pos.php`,
  `registrar_descarga.php` (y posiblemente otros PHP). Expuesta en historial de git.
- **Accion requerida:** Rotar contrasena en Hostinger + crear `/home/u450756829/config_db.php`
  fuera del webroot. Ver `config_db.example.php` en la raiz del repo.
- **Fix parcial aplicado:** Los PHP ahora intentan cargar `config_db.php` con fallback temporal.

## Archivos PHP con credenciales hardcodeadas (estado por archivo)

| Archivo | Estado |
|---|---|
| `get_dashboard_data.php` | Fix aplicado — usa config_db.php con fallback |
| `sync_pos.php` | Fix aplicado — usa config_db.php con fallback |
| `registrar_descarga.php` | PENDIENTE — aun tiene credencial hardcodeada |
| `registrar_uso.php` | PENDIENTE — revisar si tiene credencial |
| `get_stats.php` | PENDIENTE — revisar si tiene credencial |
| `registrar_comentario.php` | PENDIENTE — revisar si tiene credencial |

## Patrones recurrentes de seguridad

- XSS via `window.onerror` con innerHTML sin escape — corregido en `index.html`
- `escHtml()` en JS faltaba escape de comilla simple — corregido en `dashboard.js`
- CORS `*` en endpoints con datos financieros — corregido en `get_dashboard_data.php`
- Validacion de estructura interna de JSON antes de guardar en DB era insuficiente — corregido en `sync_pos.php`
- `hardware_id` no tenia validacion de formato — corregido en `sync_pos.php`

## Superficie de ataque conocida

- `GET /get_dashboard_data.php?id=HARDWARE_ID` — expone datos financieros de negocios clientes.
  Solo "protegido" por conocer el hardware_id (sin token de autenticacion real). ALTO-02 pendiente.
- `POST /sync_pos.php` — acepta datos del POS sin autenticacion por token. Cualquiera puede
  enviar datos falsos a cualquier hardware_id conocido.
- `/downloads/BD/datos.sqbpro` y `datosNuevo.sqbpro` — archivos de BD potencialmente accesibles.

## Dependencias de terceros en uso

- Chart.js 4.4.0 (CDN jsdelivr) — sin CVEs conocidos a marzo 2026
- Swiper.js 5.4.5 — version antigua, revisar CVEs en proxima auditoria
- FormSubmit.co — maneja datos de contacto, revisar politica de privacidad
- ip-api.com — geolocaliza IPs de descargas

## PWA Dashboard — problemas conocidos

- `start_url: "./"` en manifest no incluye `hardware_id` — PWA instalada siempre muestra error URL invalida.
  Pendiente solucion de redirect en SW o manifest dinamico.
- Iconos 192x192 y 512x512 apuntan a `../img/A.png` (fix temporal). Crear PNGs reales pendiente.

## Tabla de base de datos: dashboard_pos

- `hardware_id` VARCHAR(50) PRIMARY KEY
- `ultima_sync` DATETIME — fuente de `hace_minutos` (puede ser negativa si relojes desincronizados — corregido con `max(0,...)`)
- `ultimas_ventas` TEXT — JSON `[{hora, total_centavos, cajero}, ...]`
- `historico_semana` TEXT — JSON `[{fecha, total_centavos}, ...]` 7 dias

## Correcciones aplicadas en sesion 2026-03-08 (Dashboard PWA)

Ver `dashboard.md` para detalle completo.
