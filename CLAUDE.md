# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Antipartícula is a business website for a software development consultancy based in Mexico. The site showcases IT services, custom software development, and includes a product page for their point-of-sale (POS) software. The project is a traditional multi-page website built with HTML, CSS, and vanilla JavaScript, served via Apache with PHP backend functionality.

## Architecture

### File Structure

```
antiparticula.com/
├── public_html/                 # Web root directory
│   ├── index.html              # Inicio: carrusel sobre el fondo desenfocado + hoja blanca (ver "Página de inicio")
│   ├── paginas/                # All internal pages
│   │   ├── puntodeventa.html   # POS product landing page
│   │   ├── queHacemos.html     # Services page
│   │   ├── quienes-somos.html  # About page
│   │   ├── contacto.html       # Contact page
│   │   ├── descargar.html      # Download page
│   │   └── 404.html            # Custom error page
│   ├── css/                    # Stylesheets
│   │   ├── style.css           # Main global styles
│   │   ├── puntodeventa.css    # Página del Punto de Venta (prefijo pv-)
│   │   ├── paginas.css         # Nosotros y Servicios: fondo desenfocado + hoja blanca (prefijo pg-); tokens y fondo que usan también Inicio y el POS
│   │   ├── inicio.css          # Página de inicio debajo del carrusel (prefijo in-)
│   │   ├── nav.css             # Menú principal (site-nav) y ajustes del footer, en todas las páginas
│   │   └── mensaje-exito.css   # Success message modal styles
│   ├── js/                     # JavaScript files
│   │   ├── nav.js              # Menú principal: panel, menú móvil, fondo al hacer scroll
│   │   ├── inicio.js           # Inicio: aparición suave de las secciones al hacer scroll
│   │   ├── script.js           # Swiper carousel, WhatsApp integration
│   │   └── mensaje-exito.js    # Download tracking & success modals
│   ├── img/                    # Images and graphics
│   ├── downloads/              # Downloadable files
│   │   └── BD/                 # Database files (.sqbpro)
│   ├── products.json           # Product catalog data
│   └── .htaccess               # Apache URL rewriting rules
├── parciales/                  # Menú y footer compartidos (fuente única, no se publica)
│   └── actualizar.php          # php parciales/actualizar.php → los copia en las páginas
└── registrar_descarga.php      # Download tracking endpoint
```

### Key Technical Components

**URL Rewriting (.htaccess)**
- Custom routes defined in `public_html/.htaccess`
- `/puntodeventa` routes to `/paginas/puntodeventa.html`
- Generic product slug routing: `/{slug}` → `/paginas/detail.html?slug={slug}`
- Custom 404 error page at `/paginas/404.html`

**Download Tracking System**
- Frontend: `public_html/js/mensaje-exito.js` contains `descargarPrueba()` and `descargarFuncional()`
- Backend: `registrar_descarga.php` logs downloads to MySQL database
- Database table: `registros_descargas` (fields: ip_address, pais, ciudad, timestamp)
- Uses ip-api.com for geolocation from IP addresses
- Downloads trigger POST to `/registrar_descarga.php` via fetch API

**Database Configuration** (registrar_descarga.php:12-15)
- MySQL database: `u450756829_PaginaWeb`
- Username: `u450756829_Leser`
- Host: 127.0.0.1
- Password is stored in plaintext (security note: should use environment variables)
- Timezone: America/Mexico_City (UTC-06:00)

**Third-Party Integrations**
- Swiper.js 5.4.5 for carousel functionality
- Font Awesome for icons
- FormSubmit.co for contact form handling (emails to viresbhx@gmail.com)
- Google Fonts (Montserrat)

**Form Handling**
- Contact forms submit to FormSubmit.co
- Success redirects to `?enviado=true` query parameter
- `mensaje-exito.js` detects query param and shows success modal

**WhatsApp Integration** (public_html/js/script.js:23-35)
- Function: `sendWhatsAppMessage()`
- Phone number: +52 55 8432 3945
- Opens WhatsApp Web with pre-filled message

## Development Workflow

### Local Development
This is a static website with PHP backend. To develop locally:

1. Use a local web server with PHP support (Apache/nginx + PHP, or PHP's built-in server)
2. For PHP built-in server: `php -S localhost:8000 -t public_html`
3. Note: .htaccess rules won't work with PHP's built-in server (Apache-specific)

### Testing Changes
- Test all pages in `public_html/paginas/` after global CSS/JS changes
- Verify .htaccess routes work correctly on Apache
- Test form submissions redirect properly with `?enviado=true`
- Check download tracking logs to database correctly

### Deployment
The site is deployed to a shared hosting environment:
- Domain: antiparticula.com
- Database host: 127.0.0.1 (localhost on shared hosting)
- Ensure .htaccess is uploaded and mod_rewrite is enabled

## Important Implementation Details

### Product Data Structure
`products.json` contains product information with this schema:
```json
{
  "id": number,
  "name": string,
  "slug": string,
  "price": number,      // Annual price in MXN
  "mensual": number,    // Monthly price in MXN
  "image": string,      // Relative path
  "Description": string // Includes embedded YouTube link
}
```

### SEO & Structured Data
The POS product page (`puntodeventa.html`) includes extensive Schema.org structured data:
- SoftwareApplication schema
- Organization schema
- VideoObject schema (YouTube tutorial)
- FAQPage schema

### Security Considerations
- `registrar_descarga.php` has POST-only validation (lines 5-9)
- Uses PDO with prepared statements to prevent SQL injection
- Database credentials load from `config_db.php`, fuera del webroot y en `.gitignore`
  (la contraseña anterior sigue en el historial de git; ya fue rotada)
- No CSRF protection on download tracking endpoint

### Dashboard PWA (`public_html/dashboard/`)

Panel de ventas del POS, servido en `/dashboard/{hardware_id}`. La app Java del POS empuja una **foto**
a `sync_pos.php`; el navegador la lee de `get_dashboard_data.php`. Pestañas: **Hoy · Caja · Inventario ·
Historial** (Semana | Mes | Año). Versión actual del panel: **v3** (`sw.js` → `antiparticula-dashboard-v5`).
El lado del POS (qué manda, cada cuánto, la bitácora de cancelaciones) está en el repo del POS:
`docs/dashboard-movil.md`.

#### Contrato (todo lo de la 3.3.0 es opcional: un POS anterior no manda nada de esto)

**POST `sync_pos.php`** (form-urlencoded) — lo de siempre más `extra`, `dias`, `meses`, `clave`, `pin`,
`pin_actual`, `pin_clear`:
- `extra` (JSON, ≤ 64 KB, centavos): `pagos_hoy {efectivo,tarjeta,transferencia,comision}`,
  `caja {esperado,fondo,efectivo_ventas,ingresos,retiros,tarjeta,transferencia}` (**`null` = sin turno
  abierto**, no ceros), `cancelaciones_hoy {…, por_usuario[]}`, `inventario {agotados,bajo_minimo,valor_costo,
  urgentes[≤10]}` (existencias en milésimas). `efectivo` incluye los abonos de apartados; `comision` incluye su IVA.
- `dias` (≤ 120) y `meses` (≤ 24): el POS los **recalcula** y se guardan con *upsert*: una cancelación de un
  día pasado se corrige sola y el año sale completo. Se descartan fechas imposibles, futuras o de hace > ~14 meses.
- Respuestas: 200 `{status, protegido, avisos[]}`, 403 `clave_invalida` / `pin_incorrecto`, 429 `bloqueado`, 400 `pin_invalido`.
  `avisos` dice qué parte nueva se omitió (`extra_no_disponible`, `dias_descartado`…): el sync **no** falla por ellas.

**GET `get_dashboard_data.php?id=`** (+ cabecera `X-Dash-Token`; `Authorization: Bearer` de respaldo): agrega
`extra`, `historico_dias` (≤ 100 días, orden ascendente; las claves de desglose faltan en días anteriores a la 3.3.0:
**NULL ≠ 0**), `protegido`. Con PIN y sin token válido: 401 `{status:'protegido'}` y **ni un dato**. Jamás sale
`pin_hash`/`clave_hash`/`extra_json` (hay prueba). `historico_anual` sale de `dashboard_pos_meses` si hay filas del año.

**POST `dashboard_auth.php`** (`id`, `pin`) → 200 `{token, expira}` | 401 `pin_incorrecto` + `restantes` | 429 `bloqueado` + `reintentar_en`.

#### Etapa 2 (POS 3.4.0, panel v4, `sw.js` → `antiparticula-dashboard-v6`; sin migración SQL)

Más claves opcionales dentro de `extra`: `sesion {cerrada:true, desde}` (salió del POS dejando el turno abierto; `null` = no),
`top_productos {hoy,semana,mes}` (≤ 5 por importe), `por_cajero` (≤ 10, hoy), `apartados {activos,por_cobrar,vencidos}`,
`presupuestos {vigentes,monto,por_vencer,vencidos}`, `gastos {hoy,mes}`, `meta_diaria` (centavos, 0 = sin meta).
- **`dash_guardar_extra` FUSIONA** por clave de primer nivel (`array_replace`, lectura y escritura en una transacción): una clave
  ausente conserva lo guardado y un `null` explícito lo reemplaza. Por eso el POS manda **siempre** todas las claves.
- Saneado en `dash_sanear_extra` (topes, `strip_tags`, negativos a 0, lo desconocido se descarta; `sesion` solo con `true` real).
- El panel oculta cada tarjeta si su dato no llegó (un POS 3.3.0 se ve igual que antes): lo más vendido (selector Hoy | 7 días |
  Mes), ventas por cajero (solo con 2 o más), por cobrar / presupuestos / gastos en Caja, utilidad del mes (ganancia − gastos)
  en Historial → Mes, barra de meta en la cabecera, chip «Sesión cerrada» + aviso en Caja y «Compartir resumen del día»
  (`navigator.share`, si no `wa.me/?text=`).
- Cerrar la ventana del programa NO es «sesión cerrada» (el latido se detiene y el panel dice «sin datos del POS»).
- Pruebas: `test_dashboard.js` 361, `correr_api.sh migrado` 153. Orden de despliegue: PHP → `dashboard/*` → POS.

#### Panel de TODAS las tiendas (`/dashboard/T-<código>`, `tiendas.js`; POS 3.4.0 + servidor 1.5.0)

Otro panel, para el dueño con varias tiendas: **no usa PHP ni MySQL**; el navegador lee directo de `https://api.antiparticula.com/api/v1/panel/*`
(servidor multisucursal; CORS ya resuelto allá) con una sesión que da el servidor al escribir el código del enlace y un PIN (6–8 dígitos, 8 fallos →
15 min). Lo crea la PC de administrador desde el POS. Pestañas **Hoy · Tiendas · Inventario · Historial (Semana | Mes)** y selector Todas · cada tienda.
- `dashboard.js` sale al ver un id `T-…` (el id de una sola tienda es un Hardware ID de 16 hex); `index.html` carga `tiendas.js` después, y `sw.js`
  (`antiparticula-dashboard-v7`) lo guarda. `tiendas.js` reutiliza las funciones globales de `dashboard.js` (`pintarPagos`, `pintarTop`, `pintarHoras`,
  `pintarSemana`, `pintarMes`, `htmlColumnas`…): **si cambias la forma de lo que esas reciben (`d.pagos`, `d.top`, `d.horas`, `d.semana`, `d.mes`,
  `d.utilidad`), cambia también `derivarT`**.
- En `localhost`/`127.0.0.1` la API es `http://<host>:18080/api/v1` (probar contra un servidor local); en producción, `api.antiparticula.com`.
- Es lo que cada tienda **ya sincronizó** (no en vivo): cada tienda dice cuándo reportó y una que lleva más de 30 min sin hablar sale en ámbar.
- Pruebas: `NODE_PATH=<jsdom> node tests/test_tiendas.js` (171). Sus ejemplos (`tests/fixtures/panel_tiendas_*.json`) los genera el servidor real
  (`PanelTest -Dpanel.ejemplo=…`), no se escriben a mano. Detalle y despliegue: `docs/panel-tiendas.md` del repo del POS.

#### PIN opcional (sin PIN el panel sigue abierto por su ID, como siempre)

- Al activarlo, el POS genera una **clave** (32 bytes) y manda `pin`+`clave`; el servidor guarda `pin_hash`
  (`password_hash`) y `clave_hash` (sha256). Desde entonces **leer exige token** y **escribir exige la clave**
  (nadie que solo conozca el ID puede ver ni falsear cifras). El PIN son 6–8 dígitos y **no se guarda en el POS**.
- **Token** = `payload.firma` (HMAC-SHA256, 30 días, ligado a una huella del `pin_hash`: cambiar el PIN invalida
  los anteriores). Secreto: `dash_secret` de `config_db.php` si existe; si no, derivado de la contraseña de la base.
- **Bloqueo:** 8 PIN incorrectos seguidos → 15 min (`SELECT … FOR UPDATE` serializa los intentos del mismo panel).
  Tras un bloqueo vencido, un fallo más vuelve a bloquear (el contador no se limpia solo). Un acierto lo reinicia.
- **PC reinstalada** (perdió su clave): `sync_pos.php` con `pin_actual` + clave nueva la reemplaza si el PIN es correcto.
- **Soporte** (PIN olvidado o ID «reclamado» por otro): `UPDATE dashboard_pos SET pin_hash=NULL, clave_hash=NULL,
  pin_fallos=0, pin_bloqueo_hasta=NULL WHERE hardware_id='…'` (también en el encabezado de la migración).
- **Límite conocido:** quien activa la protección primero «reclama» el ID; si fuera alguien que solo conoce el ID, el
  dueño lo arregla con el `UPDATE` de arriba. Sin PIN, el panel sigue siendo tan abierto como antes.

#### Premium (2026-10-20; POS 3.4.0, `FIRMA_DOM` v5, `sw.js` → `antiparticula-dashboard-v8`)

El panel de **una** tienda se consulta con la suscripción Premium o en sus días gratis (el de todas las tiendas va con la
licencia multisucursal y no cambia). Migración `sql/2026-10-20_panel_premium.sql` (idempotente, solo agrega):
`dashboard_pos.gratis_hasta` y `premium_hasta`, tablas `dashboard_licencias` y `pruebas_equipo`.
- **Días gratis:** un panel nuevo, 15 (`DASH_PRUEBA_DIAS`), contados desde la primera vez que se vio **la PC** (`equipo`, el
  Hardware ID estable): otro panel en la misma PC no regala otros 15. Los paneles que existían al correr la migración reciben
  **30 días desde ese día** (el aviso): **correrla el mismo día en que se sube el PHP**; correrla otra vez no los alarga.
- **La clave:** `sync_pos.php` recibe `licencia` (la clave firmada de 125 caracteres) y `equipo`. `dash_licencia()` la verifica
  como el POS (`LicenciaFirmada`): Base32 canónico, `kid`, ECDSA P-256/SHA-256 sobre `"AP-LIC-1"` + 14 bytes (r||s → DER), con
  las llaves de `dash_llaves_licencia()` = las de `publica.txt` del POS (**una llave nueva va en los dos lados**; `config_db.php`
  puede sumar otras en `llaves_licencia`: solo las pruebas lo hacen). `premium_hasta` solo sube (`GREATEST`). Una clave abre a
  lo más **2 paneles** (`DASH_PANELES_POR_LICENCIA`): el tercero recibe el aviso `licencia_en_otros_paneles` y no se abre.
- **Respuesta de `sync_pos.php`:** agrega `acceso {estado, hasta}`: `premium` | `prueba` | `vencido` | `abierto` (sin la
  migración: todo como antes, y `acceso` = null). Avisos nuevos: `licencia_invalida`, `licencia_en_otros_paneles`,
  `acceso_no_disponible` (este, solo si el POS mandó algo de esto).
- **Lectura:** `get_dashboard_data.php` responde **403 `{status:'premium_requerido', negocio, desde, protegido}`** sin un solo
  dato; va **después** del PIN (sin token, 401 como siempre). Con acceso, la fila trae `acceso`; `gratis_hasta`/`premium_hasta`
  nunca salen.
- **Panel:** en sus días gratis, un aviso bajo el banner («Tu panel es gratis hasta el 18 Sep (quedan 15 días)…» + «Contratar
  Premium», `wa.me/525584323945` con el negocio y el ID del panel); con 403, `showPremium()`: la pantalla «El panel móvil es parte
  de Premium» (contratar por WhatsApp, «Ya lo activé: volver a revisar» y «¿Ya tienes Premium? Actualiza el programa…»), borra
  lo guardado en el teléfono y no se vuelve a montar en cada refresco. Al pagar, el siguiente refresco monta el panel.
- **`prueba_gratis.php`** (no es del panel, pero vive aquí): la prueba de Excel del POS contada **por PC**. POST `equipo`,
  `exportar`, `importar` (lo que le QUEDA al POS, 0–9) → 200 `{exportar, importar}` (lo que queda según el servidor: `usados`
  solo sube); 400 si falta una cuenta (no se lee como «0»); 503 sin la tabla. Sin CORS.
- **Soporte** (en el encabezado de la migración): dar días (`UPDATE dashboard_pos SET gratis_hasta = …`), liberar un panel
  viejo de una clave (`DELETE FROM dashboard_licencias WHERE hardware_id = …`) o devolver la prueba de Excel.
- **Orden de despliegue:** 1. SQL (el mismo día) · 2. PHP (`dashboard_comun.php`, `sync_pos.php`, `get_dashboard_data.php`,
  `prueba_gratis.php`) y `dashboard/*` · 3. publicar el POS 3.4.0. Comprobar después que una clave real abre: un sync con
  `licencia` de un cliente debe contestar `acceso.estado = premium` (si el hosting no tuviera `openssl`, `dash_licencia` da
  null y **todos** quedarían sin Premium al terminar sus días). Detalle del POS: `docs/licencias-y-hardware-id.md` del POS,
  «Qué es Premium», y `docs/dashboard-movil.md`.

#### Migración y orden de despliegue

`sql/2026-10-09_dashboard_extra_y_pin.sql` (idempotente; solo **agrega**): columnas `extra_json`, `pin_hash`,
`clave_hash`, `pin_fallos`, `pin_bloqueo_hasta` en `dashboard_pos`; desglose (NULL en días viejos) en
`dashboard_pos_historico`; tabla `dashboard_pos_meses`. **El PHP es a prueba de orden:** cada escritura nueva va en su
propio `try/catch` *después* del `upsert` de siempre, y la lectura cae a la forma anterior si falta una columna; aun así
conviene correr el SQL **antes** de subir el PHP (el accidente de 2026-09-01 fue al revés). Antes verifica que la
migración 001 (`dashboard_pos_ventas`) esté aplicada (el encabezado trae la consulta).
Orden: **1.** SQL · **2.** PHP (`sync_pos.php`, `get_dashboard_data.php`, `dashboard_auth.php`, `dashboard_comun.php`)
y `dashboard/*` · **3.** publicar el POS 3.3.0. Volver atrás = re-subir los archivos anteriores (las columnas no estorban).
`dashboard_comun.php` no es endpoint (responde 404 si se abre directo); es PHP 7.2-compatible (hosting compartido).

`sql/000_base_dashboard_pos.sql` es una línea base **reconstruida** (la tabla original nunca se versionó): solo para pruebas.

#### Pruebas

```bash
NODE_PATH=<carpeta con jsdom> node tests/test_dashboard.js   # 385 comprobaciones del panel (jsdom)
php tests/test_sync.php                                       # parseo de ultimas_ventas (29)
tests/correr_api.sh                # PHP + MariaDB REALES en contenedores: 217 (migrado, con Premium)
tests/correr_api.sh sin-migrar     # lo viejo funciona y lo nuevo se omite con aviso: 38
```
`correr_api.sh` construye una imagen alpine con PHP 8.3 + `pdo_mysql` (la oficial `php:*-cli` es una descarga grande que
en algunas redes se cuelga), levanta `mariadb:10.11`, aplica la migración **dos veces** y golpea los endpoints por HTTP;
`PUBLIC_HTML=<copia>` prueba otra copia (así se hicieron las mutaciones). No toca `config_db.php` ni la base real.
Todas las pruebas tienen **mutaciones comprobadas** (romper la regla hace fallar alguna).
`tests/fixtures/licencia_java.json` (una clave emitida en Java con una llave de prueba) lo genera `test/test/PruebaPremium.java`
del POS con la ruta como argumento: **no se escribe a mano**; con él, la sección 9 comprueba que PHP lee la clave igual que Java.

#### Aspecto (rediseño 2026-10-09)

Mismo lenguaje que el sitio: cabecera con el degradado `#202936 → #2c6e95` y una hoja de esquinas redondeadas
(28px) que sube sobre ella; rótulos en versalitas con espaciado (`.pg-kicker`), selector segmentado en píldora y colores
de cifras iguales a los del Resumen del POS de escritorio (ventas naranja, ganancia verde, tickets azul, ticket promedio
morado; pagos: efectivo azul acero, tarjeta morado, transferencia verde azulado). Claro y oscuro: sigue al sistema y el
botón de la cabecera lo fija (`localStorage` `apdash:tema`; el script del `<head>` lo aplica antes de pintar). Los tokens
de color están en `:root` de `dashboard.css`, uno por tema: **un color nuevo se define en los TRES bloques** (claro,
oscuro por sistema, oscuro fijado a mano).
- **Sin librerías:** las gráficas son HTML/CSS propio (`htmlColumnas`, filas con barra, barra apilada); ni Chart.js ni CDN:
  se ven igual sin conexión y heredan el tema. No volver a meter un `<canvas>`.
- **Lo que se calcula en el navegador** (`derivar()`): hoy contra ayer y contra el mismo día de la semana pasada, hora
  pico, mejor día, semana contra la anterior, mes contra el mes anterior **al mismo día** (hoy sale de la foto en vivo,
  no del renglón guardado), alertas de turno abierto > 14 h o desde un día anterior.
- **«Hoy» es el de México** (`ahoraMX()`, `America/Mexico_City`, la zona del servidor), no el del teléfono.
- **El POS manda `turno_inicio` como `dd-MM-yyyy HH:mm:ss`** (no ISO): `parseFechaPOS` entiende los dos. Antes el panel
  mostraba la fecha cruda y las pruebas no lo veían porque usaban ISO.
- **Último estado guardado** (`localStorage` `apdash:datos:<id>`): se muestra al abrir y sin internet, **solo si es de hoy**
  y, en un panel con PIN, solo si hay sesión (`apdash:token:<id>`). 401 o «Cerrar sesión» borran token y datos.
- `formatMXN` separa `$` de la cifra con U+202F, y los signos menos son U+2212: **escríbelos siempre como `\u202f` /
  `\u2212`** (escritos como carácter parecen un espacio o un guion normal y se pierden al reescribir; hay prueba).
- Con un POS anterior a la 3.3.0 faltan `extra` e `historico_dias`: Caja e Inventario dicen que hay que actualizar el POS
  y «Cómo pagaron» no aparece; **nada se inventa en ceros**.

**Reglas del frontend**
- `dashboard.js` monta el DOM una sola vez (`montar`) y luego solo actualiza textos y listas (`pintar`). No reconstruir el
  `innerHTML` en cada refresco: se pierde el scroll. `setHtml` compara contra lo último que escribió (no contra `innerHTML`,
  que el navegador normaliza) y no toca lo que no cambió. Los márgenes de la primera tarjeta se colapsan con el margen
  negativo de la hoja: por eso `.sheet-in` lleva `padding-top: 1px`.
- Todos los recursos se referencian con ruta **absoluta** (`/dashboard/...`). El `.htaccess` acepta `/dashboard/ID` y
  `/dashboard/ID/`, y con la barra final las rutas relativas resuelven a `/dashboard/ID/archivo` y dan 404.
- **Subir `CACHE_NAME` en `sw.js` en cada cambio de html/css/js**, si no los clientes instalados siguen sirviendo la versión
  vieja desde caché (hoy `v5`). El SW precachea con `cache: 'reload'`, **nunca cachea** `get_dashboard_data.php`,
  `sync_pos.php` ni `dashboard_auth.php`, y `dashboard.js` recarga una vez al cambiar de versión. **La primera vez tras un
  despliegue la app instalada aún muestra la versión vieja** (la sirve el SW anterior); la nueva aparece en la siguiente apertura.
- El manifest se genera en runtime (`instalarManifest`) para inyectar el `hardware_id` en `start_url`; el `manifest.json`
  estático es solo respaldo.

### Menú principal, barra del Punto de Venta y footer
- Barra fija `nav.site-nav` (css/nav.css + js/nav.js), sin desplegables:
  Punto de Venta · Servicios · Nosotros · Contacto · botón Descargar gratis.
- **Barra secundaria `nav.pv-subnav`** (parcial `subnav-pos`) debajo del menú en las
  páginas del producto (puntodeventa, versiones, instrucciones-descarga): Funciones,
  Precios, Panel web, Preguntas, Novedades, Cómo instalarlo. Mide `--pv-subnav-h`
  (46px); el encabezado de esas páginas suma `--site-nav-h + --pv-subnav-h`.
- **Transparentes hasta arriba** (dejan ver el encabezado de la página) y vidrio
  oscuro al hacer scroll: nav.js pone `is-scrolled` a partir de 8px. Por eso el
  encabezado de cada página debe ser oscuro y empezar en y=0, bajo la barra.
- **Fuente única en `parciales/`** (fuera del webroot): `menu.html`,
  `subnav-pos.html`, `footer.html` y `lema-*.html`. **Nunca editarlos dentro de las
  páginas**: se editan los parciales y luego `php parciales/actualizar.php`, que los
  copia entre los marcadores `<!-- parcial:NOMBRE -->…<!-- /parcial:NOMBRE -->` de
  cada .html de public_html (cualquier `parciales/NOMBRE.html` sirve).
  `php parciales/actualizar.php --revisar` solo avisa (código 1 si hay páginas
  desactualizadas); correrlo antes de subir cambios.
- El script marca la página actual (`aria-current="page"` en el enlace de menú o
  barra cuya ruta coincide con la del archivo) y pone en `_next` del formulario la
  misma página.
- Opciones en el marcador: `solido` (menú siempre oscuro, solo para páginas blancas
  desde arriba: hoy instrucciones-descarga), `actual="paginas/x.html"` (qué enlace
  marcar si no es la propia página: versiones e instrucciones marcan "Punto de
  Venta") y `lema="pos"` (footer con `lema-pos.html`).
- Página nueva: copiar los pares de marcadores (vacíos) y correr el script, y
  enlazar `css/nav.css` y `js/nav.js` con ruta relativa (`../` desde paginas/).
- **Nada de rutas absolutas (`/css/...`, `/paginas/...`)** en páginas ni parciales:
  el sitio también se abre como archivo o con servidores cuya raíz no es
  public_html, y ahí esas rutas no cargan. En los parciales se escribe
  `{{raiz}}paginas/...` y el script pone `''` o `../` según la carpeta.
- En páginas con `solido` va `<div class="site-nav-spacer">` tras los menús; en las
  de encabezado oscuro, ese encabezado suma el alto de las barras a su padding
  superior (clase `nav-offset`, o `calc(... + var(--site-nav-h))`).
- Menú móvil (≤1000px): pantalla completa; la barra del producto se desliza de lado.
- Las reglas viejas `nav`, `.nav-links` y `showMenu()/hideMenu()` de style.css y
  script.js ya no las usa ninguna página.

### Página de inicio (`index.html`)
- Portada: la tarjeta con carrusel (`.blog-slider`, Swiper en script.js, estilos en
  style.css) sobre el fondo fijo `.pg-bg`. Una flecha (`.in-scroll`) baja a la hoja.
- inicio.css saca la tarjeta del `position: absolute` de style.css y la centra con
  flex dentro de `.header`, con padding superior del alto del menú (y 96px más en
  celular, lo que sobresale la foto). En pantallas bajas o celular horizontal la
  portada crece en lugar de meter la tarjeta bajo el menú. No volver a centrarla
  con `top: 50%` + `translate`.
- Debajo, `main.in-main`: hoja blanca de esquinas redondeadas que sube sobre el fondo
  fijo. Secciones: qué hacemos, Punto de Venta (captura `img/pos/resumen-claro`),
  proyecto Alvamex, cita del fundador y llamado final (`.pg-cta` dentro de `.in-cta`,
  con el mismo fondo desenfocado).
- Estilos en `css/inicio.css` (prefijo `in-`) sobre `css/paginas.css`.
- `.in-reveal` aparece al entrar en pantalla: `js/inicio.js` pone `.in-anim` en
  `<html>` solo si hay IntersectionObserver y no se pidió movimiento reducido; sin
  eso el contenido se ve desde el principio.
- Los textos salen de Servicios, Nosotros y el Punto de Venta; la cita es literal
  de Nosotros. Si cambian allá, actualizarlos aquí.

### Página del Punto de Venta (`paginas/puntodeventa.html`)
- Estilos en `css/puntodeventa.css` (prefijo `pv-`) sobre `css/paginas.css`.
  `stylePuntoVenta.css` ya no lo usa ninguna página.
- Las clases `testimonio-*`, `tst-*`, `faq-*`, `modal-*`, `whatsapp-float` y el
  contenedor `.stats-bar` conservan su nombre: las usa el JavaScript de la página y
  de `script.js`/`mensaje-exito.js`.
- Las preguntas frecuentes deben coincidir palabra por palabra con el `FAQPage` de
  los datos estructurados del `<head>`.
- **Capturas de la app** en `img/pos/`: `resumen|vender|busqueda|inventario` ×
  `claro|oscuro`, cada una en tres anchos: `-2x` (2860 px, pantallas de alta
  densidad), sin sufijo (1430 px) y `-720` (celular), todas WebP **sin pérdida**
  (el texto de la interfaz se degrada con WebP con pérdida y sin pérdida pesa lo
  mismo). La página las ofrece con `srcset` y el navegador elige. Se dibujan a 2x
  desde la app, no se agrandan: una imagen 1x se ve borrosa en pantallas con
  escala de 200 %.
- Son pantallas reales de la app Java con un negocio de ejemplo ("Abarrotes La
  Esperanza"). Para regenerarlas tras un cambio de la app, con las herramientas de
  `herramientas/capturas-pos/`: copiar `datos.db` de prueba a una carpeta temporal,
  sembrarla con `sembrar_demo.py` y correr `RenderPOS.java` con el JDK de Windows,
  `-Dawt.useSystemAAFontSettings=on` y la red bloqueada por proxy
  (`-Dhttps.proxyHost=127.0.0.1 -Dhttps.proxyPort=9`), para que la app no mande
  telemetría ni sincronice datos de ejemplo al servidor. Nunca contra la base real.

## Contact Information
- Business email: contacto@antiparticula.com
- Support email (forms): viresbhx@gmail.com
- Phone: +52 55 8432 3945
- Location: Tecámac, Estado de México
- Social media: Facebook, Instagram, TikTok, WhatsApp

## Common File Paths Referenced
- Logo: `img/A.png` or `../img/A.png` (depending on page location)
- POS installer: `downloads/Instalador_Antiparticula_Punto_Venta.exe`
- Full version installer: `downloads/Antiparticua_PuntoDeVenta.exe`
- Database files: `downloads/BD/datos.sqbpro`, `downloads/BD/datosNuevo.sqbpro`
