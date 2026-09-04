# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Antipartícula is a business website for a software development consultancy based in Mexico. The site showcases IT services, custom software development, and includes a product page for their point-of-sale (POS) software. The project is a traditional multi-page website built with HTML, CSS, and vanilla JavaScript, served via Apache with PHP backend functionality.

## Architecture

### File Structure

```
antiparticula.com/
├── public_html/                 # Web root directory
│   ├── index.html              # Homepage with carousel and service cards
│   ├── paginas/                # All internal pages
│   │   ├── puntodeventa.html   # POS product landing page
│   │   ├── queHacemos.html     # Services page
│   │   ├── quienes-somos.html  # About page
│   │   ├── contacto.html       # Contact page
│   │   ├── descargar.html      # Download page
│   │   └── 404.html            # Custom error page
│   ├── css/                    # Stylesheets
│   │   ├── style.css           # Main global styles
│   │   ├── stylePuntoVenta.css # POS product page styles
│   │   └── mensaje-exito.css   # Success message modal styles
│   ├── js/                     # JavaScript files
│   │   ├── script.js           # Navigation, Swiper carousel, WhatsApp integration
│   │   └── mensaje-exito.js    # Download tracking & success modals
│   ├── img/                    # Images and graphics
│   ├── downloads/              # Downloadable files
│   │   └── BD/                 # Database files (.sqbpro)
│   ├── products.json           # Product catalog data
│   └── .htaccess               # Apache URL rewriting rules
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

### Mobile Responsiveness
- Hamburger menu toggle via `showMenu()` and `hideMenu()` functions
- Menu slides in from right on mobile (`navLinks` element)

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
