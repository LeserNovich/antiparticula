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

Panel de ventas del POS, servido en `/dashboard/{hardware_id}`. La app Java del
POS empuja datos a `sync_pos.php`; el navegador los lee de `get_dashboard_data.php`.

**Migraciones SQL** — `sql/` (fuera del webroot). Ejecutar en orden sobre
`u450756829_PaginaWeb`. Pendiente de aplicar: `001_ventas_por_hora.sql`.

**Tablas**
- `dashboard_pos` — snapshot actual, una fila por `hardware_id`.
- `dashboard_pos_historico` — totales por día, base del histórico anual.
- `dashboard_pos_ventas` — cada venta individual, base de la gráfica horaria.
  El POS reenvía las mismas ventas en cada sync; la clave única
  `(hardware_id, fecha, venta_key)` hace el INSERT idempotente. `venta_key` es
  el folio del POS si lo manda, si no un hash de (hora, minuto, cajero, importe).
  **Si el POS empieza a mandar `folio` (o `ticket`/`venta_id`) por venta, la
  deduplicación deja de ser heurística** — hoy dos ventas del mismo cajero por
  el mismo importe en el mismo minuto se cuentan como una.

**Reglas del frontend**
- `dashboard.js` monta el DOM una sola vez (`montar`) y luego solo actualiza
  textos y datasets (`pintar`). No reconstruir el `innerHTML` en cada refresco:
  se pierde el scroll y parpadean las gráficas.
- Todos los recursos se referencian con ruta **absoluta** (`/dashboard/...`).
  El `.htaccess` acepta `/dashboard/ID` y `/dashboard/ID/`, y con la barra final
  las rutas relativas resuelven a `/dashboard/ID/archivo` y dan 404.
- **Subir `CACHE_NAME` en `sw.js` en cada cambio de html/css/js**, si no los
  clientes instalados siguen sirviendo la versión vieja desde caché.
- El manifest se genera en runtime (`instalarManifest`) para inyectar el
  `hardware_id` en `start_url`; el `manifest.json` estático es solo respaldo.

**Pendiente conocido:** el endpoint solo está protegido por conocer el
`hardware_id`. No hay autenticación real ni en lectura ni en escritura.

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
