// La página de los anuncios (paginas/gratis.html + js/gratis.js + js/mensaje-exito.js) en un Chromium de verdad,
// como celular y como computadora. Sirve public_html tal cual; registrar_gratis.php, registrar_descarga.php y
// get_stats.php los contesta la prueba (no usa la base ni TikTok). El Pixel no carga (se bloquea su script):
// lo que la página le manda queda en la cola window.ttq, y eso es lo que se revisa.
//
//   NODE_PATH=<carpeta con playwright> node tests/test_gratis_pagina.js [carpeta_para_capturas]
//
// Con carpeta, deja ahí capturas de cada estado para mirarlas.
const http = require('http'), fs = require('fs'), path = require('path');
const { chromium } = require('playwright');

const PUB = path.resolve(__dirname, '../public_html');
const CAPTURAS = process.argv[2] || null;
const TIPOS = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'application/javascript',
                '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const UA_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

let pass = 0, fail = 0;
function ok(c, label, extra) {
    if (c) { pass++; console.log('  \x1b[32m✓\x1b[0m ' + label); }
    else { fail++; console.log('  \x1b[31m✗\x1b[0m ' + label + (extra !== undefined ? '\n      → ' + extra : '')); }
}
function eq(a, b, label) { ok(JSON.stringify(a) === JSON.stringify(b), label, 'esperado ' + JSON.stringify(b) + ', obtenido ' + JSON.stringify(a)); }
function titulo(t) { console.log('\n\x1b[1m' + t + '\x1b[0m'); }

const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const f = path.join(PUB, decodeURIComponent(u.pathname));
    if (!f.startsWith(PUB) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.statusCode = 404; return res.end(''); }
    res.setHeader('content-type', TIPOS[path.extname(f)] || 'application/octet-stream');
    res.end(fs.readFileSync(f));
});

// Una página con el servidor de mentira. respuestas: { registro: {status, cuerpo}, stats: {...} }
async function abrir(navegador, opciones, url, respuestas) {
    const ctx = await navegador.newContext(opciones);
    const pagina = await ctx.newPage();
    const peticiones = { registro: [], descarga: [], archivos: [] };
    const errores = [];
    pagina.on('pageerror', (e) => errores.push(e.message));
    await pagina.route('**/analytics.tiktok.com/**', (r) => r.abort());
    await pagina.route('**/registrar_gratis.php', (r) => {
        peticiones.registro.push(Object.fromEntries(new URLSearchParams(r.request().postData() || '')));
        const resp = respuestas.registro || { status: 200, cuerpo: { status: 'ok', codigo: 'K7P3Q', link: 'https://antiparticula.com/d/K7P3Q', nuevo: true } };
        r.fulfill({ status: resp.status, contentType: 'application/json', body: JSON.stringify(resp.cuerpo) });
    });
    await pagina.route('**/registrar_descarga.php', (r) => {
        peticiones.descarga.push(Object.fromEntries(new URLSearchParams(r.request().postData() || '')));
        r.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"exitoso"}' });
    });
    await pagina.route('**/downloads/**', (r) => r.fulfill({ status: 200, body: 'x' }));
    pagina.on('download', (d) => { peticiones.archivos.push(d.url()); d.cancel().catch(() => {}); });
    await pagina.route('**/get_stats.php', (r) => r.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify(respuestas.stats || { total: 132, descargas: 1499 }) }));
    await pagina.goto(srv.base + url, { waitUntil: 'load' });
    return { ctx, pagina, peticiones, errores };
}
const visible = (p, sel) => p.isVisible(sel);
const texto = (p, sel) => p.textContent(sel).then((t) => t.trim().replace(/\s+/g, ' '));
// Lo que la página le mandó al Pixel: [['track', 'Download', {...}, {event_id}], ...]
const colaPixel = (p) => p.evaluate(() => Array.prototype.filter.call(window.ttq, (x) => Array.isArray(x)).map((x) => x.slice(0)));
async function captura(p, nombre) { if (CAPTURAS) await p.screenshot({ path: path.join(CAPTURAS, nombre + '.png'), fullPage: true }); }
async function sinScrollDeLado(p) { return p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth); }

(async () => {
    await new Promise((r) => srv.listen(0, '127.0.0.1', r));
    srv.base = 'http://127.0.0.1:' + srv.address().port;
    if (CAPTURAS) fs.mkdirSync(CAPTURAS, { recursive: true });
    const nav = await chromium.launch();
    const CELULAR = { userAgent: UA_IPHONE, viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
    const CELULAR_CHICO = Object.assign({}, CELULAR, { viewport: { width: 360, height: 740 } });
    const COMPU = { userAgent: UA_WINDOWS, viewport: { width: 1280, height: 860 } };

    titulo('1. En el celular: el formulario');
    let { ctx, pagina: p, peticiones, errores } = await abrir(nav, CELULAR, '/paginas/gratis.html?ttclid=E.C.P.del-anuncio', {});
    ok(await visible(p, '#grForm'), 'se ve el formulario');
    ok(!(await visible(p, '#grListo')) && !(await visible(p, '#grPc')), 'y no el link ni la descarga');
    const cookies = await ctx.cookies();
    eq((cookies.find((c) => c.name === 'ap_ttclid') || {}).value, 'E.C.P.del-anuncio', 'el ttclid de la URL queda en la cookie ap_ttclid');
    await p.waitForFunction(() => !document.getElementById('grTotal').parentNode.hidden);
    eq(await texto(p, '#grTotal'), '132', 'cifra real de negocios activos');
    eq(await texto(p, '#grDescargas'), '1,499', 'y de descargas, con separador de miles');
    ok(await sinScrollDeLado(p), 'sin scroll de lado a 390 px');
    await captura(p, '1-celular-formulario');

    await p.click('#grEnviar');
    ok(await visible(p, '#grError'), 'enviar vacío avisa del error');
    eq(await p.getAttribute('#grTelefono', 'aria-invalid'), 'true', 'y marca el WhatsApp');
    eq(peticiones.registro.length, 0, 'sin mandar nada al servidor');
    await p.fill('#grTelefono', '55 1234 5678');
    await p.click('#grEnviar');
    eq(await p.getAttribute('#grGiro', 'aria-invalid'), 'true', 'sin giro: marca el giro');
    eq(await p.getAttribute('#grTelefono', 'aria-invalid'), null, 'y ya no el WhatsApp');
    await p.selectOption('#grGiro', 'ferreteria');
    await p.click('#grEnviar');
    eq(await p.getAttribute('#grAcepta', 'aria-invalid'), 'true', 'sin aceptar: marca la casilla');
    eq(peticiones.registro.length, 0, 'todavía sin mandar nada');
    await captura(p, '2-celular-error');

    titulo('2. En el celular: registro correcto');
    await p.fill('#grNombre', 'Ana');
    await p.check('#grAcepta');
    await p.click('#grEnviar');
    await p.waitForSelector('#grListo', { state: 'visible' });
    eq(peticiones.registro.length, 1, 'se manda una vez');
    const enviado = peticiones.registro[0] || {};
    eq([enviado.telefono, enviado.giro, enviado.nombre, enviado.acepta, enviado.sitio], ['55 1234 5678', 'ferreteria', 'Ana', '1', ''],
       'con WhatsApp, giro, nombre, acepta=1 y el campo escondido vacío');
    eq(enviado.ttclid, 'E.C.P.del-anuncio', 'y el ttclid de la cookie');
    ok(/^[A-Za-z0-9_-]{8,64}$/.test(enviado.event_id || ''), 'y un event_id con la forma que acepta el servidor');
    ok((enviado.url || '').indexOf('/paginas/gratis.html?ttclid=') > 0, 'y la URL de la página');
    eq(await texto(p, '#grListoTitulo'), '¡Listo, Ana!', 'título con su nombre');
    eq(await texto(p, '#grLink'), 'antiparticula.com/d/K7P3Q', 'su link personal');
    const wa = await p.getAttribute('#grMandar', 'href');
    ok(wa.indexOf('https://wa.me/?text=') === 0 && decodeURIComponent(wa).indexOf('https://antiparticula.com/d/K7P3Q') > 0,
       'el botón de WhatsApp lleva su link');
    ok(!(await visible(p, '#grForm')), 'el formulario ya no se ve');
    const cola = await colaPixel(p);
    const registro = cola.filter((x) => x[0] === 'track' && x[1] === 'CompleteRegistration');
    eq(registro.length, 1, 'el Pixel recibe UN CompleteRegistration');
    eq(registro[0] && registro[0][3], { event_id: enviado.event_id }, 'con el mismo event_id que el servidor');
    await captura(p, '3-celular-listo');
    await p.reload({ waitUntil: 'load' });
    ok(await visible(p, '#grListo'), 'al volver a abrir la página sigue su link (sin otro formulario)');
    eq(await texto(p, '#grLink'), 'antiparticula.com/d/K7P3Q', 'el mismo link');
    eq(errores, [], 'sin errores de JavaScript');
    await ctx.close();

    titulo('3. En el celular: el servidor dice que no, o que ya estaba');
    ({ ctx, pagina: p, peticiones } = await abrir(nav, CELULAR, '/paginas/gratis.html',
        { registro: { status: 400, cuerpo: { status: 'error', campo: 'telefono', message: 'Revisa tu número de WhatsApp: son 10 dígitos, por ejemplo 55 1234 5678.' } } }));
    await p.fill('#grTelefono', '12 3456 7890'); await p.selectOption('#grGiro', 'otro'); await p.check('#grAcepta');
    await p.click('#grEnviar');
    await p.waitForSelector('#grError', { state: 'visible' });
    ok((await texto(p, '#grError')).indexOf('Revisa tu número') === 0, 'enseña el mensaje del servidor');
    eq(await p.getAttribute('#grTelefono', 'aria-invalid'), 'true', 'y marca el campo que dijo');
    ok(await p.isEnabled('#grEnviar'), 'el botón vuelve a quedar activo');
    await ctx.close();
    ({ ctx, pagina: p } = await abrir(nav, CELULAR, '/paginas/gratis.html',
        { registro: { status: 200, cuerpo: { status: 'ok', codigo: 'K7P3Q', link: 'https://antiparticula.com/d/K7P3Q', nuevo: false } } }));
    await p.fill('#grTelefono', '5512345678'); await p.selectOption('#grGiro', 'otro'); await p.check('#grAcepta');
    await p.click('#grEnviar');
    await p.waitForSelector('#grListo', { state: 'visible' });
    eq((await colaPixel(p)).filter((x) => x[1] === 'CompleteRegistration').length, 0, 'ya registrado (nuevo=false): el Pixel no cuenta otro');
    await ctx.close();

    titulo('4. En el celular, abriendo su link personal');
    ({ ctx, pagina: p } = await abrir(nav, CELULAR_CHICO, '/paginas/gratis.html?c=k7p3q', {}));
    ok(await visible(p, '#grListo'), 'enseña el link, no el formulario');
    eq(await texto(p, '#grListoTitulo'), 'Este link es para tu computadora', 'y dice que es para la computadora');
    eq(await texto(p, '#grLink'), 'antiparticula.com/d/K7P3Q', 'el código en mayúsculas');
    ok(await sinScrollDeLado(p), 'sin scroll de lado a 360 px');
    await ctx.close();

    titulo('5. En la computadora');
    ({ ctx, pagina: p, peticiones, errores } = await abrir(nav, COMPU, '/paginas/gratis.html', { stats: { total: null, descargas: 0 } }));
    ok(await visible(p, '#grPc'), 'se ve la descarga');
    ok(!(await visible(p, '#grForm')), 'y no el formulario');
    await p.waitForFunction(() => /Windows/.test(document.querySelector('[data-descarga]').textContent));
    eq(await texto(p, '[data-descarga]'), 'Descargar gratis para Windows', 'el botón dice para Windows');
    await p.waitForTimeout(300);
    ok(await p.evaluate(() => document.getElementById('grTotal').parentNode.hidden && document.getElementById('grDescargas').parentNode.hidden),
       'una cifra null o 0 no se enseña');
    await captura(p, '4-computadora');
    await ctx.close();

    ({ ctx, pagina: p, peticiones, errores } = await abrir(nav, COMPU, '/paginas/gratis.html?c=K7P3Q', {}));
    eq(await texto(p, '#grPcTitulo'), 'Tu punto de venta está listo', 'con ?c=: «Tu punto de venta está listo»');
    eq(((await ctx.cookies()).find((c) => c.name === 'ap_reg') || {}).value, 'K7P3Q', 'y guarda el código en la cookie ap_reg');
    await p.click('[data-descarga]');
    await p.waitForTimeout(500);
    eq(peticiones.descarga.length, 1, 'descargar avisa a registrar_descarga.php');
    const desc = peticiones.descarga[0] || {};
    eq([desc.sistema, desc.codigo], ['windows', 'K7P3Q'], 'con el sistema y el código de su link');
    ok(/^[A-Za-z0-9_-]{8,64}$/.test(desc.event_id || ''), 'y un event_id');
    ok(peticiones.archivos.some((u) => /Instalador_Antiparticula_Punto_Venta\.exe$/.test(u)), 'baja el instalador de Windows');
    const descargaPixel = (await colaPixel(p)).filter((x) => x[0] === 'track' && x[1] === 'Download');
    eq(descargaPixel.length, 1, 'el Pixel recibe UN Download');
    eq(descargaPixel[0] && descargaPixel[0][3], { event_id: desc.event_id }, 'con el mismo event_id que el servidor');
    eq(errores, [], 'sin errores de JavaScript');
    await captura(p, '5-computadora-con-codigo');
    await ctx.close();

    titulo('6. Preguntas y aviso de privacidad');
    ({ ctx, pagina: p } = await abrir(nav, CELULAR, '/paginas/gratis.html', {}));
    const preguntas = await p.$$('.faq-pregunta');
    await preguntas[1].click();
    eq(await p.$$eval('.faq-item.open', (x) => x.length), 1, 'abrir una pregunta la enseña');
    eq(await preguntas[1].getAttribute('aria-expanded'), 'true', 'con aria-expanded');
    await preguntas[2].click();
    eq(await p.$$eval('.faq-item.open .faq-pregunta', (x) => x.map((b) => b.textContent.trim().slice(0, 12))), ['¿El sistema '], 'abrir otra cierra la anterior');
    await ctx.close();
    ({ ctx, pagina: p, errores } = await abrir(nav, CELULAR_CHICO, '/paginas/privacidad.html', {}));
    ok(await sinScrollDeLado(p), 'el aviso de privacidad, sin scroll de lado a 360 px');
    ok((await p.textContent('main')).indexOf('Erick Ricardo Gonzalez Manuel') > 0, 'con el nombre del responsable');
    eq(errores, [], 'sin errores de JavaScript');
    await captura(p, '6-privacidad');
    await ctx.close();

    await nav.close();
    srv.close();
    console.log('\n' + (fail === 0 ? '\x1b[32m' : '\x1b[31m') + pass + ' correctas, ' + fail + ' fallidas\x1b[0m');
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(2); });
